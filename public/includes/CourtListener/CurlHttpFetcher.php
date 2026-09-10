<?php
declare(strict_types=1);

namespace Project1960\CourtListener;

/** curl-based GET for free document URLs. */
final class CurlHttpFetcher implements HttpFetcher
{
    public function get(string $url): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'curl_init failed'];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_USERAGENT => 'Project1960/1.0 (research; +https://project1960.rizzn.net)',
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            return ['ok' => false, 'status' => $status, 'body' => '', 'error' => $err ?: 'curl_exec failed'];
        }

        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => (string) $body];
    }
}
