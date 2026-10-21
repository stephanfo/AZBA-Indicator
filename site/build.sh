#!/usr/bin/env bash
#
# Builds the firmware and assembles the GitHub Pages site in _site/:
# the presentation page, the web installer (ESP Web Tools + manifest + firmware)
# and the backend download.
#
#   ./site/build.sh            build
#   ./site/build.sh --serve    build, then serve on http://localhost:8080
#
# This script is the REFERENCE for .github/workflows/pages.yml: the workflow
# calls it as is, so what works here works in CI.
#
# Needs: PlatformIO (`pio` on the PATH, or PIO=/path/to/pio), npm dependencies
# of site/ (`npm --prefix site ci`), zip, python3.

set -euo pipefail

cd "$(dirname "$0")/.."          # repository root
OUT="_site"
ENV="d1_mini_lite"
PIO="${PIO:-pio}"

if [ ! -d site/node_modules/esp-web-tools ]; then
  echo "Missing site dependencies. Run first:  npm --prefix site ci" >&2
  exit 1
fi

# --- Firmware -----------------------------------------------------------------------------------
# extra/version.py stamps the version into the firmware and writes it next to it.
echo "→ Building the firmware"
"$PIO" run -e "$ENV"
BUILD=".pio/build/$ENV"
VERSION=$(tr -d '[:space:]' < "$BUILD/version.txt")

# --- Assembly -----------------------------------------------------------------------------------
echo "→ Assembling $OUT (version $VERSION)"
rm -rf "$OUT"
mkdir -p "$OUT/firmware" "$OUT/esp-web-tools"

cp site/index.html site/style.css site/app.js site/CNAME "$OUT"/
cp -r site/assets "$OUT"/

# The ESP8266 image is flashed whole at offset 0 (bootloader included).
cp "$BUILD/firmware.bin" "$OUT/firmware/firmware.bin"

# ESP Web Tools manifest. The firmware does not speak Improv, hence no wait for it.
# Erasing is offered on a new install: it clears settings left by another firmware.
cat > "$OUT/firmware/manifest.json" <<MANIFEST
{
  "name": "AZBA Indicator",
  "version": "$VERSION",
  "new_install_prompt_erase": true,
  "new_install_improv_wait_time": 0,
  "builds": [
    {
      "chipFamily": "ESP8266",
      "parts": [{ "path": "firmware.bin", "offset": 0 }]
    }
  ]
}
MANIFEST

# ESP Web Tools is served from this site: no request to a third-party CDN.
cp site/node_modules/esp-web-tools/dist/web/*.js "$OUT/esp-web-tools/"
cp site/node_modules/esp-web-tools/LICENSE "$OUT/esp-web-tools/LICENSE"

# The backend, ready to upload by FTP.
zip -q -j "$OUT/azba-serveur.zip" php/azba.php php/azba_lib.php LICENSE

# Without this file GitHub Pages runs Jekyll, which drops some paths.
touch "$OUT/.nojekyll"

DOMAIN=$(tr -d '[:space:]' < site/CNAME)
cat > "$OUT/robots.txt" <<ROBOTS
User-agent: *
Allow: /

Sitemap: https://$DOMAIN/sitemap.xml
ROBOTS
cat > "$OUT/sitemap.xml" <<SITEMAP
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://$DOMAIN/</loc></url></urlset>
SITEMAP

# --- Checks -------------------------------------------------------------------------------------
# Silent failures: a site that builds but whose installer has no firmware, or
# whose sharing metadata point at another domain, looks broken to nobody.
echo "→ Checks"
fail=0
for f in index.html style.css app.js CNAME .nojekyll robots.txt sitemap.xml azba-serveur.zip \
         firmware/firmware.bin firmware/manifest.json \
         esp-web-tools/install-button.js \
         assets/favicon.svg assets/img/og.jpg assets/img/azba-front.webp assets/img/azba-inside.webp \
         assets/fonts/geist-sans-latin-400-normal.woff2; do
  if [ ! -s "$OUT/$f" ] && [ "$f" != ".nojekyll" ]; then echo "   ✗ missing: $f"; fail=1; fi
done

if ! python3 -c 'import json,sys; m=json.load(open(sys.argv[1])); assert m["version"] and m["builds"]' \
     "$OUT/firmware/manifest.json" 2>/dev/null; then
  echo "   ✗ firmware/manifest.json is not a valid manifest"; fail=1
fi

# Every page reference to /assets/... must exist (a renamed image would 404 silently).
for ref in $(grep -o '/assets/[^"]*' site/index.html | sort -u); do
  [ -e "$OUT$ref" ] || { echo "   ✗ index.html references a missing file: $ref"; fail=1; }
done

if ! grep -q "https://$DOMAIN/" "$OUT/index.html"; then
  echo "   ✗ index.html does not announce $DOMAIN in its metadata"; fail=1
fi

# No `grep -q` here: it would exit early, and pipefail would report unzip's SIGPIPE.
unzip -l "$OUT/azba-serveur.zip" | grep 'azba_lib.php' > /dev/null \
  || { echo "   ✗ azba-serveur.zip lacks azba_lib.php"; fail=1; }

[ "$fail" -eq 0 ] && echo "   ✓ all in place"
[ "$fail" -eq 0 ] || { echo "Incomplete assembly."; exit 1; }

echo
echo "Site assembled in $OUT/ ($(du -sh "$OUT" | cut -f1)), firmware $VERSION"

if [ "${1:-}" = "--serve" ]; then
  PORT="${PORT:-8080}"
  echo "→ http://localhost:$PORT  (Ctrl+C to stop; PORT=8099 to change)"
  # Web Serial works on http://localhost too, so the installer can be tried locally.
  cd "$OUT" && exec python3 -m http.server "$PORT"
fi
