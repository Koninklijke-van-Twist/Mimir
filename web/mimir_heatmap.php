<?php

declare(strict_types=1);

/**
 * Weekraster zoals Mithra: kolommen ma–zo, rijen zijn weken, oudste week bovenaan.
 * INTENSITY_MAX staat lager dan Mithra (500 scans). Een API-sleutel heeft zelden
 * honderden calls per dag; 20 maakt de stappen in het raster zichtbaar.
 */
const MIMIR_HEATMAP_COLS = 7;
const MIMIR_HEATMAP_ROWS = 4;
const MIMIR_HEATMAP_CELL_PX = 14;
const MIMIR_HEATMAP_CELL_GAP = 2;
const MIMIR_HEATMAP_INTENSITY_MAX = 500;
const MIMIR_HEATMAP_OVER_LIMIT_MULTIPLIER = 5;
const MIMIR_HEATMAP_TZ = 'Europe/Amsterdam';

function mimir_heatmap_timezone(): DateTimeZone
{
    return new DateTimeZone(MIMIR_HEATMAP_TZ);
}

function mimir_heatmap_date(string $date): ?DateTimeImmutable
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, mimir_heatmap_timezone());
    if (!$parsed instanceof DateTimeImmutable) {
        return null;
    }
    $errors = DateTimeImmutable::getLastErrors();
    if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
        return null;
    }
    return $parsed;
}

function mimir_heatmap_today(int $now): string
{
    return (new DateTimeImmutable('@' . $now))->setTimezone(mimir_heatmap_timezone())->format('Y-m-d');
}

function mimir_heatmap_monday_of_week(string $date): string
{
    $parsed = mimir_heatmap_date($date);
    if ($parsed === null) {
        return '';
    }
    $mondayOffset = (int) $parsed->format('N') - 1;
    return $parsed->modify('-' . $mondayOffset . ' days')->format('Y-m-d');
}

function mimir_heatmap_date_shift(string $date, int $dayOffset): string
{
    $parsed = mimir_heatmap_date($date);
    if ($parsed === null) {
        return '';
    }
    $sign = $dayOffset >= 0 ? '+' : '';
    return $parsed->modify($sign . $dayOffset . ' days')->format('Y-m-d');
}

/**
 * @return list<string>
 */
function mimir_heatmap_grid_dates(string $today, int $rows = MIMIR_HEATMAP_ROWS, int $cols = MIMIR_HEATMAP_COLS): array
{
    $today = mimir_heatmap_date($today) !== null ? $today : '';
    if ($today === '') {
        return [];
    }
    $rows = max(1, $rows);
    $cols = max(1, $cols);
    $monday = mimir_heatmap_monday_of_week($today);
    if ($monday === '') {
        return [];
    }
    $oldestMonday = mimir_heatmap_date_shift($monday, -(($rows - 1) * 7));
    if ($oldestMonday === '') {
        return [];
    }
    $dates = [];
    for ($row = 0; $row < $rows; $row++) {
        for ($col = 0; $col < $cols; $col++) {
            $date = mimir_heatmap_date_shift($oldestMonday, ($row * $cols) + $col);
            if ($date !== '') {
                $dates[] = $date;
            }
        }
    }
    return $dates;
}

/**
 * @param array<string, int> $countsByDate
 * @return list<array{date: string, count: int, future: bool}>
 */
function mimir_heatmap_build_grid_days(array $countsByDate, string $today, int $rows = MIMIR_HEATMAP_ROWS, int $cols = MIMIR_HEATMAP_COLS): array
{
    $days = [];
    foreach (mimir_heatmap_grid_dates($today, $rows, $cols) as $date) {
        $future = strcmp($date, $today) > 0;
        $days[] = [
            'date' => $date,
            'count' => $future ? 0 : (int) ($countsByDate[$date] ?? 0),
            'future' => $future,
        ];
    }
    return $days;
}

function mimir_heatmap_activity_level(int $count, int $intensityMax = MIMIR_HEATMAP_INTENSITY_MAX): string
{
    if ($count <= 0) {
        return '';
    }
    if ($count > $intensityMax) {
        return 'level-over';
    }
    if ($count >= $intensityMax) {
        return 'level-max';
    }
    if ($count >= (int) ceil($intensityMax * 0.75)) {
        return 'level-4';
    }
    if ($count >= (int) ceil($intensityMax * 0.5)) {
        return 'level-3';
    }
    if ($count >= (int) ceil($intensityMax * 0.25)) {
        return 'level-2';
    }
    return 'level-1';
}

/**
 * @return array{rows: int, cols: int, cell_px: int, gap_px: int, intensity_max: int, over_limit_multiplier: int}
 */
function mimir_heatmap_options(): array
{
    return [
        'rows' => MIMIR_HEATMAP_ROWS,
        'cols' => MIMIR_HEATMAP_COLS,
        'cell_px' => MIMIR_HEATMAP_CELL_PX,
        'gap_px' => MIMIR_HEATMAP_CELL_GAP,
        'intensity_max' => MIMIR_HEATMAP_INTENSITY_MAX,
        'over_limit_multiplier' => MIMIR_HEATMAP_OVER_LIMIT_MULTIPLIER,
    ];
}
