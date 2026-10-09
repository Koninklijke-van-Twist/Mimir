<?php

declare(strict_types=1);

/**
 * Splitsing: rijcache per environment + tabel, meta-database apart,
 * eenmalige migratie (idempotent, verificatie, inhaalronde), invalidatie en
 * een circuit per cache-database.
 */

require_once dirname(__DIR__) . '/web/mimir_split.php';
require_once dirname(__DIR__) . '/web/mimir_write.php';

function mimir_split_same(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) {
        fwrite(STDERR, 'FAIL: ' . $message . ' expected ' . var_export($expected, true) . ' got ' . var_export($actual, true) . "\n");
        exit(1);
    }
}

$root = sys_get_temp_dir() . '/mimir-split-' . bin2hex(random_bytes(4));
mkdir($root, 0777, true);
$GLOBALS['mimir_runtime_dir'] = $root;
unset($GLOBALS['mimir_reliability_db_path']);
mimir_bc_limit_set_dir($root . '/bc_slots');
$now = time();

// --- Oud bestand met sleutels, heatmap, schrijflog en een rijcache ---
mimir_split_same(mimir_db_path(), $root . '/mimir.sqlite', 'before migration the meta db is the old file');
$old = mimir_db(mimir_db_path());
$k1 = mimir_key_create($old, 'tim@kvt.nl', 'Consus', $now, true);
$k2 = mimir_key_create($old, 'tim@kvt.nl', 'Penates', $now);
mimir_usage_log($old, $k1['id'], 'query', $now - 100);
mimir_usage_log($old, $k1['id'], 'write', $now - 50, 0, 1, 0, 0, MIMIR_USAGE_KIND_WRITE);
mimir_usage_log($old, $k2['id'], 'tables', $now - 10);
mimir_write_log_insert($old, ['key_id' => $k1['id'], 'logged_at' => $now, 'method' => 'POST', 'company' => 'KVT', 'environment' => 'kvtmdlive_aad', 'table' => 'ItemCard', 'status' => 201, 'duration_ms' => 5, 'fields' => ['No'], 'entry_id' => 'e1']);
mimir_meta_put($old, 'metadata:x', ['entity_sets' => []], $now);
mimir_cache_upsert($old, 'kvtmdlive_aad', 'KVT', 'ItemCard', 'OLD', ['No' => 'OLD'], $now);

// --- Rijcache gaat naar een eigen bestand per environment + tabel ---
$cachePdo = mimir_cache_pdo_for($old, 'kvtmdlive_aad', 'ItemCard');
mimir_split_same(mimir_pdo_is_memory($cachePdo), false, 'file cache db');
mimir_split_same(is_file($root . '/cache/kvtmdlive_aad/itemcard.sqlite'), true, 'data/cache/<env>/<table>.sqlite exists');
mimir_split_same(mimir_cache_all($cachePdo, 'kvtmdlive_aad', 'KVT', 'ItemCard'), [], 'old 2.4 GB cache is not reused');
mimir_cache_upsert($cachePdo, 'kvtmdlive_aad', 'KVT', 'ItemCard', 'A', ['No' => 'A'], $now);
$germany = mimir_cache_pdo_for($old, 'kvtgermanylive_aad', 'ItemCard');
mimir_split_same($germany === $cachePdo, false, 'other environment has its own db');
mimir_split_same(mimir_cache_pdo_for(mimir_db(':memory:'), 'kvtmdlive_aad', 'ItemCard') !== $cachePdo, true, 'memory meta pdo stays memory (live bypass)');

// Invalidatie na een write raakt de tabel-database.
mimir_cache_invalidate_entity($old, 'kvtmdlive_aad', 'KVT', 'itemcard');
mimir_split_same(mimir_cache_all($cachePdo, 'kvtmdlive_aad', 'KVT', 'ItemCard'), [], 'invalidation clears the table db');

// Circuit per cache-database: kapotte tabel gaat live, de rest blijft werken.
mkdir($root . '/cache/kvtmdlive_aad/customer.sqlite', 0777, true); // pad is een map: openen faalt
$broken = mimir_cache_pdo_for($old, 'kvtmdlive_aad', 'Customer');
mimir_split_same(mimir_pdo_is_memory($broken), true, 'broken table db falls back to memory (live)');
mimir_split_same(mimir_cache_db_circuit_open($root . '/cache/kvtmdlive_aad/customer.sqlite'), true, 'own circuit for that table');
mimir_split_same(mimir_circuit_is_open(), false, 'global circuit stays closed');
mimir_split_same(mimir_pdo_is_memory(mimir_cache_pdo_for($old, 'kvtmdlive_aad', 'ItemCard')), false, 'other tables keep their cache');
try {
    mimir_cache_invalidate_entity($old, 'kvtmdlive_aad', 'KVT', 'Customer');
    mimir_split_same(true, false, 'invalidation on a broken db must throw (pending)');
} catch (RuntimeException) {
}
mimir_split_same(mimir_write_invalidate($old, 'kvtmdlive_aad', 'KVT', 'Customer', $now), false, 'write invalidation becomes pending');

// --- Migratie ---
$lines = [];
$result = mimir_split_migrate($root, 0, static function (string $line) use (&$lines): void {
    $lines[] = $line;
});
mimir_split_same($result['ok'], true, 'migration verified');
mimir_split_same($result['first']['tables']['api_keys'], ['old' => 2, 'copied' => 2, 'present' => 2], 'keys copied');
mimir_split_same($result['first']['tables']['api_usage']['present'], 3, 'heatmap rows copied');
mimir_split_same($result['first']['tables']['write_log']['present'], 1, 'write log copied');
mimir_split_same(is_file($root . '/' . MIMIR_SPLIT_MARKER), true, 'marker set');
mimir_split_same(mimir_db_path(), $root . '/mimir-meta.sqlite', 'requests switch to the meta db');

$meta = mimir_db(mimir_db_path());
mimir_split_same(mimir_key_lookup($meta, $k1['key'])['can_write'], true, 'key with write right works after migration');
$kinds = $meta->query("SELECT kind, COUNT(*) FROM api_usage GROUP BY kind ORDER BY kind")->fetchAll(PDO::FETCH_KEY_PAIR);
mimir_split_same($kinds, ['read' => 2, 'write' => 1], 'kind read/write preserved');
mimir_split_same(count(mimir_write_log_list($meta, $k1['id'])['value']), 1, 'write log readable');
mimir_split_same((int) $meta->query('SELECT COUNT(*) FROM cache_rows')->fetchColumn(), 0, 'old row cache not migrated');

// Idempotent + inhaalronde: een late write in het oude bestand komt alsnog mee.
mimir_usage_log($old, $k2['id'], 'query', $now);
$again = mimir_split_migrate($root, 0);
mimir_split_same($again['already'], true, 'second run sees the marker');
mimir_split_same($again['first']['tables']['api_keys']['copied'], 0, 'no duplicate keys');
mimir_split_same($again['first']['tables']['api_usage']['copied'], 1, 'late usage row caught up');
mimir_split_same((int) $meta->query('SELECT COUNT(*) FROM api_usage')->fetchColumn(), 4, 'no duplicates');

// Nieuwe sleutels na de migratie krijgen geen botsende id's.
$k3 = mimir_key_create($meta, 'tim@kvt.nl', 'Nieuw', $now);
mimir_split_same($k3['id'] > $k2['id'], true, 'autoincrement continues after copied ids');

exec('rm -rf ' . escapeshellarg($root));
fwrite(STDOUT, "ok\n");
