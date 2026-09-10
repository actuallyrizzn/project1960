<?php
declare(strict_types=1);

namespace Project1960\CourtListener;

/**
 * Aggregate match outcomes for CL-M2 batch reporting.
 */
final class MatchBatchStats
{
    private int $matched = 0;
    private int $ambiguous = 0;
    private int $noMatch = 0;
    private int $errors = 0;
    private int $processed = 0;

    public function record(string $outcome): void
    {
        $this->processed++;
        if (str_contains($outcome, 'matched')) {
            $this->matched++;
        } elseif (str_contains($outcome, 'ambiguous')) {
            $this->ambiguous++;
        } elseif (str_contains($outcome, 'error')) {
            $this->errors++;
        } else {
            $this->noMatch++;
        }
    }

    public function recordError(): void
    {
        $this->processed++;
        $this->errors++;
    }

    /** @return array{processed: int, matched: int, ambiguous: int, no_match: int, errors: int, match_rate: float} */
    public function toArray(): array
    {
        $denom = max(1, $this->processed - $this->errors);

        return [
            'processed' => $this->processed,
            'matched' => $this->matched,
            'ambiguous' => $this->ambiguous,
            'no_match' => $this->noMatch,
            'errors' => $this->errors,
            'match_rate' => round($this->matched / $denom, 4),
        ];
    }

    public function summaryLine(): string
    {
        $a = $this->toArray();

        return sprintf(
            'Done: processed=%d matched=%d ambiguous=%d no_match=%d errors=%d match_rate=%.1f%%',
            $a['processed'],
            $a['matched'],
            $a['ambiguous'],
            $a['no_match'],
            $a['errors'],
            $a['match_rate'] * 100
        );
    }
}
