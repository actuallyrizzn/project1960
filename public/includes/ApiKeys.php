<?php
declare(strict_types=1);

namespace Project1960;

use InvalidArgumentException;
use JsonException;
use PDO;

/**
 * Scoped API keys — Environment pattern, P1960 scope pack (AD-R2 lean / Doc #1315).
 */
final class ApiKeys
{
    public const SCOPE_STATS_READ = 'stats:read';
    public const SCOPE_CASES_READ = 'cases:read';
    public const SCOPE_ENRICHMENT_READ = 'enrichment:read';
    public const SCOPE_PATTERNS_READ = 'patterns:read';
    public const SCOPE_ADMIN_READ = 'admin:read';
    public const SCOPE_ADMIN_USERS = 'admin:users';
    public const SCOPE_ADMIN_KEYS = 'admin:keys';
    public const SCOPE_ADMIN_SETTINGS = 'admin:settings';
    public const SCOPE_PIPELINE_STATUS = 'pipeline:status';
    public const SCOPE_PIPELINE_ENQUEUE = 'pipeline:enqueue';

    /** @var list<string> */
    public const ALL_SCOPES = [
        self::SCOPE_STATS_READ,
        self::SCOPE_CASES_READ,
        self::SCOPE_ENRICHMENT_READ,
        self::SCOPE_PATTERNS_READ,
        self::SCOPE_ADMIN_READ,
        self::SCOPE_ADMIN_USERS,
        self::SCOPE_ADMIN_KEYS,
        self::SCOPE_ADMIN_SETTINGS,
        self::SCOPE_PIPELINE_STATUS,
        self::SCOPE_PIPELINE_ENQUEUE,
    ];

    private const KEY_BYTES = 32;
    private const LAST_USED_THROTTLE = 600;

    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<string> */
    public static function presetsForRole(string $role): array
    {
        if ($role === 'operator') {
            return self::ALL_SCOPES;
        }
        // readonly
        return [
            self::SCOPE_STATS_READ,
            self::SCOPE_CASES_READ,
            self::SCOPE_ENRICHMENT_READ,
            self::SCOPE_PATTERNS_READ,
            self::SCOPE_ADMIN_READ,
            self::SCOPE_PIPELINE_STATUS,
        ];
    }

    /**
     * @param list<string>|null $scopes
     * @return list<string>
     */
    public static function normalizeScopes(?array $scopes, string $role): array
    {
        if ($scopes === null) {
            return self::presetsForRole($role);
        }
        $allowed = array_flip(self::ALL_SCOPES);
        $out = [];
        foreach ($scopes as $s) {
            $s = trim((string) $s);
            if ($s !== '' && isset($allowed[$s]) && !in_array($s, $out, true)) {
                $out[] = $s;
            }
        }
        if ($out === []) {
            throw new InvalidArgumentException('scopes must include at least one known scope');
        }
        if ($role !== 'operator') {
            $ok = array_flip(self::presetsForRole('readonly'));
            $out = array_values(array_filter($out, static fn (string $s): bool => isset($ok[$s])));
            if ($out === []) {
                throw new InvalidArgumentException('readonly keys cannot hold those scopes');
            }
        }

        return $out;
    }

    public static function hashKey(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    public static function previewKey(string $plaintext): string
    {
        return substr($plaintext, 0, 12);
    }

    public static function hasScope(array $scopes, string $required): bool
    {
        return in_array($required, $scopes, true);
    }

    /**
     * @param list<string>|null $scopes
     * @return array{plaintext: string, key: array<string, mixed>}
     */
    public function mint(
        int $userId,
        string $keyName,
        ?array $scopes = null,
        ?int $createdByUserId = null
    ): array {
        $stmt = $this->pdo->prepare(
            'SELECT id, role, is_active FROM admin_users WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user === false || (int) $user['is_active'] !== 1) {
            throw new InvalidArgumentException('User not found or inactive');
        }
        $keyName = trim($keyName);
        if ($keyName === '') {
            $keyName = 'Unnamed Key';
        }
        if (strlen($keyName) > 80) {
            $keyName = substr($keyName, 0, 80);
        }
        $norm = self::normalizeScopes($scopes, (string) $user['role']);
        $plaintext = 'p1960_' . bin2hex(random_bytes(self::KEY_BYTES));
        $hash = self::hashKey($plaintext);
        $preview = self::previewKey($plaintext);
        $ins = $this->pdo->prepare(
            'INSERT INTO api_keys (
                user_id, key_name, api_key_hash, key_preview, scopes_json, created_by_user_id
             ) VALUES (
                :uid, :name, :hash, :preview, :scopes, :by
             )'
        );
        $ins->execute([
            ':uid' => $userId,
            ':name' => $keyName,
            ':hash' => $hash,
            ':preview' => $preview,
            ':scopes' => json_encode($norm, JSON_THROW_ON_ERROR),
            ':by' => $createdByUserId,
        ]);
        $id = (int) $this->pdo->lastInsertId();

        return ['plaintext' => $plaintext, 'key' => $this->getById($id)];
    }

    /** @return array<string, mixed>|null */
    public function getById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM api_keys WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->publicize($row);
    }

    /** @return array<string, mixed>|null Raw join row for auth (includes user fields). */
    public function resolvePlaintext(string $plaintext): ?array
    {
        if ($plaintext === '') {
            return null;
        }
        $hash = self::hashKey($plaintext);
        $stmt = $this->pdo->prepare(
            'SELECT ak.*, u.username, u.email, u.role, u.is_active AS user_is_active,
                    u.password_hash, u.created_at AS user_created_at, u.updated_at AS user_updated_at
             FROM api_keys ak
             JOIN admin_users u ON u.id = ak.user_id
             WHERE ak.api_key_hash = :h
               AND ak.revoked_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([':h' => $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || (int) $row['user_is_active'] !== 1) {
            return null;
        }
        $this->touchLastUsed((int) $row['id'], isset($row['last_used_at']) ? (string) $row['last_used_at'] : null);

        return $row;
    }

    public function authenticateFromHeaders(array $server): ?array
    {
        $key = $this->extractApiKey($server);
        if ($key === null) {
            return null;
        }

        return $this->resolvePlaintext($key);
    }

    public function requireScope(array $row, string $scope): void
    {
        $scopes = self::scopesFromRow($row);
        if (!self::hasScope($scopes, $scope)) {
            throw new InvalidArgumentException('Missing required scope: ' . $scope);
        }
    }

    public function revoke(int $keyId): bool
    {
        $stmt = $this->pdo->prepare('SELECT * FROM api_keys WHERE id = :id');
        $stmt->execute([':id' => $keyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return false;
        }
        if ($row['revoked_at'] !== null && $row['revoked_at'] !== '') {
            return true;
        }
        $this->pdo->prepare(
            "UPDATE api_keys SET revoked_at = datetime('now') WHERE id = :id"
        )->execute([':id' => $keyId]);

        return true;
    }

    /** @return list<array<string, mixed>> */
    public function listForUser(int $userId, bool $includeRevoked = false): array
    {
        $sql = 'SELECT * FROM api_keys WHERE user_id = :uid';
        if (!$includeRevoked) {
            $sql .= ' AND revoked_at IS NULL';
        }
        $sql .= ' ORDER BY id DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':uid' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $r): array => $this->publicize($r), $rows);
    }

    /** @return list<string> */
    public static function scopesFromRow(array $row): array
    {
        if (isset($row['scopes']) && is_array($row['scopes'])) {
            return array_values($row['scopes']);
        }
        $raw = $row['scopes_json'] ?? '[]';
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_map('strval', $decoded));
    }

    /** @return array<string, mixed> */
    public function publicize(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'username' => $row['username'] ?? null,
            'key_name' => $row['key_name'],
            'key_preview' => $row['key_preview'],
            'scopes' => self::scopesFromRow($row),
            'created_by_user_id' => isset($row['created_by_user_id']) && $row['created_by_user_id'] !== null
                ? (int) $row['created_by_user_id']
                : null,
            'created_at' => $row['created_at'] ?? null,
            'last_used_at' => $row['last_used_at'] ?? null,
            'revoked_at' => $row['revoked_at'] ?? null,
        ];
    }

    /** @return list<string>|null */
    private function extractApiKey(array $server): ?string
    {
        $headers = [];
        foreach ($server as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($k, 5)));
                $headers[$name] = $v;
            }
        }
        if (!empty($server['HTTP_X_API_KEY']) && is_string($server['HTTP_X_API_KEY'])) {
            return trim($server['HTTP_X_API_KEY']);
        }
        if (!empty($headers['x-api-key']) && is_string($headers['x-api-key'])) {
            return trim($headers['x-api-key']);
        }
        $auth = $server['HTTP_AUTHORIZATION'] ?? null;
        if (is_string($auth) && str_starts_with($auth, 'Bearer ')) {
            return trim(substr($auth, 7));
        }

        return null;
    }

    private function touchLastUsed(int $keyId, ?string $lastUsedRaw): void
    {
        if ($lastUsedRaw !== null && $lastUsedRaw !== '') {
            $ts = strtotime($lastUsedRaw);
            if ($ts !== false && (time() - $ts) < self::LAST_USED_THROTTLE) {
                return;
            }
        }
        $this->pdo->prepare(
            "UPDATE api_keys SET last_used_at = datetime('now') WHERE id = :id"
        )->execute([':id' => $keyId]);
    }
}
