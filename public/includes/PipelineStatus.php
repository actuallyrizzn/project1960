<?php
declare(strict_types=1);

namespace Project1960;

use PDO;
use PDOException;
use Project1960\Scraper\ScraperState;

/** Read-only pipeline counters for admin (AD-P1). */
final class PipelineStatus
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{
     *   scrape: array<string, int|string|null>,
     *   match: array<string, int>,
     *   documents: array<string, int>,
     *   extract: array<string, int>
     * }
     */
    public function collect(): array
    {
        return [
            'scrape' => $this->scrape(),
            'match' => $this->match(),
            'documents' => $this->documents(),
            'extract' => $this->extract(),
        ];
    }

    /** @return array<string, int|string|null> */
    private function scrape(): array
    {
        $lastPage = (new ScraperState($this->pdo))->lastPage();

        return [
            'last_page' => $lastPage,
            'cases_total' => $this->count('SELECT COUNT(*) FROM cases'),
            'cases_1960' => $this->count('SELECT COUNT(*) FROM cases WHERE mentions_1960 = 1'),
        ];
    }

    /** @return array<string, int> */
    private function match(): array
    {
        return [
            'dockets' => $this->count('SELECT COUNT(*) FROM courtlistener_dockets'),
            'case_links' => $this->count('SELECT COUNT(*) FROM case_courtlistener_links'),
            'match_reviews' => $this->countSafe('SELECT COUNT(*) FROM cl_match_reviews'),
        ];
    }

    /** @return array<string, int> */
    private function documents(): array
    {
        $byStatus = [
            'none' => 0,
            'pending' => 0,
            'done' => 0,
            'failed' => 0,
        ];
        try {
            $rows = $this->pdo->query(
                'SELECT ocr_status, COUNT(*) AS c FROM courtlistener_documents GROUP BY ocr_status'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                $status = (string) ($row['ocr_status'] ?? 'none');
                if (isset($byStatus[$status])) {
                    $byStatus[$status] = (int) $row['c'];
                }
            }
        } catch (PDOException) {
        }

        return [
            'documents_total' => $this->count('SELECT COUNT(*) FROM courtlistener_documents'),
            'with_text' => $this->count('SELECT COUNT(*) FROM courtlistener_document_text'),
            'ocr_none' => $byStatus['none'],
            'ocr_pending' => $byStatus['pending'],
            'ocr_done' => $byStatus['done'],
            'ocr_failed' => $byStatus['failed'],
        ];
    }

    /** @return array<string, int> */
    private function extract(): array
    {
        return [
            'persons' => $this->countSafe('SELECT COUNT(*) FROM cl_persons'),
            'charges' => $this->countSafe('SELECT COUNT(*) FROM cl_extract_charges'),
            'outcomes' => $this->countSafe('SELECT COUNT(*) FROM cl_extract_outcomes'),
        ];
    }

    private function count(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }

    private function countSafe(string $sql): int
    {
        try {
            return $this->count($sql);
        } catch (PDOException) {
            return 0;
        }
    }
}
