<?php

declare(strict_types=1);

/**
 * Bugbot PR #11: een storage-fout in de rijcache van één tabel (ná het
 * openen) zet alleen het circuit van die tabel, nooit het globale circuit.
 * Bugbot PR #9: de pending-wachtrij raakt niets kwijt als het proces na het
 * claimen crasht; achtergelaten .processing-bestanden worden overgenomen.
 */

require_once dirname(__DIR__) . '/web/mimir_write.php';

function mimir_tc_same(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) {
        fwrite(STDERR, 'FAIL: ' . $message . ' expected ' . var_export($expected, true) . ' got ' . var_export($actual, true) . "\n");
        exit(1);
    }
}

$root = sys_get_temp_dir() . '/mimir-tc-' . bin2hex(random_bytes(4));
mkdir($root, 0777, true);
$GLOBALS['mimir_runtime_dir'] = $root;
unset($GLOBALS['mimir_reliability_db_path']);
mimir_bc_limit_set_dir($root . '/bc_slots');
$GLOBALS['mimir_probe_interval'] = 3600;
$now = time();
$env = 'kvtmdlive_aad';
$prefix = 'https://bc.example.test:7148/BC/ODataV4/';

$meta = mimir_db(mimir_db_path());
$key = mimir_key_create($meta, 'tim@kvt.nl', 'Consus', $now);
$customerPath = $root . '/cache/' . $env . '/customer.sqlite';
$fetch = static function (string $url): array {
    return ['value' => [['No' => 'C1', 'Name' => 'Een']]];
};

// --- 1. Schrijffout tijdens mimir_fetch_collection op een tabel-database ---
$customer = mimir_cache_pdo_for($meta, $env, 'Customer');
$item = mimir_cache_pdo_for($meta, $env, 'ItemCard');
mimir_cache_upsert($item, $env, 'KVT', 'ItemCard', 'I1', ['No' => 'I1'], $now);
$customer->exec('DROP TABLE cache_rows'); // elke upsert faalt nu met een PDOException
$GLOBALS['mimir_stamp_bc_live'] = false;
$result = mimir_fetch_collection($customer, $env, 'KVT', 'Customer', $prefix, null, ['No' => 'Edm.String', 'Name' => 'Edm.String'], ['No'], [], null, 'local', $fetch, $now);
mimir_tc_same(count($result['rows']), 1, 'rows still come from BC');
mimir_tc_same(mimir_circuit_is_open(), false, 'table write error does not open the global circuit');
mimir_tc_same(mimir_cache_db_circuit_open($customerPath), true, 'only that table gets a circuit');
mimir_tc_same($GLOBALS['mimir_stamp_bc_live'], true, 'response is stamped bc-live');
mimir_tc_same(mimir_ui_open_db() instanceof PDO, true, 'keys page still opens the meta db (no 503)');
mimir_tc_same(mimir_pdo_is_memory(mimir_cache_pdo_for($meta, $env, 'Customer')), true, 'broken table goes live while its circuit is open');
mimir_tc_same(mimir_pdo_is_memory(mimir_cache_pdo_for($meta, $env, 'ItemCard')), false, 'other tables keep their cache');
mimir_tc_same(count(mimir_cache_all(mimir_cache_pdo_for($meta, $env, 'ItemCard'), $env, 'KVT', 'ItemCard')), 1, 'other table cache intact');

// --- 1b. Tweede storage-fout op dezelfde (al uit de pool gehaalde) PDO ---
mimir_circuit_close('test');
mimir_cache_storage_failure($customer, new PDOException('database is locked'), $env, 'Customer');
mimir_tc_same(mimir_circuit_is_open(), false, 'second error on the same table PDO stays per table');
$coverageError = null;
try {
    mimir_coverage_put($customer, $env, 'KVT', 'Customer', '', '', $now, 1);
} catch (Throwable $error) {
    $coverageError = $error;
}
mimir_tc_same($coverageError instanceof Throwable, false, 'coverage table still exists (only cache_rows dropped)');
mimir_fetch_collection($customer, $env, 'KVT', 'Customer', $prefix, null, ['No' => 'Edm.String', 'Name' => 'Edm.String'], ['No'], [], null, 'local', $fetch, $now);
mimir_tc_same(mimir_circuit_is_open(), false, 'repeated fetch on the failed table PDO keeps the global circuit closed');
mimir_tc_same(mimir_cache_db_fail($customer, new PDOException('disk I/O error'), $env, 'Customer'), true, 'failed PDO is still recognised as a table db');
$GLOBALS['mimir_stamp_bc_live'] = false;
$second = mimir_query_entity_isolated($customer, [
    'environment' => $env,
    'company' => 'KVT',
    'entity' => 'Customer',
    'service_prefix' => $prefix,
    'top' => 1,
    'max_age' => 0,
    'schema' => ['keys' => ['No'], 'properties' => ['No' => 'Edm.String', 'Name' => 'Edm.String']],
], $fetch, $now);
mimir_tc_same(count($second['value']), 1, 'query on the failed PDO is answered live');
mimir_tc_same(mimir_circuit_is_open(), false, 'still no global circuit');
mimir_tc_same(mimir_ui_open_db() instanceof PDO, true, 'keys page fine after repeated errors');

// --- 2. Leesfout in de query (via mimir_with_cache_or_live, zoals de API) ---
@unlink($customerPath . '.circuit');
mimir_cache_db_reset_pool();
$vendorPath = $root . '/cache/' . $env . '/vendor.sqlite';
$vendor = mimir_cache_pdo_for($meta, $env, 'Vendor');
$vendor->exec('DROP TABLE cache_coverage');
$vendor->exec('DROP TABLE cache_rows');
$job = [
    'environment' => $env,
    'company' => 'KVT',
    'entity' => 'Vendor',
    'service_prefix' => $prefix,
    'top' => 1,
    'schema' => ['keys' => ['No'], 'properties' => ['No' => 'Edm.String', 'Name' => 'Edm.String']],
];
$liveCalled = false;
$GLOBALS['mimir_stamp_bc_live'] = false;
$payload = mimir_with_cache_or_live(
    static fn (): array => mimir_query_entity_isolated($vendor, $job, $fetch, $now),
    static function () use (&$liveCalled): array {
        $liveCalled = true;

        return ['value' => [], 'meta' => []];
    },
    ['environment' => $env, 'entity' => 'Vendor']
);
mimir_tc_same($liveCalled, false, 'table error handled inside the table query, not by the global failover');
mimir_tc_same(count($payload['value']), 1, 'query answered live from BC');
mimir_tc_same($payload['meta']['source'] ?? null, 'bc-live', 'stamped bc-live');
mimir_tc_same(mimir_circuit_is_open(), false, 'global circuit stays closed on a table read error');
mimir_tc_same(mimir_cache_db_circuit_open($vendorPath), true, 'vendor table circuit open');
mimir_tc_same(mimir_ui_open_db() instanceof PDO, true, 'keys page still fine after read error');

// Fouten op een PDO die geen tabel-database is (zonder splitsing) blijven globaal.
$plain = mimir_db(':memory:');
$plain->exec('DROP TABLE cache_rows');
mimir_fetch_collection($plain, $env, 'KVT', 'Customer', $prefix, null, ['No' => 'Edm.String'], ['No'], [], null, 'local', $fetch, $now);
mimir_tc_same(mimir_circuit_is_open(), true, 'non-split cache error still trips the global circuit');
mimir_circuit_close('test');

// --- 3. Pending-wachtrij: crash na claimen verliest niets ---
mimir_cache_db_reset_pool();
mimir_cache_upsert(mimir_cache_pdo_for($meta, $env, 'Contact'), $env, 'KVT', 'Contact', 'C1', ['No' => 'C1'], $now);
mimir_pending_add('invalidate', ['environment' => $env, 'company' => 'KVT', 'entity' => 'Contact', 'at' => $now]);
mimir_pending_add('usage', ['key_id' => $key['id'], 'endpoint' => 'write', 'at' => $now]);
$claim = mimir_pending_take(); // proces "crasht" hierna: niets toegepast, niets opgeruimd
mimir_tc_same(count($claim['items']), 2, 'claimed two items');
$leftover = glob(mimir_pending_path() . '.processing.*') ?: [];
mimir_tc_same(count($leftover), 1, 'claimed file is kept until applied');
mimir_tc_same(mimir_pending_apply($meta), 0, 'a fresh claim of another process is not taken over');
touch($leftover[0], time() - MIMIR_PENDING_STALE_SECONDS - 5);
mimir_tc_same(mimir_pending_apply($meta), 2, 'stale claim is recovered and applied');
mimir_tc_same(glob(mimir_pending_path() . '.processing.*') ?: [], [], 'claim removed after apply');
mimir_tc_same(mimir_cache_all(mimir_cache_pdo_for($meta, $env, 'Contact'), $env, 'KVT', 'Contact'), [], 'invalidation was not lost');
mimir_tc_same((int) $meta->query("SELECT COUNT(*) FROM api_usage WHERE kind = 'write'")->fetchColumn(), 1, 'usage was not lost');

// Claim van een wachtrij die al lang bestaat (storing > 10 min) is vers:
// rename behoudt de oude mtime, dus de claim moet zelf worden aangeraakt.
mimir_pending_add('usage', ['key_id' => $key['id'], 'endpoint' => 'write', 'at' => $now]);
touch(mimir_pending_path(), time() - MIMIR_PENDING_STALE_SECONDS - 60);
$claim = mimir_pending_take(); // proces A is hiermee bezig
mimir_tc_same(count($claim['items']), 1, 'old queue claimed');
mimir_tc_same(mimir_pending_apply($meta), 0, 'another process does not take over a fresh claim of an old queue');
mimir_tc_same((int) $meta->query("SELECT COUNT(*) FROM api_usage WHERE kind = 'write'")->fetchColumn(), 1, 'no duplicate usage');
mimir_pending_done($claim['files']);

// Item dat niet lukt gaat terug in de wachtrij; het claimbestand verdwijnt.
mimir_pending_add('invalidate', ['environment' => $env, 'company' => 'KVT', 'entity' => 'Broken', 'at' => $now]);
mkdir($root . '/cache/' . $env . '/broken.sqlite', 0777, true);
mimir_tc_same(mimir_pending_apply($meta), 0, 'broken table cannot be invalidated yet');
mimir_tc_same(count(array_filter(explode("\n", (string) file_get_contents(mimir_pending_path())))), 1, 'failed item re-queued');
mimir_tc_same(glob(mimir_pending_path() . '.processing.*') ?: [], [], 'no claim left behind after re-queue');

exec('rm -rf ' . escapeshellarg($root));
fwrite(STDOUT, "ok\n");
