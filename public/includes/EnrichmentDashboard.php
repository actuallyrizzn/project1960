<?php
declare(strict_types=1);

namespace Project1960;

use PDO;
use PDOException;

final class EnrichmentDashboard
{
    /** @var list<array{key: string, label: string, icon: string, badge: string, bar: string, blurb: string}> */
    public const TABLE_CARDS = [
        [
            'key' => 'case_metadata',
            'label' => 'Case Metadata',
            'icon' => 'bi-info-circle',
            'badge' => 'bg-primary',
            'bar' => 'bg-primary',
            'blurb' => 'District office, U.S. Attorney, event type, penalties, and crypto assets.',
        ],
        [
            'key' => 'participants',
            'label' => 'Participants',
            'icon' => 'bi-people',
            'badge' => 'bg-success',
            'bar' => 'bg-success',
            'blurb' => 'Defendants, prosecutors, and other key figures.',
        ],
        [
            'key' => 'case_agencies',
            'label' => 'Law Enforcement Agencies',
            'icon' => 'bi-building',
            'badge' => 'bg-info',
            'bar' => 'bg-info',
            'blurb' => 'Federal, state, and local agencies involved in the case.',
        ],
        [
            'key' => 'charges',
            'label' => 'Charges',
            'icon' => 'bi-exclamation-triangle',
            'badge' => 'bg-danger',
            'bar' => 'bg-danger',
            'blurb' => 'Criminal charges, statutes, severity, and maximum penalties.',
        ],
        [
            'key' => 'financial_actions',
            'label' => 'Financial Actions',
            'icon' => 'bi-cash-stack',
            'badge' => 'bg-warning',
            'bar' => 'bg-warning',
            'blurb' => 'Forfeitures, fines, restitution, and recoveries.',
        ],
        [
            'key' => 'victims',
            'label' => 'Victims',
            'icon' => 'bi-heart-broken',
            'badge' => 'bg-secondary',
            'bar' => 'bg-secondary',
            'blurb' => 'Individuals or entities harmed by the criminal activity.',
        ],
        [
            'key' => 'quotes',
            'label' => 'Notable Quotes',
            'icon' => 'bi-chat-quote',
            'badge' => 'bg-dark',
            'bar' => 'bg-dark',
            'blurb' => 'Key statements from prosecutors and officials.',
        ],
        [
            'key' => 'themes',
            'label' => 'Themes',
            'icon' => 'bi-tags',
            'badge' => 'bg-primary',
            'bar' => 'bg-primary',
            'blurb' => 'Themes and patterns identified in case narratives.',
        ],
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function ensureActivityLogTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS enrichment_activity_log (
                log_id INTEGER PRIMARY KEY AUTOINCREMENT,
                timestamp TEXT,
                case_id TEXT,
                table_name TEXT,
                status TEXT,
                notes TEXT
            )'
        );
    }

    /**
     * @return list<array{timestamp: string, case_id: string, table_name: string, status: string, notes: string}>
     */
    public function recentActivity(int $limit = 100): array
    {
        $this->ensureActivityLogTable();
        $limit = max(1, min(500, $limit));
        try {
            $stmt = $this->pdo->query(
                'SELECT timestamp, case_id, table_name, status, notes
                 FROM enrichment_activity_log
                 ORDER BY timestamp DESC
                 LIMIT ' . $limit
            );
            if ($stmt === false) {
                return [];
            }
            /** @var list<array{timestamp: string, case_id: string, table_name: string, status: string, notes: string}> $rows */
            $rows = $stmt->fetchAll();

            return $rows;
        } catch (PDOException) {
            return [];
        }
    }

    /**
     * Progress % for a table: verified cases with enrichment ÷ verified_yes.
     * Caps at 100 so UI bars never overflow if data drifts.
     *
     * @param array{verified_yes: int, enrichment: array<string, int>} $stats
     */
    public static function percentComplete(array $stats, string $tableKey): float
    {
        $verified = (int) ($stats['verified_yes'] ?? 0);
        if ($verified <= 0) {
            return 0.0;
        }
        $count = (int) ($stats['enrichment'][$tableKey] ?? 0);
        $pct = ($count / $verified) * 100;
        if ($pct > 100.0) {
            $pct = 100.0;
        }

        return round($pct, 1);
    }

    /**
     * @return array{
     *   stats: array<string, mixed>,
     *   activity_log: list<array{timestamp: string, case_id: string, table_name: string, status: string, notes: string}>,
     *   cards: list<array{key: string, label: string, icon: string, badge: string, bar: string, blurb: string, count: int, percent: float}>
     * }
     */
    public function collect(): array
    {
        $stats = Stats::collect($this->pdo);
        $cards = [];
        foreach (self::TABLE_CARDS as $card) {
            $count = (int) ($stats['enrichment'][$card['key']] ?? 0);
            $cards[] = $card + [
                'count' => $count,
                'percent' => self::percentComplete($stats, $card['key']),
            ];
        }

        return [
            'stats' => $stats,
            'activity_log' => $this->recentActivity(),
            'cards' => $cards,
        ];
    }
}
