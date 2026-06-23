<?php
/**
 * tests/run.php — zero-dependency test battery for php/azba_lib.php.
 *
 *   php tests/run.php              # unit tests only (offline, deterministic)
 *   AZBA_LIVE=1 php tests/run.php  # also run the live SIA contract checks
 *
 * Exit code is non-zero if any test fails (CI-friendly). No composer / PHPUnit.
 *
 * Focus, in priority order:
 *   1. The fail-safe guards (a false "inactive" can cause an aerial incident),
 *      so every guard MUST throw and is asserted here.
 *   2. The time-based flag math at its boundaries (with a frozen $now).
 *   3. (opt-in) the live SIA contract the guards depend on.
 */

declare(strict_types=1);
date_default_timezone_set('UTC');

// azba_lib.php = reusable SIA client (fetch + extraction).
// azba.php holds the app-specific logic (flags / filter / metadata); we pull it
// in with the main guard set so requiring it only defines functions.
define('AZBA_NO_MAIN', true);
require __DIR__ . '/../php/azba.php';

// ---------------------------------------------------------------------------
// Tiny test harness
// ---------------------------------------------------------------------------
$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;

function check(bool $ok, string $name): void
{
    if ($ok) {
        $GLOBALS['__pass']++;
        // keep output quiet on success; uncomment to see every case:
        // fwrite(STDOUT, "  ok   $name\n");
    } else {
        $GLOBALS['__fail']++;
        fwrite(STDOUT, "  FAIL $name\n");
    }
}

function assert_eq($expected, $actual, string $name): void
{
    $ok = $expected === $actual;
    if (!$ok) {
        fwrite(STDOUT, "  FAIL $name\n         expected: " . var_export($expected, true)
            . "\n         actual:   " . var_export($actual, true) . "\n");
        $GLOBALS['__fail']++;
        return;
    }
    $GLOBALS['__pass']++;
}

function assert_true(bool $cond, string $name): void
{
    check($cond, $name);
}

/** Assert the callable throws $expected (default: AzbaDataException). */
function assert_throws(callable $fn, string $name, string $expected = 'AzbaDataException'): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $expected) {
            $GLOBALS['__pass']++;
            return;
        }
        fwrite(STDOUT, "  FAIL $name (threw " . get_class($e) . ", not $expected)\n");
        $GLOBALS['__fail']++;
        return;
    }
    fwrite(STDOUT, "  FAIL $name (did not throw)\n");
    $GLOBALS['__fail']++;
}

/** Assert the callable does NOT throw. */
function assert_nothrow(callable $fn, string $name)
{
    try {
        $res = $fn();
        $GLOBALS['__pass']++;
        return $res;
    } catch (Throwable $e) {
        fwrite(STDOUT, "  FAIL $name (threw " . get_class($e) . ': ' . $e->getMessage() . ")\n");
        $GLOBALS['__fail']++;
        return null;
    }
}

// ---------------------------------------------------------------------------
// Builders for synthetic API payloads
// ---------------------------------------------------------------------------
function zone(string $codeId, array $slots): array
{
    $timeSlots = array_map(fn($s) => ['startTime' => $s[0], 'endTime' => $s[1]], $slots);
    return ['codeId' => $codeId, 'name' => $codeId, 'timeSlots' => $timeSlots];
}

/** Build a one-zone $data straight from start/end ISO strings (skips the API shape). */
function data_with_slots(string $key, array $slots): array
{
    $acts = [];
    foreach ($slots as $s) {
        $start = new DateTime($s[0]);
        $end   = new DateTime($s[1]);
        $acts[] = [
            'date'      => $start->format('Y-m-d'),
            'start_utc' => $start->format('Y-m-d\TH:i:s\Z'),
            'end_utc'   => $end->format('Y-m-d\TH:i:s\Z'),
        ];
    }
    return [$key => [
        'activations'         => $acts,
        'is_active_now'       => false,
        'will_be_active'      => false,
        'will_be_active_soon' => false,
    ]];
}

function iso(DateTime $base, string $modify): string
{
    $d = clone $base;
    $d->modify($modify);
    return $d->format('Y-m-d\TH:i:s\Z');
}

// ===========================================================================
echo "== azba_build_zones: safety guards (each MUST throw) ==\n";
// ===========================================================================

assert_throws(fn() => azba_build_zones(['startDate' => 'x', 'hydra:member' => []]),
    'non-list / Hydra-wrapper shape');
assert_throws(fn() => azba_build_zones([['name' => 'no codeId', 'timeSlots' => [['startTime' => 'a', 'endTime' => 'b']]]]),
    'missing codeId');
assert_throws(fn() => azba_build_zones([['codeId' => 123, 'timeSlots' => [['startTime' => 'a', 'endTime' => 'b']]]]),
    'non-string codeId');
assert_throws(fn() => azba_build_zones([zone('LFXY99', [['2026-06-23T08:00:00+00:00', '2026-06-23T09:00:00+00:00']])]),
    'non-LFR prefix (LFXY..)');
assert_throws(fn() => azba_build_zones([zone('EGR1', [['2026-06-23T08:00:00+00:00', '2026-06-23T09:00:00+00:00']])]),
    'foreign prefix (EG..)');
assert_throws(fn() => azba_build_zones([['codeId' => 'LFR45S3']]),
    'missing timeSlots key');
assert_throws(fn() => azba_build_zones([['codeId' => 'LFR45S3', 'timeSlots' => []]]),
    'empty timeSlots array');
assert_throws(fn() => azba_build_zones([['codeId' => 'LFR45S3', 'timeSlots' => [['startTime' => '2026-06-23T08:00:00+00:00']]]]),
    'slot missing endTime');
assert_throws(fn() => azba_build_zones([['codeId' => 'LFR45S3', 'timeSlots' => [['endTime' => '2026-06-23T09:00:00+00:00']]]]),
    'slot missing startTime');
assert_throws(fn() => azba_build_zones([zone('LFR45S3', [['not-a-date', 'also-bad']])]),
    'unparsable slot times');

// ===========================================================================
echo "== azba_build_zones: happy path + mapping ==\n";
// ===========================================================================

assert_eq([], azba_build_zones([]), 'empty list -> [] (no throw)');

$built = assert_nothrow(fn() => azba_build_zones([
    zone('LFR149E', [['2026-06-23T08:00:00+00:00', '2026-06-23T10:00:00+00:00']]),
    zone('LFR45S6.1', [
        ['2026-06-23T07:30:00+00:00', '2026-06-23T10:00:00+00:00'],
        ['2026-06-23T11:30:00+00:00', '2026-06-23T13:30:00+00:00'],
    ]),
]), 'build two well-formed zones');
assert_eq(['R149E', 'R45S6.1'], array_keys($built), 'codeId mapping LFR..->R.. (incl. dotted)');
assert_eq(1, count($built['R149E']['activations']), 'R149E has 1 activation');
assert_eq(2, count($built['R45S6.1']['activations']), 'R45S6.1 has 2 activations');
assert_eq('2026-06-23T07:30:00Z', $built['R45S6.1']['activations'][0]['start_utc'], 'start_utc normalized to Z');
assert_eq('2026-06-23', $built['R45S6.1']['activations'][0]['date'], 'date derived from start');
// Boundary: the library returns raw extraction only — no app-side flags here.
assert_eq(['activations'], array_keys($built['R149E']), 'build_zones yields activations only (no flags)');

// Real captured fixture must parse cleanly (early warning if it drifts).
$fixZones = json_decode(file_get_contents(__DIR__ . '/fixtures/active_zones.json'), true);
$fixBuilt = assert_nothrow(fn() => azba_build_zones($fixZones), 'real fixture builds without throwing');
assert_true(is_array($fixBuilt) && count($fixBuilt) === 3, 'fixture yields 3 zones');

// ===========================================================================
echo "== azba_parse_range: freshness guards ==\n";
// ===========================================================================

$now = new DateTime('2026-06-23T12:00:00+00:00');

assert_nothrow(fn() => azba_parse_range(
    ['startDate' => '2026-06-23T07:29:00+00:00', 'endDate' => '2026-06-24T07:29:00+00:00'], $now),
    'valid fresh window');
assert_throws(fn() => azba_parse_range(
    ['startDate' => '2026-06-21T07:29:00+00:00', 'endDate' => '2026-06-22T07:29:00+00:00'], $now),
    'stale window (now > end)');
assert_throws(fn() => azba_parse_range(
    ['startDate' => '2026-06-24T07:29:00+00:00', 'endDate' => '2026-06-23T07:29:00+00:00'], $now),
    'inverted window (end <= start)');
assert_throws(fn() => azba_parse_range(
    ['startDate' => '2026-06-25T07:00:00+00:00', 'endDate' => '2026-06-26T07:00:00+00:00'], $now),
    'now too far before start (> 6h grace)');
assert_nothrow(fn() => azba_parse_range(
    ['startDate' => '2026-06-23T15:00:00+00:00', 'endDate' => '2026-06-24T15:00:00+00:00'], $now),
    'now within 6h grace before start');
assert_throws(fn() => azba_parse_range(['startDate' => 'nope', 'endDate' => 'nope'], $now),
    'unparsable range dates');
assert_throws(fn() => azba_parse_range([], $now),
    'range missing startDate/endDate');

// returned bounds are correct
[$rs, $re] = azba_parse_range(
    ['startDate' => '2026-06-23T07:29:00+00:00', 'endDate' => '2026-06-24T07:29:00+00:00'], $now);
assert_eq('2026-06-23T07:29:00Z', $rs->format('Y-m-d\TH:i:s\Z'), 'parsed start bound');
assert_eq('2026-06-24T07:29:00Z', $re->format('Y-m-d\TH:i:s\Z'), 'parsed end bound');

// ===========================================================================
echo "== azba_compute_flags: time math (frozen now) ==\n";
// ===========================================================================

$now = new DateTime('2026-06-23T12:00:00+00:00');

// active now: slot fully surrounds now
$d = azba_compute_flags(data_with_slots('R1', [[iso($now, '-30 min'), iso($now, '+30 min')]]), $now);
assert_true($d['R1']['is_active_now'], 'active when now inside slot');
assert_true(!$d['R1']['will_be_active'], 'active slot is not "will_be_active"');

// +5min anticipation: start = now+4min -> already active
$d = azba_compute_flags(data_with_slots('R1', [[iso($now, '+4 min'), iso($now, '+90 min')]]), $now);
assert_true($d['R1']['is_active_now'], 'anticipation: start now+4min counts as active');

// start = now+6min -> not active, but future + soon
$d = azba_compute_flags(data_with_slots('R1', [[iso($now, '+6 min'), iso($now, '+90 min')]]), $now);
assert_true(!$d['R1']['is_active_now'], 'start now+6min not yet active');
assert_true($d['R1']['will_be_active'], 'start now+6min is future');
assert_true($d['R1']['will_be_active_soon'], 'start now+6min is soon (<4h)');

// start = now+5h -> future but NOT soon
$d = azba_compute_flags(data_with_slots('R1', [[iso($now, '+5 hours'), iso($now, '+6 hours')]]), $now);
assert_true(!$d['R1']['is_active_now'], 'start now+5h not active');
assert_true($d['R1']['will_be_active'], 'start now+5h is future');
assert_true(!$d['R1']['will_be_active_soon'], 'start now+5h not soon (>4h)');

// fully past slot -> all false
$d = azba_compute_flags(data_with_slots('R1', [[iso($now, '-3 hours'), iso($now, '-2 hours')]]), $now);
assert_true(!$d['R1']['is_active_now'] && !$d['R1']['will_be_active'] && !$d['R1']['will_be_active_soon'],
    'past slot -> all flags false');

// multi-slot zone: past + future combine on the same zone
$d = azba_compute_flags(data_with_slots('R1', [
    [iso($now, '-3 hours'), iso($now, '-2 hours')],
    [iso($now, '+90 min'), iso($now, '+3 hours')],
]), $now);
assert_true(!$d['R1']['is_active_now'], 'multi-slot: not active now');
assert_true($d['R1']['will_be_active'] && $d['R1']['will_be_active_soon'], 'multi-slot: future slot sets flags');

// midnight-spanning slot (explicit end next day) -> active across midnight
$mNow = new DateTime('2026-06-23T23:30:00+00:00');
$d = azba_compute_flags(data_with_slots('R1', [['2026-06-23T22:00:00Z', '2026-06-24T02:00:00Z']]), $mNow);
assert_true($d['R1']['is_active_now'], 'midnight-spanning slot active at 23:30');

// ===========================================================================
echo "== azba_filter_zone ==\n";
// ===========================================================================

$base = ['R45S3' => ['x' => 1], 'R142A' => ['y' => 2]];
assert_eq(['R45S3' => ['x' => 1]], azba_filter_zone($base, 'R45S3'), 'exact match returns single zone');
assert_eq(['R45S3' => ['x' => 1]], azba_filter_zone($base, '  r45s3 '), 'lowercase + spaces normalized');
assert_eq([], azba_filter_zone($base, 'ZZZ'), 'unknown zone -> []');
assert_eq($base, azba_filter_zone($base, null), 'null filter -> unchanged');
assert_eq($base, azba_filter_zone($base, '   '), 'blank filter -> unchanged');

// ===========================================================================
echo "== azba_fetch: end-to-end orchestration (mock HTTP, offline) ==\n";
// ===========================================================================

// A mock transport that returns canned [status, rawBody] per request, in order.
// Each step is [statusInt, mixedBodyOrNull]; body is json-encoded if not a string.
function mock_http(array $steps): callable
{
    $i = 0;
    return function (string $url, array $headers) use (&$i, $steps): array {
        $step = $steps[$i] ?? end($steps);
        $i++;
        [$status, $body] = $step;
        if ($body !== null && !is_string($body)) {
            $body = json_encode($body);
        }
        return [$status, $body];
    };
}

$rangeBody  = json_decode(file_get_contents(__DIR__ . '/fixtures/current_date.json'), true);
$zonesBody  = json_decode(file_get_contents(__DIR__ . '/fixtures/active_zones.json'), true);
// Freeze "now" just after the fixture window opens so the freshness guard passes.
$fetchNow   = new DateTime($rangeBody['startDate']);
$fetchNow->modify('+1 hour');

// Happy path: currentDate then r_t_b_as both 200. Lib returns RAW extraction
// (interval + zones with activations only) — no flags/filter/metadata.
$res = assert_nothrow(fn() => azba_fetch([
    'now'  => $fetchNow,
    'http' => mock_http([[200, $rangeBody], [200, $zonesBody]]),
]), 'azba_fetch happy path returns a result');
assert_true(is_array($res) && isset($res['interval'], $res['zones']), 'azba_fetch result has interval + zones');
assert_true(!empty($res['interval']['start_utc']) && !empty($res['interval']['end_utc']), 'azba_fetch interval has start/end');
assert_eq(3, count($res['zones']), 'azba_fetch extracts the 3 fixture zones');
assert_true(array_key_exists('R45S3', $res['zones']), 'azba_fetch mapped LFR45S3 -> R45S3');
assert_eq(['activations'], array_keys($res['zones']['R45S3']), 'azba_fetch zones are raw (activations only, no flags)');

// Untrusted data propagates as AzbaDataException (malformed zone: non-LFR codeId).
assert_throws(fn() => azba_fetch([
    'now'  => $fetchNow,
    'http' => mock_http([[200, $rangeBody], [200, [['codeId' => 'XX1', 'timeSlots' => [['startTime' => 'a', 'endTime' => 'b']]]]]]),
]), 'azba_fetch -> AzbaDataException on malformed zones');

// Auth rejected -> AzbaFetchException.
assert_throws(fn() => azba_fetch([
    'now'  => $fetchNow,
    'http' => mock_http([[401, null]]),
]), 'azba_fetch -> AzbaFetchException on HTTP 401', 'AzbaFetchException');

// Active-zones 500 twice (retry exhausted) -> AzbaFetchException.
assert_throws(fn() => azba_fetch([
    'now'  => $fetchNow,
    'http' => mock_http([[200, $rangeBody], [500, null], [500, null]]),
]), 'azba_fetch -> AzbaFetchException when r_t_b_as 500 twice', 'AzbaFetchException');

// Active-zones 500 then 200 -> retry succeeds.
$res = assert_nothrow(fn() => azba_fetch([
    'now'  => $fetchNow,
    'http' => mock_http([[200, $rangeBody], [500, null], [200, $zonesBody]]),
]), 'azba_fetch retries r_t_b_as on transient 500 and succeeds');
assert_true(isset($res['zones']) && count($res['zones']) === 3, 'azba_fetch retry yields the 3 zones');

// Exception hierarchy: both lib errors are catchable as AzbaException.
assert_true(is_subclass_of('AzbaFetchException', 'AzbaException')
    && is_subclass_of('AzbaDataException', 'AzbaException'), 'both exceptions extend AzbaException');

// ===========================================================================
echo "== azba_build_metadata (app logic, in azba.php) ==\n";
// ===========================================================================

$interval = ['start_utc' => '2026-06-23T07:29:00Z', 'end_utc' => '2026-06-24T07:29:00Z'];
$meta = azba_build_metadata([
    'A' => ['is_active_now' => true,  'will_be_active' => false, 'will_be_active_soon' => false],
    'B' => ['is_active_now' => false, 'will_be_active' => true,  'will_be_active_soon' => true],
    'C' => ['is_active_now' => false, 'will_be_active' => true,  'will_be_active_soon' => false],
], $interval);
assert_eq(3, $meta['zones_total'], 'metadata: total');
assert_eq(1, $meta['zones_active_now'], 'metadata: active_now');
assert_eq(2, $meta['zones_will_be_active'], 'metadata: will_be_active');
assert_eq(1, $meta['zones_will_be_active_soon'], 'metadata: will_be_active_soon');
assert_eq('2026-06-23T07:29:00Z', $meta['interval_start_utc'], 'metadata: interval start');
assert_eq('2026-06-24T07:29:00Z', $meta['interval_end_utc'], 'metadata: interval end');

// ===========================================================================
// Live contract checks (opt-in) — re-assert the upstream shape the guards rely
// on, so SIA drift is caught early instead of in the field.
// ===========================================================================
if (getenv('AZBA_LIVE') === '1') {
    echo "== LIVE SIA contract (AZBA_LIVE=1) ==\n";

    // Drive the real library end-to-end (real network). If the SIA rotates its
    // API or changes the contract, azba_fetch() throws and this fails fast —
    // exactly the early-warning we want, before it reaches the field.
    $res = assert_nothrow(fn() => azba_fetch(), 'live azba_fetch() succeeds end-to-end');
    assert_true(is_array($res) && isset($res['interval'], $res['zones']),
        'live azba_fetch() returns interval + zones');
    assert_true(is_array($res['zones']),
        'live azba_fetch() zones is an array');
    assert_true(!empty($res['interval']['start_utc']),
        'live azba_fetch() interval has a start');

    // The app pipeline (flags + metadata) also runs over the live extraction.
    $flagged = assert_nothrow(fn() => azba_compute_flags($res['zones'], new DateTime('now', new DateTimeZone('UTC'))),
        'live app pipeline: azba_compute_flags over real zones');
    assert_nothrow(fn() => azba_build_metadata($flagged, $res['interval']),
        'live app pipeline: azba_build_metadata over real zones');
}

// ---------------------------------------------------------------------------
$pass = $GLOBALS['__pass'];
$fail = $GLOBALS['__fail'];
echo "\n";
if ($fail === 0) {
    echo "OK  $pass/$pass passed\n";
    exit(0);
}
echo "FAILED  $fail failed, $pass passed\n";
exit(1);
