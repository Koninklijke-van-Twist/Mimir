<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/web/mimir_store.php';

function ff_same(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: {$message} expected " . var_export($expected, true) . ' got ' . var_export($actual, true) . "\n");
        exit(1);
    }
}

ff_same(mimir_filter_has_flowfilter("Date_Filter eq '..2026-10-26'"), true, 'string Date_Filter');
ff_same(mimir_filter_has_flowfilter(['field' => 'Global_Dimension_1_Filter', 'op' => 'eq', 'value' => 'X']), true, 'tree FlowFilter');
ff_same(mimir_filter_has_flowfilter("No eq '1000'"), false, 'gewoon veld');

$now = 1_700_000_000;
$pdo = mimir_db(':memory:');
$job = static fn (string $date): array => [
    'environment' => 'kvtmdlive_aad',
    'company' => 'KVT',
    'entity' => 'Rekeningschema',
    'service_prefix' => 'https://bc.test/env/ODataV4/',
    'schema' => ['keys' => ['No'], 'properties' => [
        'No' => 'Edm.String', 'Name' => 'Edm.String', 'Balance_at_Date' => 'Edm.Decimal', 'Date_Filter' => 'Edm.String',
    ]],
    'select' => ['No', 'Name', 'Balance_at_Date'],
    'filter' => "Date_Filter eq '.." . $date . "'",
    'top' => 0,
];
$calls = [];
$fetch = static function (string $url) use (&$calls): array {
    $calls[] = $url;
    $balance = str_contains(urldecode($url), '2026-10-10') ? 10 : 26;
    return ['value' => [['No' => '1000', 'Name' => 'Kas', 'Balance_at_Date' => $balance, 'Date_Filter' => '']]];
};
$a = mimir_query_entity($pdo, $job('2026-10-10'), $fetch, $now);
$b = mimir_query_entity($pdo, $job('2026-10-26'), $fetch, $now);
$c = mimir_query_entity($pdo, $job('2026-10-10'), $fetch, $now);
ff_same(count($a['value']), 1, 'FlowFilter-rijen niet lokaal weggefilterd');
ff_same($a['value'][0]['Balance_at_Date'], 10, 'saldo 10-10');
ff_same($b['value'][0]['Balance_at_Date'], 26, 'saldo 26-10');
ff_same($c['value'][0]['Balance_at_Date'], 10, 'geen hergebruik over Date_Filter-waarden');
ff_same(count($calls), 3, 'altijd live');
ff_same(mimir_cache_all($pdo, 'kvtmdlive_aad', 'KVT', 'Rekeningschema'), [], 'geen rijcache voor FlowFilter');

echo "OK\n";
