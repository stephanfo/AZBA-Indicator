// lib/AzbaConfig/src/AzbaConfig.h
// Pure rules for the settings typed in the captive portal (AZBA zone, backend URL)
// and for the request URL built from them.
//
// No Arduino dependency on purpose: these functions run unchanged on the device
// and on the host, where `pio test -e native` checks them.
//
// SAFETY: the zone code is the one setting that can turn into a false "inactive".
// The backend only lists zones that are active, and the firmware treats a zone
// missing from the answer as inactive (green). A zone code that does not match
// the backend's key (wrong case, "LF" prefix, stray space) would therefore show
// green forever. azbaNormalizeZone() is what keeps the two keys identical.

#ifndef AZBA_CONFIG_H
#define AZBA_CONFIG_H

#include <stddef.h>

// Buffer sizes, terminating NUL included.
#define AZBA_ZONE_MAX 16   // e.g. "R45N5.1"
#define AZBA_URL_MAX  160  // e.g. "https://my-club.fr/azba/azba.php"

// Normalizes a zone code typed by a user into the backend's key format:
// trims and drops spaces, upper-cases, strips the ICAO "LF" prefix
// ("lfr149e " -> "R149E"). Returns false (and leaves `out` empty) if the result
// is not a plausible AZBA code: 'R', then a digit, then digits, letters or dots,
// not ending with a dot.
bool azbaNormalizeZone(const char* in, char* out, size_t outSize);

// Normalizes a backend URL typed by a user (trims surrounding spaces) and checks
// it: http:// or https://, a host, no space, no query string or fragment (the
// firmware appends its own "?azba=..."), and it fits in AZBA_URL_MAX.
// Returns false (and leaves `out` empty) otherwise.
bool azbaNormalizeUrl(const char* in, char* out, size_t outSize);

// True if the URL uses TLS (needs a secure client on the device).
bool azbaIsHttps(const char* url);

// Builds "<base>?azba=<zone>&refresh_count=<n>&attempt_count=<a>".
// Returns false if it does not fit in `out`.
bool azbaBuildRequestUrl(char* out, size_t outSize, const char* base, const char* zone,
                         unsigned long refreshCount, int attempt);

#endif // AZBA_CONFIG_H
