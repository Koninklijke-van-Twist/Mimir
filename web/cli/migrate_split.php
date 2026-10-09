<?php

declare(strict_types=1);

/**
 * Eenmalig, op de server, als de user die ook web/data beheert:
 *   php web/cli/migrate_split.php            (of: php /var/www/html/mimir/cli/migrate_split.php)
 * Optioneel: --data=/pad/naar/data  --settle=10
 * Alleen CLI; via het web geeft dit 403.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once dirname(__DIR__) . '/mimir_split.php';

$options = getopt('', ['data::', 'settle::']);
$dataDir = rtrim((string) ($options['data'] ?? dirname(__DIR__) . '/data'), '/');
$settle = isset($options['settle']) ? max(0, (int) $options['settle']) : 10;
$GLOBALS['mimir_runtime_dir'] = $dataDir;

try {
    $result = mimir_split_migrate($dataDir, $settle, static function (string $line): void {
        fwrite(STDOUT, $line . "\n");
    });
} catch (Throwable $error) {
    fwrite(STDERR, 'FOUT: ' . $error->getMessage() . "\n");
    exit(1);
}
if (!$result['ok']) {
    fwrite(STDERR, "FOUT: inhaalronde niet volledig; draai het commando opnieuw (idempotent).\n");
    exit(2);
}
fwrite(STDOUT, "Klaar. Controleer api/health.php en de sleutelpagina; daarna mag data/mimir.sqlite (+ -wal/-shm) weg.\n");
