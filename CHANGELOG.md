# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
This project does not yet use formal version tags; entries are grouped by date.

## [Unreleased]

### Changed
- **`php/azba.php` rewritten to use the official SIA JSON API.** The old version
  scraped the HTML of `/schedules` and searched for the text
  `"Liste des zones activées"`. The SIA replaced that page with a JavaScript
  single-page app ("AZBA2"), so the text disappeared and the script failed with
  `Bloc "Liste des zones activées" introuvable`. It now queries the structured
  endpoints the web app itself uses (`/api/v3/custom/currentDate` and
  `/api/v3/r_t_b_as`), which are immune to wording/markup changes. The JSON
  output contract is unchanged, so **no firmware change is required**.
- Split the backend into `php/azba.php` (thin entry: network + request/response)
  and `php/azba_lib.php` (pure, testable logic) so the safety guards and time
  math can be unit-tested deterministically. Output is byte-for-byte identical.
- **`php/azba_lib.php` is now a self-contained, reusable SIA library** with a
  clean boundary: it does the SIA querying + decoding + extraction only, exposed
  as a single `azba_fetch()` call returning the **raw data**
  (`['interval' => …, 'zones' => [ 'R45S3' => ['activations' => […]], … ]]`).
  All product-specific analysis — the activity flags (`is_active_now` /
  `will_be_active` / `will_be_active_soon`, the +5 min anticipation and 4-hour
  window), the `?azba=` filter, and the metadata counters — moved **into**
  `php/azba.php`, which is now the swappable request handler. Other projects copy
  `azba_lib.php` (no Composer), call `azba_fetch()`, and apply their own logic.
  The HTTP transport is injectable (`$options['http']`) so the full pipeline is
  testable offline, and the API base / shared secret are overridable via
  `define()` or `$options`. Firmware JSON output remains byte-for-byte identical.
- Errors now use a typed hierarchy: `AzbaException` (base), `AzbaFetchException`
  (network / HTTP / auth, with the HTTP status in `getCode()`), and
  `AzbaDataException` (untrusted data). Callers catch `AzbaException` to handle
  all failures and map them to their own response.

### Added
- **Fail-safe data guards.** A false "inactive" is dangerous (it could imply an
  active restricted zone is clear). The backend now returns a non-200 — surfaced
  as the firmware's white ERROR LED — on any untrusted condition: rejected auth,
  unexpected response shape, missing/empty time slots, unparsable times, or a
  stale/inconsistent validity window. It never emits a "200 OK" it isn't
  confident in.
- Single retry on transient 5xx / network errors when fetching active zones.
- **Zero-dependency PHP test battery** (`tests/run.php`, no Composer/PHPUnit):
  covers every fail-safe guard, the validity-window freshness checks, the
  `is_active_now` / `will_be_active` / `will_be_active_soon` boundary math, the
  zone filter, and metadata counters. An opt-in live mode
  (`AZBA_LIVE=1 php tests/run.php`) re-checks the upstream SIA contract so an API
  change is caught early. Offline fixtures live in `tests/fixtures/`.
- Documentation in the `php/azba.php` header explaining the API, the `AUTH`
  header scheme, and how to re-extract the API base / shared secret from the SIA
  web app bundle if the SIA rotates them.

### Fixed
- Indicator no longer fails permanently after the SIA AZBA page redesign.

## [2026-06-23] — Documentation & configuration

### Changed
- Improved LED state descriptions, added the AZBA zone configuration to
  `secrets.example.h`, and updated the base URL.

## [2025-11-22]

### Changed
- `refreshCount` starts at 1 and is incremented even when no AZBA zones are
  found; added the "inside" product image.

## [2025-11-21] — Initial release

### Added
- ESP8266 firmware driving WS2812B LEDs to show AZBA zone status (active now /
  soon / later / inactive), with WiFi resilience and periodic auto-reboot.
- PHP backend, 3D-printable enclosure, and secrets tooling. Default zone aligned
  to `R149E` to match the reference hardware.
