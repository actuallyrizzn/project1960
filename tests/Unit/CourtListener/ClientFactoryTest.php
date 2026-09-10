<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit\CourtListener;

use CourtListener\CourtListenerClient;
use PHPUnit\Framework\TestCase;
use Project1960\CourtListener\ClientFactory;
use RuntimeException;

final class ClientFactoryTest extends TestCase
{
    public function testApiTokenFromEnvArray(): void
    {
        $token = ClientFactory::apiToken(['COURTLISTENER_API_TOKEN' => ' tok_test ']);
        self::assertSame('tok_test', $token);
    }

    public function testApiTokenAliasCourlistenerToken(): void
    {
        $token = ClientFactory::apiToken(['COURTLISTENER_TOKEN' => 'alias_tok']);
        self::assertSame('alias_tok', $token);
    }

    public function testApiTokenFromPutenv(): void
    {
        $prev = getenv('COURTLISTENER_API_TOKEN');
        putenv('COURTLISTENER_API_TOKEN=from_putenv');
        try {
            self::assertSame('from_putenv', ClientFactory::apiToken([]));
        } finally {
            if ($prev === false) {
                putenv('COURTLISTENER_API_TOKEN');
            } else {
                putenv('COURTLISTENER_API_TOKEN=' . $prev);
            }
        }
    }

    public function testApiTokenMissingThrows(): void
    {
        $prevA = getenv('COURTLISTENER_API_TOKEN');
        $prevB = getenv('COURTLISTENER_TOKEN');
        putenv('COURTLISTENER_API_TOKEN');
        putenv('COURTLISTENER_TOKEN');
        try {
            $this->expectException(RuntimeException::class);
            ClientFactory::apiToken([]);
        } finally {
            if ($prevA === false) {
                putenv('COURTLISTENER_API_TOKEN');
            } else {
                putenv('COURTLISTENER_API_TOKEN=' . $prevA);
            }
            if ($prevB === false) {
                putenv('COURTLISTENER_TOKEN');
            } else {
                putenv('COURTLISTENER_TOKEN=' . $prevB);
            }
        }
    }

    public function testMakeUsesInjectedFactory(): void
    {
        $fake = $this->createMock(CourtListenerClient::class);
        $seen = null;
        $client = ClientFactory::make(
            ['timeout' => 5],
            ['COURTLISTENER_API_TOKEN' => 'abc123'],
            static function (array $cfg) use (&$seen, $fake): CourtListenerClient {
                $seen = $cfg;
                return $fake;
            },
        );
        self::assertSame($fake, $client);
        self::assertSame('abc123', $seen['api_token'] ?? null);
        self::assertSame(5, $seen['timeout'] ?? null);
    }

    public function testMakeDefaultConstructsSdkClient(): void
    {
        $client = ClientFactory::make(
            [],
            ['COURTLISTENER_API_TOKEN' => 'unit_test_token_not_used'],
        );
        self::assertInstanceOf(CourtListenerClient::class, $client);
    }
}
