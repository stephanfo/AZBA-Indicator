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

#endif // SECRETS_EXAMPLE_H
