<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit\CourtListener;

use PHPUnit\Framework\TestCase;
use Project1960\CourtListener\DocketMatcher;
use Project1960\CourtListener\MatchCliOptions;
use Project1960\CourtListener\SdkSearchGateway;
use Project1960\CourtListener\SearchGateway;
use Project1960\CourtListenerDocketStore;
use Project1960\CourtListenerMatchReviewStore;
use Project1960\Schema;
use PDO;

final class DocketMatcherTest extends TestCase
{
    private string $path;
    private PDO $pdo;
    private DocketMatcher $matcher;
    /** @var list<array<string, mixed>> */
    private array $fakeResults = [];

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/p1960_match_' . bin2hex(random_bytes(4)) . '.db';
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

        $gateway = new class ($this) implements SearchGateway {
            public function __construct(private DocketMatcherTest $t)
            {
            }

            public function search(array $params): array
            {
                return ['results' => $this->t->getFakeResults()];
            }
        };

        $this->matcher = new DocketMatcher(
            $gateway,
            new CourtListenerDocketStore($this->pdo),
            new CourtListenerMatchReviewStore($this->pdo),
            $this->pdo,
        );
    }

    /** @return list<array<string, mixed>> */
    public function getFakeResults(): array
    {
        return $this->fakeResults;
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testExactDocketNumberMatchPersistsLink(): void
    {
        $this->fakeResults = [
            [
                'docket_id' => 9001,
                'docketNumber' => '1:24-cr-0001',
                'caseName' => 'United States v. Jane Fixture',
                'court_id' => 'nysd',
            ],
            [
                'docket_id' => 9002,
                'docketNumber' => '2:99-cv-9999',
                'caseName' => 'Other Matter',
                'court_id' => 'cacd',
            ],
        ];
        $seed = [
            'case_id' => 'c1',
            'title' => 'United States v. Jane Fixture',
            'case_number' => '1:24-cr-0001',
            'district_office' => 'Southern District of New York',
            'party_names' => ['Jane Fixture'],
        ];
        $result = $this->matcher->matchOne($seed, dryRun: false);
        self::assertSame('matched', $result['outcome']);
        self::assertSame(9001, $result['cl_docket_id']);
        self::assertGreaterThanOrEqual(DocketMatcher::ACCEPT_MIN, $result['confidence']);

        $links = (new CourtListenerDocketStore($this->pdo))->linksForCase('c1');
        self::assertCount(1, $links);
        self::assertSame(9001, (int) $links[0]['cl_docket_id']);
        self::assertNull((new CourtListenerMatchReviewStore($this->pdo))->get('c1'));
    }

    public function testAmbiguousFlagsReviewQueue(): void
    {
        $this->fakeResults = [
            [
                'docket_id' => 1,
                'docketNumber' => '1:24-cr-0001',
                'caseName' => 'United States v. Jane Fixture',
                'court_id' => 'nysd',
            ],
            [
                'docket_id' => 2,
                'docketNumber' => '1:24-cr-0001',
                'caseName' => 'United States v. Jane Fixture',
                'court_id' => 'nysd',
            ],
        ];
        $seed = [
            'case_id' => 'c1',
            'title' => 'United States v. Jane Fixture',
            'case_number' => '1:24-cr-0001',
            'district_office' => 'Southern District of New York',
            'party_names' => ['Jane Fixture'],
        ];
        $result = $this->matcher->matchOne($seed, dryRun: false);
        self::assertSame('ambiguous', $result['outcome']);
        $review = (new CourtListenerMatchReviewStore($this->pdo))->get('c1');
        self::assertNotNull($review);
        self::assertSame('ambiguous', $review['reason']);
        self::assertSame('pending', $review['status']);
    }

    public function testDryRunDoesNotWrite(): void
    {
        $this->fakeResults = [
            [
                'docket_id' => 55,
                'docketNumber' => '1:24-cr-0001',
                'caseName' => 'United States v. Jane Fixture',
                'court_id' => 'nysd',
            ],
        ];
        $seed = [
            'case_id' => 'c1',
            'title' => 'United States v. Jane Fixture',
            'case_number' => '1:24-cr-0001',
            'district_office' => 'Southern District of New York',
            'party_names' => ['Jane Fixture'],
        ];
        $result = $this->matcher->matchOne($seed, dryRun: true);
        self::assertSame('dry_run_matched', $result['outcome']);
        self::assertSame([], (new CourtListenerDocketStore($this->pdo))->linksForCase('c1'));
    }

    public function testNoMatchEmptyResults(): void
    {
        $this->fakeResults = [];
        $result = $this->matcher->matchOne([
            'case_id' => 'c1',
            'title' => 'Nobody',
            'case_number' => '9:99-cr-9999',
            'party_names' => [],
        ]);
        self::assertSame('no_match', $result['outcome']);
        $review = (new CourtListenerMatchReviewStore($this->pdo))->get('c1');
        self::assertNotNull($review);
        self::assertSame('no_match', $review['reason']);
    }

    public function testLoadSeedsAndBuildQuery(): void
    {
        $seeds = $this->matcher->loadSeeds(5, verifiedOnly: true);
        self::assertCount(1, $seeds);
        self::assertSame('c1', $seeds[0]['case_id']);
        $q = $this->matcher->buildQuery($seeds[0]);
        self::assertStringContainsString('1:24-cr-0001', $q);
        self::assertStringContainsString('Jane Fixture', $q);
    }

    public function testMatchCliOptions(): void
    {
        $o = MatchCliOptions::fromArgv(['match.php', '--limit=3', '--dry-run', '--verbose']);
        self::assertSame(3, $o->limit);
        self::assertTrue($o->dryRun);
        self::assertTrue($o->verbose);
        self::assertSame(2, $o->waitSeconds);

        $o2 = MatchCliOptions::fromArgv(['match.php', '--limit', '7', '--wait=5', '--all', '--help']);
        self::assertSame(7, $o2->limit);
        self::assertSame(5, $o2->waitSeconds);
        self::assertTrue($o2->allCases);
        self::assertTrue($o2->help);

        $o3 = MatchCliOptions::fromGetopt(['limit' => 'nope']);
        self::assertSame(25, $o3->limit);

        $o4 = MatchCliOptions::fromArgv(['match.php', '-h', '-v', 'noise', '--limit=']);
        self::assertTrue($o4->help);
        self::assertTrue($o4->verbose);
        self::assertSame(25, $o4->limit);

        $o5 = MatchCliOptions::fromGetopt(['limit' => '0']);
        self::assertSame(1, $o5->limit);

        $o6 = MatchCliOptions::fromGetopt(['limit' => '']);
        self::assertSame(25, $o6->limit);

        self::assertStringContainsString('--dry-run', MatchCliOptions::helpText());
    }

    public function testSdkSearchGatewayCallsListSearch(): void
    {
        $search = $this->getMockBuilder(\CourtListener\Api\Search::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['listSearch'])
            ->getMock();
        $search->expects(self::once())
            ->method('listSearch')
            ->with(['q' => '1:24', 'type' => 'd'])
            ->willReturn(['results' => [['docket_id' => 42]]]);

        $client = $this->getMockBuilder(\CourtListener\CourtListenerClient::class)
            ->disableOriginalConstructor()
            ->getMock();
        $client->search = $search;

        $gw = new \Project1960\CourtListener\SdkSearchGateway($client);
        $out = $gw->search(['q' => '1:24', 'type' => 'd']);
        self::assertSame(42, $out['results'][0]['docket_id']);
    }
}
