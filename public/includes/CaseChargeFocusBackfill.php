<?php
declare(strict_types=1);

namespace Project1960;

use Project1960\Scraper\PressReleaseChargeParser;
use PDO;

/**
 * Backfill charges / case_docket_refs / CL link relevance from press bodies.
 */
final class CaseChargeFocusBackfill
{
    public function __construct(
        private PDO $pdo,
        private PressReleaseChargeParser $parser = new PressReleaseChargeParser(),
        private ?CaseChargeStore $store = null,
    ) {
        $this->store ??= new CaseChargeStore($pdo);
    }

    /**
     * @return list<string> case ids
     */
    public function selectCaseIds(string $cohort, ?int $limit = null, ?string $onlyCaseId = null): array
    {
        if ($onlyCaseId !== null && $onlyCaseId !== '') {
            return [$onlyCaseId];
        }
        $where = match ($cohort) {
            'chokepoint' => 'CAST(verified_1960 AS INTEGER) = 1 AND CAST(mentions_crypto AS INTEGER) = 1',
            'drip' => 'CAST(verified_1960 AS INTEGER) = 1 OR CAST(mentions_crypto AS INTEGER) = 1',
            'verified' => 'CAST(verified_1960 AS INTEGER) = 1',
            'crypto' => 'CAST(mentions_crypto AS INTEGER) = 1',
            'all' => '1=1',
            default => throw new \InvalidArgumentException('Unknown cohort: ' . $cohort),
        };
        $sql = "SELECT id FROM cases WHERE {$where} ORDER BY date DESC";
        if ($limit !== null && $limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit;
        }

        return array_map('strval', $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * @return array{
     *   case_id: string,
     *   mode: string,
     *   charges_upserted: int,
     *   charges_1960: int,
     *   docket_refs: int,
     *   existing_flagged: int,
     *   links_tagged: int,
     *   dry_run: bool
     * }
     */
    public function processCase(string $caseId, bool $dryRun = false): array
    {
        $stmt = $this->pdo->prepare('SELECT id, title, body FROM cases WHERE id = ?');
        $stmt->execute([$caseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \RuntimeException('Unknown case_id: ' . $caseId);
        }
        $parsed = $this->parser->parse((string) ($row['body'] ?? ''));
        $charges1960 = 0;
        $upserted = 0;
        foreach ($parsed['charges'] as $ch) {
            if ($ch['is_1960']) {
                $charges1960++;
            }
            if (!$dryRun) {
                $this->store->upsertCharge([
                    'case_id' => $caseId,
                    'defendant' => $ch['defendant'],
                    'charge_description' => $ch['charge_description'],
                    'count_num' => $ch['count_num'],
                    'status' => $ch['status'],
                    'is_1960' => $ch['is_1960'],
                    'verified_1960' => $ch['is_1960'] ? null : 0,
                    'source' => 'press',
                ]);
            }
            $upserted++;
        }

        $refsN = count($parsed['docket_refs']);
        if (!$dryRun && $refsN > 0) {
            $this->store->upsertDocketRefs($caseId, array_map(
                static fn (array $r): array => $r + ['source' => 'press'],
                $parsed['docket_refs']
            ));
        }

        $flagged = $this->flagExistingCharges($caseId, $dryRun);
        $links = $this->tagLinkRelevance(
            $caseId,
            $dryRun ? ($charges1960 + $flagged) : null,
            $dryRun
        );

        return [
            'case_id' => $caseId,
            'mode' => $parsed['mode'],
            'charges_upserted' => $upserted,
            'charges_1960' => $charges1960,
            'docket_refs' => $refsN,
            'existing_flagged' => $flagged,
            'links_tagged' => $links,
            'dry_run' => $dryRun,
        ];
    }

    private function flagExistingCharges(string $caseId, bool $dryRun): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT charge_id, charge_description, statute, is_1960 FROM charges WHERE case_id = ?'
        );
        $stmt->execute([$caseId]);
        $n = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $blob = trim((string) ($row['charge_description'] ?? '') . ' ' . (string) ($row['statute'] ?? ''));
            if ($blob === '' || !PressReleaseChargeParser::chargeLooksLike1960($blob)) {
                continue;
            }
            if ((int) ($row['is_1960'] ?? 0) === 1) {
                continue;
            }
            $n++;
            if (!$dryRun) {
                $upd = $this->pdo->prepare(
                    'UPDATE charges SET is_1960 = 1, updated_at = ? WHERE charge_id = ?'
                );
                $upd->execute([gmdate('c'), (int) $row['charge_id']]);
            }
        }

        return $n;
    }

    /**
     * @param ?int $dryRun1960Count when dry-run, approximate is_1960 count including this pass
     */
    private function tagLinkRelevance(string $caseId, ?int $dryRun1960Count, bool $dryRun): int
    {
        $cstmt = $this->pdo->prepare('SELECT COUNT(*) FROM charges WHERE case_id = ?');
        $cstmt->execute([$caseId]);
        $total = (int) $cstmt->fetchColumn();

        $istmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM charges WHERE case_id = ? AND CAST(COALESCE(is_1960, 0) AS INTEGER) = 1'
        );
        $istmt->execute([$caseId]);
        $is1960 = (int) $istmt->fetchColumn();
        if ($dryRun && $dryRun1960Count !== null) {
            $is1960 = max($is1960, $dryRun1960Count);
            // structured pass may add many non-1960 charges not yet in DB
            $total = max($total, $is1960);
        }

        if ($is1960 <= 0) {
            return 0;
        }

        // Mixed enterprise PR → ambient lead docket; pure UMT set → primary_1960.
        $relevance = ($total > $is1960) ? 'ambient' : 'primary_1960';

        $links = $this->pdo->prepare(
            'SELECT cl_docket_id FROM case_courtlistener_links WHERE case_id = ?'
        );
        $links->execute([$caseId]);
        $ids = $links->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $n = 0;
        foreach ($ids as $docketId) {
            $n++;
            if ($dryRun) {
                continue;
            }
            $focusChargeId = null;
            $fc = $this->pdo->prepare(
                'SELECT charge_id FROM charges
                 WHERE case_id = ? AND CAST(COALESCE(is_1960, 0) AS INTEGER) = 1
                 ORDER BY charge_id LIMIT 1'
            );
            $fc->execute([$caseId]);
            $fid = $fc->fetchColumn();
            if ($fid !== false) {
                $focusChargeId = (int) $fid;
            }
            $this->store->setLinkRelevance($caseId, (int) $docketId, $relevance, $focusChargeId);
        }

        return $n;
    }
}
