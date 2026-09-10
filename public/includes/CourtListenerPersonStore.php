<?php
declare(strict_types=1);

namespace Project1960;

use InvalidArgumentException;
use PDO;

/**
 * CL extract people + aliases + case edges (CL-S3).
 * Parallel to press-release `participants` — designed for cross-case queries.
 */
final class CourtListenerPersonStore
{
    public const ROLES = ['defendant', 'attorney', 'judge', 'witness', 'other'];

    public function __construct(private PDO $pdo)
    {
    }

    public static function normalizeName(string $name): string
    {
        $n = strtolower(trim($name));
        $n = preg_replace('/\s+/u', ' ', $n) ?? $n;
        $n = preg_replace('/[^\p{L}\p{N} .\'-]/u', '', $n) ?? $n;

        return trim($n);
    }

    /**
     * @param array{
     *   display_name: string,
     *   role?: string,
     *   organization?: ?string,
     *   source_document_id?: int|string|null,
     *   confidence?: float|int|null,
     *   raw_json?: ?string,
     *   normalized_name?: ?string
     * } $person
     */
    public function upsertPerson(array $person): int
    {
        $display = trim((string) ($person['display_name'] ?? ''));
        if ($display === '') {
            throw new InvalidArgumentException('display_name is required');
        }

        $role = (string) ($person['role'] ?? 'other');
        if (!in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException('invalid role: ' . $role);
        }

        $normalized = $person['normalized_name'] ?? null;
        $normalized = is_string($normalized) && trim($normalized) !== ''
            ? self::normalizeName($normalized)
            : self::normalizeName($display);
        if ($normalized === '') {
            throw new InvalidArgumentException('normalized_name empty after normalize');
        }

        $existing = $this->pdo->prepare(
            'SELECT id FROM cl_persons WHERE normalized_name = :n AND role = :r LIMIT 1'
        );
        $existing->bindValue(':n', $normalized);
        $existing->bindValue(':r', $role);
        $existing->execute();
        $id = $existing->fetchColumn();

        $src = $person['source_document_id'] ?? null;
        $srcId = $src === null || $src === '' ? null : (int) $src;
        $conf = $person['confidence'] ?? null;

        if ($id !== false) {
            $stmt = $this->pdo->prepare(
                'UPDATE cl_persons SET
                    display_name = :display,
                    organization = :org,
                    source_document_id = COALESCE(:src, source_document_id),
                    confidence = COALESCE(:conf, confidence),
                    raw_json = COALESCE(:raw, raw_json),
                    updated_at = :updated
                 WHERE id = :id'
            );
            $stmt->bindValue(':id', (int) $id, PDO::PARAM_INT);
            $stmt->bindValue(':display', $display);
            $stmt->bindValue(':org', $person['organization'] ?? null);
            if ($srcId === null) {
                $stmt->bindValue(':src', null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(':src', $srcId, PDO::PARAM_INT);
            }
            if ($conf === null) {
                $stmt->bindValue(':conf', null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(':conf', (float) $conf);
            }
            $stmt->bindValue(':raw', $person['raw_json'] ?? null);
            $stmt->bindValue(':updated', gmdate('c'));
            $stmt->execute();

            return (int) $id;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO cl_persons (
                normalized_name, display_name, role, organization,
                source_document_id, confidence, raw_json, updated_at
             ) VALUES (
                :n, :display, :role, :org, :src, :conf, :raw, :updated
             )'
        );
        $stmt->bindValue(':n', $normalized);
        $stmt->bindValue(':display', $display);
        $stmt->bindValue(':role', $role);
        $stmt->bindValue(':org', $person['organization'] ?? null);
        if ($srcId === null) {
            $stmt->bindValue(':src', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':src', $srcId, PDO::PARAM_INT);
        }
        if ($conf === null) {
            $stmt->bindValue(':conf', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':conf', (float) $conf);
        }
        $stmt->bindValue(':raw', $person['raw_json'] ?? null);
        $stmt->bindValue(':updated', gmdate('c'));
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByNormalizedName(string $name): array
    {
        $n = self::normalizeName($name);
        if ($n === '') {
            return [];
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, normalized_name, display_name, role, organization,
                    source_document_id, confidence, raw_json, updated_at
             FROM cl_persons WHERE normalized_name = :n ORDER BY id'
        );
        $stmt->bindValue(':n', $n);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function addAlias(int $personId, string $aliasDisplay): void
    {
        if ($personId <= 0) {
            throw new InvalidArgumentException('person_id must be positive');
        }
        $display = trim($aliasDisplay);
        $norm = self::normalizeName($display);
        if ($norm === '') {
            throw new InvalidArgumentException('alias empty');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO cl_person_aliases (person_id, alias_normalized, alias_display)
             VALUES (:pid, :an, :ad)
             ON CONFLICT(person_id, alias_normalized) DO UPDATE SET
                alias_display = excluded.alias_display'
        );
        $stmt->bindValue(':pid', $personId, PDO::PARAM_INT);
        $stmt->bindValue(':an', $norm);
        $stmt->bindValue(':ad', $display);
        $stmt->execute();
    }

    /**
     * @param array{
     *   person_id: int,
     *   case_id: string,
     *   role?: ?string,
     *   confidence?: float|int|null,
     *   source_document_id?: int|string|null
     * } $edge
     */
    public function linkCase(array $edge): void
    {
        $pid = (int) ($edge['person_id'] ?? 0);
        $caseId = trim((string) ($edge['case_id'] ?? ''));
        if ($pid <= 0 || $caseId === '') {
            throw new InvalidArgumentException('person_id and case_id required');
        }
        $src = $edge['source_document_id'] ?? null;
        $srcId = $src === null || $src === '' ? null : (int) $src;
        $conf = $edge['confidence'] ?? null;

        $stmt = $this->pdo->prepare(
            'INSERT INTO cl_person_case_edges (
                person_id, case_id, role, confidence, source_document_id, updated_at
             ) VALUES (
                :pid, :cid, :role, :conf, :src, :updated
             )
             ON CONFLICT(person_id, case_id) DO UPDATE SET
                role = COALESCE(excluded.role, cl_person_case_edges.role),
                confidence = COALESCE(excluded.confidence, cl_person_case_edges.confidence),
                source_document_id = COALESCE(excluded.source_document_id, cl_person_case_edges.source_document_id),
                updated_at = excluded.updated_at'
        );
        $stmt->bindValue(':pid', $pid, PDO::PARAM_INT);
        $stmt->bindValue(':cid', $caseId);
        $stmt->bindValue(':role', $edge['role'] ?? null);
        if ($conf === null) {
            $stmt->bindValue(':conf', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':conf', (float) $conf);
        }
        if ($srcId === null) {
            $stmt->bindValue(':src', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':src', $srcId, PDO::PARAM_INT);
        }
        $stmt->bindValue(':updated', gmdate('c'));
        $stmt->execute();
    }

    /**
     * Cases linked to any person matching the normalized name (direct or alias).
     *
     * @return list<string>
     */
    public function caseIdsForNormalizedName(string $name): array
    {
        $n = self::normalizeName($name);
        if ($n === '') {
            return [];
        }
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT e.case_id
             FROM cl_person_case_edges e
             WHERE e.person_id IN (
                SELECT id FROM cl_persons WHERE normalized_name = :n
                UNION
                SELECT person_id FROM cl_person_aliases WHERE alias_normalized = :n2
             )
             ORDER BY e.case_id'
        );
        $stmt->bindValue(':n', $n);
        $stmt->bindValue(':n2', $n);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return array_map('strval', $rows ?: []);
    }
}
