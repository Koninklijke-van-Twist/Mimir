<?php

declare(strict_types=1);

/**
 * Schrijven naar BC: migratie, default uit, 403, juiste environment (gemockte
 * HTTP), cache-invalidatie, geen retry na time-out, heatmap-splitsing en CSRF.
 * Belt nooit de echte Business Central: $send is overal een mock.
 */

require_once dirname(__DIR__) . '/web/mimir_write.php';

function mimir_write_fail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function mimir_write_same(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) {
        mimir_write_fail($message . ' expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }
}

$tmp = sys_get_temp_dir() . '/mimir-write-test-' . bin2hex(random_bytes(4));
mkdir($tmp, 0777, true);
$GLOBALS['mimir_runtime_dir'] = $tmp;
$GLOBALS['mimir_reliability_db_path'] = $tmp . '/unused.sqlite';
mimir_bc_limit_set_dir($tmp . '/bc_slots');

// --- 1. Migratie: oude api_keys/api_usage zonder can_write/kind ---
$legacy = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$legacy->exec('CREATE TABLE api_keys (id INTEGER PRIMARY KEY AUTOINCREMENT, owner_email TEXT NOT NULL, label TEXT NOT NULL, key_plain TEXT NOT NULL, key_hash TEXT NOT NULL UNIQUE, created_at INTEGER NOT NULL, revoked_at INTEGER)');
$legacy->exec('CREATE TABLE api_usage (id INTEGER PRIMARY KEY AUTOINCREMENT, key_id INTEGER NOT NULL, endpoint TEXT NOT NULL, called_at INTEGER NOT NULL)');
$legacy->exec("INSERT INTO api_keys (owner_email, label, key_plain, key_hash, created_at) VALUES ('tim@kvt.nl', 'Oud', 'mimir_old', '" . mimir_key_hash('mimir_old') . "', 1)");
$legacy->exec("INSERT INTO api_usage (key_id, endpoint, called_at) VALUES (1, 'query', 100)");
mimir_migrate($legacy);
mimir_migrate($legacy); // idempotent
mimir_write_same(in_array('can_write', mimir_sqlite_columns($legacy, 'api_keys'), true), true, 'migration adds can_write');
mimir_write_same(in_array('kind', mimir_sqlite_columns($legacy, 'api_usage'), true), true, 'migration adds usage kind');
mimir_write_same((int) $legacy->query('SELECT can_write FROM api_keys WHERE id = 1')->fetchColumn(), 0, 'existing key stays read-only');
mimir_write_same((string) $legacy->query('SELECT kind FROM api_usage WHERE id = 1')->fetchColumn(), 'read', 'existing usage counts as read');
mimir_write_same(mimir_key_lookup($legacy, 'mimir_old')['can_write'], false, 'migrated key lookup has no write');

// --- 2. Default uit ---
$now = time();
$pdo = mimir_db(':memory:');
$reader = mimir_key_create($pdo, 'tim@kvt.nl', 'Lezer', $now);
mimir_write_same($reader['can_write'], false, 'new key defaults to no write');
mimir_write_same(mimir_key_lookup($pdo, $reader['key'])['can_write'], false, 'lookup: default no write');
mimir_write_same(mimir_key_mirror_lookup($reader['key'])['can_write'], false, 'mirror: default no write');
$writer = mimir_key_create($pdo, 'tim@kvt.nl', 'Schrijver', $now, true);
mimir_write_same(mimir_key_lookup($pdo, $writer['key'])['can_write'], true, 'key created with write');
mimir_write_same(mimir_key_mirror_lookup($writer['key'])['can_write'], true, 'mirror knows write for open circuit');

mimir_write_same(mimir_key_set_write($pdo, $reader['id'], 'other@kvt.nl', true), false, 'someone else cannot enable write');
mimir_write_same(mimir_key_lookup($pdo, $reader['key'])['can_write'], false, 'still no write after foreign attempt');

// --- Omgeving: twee environments, auth_list in "verkeerde" volgorde ---
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
    'entity_sets' => [['name' => 'ItemCard', 'entity_type' => 'NAV.ItemCard']],
    'types' => ['ItemCard' => ['keys' => ['No'], 'properties' => ['No' => 'Edm.String', 'Description' => 'Edm.String']]],
];
foreach (['kvtmdlive_aad', 'kvtgermanylive_aad'] as $env) {
    mimir_meta_put($pdo, 'metadata:' . mimir_odata_prefix_for_environment($env), $metadata, $now);
}

$calls = [];
$ok = static function (string $method, string $url, array $auth, ?string $json, array $headers) use (&$calls): array {
    $calls[] = compact('method', 'url', 'auth', 'json', 'headers');
    if ($method === 'DELETE') {
        return ['code' => 204, 'raw' => '', 'headers' => []];
    }

    return ['code' => $method === 'POST' ? 201 : 200, 'raw' => '{"@odata.etag":"W/\"2\"","No":"A 1","Description":"Nieuw"}', 'headers' => []];
};

// --- 3. 403 voor sleutel zonder schrijfrecht ---
$result = mimir_write_handle('POST', $reader['key'], [], '{"company":"KVT Germany","table":"ItemCard","data":{"No":"A"}}', '', $ok, $pdo);
mimir_write_same($result['status'], 403, 'read-only key gets 403');
mimir_write_same($result['payload']['code'], 'write_not_allowed', 'error code write_not_allowed');
mimir_write_same(count($calls), 0, 'no BC call for a read-only key');
$result = mimir_write_handle('POST', '', [], '{"company":"KVT Germany","table":"ItemCard","data":{"No":"A"}}', '', $ok, $pdo);
mimir_write_same($result['status'], 401, 'no key (session) is 401');

// --- 4. Juiste environment, ook als auth_list andersom staat ---
mimir_cache_upsert($pdo, 'kvtgermanylive_aad', 'KVT Germany', 'ItemCard', 'A', ['No' => 'A'], $now);
mimir_coverage_put($pdo, 'kvtgermanylive_aad', 'KVT Germany', 'ItemCard', '', '*', $now, 1);
mimir_cache_upsert($pdo, 'kvtmdlive_aad', 'Koninklijke van Twist', 'ItemCard', 'A', ['No' => 'A'], $now);

$result = mimir_write_handle('POST', $writer['key'], [], '{"company":"KVT Germany","table":"itemcard","data":{"no":"A 1","Description":"Nieuw"}}', '', $ok, $pdo);
mimir_write_same($result['status'], 201, 'insert returns BC status');
mimir_write_same($result['payload']['environment'], 'kvtgermanylive_aad', 'Germany goes to its own environment');
mimir_write_same(count($calls), 1, 'exactly one BC call');
mimir_write_same($calls[0]['url'], "https://bc.example.test:7148/kvtgermanylive_aad/ODataV4/Company('KVT%20Germany')/ItemCard", 'POST URL targets Germany');
mimir_write_same($calls[0]['auth']['user'], 'de', 'Germany credentials');
mimir_write_same(json_decode((string) $calls[0]['json'], true), ['No' => 'A 1', 'Description' => 'Nieuw'], 'field names normalised to metadata');
mimir_write_same($result['payload']['etag'], 'W/"2"', 'etag returned');

// --- 5. Cache-invalidatie: alleen Germany/ItemCard weg ---
mimir_write_same(mimir_cache_all($pdo, 'kvtgermanylive_aad', 'KVT Germany', 'ItemCard'), [], 'Germany ItemCard rows invalidated');
mimir_write_same((int) $pdo->query("SELECT COUNT(*) FROM cache_coverage WHERE environment = 'kvtgermanylive_aad'")->fetchColumn(), 0, 'coverage invalidated');
mimir_write_same(count(mimir_cache_all($pdo, 'kvtmdlive_aad', 'Koninklijke van Twist', 'ItemCard')), 1, 'other company keeps its cache');
mimir_write_same($result['payload']['meta']['cache_invalidated'], true, 'meta says invalidated');

// PATCH naar NL met etag uit If-Match; key met spatie en quote wordt gecodeerd.
$result = mimir_write_handle('PATCH', $writer['key'], [], '{"company":"Koninklijke van Twist","table":"ItemCard","key":{"No":"A/1\'x"},"data":{"Description":"B"}}', 'W/"1"', $ok, $pdo);
mimir_write_same($result['status'], 200, 'patch ok');
mimir_write_same($calls[1]['url'], "https://bc.example.test:7148/kvtmdlive_aad/ODataV4/Company('Koninklijke%20van%20Twist')/ItemCard(No='A%2F1%27%27x')", 'PATCH URL on NL with encoded key');
mimir_write_same($calls[1]['auth']['user'], 'nl', 'NL credentials');
mimir_write_same(in_array('If-Match: W/"1"', $calls[1]['headers'], true), true, 'If-Match forwarded');

// PATCH zonder etag -> 428, geen BC-call
$result = mimir_write_handle('PATCH', $writer['key'], [], '{"company":"KVT Germany","table":"ItemCard","key":{"No":"A"},"data":{"Description":"B"}}', '', $ok, $pdo);
mimir_write_same($result['payload']['code'], 'etag_required', 'patch needs etag');
// DELETE met etag in body
$result = mimir_write_handle('DELETE', $writer['key'], [], '{"company":"KVT Germany","table":"ItemCard","key":{"No":"A"},"etag":"W/\"3\""}', '', $ok, $pdo);
mimir_write_same($result['status'], 200, 'delete ok (204 -> 200 JSON)');
mimir_write_same($calls[2]['method'], 'DELETE', 'delete sent');
mimir_write_same(count($calls), 3, 'no extra calls');

// Validatie: pad-injectie, onbekende tabel, onbekend veld, te grote body
foreach ([
    ['{"company":"KVT Germany","table":"ItemCard/../Customer","data":{"No":"A"}}', 'invalid_table'],
    ['{"company":"KVT Germany","table":"Secret","data":{"No":"A"}}', 'unknown_table'],
    ['{"company":"KVT Germany","table":"ItemCard","data":{"Bogus":"A"}}', 'invalid_field'],
    ['{"company":"Onbekend","table":"ItemCard","data":{"No":"A"}}', 'unknown_company'],
    [str_repeat(' ', MIMIR_WRITE_MAX_BODY_BYTES) . '{}', 'body_too_large'],
] as [$body, $code]) {
    $result = mimir_write_handle('POST', $writer['key'], [], $body, '', $ok, $pdo);
    mimir_write_same($result['payload']['code'] ?? null, $code, 'validation ' . $code);
}
mimir_write_same(count($calls), 3, 'validation errors never reach BC');

// BC-fout netjes doorgeven
$bcError = static function () use (&$calls): array {
    $calls[] = 'error';
    return ['code' => 400, 'raw' => '{"error":{"code":"Internal_EntityWithSameKeyExists","message":"The record already exists."}}', 'headers' => []];
};
$result = mimir_write_handle('POST', $writer['key'], [], '{"company":"KVT Germany","table":"ItemCard","data":{"No":"A"}}', '', $bcError, $pdo);
mimir_write_same($result['status'], 400, 'BC 4xx is passed through');
mimir_write_same($result['payload']['code'], 'bc_error', 'bc_error code');
mimir_write_same($result['payload']['bc_error']['code'], 'Internal_EntityWithSameKeyExists', 'BC error code passed');

// --- 6. Geen retry bij time-out ---
$timeoutCalls = 0;
$timeout = static function () use (&$timeoutCalls): array {
    $timeoutCalls++;
    throw new MimirWriteTimeoutException('cURL error: Operation timed out');
};
$result = mimir_write_handle('POST', $writer['key'], [], '{"company":"KVT Germany","table":"ItemCard","data":{"No":"T"}}', '', $timeout, $pdo);
mimir_write_same($timeoutCalls, 1, 'timeout is not retried');
mimir_write_same($result['status'], 504, 'timeout is 504');
mimir_write_same($result['payload']['code'], 'bc_timeout', 'bc_timeout code');
$transientCalls = 0;
$transient = static function () use (&$transientCalls): array {
    $transientCalls++;
    throw new MimirBcTransientException('HTTP 503', null, 503);
};
mimir_write_handle('POST', $writer['key'], [], '{"company":"KVT Germany","table":"ItemCard","data":{"No":"T"}}', '', $transient, $pdo);
mimir_write_same($transientCalls, 1, 'transient errors are not retried either');

// Log: caller, geen waarden
$log = (string) file_get_contents(mimir_write_log_path());
if (!str_contains($log, '"label":"Schrijver"') || !str_contains($log, '"fields":["no","Description"]') || str_contains($log, 'Nieuw')) {
    mimir_write_fail('write log should have caller and field names but no values: ' . $log);
}
$slotsLeft = mimir_bc_slots_used('kvtgermanylive_aad');
mimir_write_same($slotsLeft, 0, 'slots are released after writes');

// --- 7. Heatmap: lezen en schrijven gescheiden ---
mimir_usage_log($pdo, $writer['id'], 'query', $now);
$list = [];
foreach (mimir_key_list($pdo, 'tim@kvt.nl', $now) as $row) {
    $list[$row['id']] = $row;
}
$today = mimir_heatmap_today($now);
$countOn = static function (array $days) use ($today): int {
    foreach ($days as $day) {
        if ($day['date'] === $today) {
            return $day['count'];
        }
    }
    return -1;
};
// writes die BC bereikten: POST, PATCH, DELETE, BC-fout, timeout, transient = 6
mimir_write_same($countOn($list[$writer['id']]['write_days']), 6, 'write heatmap counts writes that reached BC');
mimir_write_same($countOn($list[$writer['id']]['days']), 1, 'read heatmap only counts reads');
mimir_write_same($list[$writer['id']]['write_note'], null, 'writer has a write heatmap');
mimir_write_same($list[$reader['id']]['write_days'], [], 'reader has no write heatmap');
mimir_write_same($list[$reader['id']]['write_note'], 'Schrijven niet toegestaan', 'reader shows the disabled text');
mimir_write_same(mimir_heatmap_options()['write_disabled_text'], 'Schrijven niet toegestaan', 'UI gets the text too');

// --- 8. CSRF op de checkbox ---
$_SESSION = [];
$_SERVER['HTTP_X_MIMIR_CSRF'] = '';
try {
    mimir_ui_cached_payload($pdo, 'keys_set_write', 'tim@kvt.nl', $now, ['id' => $reader['id'], 'can_write' => true]);
    mimir_write_fail('keys_set_write without CSRF should fail');
} catch (MimirUserException $error) {
    mimir_write_same($error->status, 403, 'missing CSRF is 403');
}
$token = mimir_csrf_token();
$_SERVER['HTTP_X_MIMIR_CSRF'] = 'wrong' . $token;
try {
    mimir_ui_cached_payload($pdo, 'keys_set_write', 'tim@kvt.nl', $now, ['id' => $reader['id'], 'can_write' => true]);
    mimir_write_fail('keys_set_write with wrong CSRF should fail');
} catch (MimirUserException $error) {
    mimir_write_same($error->status, 403, 'wrong CSRF is 403');
}
mimir_write_same(mimir_key_lookup($pdo, $reader['key'])['can_write'], false, 'rejected CSRF changes nothing');
$_SERVER['HTTP_X_MIMIR_CSRF'] = $token;
mimir_ui_cached_payload($pdo, 'keys_set_write', 'tim@kvt.nl', $now, ['id' => $reader['id'], 'can_write' => true]);
mimir_write_same(mimir_key_lookup($pdo, $reader['key'])['can_write'], true, 'valid CSRF enables write');
mimir_write_same(mimir_key_mirror_lookup($reader['key'])['can_write'], true, 'mirror follows the toggle');
mimir_ui_cached_payload($pdo, 'keys_set_write', 'tim@kvt.nl', $now, ['id' => $reader['id'], 'can_write' => false]);
mimir_write_same(mimir_key_lookup($pdo, $reader['key'])['can_write'], false, 'toggle back off');
$created = mimir_ui_cached_payload($pdo, 'keys_create', 'tim@kvt.nl', $now, ['label' => 'Via UI']);
mimir_write_same($created['can_write'], false, 'UI create without checkbox is read-only');
$_SERVER['HTTP_X_MIMIR_CSRF'] = '';
try {
    mimir_ui_cached_payload($pdo, 'keys_create', 'tim@kvt.nl', $now, ['label' => 'X', 'can_write' => true]);
    mimir_write_fail('keys_create without CSRF should fail');
} catch (MimirUserException $error) {
    mimir_write_same($error->status, 403, 'create needs CSRF');
}

// Pending invalidatie bij een write zonder SQLite
mimir_cache_upsert($pdo, 'kvtmdlive_aad', 'Koninklijke van Twist', 'ItemCard', 'B', ['No' => 'B'], $now);
mimir_write_same(mimir_write_invalidate(null, 'kvtmdlive_aad', 'Koninklijke van Twist', 'ItemCard', $now), false, 'without DB invalidation is pending');
mimir_pending_apply($pdo);
mimir_write_same(mimir_cache_all($pdo, 'kvtmdlive_aad', 'Koninklijke van Twist', 'ItemCard'), [], 'pending invalidation applied on next open');

exec('rm -rf ' . escapeshellarg($tmp));
fwrite(STDOUT, "ok\n");
