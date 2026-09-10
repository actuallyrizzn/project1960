<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Project1960\AdminAuth;
use Project1960\AdminHelp;
use Project1960\AdminHelpController;
use Project1960\App;
use Project1960\Request;
use Project1960\Schema;

final class AdminHelpTest extends TestCase
{
    private string $path;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/p1960_help_' . bin2hex(random_bytes(4)) . '.db';
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

    public function testTopicsAndBodies(): void
    {
        self::assertCount(4, AdminHelp::topics());
        self::assertSame('bootstrap', AdminHelp::normalizeSlug('Bootstrap'));
        self::assertNull(AdminHelp::normalizeSlug('nope'));
        $t = AdminHelp::topic('api-scopes');
        self::assertStringContainsString('stats:read', $t['body']);
        foreach (['bootstrap', 'enrichment-math', 'pipeline-cli'] as $slug) {
            self::assertNotSame('', AdminHelp::topic($slug)['body']);
        }
        try {
            AdminHelp::topic('missing');
            self::fail('expected');
        } catch (\InvalidArgumentException) {
        }
    }

    public function testControllerPages(): void
    {
        (new AdminAuth($this->pdo))->login('ops', 'long-enough-secret');
        $ctl = new AdminHelpController($this->pdo);
        $idx = $ctl->index();
        self::assertSame(200, $idx->status);
        self::assertStringContainsString('Pipeline CLIs', $idx->body);
        $show = $ctl->show('bootstrap');
        self::assertStringContainsString('bootstrap-admin.php', $show->body);
        $missing = $ctl->show('nope');
        self::assertSame(302, $missing->status);
        self::assertSame('/admin/help', $missing->headers['Location'] ?? null);
    }

    public function testAppRoutes(): void
    {
        $app = new App(null, null, $this->pdo);
        $deny = $app->handle(new Request('GET', '/admin/help'));
        self::assertSame(302, $deny->status);
        (new AdminAuth($this->pdo))->login('ops', 'long-enough-secret');
        $ok = $app->handle(new Request('GET', '/admin/help'));
        self::assertSame(200, $ok->status);
        $topic = $app->handle(new Request('GET', '/admin/help/enrichment-math'));
        self::assertSame(200, $topic->status);
        self::assertStringContainsString('verified', $topic->body);
    }
}
