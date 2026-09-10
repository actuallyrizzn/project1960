<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit\CourtListener;

use PHPUnit\Framework\TestCase;
use Project1960\CourtListener\PatternQueries;
use Project1960\CourtListenerDocketStore;
use Project1960\CourtListenerPersonStore;
use Project1960\Schema;
use PDO;

final class PatternQueriesTest extends TestCase
{
    private string $dbPath;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/p1960_pat_' . bin2hex(random_bytes(4)) . '.db';
        $this->pdo = new PDO('sqlite:' . $this->dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        Schema::migrate($this->pdo);
        $store = new CourtListenerPersonStore($this->pdo);
        $p1 = $store->upsertPerson(['display_name' => 'Shared Counsel', 'role' => 'attorney', 'organization' => 'Firm']);
        $p2 = $store->upsertPerson(['display_name' => 'One Case Def', 'role' => 'defendant']);
        $store->linkCase(['person_id' => $p1, 'case_id' => 'c1', 'role' => 'attorney']);
        $store->linkCase(['person_id' => $p1, 'case_id' => 'c2', 'role' => 'attorney']);
        $store->linkCase(['person_id' => $p2, 'case_id' => 'c1', 'role' => 'defendant']);
        $store->addAlias($p1, 'S. Counsel');
        $dockets = new CourtListenerDocketStore($this->pdo);
        $dockets->upsertDocket(['cl_docket_id' => 1, 'court_id' => 'nysd', 'case_name' => 'A']);
        $dockets->upsertDocket(['cl_docket_id' => 2, 'court_id' => 'nysd', 'case_name' => 'B']);
        $dockets->upsertCaseLink(['case_id' => 'c1', 'cl_docket_id' => 1]);
        $dockets->upsertCaseLink(['case_id' => 'c2', 'cl_docket_id' => 2]);
        try {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS case_agencies (
                    id INTEGER PRIMARY KEY, case_id TEXT, agency_name TEXT
                )'
            );
            $this->pdo->exec("INSERT INTO case_agencies (case_id, agency_name) VALUES ('c1','FBI'),('c2','FBI'),('c1','HSI')");
        } catch (\Throwable) {
        }
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testMultiCaseAndSearch(): void
    {
        $q = new PatternQueries($this->pdo);
        $multi = $q->multiCasePersons(2);
        self::assertCount(1, $multi);
        self::assertSame('Shared Counsel', $multi[0]['display_name']);
        self::assertSame(2, $multi[0]['case_count']);
        $hits = $q->casesForPersonName('S. Counsel');
        self::assertCount(2, $hits);
        self::assertSame([], $q->casesForPersonName('   '));
    }

    public function testSharedAttorneysDistrictsAgencies(): void
    {
        $q = new PatternQueries($this->pdo);
        $atts = $q->sharedAttorneys(2);
        self::assertCount(1, $atts);
        $dist = $q->sharedDistricts();
        self::assertNotEmpty($dist);
        self::assertSame('nysd', $dist[0]['court_id']);
        $ags = $q->sharedAgencies(2);
        self::assertSame('FBI', $ags[0]['agency_name']);
    }
}
