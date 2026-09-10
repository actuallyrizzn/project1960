<?php
declare(strict_types=1);

namespace Project1960\Scraper;

use PDO;

/**
 * Persist scraper page cursor (legacy scraper_state.last_page).
 */
final class ScraperState
{
    public function __construct(private readonly PDO $pdo)
    {
        $this->ensureTable();
    }

    public function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS scraper_state (
                key TEXT PRIMARY KEY,
                value TEXT
            )'
        );
    }

    public function lastPage(): int
    {
        $stmt = $this->pdo->prepare('SELECT value FROM scraper_state WHERE key = ?');
        $stmt->execute(['last_page']);
        $value = $stmt->fetchColumn();
        if ($value === false || $value === null || $value === '') {
            return 0;
        }

        return (int) $value;
    }

    public function saveLastPage(int $page): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO scraper_state (key, value) VALUES (?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        );
        $stmt->execute(['last_page', (string) $page]);
    }
}
