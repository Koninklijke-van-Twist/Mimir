<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/web/mimir_store.php';

function mimir_heat_fail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function mimir_heat_same(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) {
        mimir_heat_fail($message . ' expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }
}

$today = '2026-09-23';
mimir_heat_same(mimir_heatmap_monday_of_week($today), '2026-09-21', 'wednesday belongs to monday the 21st');
$dates = mimir_heatmap_grid_dates($today);
mimir_heat_same(count($dates), 28, 'four weeks by seven days');
mimir_heat_same($dates[0], '2026-08-31', 'grid starts on the monday four weeks back');
mimir_heat_same($dates[6], '2026-09-06', 'first row ends on sunday');
mimir_heat_same($dates[27], '2026-09-27', 'grid ends on the sunday of the current week');

$days = mimir_heatmap_build_grid_days(['2026-09-23' => 4, '2026-09-24' => 9], $today);
$byDate = [];
foreach ($days as $day) {
    $byDate[$day['date']] = $day;
}
mimir_heat_same($byDate['2026-09-23']['count'], 4, 'today keeps its count');
mimir_heat_same($byDate['2026-09-23']['future'], false, 'today is not future');
mimir_heat_same($byDate['2026-09-24']['future'], true, 'tomorrow is future');
mimir_heat_same($byDate['2026-09-24']['count'], 0, 'future days do not keep a count');
mimir_heat_same($byDate['2026-09-01']['count'], 0, 'a quiet day stays zero');

mimir_heat_same(MIMIR_HEATMAP_INTENSITY_MAX, 3500, 'production intensity max');
$max = 20;
mimir_heat_same(mimir_heatmap_activity_level(0, $max), '', 'zero has no level');
mimir_heat_same(mimir_heatmap_activity_level(1, $max), 'level-1', 'one call is the lightest step');
mimir_heat_same(mimir_heatmap_activity_level(5, $max), 'level-2', 'quarter of the max');
mimir_heat_same(mimir_heatmap_activity_level(10, $max), 'level-3', 'half of the max');
mimir_heat_same(mimir_heatmap_activity_level(15, $max), 'level-4', 'three quarters of the max');
mimir_heat_same(mimir_heatmap_activity_level(20, $max), 'level-max', 'the cap is max');
mimir_heat_same(mimir_heatmap_activity_level(21, $max), 'level-over', 'above the cap is over');

$zone = new DateTimeZone('Europe/Amsterdam');
$now = (new DateTimeImmutable('2026-09-23 15:00:00', $zone))->getTimestamp();
$pdo = mimir_db(':memory:');
$created = mimir_key_create($pdo, 'tim@kvt.nl', 'Heat', $now);
$onDay = (new DateTimeImmutable('2026-09-23 09:30:00', $zone))->getTimestamp();
$also = (new DateTimeImmutable('2026-09-23 18:05:00', $zone))->getTimestamp();
$weekOne = (new DateTimeImmutable('2026-09-01 11:00:00', $zone))->getTimestamp();
$outside = (new DateTimeImmutable('2026-08-01 11:00:00', $zone))->getTimestamp();
mimir_usage_log($pdo, $created['id'], 'query', $onDay);
mimir_usage_log($pdo, $created['id'], 'tables', $also);
mimir_usage_log($pdo, $created['id'], 'schema', $weekOne);
mimir_usage_log($pdo, $created['id'], 'query', $outside);

$usage = [];
foreach (mimir_key_usage_days($pdo, $created['id'], $now) as $day) {
    $usage[$day['date']] = $day;
}
mimir_heat_same(count($usage), 28, 'usage grid covers four weeks');
mimir_heat_same($usage['2026-09-23']['count'], 2, 'two calls on the same Amsterdam day');
mimir_heat_same($usage['2026-09-01']['count'], 1, 'older week inside the grid counts');
mimir_heat_same(isset($usage['2026-08-01']), false, 'a day before the grid is left out');

fwrite(STDOUT, "ok\n");
