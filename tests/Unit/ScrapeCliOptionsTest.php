<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Project1960\Scraper\ScrapeCliOptions;

final class ScrapeCliOptionsTest extends TestCase
{
    public function testDefaults(): void
    {
        $o = ScrapeCliOptions::fromArgv(['scrape.php']);
        self::assertNull($o->maxPages);
        self::assertNull($o->limit);
        self::assertNull($o->pageStart);
        self::assertSame(2, $o->waitSeconds);
        self::assertFalse($o->dryRun);
        self::assertFalse($o->verbose);
        self::assertFalse($o->help);
    }

    public function testAllFlagsEqualsForm(): void
    {
        $o = ScrapeCliOptions::fromArgv([
            'scrape.php',
            '--max-pages=3',
            '--page-start=10',
            '--wait=5',
            '--dry-run',
            '--verbose',
        ]);
        self::assertSame(3, $o->maxPages);
        self::assertSame(10, $o->pageStart);
        self::assertSame(5, $o->waitSeconds);
        self::assertTrue($o->dryRun);
        self::assertTrue($o->verbose);
    }

    public function testLimitAliasesMaxPages(): void
    {
        $o = ScrapeCliOptions::fromArgv(['scrape.php', '--limit=4']);
        self::assertSame(4, $o->limit);
        self::assertSame(4, $o->maxPages);
    }

    public function testMaxPagesWinsOverLimit(): void
    {
        $o = ScrapeCliOptions::fromArgv(['scrape.php', '--limit=9', '--max-pages=2']);
        self::assertSame(2, $o->maxPages);
        self::assertSame(9, $o->limit);
    }

    public function testSpaceSeparatedValues(): void
    {
        $o = ScrapeCliOptions::fromArgv(['scrape.php', '--wait', '7', '--page-start', '1']);
        self::assertSame(7, $o->waitSeconds);
        self::assertSame(1, $o->pageStart);
    }

    public function testHelpFlag(): void
    {
        $o = ScrapeCliOptions::fromArgv(['scrape.php', '--help']);
        self::assertTrue($o->help);
        self::assertStringContainsString('--dry-run', ScrapeCliOptions::helpText());
        self::assertStringContainsString('Cron example', ScrapeCliOptions::helpText());
    }

    public function testFromGetoptDirect(): void
    {
        $o = ScrapeCliOptions::fromGetopt([
            'dry-run' => false,
            'verbose' => false,
            'wait' => '0',
            'max-pages' => '1',
        ]);
        self::assertTrue($o->dryRun);
        self::assertTrue($o->verbose);
        self::assertSame(0, $o->waitSeconds);
        self::assertSame(1, $o->maxPages);
    }

    public function testInvalidIntBecomesNull(): void
    {
        $o = ScrapeCliOptions::fromGetopt(['max-pages' => 'nope', 'wait' => '']);
        self::assertNull($o->maxPages);
        self::assertSame(2, $o->waitSeconds);
    }
}
