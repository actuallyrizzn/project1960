<?php
declare(strict_types=1);

namespace Project1960;

use InvalidArgumentException;
use PDO;

/**
 * Create first (or additional) admin_users row from env — never logs passwords.
 */
final class AdminBootstrap
{
    public function __construct(private PDO $pdo)
    {
    }

    public function countOperators(): int
    {
        return (int) $this->pdo->query(
            "SELECT COUNT(*) FROM admin_users WHERE is_active = 1"
        )->fetchColumn();
    }

    /**
     * @param array{username: string, email: string, password: string, role?: string} $creds
     * @return array{id: int, username: string, email: string, role: string, created: bool}
     */
    public function create(array $creds, bool $dryRun = false): array
    {
        $username = trim($creds['username'] ?? '');
        $email = trim($creds['email'] ?? '');
        $password = (string) ($creds['password'] ?? '');
        $role = $creds['role'] ?? 'operator';
        if ($username === '' || $email === '') {
            throw new InvalidArgumentException('username and email are required');
        }
        if (strlen($password) < 12) {
            throw new InvalidArgumentException('password must be at least 12 characters');
        }
        if (!in_array($role, ['operator', 'readonly'], true)) {
            throw new InvalidArgumentException('role must be operator or readonly');
        }

        $check = $this->pdo->prepare(
            'SELECT id FROM admin_users WHERE username = :u OR email = :e LIMIT 1'
        );
        $check->execute([':u' => $username, ':e' => $email]);
        $existing = $check->fetch(PDO::FETCH_ASSOC);
        if ($existing !== false) {
            throw new InvalidArgumentException('username or email already exists');
        }

        if ($dryRun) {
            return [
                'id' => 0,
                'username' => $username,
                'email' => $email,
                'role' => $role,
                'created' => false,
            ];
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $ins = $this->pdo->prepare(
            'INSERT INTO admin_users (username, email, password_hash, role)
             VALUES (:u, :e, :p, :r)'
        );
        $ins->execute([
            ':u' => $username,
            ':e' => $email,
            ':p' => $hash,
            ':r' => $role,
        ]);

        return [
            'id' => (int) $this->pdo->lastInsertId(),
            'username' => $username,
            'email' => $email,
            'role' => $role,
            'created' => true,
        ];
    }

    /**
     * Load credentials from getenv / optional env-style pass file.
     * Pass file keys: P1960_ADMIN_USERNAME, P1960_ADMIN_EMAIL, P1960_ADMIN_PASSWORD, optional P1960_ADMIN_ROLE.
     *
     * @param array<string, string|null> $env
     * @return array{username: string, email: string, password: string, role: string}
     */
    public static function credentialsFromEnv(array $env = [], ?string $passFile = null): array
    {
        $merged = $env;
        if ($passFile !== null && $passFile !== '') {
            if (!is_readable($passFile)) {
                throw new InvalidArgumentException('pass file not readable: ' . $passFile);
            }
            foreach (file($passFile, FILE_IGNORE_NEW_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $merged[trim($k)] = trim($v, " \t\"'");
            }
        }
        $get = static function (string $key) use ($merged): string {
            $v = $merged[$key] ?? getenv($key) ?: '';

            return is_string($v) ? $v : '';
        };

        return [
            'username' => $get('P1960_ADMIN_USERNAME'),
            'email' => $get('P1960_ADMIN_EMAIL'),
            'password' => $get('P1960_ADMIN_PASSWORD'),
            'role' => $get('P1960_ADMIN_ROLE') !== '' ? $get('P1960_ADMIN_ROLE') : 'operator',
        ];
    }
}
