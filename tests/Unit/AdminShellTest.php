<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Project1960\AdminAuth;
use Project1960\AdminShell;
use Project1960\App;
use Project1960\Request;
use Project1960\Schema;
use Project1960\View;

final class AdminShellTest extends TestCase
{
    private string $path;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/p1960_shell_' . bin2hex(random_bytes(4)) . '.db';
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

    public function testNavAndActive(): void
    {
        $items = AdminShell::navItems();
        self::assertCount(6, $items);
        self::assertTrue(AdminShell::isActive('/admin', '/admin'));
        self::assertFalse(AdminShell::isActive('/admin', '/admin/users'));
        self::assertTrue(AdminShell::isActive('/admin/users', '/admin/users'));
    }

    public function testRequireAuthRedirects(): void
    {
        $auth = new AdminAuth($this->pdo);
        $deny = AdminShell::requireAuth($auth);
        self::assertNotNull($deny);
        self::assertSame(302, $deny->status);
        self::assertSame('/admin/login', $deny->headers['Location']);
    }

    public function testRenderPageWhenAuthed(): void
    {
        $auth = new AdminAuth($this->pdo);
        self::assertTrue($auth->login('ops', 'long-enough-secret'));
        self::assertNull(AdminShell::requireAuth($auth));
        $resp = AdminShell::renderPage(new View(), 'admin/home', [
            'title' => 'Home',
            'currentPath' => '/admin',
            'adminUser' => $auth->user(),
        ]);
        self::assertSame(200, $resp->status);
        self::assertStringContainsString('Project 1960 Admin', $resp->body);
        self::assertStringContainsString('API Keys', $resp->body);
        self::assertStringContainsString('ops', $resp->body);
    }

    public function testAppAdminRedirectsWhenAnonymous(): void
    {
        $app = new App(null, null, $this->pdo);
        $resp = $app->handle(new Request('GET', '/admin'));
        self::assertSame(302, $resp->status);
    }
}
