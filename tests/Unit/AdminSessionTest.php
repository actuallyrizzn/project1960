<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Project1960\AdminSession;
use Project1960\Csrf;

final class AdminSessionTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];
    }

    public function testCsrfAndAuthShareCookieName(): void
    {
        self::assertSame(PHP_SESSION_NONE, session_status());
        $token = Csrf::token();
        self::assertSame(AdminSession::COOKIE_NAME, session_name());
        self::assertTrue(Csrf::verify($token));
        AdminSession::start();
        self::assertSame(AdminSession::COOKIE_NAME, session_name());
        self::assertTrue(Csrf::verify($token));
    }
}
