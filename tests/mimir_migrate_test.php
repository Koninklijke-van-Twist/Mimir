<?php

declare(strict_types=1);

/**
 * Migratie met schemaversie en lock, en een lichte probe die het circuit
 * na een deploy direct sluit.
 */

require_once dirname(__DIR__) . '/web/mimir_store.php';

function mimir_mig_same(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) {
        fwrite(STDERR, 'FAIL: ' . $message . ' expected ' . var_export($expected, true) . ' got ' . var_export($actual, true) . "\n");
        exit(1);
    }
}

$root = sys_get_temp_dir() . '/mimir-mig-' . bin2hex(random_bytes(4));
mkdir($root, 0777, true);
$GLOBALS['mimir_runtime_dir'] = $root;
$path = $root . '/mimir.sqlite';
$GLOBALS['mimir_reliability_db_path'] = $path;

// Oude database (versie 0, zonder can_write/kind/write_log).
$legacy = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$legacy->exec('PRAGMA journal_mode = WAL');
$legacy->exec('CREATE TABLE api_keys (id INTEGER PRIMARY KEY AUTOINCREMENT, owner_email TEXT NOT NULL, label TEXT NOT NULL, key_plain TEXT NOT NULL, key_hash TEXT NOT NULL UNIQUE, created_at INTEGER NOT NULL, revoked_at INTEGER)');
$legacy->exec('CREATE TABLE api_usage (id INTEGER PRIMARY KEY AUTOINCREMENT, key_id INTEGER NOT NULL, endpoint TEXT NOT NULL, called_at INTEGER NOT NULL)');
$legacy = null;

// Twee "requests" openen tegelijk: de tweede heeft al een verbinding vóór de migratie.
$second = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$second->exec('PRAGMA busy_timeout = 3000');
$first = mimir_db($path);
mimir_mig_same(mimir_schema_version($first), MIMIR_SCHEMA_VERSION, 'first open migrates to the current version');
mimir_migrate($second); // zag nog versie 0; mag niet "duplicate column" geven
mimir_mig_same(mimir_schema_version($second), MIMIR_SCHEMA_VERSION, 'second connection sees the version');
mimir_mig_same(in_array('can_write', mimir_sqlite_columns($second, 'api_keys'), true), true, 'can_write exists once');

// Op de huidige versie: geen DDL meer. Een vreemde write-lock blokkeert het openen niet.
$blocker = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$blocker->exec('BEGIN IMMEDIATE');
$started = microtime(true);
$third = mimir_db($path, 200);
mimir_mig_same(mimir_schema_version($third), MIMIR_SCHEMA_VERSION, 'open while another process holds the write lock');
mimir_mig_same(microtime(true) - $started < 1.0, true, 'no waiting on the lock when the schema is current');
$blocker->exec('ROLLBACK');

// Circuit door een trage start open: probe sluit het meteen.
mimir_circuit_trip('test: trage start na deploy');
mimir_mig_same(mimir_circuit_is_open(), true, 'circuit open');
$GLOBALS['mimir_probe_interval'] = 0;
mimir_mig_same(mimir_circuit_should_bypass(), false, 'healthy probe means no bypass');
mimir_mig_same(mimir_circuit_is_open(), false, 'probe closed the circuit');

exec('rm -rf ' . escapeshellarg($root));
fwrite(STDOUT, "ok\n");
