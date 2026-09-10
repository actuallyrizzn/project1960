<?php
declare(strict_types=1);

namespace Project1960;

use PDO;

/** Ambiguous / low-confidence CL match queue (CL-M1). */
final class CourtListenerMatchReviewStore
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param list<array<string, mixed>> $candidates
     */
    public function flag(string $caseId, string $reason, array $candidates): void
    {
        $caseId = trim($caseId);
        if ($caseId === '') {
            throw new \InvalidArgumentException('case_id required');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO cl_match_reviews (case_id, status, reason, candidates_json, updated_at)
             VALUES (:id, \'pending\', :reason, :json, :updated)
             ON CONFLICT(case_id) DO UPDATE SET
                status = \'pending\',
                reason = excluded.reason,
                candidates_json = excluded.candidates_json,
                updated_at = excluded.updated_at'
        );
        $stmt->bindValue(':id', $caseId);
        $stmt->bindValue(':reason', $reason);
        $stmt->bindValue(':json', json_encode($candidates, JSON_THROW_ON_ERROR));
        $stmt->bindValue(':updated', gmdate('c'));
        $stmt->execute();
    }

    public function clear(string $caseId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM cl_match_reviews WHERE case_id = :id');
        $stmt->bindValue(':id', $caseId);
        $stmt->execute();
    }

    /** @return array<string, mixed>|null */
    public function get(string $caseId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT case_id, status, reason, candidates_json, updated_at
             FROM cl_match_reviews WHERE case_id = :id'
        );
        $stmt->bindValue(':id', $caseId);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }
}
