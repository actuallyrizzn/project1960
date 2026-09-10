<?php
declare(strict_types=1);

namespace Project1960;

use PDO;
use Project1960\CourtListener\FilingFactsStore;

/**
 * CourtListener panel data for case detail (CL-U1).
 */
final class CaseClPanel
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{
     *   dockets: list<array<string, mixed>>,
     *   documents: list<array<string, mixed>>,
     *   people: list<array<string, mixed>>,
     *   charges: list<array<string, mixed>>,
     *   outcomes: list<array<string, mixed>>
     * }
     */
    public function forCase(string $caseId): array
    {
        $dockets = (new CourtListenerDocketStore($this->pdo))->linksForCase($caseId);
        $documents = [];
        $docketIds = array_map(static fn (array $d): int => (int) $d['cl_docket_id'], $dockets);
        if ($docketIds !== []) {
            $placeholders = implode(',', array_fill(0, count($docketIds), '?'));
            $stmt = $this->pdo->prepare(
                "SELECT cl_document_id, cl_docket_id, entry_number, description, ocr_status,
                        download_status, has_plaintext, filepath_or_url
                 FROM courtlistener_documents
                 WHERE cl_docket_id IN ({$placeholders})
                 ORDER BY cl_document_id ASC"
            );
            $stmt->execute($docketIds);
            $documents = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.display_name, p.role, p.organization, p.confidence, e.role AS edge_role
             FROM cl_person_case_edges e
             JOIN cl_persons p ON p.id = e.person_id
             WHERE e.case_id = :cid
             ORDER BY p.role, p.display_name'
        );
        $stmt->bindValue(':cid', $caseId);
        $stmt->execute();
        $people = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $facts = new FilingFactsStore($this->pdo);
        $charges = $facts->chargesForCase($caseId);
        $outcomes = $facts->outcomesForCase($caseId);

        return [
            'dockets' => $dockets,
            'documents' => $documents,
            'people' => $people,
            'charges' => $charges,
            'outcomes' => $outcomes,
        ];
    }
}
