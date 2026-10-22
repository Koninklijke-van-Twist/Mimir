<?php

declare(strict_types=1);

/**
 * Taalonafhankelijke kolomkoppeling tunnel-captions -> OData-velden.
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

$root = sys_get_temp_dir() . '/mimir-tunnel-columns-' . getmypid();
if (!is_dir($root) && !mkdir($root, 0777, true) && !is_dir($root)) {
    mimir_tc_fail('temp dir');
}
$GLOBALS['mimir_runtime_dir'] = $root;
$clock = 1_700_000_000;
$GLOBALS['mimir_tunnel_now'] = static function () use (&$clock): int {
    return $clock;
};

// --- Waarden normaliseren -------------------------------------------------
mimir_tc_same(mimir_tunnel_parse_number('1.234,50'), 1234.5, 'NL thousands + decimal comma');
mimir_tc_same(mimir_tunnel_parse_number('1,234.50'), 1234.5, 'EN thousands + decimal point');
mimir_tc_same(mimir_tunnel_parse_number('-12,5'), -12.5, 'negative decimal comma');
mimir_tc_same(mimir_tunnel_parse_number('abc'), null, 'text is no number');
mimir_tc_same(mimir_tunnel_parse_date('22-10-2026'), '2026-10-22', 'd-m-Y');
mimir_tc_same(mimir_tunnel_parse_date('2026-10-22T08:00:00Z'), '2026-10-22', 'ISO datetime');
mimir_tc_same(mimir_tunnel_parse_date('31.12.26'), '2026-12-31', 'short year with dots');
mimir_tc_same(mimir_tunnel_norm_value('Ja', 'Edm.Boolean'), mimir_tunnel_norm_value(true, 'Edm.Boolean'), 'Ja == true');
mimir_tc_same(mimir_tunnel_norm_value('1.234,50', 'Edm.Decimal'), mimir_tunnel_norm_value(1234.5, 'Edm.Decimal'), '1.234,50 == 1234.5');
mimir_tc_same(mimir_tunnel_norm_value('0', 'Edm.Decimal'), mimir_tunnel_norm_value(0, 'Edm.Decimal'), '0 == 0');

// --- Kern: Nederlandse + gemengde captions -------------------------------
$types = [
    'No' => 'Edm.String',
    'Name' => 'Edm.String',
    'Name_2' => 'Edm.String',
    'Post_Code' => 'Edm.String',
    'Balance_LCY' => 'Edm.Decimal',
    'Blocked' => 'Edm.Boolean',
    'Last_Date_Modified' => 'Edm.Date',
    'Hidden_Field' => 'Edm.String',
];
$odata = [
    ['No' => 'K001', 'Name' => 'Pompen BV', 'Name_2' => '', 'Post_Code' => '1234AB', 'Balance_LCY' => 1234.5, 'Blocked' => false, 'Last_Date_Modified' => '2026-10-01', 'Hidden_Field' => 'x'],
    ['No' => 'K002', 'Name' => 'Kranen NV', 'Name_2' => 'Afd. Zuid', 'Post_Code' => '5678CD', 'Balance_LCY' => 0, 'Blocked' => true, 'Last_Date_Modified' => '2026-09-15', 'Hidden_Field' => 'y'],
    ['No' => 'K003', 'Name' => 'Motoren', 'Name_2' => '', 'Post_Code' => '1234AB', 'Balance_LCY' => -10, 'Blocked' => false, 'Last_Date_Modified' => '2026-10-01', 'Hidden_Field' => 'z'],
];
$tunnel = [
    ['Nr.' => 'K001', 'Naam' => 'Pompen BV', 'Name 2' => '', 'Post Code' => '1234AB', 'Saldo (LV)' => '1.234,50', 'Geblokkeerd' => 'Nee', 'Laatst gewijzigd' => '01-10-2026', 'Alleen tunnel' => 'q1'],
    ['Nr.' => 'K002', 'Naam' => 'Kranen NV', 'Name 2' => 'Afd. Zuid', 'Post Code' => '5678CD', 'Saldo (LV)' => '0,00', 'Geblokkeerd' => 'Ja', 'Laatst gewijzigd' => '15-09-2026', 'Alleen tunnel' => 'q2'],
    ['Nr.' => 'K003', 'Naam' => 'Motoren', 'Name 2' => '', 'Post Code' => '1234AB', 'Saldo (LV)' => '-10,00', 'Geblokkeerd' => 'Nee', 'Laatst gewijzigd' => '01-10-2026', 'Alleen tunnel' => 'q3'],
];
$captions = array_keys($tunnel[0]);
$learned = mimir_tunnel_learn_columns($captions, $tunnel, $odata, $types, ['No']);
mimir_tc_same($learned['map']['Nr.'] ?? null, 'No', 'Nr. -> No via values');
mimir_tc_same($learned['map']['Naam'] ?? null, 'Name', 'Naam -> Name via values');
mimir_tc_same($learned['map']['Name 2'] ?? null, 'Name_2', 'English Name 2 -> Name_2 by name');
mimir_tc_same($learned['map']['Post Code'] ?? null, 'Post_Code', 'English Post Code -> Post_Code by name');
mimir_tc_same($learned['map']['Saldo (LV)'] ?? null, 'Balance_LCY', 'NL decimal -> Balance_LCY');
mimir_tc_same($learned['map']['Geblokkeerd'] ?? null, 'Blocked', 'Ja/Nee -> Blocked');
mimir_tc_same($learned['map']['Laatst gewijzigd'] ?? null, 'Last_Date_Modified', 'NL date -> date field');
mimir_tc_same($learned['unmapped'], ['Alleen tunnel'], 'tunnel-only column stays unmapped');
mimir_tc_same(in_array('Hidden_Field', $learned['map'], true), false, 'OData-only field is not invented');
mimir_tc_same($learned['method']['Name 2'] ?? null, 'naam', 'name match is reported');
mimir_tc_same($learned['method']['Nr.'] ?? null, 'rij', 'row match is reported');

$record = mimir_tunnel_cells_to_record($tunnel[1], $types, $learned['map']);
mimir_tc_same($record['No'] ?? null, 'K002', 'record uses learned key');
mimir_tc_same($record['Name'] ?? null, 'Kranen NV', 'record uses learned name');
mimir_tc_same($record['Balance_LCY'] ?? null, 0.0, 'decimal coerced');
mimir_tc_same($record['Blocked'] ?? null, true, 'boolean coerced');
mimir_tc_same(array_key_exists('Hidden_Field', $record), false, 'missing field stays absent');

// --- Rijvolgorde anders: set-vergelijking --------------------------------
$learnedSet = mimir_tunnel_learn_columns($captions, array_reverse($tunnel), $odata, $types, ['No']);
mimir_tc_same($learnedSet['map']['Nr.'] ?? null, 'No', 'reversed rows: Nr. via value sets');
mimir_tc_same($learnedSet['map']['Naam'] ?? null, 'Name', 'reversed rows: Naam via value sets');

// --- Dubbele captions -----------------------------------------------------
$dupTypes = ['No' => 'Edm.String', 'Name' => 'Edm.String', 'Search_Name' => 'Edm.String'];
$dupOdata = [['No' => 'A', 'Name' => 'Pomp', 'Search_Name' => 'POMP'], ['No' => 'B', 'Name' => 'Kraan', 'Search_Name' => 'KRAAN']];
$dupTunnel = [['Nr.' => 'A', 'Naam' => 'Pomp', 'Naam#2' => 'POMP'], ['Nr.' => 'B', 'Naam' => 'Kraan', 'Naam#2' => 'KRAAN']];
// Waarden verschillen alleen in hoofdletters: strings vergelijken case-insensitive, dus volgorde beslist.
$dup = mimir_tunnel_learn_columns(['Nr.', 'Naam', 'Naam#2'], $dupTunnel, $dupOdata, $dupTypes, ['No']);
mimir_tc_same($dup['map']['Naam'] ?? null, 'Name', 'first duplicate -> first field');
mimir_tc_same($dup['map']['Naam#2'] ?? null, 'Search_Name', 'second duplicate -> second field');
$dupOdata2 = [['No' => 'A', 'Name' => 'Pomp', 'Search_Name' => 'Iets anders'], ['No' => 'B', 'Name' => 'Kraan', 'Search_Name' => 'Ook anders']];
$dupTunnel2 = [['Nr.' => 'A', 'Naam' => 'Iets anders', 'Naam#2' => 'Pomp'], ['Nr.' => 'B', 'Naam' => 'Ook anders', 'Naam#2' => 'Kraan']];
$dup2 = mimir_tunnel_learn_columns(['Nr.', 'Naam', 'Naam#2'], $dupTunnel2, $dupOdata2, $dupTypes, ['No']);
mimir_tc_same($dup2['map']['Naam'] ?? null, 'Search_Name', 'distinct values beat column order');
mimir_tc_same($dup2['map']['Naam#2'] ?? null, 'Name', 'distinct values beat column order (2)');

// --- Naam klopt, waarden spreken tegen ------------------------------------
$conTypes = ['No' => 'Edm.String', 'Description' => 'Edm.String', 'Description_2' => 'Edm.String'];
$conOdata = [['No' => 'A', 'Description' => 'Lang', 'Description_2' => 'Kort'], ['No' => 'B', 'Description' => 'Groot', 'Description_2' => 'Klein']];
$conTunnel = [['No.' => 'A', 'Description' => 'Kort'], ['No.' => 'B', 'Description' => 'Klein']];
$con = mimir_tunnel_learn_columns(['No.', 'Description'], $conTunnel, $conOdata, $conTypes, ['No']);
mimir_tc_same($con['map']['Description'] ?? null, 'Description_2', 'values override a contradicted name');

// --- Zonder steekproef: alleen naam (oude gedrag) -------------------------
$noSample = mimir_tunnel_learn_columns(['Nr.', 'Name 2'], $tunnel, [], $types, ['No']);
mimir_tc_same($noSample['map'], ['Name 2' => 'Name_2'], 'without a sample only names map');

// --- Steekproef-URL -------------------------------------------------------
mimir_tc_same(
    mimir_tunnel_sample_url("https://bc.test/env/ODataV4/Company('KVT')/ItemList?\$top=50&\$filter=No%20eq%20'A'", 'ItemList', 20),
    "https://bc.test/env/ODataV4/Company('KVT')/ItemList?\$top=20",
    'list url becomes an unfiltered sample'
);
mimir_tc_same(
    mimir_tunnel_sample_url("https://bc.test/env/ODataV4/Company('KVT')/ItemList(No='A')", 'ItemList', 3),
    "https://bc.test/env/ODataV4/Company('KVT')/ItemList?\$top=3",
    'card url becomes the collection'
);

// --- Integratie via mimir_query_entity ------------------------------------
$GLOBALS['mimir_tunnel_url'] = 'http://127.0.0.1:8787';
$GLOBALS['mimir_tunnel_token'] = 'tok';
$GLOBALS['mimir_tunnel_cooldown'] = 120;

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
$language = 'nl';
$tunnelCalls = [];
$GLOBALS['mimir_tunnel_transport'] = static function (string $method, string $url, array $headers) use (&$tunnelCalls, &$language, $services): array {
    unset($method, $headers);
    $tunnelCalls[] = $url;
    if (str_contains($url, '/page/810')) {
        return ['status' => 200, 'body' => json_encode($services)];
    }
    $rows = $language === 'nl'
        ? [['Nr.' => 'K001', 'Naam' => 'Pompen BV', 'Saldo (LV)' => '1.234,50'], ['Nr.' => 'K002', 'Naam' => 'Kranen NV', 'Saldo (LV)' => '0,00']]
        : [['No.' => 'K001', 'Name' => 'Pompen BV', 'Balance (LCY)' => '1,234.50'], ['No.' => 'K002', 'Name' => 'Kranen NV', 'Balance (LCY)' => '0.00']];
    if ($language === 'de') {
        $rows = [['Nr.' => 'K001', 'Name' => 'Pompen BV', 'Saldo (MW)' => '1.234,50'], ['Nr.' => 'K002', 'Name' => 'Kranen NV', 'Saldo (MW)' => '0,00']];
    }
    if (str_contains($url, 'filter=')) {
        $col = $language === 'en' ? 'No.' : 'Nr.';
        if (!str_contains($url, 'filter=' . rawurlencode($col . '='))) {
            return ['status' => 502, 'body' => '{"ok":false,"error":"Filter column not found","code":"BC_ERROR"}'];
        }
        $rows = [$rows[1]];
    }

    return ['status' => 200, 'body' => json_encode(['rows' => array_map(static fn (array $c): array => ['cells' => $c], $rows)])];
};
$odataRows = [
    ['No' => 'K001', 'Name' => 'Pompen BV', 'Balance_LCY' => 1234.5],
    ['No' => 'K002', 'Name' => 'Kranen NV', 'Balance_LCY' => 0],
];
$odataCalls = [];
$odata = static function (string $url) use (&$odataCalls, $odataRows): array {
    $odataCalls[] = $url;

    return ['value' => $odataRows];
};

mimir_tc_clear_circuit();
$pdo = mimir_db(':memory:');
$result = mimir_query_entity($pdo, mimir_tc_job($pdo), $odata, $clock);
mimir_tc_same($result['meta']['route'] ?? null, 'tunnel', 'Dutch captions are served by the tunnel');
mimir_tc_same($result['value'][0]['No'] ?? null, 'K001', 'Dutch Nr. becomes No');
mimir_tc_same($result['value'][1]['Name'] ?? null, 'Kranen NV', 'Dutch Naam becomes Name');
mimir_tc_same(count($odataCalls), 1, 'one OData sample to learn the mapping');
mimir_tc_same(str_contains($odataCalls[0], '$top=2'), true, 'sample is small ($top = tunnel rows): ' . $odataCalls[0]);
$entry = mimir_meta_get_raw($pdo, mimir_tunnel_cache_key('kvtmdlive_aad'))['services']['CustomerList'] ?? [];
mimir_tc_same($entry['columns']['map']['Saldo (LV)'] ?? null, 'Balance_LCY', 'mapping is cached with the page');
mimir_tc_same($entry['captions']['No'] ?? null, 'Nr.', 'reverse caption is stored for filters');

// Tweede verzoek: koppeling uit de cache, geen OData.
$odataCalls = [];
$result = mimir_query_entity($pdo, mimir_tc_job($pdo), $odata, $clock + 60);
mimir_tc_same(array_values(array_filter($odataCalls, static fn ($u): bool => !preg_match('/\?\$top=\d+$/', (string) $u))), [], 'cached mapping needs no OData (behalve eenmalige formaat-steekproef)');
mimir_tc_same($result['value'][0]['Balance_LCY'] ?? null, 1234.5, 'cached mapping coerces NL decimal');

// Filter op No gaat als "Nr." naar de tunnel.
$tunnelCalls = [];
$result = mimir_query_entity($pdo, mimir_tc_job($pdo, ['top' => 10, 'filter' => ['field' => 'No', 'op' => 'eq', 'value' => 'K002']]), $odata, $clock + 120);
mimir_tc_same(array_values(array_filter($odataCalls, static fn ($u): bool => !preg_match('/\?\$top=\d+$/', (string) $u))), [], 'filtered read stays on the tunnel (behalve eenmalige formaat-steekproef)');
mimir_tc_same($result['value'][0]['No'] ?? null, 'K002', 'filtered row is returned');
$usedNr = false;
foreach ($tunnelCalls as $call) {
    if (str_contains($call, 'filter=' . rawurlencode('Nr.=K002'))) {
        $usedNr = true;
    }
}
mimir_tc_same($usedNr, true, 'filter uses the learned Dutch caption');

// Taalwissel (onbekende captions): koppeling opnieuw geleerd.
$language = 'de';
$odataCalls = [];
$result = mimir_query_entity($pdo, mimir_tc_job($pdo), $odata, $clock + 180);
mimir_tc_same($result['meta']['route'] ?? null, 'tunnel', 'after a language switch the tunnel still serves');
mimir_tc_same($result['value'][0]['Balance_LCY'] ?? null, 1234.5, 'new caption Saldo (MW) is learned');
mimir_tc_same(count($odataCalls), 1, 'one new sample after the mismatch');

// Engels: alles op naam, geen steekproef nodig.
$language = 'en';
$enPdo = mimir_db(':memory:');
$odataCalls = [];
$result = mimir_query_entity($enPdo, mimir_tc_job($enPdo), $odata, $clock);
mimir_tc_same($result['value'][0]['Balance_LCY'] ?? null, 1234.5, 'English captions map by name');
mimir_tc_same(array_values(array_filter($odataCalls, static fn ($u): bool => !preg_match('/\?\$top=\d+$/', (string) $u))), [], 'English captions need no sample (behalve eenmalige formaat-steekproef)');

// Verlopen (24 uur): opnieuw geleerd.
$language = 'nl';
$odataCalls = [];
mimir_query_entity($pdo, mimir_tc_job($pdo), $odata, $clock + MIMIR_TUNNEL_COLUMNS_TTL + 600);
mimir_tc_same(count($odataCalls), 1, 'mapping is relearned after 24h');

// Sleutel niet te koppelen: hele verzoek via OData en gelogd.
$GLOBALS['mimir_tunnel_transport'] = static function (string $method, string $url, array $headers) use ($services): array {
    unset($method, $headers);
    if (str_contains($url, '/page/810')) {
        return ['status' => 200, 'body' => json_encode($services)];
    }

    return ['status' => 200, 'body' => json_encode(['rows' => [['cells' => ['Nr.' => 'ZZZ', 'Naam' => 'Onbekend']]]])];
};
mimir_tc_clear_circuit();
$badPdo = mimir_db(':memory:');
$odataCalls = [];
$result = mimir_query_entity($badPdo, mimir_tc_job($badPdo), $odata, $clock);
mimir_tc_same($result['value'][0]['No'] ?? null, 'K001', 'unmappable key falls back to OData rows');
mimir_tc_same(count($odataCalls) >= 2, true, 'sample plus the OData fallback read');
$log = is_file(mimir_event_log_path()) ? (string) file_get_contents(mimir_event_log_path()) : '';
mimir_tc_same(str_contains($log, 'Kolomkoppeling CustomerList'), true, 'missing key mapping is logged');

fwrite(STDOUT, "ok\n");
