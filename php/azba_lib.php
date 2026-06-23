<?php
/**
 * azba_lib.php — self-contained, reusable SIA AZBA client + analysis library.
 *
 * Drop this single file into any PHP project, `require` it, and call
 * azba_fetch() to get the current AZBA zone activations as a plain PHP array.
 * No Composer, no framework. Only the host project's request/response glue
 * (e.g. azba.php) needs to be written per project — this file is the reusable
 * core.
 *
 *   require 'azba_lib.php';
 *   $result = azba_fetch('R149E');   // or azba_fetch() for all zones
 *   // $result = ['metadata' => [...], 'zones' => [...]]  -> serialize as you wish
 *
 * The library does the SIA querying (auth + the two endpoints + retry), decodes
 * the response, validates it (fail-safe guards), and computes the activation
 * flags. It NEVER does echo / exit / reads $_GET; it returns data or throws.
 *
 * ---------------------------------------------------------------------------
 * SIA backend API (the same one the "AZBA2" web app at
 * https://www.sia.aviation-civile.gouv.fr/azbaEx/ consumes), base AZBA_API_BASE:
 *   1. v3/custom/currentDate
 *        -> { "rtba": "...", "startDate": "...", "endDate": "..." }
 *        Gives the validity interval and the time window for call #2.
 *   2. v3/r_t_b_as?itemsPerPage=600&debutIntervalTemps=<start>&finIntervalTemps=<end>
 *        -> JSON array of zones, each with codeId (e.g. "LFR149E") and a
 *           timeSlots[] array of { startTime, endTime } in ISO-8601 UTC.
 *
 * Authentication (header "AUTH", required, else HTTP 401):
 *   AUTH = base64( json_encode( { "tokenUri": sha512( SHARE_SECRET . "/api/" . <path> ) } ) )
 *   where <path> is the exact request path+query after "/api/". The hashed
 *   string must be byte-identical to the URL sent, so "+" in the date params is
 *   encoded as "%2B" in BOTH the URL and the hashed path (a literal "+" -> 500).
 *
 * Re-extracting AZBA_SHARE_SECRET / API version after a SIA redeploy:
 *   1. Fetch https://www.sia.aviation-civile.gouv.fr/azbaEx/ , note the
 *      main.<hash>.js bundle referenced in the HTML.
 *   2. In that bundle, grep for `share_secret:"..."` (-> AZBA_SHARE_SECRET) and
 *      `azbaApiVersion:"v3/"` / `baseUrl:"https://.../api/"` (-> AZBA_API_BASE).
 *   To override without editing this file, `define()` the constants before the
 *   `require`, or pass 'apiBase' / 'shareSecret' in the azba_fetch() $options.
 *
 * ---------------------------------------------------------------------------
 * SAFETY / FAIL-SAFE DESIGN (read before changing):
 *   This data can drive a physical AZBA indicator. A *false "inactive"* is
 *   dangerous: it could imply an actually-active low-altitude military zone is
 *   clear. So the library NEVER returns data it is not confident in — any doubt
 *   (network error, auth rejected, unexpected response shape, stale validity
 *   window, unparsable times) throws an AzbaException. The caller is expected to
 *   turn that into a visible failure (the firmware shows its white ERROR LED)
 *   rather than silently signalling "clear".
 */

// Config — override by define()-ing before require, or via azba_fetch() $options.
if (!defined('AZBA_API_BASE')) {
    define('AZBA_API_BASE', 'https://bo-prod-sofia-vac.sia-france.fr/api/');
}
if (!defined('AZBA_SHARE_SECRET')) {
    define('AZBA_SHARE_SECRET', 'Y9Q3Ve72nN3PnTXmEtKnS4sggmdsigRMWH9kCDGHpCHyenFKKGhDq5vgBWZ4');
}

/**
 * Base type for every error this library raises. Catch this to handle all
 * AZBA failures uniformly (and map them to your own response).
 */
class AzbaException extends RuntimeException
{
}

/**
 * Network / HTTP / auth failure talking to the SIA API. getCode() carries the
 * HTTP status (0 = transport/network error, 401 = auth rejected, ...).
 */
class AzbaFetchException extends AzbaException
{
}

/**
 * Thrown when the upstream data cannot be trusted (unexpected shape, stale
 * window, unparsable dates...).
 */
class AzbaDataException extends AzbaException
{
}

/**
 * Validate the "currentDate" response and return its bounds as DateTimes.
 *
 * The SIA window is a rolling ~24h interval around "now". If "now" is past the
 * window end, the window is inverted, or "now" is implausibly far before the
 * start, the data is stale/wrong and could under-report activations — we refuse
 * rather than risk a false "inactive". A small grace is allowed at the lower
 * bound for clock skew.
 *
 * @param array    $range Decoded currentDate body (expects startDate/endDate).
 * @param DateTime $now   Current instant (injected for testability).
 * @return array{0:DateTime,1:DateTime} [start, end]
 * @throws AzbaDataException
 */
function azba_parse_range(array $range, DateTime $now): array
{
    if (empty($range['startDate']) || empty($range['endDate'])) {
        throw new AzbaDataException('AZBA validity range missing startDate/endDate — API format may have changed');
    }

    try {
        $start = new DateTime($range['startDate']);
        $end   = new DateTime($range['endDate']);
    } catch (Exception $e) {
        throw new AzbaDataException('AZBA validity range has an unparsable date — API format may have changed');
    }

    $graceStart = (clone $start)->sub(new DateInterval('PT6H'));
    if ($end <= $start || $now > $end || $now < $graceStart) {
        throw new AzbaDataException('AZBA validity window is stale or inconsistent — refusing to serve possibly outdated data');
    }

    return [$start, $end];
}

/**
 * Turn the raw "active zones" API array into $data keyed by the legacy zone
 * code (codeId without the "LF" prefix, e.g. "LFR149E" -> "R149E").
 *
 * Strict by design: any unexpected element shape throws rather than being
 * silently dropped — silently dropping an active zone is exactly the dangerous
 * "false inactive". An empty list is legitimate (nothing active) and returns [].
 *
 * Returns the raw extracted activations only — application-specific analysis
 * (activity flags, filtering, counters) is intentionally left to the caller.
 *
 * @param array $apiZones Decoded r_t_b_as body (expected: a plain list).
 * @return array<string,array{activations:array<array{date:string,start_utc:string,end_utc:string}>}>
 * @throws AzbaDataException
 */
function azba_build_zones(array $apiZones): array
{
    // Must be a JSON list. An associative/object shape means the contract
    // changed (e.g. a Hydra/paginated wrapper) -> do not trust.
    if (!empty($apiZones) && array_keys($apiZones) !== range(0, count($apiZones) - 1)) {
        throw new AzbaDataException('AZBA active-zones response is not a plain list — API format may have changed');
    }

    $data = [];

    foreach ($apiZones as $zone) {
        if (!is_array($zone) || empty($zone['codeId']) || !is_string($zone['codeId'])) {
            throw new AzbaDataException('AZBA zone entry missing codeId — API format may have changed');
        }

        // All known zone codes are "LF" + legacy key (e.g. "LFR149E" -> "R149E").
        // A different prefix means the naming changed and we can no longer map
        // to the firmware ZONE_ID safely -> refuse.
        if (strncmp($zone['codeId'], 'LFR', 3) !== 0) {
            throw new AzbaDataException('Unexpected AZBA codeId "' . $zone['codeId'] . '" — zone naming may have changed');
        }
        $zoneName = substr($zone['codeId'], 2); // drop "LF" -> "R149E"

        // A zone is only returned because it HAS activations in the window.
        // Missing/empty timeSlots therefore signals a schema change, not a
        // genuinely idle zone -> refuse rather than report it as inactive.
        if (!isset($zone['timeSlots']) || !is_array($zone['timeSlots']) || count($zone['timeSlots']) === 0) {
            throw new AzbaDataException('AZBA zone "' . $zone['codeId'] . '" has no timeSlots — API format may have changed');
        }

        if (!isset($data[$zoneName])) {
            $data[$zoneName] = ['activations' => []];
        }

        foreach ($zone['timeSlots'] as $slot) {
            if (!is_array($slot) || empty($slot['startTime']) || empty($slot['endTime'])) {
                throw new AzbaDataException('AZBA timeSlot for "' . $zone['codeId'] . '" missing start/end — API format may have changed');
            }
            try {
                $start = new DateTime($slot['startTime']);
                $end   = new DateTime($slot['endTime']);
            } catch (Exception $e) {
                throw new AzbaDataException('AZBA timeSlot for "' . $zone['codeId'] . '" has unparsable times — API format may have changed');
            }

            $data[$zoneName]['activations'][] = [
                'date'      => $start->format('Y-m-d'),
                'start_utc' => $start->format('Y-m-d\TH:i:s\Z'),
                'end_utc'   => $end->format('Y-m-d\TH:i:s\Z'),
            ];
        }
    }

    return $data;
}

// ===========================================================================
// Network layer (SIA query + AUTH). Kept here so the library is self-contained.
// ===========================================================================

/**
 * GET a path on the SIA API (path is everything after "/api/", query included
 * and already URL-encoded). Returns [httpStatus, decodedBody|null].
 *
 * Transport is injectable for testing: pass $options['http'] as a callable
 *   fn(string $url, array $headers): array{0:int, 1:?string}   // [status, rawBody]
 * Otherwise a real cURL GET is used.
 *
 * @param array $options { http?: callable, timeout?: int, apiBase?: string, shareSecret?: string }
 * @return array{0:int,1:mixed}
 */
function azba_http_get(string $pathWithQuery, array $options = []): array
{
    $apiBase = $options['apiBase']     ?? AZBA_API_BASE;
    $secret  = $options['shareSecret'] ?? AZBA_SHARE_SECRET;
    $timeout = $options['timeout']     ?? 15;

    // AUTH header: sha512 over secret + "/api/" + the exact path sent.
    $tokenUri = hash('sha512', $secret . '/api/' . $pathWithQuery);
    $auth     = base64_encode(json_encode(['tokenUri' => $tokenUri]));
    $headers  = [
        'AUTH: ' . $auth,
        'Accept: application/json',
        'User-Agent: AZBA-Indicator-PHP',
    ];
    $url = $apiBase . $pathWithQuery;

    if (isset($options['http']) && is_callable($options['http'])) {
        [$status, $body] = $options['http']($url, $headers);
    } else {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch); // curl_close() is a deprecated no-op since PHP 8.0
        if ($body === false) {
            $body   = null;
            $status = 0;
        }
    }

    return [(int) $status, $body === null ? null : json_decode($body, true)];
}

/**
 * One-call entry point: query the SIA, decode and validate the response, and
 * return the **raw extracted data** as a plain PHP array.
 *
 * This is the reusable boundary: the library handles SIA querying + extraction
 * only. Application-specific analysis (activity flags, zone filtering, metadata
 * counters, output format) is the caller's job — see php/azba.php for an example.
 *
 *   $sia = azba_fetch();
 *   // => [
 *   //   'interval' => ['start_utc' => '...Z', 'end_utc' => '...Z'],
 *   //   'zones'    => [
 *   //     'R45S3' => ['activations' => [
 *   //        ['date' => 'YYYY-MM-DD', 'start_utc' => '...Z', 'end_utc' => '...Z'], ...
 *   //     ]], ...
 *   //   ],
 *   // ]
 *
 * Only zones with at least one activation in the current window are present.
 *
 * @param array $options { now?: DateTime, http?: callable, timeout?: int,
 *                         apiBase?: string, shareSecret?: string }
 * @return array{interval:array{start_utc:string,end_utc:string},zones:array}
 * @throws AzbaFetchException On network / HTTP / auth failure.
 * @throws AzbaDataException  On untrusted / unexpected data.
 */
function azba_fetch(array $options = []): array
{
    $now = $options['now'] ?? new DateTime('now', new DateTimeZone('UTC'));

    // 1. Validity interval (and the time window for the active-zones query).
    [$status, $range] = azba_http_get('v3/custom/currentDate', $options);
    if ($status === 401) {
        throw new AzbaFetchException('AZBA API auth rejected (HTTP 401) — SHARE_SECRET may have rotated', 401);
    }
    if ($status !== 200 || !is_array($range)) {
        throw new AzbaFetchException('Failed to retrieve AZBA validity range from SIA API', $status);
    }

    // Validates shape + freshness; throws AzbaDataException on any doubt.
    [$intervalStart, $intervalEnd] = azba_parse_range($range, $now);

    // 2. Active zones over the interval. The "+" of the ISO offset must be sent
    //    (and hashed) as "%2B" or the API returns HTTP 500 — rawurlencode does so.
    $debut = rawurlencode($range['startDate']);
    $fin   = rawurlencode($range['endDate']);
    $path  = 'v3/r_t_b_as?itemsPerPage=600&debutIntervalTemps=' . $debut . '&finIntervalTemps=' . $fin;

    // Retry once on a transient 5xx / network error.
    [$status, $zones] = azba_http_get($path, $options);
    if ($status >= 500 || $status === 0) {
        [$status, $zones] = azba_http_get($path, $options);
    }
    if ($status === 401) {
        throw new AzbaFetchException('AZBA API auth rejected (HTTP 401) — SHARE_SECRET may have rotated', 401);
    }
    if ($status !== 200 || !is_array($zones)) {
        throw new AzbaFetchException('Failed to retrieve AZBA active zones from SIA API', $status);
    }

    // 3. Validate + extract (throws AzbaDataException on any integrity problem).
    return [
        'interval' => [
            'start_utc' => $intervalStart->format('Y-m-d\TH:i:s\Z'),
            'end_utc'   => $intervalEnd->format('Y-m-d\TH:i:s\Z'),
        ],
        'zones' => azba_build_zones($zones),
    ];
}
