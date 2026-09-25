<?php

if (!function_exists('array_any')) {
    function array_any(array $array, callable $callback): bool
    {
        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return true;
            }
        }

        return false;
    }
}

function is_trusted_requester(): bool
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    $server = $_SERVER['SERVER_ADDR'] ?? '';
    $trusted = ['127.0.0.1', '::1'];
    if ($remote === $server && $remote !== '') {
        return true;
    }
    if (in_array($remote, $trusted, true)) {
        return true;
    }
    return false;
}

if (is_trusted_requester()) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }

    $currentEmail = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
    $defaultAllowedUser = strtolower(trim((string) ($allowedUsers[0] ?? '')));
    if ($currentEmail === '' && $defaultAllowedUser !== '') {
        if (!is_array($_SESSION['user'] ?? null)) {
            $_SESSION['user'] = [];
        }

        $_SESSION['user']['email'] = $defaultAllowedUser;
    }
}

if (!is_trusted_requester()) {
    require __DIR__ . "/../login/lib.php";

    if (
        !array_any($allowedUsers, function ($email) {
            return strtolower((string) $email) === strtolower((string) ($_SESSION['user']['email'] ?? ''));
        })
    ) {
        require __DIR__ . "/../login/403.php";
        die();
    }

    $analyticsEmail = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
    $analyticsApiKey = trim((string) ($_SESSION['user']['api_key'] ?? ''));
    $analyticsOid = strtolower(trim((string) ($_SESSION['user']['oid'] ?? '')));
    if ($analyticsEmail !== '' && $analyticsApiKey !== '' && $analyticsOid !== '') {
        $httpsFlag = strtolower(trim((string) ($_SERVER['HTTPS'] ?? '')));
        $requestScheme = strtolower(trim((string) ($_SERVER['REQUEST_SCHEME'] ?? '')));
        $httpsOn = ($httpsFlag !== '' && $httpsFlag !== 'off')
            || $requestScheme === 'https'
            || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
        if (!$httpsOn) {
            return;
        }
        $analyticsScheme = 'https';
        $analyticsHost = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $analyticsBase = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
        $analyticsUrl = $analyticsScheme . '://' . $analyticsHost . $analyticsBase . '/analytics/analytics.php?' . http_build_query([
            'user_email' => $analyticsEmail,
            'oid' => $analyticsOid,
        ], '', '&', PHP_QUERY_RFC3986);

        if (function_exists('curl_init')) {
            $analyticsCurl = curl_init($analyticsUrl);
            curl_setopt_array($analyticsCurl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 1,
                CURLOPT_TIMEOUT => 1,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => ['X-API-Key: ' . $analyticsApiKey],
            ]);
            curl_exec($analyticsCurl);
            curl_close($analyticsCurl);
        }
    }
}