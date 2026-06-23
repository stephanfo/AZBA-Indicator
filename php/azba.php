<?php
/**
 * azba.php — HTTP request handler for the AZBA Indicator firmware.
 *
 * This is the PROJECT-SPECIFIC layer on top of the reusable SIA library in
 * azba_lib.php. The library does the SIA querying + extraction (raw zone
 * activations); everything in this file is application logic for *this* product:
 *   - the activity flags (is_active_now / will_be_active / will_be_active_soon),
 *     including the +5 min "anticipation" and the 4-hour "soon" window,
 *   - the optional ?azba=<ZONE_ID> filter,
 *   - the metadata counters,
 *   - the JSON output contract the firmware (src/main.cpp) expects.
 *
 * To reuse the AZBA library in another project, copy php/azba_lib.php, call
 * azba_fetch(), and apply whatever analysis/format your project needs — you only
 * rewrite a handler like this one, never the SIA logic.
 *
 * Firmware contract: GET azba.php?azba=<ZONE_ID> returns
 * { "metadata": {...}, "zones": { "<ZONE_ID>": { activations[], is_active_now,
 *   will_be_active, will_be_active_soon }, ... } }.
 *
 * FAIL-SAFE: a false "inactive" could imply an active restricted zone is clear.
 * Any untrusted condition (AzbaException from the library) becomes a non-200,
 * which the firmware shows as its white ERROR LED rather than a false "clear"
 * (green). See the SAFETY section in azba_lib.php.
 */

require __DIR__ . '/azba_lib.php';

date_default_timezone_set('UTC'); // Working in UTC

/**
 * Compute is_active_now / will_be_active / will_be_active_soon per zone.
 *
 * Anticipation: the start of every slot is compared against now+5min so a zone
 * that is about to open is flagged slightly early (avoids an unsignalled switch
 * during the device refresh period). The end is compared against the real $now
 * so a slot is not artificially extended.
 *
 * @param array    $zones Zones as returned by azba_fetch()['zones'] (activations only).
 * @param DateTime $now   Current instant (injected for testability).
 * @return array Same zones, each with the three booleans added.
 */
function azba_compute_flags(array $zones, DateTime $now): array
{
    $now_plus5      = (clone $now)->add(new DateInterval('PT5M'));
    $in4Hours_plus5 = (clone $now)->add(new DateInterval('PT4H'))->add(new DateInterval('PT5M'));

    foreach ($zones as &$zoneData) {
        $zoneData['is_active_now']       = false;
        $zoneData['will_be_active']      = false;
        $zoneData['will_be_active_soon'] = false;

        foreach ($zoneData['activations'] as $slot) {
            $start = new DateTime($slot['start_utc']);
            $end   = new DateTime($slot['end_utc']);

            // is_active_now: anticipate the start by +5 min, keep the end on $now.
            if ($now_plus5 >= $start && $now <= $end) {
                $zoneData['is_active_now'] = true;
            }

            // will_be_active: a slot whose START is still in the future (now+5min).
            if ($start > $now_plus5) {
                $zoneData['will_be_active'] = true;
            }

            // will_be_active_soon: future start within the next 4 hours (+5min).
            if ($start > $now_plus5 && $start <= $in4Hours_plus5) {
                $zoneData['will_be_active_soon'] = true;
            }
        }
    }
    unset($zoneData);

    return $zones;
}

/**
 * Apply the optional ?azba= filter. Never throws / never 404s: an unknown zone
 * yields an empty set (the caller still emits metadata).
 *
 * @param array       $zones     Zones (already flagged).
 * @param string|null $rawFilter Raw filter value (e.g. from $_GET['azba']) or null.
 * @return array Filtered zones (the single requested zone, or [] / the input).
 */
function azba_filter_zone(array $zones, ?string $rawFilter): array
{
    if ($rawFilter === null || trim($rawFilter) === '') {
        return $zones;
    }

    $filterZone = strtoupper(trim($rawFilter));
    $filterZone = preg_replace('/\s+/', '', $filterZone);

    if (isset($zones[$filterZone])) {
        return [$filterZone => $zones[$filterZone]];
    }

    return [];
}

/**
 * Build the metadata block (validity interval + per-flag counters).
 *
 * @param array $zones    Zones to count (already filtered + flagged).
 * @param array $interval ['start_utc' => '...Z', 'end_utc' => '...Z'] from azba_fetch().
 * @return array
 */
function azba_build_metadata(array $zones, array $interval): array
{
    $zonesTotal = $zonesActiveNow = $zonesWillBeActive = $zonesWillBeActiveSoon = 0;

    foreach ($zones as $zoneData) {
        $zonesTotal++;
        if (!empty($zoneData['is_active_now'])) {
            $zonesActiveNow++;
        }
        if (!empty($zoneData['will_be_active'])) {
            $zonesWillBeActive++;
        }
        if (!empty($zoneData['will_be_active_soon'])) {
            $zonesWillBeActiveSoon++;
        }
    }

    return [
        'interval_start_utc'        => $interval['start_utc'] ?? null,
        'interval_end_utc'          => $interval['end_utc'] ?? null,
        'zones_total'               => $zonesTotal,
        'zones_active_now'          => $zonesActiveNow,
        'zones_will_be_active'      => $zonesWillBeActive,
        'zones_will_be_active_soon' => $zonesWillBeActiveSoon,
    ];
}

/**
 * Emit a JSON error envelope and stop. Used for every non-trustworthy state so
 * the firmware falls back to its (safe) ERROR display.
 */
function fail(int $httpCode, string $message): void
{
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// Guard: tests `define('AZBA_NO_MAIN', true)` then `require` this file to reach
// the functions above without performing the request.
if (!defined('AZBA_NO_MAIN')) {
    try {
        $now = new DateTime('now', new DateTimeZone('UTC'));

        // Library: SIA query + raw extraction.
        $sia = azba_fetch(['now' => $now]);

        // Application logic: flags -> filter -> metadata -> output.
        $zones = azba_compute_flags($sia['zones'], $now);
        $zones = azba_filter_zone($zones, $_GET['azba'] ?? null);
        $metadata = azba_build_metadata($zones, $sia['interval']);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            ['metadata' => $metadata, 'zones' => $zones],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
    } catch (AzbaException $e) {
        // Network/auth (AzbaFetchException) or untrusted data (AzbaDataException):
        // fail visibly so the firmware never shows a false "inactive".
        fail(502, $e->getMessage());
    }
}
