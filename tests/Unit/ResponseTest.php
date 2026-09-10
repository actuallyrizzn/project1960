<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Project1960\Response;

final class ResponseTest extends TestCase
{
    public function testJsonEncodesBody(): void
    {
        $response = Response::json(['ok' => true]);

        self::assertSame(200, $response->status);
        self::assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
        self::assertSame('{"ok":true}', $response->body);
    }

    public function testTextAndHtmlHelpers(): void
    {
        $text = Response::text('hi', 201);
        $html = Response::html('<p>x</p>');

        self::assertSame(201, $text->status);
        self::assertStringContainsString('text/plain', $text->headers['Content-Type']);
        self::assertStringContainsString('text/html', $html->headers['Content-Type']);
    }

    public function testSendEmitsBody(): void
    {
        $response = Response::text('emitted');
        ob_start();
        $response->send();
        $out = ob_get_clean();
        self::assertSame('emitted', $out);
    }
}
