<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit\CourtListener;

use PHPUnit\Framework\TestCase;
use Project1960\CourtListener\OcrCliOptions;

final class OcrCliOptionsTest extends TestCase
{
    public function testDefaults(): void
    {
        $o = OcrCliOptions::fromGetopt([]);
        self::assertSame(5, $o->limit);
        self::assertSame(1, $o->wait);
        self::assertFalse($o->dryRun);
        self::assertFalse($o->metricsOnly);
        self::assertFalse($o->verbose);
        self::assertFalse($o->help);
    }

    public function testParsesFlags(): void
    {
        $o = OcrCliOptions::fromGetopt([
            'limit' => '12',
            'wait' => '0',
            'dry-run' => false,
            'metrics' => false,
            'verbose' => false,
            'help' => false,
        ]);
        self::assertSame(12, $o->limit);
        self::assertSame(0, $o->wait);
        self::assertTrue($o->dryRun);
        self::assertTrue($o->metricsOnly);
        self::assertTrue($o->verbose);
        self::assertTrue($o->help);
    }

    public function testClampsLimit(): void
    {
        $o = OcrCliOptions::fromGetopt(['limit' => '0']);
        self::assertSame(1, $o->limit);
    }
}
