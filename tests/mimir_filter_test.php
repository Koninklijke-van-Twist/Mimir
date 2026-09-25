<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/web/mimir_filter.php';
require_once dirname(__DIR__) . '/web/odata.php';

function mimir_test_fail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function mimir_test_same(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) {
        mimir_test_fail($message . ' expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }
}

$types = [
    'No' => 'Edm.String',
    'Description' => 'Edm.String',
    'Inventory' => 'Edm.Decimal',
    'Blocked' => 'Edm.Boolean',
];

$eq = mimir_filter_plan(['field' => 'No', 'op' => 'eq', 'value' => "O'Brien"], $types);
mimir_test_same($eq['mode'], 'bc', 'string eq is pushed');
mimir_test_same($eq['odata'], "No eq 'O''Brien'", 'quotes are doubled');

$and = mimir_filter_plan([
    'and' => [
        ['field' => 'No', 'op' => 'eq', 'value' => 'A'],
        ['field' => 'Inventory', 'op' => 'gt', 'value' => 5],
    ],
], $types);
mimir_test_same($and['mode'], 'bc', 'and of leaves is pushed');
mimir_test_same($and['odata'], "(No eq 'A') and (Inventory gt 5)", 'and odata');

$sameOr = mimir_filter_plan([
    'or' => [
        ['field' => 'No', 'op' => 'eq', 'value' => 'A'],
        ['field' => 'No', 'op' => 'eq', 'value' => 'B'],
    ],
], $types);
mimir_test_same($sameOr['mode'], 'bc', 'or on one field is pushed');
mimir_test_same($sameOr['odata'], "(No eq 'A') or (No eq 'B')", 'same-field or');

$crossOr = mimir_filter_plan([
    'or' => [
        ['field' => 'No', 'op' => 'eq', 'value' => 'A'],
        ['field' => 'Description', 'op' => 'contains', 'value' => 'pomp'],
    ],
], $types);
mimir_test_same($crossOr['mode'], 'local', 'or across fields stays local');
mimir_test_same($crossOr['odata'], null, 'cross-field or has no $filter');

$xor = mimir_filter_plan([
    'xor' => [
        ['field' => 'Blocked', 'op' => 'eq', 'value' => true],
        ['field' => 'No', 'op' => 'eq', 'value' => 'A'],
    ],
], $types);
mimir_test_same($xor['mode'], 'local', 'xor stays local');
mimir_test_same($xor['odata'], null, 'xor has no $filter');

$mixedFilter = [
    'and' => [
        ['field' => 'No', 'op' => 'eq', 'value' => 'A'],
        ['or' => [
            ['field' => 'Description', 'op' => 'contains', 'value' => 'x'],
            ['field' => 'Inventory', 'op' => 'gt', 'value' => 1],
        ]],
    ],
];
$mixed = mimir_filter_plan($mixedFilter, $types);
mimir_test_same($mixed['mode'], 'mixed', 'mandatory and is pushed beside a cross-field or');
mimir_test_same($mixed['odata'], "(No eq 'A')", 'mixed keeps the safe leaf');

$contains = mimir_filter_plan(['field' => 'Description', 'op' => 'contains', 'value' => 'abc'], $types);
mimir_test_same($contains['odata'], "contains(Description,'abc')", 'contains function');

$bool = mimir_filter_plan(['field' => 'Blocked', 'op' => 'eq', 'value' => false], $types);
mimir_test_same($bool['odata'], 'Blocked eq false', 'boolean literal');

mimir_test_same(mimir_filter_validate(['field' => 'No;drop', 'op' => 'eq', 'value' => '1']), 'Veldnaam is ongeldig.', 'unsafe field');
mimir_test_same(mimir_filter_validate(['and' => [], 'or' => []]), 'Een filterknoop is een groep (and/or/xor) of een voorwaarde, niet allebei.', 'mixed group');

$row = ['No' => 'A', 'Description' => 'xpomp', 'Inventory' => 2, 'Blocked' => false];
mimir_test_same(mimir_filter_match($row, $mixedFilter, $types), true, 'mixed local match via description');
mimir_test_same(mimir_filter_match(['No' => 'A', 'Description' => 'nee', 'Inventory' => 0], $mixedFilter, $types), false, 'mixed local miss');
mimir_test_same(mimir_filter_match($row, ['xor' => [
    ['field' => 'Blocked', 'op' => 'eq', 'value' => false],
    ['field' => 'No', 'op' => 'eq', 'value' => 'A'],
]], $types), false, 'xor of two trues is false');
mimir_test_same(mimir_filter_match($row, ['xor' => [
    ['field' => 'Blocked', 'op' => 'eq', 'value' => true],
    ['field' => 'No', 'op' => 'eq', 'value' => 'A'],
]], $types), true, 'xor of one true is true');
mimir_test_same(mimir_filter_match(['No' => '00123'], ['field' => 'No', 'op' => 'eq', 'value' => '123'], ['No' => 'Edm.String']), false, 'string codes are not numeric');

$xml = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<edmx:Edmx Version="4.0" xmlns:edmx="http://docs.oasis-open.org/odata/ns/edmx">
  <edmx:DataServices>
    <Schema Namespace="Microsoft.NAV" xmlns="http://docs.oasis-open.org/odata/ns/edm">
      <EntityType Name="Item">
        <Key><PropertyRef Name="No"/></Key>
        <Property Name="No" Type="Edm.String"/>
        <Property Name="Inventory" Type="Edm.Decimal"/>
      </EntityType>
      <EntityContainer Name="NAV">
        <EntitySet Name="ItemList" EntityType="Microsoft.NAV.Item"/>
      </EntityContainer>
    </Schema>
  </edmx:DataServices>
</edmx:Edmx>
XML;
$parsed = odata_parse_metadata($xml);
$schema = mimir_schema_for_set($parsed, 'ItemList');
mimir_test_same($schema['keys'], ['No'], 'metadata key');
mimir_test_same($schema['properties']['Inventory'], 'Edm.Decimal', 'metadata property');

fwrite(STDOUT, "ok\n");
