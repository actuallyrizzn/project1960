<?php
declare(strict_types=1);

namespace Project1960;

use PDO;

/**
 * Charge- and docket-grain helpers for multi-defendant press releases.
 *
 * Press row (`cases`) can stay enterprise-wide; §1960 focus lives on
 * `charges` (+ optional `case_courtlistener_links.relevance`).
 */
final class CaseChargeStore
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array{
     *   case_id: string,
     *   charge_description?: ?string,
     *   statute?: ?string,
     *   defendant?: ?string,
     *   status?: ?string,
     *   participant_id?: ?int,
     *   cl_docket_id?: ?int,
     *   count_num?: ?int,
     *   is_1960?: bool|int,
     *   verified_1960?: bool|int|null,
     *   source?: string,
     *   severity?: ?string,
     *   max_penalty?: ?string,
     *   fine_amount?: ?string
     * } $row
     */
    public function upsertCharge(array $row): int
    {
        $caseId = (string) $row['case_id'];
        $desc = isset($row['charge_description']) ? (string) $row['charge_description'] : '';
        $defendant = isset($row['defendant']) ? (string) $row['defendant'] : '';
        $source = (string) ($row['source'] ?? 'manual');

        $existingId = $this->findSimilarChargeId($caseId, $defendant, $desc);
        $now = gmdate('c');
        $is1960 = !empty($row['is_1960']) ? 1 : 0;
        $verified = array_key_exists('verified_1960', $row) && $row['verified_1960'] !== null
            ? ((int) (bool) $row['verified_1960'])
            : null;

        if ($existingId !== null) {
            $stmt = $this->pdo->prepare(
                'UPDATE charges SET
                    charge_description = COALESCE(?, charge_description),
                    statute = COALESCE(?, statute),
                    defendant = COALESCE(?, defendant),
                    status = COALESCE(?, status),
                    severity = COALESCE(?, severity),
                    max_penalty = COALESCE(?, max_penalty),
                    fine_amount = COALESCE(?, fine_amount),
                    participant_id = COALESCE(?, participant_id),
                    cl_docket_id = COALESCE(?, cl_docket_id),
                    count_num = COALESCE(?, count_num),
                    is_1960 = CASE WHEN ? = 1 THEN 1 ELSE is_1960 END,
                    verified_1960 = COALESCE(?, verified_1960),
                    source = ?,
                    updated_at = ?
                 WHERE charge_id = ?'
            );
            $stmt->execute([
                $desc !== '' ? $desc : null,
                $row['statute'] ?? null,
                $defendant !== '' ? $defendant : null,
                $row['status'] ?? null,
                $row['severity'] ?? null,
                $row['max_penalty'] ?? null,
                $row['fine_amount'] ?? null,
                $row['participant_id'] ?? null,
                $row['cl_docket_id'] ?? null,
                $row['count_num'] ?? null,
                $is1960,
                $verified,
                $source,
                $now,
                $existingId,
            ]);

            return $existingId;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO charges (
                case_id, charge_description, statute, severity, max_penalty, fine_amount,
                defendant, status, participant_id, cl_docket_id, count_num,
                is_1960, verified_1960, source, updated_at
             ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $caseId,
            $desc !== '' ? $desc : null,
            $row['statute'] ?? null,
            $row['severity'] ?? null,
            $row['max_penalty'] ?? null,
            $row['fine_amount'] ?? null,
            $defendant !== '' ? $defendant : null,
            $row['status'] ?? null,
            $row['participant_id'] ?? null,
            $row['cl_docket_id'] ?? null,
            $row['count_num'] ?? null,
            $is1960,
            $verified,
            $source,
            $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list1960FocusCharges(?string $caseId = null): array
    {
        if ($caseId !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM charges
                 WHERE case_id = ?
                   AND (is_1960 = 1 OR verified_1960 = 1
                        OR lower(COALESCE(statute, \'\')) LIKE \'%1960%\'
                        OR lower(COALESCE(charge_description, \'\')) LIKE \'%unlicensed money%\')
                 ORDER BY charge_id'
            );
            $stmt->execute([$caseId]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return $this->pdo->query(
            'SELECT * FROM charges
             WHERE is_1960 = 1 OR verified_1960 = 1
             ORDER BY case_id, charge_id'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function setLinkRelevance(
        string $caseId,
        int $clDocketId,
        string $relevance,
        ?int $focusChargeId = null,
        ?int $focusParticipantId = null,
    ): void {
        $allowed = ['unspecified', 'primary_1960', 'related', 'ambient'];
        if (!in_array($relevance, $allowed, true)) {
            throw new \InvalidArgumentException('Invalid relevance: ' . $relevance);
        }
        $stmt = $this->pdo->prepare(
            'UPDATE case_courtlistener_links
             SET relevance = ?, focus_charge_id = ?, focus_participant_id = ?
             WHERE case_id = ? AND cl_docket_id = ?'
        );
        $stmt->execute([$relevance, $focusChargeId, $focusParticipantId, $caseId, $clDocketId]);
    }

    /**
     * @param list<array{docket_number: string, court_hint?: ?string, caption?: ?string, source?: string}> $refs
     */
    public function upsertDocketRefs(string $caseId, array $refs): int
    {
        $n = 0;
        $stmt = $this->pdo->prepare(
            'INSERT INTO case_docket_refs (case_id, docket_number, court_hint, caption, source, updated_at)
             VALUES (?,?,?,?,?,?)
             ON CONFLICT(case_id, docket_number) DO UPDATE SET
               court_hint = COALESCE(excluded.court_hint, case_docket_refs.court_hint),
               caption = COALESCE(excluded.caption, case_docket_refs.caption),
               source = excluded.source,
               updated_at = excluded.updated_at'
        );
        $now = gmdate('c');
        foreach ($refs as $ref) {
            $num = trim((string) ($ref['docket_number'] ?? ''));
            if ($num === '') {
                continue;
            }
            $stmt->execute([
                $caseId,
                $num,
                $ref['court_hint'] ?? null,
                $ref['caption'] ?? null,
                (string) ($ref['source'] ?? 'press'),
                $now,
            ]);
            $n++;
        }

        return $n;
    }

    private function findSimilarChargeId(string $caseId, string $defendant, string $desc): ?int
    {
        if ($defendant === '' && $desc === '') {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT charge_id FROM charges
             WHERE case_id = ?
               AND lower(COALESCE(defendant, \'\')) = lower(?)
               AND lower(COALESCE(charge_description, \'\')) = lower(?)
             LIMIT 1'
        );
        $stmt->execute([$caseId, $defendant, $desc]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }
}
