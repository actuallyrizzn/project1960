<?php
declare(strict_types=1);

namespace Project1960\CourtListener;

/**
 * Venice chat completions client (CL-X1/X2).
 *
 * @param null|callable(string $url, string $payload, string $apiKey): array{ok: bool, status: int, body: string, error?: string} $transport
 */
final class VeniceClient implements LlmClient
{
    /** @var null|callable(string, string, string): array{ok: bool, status: int, body: string, error?: string} */
    private $transport;

    public function __construct(
        private string $apiKey,
        private string $model = 'llama-3.3-70b',
        private string $baseUrl = 'https://api.venice.ai/api/v1',
        ?callable $transport = null,
    ) {
        $this->transport = $transport;
    }

    public static function fromEnv(): self
    {
        $key = getenv('VENICE_API_KEY') ?: getenv('VENICE_INFERENCE_KEY') ?: '';
        if (!is_string($key) || $key === '') {
            throw new \RuntimeException('VENICE_API_KEY not set');
        }

        return new self($key);
    }

    public function complete(string $prompt): array
    {
        $payload = json_encode([
            'model' => $this->model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.1,
        ], JSON_THROW_ON_ERROR);

        $url = rtrim($this->baseUrl, '/') . '/chat/completions';
        $raw = ($this->transport ?? [$this, 'curlPost'])($url, $payload, $this->apiKey);
        if (!($raw['ok'] ?? false)) {
            return ['ok' => false, 'content' => '', 'error' => $raw['error'] ?? 'transport failed'];
        }
        $code = (int) ($raw['status'] ?? 0);
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'content' => '', 'error' => "HTTP {$code}"];
        }
        try {
            /** @var array<string, mixed> $json */
            $json = json_decode((string) $raw['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return ['ok' => false, 'content' => '', 'error' => $e->getMessage()];
        }
        $content = $json['choices'][0]['message']['content'] ?? '';
        if (!is_string($content) || $content === '') {
            return ['ok' => false, 'content' => '', 'error' => 'empty content'];
        }

        return ['ok' => true, 'content' => $content];
    }

    /**
     * @return array{ok: bool, status: int, body: string, error?: string}
     */
    private function curlPost(string $url, string $payload, string $apiKey): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'curl_init failed'];
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => $err ?: 'curl failed'];
        }

        return ['ok' => true, 'status' => $code, 'body' => (string) $body];
    }
}
