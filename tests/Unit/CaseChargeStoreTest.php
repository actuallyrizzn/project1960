<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Project1960\CaseChargeStore;
use Project1960\Schema;

final class CaseChargeStoreTest extends TestCase
{
    private string $path;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/p1960_charges_' . bin2hex(random_bytes(4)) . '.db';
        $this->pdo = new PDO('sqlite:' . $this->path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        Schema::migrate($this->pdo);
        $this->pdo->exec(
            "INSERT INTO cases (id, title, date, body, url, verified_1960)
             VALUES ('thai-pr', 'Thai Sex Trafficking', '1544745600', 'body', 'https://ex/thai', 1)"
        );
        $this->pdo->exec(
            "INSERT INTO courtlistener_dockets (cl_docket_id, court_id, docket_number, case_name)
             VALUES (7508872, 'mnd', '0:17-cr-00107', 'United States v. Morris')"
        );
        $this->pdo->exec(
            "INSERT INTO case_courtlistener_links (case_id, cl_docket_id, match_confidence, match_method)
             VALUES ('thai-pr', 7508872, 0.888, 'auto_docket_court')"
        );
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testSchemaHasFocusColumns(): void
    {
        $chargeCols = $this->pdo->query('PRAGMA table_info(charges)')->fetchAll(PDO::FETCH_ASSOC);
        $names = array_column($chargeCols, 'name');
        foreach (['is_1960', 'verified_1960', 'participant_id', 'cl_docket_id', 'source'] as $col) {
            self::assertContains($col, $names);
        }
        $linkCols = array_column(
            $this->pdo->query('PRAGMA table_info(case_courtlistener_links)')->fetchAll(PDO::FETCH_ASSOC),
            'name'
        );
        self::assertContains('relevance', $linkCols);
        self::assertContains('focus_charge_id', $linkCols);
        $tables = $this->pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='case_docket_refs'"
        )->fetchColumn();
        self::assertSame('case_docket_refs', $tables);
    }

    public function testCanHornInOnUmtChargeInsideEnterprisePr(): void
    {
        $store = new CaseChargeStore($this->pdo);

        self::assertSame(2, $store->upsertDocketRefs('thai-pr', [
            [
                'docket_number' => '17-cr-107',
                'court_hint' => 'mnd',
                'caption' => 'United States v. Michael Morris, et al.',
            ],
            [
                'docket_number' => '16-cr-257',
                'court_hint' => 'mnd',
                'caption' => 'United States v. Sumalee Intarathong, et al.',
            ],
        ]));

        $sexId = $store->upsertCharge([
            'case_id' => 'thai-pr',
            'defendant' => 'MICHAEL J. MORRIS',
            'charge_description' => 'Conspiracy to commit sex trafficking',
            'status' => 'convicted',
            'is_1960' => false,
            'source' => 'press',
        ]);
        $umtId = $store->upsertCharge([
            'case_id' => 'thai-pr',
            'defendant' => 'BHUNNA WIN',
            'charge_description' => 'Unlicensed money transmitting business',
            'statute' => null,
            'count_num' => 1,
            'status' => 'convicted',
            'is_1960' => true,
            'verified_1960' => true,
            'cl_docket_id' => 7508872,
            'source' => 'press',
        ]);

        $store->setLinkRelevance('thai-pr', 7508872, 'ambient', $umtId);

        $focus = $store->list1960FocusCharges('thai-pr');
        self::assertCount(1, $focus);
        self::assertSame('BHUNNA WIN', $focus[0]['defendant']);
        self::assertSame(1, (int) $focus[0]['is_1960']);
        self::assertSame($umtId, (int) $focus[0]['charge_id']);
        self::assertNotSame($sexId, $umtId);

        $rel = $this->pdo->query(
            "SELECT relevance, focus_charge_id FROM case_courtlistener_links
             WHERE case_id='thai-pr' AND cl_docket_id=7508872"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('ambient', $rel['relevance']);
        self::assertSame($umtId, (int) $rel['focus_charge_id']);

        $refs = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM case_docket_refs WHERE case_id='thai-pr'"
        )->fetchColumn();
        self::assertSame(2, $refs);
    }
}
