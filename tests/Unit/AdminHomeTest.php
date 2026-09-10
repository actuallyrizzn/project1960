<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Project1960\AdminAuth;
use Project1960\AdminHomeController;
use Project1960\App;
use Project1960\Request;
use Project1960\Schema;

final class AdminHomeTest extends TestCase
{
    private string $path;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/p1960_home_' . bin2hex(random_bytes(4)) . '.db';
        $this->pdo = new PDO('sqlite:' . $this->path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        Schema::migrate($this->pdo);
        $hash = password_hash('long-enough-secret', PASSWORD_DEFAULT);
        $this->pdo->exec(
            "INSERT INTO admin_users (username, email, password_hash, role)
             VALUES ('ops', 'ops@ex.com', " . $this->pdo->quote($hash) . ", 'operator')"
        );
        $this->pdo->exec(
            "INSERT INTO cases (id, title, mentions_1960, verified_1960, mentions_crypto)
             VALUES ('c1', 'One', 1, 1, 0), ('c2', 'Two', 1, 0, 0)"
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

    public function testHomeShowsStatsAndLinks(): void
    {
        (new AdminAuth($this->pdo))->login('ops', 'long-enough-secret');
        $resp = (new AdminHomeController($this->pdo))->pageGet();
        self::assertSame(200, $resp->status);
        self::assertStringContainsString('Operator home', $resp->body);
        self::assertStringContainsString('Verified yes', $resp->body);
        self::assertStringContainsString('case_metadata', $resp->body);
        self::assertStringContainsString('/admin/pipeline', $resp->body);
        self::assertStringContainsString('ops', $resp->body);
    }

    public function testAppAdminHomeRequiresAuthThenRenders(): void
    {
        $app = new App(null, null, $this->pdo);
        $deny = $app->handle(new Request('GET', '/admin'));
        self::assertSame(302, $deny->status);
        (new AdminAuth($this->pdo))->login('ops', 'long-enough-secret');
        $ok = $app->handle(new Request('GET', '/admin'));
        self::assertSame(200, $ok->status);
        self::assertStringContainsString('Shortcuts', $ok->body);
    }
}
