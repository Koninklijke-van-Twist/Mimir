<?php

declare(strict_types=1);

/**
 * Delta-ophalen: bij een live fetch alleen rijen die sinds de vorige fetch in BC
 * gewijzigd zijn, en die in de bestaande rijcache mergen.
 *
 * Conservatief: bij elke twijfel null (= volledige fetch zoals voorheen).
 */

defined('MIMIR_DELTA_ENABLED') || define('MIMIR_DELTA_ENABLED', true);
// Minstens zo vaak een volledige fetch: wijzigingsvelden tonen geen verwijderde rijen.
defined('MIMIR_DELTA_FULL_REFRESH') || define('MIMIR_DELTA_FULL_REFRESH', 86400);
// Veiligheidsmarge op de starttijd van de vorige fetch (klokverschil, lange transacties).
defined('MIMIR_DELTA_MARGIN') || define('MIMIR_DELTA_MARGIN', 600);

const MIMIR_DELTA_FIELDS = [
    'SystemModifiedAt',
    'Last_DateTime_Modified',
    'lastModifiedDateTime',
    'Last_Modified_Date_Time',
    'Last_Date_Modified',
];

/**
 * Wijzigingsveld uit de metadata-typen, of null.
 *
 * @param array<string, string> $types
 * @return array{field: string, date_only: bool}|null
 */
function mimir_delta_change_field(array $types): ?array
{
    foreach (MIMIR_DELTA_FIELDS as $field) {
        $type = $types[$field] ?? null;
        if ($type === 'Edm.DateTimeOffset') {
            return ['field' => $field, 'date_only' => false];
        }
        if ($type === 'Edm.Date') {
            return ['field' => $field, 'date_only' => true];
        }
    }

    return null;
}

/**
 * OData-filter op het wijzigingsveld, gerekend vanaf de START van de vorige fetch.
 * DateTime: gt (start - marge). Alleen datum: ge datum van (start - marge), die
 * hele dag opnieuw (UTC-datum ligt nooit na de BC-datum in NL).
 *
 * @param array{field: string, date_only: bool} $change
 */
function mimir_delta_filter(array $change, int $previousStart): string
{
    $since = $previousStart - MIMIR_DELTA_MARGIN;
    if ($change['date_only']) {
        return $change['field'] . ' ge ' . gmdate('Y-m-d', $since);
    }

    return $change['field'] . ' gt ' . gmdate('Y-m-d\TH:i:s\Z', $since);
}

/**
 * Waarom deze query geen delta mag (null = mag wel).
 *
 * @param array<string, mixed> $job
 */
function mimir_delta_reason(array $job): ?string
{
    $types = $job['types'];
    if (!MIMIR_DELTA_ENABLED) {
        return 'uitgeschakeld';
    }
    if (($job['route'] ?? null) === 'tunnel') {
        return 'tunnel';
    }
    if ($job['keys'] === []) {
        return 'sleutel onbekend';
    }
    foreach ($job['keys'] as $key) {
        if (!isset($types[$key])) {
            return 'sleutel onbekend';
        }
    }
    if ((int) $job['top'] !== 0) {
        return 'top';
    }
    foreach (array_keys($types) as $name) {
        // FlowFilter-velden betekenen FlowFields; die veranderen zonder wijzigingsdatum.
        if (preg_match('/_Filter$/', (string) $name) === 1) {
            return 'flowfields';
        }
    }
    if (mimir_filter_has_flowfilter($job['filter']) || !mimir_filter_coverage_servable($job['filter'])) {
        return 'filter niet lokaal te bewijzen';
    }
    if (mimir_delta_change_field($types) === null) {
        return 'geen wijzigingsveld';
    }

    return null;
}

/** @param array<string, mixed> $job */
function mimir_delta_state_key(array $job): string
{
    return 'delta:' . $job['company'] . '|' . $job['entity'] . '|' . md5((string) json_encode($job['filter']));
}

/**
 * Na een geslaagde volledige fetch: starttijd vastleggen als basis voor de volgende delta.
 *
 * @param array<string, mixed> $job
 * @param array{truncated: bool, pushed: ?string} $live
 * @param list<string> $fetchSelect
 */
function mimir_delta_record_full(PDO $pdo, array $job, array $live, array $fetchSelect, int $now): string
{
    $reason = mimir_delta_reason($job);
    if ($reason === null && (!empty($live['truncated']) || !empty($GLOBALS['mimir_stamp_bc_live']))) {
        $reason = 'onvolledig';
    }
    if ($reason === null) {
        try {
            mimir_meta_put($pdo, mimir_delta_state_key($job), [
                'full_at' => $now,
                'last_at' => $now,
                'filter_sig' => $live['pushed'] ?? '',
                'select_sig' => mimir_select_sig($fetchSelect),
            ], $now);
        } catch (Throwable $error) {
            $reason = 'status niet opgeslagen';
        }
    }
    $mode = $reason === null ? 'full' : 'full (' . $reason . ')';
    mimir_event_log('delta', 'mode=' . $mode . ' webservice=' . $job['entity'], $job['environment'], $job['entity'], 'delta-full');

    return $mode;
}

/**
 * Probeer een delta. Null = volledige fetch doen.
 *
 * @param array<string, mixed> $job
 * @param callable(string): array $fetch
 * @return array{rows: list<array{payload: array<string, mixed>, fetched_at: int}>, from_live: int}|null
 */
function mimir_delta_try(PDO $pdo, array $job, callable $fetch, int $now): ?array
{
    if (mimir_delta_reason($job) !== null) {
        return null;
    }
    $key = mimir_delta_state_key($job);
    $state = mimir_meta_get_raw($pdo, $key);
    if (!is_array($state) || !isset($state['full_at'], $state['last_at'])) {
        return null;
    }
    $fullAt = (int) $state['full_at'];
    if ($now - $fullAt >= MIMIR_DELTA_FULL_REFRESH || !mimir_select_sig_covers((string) $state['select_sig'], mimir_select_sig($job['required']))) {
        return null;
    }
    if (!mimir_delta_coverage_exists($pdo, $job, (string) $state['filter_sig'], (string) $state['select_sig'], $fullAt)) {
        return null; // bv. na een write geïnvalideerd
    }
    $change = mimir_delta_change_field($job['types']);
    try {
        // Alleen op het wijzigingsveld, niet AND het eigen filter: zo komen ook rijen
        // binnen die sinds de vorige fetch NIET meer aan het filter voldoen.
        $url = mimir_collection_url($job['prefix'], $job['company'], $job['entity'], [
            '$filter' => mimir_delta_filter($change, (int) $state['last_at']),
        ]);
        $pages = mimir_follow_pages($url, $job['prefix'], $fetch);
        if ($pages['truncated']) {
            throw new RuntimeException('delta onvolledig');
        }
        $batch = [];
        foreach ($pages['rows'] as $payload) {
            $clean = mimir_strip_odata_noise($payload);
            foreach ($job['keys'] as $field) {
                if (!array_key_exists($field, $clean)) {
                    throw new RuntimeException('delta-rij zonder sleutel');
                }
            }
            $batch[] = [mimir_row_key($clean, $job['keys']), $clean];
        }
        unset($pages);
        mimir_cache_upsert_many($pdo, $job['environment'], $job['company'], $job['entity'], $batch, $now);
        $state['last_at'] = $now;
        mimir_meta_put($pdo, $key, $state, $now);
    } catch (Throwable $error) {
        mimir_event_log('delta', 'Delta mislukt, volledige fetch: ' . $error->getMessage(), $job['environment'], $job['entity'], 'delta-fallback');

        return null;
    }

    // Alleen rijen die de laatste volledige fetch (of later) nog zag: verwijderde rijen vallen zo af.
    $rows = [];
    $fromLive = 0;
    foreach (mimir_cache_each($pdo, $job['environment'], $job['company'], $job['entity']) as $row) {
        if ($row['fetched_at'] < $fullAt || !mimir_filter_match($row['payload'], $job['filter'], $job['types'])) {
            continue;
        }
        $rows[] = ['payload' => $row['payload'], 'fetched_at' => $row['fetched_at']];
        if ($row['fetched_at'] === $now) {
            $fromLive++;
        }
    }
    mimir_event_log('delta', 'mode=delta gewijzigd=' . count($batch) . ' rijen=' . count($rows) . ' webservice=' . $job['entity'], $job['environment'], $job['entity'], 'delta');

    return ['rows' => $rows, 'from_live' => $fromLive];
}

/** @param array<string, mixed> $job */
function mimir_delta_coverage_exists(PDO $pdo, array $job, string $filterSig, string $selectSig, int $fullAt): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM cache_coverage WHERE environment = :e AND company = :c AND entity = :t
         AND filter_sig = :f AND select_sig = :s AND fetched_at >= :at'
    );
    $stmt->execute([':e' => $job['environment'], ':c' => $job['company'], ':t' => $job['entity'], ':f' => $filterSig, ':s' => $selectSig, ':at' => $fullAt]);

    return $stmt->fetchColumn() !== false;
}
