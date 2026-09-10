<?php
declare(strict_types=1);

namespace Project1960\CourtListener;

/**
 * Prompt + normalize for charges / outcomes / money (CL-X3).
 */
final class FilingFactsPrompt
{
    public static function build(string $filingChunk, ?string $caseContext = null): string
    {
        $ctx = $caseContext ? "Case context: {$caseContext}\n\n" : '';

        return <<<PROMPT
You are a legal data extraction expert. From the filing excerpt, extract charges and outcomes/money.
Return ONLY JSON:
{
  "charges": [{"charge_description":"","statute":null,"defendant":null}],
  "outcomes": [{"outcome_type":"plea|verdict|sentence|forfeiture|fine|other","description":"","amount":null,"currency":null,"defendant":null}]
}
If none, use empty arrays. No markdown.

{$ctx}Filing text:
---
{$filingChunk}
---
PROMPT;
    }

    /**
     * @param array<mixed> $decoded
     * @return array{charges: list<array<string, mixed>>, outcomes: list<array<string, mixed>>}
     */
    public static function normalize(array $decoded): array
    {
        $charges = [];
        $rawCharges = $decoded['charges'] ?? [];
        if (!is_array($rawCharges)) {
            $rawCharges = [];
        }
        foreach ($rawCharges as $row) {
            if (!is_array($row)) {
                continue;
            }
            $desc = trim((string) ($row['charge_description'] ?? $row['description'] ?? ''));
            if ($desc === '') {
                continue;
            }
            $charges[] = [
                'charge_description' => $desc,
                'statute' => self::nullStr($row['statute'] ?? null),
                'defendant' => self::nullStr($row['defendant'] ?? null),
            ];
        }

        $outcomes = [];
        $rawOut = $decoded['outcomes'] ?? $decoded['financial'] ?? [];
        if (!is_array($rawOut)) {
            $rawOut = [];
        }
        foreach ($rawOut as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = strtolower(trim((string) ($row['outcome_type'] ?? $row['type'] ?? 'other')));
            $allowed = ['plea', 'verdict', 'sentence', 'forfeiture', 'fine', 'other'];
            if (!in_array($type, $allowed, true)) {
                $type = 'other';
            }
            $desc = trim((string) ($row['description'] ?? ''));
            $amount = self::nullStr($row['amount'] ?? null);
            if ($desc === '' && $amount === null) {
                continue;
            }
            $outcomes[] = [
                'outcome_type' => $type,
                'description' => $desc !== '' ? $desc : null,
                'amount' => $amount,
                'currency' => self::nullStr($row['currency'] ?? null),
                'defendant' => self::nullStr($row['defendant'] ?? null),
            ];
        }

        return ['charges' => $charges, 'outcomes' => $outcomes];
    }

    private static function nullStr(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }

        return is_scalar($v) ? (string) $v : null;
    }
}
