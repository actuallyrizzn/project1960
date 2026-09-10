<?php
declare(strict_types=1);

namespace Project1960\CourtListener;

/**
 * Venice prompt + chunking for people/roles from filing text (CL-X1).
 */
final class PeopleExtractPrompt
{
    public const CHUNK_SIZE = 12000;
    public const CHUNK_OVERLAP = 400;

    /**
     * @return list<string>
     */
    public static function chunkText(string $text, int $size = self::CHUNK_SIZE, int $overlap = self::CHUNK_OVERLAP): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        if (mb_strlen($text) <= $size) {
            return [$text];
        }
        $chunks = [];
        $start = 0;
        $len = mb_strlen($text);
        while ($start < $len) {
            $chunks[] = mb_substr($text, $start, $size);
            $start += max(1, $size - $overlap);
            if ($start >= $len) {
                break;
            }
        }

        return $chunks;
    }

    public static function build(string $filingChunk, ?string $caseContext = null): string
    {
        $ctx = $caseContext !== null && trim($caseContext) !== ''
            ? "Case context: {$caseContext}\n\n"
            : '';

        return <<<PROMPT
You are a legal data extraction expert. Analyze the following court filing excerpt and extract people and organizations.
Return ONLY a JSON array (no markdown, no commentary). Each object:
{
  "display_name": "string",
  "role": "defendant|attorney|judge|witness|other",
  "organization": "string or null",
  "aliases": ["optional alternate names"],
  "confidence": 0.0-1.0
}

Rules:
1. Prefer exact names as written.
2. Map defense counsel / AUSA / prosecutor to role "attorney".
3. Agents/officers without a clearer role → "other" with organization set.
4. Skip unnamed placeholders ("Defendant 1").
5. If nothing found, return [].

{$ctx}Filing text:
---
{$filingChunk}
---
PROMPT;
    }

    /**
     * Normalize model JSON into person rows.
     *
     * @param array<mixed> $decoded
     * @return list<array{display_name: string, role: string, organization: ?string, aliases: list<string>, confidence: ?float}>
     */
    public static function normalizePeople(array $decoded): array
    {
        if (isset($decoded['people']) && is_array($decoded['people'])) {
            $decoded = $decoded['people'];
        }
        if (isset($decoded['persons']) && is_array($decoded['persons'])) {
            $decoded = $decoded['persons'];
        }
        // Single object
        if (isset($decoded['display_name'])) {
            $decoded = [$decoded];
        }

        $out = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['display_name'] ?? $row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $role = strtolower(trim((string) ($row['role'] ?? 'other')));
            $map = [
                'defendant' => 'defendant',
                'attorney' => 'attorney',
                'counsel' => 'attorney',
                'prosecutor' => 'attorney',
                'defense attorney' => 'attorney',
                'judge' => 'judge',
                'witness' => 'witness',
                'agent' => 'other',
                'other' => 'other',
            ];
            $role = $map[$role] ?? 'other';
            $aliases = [];
            if (isset($row['aliases']) && is_array($row['aliases'])) {
                foreach ($row['aliases'] as $a) {
                    if (is_string($a) && trim($a) !== '') {
                        $aliases[] = trim($a);
                    }
                }
            }
            $conf = $row['confidence'] ?? null;
            $out[] = [
                'display_name' => $name,
                'role' => $role,
                'organization' => isset($row['organization']) && $row['organization'] !== ''
                    ? (string) $row['organization']
                    : null,
                'aliases' => $aliases,
                'confidence' => $conf === null || $conf === '' ? null : (float) $conf,
            ];
        }

        return $out;
    }
}
