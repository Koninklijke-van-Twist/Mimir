<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/web/mimir_store.php';
$GLOBALS['mimir_delta_normal_fields'] = []; // tests los van de echte config

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
    'entity' => 'Elders', 'filter' => null, 'top' => 0, 'route' => null, 'keys' => ['No'],
    'types' => ['No' => 'Edm.String', 'Description' => 'Edm.String', 'Last_Date_Modified' => 'Edm.Date'],
];
dt_same(mimir_delta_reason($base), null, 'basis mag delta');
dt_same(mimir_delta_reason(['types' => ['No' => 'Edm.String']] + $base), 'geen wijzigingsveld', 'geen veld');
dt_same(mimir_delta_reason(['keys' => []] + $base), 'sleutel onbekend', 'geen sleutel');
dt_same(mimir_delta_reason(['top' => 100] + $base), 'top', 'top');
dt_same(mimir_delta_reason(['route' => 'tunnel'] + $base), 'tunnel', 'tunnel');
dt_same(mimir_delta_reason(['types' => $base['types'] + ['Date_Filter' => 'Edm.String']] + $base), 'flowfields', 'flowfields');
dt_same(mimir_delta_reason(['filter' => "No eq '1' or Description eq 'x'"] + $base), 'filter niet lokaal te bewijzen', 'ruwe filterstring');

// FlowField-tabel: alleen met whitelist en select binnen de whitelist.
$GLOBALS['mimir_delta_normal_fields'] = ['AppItems' => ['No', 'Description', 'Blocked', 'Last_Date_Modified']];
$ff = ['entity' => 'AppItems', 'types' => $base['types'] + ['Date_Filter' => 'Edm.String', 'Inventory' => 'Edm.Decimal']] + $base;
dt_same(mimir_delta_reason(['select' => ['No', 'Description']] + $ff), null, 'whitelist-select -> delta');
dt_same(mimir_delta_reason(['select' => ['No', 'Inventory']] + $ff), 'flowfields (kolom niet op whitelist)', 'niet-whitelist kolom -> volledig');
dt_same(mimir_delta_reason(['select' => ['No'], 'filter' => ['field' => 'Inventory', 'op' => 'gt', 'value' => 0]] + $ff), 'flowfields (kolom niet op whitelist)', 'filterveld niet op whitelist');
dt_same(mimir_delta_reason(['select' => []] + $ff), 'flowfields (geen select)', 'geen select -> volledig');
dt_same(mimir_delta_reason(['select' => ['No'], 'entity' => 'Andere'] + $ff), 'flowfields', 'tabel zonder whitelist -> volledig');
$GLOBALS['mimir_delta_normal_fields'] = ['AppItems' => ['No', 'Description', 'Blocked', 'Last_Date_Modified'], 'Leeg' => []];
dt_same(mimir_delta_reason(['select' => ['No'], 'entity' => 'Leeg'] + $base), 'flowfields', 'in config met lege lijst -> volledig, ook zonder *_Filter');
dt_same(mimir_delta_reason(['select' => ['No', 'Inventory'], 'entity' => 'AppItems'] + $base), 'flowfields (kolom niet op whitelist)', 'in config zonder *_Filter telt als FlowField-tabel');
dt_same(mimir_delta_select_query($ff), ['$select' => 'No,Description,Last_Date_Modified'], 'delta haalt alleen whitelist-velden');
$GLOBALS['mimir_delta_normal_fields'] = [];

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
    if (str_contains($decoded, '$count=true')) {
        if ($GLOBALS['dt_count_unknown'] ?? false) {
            return ['value' => []];
        }
        $n = 0;
        foreach ($bc as $r) {
            $n += str_contains($decoded, 'Blocked eq false') ? (int) !$r['Blocked'] : 1;
        }
        return ['@odata.count' => $n, 'value' => []];
    }
    if (str_contains($decoded, '$select=No') && !str_contains($decoded, '$filter')) {
        return ['value' => array_map(static fn ($r) => ['No' => $r['No']], array_values($bc))];
    }
    if (preg_match("/No ge '(\\d+)'/", $decoded, $m) === 1) {
        return ['value' => array_values(array_filter($bc, static fn ($r) => $r['No'] >= $m[1]))];
    }
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
$deltaCalls = array_values(array_filter($calls, static fn ($u) => str_contains($u, 'Last_Date_Modified ge')));
dt_same(str_contains(end($deltaCalls), 'Blocked'), false, 'delta-filter alleen op wijzigingsveld');
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

// Verwijderd in BC: count-controle ziet het verschil, sleutelcontrole wist de rij (geen volledige fetch).
unset($bc['3']);
$d = mimir_query_entity($pdo, $job, $fetch, $now + 9000);
dt_same($d['meta']['delta_mode'], 'delta', 'verwijdering via sleutelcontrole, nog steeds delta');
dt_same(array_column($d['value'], 'No'), ['1'], 'verwijderde rij weg');
dt_same(count(array_filter($calls, static fn ($u) => str_contains($u, '$select=No') && !str_contains($u, '$filter'))), 1, 'sleutels één keer opgehaald');

// Count onbekend -> sleutelcontrole; klopt het daarna, dan delta.
$GLOBALS['dt_count_unknown'] = true;
$bc['4'] = ['No' => '4', 'Description' => 'Vier', 'Blocked' => false, 'Last_Date_Modified' => 'NIEUW'];
$g = mimir_query_entity($pdo, $job, $fetch, $now + 9500);
dt_same($g['meta']['delta_mode'], 'full', 'count onbekend blijft onbewezen -> volledig');
$GLOBALS['dt_count_unknown'] = false;

// Gesorteerd op sleutel.
$bc['0'] = ['No' => '0', 'Description' => 'Nul', 'Blocked' => false, 'Last_Date_Modified' => 'NIEUW'];
$h = mimir_query_entity($pdo, $job, $fetch, $now + 9800);
dt_same($h['meta']['delta_mode'], 'delta', 'delta na nieuwe rij');
dt_same(array_column($h['value'], 'No'), ['0', '1', '4'], 'gesorteerd op sleutel');

// Vangnet: na MIMIR_DELTA_FULL_REFRESH toch volledig.
$i = mimir_query_entity($pdo, $job, $fetch, $now + 9800 + MIMIR_DELTA_FULL_REFRESH);
dt_same($i['meta']['delta_mode'], 'full', 'vangnet volledig');

// Na invalidatie (write) geen delta op lege cache.
mimir_cache_invalidate_rows($pdo, 'env', 'KVT', 'AppItems');
$e = mimir_query_entity($pdo, $job, $fetch, $now + 7300 + MIMIR_DELTA_FULL_REFRESH);
dt_same($e['meta']['delta_mode'], 'full', 'na invalidatie volledig');

// top > 0 -> nooit delta.
$f = mimir_query_entity($pdo, ['top' => 10] + $job, $fetch, $now + 9000 + MIMIR_DELTA_FULL_REFRESH);
dt_same(str_starts_with((string) ($f['meta']['delta_mode'] ?? 'full (top)'), 'full'), true, 'top -> volledig');

// Weekendverversing in blokken.
$rp = mimir_db(':memory:');
$big = [];
for ($n = 100; $n < 1100; $n++) {
    $big[(string) $n] = ['No' => (string) $n, 'Description' => 'x', 'Blocked' => false, 'Last_Date_Modified' => '2026-01-01'];
}
$bc = $big;
$calls = [];
$rjob = ['environment' => 'env', 'company' => 'KVT', 'entity' => 'AppItems', 'prefix' => 'https://bc.test/ODataV4/', 'keys' => ['No'], 'types' => $job['schema']['properties']];
mimir_meta_put($rp, 'delta:KVT|AppItems|abc', ['full_at' => 1, 'last_at' => 1, 'filter_sig' => '', 'select_sig' => '*'], $now);
mimir_cache_upsert($rp, 'env', 'KVT', 'AppItems', 'No="weg"', ['No' => 'weg'], 1);
$wk = $now;
dt_same(mimir_rolling_refresh($rp, $rjob, $fetch, 0, $wk, $wk), 'blok 0 van 47', 'slot 0');
dt_same(mimir_cache_all($rp, 'env', 'KVT', 'AppItems') !== [] && !in_array('weg', array_column(array_column(mimir_cache_all($rp, 'env', 'KVT', 'AppItems'), 'payload'), 'No'), true), true, 'verdwenen sleutel gewist');
dt_same(str_contains(end($calls), "No lt '"), true, 'blok 0 alleen bovengrens');
dt_same(mimir_rolling_refresh($rp, $rjob, $fetch, 0, $wk, $wk + 60), 'blok 0 van 47', 'zelfde slot nog eens: niets');
dt_same(mimir_rolling_refresh($rp, $rjob, $fetch, 10, $wk, $wk + 36000), 'blok 6 van 47', 'inhalen max 6 per run');
for ($t = 1; $t < 10; $t++) {
    mimir_rolling_refresh($rp, $rjob, $fetch, 47, $wk, $wk + 40000 + $t);
}
dt_same(mimir_meta_get_raw($rp, 'delta:KVT|AppItems|abc')['full_at'], $wk, 'na laatste blok telt ronde als volledige fetch');
dt_same(count(mimir_cache_all($rp, 'env', 'KVT', 'AppItems')), 1000, 'alle rijen ververst');
// FlowField-tabel met whitelist: delta werkt end-to-end en de cache houdt geen oude FlowField-waarde.
$GLOBALS['mimir_delta_normal_fields'] = ['AppItems' => ['No', 'Description', 'Blocked', 'Last_Date_Modified']];
$fp = mimir_db(':memory:');
$bc = ['1' => ['No' => '1', 'Description' => 'A', 'Blocked' => false, 'Last_Date_Modified' => '2026-01-01', 'Inventory' => 5, 'Date_Filter' => '']];
$fjob = $job;
$fjob['schema']['properties'] += ['Inventory' => 'Edm.Decimal', 'Date_Filter' => 'Edm.String'];
mimir_cache_upsert($fp, 'env', 'KVT', 'AppItems', 'No="1"', $bc['1'], $now - 100);
dt_same(mimir_query_entity($fp, $fjob, $fetch, $now)['meta']['delta_mode'], 'full', 'eerst volledig');
$bc['1']['Description'] = 'B';
$bc['1']['Last_Date_Modified'] = 'NIEUW';
$fr = mimir_query_entity($fp, $fjob, $fetch, $now + 3600);
dt_same($fr['meta']['delta_mode'], 'delta', 'FlowField-tabel met whitelist-select -> delta');
dt_same($fr['value'][0]['Description'], 'B', 'gewijzigde waarde');
dt_same(array_key_exists('Inventory', mimir_cache_all($fp, 'env', 'KVT', 'AppItems')[0]['payload']), false, 'oude FlowField-waarde niet meer in cache');
$fn = mimir_query_entity($fp, ['select' => []] + $fjob, $fetch, $now + 7200);
dt_same($fn['meta']['delta_mode'], 'full (flowfields (geen select))', 'geen select -> volledig');
$GLOBALS['mimir_delta_normal_fields'] = [];

// Volledig ophalen vervangt de rij: geen oude veldwaarde met een nieuwe tijd.
$wp = mimir_db(':memory:');
mimir_cache_upsert($wp, 'env', 'KVT', 'AppItems', 'No="1"', ['No' => '1', 'Description' => 'oud', 'Inventory' => 5, 'Blocked' => false], $now - 100);
mimir_coverage_put($wp, 'env', 'KVT', 'AppItems', '', '*', $now - 100, 1);
mimir_cache_upsert_many($wp, 'env', 'KVT', 'AppItems', [['No="1"', ['No' => '1', 'Description' => 'nieuw']]], $now, []);
dt_same(mimir_cache_all($wp, 'env', 'KVT', 'AppItems')[0]['payload'], ['No' => '1', 'Description' => 'nieuw'], 'rij volledig vervangen');
$GLOBALS['mimir_delta_normal_fields'] = ['AppItems' => ['No', 'Description', 'Blocked']];
mimir_cache_upsert_many($wp, 'env', 'KVT', 'AppItems', [['No="1"', ['No' => '1', 'Inventory' => 7]]], $now, mimir_cache_kept_columns('AppItems'));
dt_same(mimir_cache_all($wp, 'env', 'KVT', 'AppItems')[0]['payload'], ['No' => '1', 'Description' => 'nieuw', 'Inventory' => 7], 'alleen whitelist-velden blijven staan');
mimir_coverage_drop_wider($wp, 'env', 'KVT', 'AppItems', ['No'], ['Description']);
dt_same((int) $wp->query('SELECT COUNT(*) FROM cache_coverage')->fetchColumn(), 0, 'bredere dekking vervalt');
$GLOBALS['mimir_delta_normal_fields'] = [];

dt_same(mimir_rolling_refresh($rp, ['keys' => ['A', 'B']] + $rjob, $fetch, 0, $wk, $wk), 'overgeslagen (sleutel)', 'samengestelde sleutel overslaan');

echo "OK\n";
