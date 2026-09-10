<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Project1960\AdminLogin;
use Project1960\App;
use Project1960\Csrf;
use Project1960\Request;
use Project1960\Schema;

final class AdminLoginTest extends TestCase
{
    private string $path;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/p1960_login_' . bin2hex(random_bytes(4)) . '.db';
        $this->pdo = new PDO('sqlite:' . $this->path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        Schema::migrate($this->pdo);
        $hash = password_hash('long-enough-secret', PASSWORD_DEFAULT);
        $this->pdo->exec(
            "INSERT INTO admin_users (username, email, password_hash, role)
             VALUES ('ops', 'ops@ex.com', " . $this->pdo->quote($hash) . ", 'operator')"
        );
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testShowLoginForm(): void
    {
        $html = (new AdminLogin($this->pdo))->show()->body;
        self::assertStringContainsString('csrf_token', $html);
        self::assertStringContainsString('Sign in', $html);
    }

    public function testLoginSuccessAndLogout(): void
    {
        $login = new AdminLogin($this->pdo);
        $token = Csrf::token();
        $ok = $login->attempt([
            'csrf_token' => $token,
            'username' => 'ops',
            'password' => 'long-enough-secret',
        ]);
        self::assertSame(302, $ok->status);
        self::assertSame('/admin/', $ok->headers['Location']);

        $out = $login->logout();
        self::assertSame(302, $out->status);
        self::assertSame('/admin/login/', $out->headers['Location']);
    }

    public function testLoginFailsBadPasswordAndBadCsrf(): void
    {
        $login = new AdminLogin($this->pdo);
        Csrf::token();
        $bad = $login->attempt([
            'csrf_token' => Csrf::token(),
            'username' => 'ops',
            'password' => 'wrong-password!!',
        ]);
        self::assertSame(200, $bad->status);
        self::assertStringContainsString('Invalid username or password', $bad->body);

        $csrf = $login->attempt([
            'csrf_token' => 'nope',
            'username' => 'ops',
            'password' => 'long-enough-secret',
        ]);
        self::assertStringContainsString('Invalid security token', $csrf->body);
    }

    public function testAppLoginRoutes(): void
    {
        $app = new App(null, null, $this->pdo);
        $get = $app->handle(new Request('GET', '/admin/login'));
        self::assertSame(200, $get->status);
        self::assertStringContainsString('Sign in', $get->body);

        $post = $app->handle(new Request(
            'POST',
            '/admin/login',
            [],
            [],
            [],
            [
                'csrf_token' => Csrf::token(),
                'username' => 'ops',
                'password' => 'long-enough-secret',
            ]
        ));
        self::assertSame(302, $post->status);
    }
}
