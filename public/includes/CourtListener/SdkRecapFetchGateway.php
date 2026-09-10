<?php
declare(strict_types=1);

namespace Project1960\CourtListener;

use CourtListener\CourtListenerClient;

final class SdkRecapFetchGateway implements RecapFetchGateway
{
    public function __construct(private CourtListenerClient $client)
    {
    }

    public function requestFetch(array $payload): array
    {
        /** @var array<string, mixed> $out */
        $out = $this->client->recapFetch->create($payload);

        return $out;
    }
}
