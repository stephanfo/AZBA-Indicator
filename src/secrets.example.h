// src/secrets.example.h
// Copy to src/secrets.h (do NOT commit src/secrets.h) and fill your credentials.

#ifndef SECRETS_EXAMPLE_H
#define SECRETS_EXAMPLE_H

// ========== WiFi Credentials ==========
const char* ssid     = "YOUR_SSID";
const char* password = "YOUR_PASSWORD";

// ========== Static IP Configuration ==========
// Uncomment and adapt these values if using static IP:
// #define USE_STATIC_IP
// IPAddress staticIP(192, 168, 1, 2);
// IPAddress gateway(192, 168, 1, 1);
// IPAddress subnet(255, 255, 255, 0);
// IPAddress dns1(8, 8, 8, 8);
// IPAddress dns2(8, 8, 4, 4);

// ========== AZBA Indicator Config ==========
// AZBA zone identifier to monitor
const char* ZONE_ID  = "R149E";   // <--- Adjust to your zone
// Base URL of the AZBA server
const char* URL_BASE = "http://your.website.fr/folder/azba.php";

#endif // SECRETS_EXAMPLE_H
