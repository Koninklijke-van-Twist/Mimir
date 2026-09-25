<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/web/auth_helper.php';
require_once dirname(__DIR__) . '/web/mimir_store.php';

function mimir_env_fail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function mimir_env_same(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) {
        mimir_env_fail($message . ' expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }
}

$baseUrl = 'https://kvtmd365.kvt.nl:7148';
$environment = ['kvtmdlive_aad', 'kvtgermanylive_aad', 'not_in_auth_list'];
$auth_list = [
    'kvtmdlive_aad' => ['mode' => 'basic', 'user' => 'nl', 'pass' => 'secret'],
    'kvtgermanylive_aad' => ['mode' => 'ntlm', 'user' => 'de', 'pass' => 'secret'],
    'kvtmdlive_fat' => ['mode' => 'basic', 'user' => 'fat', 'pass' => 'secret'],
];

mimir_env_same(
    auth_get_active_environments(),
    ['kvtmdlive_aad', 'kvtgermanylive_aad'],
    'active list keeps only environments that have credentials'
);
mimir_env_same(auth_get_primary_environment(), 'kvtmdlive_aad', 'primary environment is the first active one');
mimir_env_same(auth_get_auth_for_environment('kvtgermanylive_aad')['user'], 'de', 'Germany credentials are independent');

$environment = [];
mimir_env_same(auth_get_active_environments(), ['kvtmdlive_aad'], 'empty active list falls back to the first auth_list key');
$environment = ['kvtmdlive_aad', 'kvtgermanylive_aad'];

$GLOBALS['demeter_company_environment_map'] = [
    'Koninklijke van Twist' => 'kvtmdlive_aad',
    'KVT Germany' => 'kvtgermanylive_aad',
];
mimir_env_same(auth_get_environment_for_company('kvt germany'), 'kvtgermanylive_aad', 'company lookup is case-insensitive');
mimir_env_same(auth_get_auth_for_company('KVT Germany')['mode'], 'ntlm', 'company resolves Germany credentials');
mimir_env_same(auth_get_environment_for_company('Koninklijke van Twist'), 'kvtmdlive_aad', 'KVT stays on the NL database');

$prefix = mimir_odata_prefix_for_environment('kvtgermanylive_aad');
$url = mimir_collection_url($prefix, "O'Brien", 'ItemList', ['$select' => 'No']);
mimir_env_same(
    $url,
    "https://kvtmd365.kvt.nl:7148/kvtgermanylive_aad/ODataV4/Company('O%27%27Brien')/ItemList?%24select=No",
    'company URL follows Penates'
);

$companiesUrl = auth_build_companies_urls('kvtgermanylive_aad')[0];
if (!str_contains($companiesUrl, '/kvtgermanylive_aad/ODataV4/Companies?')) {
    mimir_env_fail('company discovery URL must target the Germany environment: ' . $companiesUrl);
}

$now = 1_700_000_000;
$schema = [
    'keys' => ['No'],
    'properties' => [
        'No' => 'Edm.String',
        'Description' => 'Edm.String',
    ],
];
$key = mimir_row_key(['No' => 'A'], ['No']);

$legacy = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$legacy->exec(
    'CREATE TABLE cache_rows (
        company TEXT NOT NULL,
        entity TEXT NOT NULL,
        row_key TEXT NOT NULL,
        payload TEXT NOT NULL,
        fetched_at INTEGER NOT NULL,
        PRIMARY KEY (company, entity, row_key)
    )'
);
$legacy->exec(
    'CREATE TABLE cache_coverage (
        company TEXT NOT NULL,
        entity TEXT NOT NULL,
        filter_sig TEXT NOT NULL,
        select_sig TEXT NOT NULL,
        fetched_at INTEGER NOT NULL,
        row_count INTEGER NOT NULL,
        PRIMARY KEY (company, entity, filter_sig, select_sig)
    )'
);
$legacy->prepare('INSERT INTO cache_rows (company, entity, row_key, payload, fetched_at) VALUES (?, ?, ?, ?, ?)')
    ->execute(['KVT', 'ItemList', $key, '{"No":"A","Description":"oud"}', $now - 10]);
$legacy->prepare('INSERT INTO cache_coverage (company, entity, filter_sig, select_sig, fetched_at, row_count) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute(['KVT', 'ItemList', '', '*', $now - 10, 1]);
mimir_migrate($legacy);
$migrated = $legacy->query('SELECT environment, payload FROM cache_rows')->fetch(PDO::FETCH_ASSOC);
mimir_env_same((string) ($migrated['environment'] ?? 'missing'), '', 'legacy rows keep an empty environment');
if (!in_array('environment', mimir_sqlite_columns($legacy, 'cache_rows'), true)) {
    mimir_env_fail('migrated cache_rows must include environment');
}
if (!in_array('environment', mimir_sqlite_columns($legacy, 'cache_coverage'), true)) {
    mimir_env_fail('migrated cache_coverage must include environment');
}

$calls = [];
$after = mimir_query_entity($legacy, [
    'environment' => 'kvtmdlive_aad',
    'company' => 'KVT',
    'entity' => 'ItemList',
    'service_prefix' => $prefix,
    'select' => ['No', 'Description'],
    'filter' => null,
    'max_age' => 600,
    'top' => 10,
    'schema' => $schema,
], function (string $url) use (&$calls): array {
    $calls[] = $url;
    return ['value' => [['No' => 'A', 'Description' => 'live']]];
}, $now);
mimir_env_same(count($calls) > 0, true, 'legacy rows are not served for a real environment');
mimir_env_same($after['value'][0]['Description'], 'live', 'live environment replaces the answer');
$oldStillThere = (int) $legacy->query("SELECT COUNT(*) FROM cache_rows WHERE environment = ''")->fetchColumn();
mimir_env_same($oldStillThere, 1, 'legacy row was not overwritten');

$pdo = mimir_db(':memory:');
mimir_cache_upsert($pdo, 'kvtmdlive_aad', 'KVT', 'ItemList', $key, ['No' => 'A', 'Description' => 'NL'], $now - 5);
mimir_cache_upsert($pdo, 'kvtgermanylive_aad', 'KVT', 'ItemList', $key, ['No' => 'A', 'Description' => 'DE'], $now - 5);
mimir_coverage_put($pdo, 'kvtmdlive_aad', 'KVT', 'ItemList', '', '*', $now - 5, 1);
mimir_coverage_put($pdo, 'kvtgermanylive_aad', 'KVT', 'ItemList', '', '*', $now - 5, 1);
$job = [
    'company' => 'KVT',
    'entity' => 'ItemList',
    'service_prefix' => $prefix,
    'select' => ['No', 'Description'],
    'filter' => null,
    'max_age' => 600,
    'top' => 10,
    'schema' => $schema,
];
$nl = mimir_query_entity($pdo, $job + ['environment' => 'kvtmdlive_aad'], static fn (): array => ['value' => []], $now);
$de = mimir_query_entity($pdo, $job + ['environment' => 'kvtgermanylive_aad'], static fn (): array => ['value' => []], $now);
mimir_env_same($nl['value'][0]['Description'], 'NL', 'NL cache stays on kvtmdlive_aad');
mimir_env_same($de['value'][0]['Description'], 'DE', 'Germany cache stays on kvtgermanylive_aad');
mimir_env_same($nl['meta']['environment'], 'kvtmdlive_aad', 'meta names the NL environment');
mimir_env_same($de['meta']['environment'], 'kvtgermanylive_aad', 'meta names the Germany environment');
mimir_env_same($nl['meta']['from_cache'], 1, 'NL row served from its own cache');
mimir_env_same($de['meta']['from_cache'], 1, 'Germany row served from its own cache');

fwrite(STDOUT, "ok\n");
