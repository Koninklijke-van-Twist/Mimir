<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/web/mimir_service.php';

function mimir_key_fail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function mimir_key_same(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) {
        mimir_key_fail($message . ' expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }
}

$now = 1_700_000_000;
$pdo = mimir_db(':memory:');
$created = mimir_key_create($pdo, 'Tim@KVT.nl', 'Consus', $now);
if (!str_starts_with($created['key'], 'mimir_') || strlen($created['key']) < 20) {
    mimir_key_fail('key should be a mimir_ token');
}

$hash = mimir_key_hash($created['key']);
$storedHash = (string) $pdo->query('SELECT key_hash FROM api_keys WHERE id = ' . (int) $created['id'])->fetchColumn();
mimir_key_same($storedHash, $hash, 'lookup column is the sha256 of the key');
$plain = (string) $pdo->query('SELECT key_plain FROM api_keys WHERE id = ' . (int) $created['id'])->fetchColumn();
mimir_key_same($plain, $created['key'], 'plaintext stays available for the owner');

$found = mimir_key_lookup($pdo, $created['key']);
if ($found === null || $found['id'] !== $created['id']) {
    mimir_key_fail('lookup by presented key should hit the hash');
}
mimir_key_same(mimir_key_lookup($pdo, $created['key'] . 'x'), null, 'wrong key misses');

mimir_usage_log($pdo, $created['id'], 'query', $now - 86400);
mimir_usage_log($pdo, $created['id'], 'tables', $now - 10);
for ($i = 0; $i < 28; $i++) {
    mimir_usage_log($pdo, $created['id'], 'schema', $now - 1000);
}
mimir_key_same(mimir_key_avg_per_day($pdo, $created['id'], $now), 1.0, '30 calls over 30 days is 1 per day');

$old = $now - (40 * 86400);
mimir_usage_log($pdo, $created['id'], 'query', $old);
mimir_key_same(mimir_key_avg_per_day($pdo, $created['id'], $now), 1.0, 'calls older than a month do not count');

$list = mimir_key_list($pdo, 'tim@kvt.nl', $now);
mimir_key_same($list[0]['key'], $created['key'], 'owner list shows the full key');
mimir_key_same($list[0]['avg_per_day'], 1.0, 'list includes the average');
mimir_key_same($list[0]['shared_pct'], 0, 'existing query without shared flag is 0%');

$empty = mimir_key_create($pdo, 'tim@kvt.nl', 'Nog leeg', $now);
$list = mimir_key_list($pdo, 'tim@kvt.nl', $now);
$byId = [];
foreach ($list as $row) {
    $byId[$row['id']] = $row;
}
mimir_key_same($byId[$empty['id']]['shared_pct'], null, 'key without query calls shows null shared_pct');

$shareKey = mimir_key_create($pdo, 'tim@kvt.nl', 'Share demo', $now);
mimir_usage_log($pdo, $shareKey['id'], 'query', $now - 100, 1, 0, 1, 0);
mimir_usage_log($pdo, $shareKey['id'], 'query', $now - 90, 0, 1, 0, 1);
$list = mimir_key_list($pdo, 'tim@kvt.nl', $now);
$byId = [];
foreach ($list as $row) {
    $byId[$row['id']] = $row;
}
mimir_key_same($byId[$shareKey['id']]['shared_pct'], 50, 'list includes shared_pct for past week queries');

mimir_key_same(mimir_key_revoke($pdo, $created['id'], 'other@kvt.nl', $now), false, 'someone else cannot revoke');
mimir_key_same(mimir_key_revoke($pdo, $created['id'], 'tim@kvt.nl', $now), true, 'owner can revoke');
mimir_key_same(mimir_key_lookup($pdo, $created['key']), null, 'revoked key no longer authenticates');

$left = ['value' => [['No' => '1', 'Vendor_No' => '9']], 'meta' => []];
$right = ['value' => [['No' => '9', 'Name' => 'Acme']], 'meta' => []];
$joined = mimir_apply_combine([
    'items' => $left,
    'vendors' => $right,
], [
    'left' => 'items',
    'right' => 'vendors',
    'left_key' => 'Vendor_No',
    'right_key' => 'No',
    'as' => 'joined',
]);
mimir_key_same($joined['joined']['value'][0]['items.No'], '1', 'join keeps the left key');
mimir_key_same($joined['joined']['value'][0]['vendors.Name'], 'Acme', 'join adds the right name');

fwrite(STDOUT, "ok\n");
