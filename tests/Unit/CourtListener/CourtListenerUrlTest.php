<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit\CourtListener;

use PHPUnit\Framework\TestCase;
use Project1960\CourtListener\CourtListenerUrl;

final class CourtListenerUrlTest extends TestCase
{
    public function testDocketUrlRequiresSlug(): void
    {
        $url = CourtListenerUrl::docket(68380374, 'United States v. FLASHDOT LIMITED');
        self::assertSame(
            'https://www.courtlistener.com/docket/68380374/united-states-v-flashdot-limited/',
            $url
        );
        self::assertStringNotContainsString('/docket/68380374/', str_replace('/docket/68380374/united-states-v-flashdot-limited/', '', $url));
    }

    public function testFallbackSlug(): void
    {
        self::assertSame(
            'https://www.courtlistener.com/docket/1/docket/',
            CourtListenerUrl::docket(1, null)
        );
    }
}
