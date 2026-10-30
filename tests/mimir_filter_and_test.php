<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/web/mimir_filter.php';

function t(bool $ok, string $msg): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
}

$types = ['Start_Date' => 'Edm.Date', 'End_Date' => 'Edm.Date', 'Created' => 'Edm.DateTimeOffset'];
$prev = "Component_No eq 'C 1' and Status ne 'Geannuleerd'";
$week = "Resource_No eq '1419' and Start_Date le 2026-10-25 and End_Date ge 2026-10-19";

t(count(mimir_filter_parse_simple_and($prev) ?? []) === 2, 'prev parse');
t(count(mimir_filter_parse_simple_and($week) ?? []) === 3, 'week parse');
t(mimir_filter_parse_simple_and("Name eq 'a and b' and X eq 'y'")[0]['value'] === 'a and b', 'and in quotes');
t(mimir_filter_parse_simple_and("A eq 'x' or B eq 'y'") === null, 'or rejected');
t(mimir_filter_parse_simple_and("(A eq 'x') and B eq 'y'") === null, 'parens rejected');
t(mimir_filter_parse_simple_and("Qty ge 5") === null, 'number rejected');
t(mimir_filter_coverage_servable($prev, $types), 'prev servable');
t(mimir_filter_coverage_servable($week, $types), 'week servable');
t(!mimir_filter_coverage_servable("Created ge 2026-10-19", $types), 'datetime not servable');
t(!mimir_filter_coverage_servable($week), 'date without types not servable');

t(mimir_filter_match(['Component_No' => 'C 1', 'Status' => 'Open'], $prev, $types), 'prev match');
t(!mimir_filter_match(['Component_No' => 'C 1', 'Status' => 'Geannuleerd'], $prev, $types), 'prev ne');
t(!mimir_filter_match(['Component_No' => 'C 2', 'Status' => 'Open'], $prev, $types), 'prev eq');
$row = ['Resource_No' => '1419', 'Start_Date' => '2026-10-20', 'End_Date' => '2026-10-21'];
t(mimir_filter_match($row, $week, $types), 'week match');
t(!mimir_filter_match(['Start_Date' => '2026-10-26'] + $row, $week, $types), 'week le');
t(!mimir_filter_match(['End_Date' => '2026-10-18'] + $row, $week, $types), 'week ge');

echo "ok\n";
