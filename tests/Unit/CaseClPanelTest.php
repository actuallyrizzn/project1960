<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Project1960\App;
use Project1960\CaseClPanel;
use Project1960\CourtListenerDocumentStore;
use Project1960\CourtListenerDocketStore;
use Project1960\CourtListenerPersonStore;
use Project1960\FixtureDatabase;
use Project1960\Request;
use Project1960\Schema;

final class CaseClPanelTest extends TestCase
{
    public function testPanelLoadsLinkedData(): void
    {
        $fixture = new FixtureDatabase();
        $pdo = $fixture->pdo();
        Schema::migrate($pdo);
        (new CourtListenerDocketStore($pdo))->upsertDocket([
            'cl_docket_id' => 77,
            'court_id' => 'nysd',
            'docket_number' => '1:20-cr-1',
            'case_name' => 'US v Fixture',
        ]);
        (new CourtListenerDocketStore($pdo))->upsertCaseLink([
            'case_id' => 'fixture-case-1',
            'cl_docket_id' => 77,
            'match_confidence' => 0.9,
        ]);
        (new CourtListenerDocumentStore($pdo))->upsertDocument([
            'cl_document_id' => 500,
            'cl_docket_id' => 77,
            'description' => 'Indictment',
            'ocr_status' => CourtListenerDocumentStore::OCR_DONE,
        ]);
        $ps = new CourtListenerPersonStore($pdo);
        $pid = $ps->upsertPerson(['display_name' => 'Jane Fixture', 'role' => 'defendant']);
        $ps->linkCase(['person_id' => $pid, 'case_id' => 'fixture-case-1', 'role' => 'defendant']);

        $panel = (new CaseClPanel($pdo))->forCase('fixture-case-1');
        self::assertCount(1, $panel['dockets']);
        self::assertCount(1, $panel['documents']);
        self::assertCount(1, $panel['people']);

        $app = new App(null, null, $pdo);
        $html = $app->handle(new Request('GET', '/case/fixture-case-1'));
        self::assertSame(200, $html->status);
        self::assertStringContainsString('CourtListener / RECAP', $html->body);
        self::assertStringContainsString('Indictment', $html->body);

        $patterns = $app->handle(new Request('GET', '/patterns', ['q' => 'Jane Fixture']));
        self::assertSame(200, $patterns->status);
        self::assertStringContainsString('Person / network patterns', $patterns->body);
        self::assertStringContainsString('Jane Fixture', $patterns->body);

        $fixture->destroy();
    }

    public function testEmptyCasePanel(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        Schema::migrate($pdo);
        $panel = (new CaseClPanel($pdo))->forCase('missing');
        self::assertSame([], $panel['dockets']);
        self::assertSame([], $panel['documents']);
        self::assertSame([], $panel['people']);
    }
}
