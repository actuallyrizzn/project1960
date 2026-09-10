<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Project1960\AdminAuth;
use Project1960\AdminPipelineController;
use Project1960\ApiKeys;
use Project1960\App;
use Project1960\Csrf;
use Project1960\PipelineControls;
use Project1960\Request;
use Project1960\Schema;

final class PipelineControlsTest extends TestCase
{
    private string $path;
    private PDO $pdo;
    private string $opsKey;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/p1960_pctl_' . bin2hex(random_bytes(4)) . '.db';
        $this->pdo = new PDO('sqlite:' . $this->path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        Schema::migrate($this->pdo);
        $hash = password_hash('long-enough-secret', PASSWORD_DEFAULT);
        $this->pdo->exec(
            "INSERT INTO admin_users (username, email, password_hash, role)
             VALUES ('ops', 'ops@ex.com', " . $this->pdo->quote($hash) . ", 'operator')"
        );
        $opsId = (int) $this->pdo->query('SELECT id FROM admin_users')->fetchColumn();
        $this->opsKey = (new ApiKeys($this->pdo))->mint($opsId, 't', null, $opsId)['plaintext'];
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        putenv('P1960_PIPELINE_ALLOW_BURN');
        unset($_ENV['P1960_PIPELINE_ALLOW_BURN']);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        putenv('P1960_PIPELINE_ALLOW_BURN');
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testDryRunAndEnqueue(): void
    {
        $c = new PipelineControls($this->pdo);
        self::assertFalse(PipelineControls::burnAllowed([]));
        $dry = $c->dryRun('scrape', 0);
        self::assertSame(1, $dry['limit']);
        self::assertSame('dry_run', $dry['mode']);
        self::assertNotNull($c->lastDryRun());

        $eq = $c->enqueue('match', 999);
        self::assertSame(50, $eq['limit']);
        self::assertSame('enqueue_dry', $eq['mode']);
        self::assertNotNull($c->lastEnqueue());

        try {
            $c->dryRun('nope');
            self::fail('expected');
        } catch (\InvalidArgumentException) {
        }

        try {
            $c->enqueue('ocr', 5, true);
            self::fail('expected burn blocked');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Live burn blocked', $e->getMessage());
        }

        putenv('P1960_PIPELINE_ALLOW_BURN=1');
        self::assertTrue(PipelineControls::burnAllowed(['P1960_PIPELINE_ALLOW_BURN' => '1']));
        try {
            $c->enqueue('ocr', 5, true);
            self::fail('expected not wired');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('not wired', $e->getMessage());
        }
    }

    public function testPagePostAndApiEnqueue(): void
    {
        (new AdminAuth($this->pdo))->login('ops', 'long-enough-secret');
        $ctl = new AdminPipelineController($this->pdo);
        $bad = $ctl->pagePost(['csrf_token' => 'x', 'mode' => 'dry_run', 'action' => 'scrape']);
        self::assertStringContainsString('Invalid security token', $bad->body);

        $token = Csrf::token();
        $dry = $ctl->pagePost([
            'csrf_token' => $token,
            'mode' => 'dry_run',
            'action' => 'ingest',
            'limit' => '3',
        ]);
        self::assertStringContainsString('Dry-run recorded', $dry->body);

        $token = Csrf::token();
        $eq = $ctl->pagePost([
            'csrf_token' => $token,
            'mode' => 'enqueue',
            'action' => 'extract',
            'limit' => '2',
        ]);
        self::assertStringContainsString('Enqueue dry intent', $eq->body);

        $token = Csrf::token();
        $burn = $ctl->pagePost([
            'csrf_token' => $token,
            'mode' => 'enqueue',
            'action' => 'ocr',
            'confirm_burn' => '1',
        ]);
        self::assertStringContainsString('Live burn blocked', $burn->body);

        $token = Csrf::token();
        $unknown = $ctl->pagePost([
            'csrf_token' => $token,
            'mode' => 'nope',
            'action' => 'scrape',
        ]);
        self::assertStringContainsString('Unknown mode', $unknown->body);

        $app = new App(null, null, $this->pdo);
        $api = $app->handle(new Request(
            'POST',
            '/api/admin/pipeline',
            [],
            [],
            ['HTTP_X_API_KEY' => $this->opsKey],
            ['action' => 'scrape', 'limit' => '4']
        ));
        self::assertSame(202, $api->status);

        $badApi = $app->handle(new Request(
            'POST',
            '/api/admin/pipeline',
            [],
            [],
            ['HTTP_X_API_KEY' => $this->opsKey],
            ['action' => 'not-a-real-action']
        ));
        self::assertSame(400, $badApi->status);

        (new \Project1960\SiteSettings($this->pdo))->set(
            PipelineControls::KEY_LAST_DRY_RUN,
            '{not-json'
        );
        self::assertNull((new PipelineControls($this->pdo))->lastDryRun());
    }
}
