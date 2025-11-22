#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>

#include <Adafruit_NeoPixel.h>
#include <ArduinoJson.h>

#include "secrets.h" // WiFi credentials and optional static IP configuration

// ------------ CONFIG ZONE ------------
const char* ZONE_ID  = "R149E";   // <--- Change this value
const char* URL_BASE = "http://aero.ratelet.fr/azba/azba.php";

// ------------ CONFIG LEDS ------------
#define LED_PIN    D4
#define LED_COUNT  6

Adafruit_NeoPixel strip(LED_COUNT, LED_PIN, NEO_GRB + NEO_KHZ800);

enum Mode {
  MODE_CONNECTING,            // Blue solid (WiFi connecting)
  MODE_ERROR_OR_STARTUP,      // White solid (error/startup)
  MODE_ACTIVE_NOW,            // Red blinking (active now)
  MODE_WILL_BE_ACTIVE_SOON,   // Orange blinking (active soon, within 4h)
  MODE_WILL_BE_ACTIVE_LATER,  // Yellow solid (active later, beyond 4h)
  MODE_INACTIVE               // Green solid (inactive)
};

Mode currentMode = MODE_ERROR_OR_STARTUP;

// --- Intervals and parameters ---
// Interval between normal refreshes (5 minutes)
const unsigned long FETCH_INTERVAL_MS = 300000UL;      // 5 minutes
// Automatic reboot every 48 hours to avoid memory leaks/drift
const unsigned long REBOOT_INTERVAL_MS = 172800000UL;  // 48 hours in milliseconds
// Retry parameters: number of retries and delay between them
// (e.g. FETCH_MAX_RETRIES = 2 => initial attempt + 2 retries)
const int FETCH_MAX_RETRIES = 3;                       // number of retries (0 = no retry)
const unsigned long FETCH_RETRY_INTERVAL_MS = 25000UL; // 25 seconds between retries

// Timestamps and counters
unsigned long lastFetchMs = 0;   // timestamp of last fetch (millis())
unsigned long startupTimeMs = 0; // application startup timestamp
// Number of refresh cycles performed (incremented before each fetch)
// This counter is sent to the server for diagnostics (`refresh_count`)
unsigned long refreshCount = 0; // number of refreshes (5-minute cycles)
// Diagnostics counters
unsigned long totalAttempts = 0;          // total HTTP attempts across retries
unsigned long totalSuccessfulFetches = 0; // total successful fetches
unsigned int consecutiveFailures = 0;     // consecutive failed fetch cycles

// JSON buffer size (changeable)
#define JSON_BUF_SIZE 2048

// ------------ LED FUNCTIONS ------------
void setAllPixels(uint32_t color) {
  for (int i = 0; i < LED_COUNT; i++) {
    strip.setPixelColor(i, color);
  }
}

void updateLeds() {
  uint32_t color = 0;
  bool blinking = false;

  switch (currentMode) {
    case MODE_CONNECTING:
      color = strip.Color(0, 0, 255);      // Blue
      blinking = false;
      break;
    case MODE_ACTIVE_NOW:
      color = strip.Color(255, 0, 0);      // Red
      blinking = true;
      break;
    case MODE_WILL_BE_ACTIVE_SOON:
      color = strip.Color(255, 60, 0);     // Orange blinking (within 4h)
      blinking = true;
      break;
    case MODE_WILL_BE_ACTIVE_LATER:
      color = strip.Color(255, 120, 0);    // Yellow solid (beyond 4h)
      blinking = false;
      break;
    case MODE_INACTIVE:
      color = strip.Color(0, 255, 0);      // Green
      blinking = false;
      break;
    case MODE_ERROR_OR_STARTUP:
    default:
      color = strip.Color(255, 255, 255);  // White
      blinking = false;
      break;
  }

  if (blinking) {
    bool on = ((millis() / 500) % 2) == 0; // 500 ms
    if (on) setAllPixels(color);
    else    setAllPixels(0);
  } else {
    setAllPixels(color);
  }

  strip.show();
}

// ------------ HTTP + JSON ------------
void softReboot() {
  Serial.println(F("\n\n=== SYSTEM REBOOT ===\n"));
  delay(1000);
  ESP.restart();
}

void reconnectWiFi() {
  // Simple WiFi reconnect attempt: switch to STA mode and wait
  // a few seconds (loop with delays) while showing blue LEDs.
  Serial.println(F("Attempting WiFi reconnect..."));
  currentMode = MODE_CONNECTING;  // Switch to blue
  
  WiFi.mode(WIFI_STA);
  
  #ifdef USE_STATIC_IP
    WiFi.config(staticIP, gateway, subnet, dns1, dns2);
  #endif
  
  WiFi.begin(ssid, password);

  int retry = 0;
  while (WiFi.status() != WL_CONNECTED && retry < 60) {
    delay(500);
    Serial.print(".");
    updateLeds();  // Update LEDs to blue during reconnect
    retry++;
  }
  Serial.println();

  if (WiFi.status() == WL_CONNECTED) {
    Serial.print(F("Reconnected, IP: "));
    Serial.println(WiFi.localIP());
    delay(2000);  // Allow connection to stabilize
  } else {
    Serial.println(F("WiFi reconnect failed"));
    currentMode = MODE_ERROR_OR_STARTUP;
  }
}

// fetchStatus()
// - checks WiFi connection and attempts reconnect if necessary
  // - builds the request URL including two additional query parameters:
//     `refresh_count`: number of successful refresh cycles already performed (does NOT include current)
//     `attempt_count`: number of the current attempt (1..N)
// - performs the HTTP request and parses the returned JSON
// - maps JSON fields to `currentMode` to drive the LEDs
// - on failure, retry up to `FETCH_MAX_RETRIES` times with delay

void fetchStatus() {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println(F("WiFi not connected -> reconnecting"));
    reconnectWiFi();
    
    // If still not connected after attempt, exit
    if (WiFi.status() != WL_CONNECTED) {
      Serial.println(F("WiFi still not connected"));
      currentMode = MODE_ERROR_OR_STARTUP;
      return;
    }
  }

  // The URL is built for each attempt to include
  // `refresh_count` and `attempt_count` as query parameters.

  WiFiClient client;
  client.setTimeout(10000);         // 10 seconds read timeout

  HTTPClient http;
  http.setTimeout(10000);           // 10 seconds timeout

  bool success = false;
  // Retry loop: perform the initial attempt (attempt=0)
  // then up to FETCH_MAX_RETRIES retries (attempt = 1..FETCH_MAX_RETRIES)
  for (int attempt = 0; attempt <= FETCH_MAX_RETRIES; attempt++) {
    Serial.printf("Attempt %d/%d\n", attempt + 1, FETCH_MAX_RETRIES + 1);

    // Count this HTTP attempt for diagnostics
    totalAttempts++;

    // Build URL in a stack buffer to avoid String heap fragmentation on ESP8266
    char fullUrl[256];
    int len = snprintf(fullUrl, sizeof(fullUrl), "%s?azba=%s&refresh_count=%lu&attempt_count=%d",
                       URL_BASE, ZONE_ID, refreshCount, attempt + 1);
    if (len < 0 || len >= (int)sizeof(fullUrl)) {
      Serial.println(F("Warning: URL truncated"));
    }
    Serial.print(F("Request URL: "));
    Serial.println(fullUrl);

    if (!http.begin(client, fullUrl)) {
      // Error initializing HTTP request (e.g. bad URL, socket)
      Serial.println(F("http.begin() error"));
      http.end();
      client.stop();
      
    } else {
      int httpCode = http.GET();
      // Check returned HTTP status code
      if (httpCode != HTTP_CODE_OK) {
        Serial.printf("HTTP error: %d\n", httpCode);
        http.end();
        client.stop();
        
      } else {
        String payload = http.getString();
        http.end();
        client.stop();

        StaticJsonDocument<JSON_BUF_SIZE> doc;
        DeserializationError error = deserializeJson(doc, payload);

        // Parse JSON
        if (error) {
          Serial.print(F("JSON parse error: "));
          Serial.println(error.c_str());
          
        } else if (!doc.containsKey("zones")) {
          Serial.println(F("JSON does not contain 'zones'"));
          
        } else {
          JsonObject zones = doc["zones"];

          if (!zones.containsKey(ZONE_ID)) {
            Serial.print(F("JSON.zones does not contain '"));
            Serial.print(ZONE_ID);
            Serial.println(F("'"));
            currentMode = MODE_INACTIVE;
            success = true;
            refreshCount++; // Zone not found still counts as a valid refresh cycle
            break;
          }

          JsonObject z = zones[ZONE_ID];

          bool isActiveNow       = z["is_active_now"]       | false;
          bool willBeActive      = z["will_be_active"]      | false;
          bool willBeActiveSoon  = z["will_be_active_soon"] | false;

          Serial.printf("%s.is_active_now       = %s\n", ZONE_ID, isActiveNow ? "true" : "false");
          Serial.printf("%s.will_be_active      = %s\n", ZONE_ID, willBeActive ? "true" : "false");
          Serial.printf("%s.will_be_active_soon = %s\n", ZONE_ID, willBeActiveSoon ? "true" : "false");

          if (isActiveNow) {
            currentMode = MODE_ACTIVE_NOW;
          } else if (willBeActiveSoon) {
            currentMode = MODE_WILL_BE_ACTIVE_SOON;
          } else if (willBeActive) {
            currentMode = MODE_WILL_BE_ACTIVE_LATER;
          } else {
            currentMode = MODE_INACTIVE;
          }

          // Mark success: update counters
          success = true;
          refreshCount++; // Increment after successfully parsing the zone data
          totalSuccessfulFetches++;
          consecutiveFailures = 0;
          Serial.printf("Fetch successful (total successful: %lu)\n", totalSuccessfulFetches);
          break;
        }
      }
    }

    // If retries remain, optionally log the failed attempt and wait while updating LEDs
    if (attempt < FETCH_MAX_RETRIES) {
      if (!success) {
        Serial.printf("Attempt %d failed, will retry... (totalAttempts=%lu)\n", attempt + 1, totalAttempts);
      }
      Serial.printf("Waiting %lu ms before retry %d/%d\n", FETCH_RETRY_INTERVAL_MS, attempt + 2, FETCH_MAX_RETRIES + 1);
      currentMode = MODE_ERROR_OR_STARTUP; // keep error LED during wait
      unsigned long waitStart = millis();
      while (millis() - waitStart < FETCH_RETRY_INTERVAL_MS) {
        updateLeds();
        delay(200);
      }
    }

  }

  if (!success) {
    consecutiveFailures++;
    Serial.printf("Failed after retries, set to ERROR (consecutive failures=%u)\n", consecutiveFailures);
    currentMode = MODE_ERROR_OR_STARTUP;
  }
}

// ------------ SETUP & LOOP ------------
void setup() {
  Serial.begin(115200);
  delay(100);

  strip.begin();
  strip.show();
  currentMode = MODE_CONNECTING;  // Blue while connecting
  updateLeds();

  Serial.printf("Connecting to WiFi: %s\n", ssid);
  WiFi.mode(WIFI_STA);
  
  #ifdef USE_STATIC_IP
    Serial.println(F("Static IP configuration..."));
    WiFi.config(staticIP, gateway, subnet, dns1, dns2);
  #endif
  
  WiFi.begin(ssid, password);

  int retry = 0;
  while (WiFi.status() != WL_CONNECTED && retry < 60) {
    delay(500);
    Serial.print(".");
    retry++;
  }
  Serial.println();

  if (WiFi.status() == WL_CONNECTED) {
    Serial.print(F("Reconnected, IP: "));
    Serial.println(WiFi.localIP());
    delay(2000);  // Allow WiFi to stabilize
    fetchStatus(); // first request
  } else {
    Serial.println(F("WiFi connection failed -> WHITE (ERROR)"));
    currentMode = MODE_ERROR_OR_STARTUP;
  }

  lastFetchMs = millis();
  startupTimeMs = millis();  // Record startup time
}

void loop() {
  updateLeds();

  unsigned long now = millis();
  
  // Check reboot every 48 hours
  if (now - startupTimeMs >= REBOOT_INTERVAL_MS) {
    Serial.println(F("System reboot every 48 hours"));
    softReboot();
  }

  if (now - lastFetchMs >= FETCH_INTERVAL_MS) {
    lastFetchMs = now;
    fetchStatus();
  }

  delay(10);
}
