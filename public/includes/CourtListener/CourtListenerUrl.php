<?php
declare(strict_types=1);

namespace Project1960\CourtListener;

/**
 * CourtListener public URLs.
 *
 * Bare /docket/{id}/ 404s — CL requires a slug segment: /docket/{id}/{slug}/.
 */
final class CourtListenerUrl
{
    public static function docket(int $clDocketId, ?string $caseName = null): string
    {
        if ($clDocketId <= 0) {
            return '';
        }
        $slug = self::slug($caseName);
        if ($slug === '') {
            $slug = 'docket';
        }

        return 'https://www.courtlistener.com/docket/' . $clDocketId . '/' . $slug . '/';
    }

    public static function slug(?string $caseName): string
    {
        $s = strtolower(trim((string) $caseName));
        if ($s === '') {
            return '';
        }
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        $s = trim($s, '-');
        if (strlen($s) > 80) {
            $s = substr($s, 0, 80);
            $s = rtrim($s, '-');
        }

        return $s;
    }
}
