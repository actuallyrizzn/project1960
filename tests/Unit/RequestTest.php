<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Project1960\Request;

final class RequestTest extends TestCase
{
    public function testFromGlobalsParsesMethodAndPath(): void
    {
        $req = Request::fromGlobals([
            'REQUEST_METHOD' => 'get',
            'REQUEST_URI' => '/health?full=1',
        ]);

        self::assertSame('GET', $req->method);
        self::assertSame('/health', $req->path);
    }

    public function testFromGlobalsDefaults(): void
    {
        $req = Request::fromGlobals([]);

        self::assertSame('GET', $req->method);
        self::assertSame('/', $req->path);
    }

    public function testFromGlobalsEmptyPathBecomesSlash(): void
    {
        $req = Request::fromGlobals([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '',
        ]);

        self::assertSame('/', $req->path);
    }
}
