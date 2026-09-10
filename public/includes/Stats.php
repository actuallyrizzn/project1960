<?php
declare(strict_types=1);

namespace Project1960;

use PDO;
use PDOException;

final class Stats
{
    /** @var list<string> */
    public const ENRICHMENT_TABLES = [
        'case_metadata',
        'participants',
        'case_agencies',
        'charges',
        'financial_actions',
        'victims',
        'quotes',
        'themes',
    ];

    /**
     * @return array{
     *   total_cases: int,
     *   mentions_1960: int,
     *   mentions_crypto: int,
     *   verified_yes: int,
     *   verified_no: int,
     *   unprocessed_1960: int,
     *   enrichment: array<string, int>
     * }
     */
    public static function collect(PDO $pdo): array
    {
        $total = (int) $pdo->query('SELECT COUNT(*) FROM cases')->fetchColumn();
        $mentions1960 = (int) $pdo->query('SELECT COUNT(*) FROM cases WHERE mentions_1960 = 1')->fetchColumn();
        $mentionsCrypto = (int) $pdo->query('SELECT COUNT(*) FROM cases WHERE mentions_crypto = 1')->fetchColumn();
        $verifiedYes = (int) $pdo->query(
            'SELECT COUNT(*) FROM cases WHERE mentions_1960 = 1 AND verified_1960 = 1'
        )->fetchColumn();
        $verifiedNo = (int) $pdo->query(
            'SELECT COUNT(*) FROM cases WHERE mentions_1960 = 1 AND verified_1960 = 0'
        )->fetchColumn();

        $enrichment = [];
        foreach (self::ENRICHMENT_TABLES as $table) {
            $enrichment[$table] = self::distinctCaseCount($pdo, $table);
        }

        return [
            'total_cases' => $total,
            'mentions_1960' => $mentions1960,
            'mentions_crypto' => $mentionsCrypto,
            'verified_yes' => $verifiedYes,
            'verified_no' => $verifiedNo,
            'unprocessed_1960' => $mentions1960 - ($verifiedYes + $verifiedNo),
            'enrichment' => $enrichment,
        ];
    }

    private static function distinctCaseCount(PDO $pdo, string $table): int
    {
        // Whitelist only — never interpolate untrusted names
        if (!in_array($table, self::ENRICHMENT_TABLES, true)) {
            return 0;
        }

        try {
            return (int) $pdo->query('SELECT COUNT(DISTINCT case_id) FROM ' . $table)->fetchColumn();
        } catch (PDOException) {
            return 0;
        }
    }
}
