<?php
declare(strict_types=1);

namespace Project1960\Scraper;

use PDO;

/**
 * Port of legacy store_case: filter + INSERT OR IGNORE into cases.
 */
final class CaseStore
{
    public const RESULT_STORED = 'stored';
    public const RESULT_DUPLICATE = 'duplicate';
    public const RESULT_NO_MATCH = 'no_match';
    public const RESULT_MISSING_ID = 'missing_id';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $item DOJ API press release row
     * @return self::RESULT_*
     */
    public function store(array $item): string
    {
        $caseId = isset($item['uuid']) ? (string) $item['uuid'] : '';
        if ($caseId === '') {
            return self::RESULT_MISSING_ID;
        }

        $title = (string) ($item['title'] ?? '');
        $date = (string) ($item['date'] ?? '');
        $body = (string) ($item['body'] ?? '');
        $url = (string) ($item['url'] ?? '');
        $teaser = (string) ($item['teaser'] ?? '');
        $number = (string) ($item['number'] ?? '');
        $component = $this->joinListField($item['component'] ?? null, 'name');
        $topic = $this->joinTopicField($item['topic'] ?? null);
        $changed = (string) ($item['changed'] ?? '');
        $created = (string) ($item['created'] ?? '');

        $mentions1960 = KeywordFilters::mentions1960($body) || KeywordFilters::mentions1960($title);
        $mentionsCrypto = KeywordFilters::mentionsCrypto($body) || KeywordFilters::mentionsCrypto($title);

        if (!$mentions1960 && !$mentionsCrypto) {
            return self::RESULT_NO_MATCH;
        }

        $stmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO cases
             (id, title, date, body, url, teaser, number, component, topic,
              changed, created, mentions_1960, mentions_crypto)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bindValue(1, $caseId);
        $stmt->bindValue(2, $title);
        $stmt->bindValue(3, $date);
        $stmt->bindValue(4, $body);
        $stmt->bindValue(5, $url);
        $stmt->bindValue(6, $teaser);
        $stmt->bindValue(7, $number);
        $stmt->bindValue(8, $component);
        $stmt->bindValue(9, $topic);
        $stmt->bindValue(10, $changed);
        $stmt->bindValue(11, $created);
        $stmt->bindValue(12, $mentions1960 ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(13, $mentionsCrypto ? 1 : 0, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0 ? self::RESULT_STORED : self::RESULT_DUPLICATE;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array{stored: int, duplicate: int, no_match: int, missing_id: int}
     */
    public function storeAll(array $items): array
    {
        $counts = [
            self::RESULT_STORED => 0,
            self::RESULT_DUPLICATE => 0,
            self::RESULT_NO_MATCH => 0,
            self::RESULT_MISSING_ID => 0,
        ];
        foreach ($items as $item) {
            if (!is_array($item)) {
                $counts[self::RESULT_MISSING_ID]++;
                continue;
            }
            $result = $this->store($item);
            $counts[$result]++;
        }

        return [
            'stored' => $counts[self::RESULT_STORED],
            'duplicate' => $counts[self::RESULT_DUPLICATE],
            'no_match' => $counts[self::RESULT_NO_MATCH],
            'missing_id' => $counts[self::RESULT_MISSING_ID],
        ];
    }

    /**
     * Flatten DOJ component list of {name: …} dicts (or scalar) to CSV string.
     */
    public function joinListField(mixed $value, string $nameKey): string
    {
        if ($value === null) {
            return '';
        }
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $entry) {
                if (is_array($entry)) {
                    $parts[] = (string) ($entry[$nameKey] ?? '');
                } elseif (is_scalar($entry)) {
                    $parts[] = (string) $entry;
                }
            }

            return implode(', ', array_filter($parts, static fn (string $p): bool => $p !== ''));
        }

        return (string) $value;
    }

    /**
     * Topic may be a list of strings (legacy skips dict entries).
     */
    public function joinTopicField(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $entry) {
                if (is_array($entry)) {
                    continue;
                }
                if (is_scalar($entry)) {
                    $parts[] = (string) $entry;
                }
            }

            return implode(', ', $parts);
        }

        return (string) $value;
    }
}
