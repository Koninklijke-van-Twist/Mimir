<?php

declare(strict_types=1);

/**
 * Tunnelwaarden -> exact OData-formaat (Date/Time/DateTime), taalonafhankelijk,
 * en het profiel dat onbetrouwbare (verborgen/lege) tunnelkolommen aanwijst.
 */

require_once dirname(__DIR__) . '/web/mimir_store.php';

function mimir_tv_same(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: {$message} expected " . var_export($expected, true) . ' got ' . var_export($actual, true) . "\n");
        exit(1);
    }
}

$GLOBALS['mimir_tunnel_timezone'] = 'Europe/Amsterdam';

// Datums: NL D-M-YYYY, EN M/D/YYYY, ISO, leeg = BC-nul.
mimir_tv_same(mimir_tunnel_to_odata_date('20-7-2026', 'dmy'), '2026-07-20', 'NL datum');
mimir_tv_same(mimir_tunnel_to_odata_date('7/20/2026', 'mdy'), '2026-07-20', 'EN datum');
mimir_tv_same(mimir_tunnel_to_odata_date('20-7-2026', null), '2026-07-20', 'dag>12 eenduidig zonder volgorde');
mimir_tv_same(mimir_tunnel_to_odata_date('3-4-2026', null), null, 'dubbelzinnig zonder volgorde = null');
mimir_tv_same(mimir_tunnel_to_odata_date('3-4-2026', 'dmy'), '2026-04-03', 'dubbelzinnig met NL-volgorde');
mimir_tv_same(mimir_tunnel_to_odata_date('2026-07-20', null), '2026-07-20', 'ISO blijft');
mimir_tv_same(mimir_tunnel_to_odata_date('', 'dmy'), '0001-01-01', 'leeg = BC-nul');

// Tijden.
mimir_tv_same(mimir_tunnel_to_odata_time('8:00'), '08:00:00', 'H:MM');
mimir_tv_same(mimir_tunnel_to_odata_time('2:05:09 PM'), '14:05:09', 'EN PM');
mimir_tv_same(mimir_tunnel_to_odata_time('12:00 AM'), '00:00:00', 'EN middernacht');

// DateTime: lokale weergave -> UTC ISO zoals OData.
mimir_tv_same(mimir_tunnel_to_odata_datetime('20-7-2026 10:00', 'dmy'), '2026-07-20T08:00:00Z', 'NL zomertijd -> UTC');
mimir_tv_same(mimir_tunnel_to_odata_datetime('1/20/2026 10:00 AM', 'mdy'), '2026-01-20T09:00:00Z', 'EN wintertijd -> UTC');
mimir_tv_same(mimir_tunnel_to_odata_datetime('2026-07-20T08:00:00Z', null), '2026-07-20T08:00:00Z', 'ISO blijft');

// Volgorde afleiden uit het antwoord zelf (metadata-type, geen stringpatroon per veld).
$types = ['No' => 'Edm.String', 'Start_Date' => 'Edm.Date', 'Description' => 'Edm.String'];
mimir_tv_same(mimir_tunnel_infer_date_order([['Start_Date' => '20-7-2026'], ['Start_Date' => '3-4-2026']], $types), 'dmy', 'NL afgeleid');
mimir_tv_same(mimir_tunnel_infer_date_order([['Start_Date' => '7/20/2026']], $types), 'mdy', 'EN afgeleid');
mimir_tv_same(mimir_tunnel_output_value('Description', '20-7-2026', 'Edm.String', null, 'dmy'), '20-7-2026', 'tekstveld blijft tekst');

// Volledige tunnelrecords -> OData-vorm.
$body = ['rows' => [['cells' => ['No.' => 'WO1', 'Startdatum' => '20-7-2026']], ['cells' => ['No.' => 'WO2', 'Startdatum' => '3-4-2026']]]];
$records = mimir_tunnel_records($body, $types, ['No.' => 'No', 'Startdatum' => 'Start_Date']);
mimir_tv_same($records[0]['Start_Date'] ?? null, '2026-07-20', 'record 1');
mimir_tv_same($records[1]['Start_Date'] ?? null, '2026-04-03', 'record 2 met afgeleide NL-volgorde');

// Profiel: verborgen kolom (leeg in tunnel, gevuld in OData) en tijd-in-tekstveld.
$types2 = ['No' => 'Edm.String', 'Start_Date' => 'Edm.Date', 'Start_Time' => 'Edm.String', 'Created_On' => 'Edm.Date'];
$map = ['No.' => 'No', 'Startdatum' => 'Start_Date', 'Begintijd' => 'Start_Time'];
$tunnelRows = [['No.' => 'WO1', 'Startdatum' => '20-7-2026', 'Begintijd' => '']];
$odataRows = [['No' => 'WO1', 'Start_Date' => '2026-07-20', 'Start_Time' => '08:00:00', 'Created_On' => '2026-03-09']];
$profile = mimir_tunnel_build_profile($map, $tunnelRows, $odataRows, $types2, ['No']);
mimir_tv_same($profile['date_order'] ?? null, 'dmy', 'profiel volgorde');
mimir_tv_same(in_array('Start_Time', $profile['time_fields'] ?? [], true), true, 'Start_Time is tijdveld');
mimir_tv_same(isset($profile['unreliable']['Start_Time']), true, 'lege tijd = onbetrouwbaar');
mimir_tv_same(isset($profile['unreliable']['Created_On']), true, 'ontbrekend veld = onbetrouwbaar');
mimir_tv_same(isset($profile['unreliable']['Start_Date']), false, 'datum betrouwbaar');
mimir_tv_same(array_keys(mimir_tunnel_unreliable_requested($profile, ['No', 'Start_Date'], $types2)), [], '$select zonder probleemvelden = tunnel');
mimir_tv_same(array_key_exists('Start_Time', mimir_tunnel_unreliable_requested($profile, [], $types2)), true, 'alles gevraagd = OData-terugval');

echo "OK mimir_tunnel_values_test\n";
