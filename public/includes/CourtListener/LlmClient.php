<?php
declare(strict_types=1);

namespace Project1960\CourtListener;

interface LlmClient
{
    /** @return array{ok: bool, content: string, error?: string} */
    public function complete(string $prompt): array;
}
