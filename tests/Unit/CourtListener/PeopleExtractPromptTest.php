<?php
declare(strict_types=1);

namespace Project1960\Tests\Unit\CourtListener;

use PHPUnit\Framework\TestCase;
use Project1960\CourtListener\DirtyJsonParser;
use Project1960\CourtListener\PeopleExtractPrompt;

final class PeopleExtractPromptTest extends TestCase
{
    public function testBuildIncludesFilingAndRoles(): void
    {
        $p = PeopleExtractPrompt::build('Alice was indicted.', 'case-1');
        self::assertStringContainsString('Alice was indicted', $p);
        self::assertStringContainsString('case-1', $p);
        self::assertStringContainsString('defendant|attorney|judge', $p);
    }

    public function testChunkTextOverlaps(): void
    {
        $text = str_repeat('abcdefghij', 200); // 2000 chars
        $chunks = PeopleExtractPrompt::chunkText($text, 500, 50);
        self::assertGreaterThan(1, count($chunks));
        self::assertSame([], PeopleExtractPrompt::chunkText(''));
        self::assertCount(1, PeopleExtractPrompt::chunkText('short'));
    }

    public function testNormalizePeopleMapsRolesAndWrappers(): void
    {
        $rows = PeopleExtractPrompt::normalizePeople([
            'people' => [
                ['display_name' => 'Jane Doe', 'role' => 'Defense Attorney', 'aliases' => ['J. Doe'], 'confidence' => 0.9],
                ['name' => 'Hon. Smith', 'role' => 'judge'],
                ['display_name' => '', 'role' => 'other'],
            ],
        ]);
        self::assertCount(2, $rows);
        self::assertSame('attorney', $rows[0]['role']);
        self::assertSame(['J. Doe'], $rows[0]['aliases']);
        self::assertSame('judge', $rows[1]['role']);
    }

    public function testDirtyJsonParserHandlesFencesAndTrailingCommas(): void
    {
        $raw = "<think>thinking</think>\n```json\n[{\"display_name\": \"Bob\", \"role\": \"defendant\",},]\n```";
        $parsed = DirtyJsonParser::parse($raw);
        self::assertIsArray($parsed);
        $people = PeopleExtractPrompt::normalizePeople($parsed);
        self::assertSame('Bob', $people[0]['display_name']);
        self::assertNull(DirtyJsonParser::parse(''));
        self::assertNull(DirtyJsonParser::parse('not json at all'));
    }
}
