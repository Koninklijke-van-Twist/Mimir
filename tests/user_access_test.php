<?php

declare(strict_types=1);

/**
 * Run: php tests/user_access_test.php
 */

require dirname(__DIR__) . '/web/lib/UserAccess.php';
require dirname(__DIR__) . '/web/lib/AllowedUsersStore.php';

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function assert_same($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        fail($label . ' verwacht ' . var_export($expected, true) . ' kreeg ' . var_export($actual, true));
    }
}

assert_same(true, kvt_email_is_admin('Opesket@kvt.nl'), 'admin opesket');
assert_same(true, kvt_email_is_admin('tfalken@kvt.nl'), 'admin tfalken');
assert_same(true, kvt_email_is_admin('milanscheenloop@kvt.nl'), 'admin milan');
assert_same(false, kvt_email_is_admin('tim@kvt.nl'), 'geen admin');

$allowed = ['analyst@kvt.nl', 'planner@kvt.nl' => true];
assert_same(true, kvt_email_is_allowed('analyst@kvt.nl', $allowed, true), 'op lijst');
assert_same(false, kvt_email_is_allowed('x@kvt.nl', $allowed, true), 'niet op lijst');
assert_same(true, kvt_email_is_allowed('x@kvt.nl', [], true), 'lege open');
assert_same(false, kvt_email_is_allowed('x@kvt.nl', [], false), 'lege dicht');
assert_same(true, kvt_email_may_access('opesket@kvt.nl', [], ['niemand@kvt.nl'], false), 'admin altijd');
assert_same(true, kvt_email_may_access('nieuw@kvt.nl', ['nieuw@kvt.nl'], $allowed, false), 'store');
assert_same(false, kvt_email_may_access('analyst@kvt.nl', ['nieuw@kvt.nl'], $allowed, false), 'store wint');

$db = sys_get_temp_dir() . '/kvt-access-' . bin2hex(random_bytes(4)) . '.sqlite';
@unlink($db);
$store = new AllowedUsersStore($db);
assert_same(2, $store->seedAllowedUsers(['A@kvt.nl', 'b@kvt.nl', 'nope'], 'auth.php'), 'seed');
assert_same(true, $store->addAllowedUser('c@kvt.nl', 'opesket@kvt.nl'), 'add');
assert_same(true, $store->removeAllowedUser('b@kvt.nl'), 'remove');
assert_same(['a@kvt.nl', 'c@kvt.nl'], $store->listAllowedEmails(), 'list');
@unlink($db);

$root = dirname(__DIR__);
$login = (string) file_get_contents($root . '/web/logincheck.php');
if (strpos($login, 'kvt_access_decision') === false) {
    fail('logincheck moet kvt_access_decision gebruiken');
}
if (strpos($login, 'AllowedUsersStore') === false) {
    fail('logincheck moet AllowedUsersStore laden');
}
if (!is_file($root . '/web/admin_users.php')) {
    fail('admin_users.php ontbreekt');
}

echo "OK user access\n";
