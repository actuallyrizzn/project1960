<?php
declare(strict_types=1);

namespace Project1960\CourtListener;

use CourtListener\CourtListenerClient;
use InvalidArgumentException;

/**
 * Live ingest via courtlistener-sdk nested docket routes.
 *
 * Do NOT use docket-entries/?docket=… — v4 rejects unknown filter `docket` (400).
 * Correct paths: dockets/{id}/docket-entries/ and dockets/{id}/recap/.
 */
final class SdkIngestGateway implements IngestGateway
{
    public function __construct(private CourtListenerClient $client)
    {
    }

    public function listDocketEntries(array $params): array
    {
        $id = $this->requireDocketId($params);
        $query = $params;
        unset($query['docket'], $query['docket_id']);
        /** @var mixed $out */
        $out = $this->client->dockets->getDocketEntries($id, $query);

        return $this->asResults($out);
    }

    public function listRecapDocuments(array $params): array
    {
        $id = $this->requireDocketId($params);
        $query = $params;
        unset($query['docket'], $query['docket_id']);
        /** @var mixed $out */
        $out = $this->client->dockets->getRecapDocuments($id, $query);

        return $this->asResults($out);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function requireDocketId(array $params): int
    {
        $id = (int) ($params['docket'] ?? $params['docket_id'] ?? 0);
        if ($id <= 0) {
            throw new InvalidArgumentException('docket / docket_id required for ingest');
        }

        return $id;
    }

    /**
     * @return array{results: list<array<string, mixed>>}
     */
    private function asResults(mixed $out): array
    {
        if (!is_array($out)) {
            return ['results' => []];
        }
        if (isset($out['results']) && is_array($out['results'])) {
            /** @var list<array<string, mixed>> $rows */
            $rows = array_values(array_filter($out['results'], 'is_array'));

            return ['results' => $rows];
        }
        if ($out !== [] && array_is_list($out)) {
            /** @var list<array<string, mixed>> $rows */
            $rows = array_values(array_filter($out, 'is_array'));

            return ['results' => $rows];
        }

        return ['results' => []];
    }
}
