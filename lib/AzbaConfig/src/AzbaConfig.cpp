// lib/AzbaConfig/src/AzbaConfig.cpp — see AzbaConfig.h

#include "AzbaConfig.h"

#include <stdio.h>
#include <string.h>

static bool isDigit(char c) { return c >= '0' && c <= '9'; }
static bool isUpper(char c) { return c >= 'A' && c <= 'Z'; }
static char toUpper(char c) { return (c >= 'a' && c <= 'z') ? (char)(c - 'a' + 'A') : c; }
static bool isSpace(char c) { return c == ' ' || c == '\t' || c == '\r' || c == '\n'; }

static bool fail(char* out, size_t outSize) {
  if (outSize > 0) out[0] = '\0';
  return false;
}

bool azbaNormalizeZone(const char* in, char* out, size_t outSize) {
  if (in == nullptr || out == nullptr || outSize == 0) return fail(out, outSize);

  // Upper-case and drop every space ("r 149 e" is still R149E).
  char buf[AZBA_ZONE_MAX + 2];
  size_t n = 0;
  for (const char* p = in; *p; p++) {
    if (isSpace(*p)) continue;
    if (n >= sizeof(buf) - 1) return fail(out, outSize);  // too long to be a zone code
    buf[n++] = toUpper(*p);
  }
  buf[n] = '\0';

  // The SIA lists zones as "LFR149E"; the backend keys them as "R149E".
  const char* code = buf;
  if (strncmp(code, "LFR", 3) == 0) code += 2;

  size_t len = strlen(code);
  if (len < 2 || code[0] != 'R' || !isDigit(code[1]) || code[len - 1] == '.') {
    return fail(out, outSize);
  }
  for (size_t i = 1; i < len; i++) {
    if (!isDigit(code[i]) && !isUpper(code[i]) && code[i] != '.') return fail(out, outSize);
  }
  if (len + 1 > outSize || len + 1 > AZBA_ZONE_MAX) return fail(out, outSize);

  memcpy(out, code, len + 1);
  return true;
}

bool azbaNormalizeUrl(const char* in, char* out, size_t outSize) {
  if (in == nullptr || out == nullptr || outSize == 0) return fail(out, outSize);

  while (isSpace(*in)) in++;
  size_t len = strlen(in);
  while (len > 0 && isSpace(in[len - 1])) len--;

  size_t schemeLen;
  if (strncmp(in, "http://", 7) == 0) {
    schemeLen = 7;
  } else if (strncmp(in, "https://", 8) == 0) {
    schemeLen = 8;
  } else {
    return fail(out, outSize);
  }

  // A host must follow the scheme.
  if (len <= schemeLen || in[schemeLen] == '/' || in[schemeLen] == ':') return fail(out, outSize);

  for (size_t i = 0; i < len; i++) {
    char c = in[i];
    if (isSpace(c) || c == '?' || c == '#' || c == '"' || c == '\'' || c == '<' || c == '>') {
      return fail(out, outSize);
    }
  }
  if (len + 1 > outSize || len + 1 > AZBA_URL_MAX) return fail(out, outSize);

  memcpy(out, in, len);
  out[len] = '\0';
  return true;
}

bool azbaIsHttps(const char* url) {
  return url != nullptr && strncmp(url, "https://", 8) == 0;
}

bool azbaBuildRequestUrl(char* out, size_t outSize, const char* base, const char* zone,
                         unsigned long refreshCount, int attempt) {
  int len = snprintf(out, outSize, "%s?azba=%s&refresh_count=%lu&attempt_count=%d",
                     base, zone, refreshCount, attempt);
  return len >= 0 && (size_t)len < outSize;
}
