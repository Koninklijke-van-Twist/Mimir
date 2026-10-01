<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/web/odata.php';
require_once dirname(__DIR__) . '/web/mimir_filter.php';

function mimir_alias_fail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function mimir_alias_same(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) {
        mimir_alias_fail($message . ' expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }
}

function mimir_alias_metadata(bool $includeBaseline, bool $includeCaption): array
{
    $sets = '<EntitySet Name="ItemList" EntityType="Microsoft.NAV.Item"/>';
    $types = <<<'XML'
      <EntityType Name="Item">
        <Key><PropertyRef Name="No"/></Key>
        <Property Name="No" Type="Edm.String"/>
      </EntityType>
XML;
    if ($includeBaseline) {
        $sets .= '<EntitySet Name="JobBaselineLines" EntityType="Microsoft.NAV.JobBaselineLine"/>';
        $types .= <<<'XML'
      <EntityType Name="JobBaselineLine">
        <Key><PropertyRef Name="Job_No"/><PropertyRef Name="Line_No"/></Key>
        <Property Name="Job_No" Type="Edm.String"/>
        <Property Name="Line_No" Type="Edm.Int32"/>
        <Property Name="Quantity" Type="Edm.Decimal"/>
      </EntityType>
XML;
    }
    if ($includeCaption) {
        $sets .= '<EntitySet Name="Projectbasislijnregel" EntityType="Microsoft.NAV.JobBaselineLine"/>';
    }
    $xml = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<edmx:Edmx Version="4.0" xmlns:edmx="http://docs.oasis-open.org/odata/ns/edmx">
  <edmx:DataServices>
    <Schema Namespace="Microsoft.NAV" xmlns="http://docs.oasis-open.org/odata/ns/edm">
      {$types}
      <EntityContainer Name="NAV">
        {$sets}
      </EntityContainer>
    </Schema>
  </edmx:DataServices>
</edmx:Edmx>
XML;

    return odata_parse_metadata($xml);
}

$published = mimir_alias_metadata(true, false);

$fromAl = mimir_resolve_entity_schema($published, 'LVS_JobChngeOrderBudgetLne');
mimir_alias_same($fromAl['name'], 'JobBaselineLines', 'AL-naam wijst naar JobBaselineLines');
mimir_alias_same($fromAl['requested_name'], 'LVS_JobChngeOrderBudgetLne', 'gevraagde AL-naam blijft bewaard');
mimir_alias_same($fromAl['properties']['Job_No'], 'Edm.String', 'alias gebruikt het schema van de webservice');

$fromCaption = mimir_resolve_entity_schema($published, 'Projectbasislijnregel');
mimir_alias_same($fromCaption['name'], 'JobBaselineLines', 'caption wijst naar JobBaselineLines');

$fromFull = mimir_resolve_entity_schema($published, 'LVS_JobChangeOrderBudgetLine');
mimir_alias_same($fromFull['name'], 'JobBaselineLines', 'volledige AL-spelling wijst naar JobBaselineLines');

$direct = mimir_resolve_entity_schema($published, 'jobbaselinelines');
mimir_alias_same($direct['name'], 'JobBaselineLines', 'bestaande set wint, canonical naam');
mimir_alias_same($direct['requested_name'], 'JobBaselineLines', 'geen alias als de set zelf bestaat');

$both = mimir_alias_metadata(true, true);
$captionWins = mimir_resolve_entity_schema($both, 'Projectbasislijnregel');
mimir_alias_same($captionWins['name'], 'Projectbasislijnregel', 'gepubliceerde caption wordt niet herschreven');

$missing = mimir_alias_metadata(false, false);
try {
    mimir_resolve_entity_schema($missing, 'LVS_JobChngeOrderBudgetLne');
    mimir_alias_fail('ontbrekende JobBaselineLines moet 404 blijven');
} catch (RuntimeException $error) {
    mimir_alias_same($error->getMessage(), 'Onbekende tabel: LVS_JobChngeOrderBudgetLne', '404 noemt de gevraagde naam');
}

try {
    mimir_resolve_entity_schema($published, 'NietBestaand');
    mimir_alias_fail('onbekende tabel zonder alias moet falen');
} catch (RuntimeException $error) {
    mimir_alias_same($error->getMessage(), 'Onbekende tabel: NietBestaand', 'geen alias voor een willekeurige naam');
}

$props = $fromAl['properties'];
mimir_alias_same(
    mimir_baseline_jobno_filter("JobNo eq 'PRJ1'", $props),
    "Job_No eq 'PRJ1'",
    'RapidStart JobNo wordt Job_No'
);
mimir_alias_same(
    mimir_baseline_jobno_filter("Job_No eq 'PRJ1'", $props),
    "Job_No eq 'PRJ1'",
    'Job_No blijft Job_No'
);
mimir_alias_same(
    mimir_baseline_jobno_filter("JobNo eq 'JobNo'", $props),
    "Job_No eq 'JobNo'",
    'waarde tussen quotes blijft JobNo'
);
mimir_alias_same(
    mimir_baseline_jobno_filter("Description eq 'heeft JobNo erin'", $props),
    "Description eq 'heeft JobNo erin'",
    'JobNo in een literal blijft staan'
);
mimir_alias_same(
    mimir_baseline_jobno_filter("(JobNo eq 'A') or (JobNo eq 'B')", $props),
    "(Job_No eq 'A') or (Job_No eq 'B')",
    'beide identifiers buiten quotes'
);
mimir_alias_same(
    mimir_baseline_jobno_filter("JobNoExtra eq 'A'", $props),
    "JobNoExtra eq 'A'",
    'langer veld wordt niet afgekapt'
);

$json = mimir_baseline_jobno_filter([
    'and' => [
        ['field' => 'JobNo', 'op' => 'eq', 'value' => 'PRJ1'],
        ['field' => 'Quantity', 'op' => 'gt', 'value' => 0],
    ],
], $props);
if (!is_array($json) || ($json['and'][0]['field'] ?? null) !== 'Job_No' || ($json['and'][1]['field'] ?? null) !== 'Quantity') {
    mimir_alias_fail('JSON-filter JobNo werd niet hernoemd');
}

$withJobNo = $props;
$withJobNo['JobNo'] = 'Edm.String';
mimir_alias_same(
    mimir_baseline_jobno_filter("JobNo eq 'PRJ1'", $withJobNo),
    "JobNo eq 'PRJ1'",
    'bestaand veld JobNo blijft'
);
mimir_alias_same(
    mimir_baseline_jobno_filter("JobNo eq 'PRJ1'", ['Quantity' => 'Edm.Decimal']),
    "JobNo eq 'PRJ1'",
    'zonder Job_No geen herschrijving'
);

fwrite(STDOUT, "ok\n");
