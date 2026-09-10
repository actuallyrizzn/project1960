<?php
declare(strict_types=1);

namespace Project1960\Scraper;

use RuntimeException;

/**
 * curl-backed transport for production CLI scrapes.
 */
final class CurlTransport implements HttpTransport
{
    public function get(string $url, array $query, int $timeoutSeconds): array
    {
        $full = $url;
        if ($query !== []) {
            $full .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        $ch = curl_init($full);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),
            CURLOPT_USERAGENT => 'Project1960Scraper/1.0 (+https://project1960.rizzn.net)',
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $errno !== 0) {
            throw new RuntimeException('HTTP transport error: ' . ($error !== '' ? $error : 'errno ' . $errno));
        }

        return ['status' => $status, 'body' => (string) $body];
    }
}
