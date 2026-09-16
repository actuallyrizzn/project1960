<?php
declare(strict_types=1);

namespace Project1960\CourtListener;

/**
 * Work-queue ordering for CourtListener links.
 *
 * Weak accepts still get document ingest/download so fact patterns can be
 * checked, but OCR + Venice extract run them last so inference prefers
 * high-confidence dockets.
 *
 * Chokepoint 2.0 drip: verified §1960 + crypto mentions first, then crypto,
 * then verified-only.
 */
final class LinkQueuePriority
{
    public const WEAK_METHOD = 'weak_accept';

    public static function isWeakMethod(?string $method): bool
    {
        return $method === self::WEAK_METHOD;
    }

    /**
     * SQL CASE expression → 0..3 (lower = front of line).
     * 0 verified+crypto · 1 crypto · 2 verified · 3 other
     */
    public static function chokepointRankExpr(string $casesAlias = 'c'): string
    {
        $v = $casesAlias . '.verified_1960';
        $m = $casesAlias . '.mentions_crypto';

        return 'CASE'
            . " WHEN CAST({$v} AS INTEGER) = 1 AND CAST({$m} AS INTEGER) = 1 THEN 0"
            . " WHEN CAST({$m} AS INTEGER) = 1 THEN 1"
            . " WHEN CAST({$v} AS INTEGER) = 1 THEN 2"
            . ' ELSE 3 END';
    }

    /** PHP twin of chokepointRankExpr for in-memory seed sorts. */
    public static function chokepointRank(int|string|null $verified1960, int|string|null $mentionsCrypto): int
    {
        $v = (int) $verified1960 === 1;
        $c = (int) $mentionsCrypto === 1;
        if ($v && $c) {
            return 0;
        }
        if ($c) {
            return 1;
        }
        if ($v) {
            return 2;
        }

        return 3;
    }

    /**
     * SQL expression: 0 = process first (strong/unknown), 1 = defer (weak only).
     * Uses MIN so a docket also linked strongly is not deferred.
     */
    public static function deferRankSubquery(string $docketIdExpr = 'd.cl_docket_id'): string
    {
        return '(SELECT COALESCE(MIN(CASE WHEN l.match_method = '
            . "'" . self::WEAK_METHOD . "'"
            . ' THEN 1 ELSE 0 END), 0)'
            . ' FROM case_courtlistener_links l'
            . ' WHERE l.cl_docket_id = ' . $docketIdExpr . ')';
    }

    /** Column expression when a link row alias is already joined. */
    public static function deferRankColumn(string $linkAlias = 'e'): string
    {
        return 'CASE WHEN ' . $linkAlias . ".match_method = '" . self::WEAK_METHOD . "' THEN 1 ELSE 0 END";
    }
}
