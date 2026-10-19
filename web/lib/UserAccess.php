<?php

declare(strict_types=1);

/**
 * Vaste admins voor allowlist-beheer + effectieve toegang.
 * Beheer is beperkt tot deze accounts; die zijn niet via de UI te wijzigen.
 */

/**
 * @return list<string>
 */
function kvt_admin_emails(): array
{
    return [
        'opesket@kvt.nl',
        'tfalken@kvt.nl',
        'milanscheenloop@kvt.nl',
    ];
}

function kvt_email_is_admin(string $email): bool
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return false;
    }

    return in_array($email, kvt_admin_emails(), true);
}

/**
 * @param mixed $allowedUsers
 * @return list<string>
 */
function kvt_normalize_allowed_emails(mixed $allowedUsers): array
{
    if (!is_array($allowedUsers) || $allowedUsers === []) {
        return [];
    }

    $emails = [];
    foreach ($allowedUsers as $emailKey => $value) {
        $allowedEmail = is_int($emailKey) ? (string) $value : (string) $emailKey;
        $allowedEmail = strtolower(trim($allowedEmail));
        if ($allowedEmail === '' || !filter_var($allowedEmail, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $emails[$allowedEmail] = true;
    }

    return array_keys($emails);
}

/**
 * Auth.php-semantiek: lege/$allowedUsers ontbreekt → $emptyMeansAllow.
 *
 * @param mixed $allowedUsers
 */
function kvt_email_is_allowed(string $email, mixed $allowedUsers, bool $emptyMeansAllow = true): bool
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return false;
    }
    if (kvt_email_is_admin($email)) {
        return true;
    }
    if (!is_array($allowedUsers) || $allowedUsers === []) {
        return $emptyMeansAllow;
    }

    foreach ($allowedUsers as $emailKey => $value) {
        $allowedEmail = is_int($emailKey) ? (string) $value : (string) $emailKey;
        if (strtolower(trim($allowedEmail)) === $email) {
            return true;
        }
    }

    return false;
}

/**
 * Effectieve toegang: vaste admins altijd; gevulde SQLite-allowlist is leidend;
 * anders auth.php ($allowedUsers) met de app-eigen lege-lijst-semantiek.
 *
 * @param list<string> $managedEmails
 * @param mixed $authAllowedUsers
 */
function kvt_email_may_access(
    string $email,
    array $managedEmails,
    mixed $authAllowedUsers,
    bool $emptyAuthMeansAllow = true
): bool {
    $email = strtolower(trim($email));
    if ($email === '') {
        return false;
    }
    if (kvt_email_is_admin($email)) {
        return true;
    }
    if ($managedEmails !== []) {
        return in_array($email, $managedEmails, true);
    }

    return kvt_email_is_allowed($email, $authAllowedUsers, $emptyAuthMeansAllow);
}
