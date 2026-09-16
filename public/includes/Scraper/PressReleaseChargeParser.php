<?php
declare(strict_types=1);

namespace Project1960\Scraper;

/**
 * Extract defendant charges + docket refs from DOJ press HTML/text.
 *
 * Structured lists (Thai-style &lt;strong&gt;NAME&lt;/strong&gt; + Convicted/Charged + &lt;li&gt;)
 * get full charge rows. Prose releases still yield docket refs + UMT/§1960 phrase hits.
 */
final class PressReleaseChargeParser
{
    /**
     * @return array{
     *   charges: list<array{defendant: ?string, charge_description: string, count_num: ?int, status: ?string, is_1960: bool}>,
     *   docket_refs: list<array{docket_number: string, court_hint: ?string, caption: ?string}>,
     *   mode: string
     * }
     */
    public function parse(string $htmlOrText): array
    {
        $charges = $this->parseStructuredDefendantLists($htmlOrText);
        $mode = $charges !== [] ? 'structured' : 'prose';
        if ($charges === []) {
            $charges = $this->parseProse1960Mentions($htmlOrText);
        }
        $refs = $this->parseDocketRefs($htmlOrText);

        return [
            'charges' => $charges,
            'docket_refs' => $refs,
            'mode' => $mode,
        ];
    }

    public static function chargeLooksLike1960(string $description): bool
    {
        if (KeywordFilters::mentions1960($description)) {
            return true;
        }
        $hay = mb_strtolower($description);

        return str_contains($hay, 'unlicensed money')
            || str_contains($hay, 'unlawful money')
            || str_contains($hay, 'money transmitting business')
            || str_contains($hay, 'money transmitter')
            || (str_contains($hay, '1960') && str_contains($hay, '18'));
    }

    /**
     * @return list<array{defendant: ?string, charge_description: string, count_num: ?int, status: ?string, is_1960: bool}>
     */
    private function parseStructuredDefendantLists(string $html): array
    {
        if (!str_contains(mb_strtolower($html), '<strong')) {
            return [];
        }
        $out = [];
        // Split on defendant name headings.
        if (preg_match_all(
            '/<strong>(.*?)<\/strong>(.*?)(?=<strong>|\\z)/is',
            $html,
            $blocks,
            PREG_SET_ORDER
        ) === false) {
            return [];
        }
        foreach ($blocks as $block) {
            $rawName = html_entity_decode(strip_tags($block[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $rawName = trim(preg_replace('/\s+/u', ' ', str_replace("\xc2\xa0", ' ', $rawName)) ?? '');
            if ($rawName === '' || mb_strlen($rawName) < 3) {
                continue;
            }
            // Skip non-person headings (section titles).
            if (!preg_match('/[A-Za-z].*,\s*\d{1,3}\s*$/u', $rawName)
                && !preg_match('/^[A-Z][A-Z\s\.\-\']{4,}$/u', $rawName)) {
                // Allow "NAME, 65" or ALL CAPS names.
                if (!preg_match('/^[A-Za-z].{2,80}$/u', $rawName)) {
                    continue;
                }
            }
            $chunk = $block[2];
            $status = null;
            if (preg_match('/\b(Convicted|Charged|Pleaded guilty|Pled guilty|Indicted)\s*:/i', $chunk, $sm)) {
                $status = mb_strtolower($sm[1]);
                if (str_starts_with($status, 'plead') || str_starts_with($status, 'pled')) {
                    $status = 'pleaded';
                } elseif (str_starts_with($status, 'indict')) {
                    $status = 'indicted';
                } elseif (str_starts_with($status, 'charg')) {
                    $status = 'charged';
                } else {
                    $status = 'convicted';
                }
            }
            if (!preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $chunk, $lis)) {
                continue;
            }
            $defendant = $this->normalizeDefendantName($rawName);
            foreach ($lis[1] as $liHtml) {
                $li = html_entity_decode(strip_tags($liHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $li = trim(preg_replace('/\s+/u', ' ', str_replace("\xc2\xa0", ' ', $li)) ?? '');
                if ($li === '') {
                    continue;
                }
                $count = null;
                if (preg_match('/,\s*(\d+)\s+counts?\s*$/i', $li, $cm)) {
                    $count = (int) $cm[1];
                    $li = trim(preg_replace('/,\s*\d+\s+counts?\s*$/i', '', $li) ?? $li);
                }
                $out[] = [
                    'defendant' => $defendant,
                    'charge_description' => $li,
                    'count_num' => $count,
                    'status' => $status,
                    'is_1960' => self::chargeLooksLike1960($li),
                ];
            }
        }

        return $out;
    }

    /**
     * @return list<array{defendant: ?string, charge_description: string, count_num: ?int, status: ?string, is_1960: bool}>
     */
    private function parseProse1960Mentions(string $html): array
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', str_replace("\xc2\xa0", ' ', $text)) ?? $text;
        $out = [];
        $patterns = [
            '/operating an? unlicensed money transmitting business[^.]{0,80}/i',
            '/operat(?:e|ed|ing) an? unlicensed money transmit(?:ting|ter)[^.?]{0,80}/i',
            '/unlicensed money transmitting business[^.?]{0,40}/i',
            '/18\s*U\.?\s*S\.?\s*C\.?\s*§?\s*1960[^.?]{0,40}/i',
            '/§\s*1960[^.?]{0,40}/i',
        ];
        $seen = [];
        foreach ($patterns as $pat) {
            if (preg_match_all($pat, $text, $ms)) {
                foreach ($ms[0] as $hit) {
                    $desc = trim($hit);
                    $key = mb_strtolower($desc);
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $out[] = [
                        'defendant' => null,
                        'charge_description' => $desc,
                        'count_num' => null,
                        'status' => null,
                        'is_1960' => true,
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * @return list<array{docket_number: string, court_hint: ?string, caption: ?string}>
     */
    private function parseDocketRefs(string $html): array
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', str_replace("\xc2\xa0", ' ', $text)) ?? $text;
        $refs = [];
        $seen = [];

        if (preg_match_all(
            '/United States\s+v\.\s+([^,(]+?(?:,\s*et al\.)?)\s*,\s*(\d{1,2}:\d{2}-cr-\d+|\d{2}-cr-\d+)/i',
            $text,
            $ms,
            PREG_SET_ORDER
        )) {
            foreach ($ms as $m) {
                $num = $this->normalizeDocketNumber($m[2]);
                if ($num === '' || isset($seen[$num])) {
                    continue;
                }
                $seen[$num] = true;
                $refs[] = [
                    'docket_number' => $num,
                    'court_hint' => null,
                    'caption' => 'United States v. ' . trim($m[1]),
                ];
            }
        }

        if (preg_match_all('/\b(\d{1,2}:\d{2}-cr-\d+)\b/i', $text, $ms)) {
            foreach ($ms[1] as $raw) {
                $num = $this->normalizeDocketNumber($raw);
                if ($num === '' || isset($seen[$num])) {
                    continue;
                }
                $seen[$num] = true;
                $refs[] = [
                    'docket_number' => $num,
                    'court_hint' => null,
                    'caption' => null,
                ];
            }
        }

        if (preg_match_all('/\b(\d{2}-cr-\d+)\b/i', $text, $ms)) {
            foreach ($ms[1] as $raw) {
                $num = $this->normalizeDocketNumber($raw);
                if ($num === '' || isset($seen[$num])) {
                    continue;
                }
                $seen[$num] = true;
                $refs[] = [
                    'docket_number' => $num,
                    'court_hint' => null,
                    'caption' => null,
                ];
            }
        }

        return $refs;
    }

    private function normalizeDefendantName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        // Drop trailing age ", 65"
        $name = trim(preg_replace('/,\s*\d{1,3}\s*$/u', '', $name) ?? $name);

        return $name;
    }

    private function normalizeDocketNumber(string $raw): string
    {
        $raw = strtolower(trim($raw));
        if (preg_match('/^(\d{1,2}):(\d{2})-cr-(\d+)$/', $raw, $m)) {
            return sprintf('%d:%02d-cr-%d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('/^(\d{2})-cr-(\d+)$/', $raw, $m)) {
            return sprintf('%02d-cr-%d', (int) $m[1], (int) $m[2]);
        }

        return $raw;
    }
}
