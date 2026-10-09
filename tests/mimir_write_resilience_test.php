<?php

declare(strict_types=1);

/**
 * Etag "*"/force, writes die altijd doorgaan (bijtaken best-effort + pending
 * wachtrij), en het schrijflogboek (SQLite, API, UI, fallback). Gemockte HTTP.
 */

require_once dirname(__DIR__) . '/web/mimir_write.php';

function mimir_res_fail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function mimir_res_same(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) {
        mimir_res_fail($message . ' expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }
}

$tmp = sys_get_temp_dir() . '/mimir-res-test-' . bin2hex(random_bytes(4));
mkdir($tmp, 0777, true);
file_put_contents($tmp . '/afile', 'x');
$GLOBALS['mimir_runtime_dir'] = $tmp;
// Pad dat nooit een database kan worden (map is een bestand): health probe faalt.
$GLOBALS['mimir_reliability_db_path'] = $tmp . '/afile/mimir.sqlite';
mimir_bc_limit_set_dir($tmp . '/bc_slots');

$now = time();
$GLOBALS['baseUrl'] = 'https://bc.example.test:7148';
$GLOBALS['environment'] = ['kvtmdlive_aad', 'kvtgermanylive_aad'];
$GLOBALS['auth_list'] = [
    'kvtgermanylive_aad' => ['mode' => 'basic', 'user' => 'de', 'pass' => 'x'],
    'kvtmdlive_aad' => ['mode' => 'basic', 'user' => 'nl', 'pass' => 'x'],
];
$GLOBALS['demeter_company_environment_map'] = [
    'Koninklijke van Twist' => 'kvtmdlive_aad',
    'KVT Germany' => 'kvtgermanylive_aad',
];
$metadata = [
    'entity_sets' => [['name' => 'ItemCard', 'entity_type' => 'NAV.ItemCard'], ['name' => 'Customer', 'entity_type' => 'NAV.Customer']],
    'types' => [
        'ItemCard' => ['keys' => ['No'], 'properties' => ['No' => 'Edm.String', 'Description' => 'Edm.String']],
        'Customer' => ['keys' => ['No'], 'properties' => ['No' => 'Edm.String', 'Name' => 'Edm.String']],
    ],
];
$pdo = mimir_db(':memory:');
foreach (['kvtmdlive_aad', 'kvtgermanylive_aad'] as $env) {
    mimir_meta_put($pdo, 'metadata:' . mimir_odata_prefix_for_environment($env), $metadata, $now);
    mimir_metadata_snapshot_write($env, $metadata, $now);
}
mimir_company_map_remember($GLOBALS['demeter_company_environment_map']);
$writer = mimir_key_create($pdo, 'tim@kvt.nl', 'Schrijver', $now, true);
$other = mimir_key_create($pdo, 'ariadne@kvt.nl', 'Ander', $now, true);
$reader = mimir_key_create($pdo, 'tim@kvt.nl', 'Lezer', $now);

$calls = [];
$send = static function (string $method, string $url, array $auth, ?string $json, array $headers) use (&$calls): array {
    $calls[] = compact('method', 'url', 'headers');
    return $method === 'DELETE' ? ['code' => 204, 'raw' => '', 'headers' => []] : ['code' => $method === 'POST' ? 201 : 200, 'raw' => '{"No":"A"}', 'headers' => []];
};
$patch = '{"company":"KVT Germany","table":"ItemCard","key":{"No":"A"},"data":{"Description":"B"}';

// --- 1. Etag: default 428, "*" / If-Match * / force mogen expliciet ---
$r = mimir_write_handle('PATCH', $writer['key'], [], $patch . '}', '', $send, $pdo);
mimir_res_same($r['payload']['code'] ?? null, 'etag_required', 'no etag stays 428');
mimir_res_same($r['status'], 428, 'status 428');
mimir_res_same(count($calls), 0, 'no BC call without etag');
$r = mimir_write_handle('PATCH', $writer['key'], [], $patch . ',"force":true}', '', $send, $pdo);
mimir_res_same($r['status'], 200, 'force:true works');
mimir_res_same($r['payload']['forced'], true, 'response says forced');
mimir_res_same(in_array('If-Match: *', $calls[0]['headers'], true), true, 'force sends If-Match: *');
$r = mimir_write_handle('DELETE', $writer['key'], [], '{"company":"KVT Germany","table":"ItemCard","key":{"No":"A"},"etag":"*"}', '', $send, $pdo);
mimir_res_same($r['payload']['forced'], true, 'etag * in body is forced');
$r = mimir_write_handle('PATCH', $writer['key'], [], $patch . '}', '*', $send, $pdo);
mimir_res_same($r['payload']['forced'], true, 'If-Match * header is forced');
$r = mimir_write_handle('PATCH', $writer['key'], [], $patch . ',"etag":"W/\"5\""}', '', $send, $pdo);
mimir_res_same($r['payload']['forced'], false, 'a real etag is not forced');
$r = mimir_write_handle('PATCH', $writer['key'], [], $patch . ',"etag":"W/\"5\"","force":true}', '', $send, $pdo);
mimir_res_same($r['payload']['code'] ?? null, 'invalid_request', 'force plus etag is rejected');
$r = mimir_write_handle('PATCH', $writer['key'], [], $patch . ',"force":"yes"}', '', $send, $pdo);
mimir_res_same($r['payload']['code'] ?? null, 'invalid_request', 'force must be boolean');
mimir_res_same(count($calls), 4, 'four BC calls so far');
$logged = mimir_write_log_list($pdo, $writer['id'], ['limit' => 10])['value'];
mimir_res_same(array_map(static fn ($e) => $e['forced'], array_slice($logged, 0, 4)), [false, true, true, true], 'log records forced per write (newest first)');
if (!str_contains((string) file_get_contents(mimir_write_log_path()), '"forced":true')) {
    mimir_res_fail('jsonl log has forced:true');
}

// --- 2a. Bijtaken falen: write slaagt, alles komt in pending, daarna ingehaald ---
mimir_cache_upsert($pdo, 'kvtgermanylive_aad', 'KVT Germany', 'Customer', 'C1', ['No' => 'C1'], $now);
$before = count(mimir_write_log_list($pdo, $writer['id'], ['limit' => 200])['value']);
$GLOBALS['mimir_write_fail_side_tasks'] = ['log', 'usage', 'invalidate'];
$r = mimir_write_handle('POST', $writer['key'], [], '{"company":"KVT Germany","table":"Customer","data":{"No":"C2"}}', '', $send, $pdo);
unset($GLOBALS['mimir_write_fail_side_tasks']);
mimir_res_same($r['status'], 201, 'write succeeds while side tasks fail');
mimir_res_same($r['payload']['meta']['cache_invalidated'], false, 'invalidation reported as pending');
mimir_res_same(count(mimir_cache_all($pdo, 'kvtgermanylive_aad', 'KVT Germany', 'Customer')), 1, 'cache not yet cleared');
mimir_res_same(count(mimir_write_log_list($pdo, $writer['id'], ['limit' => 200])['value']), $before, 'SQLite log not yet written');
$pendingLines = count(array_filter(explode("\n", (string) file_get_contents(mimir_pending_path()))));
mimir_res_same($pendingLines, 3, 'three pending side tasks');
$usageBefore = (int) $pdo->query("SELECT COUNT(*) FROM api_usage WHERE kind = 'write'")->fetchColumn();
mimir_res_same(mimir_pending_apply($pdo), 3, 'pending applied');
mimir_res_same(mimir_cache_all($pdo, 'kvtgermanylive_aad', 'KVT Germany', 'Customer'), [], 'cache cleared afterwards');
mimir_res_same((int) $pdo->query("SELECT COUNT(*) FROM api_usage WHERE kind = 'write'")->fetchColumn(), $usageBefore + 1, 'heatmap caught up');
mimir_res_same(count(mimir_write_log_list($pdo, $writer['id'], ['limit' => 200])['value']), $before + 1, 'log caught up');
mimir_res_same(is_file(mimir_pending_path()), false, 'queue empty');
mimir_pending_apply($pdo);
mimir_res_same(count(mimir_write_log_list($pdo, $writer['id'], ['limit' => 200])['value']), $before + 1, 'no duplicate log after second apply');

// --- 2b. Database onbereikbaar + open circuit: write via spiegel en snapshot ---
mimir_cache_upsert($pdo, 'kvtmdlive_aad', 'Koninklijke van Twist', 'Customer', 'C9', ['No' => 'C9'], $now);
mimir_circuit_trip('test: database weg');
$GLOBALS['mimir_probe_interval'] = 0;
mimir_res_same(mimir_circuit_should_bypass(), true, 'circuit is open and probe fails');
$callsBefore = count($calls);
$r = mimir_write_handle('POST', $writer['key'], [], '{"company":"Koninklijke van Twist","table":"Customer","data":{"No":"C10"}}', '', $send, null);
mimir_res_same($r['status'], 201, 'write succeeds with unreachable DB');
mimir_res_same($r['payload']['environment'], 'kvtmdlive_aad', 'right environment via snapshot map');
mimir_res_same(count($calls), $callsBefore + 1, 'exactly one BC call');
$r = mimir_write_handle('POST', $reader['key'], [], '{"company":"Koninklijke van Twist","table":"Customer","data":{"No":"C11"}}', '', $send, null);
mimir_res_same($r['payload']['code'] ?? null, 'write_not_allowed', 'mirror still enforces can_write');
mimir_circuit_close('test: database terug');
// Volgende gezonde start: wachtrij toepassen.
mimir_res_same(mimir_pending_apply($pdo), 3, 'pending from outage applied');
mimir_res_same(mimir_cache_all($pdo, 'kvtmdlive_aad', 'Koninklijke van Twist', 'Customer'), [], 'outage write invalidated later');
mimir_res_same(mimir_write_log_list($pdo, $writer['id'], ['limit' => 1])['value'][0]['company'], 'Koninklijke van Twist', 'outage write in log');

// --- 3. Schrijflogboek ---
$byId = [];
foreach (mimir_key_list($pdo, 'tim@kvt.nl', $now) as $row) {
    $byId[$row['id']] = $row;
}
mimir_res_same($byId[$writer['id']]['has_write_log'], true, 'writer has log button');
mimir_res_same($byId[$reader['id']]['has_write_log'], false, 'reader has no log button (403 before BC is not a write)');

mimir_write_handle('POST', $other['key'], [], '{"company":"KVT Germany","table":"ItemCard","data":{"No":"O1"}}', '', $send, $pdo);
$r = mimir_write_log_api_handle('GET', $writer['key'], ['limit' => '2'], $pdo);
mimir_res_same($r['status'], 200, 'api ok');
mimir_res_same(count($r['payload']['value']), 2, 'limit applies');
$next = $r['payload']['next_before_id'];
if (!is_int($next)) {
    mimir_res_fail('paging cursor expected');
}
$r2 = mimir_write_log_api_handle('GET', $writer['key'], ['limit' => '2', 'before_id' => (string) $next], $pdo);
if ($r2['payload']['value'][0]['id'] >= $next) {
    mimir_res_fail('next page is older');
}
$all = mimir_write_log_api_handle('GET', $writer['key'], ['limit' => '200'], $pdo)['payload']['value'];
$otherRows = mimir_write_log_api_handle('GET', $other['key'], [], $pdo)['payload']['value'];
mimir_res_same(count($otherRows), 1, 'other key sees only its own line');
$ids = array_map(static fn ($e) => $e['id'], $all);
mimir_res_same(in_array($otherRows[0]['id'], $ids, true), false, 'writer does not see other key lines');
$customers = mimir_write_log_api_handle('GET', $writer['key'], ['table' => 'customer', 'company' => 'KVT Germany'], $pdo)['payload']['value'];
mimir_res_same(count($customers), 1, 'table + company filter');
$future = mimir_write_log_api_handle('GET', $writer['key'], ['since' => (string) ($now + 3600)], $pdo)['payload']['value'];
mimir_res_same($future, [], 'since filter');
mimir_res_same(mimir_write_log_api_handle('GET', '', [], $pdo)['status'], 401, 'no key is 401');
mimir_res_same(mimir_write_log_api_handle('POST', $writer['key'], [], $pdo)['status'], 405, 'GET only');
mimir_res_same(mimir_write_log_api_handle('GET', $writer['key'], ['table' => '../x'], $pdo)['payload']['code'], 'invalid_table', 'table validated');
$file = mimir_write_log_from_file($writer['id'], mimir_write_log_options(['limit' => '200']));
mimir_res_same(count($file['value']), count($all), 'jsonl fallback has the same lines for this key');

$_GET = ['id' => (string) $writer['id'], 'limit' => '3'];
$ui = mimir_ui_cached_payload($pdo, 'keys_write_log', 'tim@kvt.nl', $now, null);
mimir_res_same(count($ui['value']), 3, 'owner sees log in UI');
mimir_res_same(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $ui['value'][0]['logged_at_label']), 1, 'NL time label');
try {
    mimir_ui_cached_payload($pdo, 'keys_write_log', 'ariadne@kvt.nl', $now, null);
    mimir_res_fail('other user must not see the log');
} catch (MimirUserException $error) {
    mimir_res_same($error->status, 404, 'non-owner gets 404');
}

exec('rm -rf ' . escapeshellarg($tmp));
fwrite(STDOUT, "ok\n");
