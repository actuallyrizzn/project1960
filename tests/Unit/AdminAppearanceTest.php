<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Project1960\AdminAppearanceController;
use Project1960\AdminAuth;
use Project1960\App;
use Project1960\Csrf;
use Project1960\Request;
use Project1960\Schema;
use Project1960\SiteSettings;
use Project1960\SkinLab;
use Project1960\View;

final class AdminAppearanceTest extends TestCase
{
    private string $path;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/p1960_appearance_' . bin2hex(random_bytes(4)) . '.db';
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

    public function testSiteSettingsRoundTrip(): void
    {
        $s = new SiteSettings($this->pdo);
        self::assertNull($s->get('missing'));
        self::assertSame('d', $s->get('missing', 'd'));
        $s->set('k1', 'v1');
        self::assertSame('v1', $s->get('k1'));
        $s->set('k1', 'v2');
        self::assertSame('v2', $s->get('k1'));
        self::assertSame(['k1' => 'v2'], $s->all());
    }

    public function testSkinLabPersistAndPreview(): void
    {
        $lab = new SkinLab($this->pdo);
        self::assertSame('hey', $lab->masterSlug());
        self::assertSame('obsidian', $lab->setMasterSlug('Obsidian'));
        self::assertSame('dark', SkinLab::bootstrapTheme('obsidian'));
        self::assertSame('light', SkinLab::bootstrapTheme('hey'));
        self::assertSame('ledger', $lab->effectiveSlug('ledger'));
        self::assertSame('obsidian', $lab->effectiveSlug(null));
        self::assertSame('Project 1960', $lab->siteName());
        self::assertSame('Empanada Ops', $lab->setSiteName(' Empanada Ops '));
        self::assertNull(SkinLab::normalize('nope'));
        self::assertCount(4, SkinLab::catalog());

        try {
            $lab->setMasterSlug('neon');
            self::fail('expected');
        } catch (\InvalidArgumentException) {
        }
        try {
            $lab->setSiteName('   ');
            self::fail('expected');
        } catch (\InvalidArgumentException) {
        }
        $long = str_repeat('a', 100);
        self::assertSame(80, strlen($lab->setSiteName($long)));
    }

    public function testAppearancePageSave(): void
    {
        $ctl = new AdminAppearanceController($this->pdo);
        (new AdminAuth($this->pdo))->login('ops', 'long-enough-secret');
        $get = $ctl->pageGet();
        self::assertSame(200, $get->status);
        self::assertStringContainsString('Appearance', $get->body);
        self::assertStringContainsString('data-skin="hey"', $get->body);

        $bad = $ctl->pagePost(['csrf_token' => 'x', 'action' => 'save']);
        self::assertStringContainsString('Invalid security token', $bad->body);

        $token = Csrf::token();
        $save = $ctl->pagePost([
            'csrf_token' => $token,
            'action' => 'save',
            'skin' => 'brutalist',
            'site_name' => 'P1960 Desk',
        ]);
        self::assertStringContainsString('Appearance saved', $save->body);
        self::assertStringContainsString('data-skin="brutalist"', $save->body);
        self::assertStringContainsString('P1960 Desk Admin', $save->body);

        $token = Csrf::token();
        $unknown = $ctl->pagePost(['csrf_token' => $token, 'action' => 'nope']);
        self::assertStringContainsString('Unknown action', $unknown->body);

        $token = Csrf::token();
        $invalid = $ctl->pagePost([
            'csrf_token' => $token,
            'action' => 'save',
            'skin' => 'neon',
            'site_name' => 'X',
        ]);
        self::assertStringContainsString('Unknown skin', $invalid->body);
    }

    public function testShellInjectsSkinFromPdo(): void
    {
        (new SkinLab($this->pdo))->setMasterSlug('obsidian');
        (new SkinLab($this->pdo))->setSiteName('Night Desk');
        $resp = \Project1960\AdminShell::renderPage(new View(), 'admin/home', [
            'title' => 'Home',
            'currentPath' => '/admin',
            'adminUser' => ['username' => 'ops'],
        ], $this->pdo);
        self::assertStringContainsString('data-skin="obsidian"', $resp->body);
        self::assertStringContainsString('data-bs-theme="dark"', $resp->body);
        self::assertStringContainsString('Night Desk Admin', $resp->body);
    }

    public function testAppRouteRequiresAuth(): void
    {
        $app = new App(null, null, $this->pdo);
        $deny = $app->handle(new Request('GET', '/admin/appearance'));
        self::assertSame(302, $deny->status);
    }
}
