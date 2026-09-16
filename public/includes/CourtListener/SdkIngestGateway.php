<?php
declare(strict_types=1);

namespace Project1960\CourtListener;

use CourtListener\CourtListenerClient;
use InvalidArgumentException;

/**
 * Live ingest via courtlistener-sdk list endpoints.
 *
 * CourtListener v4 (see DocketEntryFilter / RECAPDocumentFilter):
 * - docket-entries/?docket={id}          — RelatedFilter on DocketEntry
 * - recap-documents/?docket_entry__docket={id} — no bare `docket` filter (400 unknown_params)
 *
 * Nested SDK helpers dockets/{id}/docket-entries|recap return HTML 404 on prod — do not use.
 */
final class SdkIngestGateway implements IngestGateway
{
    public function __construct(private CourtListenerClient $client)
    {
    }

    public function listDocketEntries(array $params): array
    {
        $id = $this->requireDocketId($params);
        $query = $this->queryWithoutDocketKeys($params);
        $query['docket'] = $id;
        /** @var mixed $out */
        $out = $this->client->docketEntries->listDocketEntries($query);

        return $this->asResults($out);
    }

    public function listRecapDocuments(array $params): array
    {
        $id = $this->requireDocketId($params);
        $query = $this->queryWithoutDocketKeys($params);
        // RECAPDocumentFilter has docket_entry RelatedFilter → nest to Docket.
        $query['docket_entry__docket'] = $id;
        /** @var mixed $out */
        $out = $this->client->recapDocuments->listRecapDocuments($query);

        return $this->asResults($out);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function requireDocketId(array $params): int
    {
        $id = (int) ($params['docket'] ?? $params['docket_id'] ?? $params['docket_entry__docket'] ?? 0);
        if ($id <= 0) {
            throw new InvalidArgumentException('docket / docket_id required for ingest');
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function queryWithoutDocketKeys(array $params): array
    {
        unset($params['docket'], $params['docket_id'], $params['docket_entry__docket']);

        return $params;
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
