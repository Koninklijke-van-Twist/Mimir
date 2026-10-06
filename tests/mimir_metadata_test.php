<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/web/mimir_filter.php';
require_once dirname(__DIR__) . '/web/odata.php';
require_once dirname(__DIR__) . '/web/mimir_store.php';
require_once dirname(__DIR__) . '/web/mimir_metadata.php';

function mimir_md_fail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function mimir_md_same(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) {
        mimir_md_fail($message . ' expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }
}

function mimir_md_names(array $sets): array
{
    return array_map(static fn(array $set): string => (string) $set['name'], $sets);
}

$runtime = sys_get_temp_dir() . '/mimir-metadata-test-' . getmypid();
@mkdir($runtime, 0777, true);
$GLOBALS['mimir_runtime_dir'] = $runtime;
register_shutdown_function(static function () use ($runtime): void {
    foreach (glob($runtime . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($runtime);
});

// --- parser (bestaande odata_parse_metadata, nu met facets en navigatie) ---
$xml = (string) file_get_contents(__DIR__ . '/fixtures/metadata_sample.xml');
$parsed = odata_parse_metadata($xml);
mimir_md_same(mimir_md_names($parsed['entity_sets']), ['AppItems', 'Company', 'PageItemLedgerEntries', 'SalesLines', 'VendorList'], 'only container entity sets, sorted');
mimir_md_same($parsed['types']['AppItems']['properties']['Inventory'], 'Edm.Decimal', 'existing name => type map is unchanged');
mimir_md_same($parsed['types']['AppItems']['facets']['No'], ['nullable' => false, 'max_length' => 20], 'facets keep Nullable=false and MaxLength');
mimir_md_same(isset($parsed['types']['AppItems']['facets']['Inventory']), false, 'no facet entry without facets');
mimir_md_same($parsed['types']['SalesLinesType']['navigation'], [['name' => 'Item', 'type' => 'NAV.AppItems']], 'navigation properties are parsed');
$schema = mimir_schema_for_set($parsed, 'saleslines');
mimir_md_same($schema['keys'], ['Document_Type', 'Document_No', 'Line_No'], 'composite key via existing schema helper');

// --- catalogus ---
$catalog = mimir_catalog_from_parsed('kvtmdlive_aad', $parsed, 1791286800);
mimir_md_same($catalog['environment'], 'kvtmdlive_aad', 'catalog environment');
mimir_md_same($catalog['fetched_at'], 1791286800, 'catalog fetched_at');
mimir_md_same(count($catalog['entity_sets']), 5, 'catalog has every entity set');
$sales = mimir_catalog_find($catalog['entity_sets'], 'SALESLINES');
mimir_md_same($sales['entity_type'], 'NAV.SalesLinesType', 'entity type stays qualified');
mimir_md_same($sales['keys'], ['Document_Type', 'Document_No', 'Line_No'], 'catalog keys');
mimir_md_same($sales['properties'][1], ['name' => 'Document_No', 'type' => 'Edm.String', 'nullable' => false, 'max_length' => 20], 'property with facets');
mimir_md_same($sales['properties'][4], ['name' => 'Unit_Price', 'type' => 'Edm.Decimal', 'nullable' => true], 'property without facets is nullable');
mimir_md_same($sales['navigation'], [['name' => 'Item', 'type' => 'NAV.AppItems']], 'catalog navigation');
mimir_md_same(mimir_catalog_find($catalog['entity_sets'], 'Media'), null, 'entity type without set is not a table');

// Oude cache zonder facets/navigation blijft bruikbaar.
$legacy = ['entity_sets' => [['name' => 'ItemList', 'entity_type' => 'NAV.ItemList']], 'types' => ['ItemList' => ['keys' => ['No'], 'properties' => ['No' => 'Edm.String']]]];
$legacyCatalog = mimir_catalog_from_parsed('x', $legacy, 1);
mimir_md_same($legacyCatalog['entity_sets'][0]['properties'], [['name' => 'No', 'type' => 'Edm.String', 'nullable' => true]], 'legacy parsed metadata converts');
mimir_md_same($legacyCatalog['entity_sets'][0]['navigation'], [], 'legacy has empty navigation');

// --- zoekfilter ---
$sets = $catalog['entity_sets'];
mimir_md_same(count(mimir_catalog_search($sets, '  ')), 5, 'empty query returns everything');
$hits = mimir_catalog_search($sets, 'ITEM');
mimir_md_same(mimir_md_names($hits), ['AppItems', 'PageItemLedgerEntries'], 'name matches, case-insensitive');
mimir_md_same($hits[0]['match'], ['name' => true, 'fields' => []], 'name match info');
mimir_md_same($hits[1]['match'], ['name' => true, 'fields' => ['Item_No']], 'name and field match info');
$vendor = mimir_catalog_search($sets, 'Vendor');
mimir_md_same(mimir_md_names($vendor), ['VendorList', 'AppItems'], 'name matches first, then field-only matches');
mimir_md_same($vendor[1]['match'], ['name' => false, 'fields' => ['Vendor_No']], 'field-only match lists the field');
mimir_md_same(mimir_catalog_search($sets, 'vendor_no')[0]['match'], ['name' => false, 'fields' => ['Vendor_No']], 'field search is case-insensitive');
mimir_md_same(mimir_md_names(mimir_catalog_search($sets, 'no')), ['AppItems', 'PageItemLedgerEntries', 'SalesLines', 'VendorList'], 'field-only matches stay alphabetical');
mimir_md_same(mimir_catalog_search($sets, 'bestaatniet'), [], 'no match');

// --- paginering ---
mimir_md_same(mimir_catalog_page(287, 1), ['page' => 1, 'pages' => 6, 'per_page' => 50, 'offset' => 0, 'total' => 287], 'first page');
mimir_md_same(mimir_catalog_page(287, 6)['offset'], 250, 'last page offset');
mimir_md_same(mimir_catalog_page(287, 99)['page'], 6, 'page above range clamps to last');
mimir_md_same(mimir_catalog_page(287, 0)['page'], 1, 'page below range clamps to first');
mimir_md_same(mimir_catalog_page(287, 1, 500)['per_page'], 50, 'per_page is capped at 50');
mimir_md_same(mimir_catalog_page(0, 1), ['page' => 1, 'pages' => 1, 'per_page' => 50, 'offset' => 0, 'total' => 0], 'empty list has one page');
mimir_md_same(mimir_catalog_page(100, 2)['pages'], 2, 'exact multiple');

// --- leesbare datum ---
mimir_md_same(mimir_dutch_datetime(1791286800), '6 oktober 2026, 13:40', 'Dutch label in Europe/Amsterdam (CEST)');
mimir_md_same(mimir_dutch_datetime(1798794000), '1 januari 2027, 10:00', 'Dutch label in winter time (CET)');
mimir_md_same(mimir_dutch_datetime(0), '', 'no label without timestamp');

// --- environments: alleen $environment, nooit volgorde van $auth_list ---
$GLOBALS['auth_list'] = [
    'kvtgermanylive_aad' => ['mode' => 'basic', 'user' => 'de', 'pass' => 'x'],
    'kvtmdlive_aad' => ['mode' => 'basic', 'user' => 'nl', 'pass' => 'x'],
    'kvtmdlive_fat' => ['mode' => 'basic', 'user' => 'fat', 'pass' => 'x'],
];
$GLOBALS['environment'] = ['kvtmdlive_aad', 'kvtgermanylive_aad', 'zonder_credentials'];
mimir_md_same(mimir_catalog_environments(), ['kvtmdlive_aad', 'kvtgermanylive_aad'], 'environment order and credential filter');
$GLOBALS['environment'] = 'kvtgermanylive_aad, kvtmdlive_aad';
mimir_md_same(mimir_catalog_environments(), ['kvtgermanylive_aad', 'kvtmdlive_aad'], 'string environment list');
$GLOBALS['environment'] = [];
mimir_md_same(mimir_catalog_environments(), [], 'no fallback to the first auth_list key');
$GLOBALS['environment'] = ['kvtmdlive_aad', 'kvtgermanylive_aad'];

// --- opslag + payload ---
mimir_md_same(mimir_catalog_read('kvtmdlive_aad'), null, 'no catalog yet');
mimir_md_same(mimir_catalog_is_stale('kvtmdlive_aad', 1791286800), true, 'missing catalog is stale');
$empty = mimir_metadata_payload('');
mimir_md_same([$empty['environment'], $empty['total'], $empty['fetched_at']], ['kvtmdlive_aad', 0, null], 'payload without catalog defaults to first environment');
if (!str_contains((string) $empty['note'], 'nightly.php')) {
    mimir_md_fail('payload without catalog explains how to fill it');
}

// Snapshot uit de bestaande metadata-cache vult een ontbrekende catalogus.
file_put_contents($runtime . '/mimir-metadata-kvtgermanylive_aad.json', json_encode(['fetched_at' => 1791280000, 'parsed' => $legacy]));
$fromSnapshot = mimir_catalog_read('kvtgermanylive_aad');
mimir_md_same([$fromSnapshot['fetched_at'], mimir_md_names($fromSnapshot['entity_sets'])], [1791280000, ['ItemList']], 'catalog falls back to metadata snapshot');

mimir_catalog_write('kvtmdlive_aad', $parsed, 1791286800);
if (!is_file($runtime . '/mimir-catalog-kvtmdlive_aad.json')) {
    mimir_md_fail('catalog file is written in the runtime dir');
}
mimir_md_same(mimir_catalog_read('kvtmdlive_aad')['entity_sets'], $catalog['entity_sets'], 'catalog round-trips');
mimir_md_same(mimir_catalog_is_stale('kvtmdlive_aad', 1791286800 + 3600), false, 'fresh catalog');
mimir_md_same(mimir_catalog_is_stale('kvtmdlive_aad', 1791286800 + MIMIR_CATALOG_REFRESH_AGE + 1), true, 'old catalog is stale');
mimir_md_same(MIMIR_CATALOG_REFRESH_AGE < 86400, true, 'nightly threshold stays under a day');

$payload = mimir_metadata_payload('KVTMDLIVE_AAD', 'item');
mimir_md_same([$payload['environment'], $payload['total'], $payload['matched'], $payload['fetched_at_label']], ['kvtmdlive_aad', 5, 2, '6 oktober 2026, 13:40'], 'payload with search');
mimir_md_same($payload['environments'], ['kvtmdlive_aad', 'kvtgermanylive_aad'], 'payload lists environments');
mimir_md_same(isset($payload['page']), false, 'no paging unless asked');
$one = mimir_metadata_payload('kvtmdlive_aad', '', 'vendorlist');
mimir_md_same([$one['matched'], $one['entity_sets'][0]['name']], [1, 'VendorList'], 'single table');
$paged = mimir_metadata_payload('kvtmdlive_aad', '', '', 2, 2);
mimir_md_same([$paged['page'], $paged['pages'], $paged['per_page'], mimir_md_names($paged['entity_sets'])], [2, 3, 2, ['PageItemLedgerEntries', 'SalesLines']], 'paged payload');

foreach ([['onbekend_env', '', 404], ['kvtmdlive_aad', 'Bestaatniet', 404]] as [$env, $table, $status]) {
    try {
        mimir_metadata_payload($env, '', $table);
        mimir_md_fail('expected error for ' . $env . '/' . $table);
    } catch (MimirUserException $error) {
        mimir_md_same($error->status, $status, 'error status for ' . $env . '/' . $table);
    }
}

echo "ok\n";
