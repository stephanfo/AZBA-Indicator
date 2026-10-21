// Host-side unit tests for lib/AzbaConfig: pio test -e native

#include <string.h>
#include <unity.h>

#include "AzbaConfig.h"

void setUp() {}
void tearDown() {}

static void assertZone(const char* in, const char* expected) {
  char out[AZBA_ZONE_MAX];
  TEST_ASSERT_TRUE_MESSAGE(azbaNormalizeZone(in, out, sizeof(out)), in);
  TEST_ASSERT_EQUAL_STRING(expected, out);
}

static void assertZoneRejected(const char* in) {
  char out[AZBA_ZONE_MAX] = "garbage";
  TEST_ASSERT_FALSE_MESSAGE(azbaNormalizeZone(in, out, sizeof(out)), in);
  TEST_ASSERT_EQUAL_STRING("", out);
}

void test_zone_keeps_valid_codes() {
  assertZone("R149E", "R149E");
  assertZone("R45S3", "R45S3");
  assertZone("R45N5.1", "R45N5.1");
  assertZone("R45B", "R45B");
}

// The backend upper-cases its filter but the firmware looks the zone up by exact
// key: a lower-case code would never be found and would show green forever.
void test_zone_is_upper_cased() {
  assertZone("r149e", "R149E");
  assertZone("r45n5.1", "R45N5.1");
}

void test_zone_drops_spaces() {
  assertZone("  R149E ", "R149E");
  assertZone("R 149 E", "R149E");
  assertZone("\tR149E\r\n", "R149E");
}

void test_zone_strips_icao_prefix() {
  assertZone("LFR149E", "R149E");
  assertZone("lfr45s3", "R45S3");
}

void test_zone_rejects_implausible_codes() {
  assertZoneRejected("");
  assertZoneRejected("   ");
  assertZoneRejected("R");
  assertZoneRejected("149E");
  assertZoneRejected("RA");
  assertZoneRejected("LF149E");
  assertZoneRejected("R149E.");
  assertZoneRejected("R149-E");
  assertZoneRejected("R149É");
  assertZoneRejected("R1234567890ABCDEFG");
}

void test_zone_rejects_too_small_buffer() {
  char out[4];
  TEST_ASSERT_FALSE(azbaNormalizeZone("R149E", out, sizeof(out)));
  TEST_ASSERT_EQUAL_STRING("", out);
}

static void assertUrl(const char* in, const char* expected) {
  char out[AZBA_URL_MAX];
  TEST_ASSERT_TRUE_MESSAGE(azbaNormalizeUrl(in, out, sizeof(out)), in);
  TEST_ASSERT_EQUAL_STRING(expected, out);
}

static void assertUrlRejected(const char* in) {
  char out[AZBA_URL_MAX] = "garbage";
  TEST_ASSERT_FALSE_MESSAGE(azbaNormalizeUrl(in, out, sizeof(out)), in);
  TEST_ASSERT_EQUAL_STRING("", out);
}

void test_url_accepts_http_and_https() {
  assertUrl("http://example.fr/azba/azba.php", "http://example.fr/azba/azba.php");
  assertUrl("https://example.fr/azba.php", "https://example.fr/azba.php");
  assertUrl("http://192.168.1.10:8080/azba.php", "http://192.168.1.10:8080/azba.php");
  assertUrl("  https://example.fr/azba.php  ", "https://example.fr/azba.php");
}

void test_url_rejects_bad_input() {
  assertUrlRejected("");
  assertUrlRejected("example.fr/azba.php");
  assertUrlRejected("ftp://example.fr/azba.php");
  assertUrlRejected("http://");
  assertUrlRejected("https:///azba.php");
  assertUrlRejected("http://example.fr/a zba.php");
  assertUrlRejected("http://example.fr/azba.php?azba=R149E");
  assertUrlRejected("http://example.fr/azba.php#top");
  assertUrlRejected("http://example.fr/\"><script>");
}

void test_url_rejects_too_long() {
  char longUrl[AZBA_URL_MAX + 20] = "https://example.fr/";
  size_t len = strlen(longUrl);
  memset(longUrl + len, 'a', sizeof(longUrl) - len - 1);
  longUrl[sizeof(longUrl) - 1] = '\0';
  assertUrlRejected(longUrl);
}

void test_is_https() {
  TEST_ASSERT_TRUE(azbaIsHttps("https://example.fr/azba.php"));
  TEST_ASSERT_FALSE(azbaIsHttps("http://example.fr/azba.php"));
  TEST_ASSERT_FALSE(azbaIsHttps(nullptr));
}

void test_request_url() {
  char out[256];
  TEST_ASSERT_TRUE(azbaBuildRequestUrl(out, sizeof(out), "https://example.fr/azba.php", "R149E", 12, 2));
  TEST_ASSERT_EQUAL_STRING("https://example.fr/azba.php?azba=R149E&refresh_count=12&attempt_count=2", out);
}

void test_request_url_reports_truncation() {
  char out[20];
  TEST_ASSERT_FALSE(azbaBuildRequestUrl(out, sizeof(out), "https://example.fr/azba.php", "R149E", 1, 1));
}

int main(int, char**) {
  UNITY_BEGIN();
  RUN_TEST(test_zone_keeps_valid_codes);
  RUN_TEST(test_zone_is_upper_cased);
  RUN_TEST(test_zone_drops_spaces);
  RUN_TEST(test_zone_strips_icao_prefix);
  RUN_TEST(test_zone_rejects_implausible_codes);
  RUN_TEST(test_zone_rejects_too_small_buffer);
  RUN_TEST(test_url_accepts_http_and_https);
  RUN_TEST(test_url_rejects_bad_input);
  RUN_TEST(test_url_rejects_too_long);
  RUN_TEST(test_is_https);
  RUN_TEST(test_request_url);
  RUN_TEST(test_request_url_reports_truncation);
  return UNITY_END();
}
