<?php
declare(strict_types=1);

namespace Project1960\Scraper;

/**
 * Local keyword filters for 18 U.S.C. § 1960 and crypto mentions.
 *
 * Legacy used rapidfuzz partial_ratio > 80. This port uses regex + case-insensitive
 * substring checks (documented intentional simplification — no rapidfuzz dependency).
 */
final class KeywordFilters
{
    public const LAW_REGEX = '/(18[\s\.U.S.C]*1960|§\s*1960|unlicensed money transmitting)/i';

    /** @var list<string> */
    public const LAW_VARIATIONS = [
        '18 USC 1960',
        '§ 1960',
        'unlicensed money transmitting',
        // Soft aliases (legacy rapidfuzz >80); kept as substrings, not fuzzy scores
        'title 18 section 1960',
    ];

    /** @var list<string> */
    public const CRYPTO_TERMS = [
        'Bitcoin',
        'Ethereum',
        'crypto',
        'cryptocurrency',
    ];

    public static function mentions1960(string $text): bool
    {
        if ($text === '') {
            return false;
        }
        if (preg_match(self::LAW_REGEX, $text) === 1) {
            return true;
        }
        $hay = mb_strtolower($text);
        foreach (self::LAW_VARIATIONS as $term) {
            if (str_contains($hay, mb_strtolower($term))) {
                return true;
            }
        }

        return false;
    }

    public static function mentionsCrypto(string $text): bool
    {
        if ($text === '') {
            return false;
        }
        $hay = mb_strtolower($text);
        foreach (self::CRYPTO_TERMS as $term) {
            if (str_contains($hay, mb_strtolower($term))) {
                return true;
            }
        }

        return false;
    }
}
