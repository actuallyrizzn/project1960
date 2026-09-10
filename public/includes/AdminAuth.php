<?php
declare(strict_types=1);

namespace Project1960;

use PDO;

/**
 * Session auth for /admin against admin_users (Environment-shaped, AD-S2).
 * API-key auth lands in AD-S3.
 */
final class AdminAuth
{
    private const SESSION_USER_KEY = 'admin_user_id';
    private const SESSION_ACTIVITY_KEY = 'admin_last_activity';

    private ?array $user = null;

    public function __construct(private PDO $pdo)
    {
        $this->ensureSession();
        $this->hydrateFromSession();
    }

    public function login(string $usernameOrEmail, string $password): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM admin_users
             WHERE (username = :u OR email = :u) AND is_active = 1
             LIMIT 1'
        );
        $stmt->execute([':u' => $usernameOrEmail]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !password_verify($password, (string) $row['password_hash'])) {
            return false;
        }

        $this->user = $row;
        $_SESSION[self::SESSION_USER_KEY] = (int) $row['id'];
        $_SESSION[self::SESSION_ACTIVITY_KEY] = time();

        return true;
    }

    public function logout(): void
    {
        $this->user = null;
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION[self::SESSION_USER_KEY], $_SESSION[self::SESSION_ACTIVITY_KEY]);
            // Keep csrf_token so a logout form POST can still be validated mid-flow if needed
        }
    }

    public function check(): bool
    {
        return $this->user !== null;
    }

    /** @return array<string, mixed>|null */
    public function user(): ?array
    {
        return $this->user;
    }

    public function userId(): ?int
    {
        return $this->user !== null ? (int) $this->user['id'] : null;
    }

    public function role(): ?string
    {
        return $this->user !== null ? (string) $this->user['role'] : null;
    }

    public function isOperator(): bool
    {
        return $this->role() === 'operator';
    }

    /** @param array<string, mixed> $user */
    public function setUserForTests(array $user): void
    {
        $this->user = $user;
        $_SESSION[self::SESSION_USER_KEY] = (int) $user['id'];
        $_SESSION[self::SESSION_ACTIVITY_KEY] = time();
    }

    private function hydrateFromSession(): void
    {
        if (!isset($_SESSION[self::SESSION_USER_KEY])) {
            return;
        }
        $id = (int) $_SESSION[self::SESSION_USER_KEY];
        $stmt = $this->pdo->prepare(
            'SELECT * FROM admin_users WHERE id = :id AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $this->user = $row;
            $_SESSION[self::SESSION_ACTIVITY_KEY] = time();
        } else {
            unset($_SESSION[self::SESSION_USER_KEY], $_SESSION[self::SESSION_ACTIVITY_KEY]);
            $this->user = null;
        }
    }

    private function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name('p1960_admin');
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
            ]);
        }
    }
}
