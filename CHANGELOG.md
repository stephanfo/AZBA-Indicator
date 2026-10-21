# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
This project does not yet use formal version tags; entries are grouped by date.

## [Unreleased]

### Removed
- Compile-time secrets: `extra/generate_secrets.py`, `src/secrets.example.h` and
  `src/secrets.h` are no longer used.
- Static IP configuration: use a DHCP reservation on the router.

### Changed
- While running, 3 failed WiFi reconnection cycles (15 minutes) now trigger a
  reboot, so the boot path can reopen the portal.
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
- **Runtime configuration through a captive portal (WiFiManager).** WiFi network,
  AZBA zone and backend URL are entered from a phone on first boot and stored in
  flash (LittleFS `/config.json`); nothing is compiled in any more. The portal
  reopens on a double restart (RESET pressed twice, or power cycled twice within
  10 seconds) and by itself when the saved WiFi stays unreachable at boot (3 × 30 s),
  for 5 minutes. New LED state: purple slow blinking = portal open.
- Zone and URL rules in `lib/AzbaConfig` (upper-case, spaces and `LF` prefix
  dropped, format check), with host unit tests (`pio test -e native`). They keep
  the firmware's zone key identical to the backend's: a lower-case zone used to
  miss the backend's upper-case key and show green forever.
- HTTPS backends (certificate not verified) and HTTP redirects.
- **Browser installation**: `site/` presentation page (French / English,
  azba.ratelet.fr) with an ESP Web Tools install button; `site/build.sh` builds
  the firmware and assembles the site; `.github/workflows/pages.yml` tests, builds
  and deploys it to GitHub Pages. The backend is offered as `azba-serveur.zip`.
- Firmware version (`<commit date>-<git describe>`) stamped by `extra/version.py`,
  shown on the serial port, in the portal and on the page.
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
