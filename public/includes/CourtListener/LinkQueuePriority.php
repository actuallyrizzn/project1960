<?php
declare(strict_types=1);

namespace Project1960\CourtListener;

/**
 * Work-queue ordering for CourtListener links.
 *
 * Weak accepts still get document ingest/download so fact patterns can be
 * checked, but OCR + Venice extract run them last so inference prefers
 * high-confidence dockets.
 */
final class LinkQueuePriority
{
    public const WEAK_METHOD = 'weak_accept';

    public static function isWeakMethod(?string $method): bool
    {
        return $method === self::WEAK_METHOD;
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
