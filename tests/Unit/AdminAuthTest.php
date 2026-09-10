<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Project1960\AdminAuth;
use Project1960\Csrf;
use Project1960\Schema;

final class AdminAuthTest extends TestCase
{
    private string $path;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/p1960_auth_' . bin2hex(random_bytes(4)) . '.db';
        $this->pdo = new PDO('sqlite:' . $this->path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        Schema::migrate($this->pdo);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];

        $hash = password_hash('correct-horse', PASSWORD_DEFAULT);
        $this->pdo->exec(
            "INSERT INTO admin_users (username, email, password_hash, role)
             VALUES ('mark', 'mark@example.com', " . $this->pdo->quote($hash) . ", 'operator')"
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testLoginSuccessAndLogout(): void
    {
        $auth = new AdminAuth($this->pdo);
        self::assertFalse($auth->check());
        self::assertTrue($auth->login('mark', 'correct-horse'));
        self::assertTrue($auth->check());
        self::assertSame('mark', $auth->user()['username'] ?? null);
        self::assertTrue($auth->isOperator());
        self::assertSame(1, $auth->userId());
        self::assertSame('operator', $auth->role());

        // Re-hydrate from session
        $re = new AdminAuth($this->pdo);
        self::assertTrue($re->check());
        self::assertSame('mark', $re->user()['username'] ?? null);

        $auth->logout();
        self::assertFalse($auth->check());

        $again = new AdminAuth($this->pdo);
        self::assertFalse($again->check());
    }

    public function testHydrateClearsMissingOrInactiveUser(): void
    {
        $auth = new AdminAuth($this->pdo);
        self::assertTrue($auth->login('mark', 'correct-horse'));
        $this->pdo->exec('UPDATE admin_users SET is_active = 0');
        $stale = new AdminAuth($this->pdo);
        self::assertFalse($stale->check());
    }

    public function testSetUserForTests(): void
    {
        $auth = new AdminAuth($this->pdo);
        $auth->setUserForTests([
            'id' => 99,
            'username' => 'tmp',
            'email' => 't@e.com',
            'password_hash' => 'x',
            'role' => 'readonly',
            'is_active' => 1,
        ]);
        self::assertTrue($auth->check());
        self::assertSame(99, $auth->userId());
        self::assertFalse($auth->isOperator());
        self::assertSame('readonly', $auth->role());
    }

    public function testLoginFailsBadPassword(): void
    {
        $auth = new AdminAuth($this->pdo);
        self::assertFalse($auth->login('mark', 'wrong'));
        self::assertFalse($auth->check());
    }

    public function testLoginByEmail(): void
    {
        $auth = new AdminAuth($this->pdo);
        self::assertTrue($auth->login('mark@example.com', 'correct-horse'));
    }

    public function testInactiveUserCannotLogin(): void
    {
        $this->pdo->exec('UPDATE admin_users SET is_active = 0');
        $auth = new AdminAuth($this->pdo);
        self::assertFalse($auth->login('mark', 'correct-horse'));
    }

    public function testCsrfRejectsBadToken(): void
    {
        $good = Csrf::token();
        self::assertTrue(Csrf::verify($good));
        self::assertFalse(Csrf::verify('nope'));
        self::assertFalse(Csrf::verify(null));
        self::assertFalse(Csrf::verify(''));
    }

    public function testCsrfRequireThrows(): void
    {
        Csrf::token();
        $this->expectException(\RuntimeException::class);
        Csrf::requireValid('bad');
    }

    public function testCsrfRequireValidPasses(): void
    {
        $t = Csrf::token();
        Csrf::requireValid($t);
        self::assertTrue(true);
    }

    public function testCsrfInputFieldContainsToken(): void
    {
        $html = Csrf::inputField();
        self::assertStringContainsString('name="csrf_token"', $html);
        self::assertStringContainsString(Csrf::token(), $html);
    }

    public function testTokenFromRequestPrefersPost(): void
    {
        self::assertSame(
            'abc',
            Csrf::tokenFromRequest(['csrf_token' => 'abc'], ['HTTP_X_CSRF_TOKEN' => 'hdr'])
        );
        self::assertSame(
            'hdr',
            Csrf::tokenFromRequest([], ['HTTP_X_CSRF_TOKEN' => 'hdr'])
        );
        self::assertNull(Csrf::tokenFromRequest([], []));
    }

    public function testSessionBootWhenNoneAndCsrfWithoutToken(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        self::assertSame(PHP_SESSION_NONE, session_status());

        $auth = new AdminAuth($this->pdo);
        self::assertSame(PHP_SESSION_ACTIVE, session_status());
        self::assertFalse($auth->check());

        // Fresh session has no csrf key yet — verify fails before token()
        $_SESSION = [];
        self::assertFalse(Csrf::verify('anything'));

        // Force Csrf ensureSession after another close
        session_write_close();
        self::assertSame(PHP_SESSION_NONE, session_status());
        $t = Csrf::token();
        self::assertNotSame('', $t);
        self::assertTrue(Csrf::verify($t));
    }
}
