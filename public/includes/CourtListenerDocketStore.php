<?php
declare(strict_types=1);

namespace Project1960;

use PDO;

/**
 * Upsert/query helpers for CourtListener docket rows + case↔docket links.
 */
final class CourtListenerDocketStore
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array{
     *   cl_docket_id: int|string,
     *   court_id?: ?string,
     *   docket_number?: ?string,
     *   case_name?: ?string,
     *   raw_json?: ?string
     * } $docket
     */
    public function upsertDocket(array $docket): void
    {
        $id = (int) $docket['cl_docket_id'];
        if ($id <= 0) {
            throw new \InvalidArgumentException('cl_docket_id must be a positive integer');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO courtlistener_dockets (
                cl_docket_id, court_id, docket_number, case_name, raw_json, updated_at
             ) VALUES (
                :id, :court_id, :docket_number, :case_name, :raw_json, :updated_at
             )
             ON CONFLICT(cl_docket_id) DO UPDATE SET
                court_id = excluded.court_id,
                docket_number = excluded.docket_number,
                case_name = excluded.case_name,
                raw_json = COALESCE(excluded.raw_json, courtlistener_dockets.raw_json),
                updated_at = excluded.updated_at'
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':court_id', $docket['court_id'] ?? null);
        $stmt->bindValue(':docket_number', $docket['docket_number'] ?? null);
        $stmt->bindValue(':case_name', $docket['case_name'] ?? null);
        $stmt->bindValue(':raw_json', $docket['raw_json'] ?? null);
        $stmt->bindValue(':updated_at', gmdate('c'));
        $stmt->execute();
    }

    /**
     * @param array{
     *   case_id: string,
     *   cl_docket_id: int|string,
     *   match_confidence?: float|int|null,
     *   match_method?: ?string,
     *   raw_json?: ?string
     * } $link
     */
    public function upsertCaseLink(array $link): void
    {
        $caseId = trim((string) ($link['case_id'] ?? ''));
        $clId = (int) ($link['cl_docket_id'] ?? 0);
        if ($caseId === '' || $clId <= 0) {
            throw new \InvalidArgumentException('case_id and cl_docket_id are required');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO case_courtlistener_links (
                case_id, cl_docket_id, match_confidence, match_method, raw_json, updated_at
             ) VALUES (
                :case_id, :cl_docket_id, :match_confidence, :match_method, :raw_json, :updated_at
             )
             ON CONFLICT(case_id, cl_docket_id) DO UPDATE SET
                match_confidence = excluded.match_confidence,
                match_method = excluded.match_method,
                raw_json = COALESCE(excluded.raw_json, case_courtlistener_links.raw_json),
                updated_at = excluded.updated_at'
        );
        $stmt->bindValue(':case_id', $caseId);
        $stmt->bindValue(':cl_docket_id', $clId, PDO::PARAM_INT);
        $conf = $link['match_confidence'] ?? null;
        if ($conf === null) {
            $stmt->bindValue(':match_confidence', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':match_confidence', (float) $conf);
        }
        $stmt->bindValue(':match_method', $link['match_method'] ?? null);
        $stmt->bindValue(':raw_json', $link['raw_json'] ?? null);
        $stmt->bindValue(':updated_at', gmdate('c'));
        $stmt->execute();
    }

    /** @return array<string, mixed>|null */
    public function getDocket(int $clDocketId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT cl_docket_id, court_id, docket_number, case_name, raw_json, updated_at
             FROM courtlistener_dockets WHERE cl_docket_id = :id'
        );
        $stmt->bindValue(':id', $clDocketId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function linksForCase(string $caseId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT l.case_id, l.cl_docket_id, l.match_confidence, l.match_method, l.raw_json,
                    d.court_id, d.docket_number, d.case_name AS cl_case_name
             FROM case_courtlistener_links l
             LEFT JOIN courtlistener_dockets d ON d.cl_docket_id = l.cl_docket_id
             WHERE l.case_id = :case_id
             ORDER BY (l.match_confidence IS NULL), l.match_confidence DESC, l.cl_docket_id ASC'
        );
        $stmt->bindValue(':case_id', $caseId);
        $stmt->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }
}
