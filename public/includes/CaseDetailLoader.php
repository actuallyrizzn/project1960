<?php
declare(strict_types=1);

namespace Project1960;

use PDO;
use PDOException;

final class CaseDetailLoader
{
    /** @var list<string> */
    private const TABLES = [
        'participants',
        'case_agencies',
        'charges',
        'financial_actions',
        'victims',
        'quotes',
        'themes',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{case: array<string, mixed>, enrichment: array<string, mixed>, courtlistener: array<string, mixed>}|null
     */
    public function load(string $caseId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cases WHERE id = ?');
        $stmt->execute([$caseId]);
        $case = $stmt->fetch();
        if ($case === false) {
            return null;
        }

        return [
            'case' => $case,
            'enrichment' => $this->enrichmentFor($caseId),
            'courtlistener' => (new CaseClPanel($this->pdo))->forCase($caseId),
        ];
    }

    /** @return array<string, mixed> */
    public function enrichmentFor(string $caseId): array
    {
        return $this->enrichment($caseId);
    }

    /** @return array<string, mixed> */
    private function enrichment(string $caseId): array
    {
        $out = [
            'metadata' => null,
            'participants' => [],
            'agencies' => [],
            'charges' => [],
            'financial_actions' => [],
            'victims' => [],
            'quotes' => [],
            'themes' => [],
        ];

        try {
            $stmt = $this->pdo->prepare('SELECT * FROM case_metadata WHERE case_id = ?');
            $stmt->execute([$caseId]);
            $meta = $stmt->fetch();
            $out['metadata'] = $meta === false ? null : $meta;
        } catch (PDOException) {
            $out['metadata'] = null;
        }

        $map = [
            'participants' => 'participants',
            'case_agencies' => 'agencies',
            'charges' => 'charges',
            'financial_actions' => 'financial_actions',
            'victims' => 'victims',
            'quotes' => 'quotes',
            'themes' => 'themes',
        ];

        foreach ($map as $table => $key) {
            $out[$key] = $this->fetchRows($table, $caseId);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function fetchRows(string $table, string $caseId): array
    {
        if (!in_array($table, self::TABLES, true)) {
            return [];
        }
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM ' . $table . ' WHERE case_id = ?');
            $stmt->execute([$caseId]);
            /** @var list<array<string, mixed>> $rows */
            $rows = $stmt->fetchAll();

            return $rows;
        } catch (PDOException) {
            return [];
        }
    }
}
