<?php
declare(strict_types=1);

namespace Project1960\CourtListener;

use CourtListener\CourtListenerClient;
use RuntimeException;

/**
 * Thin Project 1960 glue around actuallyrizzn/courtlistener-sdk (PHP).
 * No hand-rolled HTTP — always returns CourtListenerClient.
 */
final class ClientFactory
{
    /**
     * @param array<string, string|null> $env
     */
    public static function apiToken(array $env = []): string
    {
        if ($env !== []) {
            $token = $env['COURTLISTENER_API_TOKEN'] ?? $env['COURTLISTENER_TOKEN'] ?? null;
        } else {
            $token = getenv('COURTLISTENER_API_TOKEN') ?: (getenv('COURTLISTENER_TOKEN') ?: null);
        }
        if (!is_string($token) || trim($token) === '') {
            throw new RuntimeException(
                'COURTLISTENER_API_TOKEN missing. Load ~/.ssh/courtlistener-api.pass (see Doc #1310).'
            );
        }

        return trim($token);
    }

    /**
     * @param array<string, mixed> $config Extra CourtListenerClient config (base_url, etc.)
     * @param array<string, string|null> $env
     * @param (callable(array): CourtListenerClient)|null $clientFactory Inject for tests
     */
    public static function make(
        array $config = [],
        array $env = [],
        ?callable $clientFactory = null,
    ): CourtListenerClient {
        $merged = array_merge(['api_token' => self::apiToken($env)], $config);
        $factory = $clientFactory ?? static function (array $cfg): CourtListenerClient {
            return new CourtListenerClient($cfg);
        };

        return $factory($merged);
    }
}
