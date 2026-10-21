#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>
#include <WiFiClientSecureBearSSL.h>
#include <LittleFS.h>
#include <memory>

#include <Adafruit_NeoPixel.h>
#include <ArduinoJson.h>
#include <WiFiManager.h>

#include "AzbaConfig.h" // zone / URL normalization (lib/AzbaConfig, unit-tested on host)

#ifndef FW_VERSION
#define FW_VERSION "dev"  // normally set by extra/version.py
#endif

// ------------ CONFIG LEDS ------------
#define LED_PIN    D4
#define LED_COUNT  6

Adafruit_NeoPixel strip(LED_COUNT, LED_PIN, NEO_GRB + NEO_KHZ800);

enum Mode {
  MODE_CONNECTING,            // Blue solid (WiFi connecting)
  MODE_ERROR_OR_STARTUP,      // White solid (error/startup)
  MODE_CONFIG_PORTAL,         // Purple slow blinking (setup portal open)
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

// --- WiFi and setup portal ---
// How long one WiFi connection attempt may take
const unsigned long WIFI_CONNECT_TIMEOUT_MS = 30000UL;  // 30 seconds
// Connection attempts at boot before opening the setup portal
const int WIFI_BOOT_ATTEMPTS = 3;
// Failed reconnections while running before rebooting (the boot path then
// opens the portal if the network is still unreachable)
const unsigned int WIFI_FAILURES_BEFORE_REBOOT = 3;    // 3 refresh cycles = 15 minutes
// Portal lifetime when opened on request or after WiFi failures. When it expires
// the device reboots and tries the saved network again. A device that has no
// settings yet keeps its portal open until it is configured.
const unsigned long PORTAL_TIMEOUT_S = 300;            // 5 minutes

// --- Double reset: open the portal on demand ---
// The D1 mini has no user button. Restarting the device twice within this window
// (RESET button pressed twice, or power unplugged and plugged back twice) opens
// the setup portal. A marker file survives the restart, including a power cut.
const unsigned long DOUBLE_RESET_WINDOW_MS = 10000UL;  // 10 seconds
const char* DOUBLE_RESET_FILE = "/drd";
bool doubleResetArmed = false;

// --- Settings entered in the portal, stored in LittleFS ---
const char* SETTINGS_FILE = "/config.json";
struct Settings {
  char zone[AZBA_ZONE_MAX];  // AZBA zone, normalized (e.g. "R149E")
  char url[AZBA_URL_MAX];    // backend URL (e.g. "https://my-club.fr/azba/azba.php")
};
Settings settings = {"", ""};

// Timestamps and counters
unsigned long lastFetchMs = 0;   // timestamp of last fetch (millis())
unsigned long startupTimeMs = 0; // application startup timestamp
// Number of refresh cycles performed (incremented before each fetch)
// This counter is sent to the server for diagnostics (`refresh_count`)
unsigned long refreshCount = 1; // number of refreshes (5-minute cycles)
// Diagnostics counters
unsigned long totalAttempts = 0;          // total HTTP attempts across retries
unsigned long totalSuccessfulFetches = 0; // total successful fetches
unsigned int consecutiveFailures = 0;     // consecutive failed fetch cycles
unsigned int consecutiveWifiFailures = 0; // consecutive failed WiFi reconnections

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
  unsigned long blinkPeriodMs = 500;

  switch (currentMode) {
    case MODE_CONNECTING:
      color = strip.Color(0, 0, 255);      // Blue
      blinking = false;
      break;
    case MODE_CONFIG_PORTAL:
      color = strip.Color(160, 0, 255);    // Purple slow blinking (setup portal)
      blinking = true;
      blinkPeriodMs = 1000;
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
    bool on = ((millis() / blinkPeriodMs) % 2) == 0;
    if (on) setAllPixels(color);
    else    setAllPixels(0);
  } else {
    setAllPixels(color);
  }

  strip.show();
}

// ------------ DOUBLE RESET ------------
// Returns true if the previous boot happened less than DOUBLE_RESET_WINDOW_MS
// ago. Otherwise arms the marker, which serviceDoubleReset() clears once the
// window has passed.
bool checkDoubleReset() {
  if (LittleFS.exists(DOUBLE_RESET_FILE)) {
    LittleFS.remove(DOUBLE_RESET_FILE);
    return true;
  }
  File f = LittleFS.open(DOUBLE_RESET_FILE, "w");
  if (f) {
    f.close();
    doubleResetArmed = true;
  }
  return false;
}

void serviceDoubleReset() {
  if (doubleResetArmed && millis() >= DOUBLE_RESET_WINDOW_MS) {
    LittleFS.remove(DOUBLE_RESET_FILE);
    doubleResetArmed = false;
  }
}

// Everything that must keep running while the firmware waits (LEDs, double reset).
void tick() {
  updateLeds();
  serviceDoubleReset();
}

// ------------ SETTINGS ------------
// Loads and re-validates the stored settings. Returns false if missing or
// invalid: the device then opens the portal instead of guessing.
bool loadSettings() {
  File f = LittleFS.open(SETTINGS_FILE, "r");
  if (!f) {
    Serial.println(F("No settings stored"));
    return false;
  }
  StaticJsonDocument<384> doc;
  DeserializationError error = deserializeJson(doc, f);
  f.close();
  if (error) {
    Serial.print(F("Settings unreadable: "));
    Serial.println(error.c_str());
    return false;
  }
  bool zoneOk = azbaNormalizeZone(doc["zone"] | "", settings.zone, sizeof(settings.zone));
  bool urlOk  = azbaNormalizeUrl(doc["url"] | "", settings.url, sizeof(settings.url));
  if (!zoneOk || !urlOk) {
    Serial.println(F("Stored settings are invalid"));
    return false;
  }
  return true;
}

// Normalizes and stores the values typed in the portal. Nothing is written if
// either value is invalid.
bool saveSettings(const char* rawZone, const char* rawUrl) {
  Settings candidate;
  if (!azbaNormalizeZone(rawZone, candidate.zone, sizeof(candidate.zone))) {
    Serial.printf("Rejected AZBA zone: '%s'\n", rawZone);
    return false;
  }
  if (!azbaNormalizeUrl(rawUrl, candidate.url, sizeof(candidate.url))) {
    Serial.printf("Rejected backend URL: '%s'\n", rawUrl);
    return false;
  }

  StaticJsonDocument<384> doc;
  doc["zone"] = candidate.zone;
  doc["url"]  = candidate.url;
  File f = LittleFS.open(SETTINGS_FILE, "w");
  if (!f) {
    Serial.println(F("Cannot write settings"));
    return false;
  }
  serializeJson(doc, f);
  f.close();

  settings = candidate;
  Serial.printf("Settings saved: zone=%s url=%s\n", settings.zone, settings.url);
  return true;
}

// ------------ SETUP PORTAL ------------
// These strings stay in RAM (no PROGMEM): WiFiManager reads them as plain C strings.

// Help shown above the fields of the WiFi page.
const char PORTAL_HELP_HTML[] =
  "<p>Choisissez le réseau Wi-Fi, puis renseignez la zone et le serveur. "
  "Pour ne changer que la zone ou le serveur, laissez SSID et mot de passe vides.<br>"
  "<i>Choose the Wi-Fi network, then fill in the zone and the server. "
  "To change only the zone or the server, leave SSID and password empty.</i></p>"
  "<p><b>Zone AZBA</b> : code de la carte du SIA, par exemple <b>R149E</b>. "
  "Une erreur de saisie afficherait du vert en permanence : vérifiez-le.<br>"
  "<b>Serveur</b> : adresse complète de votre fichier <b>azba.php</b>, "
  "en <b>https://</b> si votre site l'impose.</p>";

// Shown above the fields after a rejected submission.
const char PORTAL_REJECTED_HTML[] =
  "<p><b style='color:#B91C1C'>Zone ou adresse du serveur invalide : rien n'a été enregistré.</b><br>"
  "<i>Invalid zone or server address: nothing was saved.</i></p>";

// Same rules as azbaNormalizeZone / azbaNormalizeUrl, so the browser refuses an
// invalid value before it reaches the device. The firmware checks again anyway.
const char ZONE_INPUT_ATTRS[] =
  "required autocapitalize='characters' placeholder='R149E' "
  "pattern='\\s*(?:[Ll][Ff])?[Rr]\\s*[0-9](?:[\\s0-9A-Za-z.]*[0-9A-Za-z])?\\s*'";
const char URL_INPUT_ATTRS[] =
  "required type='url' placeholder='https://mon-club.fr/azba/azba.php' "
  "pattern='\\s*https?://[^\\s?#/:][^\\s?#]*\\s*'";

// Buttons and links in the colour of the project page.
const char PORTAL_HEAD_HTML[] =
  "<style>button{background-color:#C2410C}a{color:#A83809}</style>";

// Opens the setup portal (access point "AZBA-Indicator-XXXX") and never returns:
// the device reboots once the settings are saved (and the new WiFi, if any,
// connects), or when the portal expires. timeoutS = 0 keeps it open until
// configured.
void runConfigPortal(unsigned long timeoutS) {
  char apName[32];
  snprintf(apName, sizeof(apName), "AZBA-Indicator-%04X", (unsigned)(ESP.getChipId() & 0xFFFF));
  Serial.printf("Opening setup portal: WiFi '%s', http://192.168.4.1 (timeout %lus)\n",
                apName, timeoutS);
  currentMode = MODE_CONFIG_PORTAL;

  WiFiManager wm;
  char notice[sizeof(PORTAL_REJECTED_HTML)] = "";  // filled after a rejected save
  WiFiManagerParameter noticeParam(notice);
  WiFiManagerParameter help(PORTAL_HELP_HTML);
  WiFiManagerParameter zoneParam("zone", "Zone AZBA / AZBA zone", settings.zone,
                                 AZBA_ZONE_MAX - 1, ZONE_INPUT_ATTRS);
  WiFiManagerParameter urlParam("url", "Serveur / Server (azba.php)", settings.url,
                                AZBA_URL_MAX - 1, URL_INPUT_ATTRS);
  wm.addParameter(&noticeParam);
  wm.addParameter(&help);
  wm.addParameter(&zoneParam);
  wm.addParameter(&urlParam);

  // Called when the WiFi form is submitted, before the connection attempt.
  bool settingsSaved = false;
  bool keepSavedWifi = false;
  unsigned long savedAtMs = 0;
  wm.setSaveParamsCallback([&]() {
    settingsSaved = saveSettings(zoneParam.getValue(), urlParam.getValue());
    // SSID and password left empty: only the zone or the server changed, and
    // WiFiManager will not try to connect. Reboot ourselves (see loop below).
    keepSavedWifi = wm.server->arg(F("s")).length() == 0 &&
                    wm.server->arg(F("p")).length() == 0 &&
                    WiFi.SSID().length() > 0;
    savedAtMs = millis();
    strcpy(notice, settingsSaved ? "" : PORTAL_REJECTED_HTML);
  });

  const char* menu[] = {"wifi", "info", "restart"};
  wm.setMenu(menu, 3);
  wm.setTitle("AZBA Indicator " FW_VERSION);
  wm.setCustomHeadElement(PORTAL_HEAD_HTML);
  wm.setShowInfoUpdate(false);  // no firmware upload from the portal
  wm.setShowInfoErase(false);
  wm.setConnectTimeout(WIFI_CONNECT_TIMEOUT_MS / 1000);
  wm.setConfigPortalTimeout(timeoutS);
  wm.setConfigPortalBlocking(false);
  wm.startConfigPortal(apName);

  while (true) {
    if (wm.process()) {
      // New credentials saved and connected. Reboot to start cleanly; if the
      // zone/URL were rejected, the boot path reopens the portal.
      Serial.println(F("Portal: WiFi connected, rebooting"));
      break;
    }
    // Leave a few seconds for the confirmation page to reach the phone.
    if (settingsSaved && keepSavedWifi && millis() - savedAtMs > 3000) {
      Serial.println(F("Portal: settings saved, keeping WiFi, rebooting"));
      break;
    }
    if (!wm.getConfigPortalActive()) {
      Serial.println(F("Portal closed or expired, rebooting"));
      break;
    }
    tick();
    delay(10);
  }

  delay(500);
  ESP.restart();
}

// ------------ HTTP + JSON ------------
void softReboot() {
  Serial.println(F("\n\n=== SYSTEM REBOOT ===\n"));
  delay(1000);
  ESP.restart();
}

// Connects to the saved network, keeping the LEDs blue while waiting.
bool connectWiFi() {
  currentMode = MODE_CONNECTING;  // Switch to blue

  WiFi.mode(WIFI_STA);
  WiFi.begin();  // credentials saved by the setup portal

  unsigned long start = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - start < WIFI_CONNECT_TIMEOUT_MS) {
    delay(100);
    tick();
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.print(F("Connected, IP: "));
    Serial.println(WiFi.localIP());
    delay(2000);  // Allow connection to stabilize
    return true;
  }
  Serial.println(F("WiFi connection failed"));
  currentMode = MODE_ERROR_OR_STARTUP;
  return false;
}

void reconnectWiFi() {
  // Reconnect attempt while running. After WIFI_FAILURES_BEFORE_REBOOT failed
  // cycles, reboot: the boot path retries and opens the portal if needed.
  Serial.println(F("Attempting WiFi reconnect..."));
  if (connectWiFi()) {
    consecutiveWifiFailures = 0;
    return;
  }
  consecutiveWifiFailures++;
  Serial.printf("WiFi reconnect failed (%u/%u)\n", consecutiveWifiFailures, WIFI_FAILURES_BEFORE_REBOOT);
  if (consecutiveWifiFailures >= WIFI_FAILURES_BEFORE_REBOOT) {
    softReboot();
  }
}

// fetchStatus()
// - checks WiFi connection and attempts reconnect if necessary
// - builds the request URL including two additional query parameters:
//     `refresh_count`: number of successful refresh cycles already performed (does NOT include current)
//     `attempt_count`: number of the current attempt (1..N)
// - performs the HTTP(S) request and parses the returned JSON
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

  // HTTPS backends: the certificate is not checked (no CA store fits in 1 MB of
  // flash, and a pinned fingerprint would break at each renewal). TLS still
  // prevents passive tampering; plain HTTP remains supported.
  std::unique_ptr<WiFiClient> client;
  if (azbaIsHttps(settings.url)) {
    BearSSL::WiFiClientSecure* secureClient = new BearSSL::WiFiClientSecure();
    secureClient->setInsecure();
    client.reset(secureClient);
  } else {
    client.reset(new WiFiClient());
  }
  client->setTimeout(10000);        // 10 seconds read timeout

  HTTPClient http;
  http.setTimeout(10000);           // 10 seconds timeout
  http.setFollowRedirects(HTTPC_STRICT_FOLLOW_REDIRECTS);

  bool success = false;
  // Retry loop: perform the initial attempt (attempt=0)
  // then up to FETCH_MAX_RETRIES retries (attempt = 1..FETCH_MAX_RETRIES)
  for (int attempt = 0; attempt <= FETCH_MAX_RETRIES; attempt++) {
    Serial.printf("Attempt %d/%d\n", attempt + 1, FETCH_MAX_RETRIES + 1);

    // Count this HTTP attempt for diagnostics
    totalAttempts++;

    // Build URL in a stack buffer to avoid String heap fragmentation on ESP8266
    char fullUrl[256];
    if (!azbaBuildRequestUrl(fullUrl, sizeof(fullUrl), settings.url, settings.zone,
                             refreshCount, attempt + 1)) {
      Serial.println(F("Warning: URL truncated"));
    }
    Serial.print(F("Request URL: "));
    Serial.println(fullUrl);

    if (!http.begin(*client, fullUrl)) {
      // Error initializing HTTP request (e.g. bad URL, socket)
      Serial.println(F("http.begin() error"));
      http.end();
      client->stop();

    } else {
      int httpCode = http.GET();
      // Check returned HTTP status code
      if (httpCode != HTTP_CODE_OK) {
        Serial.printf("HTTP error: %d\n", httpCode);
        http.end();
        client->stop();

      } else {
        String payload = http.getString();
        http.end();
        client->stop();

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

          // The backend lists active zones only: a zone absent from the answer
          // is inactive. This is why settings.zone must be normalized exactly
          // like the backend's keys (see lib/AzbaConfig).
          if (!zones.containsKey(settings.zone)) {
            Serial.print(F("JSON.zones does not contain '"));
            Serial.print(settings.zone);
            Serial.println(F("'"));
            currentMode = MODE_INACTIVE;
            success = true;
            refreshCount++; // Zone not found still counts as a valid refresh cycle
            break;
          }

          JsonObject z = zones[settings.zone];

          bool isActiveNow       = z["is_active_now"]       | false;
          bool willBeActive      = z["will_be_active"]      | false;
          bool willBeActiveSoon  = z["will_be_active_soon"] | false;

          Serial.printf("%s.is_active_now       = %s\n", settings.zone, isActiveNow ? "true" : "false");
          Serial.printf("%s.will_be_active      = %s\n", settings.zone, willBeActive ? "true" : "false");
          Serial.printf("%s.will_be_active_soon = %s\n", settings.zone, willBeActiveSoon ? "true" : "false");

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
        tick();
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
  Serial.printf("\nAZBA Indicator %s\n", FW_VERSION);

  strip.begin();
  strip.show();
  currentMode = MODE_ERROR_OR_STARTUP;  // White during startup
  updateLeds();

  // Formats the partition on first boot after flashing.
  if (!LittleFS.begin()) {
    Serial.println(F("LittleFS mount failed"));
  }
  bool portalRequested = checkDoubleReset();
  bool configured = loadSettings();

  WiFi.mode(WIFI_STA);
  WiFi.hostname("azba-indicator");

  // First boot (or settings lost): the portal stays open until configured.
  if (!configured || !WiFi.SSID().length()) {
    runConfigPortal(0);
  }
  Serial.printf("Zone %s, backend %s\n", settings.zone, settings.url);

  if (portalRequested) {
    Serial.println(F("Double reset detected"));
    runConfigPortal(PORTAL_TIMEOUT_S);
  }

  Serial.printf("Connecting to WiFi: %s\n", WiFi.SSID().c_str());
  bool connected = false;
  for (int attempt = 1; attempt <= WIFI_BOOT_ATTEMPTS && !connected; attempt++) {
    Serial.printf("WiFi attempt %d/%d\n", attempt, WIFI_BOOT_ATTEMPTS);
    connected = connectWiFi();
  }
  if (!connected) {
    // Wrong password, new router, network down...: let the user fix it. If
    // nobody does, the portal expires and the device tries again.
    Serial.println(F("WiFi unreachable -> setup portal"));
    runConfigPortal(PORTAL_TIMEOUT_S);
  }

  fetchStatus(); // first request

  lastFetchMs = millis();
  startupTimeMs = millis();  // Record startup time
}

void loop() {
  tick();

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
