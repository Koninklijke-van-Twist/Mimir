<?php

declare(strict_types=1);

/**
 * Publieke gezondheidscheck (geen sleutel, geen secrets): staat het circuit
 * open en is SQLite bruikbaar. Staat het circuit open, dan draait hier een
 * lichte probe die het bij succes meteen sluit. De deploy roept dit aan na
 * de upload, zodat een circuit dat tijdens de deploy openging direct herstelt.
 */

require_once dirname(__DIR__) . '/mimir_store.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$db = false;
$schema = null;
try {
    if (mimir_circuit_is_open()) {
        mimir_db_health_probe();
    }
    if (!mimir_circuit_is_open()) {
        $pdo = mimir_db(mimir_db_path());
        $schema = mimir_schema_version($pdo);
        $db = true;
        $pdo = null;
    }
} catch (Throwable) {
    $db = false;
}
$circuit = mimir_circuit_public_state();
$ok = $db && !$circuit['open'];
http_response_code($ok ? 200 : 503);
echo json_encode([
    'status' => $ok ? 'ok' : 'degraded',
    'db' => $db,
    'schema_version' => $schema,
    'schema_expected' => MIMIR_SCHEMA_VERSION,
    'meta_db' => basename(mimir_db_path()),
    'split_migrated' => basename(mimir_db_path()) === 'mimir-meta.sqlite',
    'circuit' => ['mode' => $circuit['mode'], 'since' => $circuit['since']],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
