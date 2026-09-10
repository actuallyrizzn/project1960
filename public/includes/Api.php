<?php
declare(strict_types=1);

namespace Project1960;

use PDO;

/**
 * JSON API controllers matching legacy Flask /api/stats, /api/cases, /api/enrichment/<id>.
 */
final class Api
{
    public function __construct(private readonly ?PDO $pdo)
    {
    }

    public function stats(): Response
    {
        if (!$this->pdo instanceof PDO) {
            return Response::json(['error' => 'Database not configured'], 503);
        }

        return Response::json(Stats::collect($this->pdo));
    }

    public function cases(int $limit = 100): Response
    {
        if (!$this->pdo instanceof PDO) {
            return Response::json(['error' => 'Database not configured'], 503);
        }
        $limit = max(1, min(500, $limit));
        $stmt = $this->pdo->query(
            'SELECT id, title, date, classification, verified_1960, mentions_1960, mentions_crypto
             FROM cases
             ORDER BY date DESC
             LIMIT ' . $limit
        );
        if ($stmt === false) {
            return Response::json([]);
        }
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll();

        return Response::json($rows);
    }

    public function enrichment(string $caseId): Response
    {
        if (!$this->pdo instanceof PDO) {
            return Response::json(['error' => 'Database not configured'], 503);
        }

        // Legacy Flask does not 404 missing cases — returns empty enrichment tables.
        return Response::json((new CaseDetailLoader($this->pdo))->enrichmentFor($caseId));
    }
}
