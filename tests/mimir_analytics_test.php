<?php

declare(strict_types=1);

function analytics_fail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function analytics_same(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) {
        analytics_fail($message . ' expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }
}

function analytics_run_endpoint(string $script, array $server, array $get = [], array $post = []): array
{
    $runner = tempnam(sys_get_temp_dir(), 'mimir-analytics-run-');
    if ($runner === false) {
        analytics_fail('temp runner');
    }
    $statusFile = $runner . '.status';
    $code = '<?php' . "\n"
        . 'function verify_rotating_api_key(string $oid, string $key): bool { return $oid === \'oid-1\' && $key === \'good-key\'; }' . "\n"
        . 'register_shutdown_function(static function (): void { file_put_contents(' . var_export($statusFile, true) . ', (string) http_response_code()); });' . "\n"
        . '$_SERVER = ' . var_export($server, true) . ';' . "\n"
        . '$_GET = ' . var_export($get, true) . ';' . "\n"
        . '$_POST = ' . var_export($post, true) . ';' . "\n"
        . 'require ' . var_export($script, true) . ';' . "\n";
    file_put_contents($runner, $code);
    $output = [];
    $exit = 0;
    exec('php ' . escapeshellarg($runner) . ' 2>&1', $output, $exit);
    $body = implode("\n", $output);
    $status = is_file($statusFile) ? (int) file_get_contents($statusFile) : 0;
    @unlink($runner);
    @unlink($statusFile);
    if ($exit !== 0 && $status === 0) {
        analytics_fail('endpoint process failed: ' . $body);
    }

    $decoded = json_decode($body, true);

    return ['status' => $status, 'body' => $body, 'json' => is_array($decoded) ? $decoded : null];
}

$root = dirname(__DIR__);
$source = $root . '/web/analytics/analytics.php';
$rules = $root . '/web/analytics/rules.md';
if (!is_file($source) || !is_file($rules)) {
    analytics_fail('analytics endpoint or rules.md missing');
}
$php = file_get_contents($source);
if ($php === false || str_contains($php, 'kvt_protect_dirs.php')) {
    analytics_fail('this repo has no kvt_protect_dirs.php, so analytics.php must be the Elpis variant');
}
if (!str_contains($php, "const ANALYTICS_DB_PATH")) {
    analytics_fail('analytics.php is not the KVT endpoint');
}
$rulesText = file_get_contents($rules);
if ($rulesText === false || !str_contains($rulesText, 'CREATE TABLE visits')) {
    analytics_fail('rules.md is not the Clio contract');
}

$dir = sys_get_temp_dir() . '/mimir-analytics-' . getmypid();
if (!mkdir($dir) && !is_dir($dir)) {
    analytics_fail('temp analytics dir');
}
$script = $dir . '/analytics.php';
copy($source, $script);
$db = $dir . '/analytics.sqlite';

$unauthorized = analytics_run_endpoint($script, ['REQUEST_METHOD' => 'GET']);
analytics_same($unauthorized['status'], 401, 'missing credentials');
analytics_same($unauthorized['json']['ok'] ?? null, false, 'missing credentials json');
analytics_same(is_file($db), false, 'unauthorized call does not create sqlite');

$method = analytics_run_endpoint($script, ['REQUEST_METHOD' => 'PUT']);
analytics_same($method['status'], 405, 'method not allowed');

$ok = analytics_run_endpoint($script, [
    'REQUEST_METHOD' => 'GET',
    'HTTP_X_API_KEY' => 'good-key',
], [
    'user_email' => 'User@KVT.nl',
    'oid' => 'OID-1',
]);
analytics_same($ok['status'], 200, 'authorized visit');
analytics_same($ok['json']['ok'] ?? null, true, 'authorized visit json');

$pdo = new PDO('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$rows = $pdo->query('SELECT visited_at, user_email FROM visits')->fetchAll(PDO::FETCH_ASSOC);
analytics_same(count($rows), 1, 'one visit row');
analytics_same($rows[0]['user_email'], 'user@kvt.nl', 'email stored lowercase');
analytics_same(ctype_digit((string) $rows[0]['visited_at']), true, 'visited_at is an integer timestamp');

$again = analytics_run_endpoint($script, [
    'REQUEST_METHOD' => 'POST',
    'HTTP_X_OID' => 'oid-1',
], [], [
    'user_email' => 'user@kvt.nl',
    'api_key' => 'good-key',
]);
analytics_same($again['status'], 200, 'duplicate within 60s still ok');
analytics_same((int) $pdo->query('SELECT COUNT(*) FROM visits')->fetchColumn(), 1, 'duplicate within 60s is not inserted');
$pdo = null;

$legacy = $dir . '/legacy.sqlite';
$legacyPdo = new PDO('sqlite:' . $legacy);
$legacyPdo->exec('CREATE TABLE visits (id INTEGER PRIMARY KEY AUTOINCREMENT, visited_at TEXT NOT NULL, user_email TEXT NOT NULL)');
$legacyPdo->exec("INSERT INTO visits (visited_at, user_email) VALUES ('2020-01-02 03:04:05', 'Oud@KVT.nl')");
$legacyPdo = null;
copy($legacy, $db);
$migrated = analytics_run_endpoint($script, [
    'REQUEST_METHOD' => 'GET',
    'HTTP_X_API_KEY' => 'good-key',
], [
    'user_email' => 'nieuw@kvt.nl',
    'oid' => 'oid-1',
]);
analytics_same($migrated['status'], 200, 'visit after legacy migration');
$pdo = new PDO('sqlite:' . $db);
$type = '';
foreach ($pdo->query('PRAGMA table_info(visits)') as $column) {
    if (strtolower((string) $column['name']) === 'visited_at') {
        $type = strtoupper((string) $column['type']);
    }
}
analytics_same($type, 'INTEGER', 'visited_at migrated to integer');
analytics_same((int) $pdo->query('SELECT COUNT(*) FROM visits')->fetchColumn(), 2, 'legacy row kept and new row added');
$pdo = null;

foreach (glob($dir . '/*') ?: [] as $file) {
    @unlink($file);
}
@rmdir($dir);

$loginDir = $root . '/login';
$lib = $loginDir . '/lib.php';
$forbidden = $loginDir . '/403.php';
$createdLogin = !is_dir($loginDir);
if (!is_dir($loginDir) && !mkdir($loginDir) && !is_dir($loginDir)) {
    analytics_fail('login stub dir');
}
file_put_contents($lib, <<<'PHP'
<?php
$allowedUsers = ['browser@kvt.nl'];
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['user'] = [
    'email' => 'browser@kvt.nl',
    'api_key' => 'rotating-login-key',
    'oid' => 'oid-9',
];
PHP);
file_put_contents($forbidden, "<?php\nhttp_response_code(403);\necho 'forbidden';\n");

$portFile = tempnam(sys_get_temp_dir(), 'mimir-analytics-port-');
$hitFile = tempnam(sys_get_temp_dir(), 'mimir-analytics-hits-');
file_put_contents($hitFile, '');
$serverScript = tempnam(sys_get_temp_dir(), 'mimir-analytics-httpd-');
file_put_contents($serverScript, <<<'PHP'
<?php
$address = '127.0.0.1';
$socket = stream_socket_server('tcp://' . $address . ':0', $errno, $errstr);
if ($socket === false) {
    fwrite(STDERR, $errstr);
    exit(1);
}
$name = stream_socket_get_name($socket, false);
$port = (int) substr((string) $name, strrpos((string) $name, ':') + 1);
file_put_contents($argv[1], (string) $port);
while (is_resource($socket)) {
    $client = @stream_socket_accept($socket, 5);
    if ($client === false) {
        continue;
    }
    $request = '';
    while (!str_contains($request, "\r\n\r\n")) {
        $chunk = fread($client, 1024);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $request .= $chunk;
        if (strlen($request) > 8192) {
            break;
        }
    }
    file_put_contents($argv[2], $request . "\n---\n", FILE_APPEND);
    fwrite($client, "HTTP/1.1 204 No Content\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    fclose($client);
}
PHP);
$server = proc_open(
    [PHP_BINARY, $serverScript, $portFile, $hitFile],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
);
if (!is_resource($server)) {
    analytics_fail('analytics listener');
}
$deadline = microtime(true) + 3;
$port = 0;
while (microtime(true) < $deadline) {
    $raw = trim((string) file_get_contents($portFile));
    if ($raw !== '') {
        $port = (int) $raw;
        break;
    }
    usleep(20000);
}
if ($port < 1) {
    proc_terminate($server);
    analytics_fail('listener port');
}

$runLogin = static function (array $server) use ($root): array {
    $runner = tempnam(sys_get_temp_dir(), 'mimir-logincheck-run-');
    if ($runner === false) {
        analytics_fail('login runner');
    }
    $code = '<?php' . "\n"
        . '$_SERVER = ' . var_export($server, true) . ';' . "\n"
        . 'require ' . var_export($root . '/web/logincheck.php', true) . ';' . "\n"
        . 'echo mimir_logincheck_request_has_api_key() ? "api-key\n" : "browser\n";' . "\n";
    file_put_contents($runner, $code);
    $output = [];
    $exit = 0;
    exec('php ' . escapeshellarg($runner) . ' 2>&1', $output, $exit);
    @unlink($runner);
    if ($exit !== 0) {
        analytics_fail('logincheck failed: ' . implode("\n", $output));
    }

    return $output;
};

$baseServer = [
    'REMOTE_ADDR' => '203.0.113.8',
    'SERVER_ADDR' => '10.0.0.8',
    'HTTP_HOST' => '127.0.0.1:' . $port,
    'SCRIPT_NAME' => '/index.php',
    'HTTPS' => 'off',
];

file_put_contents($hitFile, '');
$browser = $runLogin($baseServer);
analytics_same(implode("\n", $browser), 'browser', 'session without api key is a browser user');
$browserHits = (string) file_get_contents($hitFile);
if (!str_contains($browserHits, 'user_email=browser%40kvt.nl')) {
    analytics_fail('browser login did not call analytics: ' . $browserHits);
}
if (!str_contains($browserHits, 'api_key=rotating-login-key')) {
    analytics_fail('browser call does not match Clio query: ' . $browserHits);
}
if (!str_contains($browserHits, 'oid=oid-9')) {
    analytics_fail('browser call missing oid: ' . $browserHits);
}
if (!str_contains($browserHits, 'X-API-Key: rotating-login-key')) {
    analytics_fail('browser call missing X-API-Key header: ' . $browserHits);
}

file_put_contents($hitFile, '');
$keyCall = $runLogin($baseServer + ['HTTP_X_API_KEY' => 'mimir-secret']);
analytics_same(implode("\n", $keyCall), 'api-key', 'X-API-Key is a Mimir API call');
analytics_same(trim((string) file_get_contents($hitFile)), '', 'X-API-Key call must not hit analytics');

file_put_contents($hitFile, '');
$bearer = $runLogin($baseServer + ['HTTP_AUTHORIZATION' => 'Bearer mimir-secret']);
analytics_same(implode("\n", $bearer), 'api-key', 'Bearer token is a Mimir API call');
analytics_same(trim((string) file_get_contents($hitFile)), '', 'Bearer call must not hit analytics');

$trusted = $runLogin([
    'REMOTE_ADDR' => '127.0.0.1',
    'SERVER_ADDR' => '10.0.0.8',
    'HTTP_HOST' => '127.0.0.1:' . $port,
    'SCRIPT_NAME' => '/index.php',
]);
analytics_same(implode("\n", $trusted), 'browser', 'trusted requester does not invent an api key');
if (str_contains((string) file_get_contents($hitFile), 'analytics.php')) {
    analytics_fail('trusted requester must not call analytics');
}

proc_terminate($server);
proc_close($server);
foreach ($pipes as $pipe) {
    if (is_resource($pipe)) {
        fclose($pipe);
    }
}
@unlink($lib);
@unlink($forbidden);
if ($createdLogin) {
    @rmdir($loginDir);
}
@unlink($portFile);
@unlink($hitFile);
@unlink($serverScript);

fwrite(STDOUT, "ok\n");
