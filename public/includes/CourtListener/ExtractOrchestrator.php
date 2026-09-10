<?php
declare(strict_types=1);

namespace Project1960\CourtListener;

use PDO;
use Project1960\CourtListenerPersonStore;

/**
 * Extract people from document text → cl_persons + edges (CL-X2).
 */
final class ExtractOrchestrator
{
    public function __construct(
        private LlmClient $llm,
        private CourtListenerPersonStore $persons,
        private PDO $pdo,
    ) {
    }

    /**
     * @return array{cl_document_id: int, people: int, status: string, error?: string}
     */
    public function extractDocument(int $clDocumentId, string $caseId, bool $dryRun = false): array
    {
        $text = $this->loadText($clDocumentId);
        if ($text === null || trim($text) === '') {
            return ['cl_document_id' => $clDocumentId, 'people' => 0, 'status' => 'no_text'];
        }

        $chunks = PeopleExtractPrompt::chunkText($text);
        $merged = [];
        foreach ($chunks as $chunk) {
            $prompt = PeopleExtractPrompt::build($chunk, $caseId);
            if ($dryRun) {
                continue;
            }
            $resp = $this->llm->complete($prompt);
            if (!$resp['ok']) {
                return [
                    'cl_document_id' => $clDocumentId,
                    'people' => 0,
                    'status' => 'failed',
                    'error' => $resp['error'] ?? 'llm failed',
                ];
            }
            $parsed = DirtyJsonParser::parse($resp['content']);
            if ($parsed === null) {
                continue;
            }
            foreach (PeopleExtractPrompt::normalizePeople($parsed) as $person) {
                $key = CourtListenerPersonStore::normalizeName($person['display_name']) . '|' . $person['role'];
                $merged[$key] = $person;
            }
        }

        if ($dryRun) {
            return [
                'cl_document_id' => $clDocumentId,
                'people' => count($chunks),
                'status' => 'would_extract',
            ];
        }

        $count = 0;
        foreach ($merged as $person) {
            $pid = $this->persons->upsertPerson([
                'display_name' => $person['display_name'],
                'role' => $person['role'],
                'organization' => $person['organization'],
                'source_document_id' => $clDocumentId,
                'confidence' => $person['confidence'],
            ]);
            foreach ($person['aliases'] as $alias) {
                $this->persons->addAlias($pid, $alias);
            }
            $this->persons->linkCase([
                'person_id' => $pid,
                'case_id' => $caseId,
                'role' => $person['role'],
                'confidence' => $person['confidence'],
                'source_document_id' => $clDocumentId,
            ]);
            $this->maybeLinkSeedParticipant($caseId, $person['display_name'], $pid);
            $count++;
        }

        return ['cl_document_id' => $clDocumentId, 'people' => $count, 'status' => 'done'];
    }

    /**
     * Docs with full_text not yet used as source_document_id on any person.
     *
     * @return list<array{cl_document_id: int, case_id: string}>
     */
    public function pendingDocuments(int $limit = 10): array
    {
        $limit = max(1, $limit);
        $sql = <<<SQL
SELECT t.cl_document_id, e.case_id
FROM courtlistener_document_text t
JOIN courtlistener_documents d ON d.cl_document_id = t.cl_document_id
JOIN case_courtlistener_links e ON e.cl_docket_id = d.cl_docket_id
WHERE t.full_text IS NOT NULL AND TRIM(t.full_text) != ''
  AND NOT EXISTS (
    SELECT 1 FROM cl_persons p WHERE p.source_document_id = t.cl_document_id
  )
ORDER BY t.cl_document_id ASC
LIMIT {$limit}
SQL;
        try {
            $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return [];
        }

        return array_map(static fn (array $r): array => [
            'cl_document_id' => (int) $r['cl_document_id'],
            'case_id' => (string) $r['case_id'],
        ], $rows ?: []);
    }

    private function loadText(int $clDocumentId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT full_text FROM courtlistener_document_text WHERE cl_document_id = :id'
        );
        $stmt->bindValue(':id', $clDocumentId, PDO::PARAM_INT);
        $stmt->execute();
        $v = $stmt->fetchColumn();

        return $v === false ? null : (string) $v;
    }

    private function maybeLinkSeedParticipant(string $caseId, string $displayName, int $personId): void
    {
        $norm = CourtListenerPersonStore::normalizeName($displayName);
        if ($norm === '') {
            return;
        }
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, name FROM participants WHERE case_id = :cid'
            );
            $stmt->bindValue(':cid', $caseId);
            $stmt->execute();
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $pn = CourtListenerPersonStore::normalizeName((string) ($row['name'] ?? ''));
                if ($pn !== '' && ($pn === $norm || str_contains($pn, $norm) || str_contains($norm, $pn))) {
                    // Soft merge: store seed participant id as alias marker
                    $this->persons->addAlias($personId, (string) $row['name']);
                }
            }
        } catch (\PDOException) {
            // seed table optional
        }
    }
}
