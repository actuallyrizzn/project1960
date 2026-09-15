<?php
declare(strict_types=1);

namespace Project1960;

final class CaseListFilters
{
    public function __construct(
        public readonly string $search = '',
        public readonly string $classification = '',
        public readonly string $mentions1960 = '',
        public readonly string $mentionsCrypto = '',
        public readonly string $verified1960 = '',
        public readonly int $page = 1,
        public readonly int $perPage = 20,
    ) {
    }

    public static function fromRequest(Request $request, int $perPage = 20): self
    {
        $page = max(1, $request->queryInt('page', 1));
        // Default Classification to Yes (1960) when the param is omitted (bare /cases).
        // Explicit empty (form "All" / ?classification=) stays unfiltered.
        $classification = array_key_exists('classification', $request->query)
            ? trim((string) $request->query['classification'])
            : 'yes';

        return new self(
            search: trim($request->query('search')),
            classification: $classification,
            mentions1960: trim($request->query('mentions_1960')),
            mentionsCrypto: trim($request->query('mentions_crypto')),
            verified1960: trim($request->query('verified_1960')),
            page: $page,
            perPage: max(1, $perPage),
        );
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }
}
