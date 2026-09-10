<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Project1960\App;
use Project1960\Request;

final class AppTest extends TestCase
{
    public function testHomeReturnsHtml(): void
    {
        $app = new App();
        $response = $app->handle(new Request('GET', '/'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Project 1960', $response->body);
        self::assertStringContainsString('text/html', $response->headers['Content-Type']);
    }

    public function testHealthReturnsJson(): void
    {
        $app = new App();
        $response = $app->handle(new Request('GET', '/health'));

        self::assertSame(200, $response->status);
        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data['ok']);
        self::assertSame('project1960', $data['service']);
        self::assertSame('project1960.rizzn.net', $data['host']);
    }

    public function testUnknownIs404(): void
    {
        $app = new App();
        $response = $app->handle(new Request('GET', '/nope'));

        self::assertSame(404, $response->status);
    }
}
