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
        $s->record('ambiguous');
        $s->record('no_match');
        $s->recordError();
        $a = $s->toArray();
        self::assertSame(5, $a['processed']);
        self::assertSame(2, $a['matched']);
        self::assertSame(1, $a['ambiguous']);
        self::assertSame(1, $a['no_match']);
        self::assertSame(1, $a['errors']);
        self::assertSame(0.5, $a['match_rate']); // 2/4 non-error
        self::assertStringContainsString('match_rate=50.0%', $s->summaryLine());
    }
}
