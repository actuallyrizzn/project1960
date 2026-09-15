<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Project1960\ActivityLog;
use Project1960\CourtListener\DocketMatcher;
use Project1960\CourtListener\SearchGateway;
use Project1960\CourtListenerDocketStore;
use Project1960\CourtListenerMatchReviewStore;
use Project1960\Schema;
use PDO;

final class ActivityLogTest extends TestCase
{
    private string $path;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/p1960_alog_' . bin2hex(random_bytes(4)) . '.db';
        $this->pdo = new PDO('sqlite:' . $this->path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        Schema::migrate($this->pdo);
        $this->pdo->exec(
            "INSERT INTO cases (id, title, date, verified_1960, number)
             VALUES ('c1', 'United States v. Jane Fixture', '2024-01-15', 1, '1:24-cr-0001')"
        );
        $this->pdo->exec(
            "INSERT INTO case_metadata (case_id, district_office, case_number)
             VALUES ('c1', 'Southern District of New York', '1:24-cr-0001')"
        );
        $this->pdo->exec(
            "INSERT INTO participants (case_id, name, role) VALUES ('c1', 'Jane Fixture', 'defendant')"
        );
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testRecordAndRecent(): void
    {
        $log = new ActivityLog($this->pdo);
        $log->record(ActivityLog::STAGE_CL_MATCH, ActivityLog::STATUS_SUCCESS, 'linked', 'c1');
        $rows = $log->recent(10);
        self::assertCount(1, $rows);
        self::assertSame('cl_match', $rows[0]['table_name']);
        self::assertSame('c1', $rows[0]['case_id']);
        self::assertSame('success', $rows[0]['status']);
    }

    public function testMatchOneWritesActivity(): void
    {
        $fake = [[
            'docket_id' => 9001,
            'docketNumber' => '1:24-cr-0001',
            'caseName' => 'United States v. Jane Fixture',
            'court_id' => 'nysd',
        ]];
        $gw = new class ($fake) implements SearchGateway {
            /** @param list<array<string, mixed>> $rows */
            public function __construct(private array $rows)
            {
            }

            public function search(array $params): array
            {
                return ['results' => $this->rows];
            }
        };
        $matcher = new DocketMatcher(
            $gw,
            new CourtListenerDocketStore($this->pdo),
            new CourtListenerMatchReviewStore($this->pdo),
            $this->pdo,
        );
        $matcher->matchOne([
            'case_id' => 'c1',
            'title' => 'United States v. Jane Fixture',
            'case_number' => '1:24-cr-0001',
            'district_office' => 'Southern District of New York',
            'party_names' => ['Jane Fixture'],
        ], dryRun: false);

        $rows = (new ActivityLog($this->pdo))->recent(5);
        self::assertNotEmpty($rows);
        self::assertSame(ActivityLog::STAGE_CL_MATCH, $rows[0]['table_name']);
        self::assertSame(ActivityLog::STATUS_SUCCESS, $rows[0]['status']);
        self::assertStringContainsString('CL #9001', $rows[0]['notes']);
    }

    public function testNormalizeStatus(): void
    {
        self::assertSame('success', ActivityLog::normalizeStatus('matched'));
        self::assertSame('success', ActivityLog::normalizeStatus('done'));
        self::assertSame('weak_accept', ActivityLog::normalizeStatus('weak_accept'));
        self::assertSame('skipped', ActivityLog::normalizeStatus('ambiguous'));
        self::assertSame('error', ActivityLog::normalizeStatus('failed'));
    }
}
