<?php

declare(strict_types=1);

/**
 * SQLite-allowlist voor app-toegang (los van auth.php-secrets).
 */
final class AllowedUsersStore
{
    private PDO $pdo;

    public function __construct(string $sqlitePath)
    {
        $dir = dirname($sqlitePath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Data-map kon niet worden aangemaakt.');
        }

        $this->pdo = new PDO('sqlite:' . $sqlitePath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA busy_timeout = 3000');
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS allowed_users (
                email TEXT PRIMARY KEY,
                added_at TEXT NOT NULL,
                added_by TEXT NOT NULL
            )'
        );
    }

    /**
     * @return list<string>
     */
    public function listAllowedEmails(): array
    {
        $stmt = $this->pdo->query(
            'SELECT email FROM allowed_users ORDER BY email COLLATE NOCASE'
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        $emails = [];
        foreach ($rows as $row) {
            if (!is_string($row)) {
                continue;
            }
            $email = strtolower(trim($row));
            if ($email !== '') {
                $emails[] = $email;
            }
        }

        return $emails;
    }

    /**
     * @return list<array{email:string,added_at:string,added_by:string}>
     */
    public function listAllowedUsers(): array
    {
        $stmt = $this->pdo->query(
            'SELECT email, added_at, added_by
             FROM allowed_users
             ORDER BY email COLLATE NOCASE'
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            if ($email === '') {
                continue;
            }
            $out[] = [
                'email' => $email,
                'added_at' => (string) ($row['added_at'] ?? ''),
                'added_by' => strtolower(trim((string) ($row['added_by'] ?? ''))),
            ];
        }

        return $out;
    }

    /**
     * @param list<string> $emails
     */
    public function seedAllowedUsers(array $emails, string $addedBy): int
    {
        $added = 0;
        foreach ($emails as $email) {
            $normalized = strtolower(trim((string) $email));
            if ($normalized === '' || !filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            if ($this->addAllowedUser($normalized, $addedBy)) {
                $added++;
            }
        }

        return $added;
    }

    public function addAllowedUser(string $email, string $addedBy): bool
    {
        $email = strtolower(trim($email));
        $addedBy = strtolower(trim($addedBy));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Ongeldig e-mailadres.');
        }
        if ($addedBy === '') {
            $addedBy = 'unknown';
        }
        $stmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO allowed_users (email, added_at, added_by)
             VALUES (:e, :a, :b)'
        );
        $stmt->execute([
            ':e' => $email,
            ':a' => gmdate('c'),
            ':b' => $addedBy,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function removeAllowedUser(string $email): bool
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return false;
        }
        $stmt = $this->pdo->prepare('DELETE FROM allowed_users WHERE email = :e');
        $stmt->execute([':e' => $email]);

        return $stmt->rowCount() > 0;
    }
}
