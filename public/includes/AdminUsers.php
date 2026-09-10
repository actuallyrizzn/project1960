<?php
declare(strict_types=1);

namespace Project1960;

use InvalidArgumentException;
use PDO;

/**
 * Operator user CRUD (AD-A3).
 */
final class AdminUsers
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $rows = $this->pdo->query(
            'SELECT id, username, email, role, is_active, created_at, updated_at
             FROM admin_users ORDER BY id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static function (array $r): array {
            $r['id'] = (int) $r['id'];
            $r['is_active'] = (int) $r['is_active'];

            return $r;
        }, $rows ?: []);
    }

    /**
     * @param array{username: string, email: string, password: string, role?: string} $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $username = trim($data['username'] ?? '');
        $email = trim($data['email'] ?? '');
        $password = (string) ($data['password'] ?? '');
        $role = $data['role'] ?? 'operator';
        if ($username === '' || $email === '') {
            throw new InvalidArgumentException('username and email are required');
        }
        if (strlen($password) < 12) {
            throw new InvalidArgumentException('password must be at least 12 characters');
        }
        if (!in_array($role, ['operator', 'readonly'], true)) {
            throw new InvalidArgumentException('invalid role');
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        try {
            $this->pdo->prepare(
                'INSERT INTO admin_users (username, email, password_hash, role)
                 VALUES (:u, :e, :p, :r)'
            )->execute([':u' => $username, ':e' => $email, ':p' => $hash, ':r' => $role]);
        } catch (\PDOException $e) {
            throw new InvalidArgumentException('username or email already exists', 0, $e);
        }
        $id = (int) $this->pdo->lastInsertId();

        return $this->get($id);
    }

    /** @return array<string, mixed> */
    public function get(int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, username, email, role, is_active, created_at, updated_at
             FROM admin_users WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new InvalidArgumentException('user not found');
        }
        $row['id'] = (int) $row['id'];
        $row['is_active'] = (int) $row['is_active'];

        return $row;
    }

    public function setActive(int $id, bool $active): array
    {
        $this->get($id);
        $this->pdo->prepare(
            "UPDATE admin_users SET is_active = :a, updated_at = datetime('now') WHERE id = :id"
        )->execute([':a' => $active ? 1 : 0, ':id' => $id]);

        return $this->get($id);
    }

    public function resetPassword(int $id, string $password): array
    {
        $this->get($id);
        if (strlen($password) < 12) {
            throw new InvalidArgumentException('password must be at least 12 characters');
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $this->pdo->prepare(
            "UPDATE admin_users SET password_hash = :p, updated_at = datetime('now') WHERE id = :id"
        )->execute([':p' => $hash, ':id' => $id]);

        return $this->get($id);
    }
}
