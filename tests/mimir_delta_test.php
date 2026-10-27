<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/web/mimir_store.php';

function dt_same(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: {$message} expected " . var_export($expected, true) . ' got ' . var_export($actual, true) . "\n");
        exit(1);
    }
}

// Detectie uit metadata-typen.
dt_same(mimir_delta_change_field(['SystemModifiedAt' => 'Edm.DateTimeOffset', 'Last_Date_Modified' => 'Edm.Date']), ['field' => 'SystemModifiedAt', 'date_only' => false], 'SystemModifiedAt eerst');
dt_same(mimir_delta_change_field(['Last_Date_Modified' => 'Edm.Date']), ['field' => 'Last_Date_Modified', 'date_only' => true], 'alleen datum');
dt_same(mimir_delta_change_field(['Last_Modified_Date_Time' => 'Edm.DateTimeOffset']), ['field' => 'Last_Modified_Date_Time', 'date_only' => false], 'workflow-variant');
dt_same(mimir_delta_change_field(['Last_Date_Modified' => 'Edm.String']), null, 'verkeerd type telt niet');
dt_same(mimir_delta_change_field(['No' => 'Edm.String']), null, 'geen veld');

// Filter: datetime gt (start - 10 min), datum ge datum van start.
$start = gmmktime(9, 30, 0, 10, 27, 2026);
dt_same(mimir_delta_filter(['field' => 'SystemModifiedAt', 'date_only' => false], $start), 'SystemModifiedAt gt 2026-10-27T09:20:00Z', 'datetime-filter');
dt_same(mimir_delta_filter(['field' => 'Last_Date_Modified', 'date_only' => true], $start), 'Last_Date_Modified ge 2026-10-27', 'datum-filter');

// Twijfelgevallen -> volledig.
$base = [
    'filter' => null, 'top' => 0, 'route' => null, 'keys' => ['No'],
    'types' => ['No' => 'Edm.String', 'Description' => 'Edm.String', 'Last_Date_Modified' => 'Edm.Date'],
];
dt_same(mimir_delta_reason($base), null, 'basis mag delta');
dt_same(mimir_delta_reason(['types' => ['No' => 'Edm.String']] + $base), 'geen wijzigingsveld', 'geen veld');
dt_same(mimir_delta_reason(['keys' => []] + $base), 'sleutel onbekend', 'geen sleutel');
dt_same(mimir_delta_reason(['top' => 100] + $base), 'top', 'top');
dt_same(mimir_delta_reason(['route' => 'tunnel'] + $base), 'tunnel', 'tunnel');
dt_same(mimir_delta_reason(['types' => $base['types'] + ['Date_Filter' => 'Edm.String']] + $base), 'flowfields', 'flowfields');
dt_same(mimir_delta_reason(['filter' => "No eq '1' or Description eq 'x'"] + $base), 'filter niet lokaal te bewijzen', 'ruwe filterstring');

// End-to-end: volledig, dan delta + merge op sleutel, dan dagelijkse volledige verversing.
$now = 1_800_000_000;
$pdo = mimir_db(':memory:');
$bc = [
    '1' => ['No' => '1', 'Description' => 'Oud', 'Blocked' => false, 'Last_Date_Modified' => '2026-01-01'],
    '2' => ['No' => '2', 'Description' => 'Twee', 'Blocked' => false, 'Last_Date_Modified' => '2026-01-01'],
    '3' => ['No' => '3', 'Description' => 'Drie', 'Blocked' => false, 'Last_Date_Modified' => '2026-01-01'],
];
$calls = [];
$fetch = static function (string $url) use (&$calls, &$bc): array {
    $decoded = urldecode($url);
    $calls[] = $decoded;
    if (str_contains($decoded, 'Last_Date_Modified ge')) {
        return ['value' => array_values(array_filter($bc, static fn ($r) => $r['Last_Date_Modified'] === 'NIEUW'))];
    }
    return ['value' => array_values($bc)];
};
$job = [
    'environment' => 'env', 'company' => 'KVT', 'entity' => 'AppItems', 'service_prefix' => 'https://bc.test/ODataV4/',
    'schema' => ['keys' => ['No'], 'properties' => ['No' => 'Edm.String', 'Description' => 'Edm.String', 'Blocked' => 'Edm.Boolean', 'Last_Date_Modified' => 'Edm.Date']],
    'select' => ['No', 'Description'], 'filter' => ['field' => 'Blocked', 'op' => 'eq', 'value' => false], 'top' => 0, 'max_age' => 0,
];
$a = mimir_query_entity($pdo, $job, $fetch, $now);
dt_same($a['meta']['delta_mode'], 'full', 'eerste keer volledig');
dt_same(count($a['value']), 3, '3 rijen');

$bc['1']['Description'] = 'Nieuw';
$bc['1']['Last_Date_Modified'] = 'NIEUW';
$bc['2']['Blocked'] = true;              // valt nu buiten het filter
$bc['2']['Last_Date_Modified'] = 'NIEUW';
$b = mimir_query_entity($pdo, $job, $fetch, $now + 3600);
dt_same($b['meta']['delta_mode'], 'delta', 'tweede keer delta');
dt_same(str_contains(end($calls), 'Blocked'), false, 'delta-filter alleen op wijzigingsveld');
$desc = array_column($b['value'], 'Description', 'No');
ksort($desc);
dt_same($desc, ['1' => 'Nieuw', '3' => 'Drie'], 'merge op sleutel + filter lokaal opnieuw');

// Delta faalt -> volledig.
$failing = static function (string $url) use ($fetch): array {
    if (str_contains(urldecode($url), 'Last_Date_Modified ge')) {
        throw new RuntimeException('400 filter niet ondersteund');
    }
    return $fetch($url);
};
$c = mimir_query_entity($pdo, $job, $failing, $now + 7200);
dt_same($c['meta']['delta_mode'], 'full', 'fout in delta -> volledig');

// Verwijderd in BC: pas zichtbaar na de dagelijkse volledige verversing.
unset($bc['3']);
$d = mimir_query_entity($pdo, $job, $fetch, $now + 7200 + MIMIR_DELTA_FULL_REFRESH);
dt_same($d['meta']['delta_mode'], 'full', 'periodiek volledig');
dt_same(array_column($d['value'], 'No'), ['1'], 'verwijderde rij weg na volledige fetch');

// Na invalidatie (write) geen delta op lege cache.
mimir_cache_invalidate_rows($pdo, 'env', 'KVT', 'AppItems');
$e = mimir_query_entity($pdo, $job, $fetch, $now + 7300 + MIMIR_DELTA_FULL_REFRESH);
dt_same($e['meta']['delta_mode'], 'full', 'na invalidatie volledig');

// top > 0 -> nooit delta.
$f = mimir_query_entity($pdo, ['top' => 10] + $job, $fetch, $now + 9000 + MIMIR_DELTA_FULL_REFRESH);
dt_same(str_starts_with((string) ($f['meta']['delta_mode'] ?? 'full (top)'), 'full'), true, 'top -> volledig');

echo "OK\n";
