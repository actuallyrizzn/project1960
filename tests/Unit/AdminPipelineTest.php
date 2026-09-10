<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Project1960\AdminAuth;
use Project1960\AdminPipelineController;
use Project1960\ApiKeys;
use Project1960\App;
use Project1960\PipelineStatus;
use Project1960\Request;
use Project1960\Schema;
use Project1960\Scraper\ScraperState;

final class AdminPipelineTest extends TestCase
{
    private string $path;
    private PDO $pdo;
    private string $opsKey;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/p1960_pipeline_' . bin2hex(random_bytes(4)) . '.db';
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
        $this->pdo->exec("INSERT INTO cases (id, title, mentions_1960) VALUES ('c1', 'A', 1)");
        (new ScraperState($this->pdo))->saveLastPage(7);
        $this->pdo->exec("INSERT INTO courtlistener_dockets (cl_docket_id, case_name) VALUES (1, 'D')");
        $this->pdo->exec(
            "INSERT INTO case_courtlistener_links (case_id, cl_docket_id, match_method)
             VALUES ('c1', 1, 'test')"
        );
        $this->pdo->exec(
            "INSERT INTO courtlistener_documents (cl_document_id, cl_docket_id, ocr_status)
             VALUES (1, 1, 'done'), (2, 1, 'pending')"
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

    public function testCollectCounts(): void
    {
        $s = (new PipelineStatus($this->pdo))->collect();
        self::assertSame(7, $s['scrape']['last_page']);
        self::assertSame(1, $s['scrape']['cases_total']);
        self::assertSame(1, $s['match']['dockets']);
        self::assertSame(1, $s['match']['case_links']);
        self::assertSame(2, $s['documents']['documents_total']);
        self::assertSame(1, $s['documents']['ocr_done']);
        self::assertSame(1, $s['documents']['ocr_pending']);
        self::assertSame(0, $s['extract']['persons']);
    }

    public function testPageAndApi(): void
    {
        (new AdminAuth($this->pdo))->login('ops', 'long-enough-secret');
        $page = (new AdminPipelineController($this->pdo))->pageGet();
        self::assertStringContainsString('Pipeline', $page->body);
        self::assertStringContainsString('last_page', $page->body);

        $app = new App(null, null, $this->pdo);
        $deny = $app->handle(new Request('GET', '/api/admin/pipeline'));
        self::assertSame(401, $deny->status);
        $ok = $app->handle(new Request(
            'GET',
            '/api/admin/pipeline',
            [],
            [],
            ['HTTP_X_API_KEY' => $this->opsKey]
        ));
        self::assertSame(200, $ok->status);
        self::assertStringContainsString('ocr_done', $ok->body);
    }
}
