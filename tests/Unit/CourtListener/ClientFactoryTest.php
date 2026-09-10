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

    public function testApiTokenMissingThrows(): void
    {
        $this->expectException(RuntimeException::class);
        ClientFactory::apiToken(['COURTLISTENER_API_TOKEN' => '']);
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
}
