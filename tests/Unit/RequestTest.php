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
            'QUERY_STRING' => 'full=1',
        ]);

        self::assertSame('GET', $req->method);
        self::assertSame('/health', $req->path);
        self::assertSame('1', $req->query('full'));
    }

    public function testFromGlobalsDefaults(): void
    {
        $req = Request::fromGlobals([]);

        self::assertSame('GET', $req->method);
        self::assertSame('/', $req->path);
        self::assertSame([], $req->query);
    }

    public function testFromGlobalsEmptyPathBecomesSlash(): void
    {
        $req = Request::fromGlobals([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '',
        ]);

        self::assertSame('/', $req->path);
    }

    public function testQueryIntDefaultsOnJunk(): void
    {
        $req = new Request('GET', '/cases', ['page' => 'abc']);
        self::assertSame(3, $req->queryInt('page', 3));
        $req2 = new Request('GET', '/cases', ['page' => '2']);
        self::assertSame(2, $req2->queryInt('page', 1));
    }

    public function testFromGlobalsAcceptsGetArray(): void
    {
        $req = Request::fromGlobals(
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/cases'],
            ['search' => 'fixture', 'page' => '2']
        );
        self::assertSame('fixture', $req->query('search'));
        self::assertSame(2, $req->queryInt('page', 1));
    }
}
