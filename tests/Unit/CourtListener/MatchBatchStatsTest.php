<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit\CourtListener;

use PHPUnit\Framework\TestCase;
use Project1960\CourtListener\MatchBatchStats;

final class MatchBatchStatsTest extends TestCase
{
    public function testAggregatesOutcomesAndMatchRate(): void
    {
        $s = new MatchBatchStats();
        $s->record('matched');
        $s->record('dry_run_matched');
        $s->record('weak_accept');
        $s->record('ambiguous');
        $s->record('no_match');
        $s->recordError();
        $a = $s->toArray();
        self::assertSame(6, $a['processed']);
        self::assertSame(2, $a['matched']);
        self::assertSame(1, $a['weak_accept']);
        self::assertSame(1, $a['ambiguous']);
        self::assertSame(1, $a['no_match']);
        self::assertSame(1, $a['errors']);
        // (2 matched + 1 weak) / 5 non-error
        self::assertSame(0.6, $a['match_rate']);
        self::assertStringContainsString('weak_accept=1', $s->summaryLine());
        self::assertStringContainsString('match_rate=60.0%', $s->summaryLine());
    }
}
