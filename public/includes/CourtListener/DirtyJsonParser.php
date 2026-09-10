<?php
declare(strict_types=1);

namespace Project1960\CourtListener;

/**
 * Resilient JSON extraction from model output (CL-X1).
 */
final class DirtyJsonParser
{
    /**
     * @return array<mixed>|null
     */
    public static function parse(string $content): ?array
    {
        $content = trim($content);
        if ($content === '') {
            return null;
        }
        // Strip common think / fence wrappers
        $content = preg_replace('#<think>.*?</think>#si', '', $content) ?? $content;
        $content = preg_replace('#```(?:json)?\s*#i', '', $content) ?? $content;
        $content = str_replace('```', '', $content);
        $content = trim($content);

        $candidates = self::candidateSnippets($content);
        foreach ($candidates as $snippet) {
            $decoded = self::tryDecode($snippet);
            if ($decoded !== null) {
                return $decoded;
            }
            $cleaned = self::clean($snippet);
            $decoded = self::tryDecode($cleaned);
            if ($decoded !== null) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function candidateSnippets(string $content): array
    {
        $out = [$content];
        if (preg_match('/\[[\s\S]*\]/', $content, $m)) {
            $out[] = $m[0];
        }
        if (preg_match('/\{[\s\S]*\}/', $content, $m)) {
            $out[] = $m[0];
        }

        return $out;
    }

    private static function clean(string $json): string
    {
        $json = trim($json);
        $json = preg_replace('/,(\s*[}\]])/', '$1', $json) ?? $json;
        $json = preg_replace("/'([^']*)'/", '"$1"', $json) ?? $json;
        $json = preg_replace('/([\x00-\x1f\x7f])/u', '', $json) ?? $json;

        return $json;
    }

    /** @return array<mixed>|null */
    private static function tryDecode(string $json): ?array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }
}
