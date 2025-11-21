<?php
/**
 * azba_json.php
 *
 * Returned JSON:
 * {
 *   "metadata": {
 *     "interval_start_utc": "2025-11-15T08:00:00Z",
 *     "interval_end_utc":   "2025-11-18T17:00:00Z",
 *     "zones_total":        12,
 *     "zones_active_now":   2,
 *     "zones_will_be_active": 5,
 *     "zones_will_be_active_soon": 3
 *   },
 *   "zones": {
 *     "R45S3": {
 *       "activations": [
 *         { "date": "2025-11-17", "start_utc": "...", "end_utc": "..." },
 *         ...
 *       ],
 *       "is_active_now": true/false,
 *       "will_be_active": true/false,
 *       "will_be_active_soon": true/false
 *     },
 *     ...
 *   }
 * }
 *
 * Optional parameter:
 *   ?azba=R45S3  -> returns only zone R45S3 (but always with metadata)
 *
 * Fields for each zone:
 *   - is_active_now: true if the zone is active now
 *   - will_be_active: true if the zone will be active in the future (beyond now)
 *   - will_be_active_soon: true if the zone will be active within the next 4 hours
 */

date_default_timezone_set('UTC'); // Working in UTC

$url = 'https://www.sia.aviation-civile.gouv.fr/schedules';

// -------------------------------------------------------------------------
// 1. Fetch the page
// -------------------------------------------------------------------------
$context = stream_context_create([
    'http' => [
        'method'  => 'GET',
        'header'  => "User-Agent: AZBA-Scraper-PHP\r\n",
        'timeout' => 15,
    ]
]);

$html = @file_get_contents($url, false, $context);

if ($html === false) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Failed to retrieve SIA page'], JSON_UNESCAPED_UNICODE);
    exit;
}

// -------------------------------------------------------------------------
// 2. Extract the "Active zones list" section as plain text
// -------------------------------------------------------------------------
$text = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');

// Isolate the section between "Liste des zones activées" and "Au delà du"
$startPos = mb_stripos($text, 'Liste des zones activées');
if ($startPos === false) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Active zones list block not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$endPos = mb_stripos($text, 'Au delà du');
if ($endPos === false) {
    $section = mb_substr($text, $startPos);
} else {
    $section = mb_substr($text, $startPos, $endPos - $startPos);
}

// Extract the display interval "du ... à ... UTC" / "à ... à ... UTC"
$intervalStart = null;
$intervalEnd   = null;

// Example in page: "du 15/11/2025 à 07:29 UTC"
if (preg_match('/du\s+(\d{2}\/\d{2}\/\d{4})\s+à\s+(\d{2}:\d{2})\s+UTC/i', $section, $m1)) {
    $date1 = $m1[1]; // 15/11/2025
    $time1 = $m1[2]; // 07:29
    $intervalStart = DateTime::createFromFormat('d/m/Y H:i', "$date1 $time1", new DateTimeZone('UTC'));
}

// Example in page: "à 18/11/2025 à 07:29 UTC"
if (preg_match('/à\s+(\d{2}\/\d{2}\/\d{4})\s+à\s+(\d{2}:\d{2})\s+UTC/i', $section, $m2)) {
    $date2 = $m2[1]; // 18/11/2025
    $time2 = $m2[2]; // 07:29
    $intervalEnd = DateTime::createFromFormat('d/m/Y H:i', "$date2 $time2", new DateTimeZone('UTC'));
}

// -------------------------------------------------------------------------
// 3. Parse line by line (date -> zone -> time slots)
// -------------------------------------------------------------------------
$lines = preg_split('/\R+/', trim($section));
$data = [];
$currentDate = null;

for ($i = 0, $n = count($lines); $i < $n; $i++) {
    $line = trim($lines[$i]);
    if ($line === '') {
        continue;
    }

    // Skip header
    if (stripos($line, 'Liste des zones activées') !== false) {
        continue;
    }
    if (stripos($line, 'Date') === 0 || stripos($line, 'Zone') === 0 ||
        stripos($line, 'Créneaux horaires') !== false) {
        continue;
    }

    // Date format 15/11/2025
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $line, $m)) {
        $day   = $m[1];
        $month = $m[2];
        $year  = $m[3];
        $currentDate = sprintf('%04d-%02d-%02d', $year, $month, $day);
        continue;
    }

    // No active zone
    if (stripos($line, 'Aucune zone active') !== false) {
        continue;
    }

    // RTBA zone name, ex: R45S3, R142A, R45N5.1, etc.
    if (preg_match('/^R[0-9A-Z.]+$/', $line)) {
        $zoneName = $line;

        if ($currentDate === null) {
            // If no date defined, skip for safety (skip)
            continue;
        }

        if (!isset($data[$zoneName])) {
            $data[$zoneName] = [
                'activations'         => [],
                'is_active_now'       => false,
                'will_be_active'      => false,
                'will_be_active_soon' => false,
            ];
        }

        // Look for time slots in following lines
        for ($j = $i + 1; $j < $n; $j++) {
            $tline = trim($lines[$j]);
            if ($tline === '' || $tline === '-') {
                continue;
            }

            // If we encounter a new date or a new zone, stop
            if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $tline) ||
                preg_match('/^R[0-9A-Z.]+$/', $tline) ||
                stripos($tline, 'Aucune zone active') !== false) {
                break;
            }

            // Extract all HHMM-HHMM time slots in the line
            if (preg_match_all(
                '/\b([01][0-9]|2[0-3])[0-5][0-9]-([01][0-9]|2[0-3])[0-5][0-9]\b/',
                $tline,
                $matches,
                PREG_SET_ORDER
            )) {
                foreach ($matches as $m2) {
                    // "0830-1100" or "2200-0000" (0000 = midnight end of day)
                    $slotStr = $m2[0];
                    $startHH = substr($slotStr, 0, 2);
                    $startMM = substr($slotStr, 2, 2);
                    $endHH   = substr($slotStr, 5, 2);
                    $endMM   = substr($slotStr, 7, 2);

                    $startStr = $currentDate . ' ' . $startHH . ':' . $startMM;
                    $endStr   = $currentDate . ' ' . $endHH   . ':' . $endMM;

                    $start = DateTime::createFromFormat('Y-m-d H:i', $startStr, new DateTimeZone('UTC'));
                    $end   = DateTime::createFromFormat('Y-m-d H:i', $endStr,   new DateTimeZone('UTC'));

                    if (!$start || !$end) {
                        continue;
                    }

                    // If end time is 00:00, it's midnight at end of day -> add 1 day
                    if ($endHH === '00' && $endMM === '00') {
                        $end->add(new DateInterval('P1D'));
                    }

                    $data[$zoneName]['activations'][] = [
                        'date'      => $currentDate,
                        'start_utc' => $start->format('Y-m-d\TH:i:s\Z'),
                        'end_utc'   => $end->format('Y-m-d\TH:i:s\Z'),
                    ];
                }
            }
        }
    }
}

// -------------------------------------------------------------------------
// 4. Calculate booleans is_active_now / will_be_active / will_be_active_soon
// -------------------------------------------------------------------------
// Anticipation: we consider the current time shifted by +5 minutes
// for all comparisons related to the start of time slots in order
// to avoid an unsignaled switch during the refresh period.
$now = new DateTime('now', new DateTimeZone('UTC'));
$now_plus5 = (clone $now)->add(new DateInterval('PT5M'));

// Window "next 4 hours" also shifted by +5 minutes to
// maintain consistency with the anticipation of starts.
$in4Hours = (clone $now)->add(new DateInterval('PT4H'));
$in4Hours_plus5 = (clone $in4Hours)->add(new DateInterval('PT5M'));

foreach ($data as $zoneName => &$zoneData) {
    foreach ($zoneData['activations'] as $slot) {
        $start = new DateTime($slot['start_utc']);
        $end   = new DateTime($slot['end_utc']);

        // is_active_now: we anticipate the start by +5 minutes (start compared
        // to $now_plus5) but we leave the end comparison on $now to
        // not artificially extend the end of a time slot.
        if ($now_plus5 >= $start && $now <= $end) {
            $zoneData['is_active_now'] = true;
        }

        // will_be_active: only FUTURE time slots
        // Time slot that starts within the next 4 hours
        // A time slot is considered future if its START is after now+5min
        if ($start > $now_plus5) {
            $zoneData['will_be_active'] = true;
        }

        // will_be_active_soon: time slot that starts within the next 4 hours
        // taking into account the +5 minute anticipation.
        if ($start > $now_plus5 && $start <= $in4Hours_plus5) {
            $zoneData['will_be_active_soon'] = true;
        }
    }
}
unset($zoneData);

// -------------------------------------------------------------------------
// 5. Filter ?azba=RXX...  (return only one zone if requested)
//    -> Never 404, even if no zone matches
// -------------------------------------------------------------------------
$allData = $data;
$filterZone = null;

if (!empty($_GET['azba'])) {
    $filterZone = strtoupper(trim($_GET['azba']));
    $filterZone = preg_replace('/\s+/', '', $filterZone);
}

if ($filterZone !== null) {
    if (isset($allData[$filterZone])) {
        $data = [
            $filterZone => $allData[$filterZone]
        ];
    } else {
        // No zone matching the filter -> no zone, but we will still return
        // a JSON with metadata + zones = {}.
        $data = [];
    }
}

// -------------------------------------------------------------------------
// 6. Build metadata (interval + counters)
// -------------------------------------------------------------------------
$zonesTotal           = 0;
$zonesActiveNow       = 0;
$zonesWillBeActive    = 0;
$zonesWillBeActiveSoon = 0;

foreach ($data as $zoneName => $zoneData) {
    // each entry in $data is a zone
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

$metadata = [
    'interval_start_utc'     => $intervalStart ? $intervalStart->format('Y-m-d\TH:i:s\Z') : null,
    'interval_end_utc'       => $intervalEnd   ? $intervalEnd->format('Y-m-d\TH:i:s\Z')   : null,
    'zones_total'            => $zonesTotal,
    'zones_active_now'       => $zonesActiveNow,
    'zones_will_be_active'   => $zonesWillBeActive,
    'zones_will_be_active_soon' => $zonesWillBeActiveSoon
];

// -------------------------------------------------------------------------
// 7. JSON output
// -------------------------------------------------------------------------
header('Content-Type: application/json; charset=utf-8');
echo json_encode(
    [
        'metadata' => $metadata,
        'zones'    => $data
    ],
    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
);
