<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Project1960\Scraper\KeywordFilters;

final class KeywordFiltersTest extends TestCase
{
    /**
     * @return list<array{0: string, 1: bool, 2: bool}>
     */
    public static function fixtureTable(): array
    {
        return [
            // text, expect_1960, expect_crypto
            ['Defendant charged under 18 U.S.C. 1960.', true, false],
            ['Violation of 18 USC 1960 and related counts.', true, false],
            ['Operating an unlicensed money transmitting business.', true, false],
            ['See § 1960 for the statute.', true, false],
            ['Routine wire fraud indictment only.', false, false],
            ['Seized Bitcoin wallets and Ethereum accounts.', false, true],
            ['Large cryptocurrency exchange prosecution.', false, true],
            ['Crypto mixer operators charged under 18 USC 1960.', true, true],
            ['CRYPTOCURRENCY AND 18.U.S.C.1960 TOGETHER', true, true],
            ['Charged under Title 18 Section 1960 without other keywords.', true, false],
            ['', false, false],
        ];
    }

    /**
     * @dataProvider fixtureTable
     */
    public function testFixtureStrings(string $text, bool $expect1960, bool $expectCrypto): void
    {
        self::assertSame($expect1960, KeywordFilters::mentions1960($text), '1960: ' . $text);
        self::assertSame($expectCrypto, KeywordFilters::mentionsCrypto($text), 'crypto: ' . $text);
    }
}
