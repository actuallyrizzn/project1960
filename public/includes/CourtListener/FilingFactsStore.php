<?php
declare(strict_types=1);

namespace Project1960\CourtListener;

use PDO;

/**
 * Persist CL-X3 charges / outcomes extracted from filings.
 */
final class FilingFactsStore
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param list<array{charge_description: string, statute: ?string, defendant: ?string}> $charges
     */
    public function replaceCharges(string $caseId, int $sourceDocumentId, array $charges): int
    {
        $del = $this->pdo->prepare(
            'DELETE FROM cl_extract_charges WHERE case_id = :c AND source_document_id = :d'
        );
        $del->bindValue(':c', $caseId);
        $del->bindValue(':d', $sourceDocumentId, PDO::PARAM_INT);
        $del->execute();

        $ins = $this->pdo->prepare(
            'INSERT INTO cl_extract_charges (
                case_id, source_document_id, charge_description, statute, defendant, raw_json, updated_at
             ) VALUES (:c, :d, :desc, :statute, :def, :raw, :u)'
        );
        $n = 0;
        foreach ($charges as $row) {
            $ins->bindValue(':c', $caseId);
            $ins->bindValue(':d', $sourceDocumentId, PDO::PARAM_INT);
            $ins->bindValue(':desc', $row['charge_description']);
            $ins->bindValue(':statute', $row['statute']);
            $ins->bindValue(':def', $row['defendant']);
            $ins->bindValue(':raw', json_encode($row, JSON_THROW_ON_ERROR));
            $ins->bindValue(':u', gmdate('c'));
            $ins->execute();
            $n++;
        }

        return $n;
    }

    /**
     * @param list<array{outcome_type: string, description: ?string, amount: ?string, currency: ?string, defendant: ?string}> $outcomes
     */
    public function replaceOutcomes(string $caseId, int $sourceDocumentId, array $outcomes): int
    {
        $del = $this->pdo->prepare(
            'DELETE FROM cl_extract_outcomes WHERE case_id = :c AND source_document_id = :d'
        );
        $del->bindValue(':c', $caseId);
        $del->bindValue(':d', $sourceDocumentId, PDO::PARAM_INT);
        $del->execute();

        $ins = $this->pdo->prepare(
            'INSERT INTO cl_extract_outcomes (
                case_id, source_document_id, outcome_type, description, amount, currency, defendant, raw_json, updated_at
             ) VALUES (:c, :d, :type, :desc, :amt, :cur, :def, :raw, :u)'
        );
        $n = 0;
        foreach ($outcomes as $row) {
            $ins->bindValue(':c', $caseId);
            $ins->bindValue(':d', $sourceDocumentId, PDO::PARAM_INT);
            $ins->bindValue(':type', $row['outcome_type']);
            $ins->bindValue(':desc', $row['description']);
            $ins->bindValue(':amt', $row['amount']);
            $ins->bindValue(':cur', $row['currency']);
            $ins->bindValue(':def', $row['defendant']);
            $ins->bindValue(':raw', json_encode($row, JSON_THROW_ON_ERROR));
            $ins->bindValue(':u', gmdate('c'));
            $ins->execute();
            $n++;
        }

        return $n;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function chargesForCase(string $caseId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM cl_extract_charges WHERE case_id = :c ORDER BY id'
        );
        $stmt->bindValue(':c', $caseId);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function outcomesForCase(string $caseId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM cl_extract_outcomes WHERE case_id = :c ORDER BY id'
        );
        $stmt->bindValue(':c', $caseId);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Run LLM extract for one document chunk set.
     *
     * @return array{charges: int, outcomes: int, status: string}
     */
    public function extractFromText(LlmClient $llm, string $caseId, int $docId, string $text, bool $dryRun = false): array
    {
        $chunks = PeopleExtractPrompt::chunkText($text);
        $allCharges = [];
        $allOutcomes = [];
        foreach ($chunks as $chunk) {
            if ($dryRun) {
                continue;
            }
            $resp = $llm->complete(FilingFactsPrompt::build($chunk, $caseId));
            if (!$resp['ok']) {
                return ['charges' => 0, 'outcomes' => 0, 'status' => 'failed'];
            }
            $parsed = DirtyJsonParser::parse($resp['content']);
            if ($parsed === null) {
                continue;
            }
            $norm = FilingFactsPrompt::normalize($parsed);
            foreach ($norm['charges'] as $c) {
                $allCharges[] = $c;
            }
            foreach ($norm['outcomes'] as $o) {
                $allOutcomes[] = $o;
            }
        }
        if ($dryRun) {
            return ['charges' => count($chunks), 'outcomes' => 0, 'status' => 'would_extract'];
        }
        $cn = $this->replaceCharges($caseId, $docId, $allCharges);
        $on = $this->replaceOutcomes($caseId, $docId, $allOutcomes);

        return ['charges' => $cn, 'outcomes' => $on, 'status' => 'done'];
    }
}
