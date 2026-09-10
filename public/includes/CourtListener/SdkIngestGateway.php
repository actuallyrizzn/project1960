<?php
declare(strict_types=1);

namespace Project1960\CourtListener;

use CourtListener\CourtListenerClient;

/** Live ingest via courtlistener-sdk — no hand-rolled HTTP. */
final class SdkIngestGateway implements IngestGateway
{
    public function __construct(private CourtListenerClient $client)
    {
    }

    public function listDocketEntries(array $params): array
    {
        /** @var array{results?: list<array<string, mixed>>} $out */
        $out = $this->client->docketEntries->listDocketEntries($params);

        return $out;
    }

    public function listRecapDocuments(array $params): array
    {
        /** @var array{results?: list<array<string, mixed>>} $out */
        $out = $this->client->recapDocuments->listRecapDocuments($params);

        return $out;
    }
}
