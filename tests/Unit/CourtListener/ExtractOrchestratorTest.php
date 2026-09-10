<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit\CourtListener;

use PHPUnit\Framework\TestCase;
use Project1960\CourtListener\ExtractOrchestrator;
use Project1960\CourtListener\LlmClient;
use Project1960\CourtListener\PeopleExtractPrompt;
use Project1960\CourtListenerDocumentStore;
use Project1960\CourtListenerDocketStore;
use Project1960\CourtListenerPersonStore;
use Project1960\Schema;
use PDO;

final class ExtractOrchestratorTest extends TestCase
{
    private string $dbPath;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/p1960_x_' . bin2hex(random_bytes(4)) . '.db';
        $this->pdo = new PDO('sqlite:' . $this->dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        Schema::migrate($this->pdo);
        $this->pdo->exec("INSERT INTO cases (id, title, date, url, body) VALUES ('c1', 'T', '2024-01-01', 'http://x', 'body')");
        (new CourtListenerDocketStore($this->pdo))->upsertDocket(['cl_docket_id' => 9, 'case_name' => 'US v X']);
        (new CourtListenerDocketStore($this->pdo))->upsertCaseLink(['case_id' => 'c1', 'cl_docket_id' => 9]);
        $docs = new CourtListenerDocumentStore($this->pdo);
        $docs->upsertDocument([
            'cl_document_id' => 50,
            'cl_docket_id' => 9,
            'ocr_status' => CourtListenerDocumentStore::OCR_DONE,
        ]);
        $docs->upsertFullText(50, 'Defendant Alice Example appeared with counsel Bob Lawyer.');
        try {
            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS participants (
                    id INTEGER PRIMARY KEY, case_id TEXT, name TEXT, role TEXT
                )"
            );
            $this->pdo->exec("INSERT INTO participants (case_id, name, role) VALUES ('c1', 'Alice Example', 'defendant')");
        } catch (\Throwable) {
        }
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    private function llm(string $content, bool $ok = true): LlmClient
    {
        return new class ($content, $ok) implements LlmClient {
            public function __construct(private string $content, private bool $ok)
            {
            }

            public function complete(string $prompt): array
            {
                if (!$this->ok) {
                    return ['ok' => false, 'content' => '', 'error' => 'boom'];
                }

                return ['ok' => true, 'content' => $this->content];
            }
        };
    }

    public function testExtractStoresPeopleAndEdges(): void
    {
        $json = json_encode([
            ['display_name' => 'Alice Example', 'role' => 'defendant', 'confidence' => 0.95, 'aliases' => ['A. Example']],
            ['display_name' => 'Bob Lawyer', 'role' => 'attorney', 'organization' => 'Law LLC'],
        ], JSON_THROW_ON_ERROR);
        $orch = new ExtractOrchestrator($this->llm($json), new CourtListenerPersonStore($this->pdo), $this->pdo);
        $r = $orch->extractDocument(50, 'c1');
        self::assertSame('done', $r['status']);
        self::assertSame(2, $r['people']);
        $store = new CourtListenerPersonStore($this->pdo);
        $cases = $store->caseIdsForNormalizedName('Alice Example');
        self::assertContains('c1', $cases);
    }

    public function testDryRunAndNoText(): void
    {
        $orch = new ExtractOrchestrator($this->llm('[]'), new CourtListenerPersonStore($this->pdo), $this->pdo);
        $dry = $orch->extractDocument(50, 'c1', dryRun: true);
        self::assertSame('would_extract', $dry['status']);
        $r = $orch->extractDocument(999, 'c1');
        self::assertSame('no_text', $r['status']);
    }

    public function testLlmFailure(): void
    {
        $orch = new ExtractOrchestrator($this->llm('', false), new CourtListenerPersonStore($this->pdo), $this->pdo);
        $r = $orch->extractDocument(50, 'c1');
        self::assertSame('failed', $r['status']);
    }

    public function testPendingDocuments(): void
    {
        $orch = new ExtractOrchestrator($this->llm('[]'), new CourtListenerPersonStore($this->pdo), $this->pdo);
        $pending = $orch->pendingDocuments(5);
        self::assertNotEmpty($pending);
        self::assertSame(50, $pending[0]['cl_document_id']);
        $json = '[{"display_name":"Zed","role":"other"}]';
        $orch2 = new ExtractOrchestrator($this->llm($json), new CourtListenerPersonStore($this->pdo), $this->pdo);
        $orch2->extractDocument(50, 'c1');
        self::assertSame([], $orch2->pendingDocuments(5));
    }

    public function testSkipsUnparseableChunks(): void
    {
        $orch = new ExtractOrchestrator(
            $this->llm('sorry I cannot help'),
            new CourtListenerPersonStore($this->pdo),
            $this->pdo
        );
        $r = $orch->extractDocument(50, 'c1');
        self::assertSame('done', $r['status']);
        self::assertSame(0, $r['people']);
    }

    public function testNormalizeSingleObjectAndPersonsKey(): void
    {
        $people = PeopleExtractPrompt::normalizePeople([
            'persons' => [['display_name' => 'Only', 'role' => 'agent']],
        ]);
        self::assertSame('other', $people[0]['role']);
        $one = PeopleExtractPrompt::normalizePeople(['display_name' => 'Solo', 'role' => 'witness']);
        self::assertCount(1, $one);
    }

    public function testPendingDocumentsSqlFailureReturnsEmpty(): void
    {
        $this->pdo->exec('DROP TABLE case_courtlistener_links');
        $orch = new ExtractOrchestrator($this->llm('[]'), new CourtListenerPersonStore($this->pdo), $this->pdo);
        self::assertSame([], $orch->pendingDocuments(3));
    }

    public function testSeedMergeSurvivesMissingParticipantsTable(): void
    {
        $this->pdo->exec('DROP TABLE participants');
        $json = '[{"display_name":"No Seed","role":"defendant"}]';
        $orch = new ExtractOrchestrator($this->llm($json), new CourtListenerPersonStore($this->pdo), $this->pdo);
        $r = $orch->extractDocument(50, 'c1');
        self::assertSame('done', $r['status']);
        self::assertSame(1, $r['people']);
    }
}
