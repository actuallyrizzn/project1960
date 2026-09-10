<?php
declare(strict_types=1);

namespace Project1960\CourtListener;

use CourtListener\CourtListenerClient;

/** Live gateway: always uses SDK Search API. */
final class SdkSearchGateway implements SearchGateway
{
    public function __construct(private CourtListenerClient $client)
    {
    }

    public function search(array $params): array
    {
        /** @var array{results?: list<array<string, mixed>>, count?: int} $out */
        $out = $this->client->search->listSearch($params);

        return $out;
    }
}
