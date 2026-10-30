<?php

declare(strict_types=1);

/**
 * Delta-ophalen: bij een live fetch alleen rijen die sinds de vorige fetch in BC
 * gewijzigd zijn, en die in de bestaande rijcache mergen.
 *
 * Conservatief: bij elke twijfel null (= volledige fetch zoals voorheen).
 */

defined('MIMIR_DELTA_ENABLED') || define('MIMIR_DELTA_ENABLED', true);
// Vangnet: na zo lang zonder volledige fetch of afgeronde weekendverversing toch volledig.
// Iets ruimer dan een week, zodat een geslaagde weekendverversing er altijd eerder is.
defined('MIMIR_DELTA_FULL_REFRESH') || define('MIMIR_DELTA_FULL_REFRESH', 8 * 86400);
// Tabellen die hourly.php in het weekend in 48 blokken ververst.
defined('MIMIR_ROLLING_REFRESH_TABLES') || define('MIMIR_ROLLING_REFRESH_TABLES', ['AppItems']);
defined('MIMIR_ROLLING_SLOTS') || define('MIMIR_ROLLING_SLOTS', 48);
// Inhalen na een gemist uur, maar niet alles in één run.
defined('MIMIR_ROLLING_MAX_CHUNKS_PER_RUN') || define('MIMIR_ROLLING_MAX_CHUNKS_PER_RUN', 6);
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
    // Strikt opt-in: FlowFields zijn niet uit de metadata te halen. Alleen tabellen uit
    // de config, en alleen als alle gevraagde kolommen bevestigde Normal-velden zijn.
    $normal = mimir_delta_normal_fields((string) ($job['entity'] ?? ''));
    if ($normal === null || $normal === []) {
        return 'niet in config';
    }
    if (($job['select'] ?? []) === []) {
        return 'geen select';
    }
    $needed = array_merge($job['select'], mimir_filter_fields($job['filter']), $job['keys']);
    if (array_diff($needed, $normal) !== []) {
        return 'kolom niet op whitelist';
    }
    if (mimir_filter_has_flowfilter($job['filter']) || !mimir_filter_coverage_servable($job['filter'])
        || (is_string($job['filter']) && mimir_filter_parse_simple_eq($job['filter']) === null)) {
        return 'filter niet lokaal te bewijzen';
    }
    if (mimir_delta_change_field($types) === null) {
        return 'geen wijzigingsveld';
    }

    return null;
}

/**
 * Bevestigde Normal-velden van een tabel (web/config/delta_normal_fields.php), of null.
 *
 * @return list<string>|null
 */
function mimir_delta_normal_fields(string $entity): ?array
{
    $config = $GLOBALS['mimir_delta_normal_fields'] ??= (array) (require __DIR__ . '/config/delta_normal_fields.php');
    $fields = $config[$entity] ?? null;

    return is_array($fields) ? array_values(array_map('strval', $fields)) : null;
}

/**
 * $select voor delta/weekendblokken: sleutels en whitelist-velden die bestaan.
 *
 * @param array<string, mixed> $job
 * @return array<string, string>
 */
function mimir_delta_select_query(array $job): array
{
    $fields = array_values(array_intersect((array) mimir_delta_normal_fields($job['entity']), array_keys($job['types'])));

    return ['$select' => implode(',', array_unique(array_merge($job['keys'], $fields)))];
}

/**
 * Upsert van delta-/blokrijen: de rij vervangt de oude (geen merge) en bevat alleen
 * sleutels en whitelist-velden, ook als BC $select negeert.
 *
 * @param array<string, mixed> $job
 * @param list<array{0: string, 1: array<string, mixed>}> $batch
 */
function mimir_delta_store(PDO $pdo, array $job, array $batch, int $now): void
{
    $kept = mimir_cache_kept_columns($job['entity']);
    $select = array_merge($job['keys'], $kept);
    foreach ($batch as $i => [$rowKey, $payload]) {
        $batch[$i][1] = array_intersect_key($payload, array_flip($select));
    }
    mimir_coverage_drop_wider($pdo, $job['environment'], $job['company'], $job['entity'], $select, $kept);
    mimir_cache_upsert_many($pdo, $job['environment'], $job['company'], $job['entity'], $batch, $now, $kept);
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
    if (!mimir_delta_coverage_exists($pdo, $job, (string) $state['filter_sig'], (string) $state['select_sig'])) {
        return null; // bv. na een write geïnvalideerd
    }
    $change = mimir_delta_change_field($job['types']);
    try {
        // Alleen op het wijzigingsveld, niet AND het eigen filter: zo komen ook rijen
        // binnen die sinds de vorige fetch NIET meer aan het filter voldoen.
        $url = mimir_collection_url($job['prefix'], $job['company'], $job['entity'], [
            '$filter' => mimir_delta_filter($change, (int) $state['last_at']),
        ] + mimir_delta_select_query($job));
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
        mimir_delta_store($pdo, $job, $batch, $now);
        unset($state['_fetched_at']);
        $state['last_at'] = $now;
        mimir_meta_put($pdo, $key, $state, $now);
    } catch (Throwable $error) {
        mimir_event_log('delta', 'Delta mislukt, volledige fetch: ' . $error->getMessage(), $job['environment'], $job['entity'], 'delta-fallback');

        return null;
    }

    try {
        $rows = mimir_delta_cached_rows($pdo, $job, $fullAt);
        $check = mimir_delta_count_check($pdo, $job, (string) $state['filter_sig'], count($rows), $fetch);
        if ($check === 'swept') {
            $rows = mimir_delta_cached_rows($pdo, $job, $fullAt);
            if (mimir_delta_count_check($pdo, $job, (string) $state['filter_sig'], count($rows), $fetch) !== 'ok') {
                throw new RuntimeException('aantal klopt ook na sleutelcontrole niet');
            }
        }
    } catch (Throwable $error) {
        mimir_event_log('delta', 'Controle mislukt, volledige fetch: ' . $error->getMessage(), $job['environment'], $job['entity'], 'delta-fallback');

        return null;
    }
    $fromLive = count(array_filter($rows, static fn (array $row): bool => $row['fetched_at'] === $now));
    mimir_event_log('delta', 'mode=delta gewijzigd=' . count($batch) . ' rijen=' . count($rows) . ' controle=' . $check . ' webservice=' . $job['entity'], $job['environment'], $job['entity'], 'delta');

    return ['rows' => $rows, 'from_live' => $fromLive];
}

/**
 * Gecachete rijen die aan het filter voldoen, gesorteerd op sleutel. Alleen rijen
 * die de laatste volledige fetch (of weekendverversing) of later nog zag.
 *
 * @param array<string, mixed> $job
 * @return list<array{payload: array<string, mixed>, fetched_at: int}>
 */
function mimir_delta_cached_rows(PDO $pdo, array $job, int $fullAt): array
{
    $rows = [];
    foreach (mimir_cache_each($pdo, $job['environment'], $job['company'], $job['entity']) as $row) {
        if ($row['fetched_at'] >= $fullAt && mimir_filter_match($row['payload'], $job['filter'], $job['types'])) {
            $rows[] = ['payload' => $row['payload'], 'fetched_at' => $row['fetched_at']];
        }
    }
    usort($rows, static function (array $a, array $b) use ($job): int {
        foreach ($job['keys'] as $key) {
            $cmp = ($a['payload'][$key] ?? null) <=> ($b['payload'][$key] ?? null);
            if ($cmp !== 0) {
                return $cmp;
            }
        }

        return 0;
    });

    return $rows;
}

/**
 * Vergelijk het aantal rijen in BC ($count, zelfde basisfilter) met de cache.
 * 'ok' = gelijk; 'swept' = verschil of onbekend, sleutels opgehaald en verdwenen rijen gewist.
 *
 * @param array<string, mixed> $job
 */
function mimir_delta_count_check(PDO $pdo, array $job, string $filterSig, int $cachedMatching, callable $fetch): string
{
    $query = ['$top' => 0, '$count' => 'true'];
    if ($filterSig !== '') {
        $query['$filter'] = $filterSig;
    }
    $payload = $fetch(mimir_collection_url($job['prefix'], $job['company'], $job['entity'], $query));
    $count = $payload['@odata.count'] ?? null;
    if (is_int($count) && $count === ($filterSig === '' ? mimir_delta_cached_total($pdo, $job) : $cachedMatching)) {
        return 'ok';
    }
    mimir_delta_sweep($pdo, $job, mimir_delta_bc_keys($job, $fetch));

    return 'swept';
}

/** @param array<string, mixed> $job */
function mimir_delta_cached_total(PDO $pdo, array $job): int
{
    $fullAt = (int) (mimir_meta_get_raw($pdo, mimir_delta_state_key($job))['full_at'] ?? PHP_INT_MAX);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM cache_rows WHERE environment = :e AND company = :c AND entity = :t AND fetched_at >= :at');
    $stmt->execute([':e' => $job['environment'], ':c' => $job['company'], ':t' => $job['entity'], ':at' => $fullAt]);

    return (int) $stmt->fetchColumn();
}

/**
 * Alle sleutels van de tabel in BC-volgorde, alleen sleutelvelden, per pagina.
 *
 * @param array<string, mixed> $job
 * @return list<array{row_key: string, values: array<string, mixed>}>
 */
function mimir_delta_bc_keys(array $job, callable $fetch): array
{
    $keys = [];
    $url = mimir_collection_url($job['prefix'], $job['company'], $job['entity'], ['$select' => implode(',', $job['keys'])]);
    $pages = mimir_follow_pages($url, $job['prefix'], $fetch, 0, static function (array $page) use (&$keys, $job): void {
        foreach ($page as $row) {
            $values = [];
            foreach ($job['keys'] as $field) {
                if (!is_array($row) || !array_key_exists($field, $row)) {
                    throw new RuntimeException('sleutelrij zonder sleutel');
                }
                $values[$field] = $row[$field];
            }
            $keys[] = ['row_key' => mimir_row_key($values, $job['keys']), 'values' => $values];
        }
    });
    if ($pages['truncated']) {
        throw new RuntimeException('sleutellijst onvolledig');
    }

    return $keys;
}

/**
 * Wis gecachete rijen waarvan de sleutel niet meer in BC staat.
 *
 * @param array<string, mixed> $job
 * @param list<array{row_key: string}> $bcKeys
 */
function mimir_delta_sweep(PDO $pdo, array $job, array $bcKeys): int
{
    $present = array_flip(array_column($bcKeys, 'row_key'));
    $stmt = $pdo->prepare('SELECT row_key FROM cache_rows WHERE environment = :e AND company = :c AND entity = :t');
    $stmt->execute([':e' => $job['environment'], ':c' => $job['company'], ':t' => $job['entity']]);
    $gone = [];
    while (($rowKey = $stmt->fetchColumn()) !== false) {
        if (!isset($present[(string) $rowKey])) {
            $gone[] = (string) $rowKey;
        }
    }
    $stmt->closeCursor();
    foreach ($gone as $rowKey) {
        mimir_cache_delete($pdo, $job['environment'], $job['company'], $job['entity'], $rowKey);
    }

    return count($gone);
}

/**
 * Weekendverversing in blokken (hourly.php): slot 0 haalt alle sleutels, wist
 * verdwenen rijen en legt de blokgrenzen vast; elk slot ververst daarna één blok
 * volledige rijen. Na het laatste blok telt dit als volledige fetch (full_at).
 * Alleen bij precies één Edm.String-sleutel; anders 'overgeslagen'.
 *
 * @param array<string, mixed> $job environment, company, entity, prefix, keys, types
 * @return string korte status voor de log
 */
function mimir_rolling_refresh(PDO $pdo, array $job, callable $fetch, int $slot, int $weekendStart, int $now): string
{
    if (count($job['keys']) !== 1 || ($job['types'][$job['keys'][0]] ?? '') !== 'Edm.String') {
        return 'overgeslagen (sleutel)';
    }
    if (!in_array($job['keys'][0], (array) mimir_delta_normal_fields($job['entity']), true)) {
        return 'overgeslagen (niet in config)';
    }
    $key = $job['keys'][0];
    $stateKey = 'rolling:' . $job['company'] . '|' . $job['entity'];
    $state = mimir_meta_get_raw($pdo, $stateKey);
    if (!is_array($state) || (int) ($state['cycle_start'] ?? 0) < $weekendStart) {
        $state = null; // nieuwe ronde dit weekend
    }
    $done = 0;
    $next = $state === null ? 0 : (int) $state['done'] + 1;
    while ($next <= $slot && $next < MIMIR_ROLLING_SLOTS && $done < MIMIR_ROLLING_MAX_CHUNKS_PER_RUN) {
        if ($next === 0) {
            $bcKeys = mimir_delta_bc_keys($job, $fetch);
            mimir_delta_sweep($pdo, $job, $bcKeys);
            $bounds = [];
            for ($i = 1; $i < MIMIR_ROLLING_SLOTS; $i++) {
                $bounds[] = $bcKeys[intdiv($i * count($bcKeys), MIMIR_ROLLING_SLOTS)]['values'][$key] ?? null;
            }
            // Kleine tabel: alles in één blok.
            $small = count($bcKeys) < MIMIR_ROLLING_SLOTS * 10;
            unset($bcKeys);
            $state = ['cycle_start' => $now, 'bounds' => $bounds, 'done' => -1, 'small' => $small];
        }
        $lower = $next === 0 ? null : $state['bounds'][$next - 1];
        $upper = $next === MIMIR_ROLLING_SLOTS - 1 || !empty($state['small']) ? null : $state['bounds'][$next];
        $parts = [];
        if ($lower !== null) {
            $parts[] = $key . " ge '" . str_replace("'", "''", (string) $lower) . "'";
        }
        if ($upper !== null) {
            $parts[] = $key . " lt '" . str_replace("'", "''", (string) $upper) . "'";
        }
        $query = ($parts === [] ? [] : ['$filter' => implode(' and ', $parts)]) + mimir_delta_select_query($job);
        $pages = mimir_follow_pages(
            mimir_collection_url($job['prefix'], $job['company'], $job['entity'], $query),
            $job['prefix'],
            $fetch,
            0,
            static function (array $page) use ($pdo, $job, $now): void {
                $batch = [];
                foreach ($page as $row) {
                    $clean = mimir_strip_odata_noise($row);
                    $batch[] = [mimir_row_key($clean, $job['keys']), $clean];
                }
                mimir_delta_store($pdo, $job, $batch, $now);
            }
        );
        if ($pages['truncated']) {
            throw new RuntimeException('blok ' . $next . ' onvolledig');
        }
        $state['done'] = empty($state['small']) ? $next : MIMIR_ROLLING_SLOTS - 1;
        unset($state['_fetched_at']);
        mimir_meta_put($pdo, $stateKey, $state, $now);
        $next = (int) $state['done'] + 1;
        $done++;
    }
    if ($state !== null && (int) $state['done'] === MIMIR_ROLLING_SLOTS - 1 && empty($state['applied'])) {
        mimir_rolling_mark_full($pdo, $job, (int) $state['cycle_start'], $now);
        $state['applied'] = true;
        unset($state['_fetched_at']);
        mimir_meta_put($pdo, $stateKey, $state, $now);

        return 'ronde klaar';
    }

    return $state === null ? 'niets te doen' : 'blok ' . $state['done'] . ' van ' . (MIMIR_ROLLING_SLOTS - 1);
}

/**
 * Afgeronde weekendronde: elke delta-status van dit bedrijf/deze tabel krijgt
 * de start van de ronde als laatste volledige fetch.
 *
 * @param array<string, mixed> $job
 */
function mimir_rolling_mark_full(PDO $pdo, array $job, int $cycleStart, int $now): void
{
    $stmt = $pdo->prepare('SELECT cache_key FROM meta_cache WHERE cache_key LIKE :prefix');
    $stmt->execute([':prefix' => 'delta:' . $job['company'] . '|' . $job['entity'] . '|%']);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $cacheKey) {
        $state = mimir_meta_get_raw($pdo, (string) $cacheKey);
        if (is_array($state) && (int) ($state['full_at'] ?? 0) < $cycleStart) {
            unset($state['_fetched_at']);
            $state['full_at'] = $cycleStart;
            mimir_meta_put($pdo, (string) $cacheKey, $state, $now);
        }
    }
}

/** @param array<string, mixed> $job */
function mimir_delta_coverage_exists(PDO $pdo, array $job, string $filterSig, string $selectSig): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM cache_coverage WHERE environment = :e AND company = :c AND entity = :t
         AND filter_sig = :f AND select_sig = :s'
    );
    $stmt->execute([':e' => $job['environment'], ':c' => $job['company'], ':t' => $job['entity'], ':f' => $filterSig, ':s' => $selectSig]);

    return $stmt->fetchColumn() !== false;
}
