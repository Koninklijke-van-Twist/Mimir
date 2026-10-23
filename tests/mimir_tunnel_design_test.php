<?php

declare(strict_types=1);

define('MIMIR_DEFAULT_ROUTE', ''); // automatische route testen

/**
 * DesignName-sleutels van de bridge (keyBy: designName) -> OData-velden via \$metadata.
 */

require_once dirname(__DIR__) . '/web/mimir_store.php';

function mimir_tc_fail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function mimir_tc_same(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) {
        mimir_tc_fail($message . ' expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }
}

$root = sys_get_temp_dir() . '/mimir-tunnel-design-' . getmypid();
if (!is_dir($root) && !mkdir($root, 0777, true) && !is_dir($root)) {
    mimir_tc_fail('temp dir');
}
$GLOBALS['mimir_runtime_dir'] = $root;
$clock = 1_700_000_000;
$GLOBALS['mimir_tunnel_now'] = static function () use (&$clock): int {
    return $clock;
};


$GLOBALS['mimir_tunnel_url'] = 'http://127.0.0.1:8787';
$GLOBALS['mimir_tunnel_token'] = 'tok';
$GLOBALS['mimir_tunnel_cooldown'] = 120;

// --- Normalisatie DesignName -> OData-naam --------------------------------
mimir_tc_same(mimir_tunnel_design_norm('No.'), 'no', 'No. -> no');
mimir_tc_same(mimir_tunnel_design_norm('Balance (LCY)'), 'balance__lcy', 'Balance (LCY) -> balance__lcy');
mimir_tc_same(mimir_tunnel_design_norm('Post_Code'), 'post_code', 'OData-naam blijft gelijk');
$types = ['No' => 'Edm.String', 'Name' => 'Edm.String', 'Balance_LCY' => 'Edm.Decimal', 'Post_Code' => 'Edm.String', 'E_Mail' => 'Edm.String'];
mimir_tc_same(
    mimir_tunnel_design_columns(['No.', 'name', 'Balance (LCY)', 'Post Code', 'E-Mail', 'Onbekend', 'Name#2'], $types),
    ['No.' => 'No', 'name' => 'Name', 'Post Code' => 'Post_Code', 'E-Mail' => 'E_Mail', 'Balance (LCY)' => 'Balance_LCY'],
    'DesignNames koppelen case-insensitive; samengevoegde _ als tweede ronde; #n en onbekend niet'
);
mimir_tc_same(mimir_tunnel_body_is_design_keyed(['rows' => []]), false, 'oude bridge (geen keyBy) = captions');
mimir_tc_same(mimir_tunnel_body_is_design_keyed(['keyBy' => 'designName']), true, 'keyBy designName herkend');
mimir_tc_same(
    mimir_tunnel_effective_columns(['columns' => ['map' => ['Saldo' => 'Balance_LCY', 'No' => 'Name']]], ['keyBy' => 'designName', 'rows' => [['cells' => ['No' => 'x', 'Saldo' => '1']]]], $types),
    ['No' => 'No', 'Saldo' => 'Balance_LCY'],
    'metadata-koppeling wint, geleerde koppeling vult aan zonder dubbel veld'
);
mimir_tc_same(
    mimir_tunnel_effective_columns(['columns' => ['map' => ['Nr.' => 'No']]], ['rows' => [['cells' => ['Nr.' => 'x']]]], $types),
    ['Nr.' => 'No'],
    'zonder keyBy alleen de geleerde koppeling (oude gedrag)'
);

function mimir_tc_job(PDO $pdo, array $overrides = []): array
{
    return array_merge([
        'environment' => 'kvtmdlive_aad',
        'company' => 'KVT',
        'entity' => 'CustomerList',
        'service_prefix' => 'https://bc.test/env/ODataV4/',
        'select' => ['No', 'Name', 'Balance_LCY'],
        'filter' => null,
        'max_age' => 0,
        'top' => 0,
        'key_id' => 7,
        'meta_pdo' => $pdo,
        'schema' => ['keys' => ['No'], 'properties' => ['No' => 'Edm.String', 'Name' => 'Edm.String', 'Balance_LCY' => 'Edm.Decimal']],
    ], $overrides);
}

function mimir_tc_clear_circuit(): void
{
    $path = mimir_tunnel_circuit_path();
    if (is_file($path)) {
        unlink($path);
    }
}


$services = ['rows' => [['cells' => ['Servicenaam' => 'CustomerList', 'Object-id' => '22', 'Objectsoort' => 'Pagina', 'Gepubliceerd' => 'Ja']]]];
$tunnelCalls = [];
$designRows = [['No' => 'K001', 'Name' => 'Pompen BV', 'Balance (LCY)' => '1.234,50'], ['No' => 'K002', 'Name' => 'Kranen NV', 'Balance (LCY)' => '0,00']];
$GLOBALS['mimir_tunnel_transport'] = static function (string $method, string $url, array $headers) use (&$tunnelCalls, $services, &$designRows): array {
    unset($method, $headers);
    $tunnelCalls[] = $url;
    if (str_contains($url, '/page/810')) {
        return ['status' => 200, 'body' => json_encode($services)];
    }
    $design = str_contains($url, 'keyBy=designName');
    $rows = $design
        ? $designRows
        : [['Nr.' => 'K001', 'Naam' => 'Pompen BV', 'Saldo (LV)' => '1.234,50'], ['Nr.' => 'K002', 'Naam' => 'Kranen NV', 'Saldo (LV)' => '0,00']];
    if (str_contains($url, 'filter=')) {
        if (!str_contains($url, 'filter=' . rawurlencode('No=')) && !str_contains($url, 'filter=' . rawurlencode('Nr.='))) {
            return ['status' => 502, 'body' => '{"ok":false,"error":"Filter column not found","code":"BC_ERROR"}'];
        }
        $rows = [$rows[1]];
    }
    $body = ['rows' => array_map(static fn (array $c): array => ['cells' => $c], $rows)];
    if ($design) {
        $body['keyBy'] = 'designName';
    }

    return ['status' => 200, 'body' => json_encode($body)];
};
$odataCalls = [];
$odata = static function (string $url) use (&$odataCalls): array {
    $odataCalls[] = $url;

    return ['value' => [
        ['No' => 'K001', 'Name' => 'Pompen BV', 'Balance_LCY' => 1234.5],
        ['No' => 'K002', 'Name' => 'Kranen NV', 'Balance_LCY' => 0],
    ]];
};

mimir_tc_clear_circuit();
$pdo = mimir_db(':memory:');
$result = mimir_query_entity($pdo, mimir_tc_job($pdo), $odata, $clock);
mimir_tc_same($result['meta']['route'] ?? null, 'tunnel', 'DesignName-antwoord gaat via de tunnel');
mimir_tc_same($result['meta']['tunnel_key_by'] ?? null, 'designName', 'meta.tunnel_key_by = designName');
mimir_tc_same($result['value'][0]['Balance_LCY'] ?? null, 1234.5, 'Balance (LCY) -> Balance_LCY via $metadata');
mimir_tc_same($result['value'][1]['No'] ?? null, 'K002', 'No -> No');
mimir_tc_same(array_values(array_filter($odataCalls, static fn ($u): bool => !preg_match('/\?\$top=\d+$/', (string) $u))), [], 'geen OData-steekproef nodig met DesignNames (behalve eenmalige formaat-steekproef)');
$asked = false;
foreach ($tunnelCalls as $call) {
    if (str_contains($call, '/page/22') && str_contains($call, 'keyBy=designName')) {
        $asked = true;
    }
    if (str_contains($call, '/page/810') && str_contains($call, 'keyBy=')) {
        mimir_tc_fail('page 810 (webservices) blijft op captions');
    }
}
mimir_tc_same($asked, true, 'Mímir vraagt de bridge om keyBy=designName');
$entry = mimir_meta_get_raw($pdo, mimir_tunnel_cache_key('kvtmdlive_aad'))['services']['CustomerList'] ?? [];
mimir_tc_same($entry['columns']['key_by'] ?? null, 'designName', 'koppeling uit metadata wordt bewaard');
mimir_tc_same($entry['captions']['No'] ?? null, 'No', 'filter gebruikt de DesignName');

$tunnelCalls = [];
$result = mimir_query_entity($pdo, mimir_tc_job($pdo, ['top' => 10, 'filter' => ['field' => 'No', 'op' => 'eq', 'value' => 'K002']]), $odata, $clock + 60);
mimir_tc_same($result['value'][0]['Name'] ?? null, 'Kranen NV', 'gefilterde rij via DesignName-filter');
mimir_tc_same(array_values(array_filter($odataCalls, static fn ($u): bool => !preg_match('/\?\$top=\d+$/', (string) $u))), [], 'gefilterd lezen zonder OData (behalve eenmalige formaat-steekproef)');

// DesignName die niet in $metadata staat (en geen naam lijkt): terugval op waardevergelijking.
$designRows = [['No' => 'K001', 'Name' => 'Pompen BV', 'BalanceLCYCtrl' => '1.234,50'], ['No' => 'K002', 'Name' => 'Kranen NV', 'BalanceLCYCtrl' => '0,00']];
mimir_tc_clear_circuit();
unset($GLOBALS['mimir_tunnel_columns_learned']);
$pdo2 = mimir_db(':memory:');
$odataCalls = [];
$result = mimir_query_entity($pdo2, mimir_tc_job($pdo2), $odata, $clock);
mimir_tc_same($result['value'][0]['Balance_LCY'] ?? null, 1234.5, 'onbekende DesignName via waardevergelijking gekoppeld');
mimir_tc_same(count($odataCalls), 1, 'één OData-steekproef als terugval');

fwrite(STDOUT, "ok\n");
