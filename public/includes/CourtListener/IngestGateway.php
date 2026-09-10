<?php
declare(strict_types=1);

namespace Project1960\CourtListener;

/**
 * SDK port for docket-entry + RECAP document metadata (CL-I2).
 */
interface IngestGateway
{
    /**
     * @param array<string, mixed> $params
     * @return array{results?: list<array<string, mixed>>}
     */
    public function listDocketEntries(array $params): array;

    /**
     * @param array<string, mixed> $params
     * @return array{results?: list<array<string, mixed>>}
     */
    public function listRecapDocuments(array $params): array;
}
