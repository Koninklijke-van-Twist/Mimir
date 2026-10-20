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

function analytics_expected_htaccess(): string
{
    return "Options -Indexes\n"
        . "<FilesMatch \"\\.(sqlite|sqlite3|db)(-wal|-shm|-journal)?$\">\n"
        . "    <IfModule mod_authz_core.c>\n        Require all denied\n    </IfModule>\n"
        . "    <IfModule !mod_authz_core.c>\n        Order allow,deny\n        Deny from all\n    </IfModule>\n"
        . "</FilesMatch>\n";
}

function analytics_http_status(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if (!is_string($raw)) {
        analytics_fail('no response from ' . $url);
    }
    $parts = explode("\r\n\r\n", $raw, 2);

    return ['status' => $status, 'body' => $parts[1] ?? ''];
}

function analytics_assert_webserver_blocks_sqlite(string $source): void
{
    $apache = '';
    foreach (['/usr/sbin/apache2', '/usr/bin/apache2'] as $candidate) {
        if (is_executable($candidate)) {
            $apache = $candidate;
            break;
        }
    }
    if ($apache === '') {
        $apache = trim((string) shell_exec('command -v apache2'));
    }
    $phpModule = '';
    foreach (glob('/usr/lib/apache2/modules/libphp*.so') ?: [] as $candidate) {
        $phpModule = $candidate;
        break;
    }
    if ($apache === '' || $phpModule === '') {
        fwrite(STDERR, "apache check skipped\n");
        return;
    }

    $root = sys_get_temp_dir() . '/mimir-analytics-httpd-' . getmypid();
    $docroot = $root . '/htdocs';
    if (!mkdir($docroot, 0777, true) && !is_dir($docroot)) {
        analytics_fail('apache docroot');
    }
    copy($source, $docroot . '/analytics.php');
    analytics_run_endpoint($docroot . '/analytics.php', ['REQUEST_METHOD' => 'GET']);
    analytics_same((string) file_get_contents($docroot . '/.htaccess'), analytics_expected_htaccess(), 'served htaccess was written by analytics.php');
    $secret = 'sqlite-secret-' . bin2hex(random_bytes(8));
    foreach (['analytics.sqlite', 'analytics.sqlite-wal', 'analytics.sqlite-shm', 'analytics.sqlite-journal', 'notes.sqlite3', 'notes.db'] as $name) {
        file_put_contents($docroot . '/' . $name, $secret);
    }
    file_put_contents($docroot . '/rules.md', 'analytics contract');

    $port = 0;
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($socket === false) {
        analytics_fail('apache port: ' . $errstr);
    }
    $name = stream_socket_get_name($socket, false);
    $port = (int) substr((string) $name, strrpos((string) $name, ':') + 1);
    fclose($socket);

    $config = $root . '/httpd.conf';
    $configBody = <<<CONF
ServerRoot "/etc/apache2"
PidFile "{$root}/httpd.pid"
ErrorLog "{$root}/error.log"
Listen 127.0.0.1:{$port}
ServerName localhost
LoadModule mpm_prefork_module /usr/lib/apache2/modules/mod_mpm_prefork.so
LoadModule authz_core_module /usr/lib/apache2/modules/mod_authz_core.so
LoadModule authn_core_module /usr/lib/apache2/modules/mod_authn_core.so
LoadModule php_module {$phpModule}
LoadModule dir_module /usr/lib/apache2/modules/mod_dir.so
LoadModule mime_module /usr/lib/apache2/modules/mod_mime.so
LoadModule authz_host_module /usr/lib/apache2/modules/mod_authz_host.so
TypesConfig /etc/mime.types
DirectoryIndex disabled
DocumentRoot "{$docroot}"
<Directory "{$docroot}">
    AllowOverride All
    Require all granted
    Options FollowSymLinks
</Directory>
<FilesMatch \.php$>
    SetHandler application/x-httpd-php
</FilesMatch>
CONF;
    file_put_contents($config, $configBody);
    $pid = (int) trim((string) shell_exec(
        'setsid ' . escapeshellarg($apache) . ' -f ' . escapeshellarg($config)
        . ' -DFOREGROUND > ' . escapeshellarg($root . '/stdout.log')
        . ' 2> ' . escapeshellarg($root . '/stderr.log') . ' < /dev/null & echo $!'
    ));
    if ($pid < 1) {
        analytics_fail('apache did not start');
    }

    $base = 'http://127.0.0.1:' . $port;
    $ready = false;
    $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline) {
        $ch = curl_init($base . '/analytics.php');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 1, CURLOPT_CONNECTTIMEOUT => 1]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code > 0) {
            $ready = true;
            break;
        }
        usleep(50000);
    }
    if (!$ready) {
        $errorLog = is_file($root . '/error.log') ? (string) file_get_contents($root . '/error.log') : '';
        $stderr = is_file($root . '/stderr.log') ? (string) file_get_contents($root . '/stderr.log') : '';
        posix_kill($pid, SIGTERM);
        analytics_fail("apache not ready\n{$stderr}\n{$errorLog}");
    }

    $endpoint = analytics_http_status($base . '/analytics.php');
    if ($endpoint['status'] === 403) {
        analytics_fail('analytics.php is blocked by htaccess');
    }
    analytics_same($endpoint['status'], 401, 'analytics.php stays reachable: ' . $endpoint['body']);
    if (!str_contains($endpoint['body'], '"ok":false')) {
        analytics_fail('analytics.php did not return its JSON response: ' . $endpoint['body']);
    }

    foreach (['analytics.sqlite', 'analytics.sqlite-wal', 'analytics.sqlite-shm', 'analytics.sqlite-journal', 'notes.sqlite3', 'notes.db'] as $blocked) {
        $response = analytics_http_status($base . '/' . $blocked);
        analytics_same($response['status'], 403, $blocked . ' must be denied by the web server');
        if (str_contains($response['body'], $secret)) {
            analytics_fail($blocked . ' body leaked through the web server');
        }
    }

    $rules = analytics_http_status($base . '/rules.md');
    analytics_same($rules['status'], 200, 'non-sqlite files stay readable');

    posix_kill($pid, SIGTERM);
    foreach (array_merge(glob($docroot . '/*') ?: [], glob($docroot . '/.*') ?: []) as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    @rmdir($docroot);
    foreach (glob($root . '/*') ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    @rmdir($root);
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

$htaccess = $dir . '/.htaccess';
analytics_same(is_file($htaccess), true, 'missing htaccess is created before auth');
$htBody = (string) file_get_contents($htaccess);
analytics_same($htBody, analytics_expected_htaccess(), 'created htaccess is the Clio FilesMatch shield');
$shield = '/\.(sqlite|sqlite3|db)(-wal|-shm|-journal)?$/';
foreach (['analytics.sqlite', 'analytics.sqlite-wal', 'analytics.sqlite-shm', 'analytics.sqlite-journal', 'cache.sqlite3', 'cache.db', 'cache.db-journal'] as $blockedName) {
    if (preg_match($shield, $blockedName) !== 1) {
        analytics_fail($blockedName . ' is not covered by FilesMatch');
    }
}
foreach (['analytics.php', 'rules.md', '.htaccess'] as $openName) {
    if (preg_match($shield, $openName) === 1) {
        analytics_fail($openName . ' would be blocked by FilesMatch');
    }
}

file_put_contents($htaccess, "Options -Indexes\n");
analytics_run_endpoint($script, ['REQUEST_METHOD' => 'GET']);
$appended = (string) file_get_contents($htaccess);
if (!str_starts_with($appended, "Options -Indexes\n\n# kvt_protect_dirs\n")) {
    analytics_fail('existing htaccess without sqlite was replaced instead of extended');
}
if (substr_count($appended, '<FilesMatch') !== 1) {
    analytics_fail('sqlite shield was not appended once');
}

$already = "Options -Indexes\n" . analytics_expected_htaccess();
file_put_contents($htaccess, $already);
analytics_run_endpoint($script, ['REQUEST_METHOD' => 'GET']);
analytics_same((string) file_get_contents($htaccess), $already, 'htaccess that already mentions sqlite stays untouched');

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

foreach (array_merge(glob($dir . '/*') ?: [], glob($dir . '/.*') ?: []) as $file) {
    if (is_file($file)) {
        @unlink($file);
    }
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

analytics_assert_webserver_blocks_sqlite($source);

fwrite(STDOUT, "ok\n");
