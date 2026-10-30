<?php

declare(strict_types=1);

/**
 * Gebeurtenislog lezen via api/events.php (alleen sleutels met can_read_events=1).
 * GET ?date=YYYY-MM-DD&status=cross-red,recycle-red&caller=&category=&limit=&offset=&summary=1
 * Uitvoer bevat nooit prefix/hash van een sleutel: caller wordt label + owner.
 */

require_once __DIR__ . '/mimir_write.php';

/** @return array{label: string, owner: string} */
function mimir_events_caller_parts(string $caller): array
{
    $label = '';
    $owner = '';
    if (preg_match('/label="([^"]*)"/', $caller, $m) === 1) {
        $label = $m[1];
    }
    if (preg_match('/owner=(\S+)/', $caller, $m) === 1) {
        $owner = $m[1];
    }
    if ($label === '' && $owner === '' && !str_contains($caller, '=')) {
        $label = $caller;
    } elseif ($label === '' && preg_match('/^(\w[\w-]*)(?:\s|$)/', $caller, $m) === 1 && $m[1] !== 'key_id') {
        $label = $m[1];
    }

    return ['label' => $label, 'owner' => $owner];
}

/** Bericht zonder getallen, GUID's en quoted waarden, voor groeperen. */
function mimir_events_normalize_message(string $message): string
{
    $message = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '<id>', $message) ?? $message;
    $message = preg_replace("/'[^']*'|\"[^\"]*\"/", '<v>', $message) ?? $message;
    $message = preg_replace('/\d+(?:\.\d+)?/', '<n>', $message) ?? $message;

    return trim(preg_replace('/\s+/', ' ', $message) ?? $message);
}

function mimir_events_iso(string $ts): string
{
    try {
        return (new DateTimeImmutable($ts))->setTimezone(new DateTimeZone('Europe/Amsterdam'))->format(DATE_ATOM);
    } catch (Throwable) {
        return $ts;
    }
}

/**
 * Kern zonder auth, testbaar.
 *
 * @param array<string, mixed> $get
 * @return array<string, mixed>
 */
function mimir_events_query(array $get): array
{
    $day = (string) ($get['date'] ?? '');
    if ($day === '') {
        $day = mimir_event_today();
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
        throw new MimirWriteException('date moet YYYY-MM-DD zijn.', 400, 'bad_request');
    }
    $statuses = isset($get['status']) && $get['status'] !== '' ? mimir_event_status_filter((string) $get['status']) : null;
    $callerFilter = strtolower(trim((string) ($get['caller'] ?? '')));
    $category = trim((string) ($get['category'] ?? ''));
    $limit = min(5000, max(1, (int) ($get['limit'] ?? 500)));
    $offset = max(0, (int) ($get['offset'] ?? 0));
    $summary = in_array((string) ($get['summary'] ?? ''), ['1', 'true'], true);

    $day_data = mimir_event_log_day($day, 1, PHP_INT_MAX, null);
    $rows = [];
    foreach ($day_data['rows'] as $row) {
        $status = mimir_event_status_key(mimir_event_status($row));
        if ($statuses !== null && !in_array($status, $statuses, true)) {
            continue;
        }
        if ($category !== '' && $row['category'] !== $category) {
            continue;
        }
        $caller = mimir_events_caller_parts($row['caller']);
        if ($callerFilter !== '' && strtolower($caller['label']) !== $callerFilter) {
            continue;
        }
        $rows[] = [
            'time' => mimir_events_iso($row['ts']),
            'category' => $row['category'],
            'environment' => $row['environment'],
            'entity' => $row['entity'],
            'action' => $row['action'],
            'caller' => $caller,
            'message' => $row['message'],
            'status' => $status,
        ];
    }
    $base = ['date' => $day, 'counts' => $day_data['counts'], 'all' => $day_data['all'], 'matched' => count($rows)];
    if (!$summary) {
        return $base + ['offset' => $offset, 'limit' => $limit, 'rows' => array_slice($rows, $offset, $limit)];
    }
    // Groepen van fouten; zonder statusfilter alle niet-groene/niet-info statussen.
    $errorKeys = ['question-red', 'cross-red', 'cross-orange', 'recycle-orange', 'recycle-red'];
    $groups = [];
    foreach ($rows as $row) {
        if ($statuses === null && !in_array($row['status'], $errorKeys, true)) {
            continue;
        }
        $norm = mimir_events_normalize_message($row['message']);
        $id = implode("\x1f", [$row['caller']['label'], $row['caller']['owner'], $row['entity'], $row['action'], $norm]);
        if (!isset($groups[$id])) {
            $groups[$id] = ['caller' => $row['caller'], 'entity' => $row['entity'], 'action' => $row['action'], 'message' => $norm,
                'example' => $row['message'], 'statuses' => [], 'count' => 0, 'first' => $row['time'], 'last' => $row['time']];
        }
        $g = &$groups[$id];
        $g['count']++;
        $g['statuses'][$row['status']] = ($g['statuses'][$row['status']] ?? 0) + 1;
        // Rijen zijn nieuwste eerst.
        $g['first'] = $row['time'];
        unset($g);
    }
    $groups = array_values($groups);
    usort($groups, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

    return $base + ['summary' => $groups];
}

/**
 * @param array<string, mixed> $get
 * @return array{status: int, payload: array<string, mixed>}
 */
function mimir_events_api_handle(string $method, string $apiKey, array $get, ?PDO $pdo = null): array
{
    try {
        if (strtoupper($method) !== 'GET') {
            throw new MimirWriteException('GET verwacht.', 405, 'method_not_allowed');
        }
        if ($apiKey === '') {
            throw new MimirWriteException('API-sleutel ontbreekt. Gebruik Authorization: Bearer of de header X-API-Key.', 401, 'unauthorized');
        }
        $record = $pdo instanceof PDO ? mimir_key_lookup($pdo, $apiKey) : mimir_authenticate_api_key($apiKey, $pdo);
        if (!is_array($record)) {
            throw new MimirWriteException('API-sleutel is ongeldig of ingetrokken.', 401, 'unauthorized');
        }
        if (!$pdo instanceof PDO || !mimir_key_can_read_events($pdo, (int) $record['id'])) {
            throw new MimirWriteException('Deze sleutel mag het gebeurtenislog niet lezen (zet "Mag eventlog lezen" aan).', 403, 'events_not_allowed');
        }
        mimir_usage_log_best_effort($pdo, (int) $record['id'], 'events', time());

        return ['status' => 200, 'payload' => mimir_events_query($get)];
    } catch (MimirWriteException $error) {
        return ['status' => $error->status, 'payload' => ['error' => $error->getMessage(), 'code' => $error->errorCode]];
    } catch (Throwable $error) {
        return ['status' => 500, 'payload' => ['error' => mimir_public_error($error), 'code' => 'internal_error']];
    }
}

function mimir_events_api_main(): void
{
    $apiKey = mimir_request_api_key();
    if ($apiKey !== '') {
        mimir_load_auth(true);
    }
    $result = mimir_events_api_handle((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), $apiKey, $_GET);
    mimir_json($result['payload'], $result['status']);
}
