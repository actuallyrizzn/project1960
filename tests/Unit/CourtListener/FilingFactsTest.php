<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit\CourtListener;

use PHPUnit\Framework\TestCase;
use Project1960\CourtListener\FilingFactsPrompt;
use Project1960\CourtListener\FilingFactsStore;
use Project1960\CourtListener\LlmClient;
use Project1960\Schema;
use PDO;

final class FilingFactsTest extends TestCase
{
    private string $dbPath;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/p1960_facts_' . bin2hex(random_bytes(4)) . '.db';
        $this->pdo = new PDO('sqlite:' . $this->dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        Schema::migrate($this->pdo);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testNormalizeChargesAndOutcomes(): void
    {
        $n = FilingFactsPrompt::normalize([
            'charges' => [
                ['charge_description' => '18 USC 1960', 'statute' => '18 U.S.C. § 1960', 'defendant' => 'Jane'],
                ['description' => ''],
            ],
            'outcomes' => [
                ['outcome_type' => 'forfeiture', 'amount' => '100000', 'currency' => 'USD', 'description' => 'seized'],
                ['type' => 'weird', 'description' => 'plea'],
            ],
        ]);
        self::assertCount(1, $n['charges']);
        self::assertSame('18 USC 1960', $n['charges'][0]['charge_description']);
        self::assertCount(2, $n['outcomes']);
        self::assertSame('forfeiture', $n['outcomes'][0]['outcome_type']);
        self::assertSame('other', $n['outcomes'][1]['outcome_type']);
        self::assertStringContainsString('charges', FilingFactsPrompt::build('text', 'c1'));
    }

    public function testStoreReplaceAndExtract(): void
    {
        $store = new FilingFactsStore($this->pdo);
        $store->replaceCharges('c1', 1, [
            ['charge_description' => 'Money transmitting', 'statute' => '1960', 'defendant' => 'A'],
        ]);
        $store->replaceOutcomes('c1', 1, [
            [
                'outcome_type' => 'plea',
                'description' => 'guilty',
                'amount' => null,
                'currency' => null,
                'defendant' => 'A',
            ],
        ]);
        self::assertCount(1, $store->chargesForCase('c1'));
        self::assertCount(1, $store->outcomesForCase('c1'));

        $llm = new class implements LlmClient {
            public function complete(string $prompt): array
            {
                return [
                    'ok' => true,
                    'content' => json_encode([
                        'charges' => [['charge_description' => 'Conspiracy', 'statute' => null, 'defendant' => null]],
                        'outcomes' => [['outcome_type' => 'fine', 'amount' => '5000', 'currency' => 'USD', 'description' => 'fine']],
                    ], JSON_THROW_ON_ERROR),
                ];
            }
        };
        $r = $store->extractFromText($llm, 'c1', 2, 'Defendant pled and was fined.');
        self::assertSame('done', $r['status']);
        self::assertSame(1, $r['charges']);
        self::assertSame(1, $r['outcomes']);
        self::assertSame('would_extract', $store->extractFromText($llm, 'c1', 3, 'x', dryRun: true)['status']);
    }

    public function testExtractLlmFailure(): void
    {
        $store = new FilingFactsStore($this->pdo);
        $llm = new class implements LlmClient {
            public function complete(string $prompt): array
            {
                return ['ok' => false, 'content' => '', 'error' => 'x'];
            }
        };
        self::assertSame('failed', $store->extractFromText($llm, 'c1', 1, 'text')['status']);
    }
}
