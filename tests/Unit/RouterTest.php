<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Project1960\Request;
use Project1960\Response;
use Project1960\Router;

final class RouterTest extends TestCase
{
    public function testDispatchesRegisteredGetRoute(): void
    {
        $router = new Router();
        $router->get('/ping', static fn (Request $r): Response => Response::text('pong'));

        $response = $router->dispatch(new Request('GET', '/ping'));

        self::assertSame(200, $response->status);
        self::assertSame('pong', $response->body);
    }

    public function testNormalizesTrailingSlash(): void
    {
        $router = new Router();
        $router->get('/ping', static fn (Request $r): Response => Response::text('pong'));

        $response = $router->dispatch(new Request('GET', '/ping/'));

        self::assertSame('pong', $response->body);
    }

    public function testReturns404ForUnknownRoute(): void
    {
        $router = new Router();
        $response = $router->dispatch(new Request('GET', '/missing'));

        self::assertSame(404, $response->status);
        self::assertSame('Not Found', $response->body);
    }

    public function testStripsQueryStringFromPath(): void
    {
        $router = new Router();
        $router->get('/ping', static fn (Request $r): Response => Response::text('pong'));

        $response = $router->dispatch(new Request('GET', '/ping?x=1'));

        self::assertSame('pong', $response->body);
    }

    public function testEmptyPathNormalizesToRoot(): void
    {
        $router = new Router();
        $router->get('/', static fn (Request $r): Response => Response::text('home'));

        $response = $router->dispatch(new Request('GET', ''));

        self::assertSame('home', $response->body);
    }
}
