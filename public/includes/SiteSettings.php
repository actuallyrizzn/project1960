<?php
declare(strict_types=1);

namespace Project1960;

use PDO;

/** Key/value site_settings store (AD-A5). */
final class SiteSettings
{
    public function __construct(private PDO $pdo)
    {
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM site_settings WHERE key = :k LIMIT 1');
        $stmt->execute([':k' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return $default;
        }

        return (string) $row['value'];
    }

    public function set(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO site_settings (key, value, updated_at)
             VALUES (:k, :v, datetime('now'))
             ON CONFLICT(key) DO UPDATE SET
               value = excluded.value,
               updated_at = datetime('now')"
        );
        $stmt->execute([':k' => $key, ':v' => $value]);
    }

    /** @return array<string, string> */
    public function all(): array
    {
        $rows = $this->pdo->query('SELECT key, value FROM site_settings ORDER BY key')->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[(string) $row['key']] = (string) $row['value'];
        }

        return $out;
    }
}
