<?php

declare(strict_types=1);

/** api/events.php: auth (can_read_events), filters, summary en geen prefix/hash in de uitvoer. */

require_once dirname(__DIR__) . '/web/mimir_events_api.php';

function ev_same(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: {$message} expected " . var_export($expected, true) . ' got ' . var_export($actual, true) . "\n");
        exit(1);
    }
}

$tmp = sys_get_temp_dir() . '/mimir-events-test-' . bin2hex(random_bytes(4));
mkdir($tmp, 0777, true);
$GLOBALS['mimir_runtime_dir'] = $tmp;
$GLOBALS['mimir_reliability_db_path'] = $tmp . '/unused.sqlite';

$day = '2026-10-30';
$caller = 'key_id=31 label="Sancus" owner=tfalken@kvt.nl prefix=abcd1234 hash=deadbeef';
$lines = [
    ['ts' => '2026-10-30T10:00:00+00:00', 'category' => 'error', 'message' => 'HTTP 501 op filter 12', 'environment' => 'Prod', 'entity' => 'ProjectPosten', 'action' => 'bc-failed', 'caller' => $caller],
    ['ts' => '2026-10-30T11:00:00+00:00', 'category' => 'error', 'message' => 'HTTP 501 op filter 99', 'environment' => 'Prod', 'entity' => 'ProjectPosten', 'action' => 'bc-failed', 'caller' => $caller],
    ['ts' => '2026-10-30T11:30:00+00:00', 'category' => 'request', 'message' => 'ok', 'environment' => 'Prod', 'entity' => 'Items', 'action' => 'retried', 'attempt' => 2, 'caller' => 'nightly'],
];
file_put_contents(mimir_event_log_path($day), implode("\n", array_map('json_encode', $lines)) . "\n");

$all = mimir_events_query(['date' => $day]);
ev_same($all['matched'], 3, 'alle rijen');
ev_same($all['counts']['cross-red'], 2, 'telling cross-red');
ev_same($all['rows'][0]['time'], '2026-10-30T12:30:00+01:00', 'tijd in Amsterdam');
$red = mimir_events_query(['date' => $day, 'status' => 'cross-red', 'caller' => 'sancus']);
ev_same($red['matched'], 2, 'statusfilter + caller');
ev_same($red['rows'][0]['caller'], ['label' => 'Sancus', 'owner' => 'tfalken@kvt.nl'], 'caller label+owner');
ev_same(mimir_events_query(['date' => $day, 'limit' => 1, 'offset' => 1])['rows'][0]['entity'], 'ProjectPosten', 'offset');
$sum = mimir_events_query(['date' => $day, 'summary' => '1']);
ev_same(count($sum['summary']), 1, 'een groep');
ev_same($sum['summary'][0]['count'], 2, 'groep telt 2');
ev_same($sum['summary'][0]['first'], '2026-10-30T11:00:00+01:00', 'first');
ev_same($sum['summary'][0]['last'], '2026-10-30T12:00:00+01:00', 'last');
$json = json_encode([$all, $sum]);
ev_same(str_contains($json, 'abcd1234') || str_contains($json, 'deadbeef') || str_contains($json, 'prefix='), false, 'geen prefix/hash');

// Auth
$pdo = mimir_db($tmp . '/keys.sqlite');
mimir_migrate($pdo);
$key = mimir_key_create($pdo, 'tfalken@kvt.nl', 'Hephaestus', time());
ev_same(mimir_events_api_handle('GET', '', [], $pdo)['status'], 401, 'geen sleutel');
ev_same(mimir_events_api_handle('GET', 'nope', [], $pdo)['status'], 401, 'foute sleutel');
ev_same(mimir_events_api_handle('GET', $key['key'], ['date' => $day], $pdo)['status'], 403, 'standaard geen recht');
ev_same(mimir_key_set_events($pdo, $key['id'], 'ander@kvt.nl', true), false, 'alleen eigenaar');
ev_same(mimir_key_set_events($pdo, $key['id'], 'tfalken@kvt.nl', true), true, 'eigenaar zet aan');
$ok = mimir_events_api_handle('GET', $key['key'], ['date' => $day, 'summary' => '1'], $pdo);
ev_same($ok['status'], 200, 'met recht 200');
ev_same(mimir_events_api_handle('GET', $key['key'], ['date' => '30-10-2026'], $pdo)['status'], 400, 'foute datum');
ev_same(mimir_events_api_handle('POST', $key['key'], [], $pdo)['status'], 405, 'alleen GET');

// Bestaande database op versie 4 zonder kolom krijgt can_read_events bij openen.
$old = new PDO('sqlite:' . $tmp . '/old.sqlite');
$old->exec('CREATE TABLE api_keys (id INTEGER PRIMARY KEY AUTOINCREMENT, owner_email TEXT NOT NULL, label TEXT NOT NULL, key_plain TEXT NOT NULL, key_hash TEXT NOT NULL UNIQUE, created_at INTEGER NOT NULL, revoked_at INTEGER, can_write INTEGER NOT NULL DEFAULT 0)');
$old->exec('PRAGMA user_version = 4');
mimir_migrate($old);
ev_same(in_array('can_read_events', mimir_sqlite_columns($old, 'api_keys'), true), true, 'v4 -> kolom toegevoegd');

echo "mimir_events_api_test OK\n";
