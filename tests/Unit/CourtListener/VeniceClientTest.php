<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit\CourtListener;

use PHPUnit\Framework\TestCase;
use Project1960\CourtListener\VeniceClient;

final class VeniceClientTest extends TestCase
{
    public function testCompleteParsesContent(): void
    {
        $client = new VeniceClient('key', transport: static function () {
            return [
                'ok' => true,
                'status' => 200,
                'body' => json_encode([
                    'choices' => [['message' => ['content' => '[{"display_name":"A"}]']]],
                ], JSON_THROW_ON_ERROR),
            ];
        });
        $r = $client->complete('hi');
        self::assertTrue($r['ok']);
        self::assertStringContainsString('display_name', $r['content']);
    }

    public function testHttpError(): void
    {
        $client = new VeniceClient('key', transport: static fn () => ['ok' => true, 'status' => 429, 'body' => '{}']);
        $r = $client->complete('x');
        self::assertFalse($r['ok']);
        self::assertStringContainsString('429', (string) ($r['error'] ?? ''));
    }

    public function testTransportFailureAndEmpty(): void
    {
        $fail = new VeniceClient('key', transport: static fn () => ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'down']);
        self::assertFalse($fail->complete('x')['ok']);
        $empty = new VeniceClient('key', transport: static fn () => [
            'ok' => true,
            'status' => 200,
            'body' => json_encode(['choices' => [['message' => ['content' => '']]]], JSON_THROW_ON_ERROR),
        ]);
        self::assertFalse($empty->complete('x')['ok']);
    }

    public function testFromEnvRequiresKey(): void
    {
        $prev = getenv('VENICE_API_KEY');
        putenv('VENICE_API_KEY');
        putenv('VENICE_INFERENCE_KEY');
        try {
            $this->expectException(\RuntimeException::class);
            VeniceClient::fromEnv();
        } finally {
            if ($prev !== false) {
                putenv('VENICE_API_KEY=' . $prev);
            }
        }
    }
}
