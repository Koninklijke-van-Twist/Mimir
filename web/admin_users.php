<?php

declare(strict_types=1);

/**
 * Gebruikersbeheer — alleen vaste admins (zie UserAccess.php).
 * Verwacht: auth.php + logincheck.php al geladen door de aanroeper, OF
 * deze file laadt ze zelf wanneer APP_BOOTSTRAP niet gezet is.
 */

if (!defined('KVT_ADMIN_USERS_BOOTSTRAPPED')) {
    require __DIR__ . '/auth.php';
    require __DIR__ . '/logincheck.php';
    define('KVT_ADMIN_USERS_BOOTSTRAPPED', true);
}

require_once __DIR__ . '/lib/UserAccess.php';
require_once __DIR__ . '/lib/AllowedUsersStore.php';

function kvt_admin_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function kvt_admin_session_email(): string
{
    $user = $_SESSION['user'] ?? null;
    if (!is_array($user)) {
        // logincheck kan session_write_close hebben gedaan; lees bag
        if (function_exists('session_status') && session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $user = $_SESSION['user'] ?? null;
    }
    $email = '';
    if (is_array($user)) {
        $email = strtolower(trim((string) ($user['email'] ?? '')));
    }

    return $email;
}

$appName = $GLOBALS['KVT_APP_NAME'] ?? basename(dirname(__DIR__));
$emptyAuthMeansAllow = (bool) ($GLOBALS['KVT_EMPTY_AUTH_MEANS_ALLOW'] ?? true);
$flashError = '';
$flashOk = '';
$adminUsers = [];

try {
    $email = kvt_admin_session_email();
    if (!kvt_email_is_admin($email)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Alleen vaste admins mogen gebruikersbeheer openen.';
        exit;
    }

    $store = new AllowedUsersStore(__DIR__ . '/data/access.sqlite');
    if ($store->listAllowedEmails() === []) {
        $seed = kvt_normalize_allowed_emails($allowedUsers ?? null);
        if ($seed !== []) {
            $store->seedAllowedUsers($seed, 'auth.php');
        }
    }

    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'admin_add_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $newEmail = strtolower(trim((string) ($_POST['email'] ?? '')));
        if ($store->addAllowedUser($newEmail, $email)) {
            $flashOk = $newEmail . ' heeft nu toegang.';
        } else {
            $flashOk = $newEmail . ' stond al op de lijst.';
        }
    }
    if ($action === 'admin_remove_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $removeEmail = strtolower(trim((string) ($_POST['email'] ?? '')));
        if (kvt_email_is_admin($removeEmail)) {
            throw new InvalidArgumentException('Vaste admins kunnen niet worden verwijderd.');
        }
        if ($store->removeAllowedUser($removeEmail)) {
            $flashOk = $removeEmail . ' heeft geen toegang meer.';
        } else {
            $flashOk = $removeEmail . ' stond niet op de lijst.';
        }
    }

    $adminUsers = $store->listAllowedUsers();
} catch (Throwable $e) {
    $flashError = $e->getMessage();
}

?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= kvt_admin_h((string) $appName) ?> — Gebruikersbeheer</title>
  <style>
    :root { --ink:#1f2937; --muted:#64748b; --line:#dbe3ee; --accent:#1f4ea6; --err:#b91c1c; --ok:#15803d; --bg:#eef2f7; }
    body { margin:0; font-family: Verdana, Geneva, Tahoma, sans-serif; background:var(--bg); color:var(--ink); }
    .wrap { max-width:820px; margin:0 auto; padding:28px 20px 64px; }
    h1 { margin:0 0 8px; font-size:24px; color:var(--accent); }
    .muted { color:var(--muted); font-size:13px; }
    .card { background:#fff; border:1px solid var(--line); border-radius:12px; padding:20px; margin-top:16px; }
    .banner { border-radius:8px; padding:10px 12px; margin:12px 0; font-size:14px; }
    .banner.err { background:#fef2f2; color:var(--err); border:1px solid #fecaca; }
    .banner.ok { background:#f0fdf4; color:var(--ok); border:1px solid #bbf7d0; }
    table { width:100%; border-collapse:collapse; font-size:13px; }
    th, td { padding:8px; border-bottom:1px solid var(--line); text-align:left; }
    code { font-family: ui-monospace, Menlo, Consolas, monospace; font-size:0.92em; }
    input[type=email] { width:100%; max-width:360px; padding:9px 10px; border:1px solid var(--line); border-radius:8px; font:inherit; }
    button, .btn { appearance:none; border:1px solid var(--accent); background:var(--accent); color:#fff; border-radius:8px; padding:9px 14px; font:inherit; cursor:pointer; text-decoration:none; display:inline-block; }
    button.danger { background:var(--err); border-color:var(--err); }
    .actions { margin-top:12px; display:flex; gap:10px; flex-wrap:wrap; }
    .inline-form { display:inline; margin:0; }
    ul.admins { list-style:none; padding:0; }
    ul.admins li { padding:6px 0; border-bottom:1px solid var(--line); }
  </style>
</head>
<body>
<div class="wrap">
  <a class="btn" href="index.php">← Terug</a>
  <h1>Gebruikersbeheer</h1>
  <p class="muted"><?= kvt_admin_h((string) $appName) ?> · <?= kvt_admin_h(kvt_admin_session_email()) ?></p>

  <?php if ($flashError !== ''): ?>
    <div class="banner err"><?= kvt_admin_h($flashError) ?></div>
  <?php endif; ?>
  <?php if ($flashOk !== ''): ?>
    <div class="banner ok"><?= kvt_admin_h($flashOk) ?></div>
  <?php endif; ?>

  <div class="card">
    <p class="muted">
      Alleen vaste admins (<?= kvt_admin_h(implode(', ', kvt_admin_emails())) ?>)
      kunnen deze lijst wijzigen. Zodra hier gebruikers staan, geldt alleen deze
      lijst plus de vaste admins<?= $emptyAuthMeansAllow ? ' — niet meer de open fallback uit auth.php' : '' ?>.
    </p>

    <h2>Vaste admins</h2>
    <ul class="admins">
      <?php foreach (kvt_admin_emails() as $adminEmail): ?>
        <li><code><?= kvt_admin_h($adminEmail) ?></code> <span class="muted">altijd toegang · niet verwijderbaar</span></li>
      <?php endforeach; ?>
    </ul>

    <h2>Overige toegang</h2>
    <?php if ($adminUsers === []): ?>
      <p class="muted">Nog geen extra gebruikers.</p>
    <?php else: ?>
      <table>
        <thead><tr><th>E-mail</th><th>Toegevoegd</th><th>Door</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($adminUsers as $row): ?>
          <tr>
            <td><code><?= kvt_admin_h($row['email']) ?></code></td>
            <td class="muted"><?= kvt_admin_h($row['added_at']) ?></td>
            <td class="muted"><?= kvt_admin_h($row['added_by']) ?></td>
            <td>
              <?php if (kvt_email_is_admin($row['email'])): ?>
                <span class="muted">admin</span>
              <?php else: ?>
                <form method="post" class="inline-form" onsubmit="return confirm('Toegang intrekken voor <?= kvt_admin_h($row['email']) ?>?');">
                  <input type="hidden" name="email" value="<?= kvt_admin_h($row['email']) ?>">
                  <button type="submit" name="action" value="admin_remove_user" class="danger">Intrekken</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <form method="post" style="margin-top:16px">
      <label class="muted" for="email">E-mailadres toevoegen</label><br>
      <input type="email" name="email" id="email" required placeholder="collega@kvt.nl" autocomplete="off">
      <div class="actions">
        <button type="submit" name="action" value="admin_add_user">Toegang verlenen</button>
      </div>
    </form>
  </div>
</div>
</body>
</html>
