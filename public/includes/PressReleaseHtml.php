<?php
declare(strict_types=1);

namespace Project1960;

/**
 * DOJ press-release bodies are stored as HTML. Render a safe subset for case detail
 * (legacy Flask used |safe; PHP must not htmlspecialchars the whole body).
 */
final class PressReleaseHtml
{
    /** @var list<string> */
    private const ALLOWED_TAGS = [
        'p', 'br', 'hr',
        'a', 'strong', 'b', 'em', 'i', 'u',
        'ul', 'ol', 'li',
        'h2', 'h3', 'h4', 'h5',
        'blockquote', 'div', 'span',
    ];

    /** @var list<string> */
    private const ALLOWED_ATTRS = ['href', 'title', 'rel', 'target'];

    /**
     * Return HTML safe to echo into the case detail page (already escaped/sanitized).
     */
    public static function render(?string $raw): string
    {
        if ($raw === null || trim($raw) === '') {
            return '';
        }
        // Plain text (no real HTML tags): escape + preserve newlines
        if (!self::looksLikeHtml($raw)) {
            return nl2br(View::e($raw), false);
        }

        return self::sanitize($raw);
    }

    private static function looksLikeHtml(string $raw): bool
    {
        return (bool) preg_match(
            '/<\s*\/?\s*(?:p|br|hr|div|span|a|ul|ol|li|h[1-6]|strong|em|b|i|u|table|thead|tbody|tr|td|th|blockquote|img)\b/i',
            $raw
        );
    }

    private static function sanitize(string $html): string
    {
        $wrapped = '<!DOCTYPE html><html><body><div id="p1960-root">' . $html . '</div></body></html>';
        $prev = libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8">' . $wrapped,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$loaded) {
            return nl2br(View::e(strip_tags($html)), false);
        }

        $root = $dom->getElementById('p1960-root');
        if (!$root instanceof \DOMElement) {
            return nl2br(View::e(strip_tags($html)), false);
        }

        self::walk($root);
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return $out;
    }

    private static function walk(\DOMNode $node): void
    {
        if ($node instanceof \DOMElement) {
            $tag = strtolower($node->tagName);
            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                // Replace disallowed element with its children (unwrap)
                $parent = $node->parentNode;
                if ($parent instanceof \DOMNode) {
                    while ($node->firstChild) {
                        $parent->insertBefore($node->firstChild, $node);
                    }
                    $parent->removeChild($node);
                }

                return;
            }
            // Strip event handlers / dangerous attrs
            if ($node->hasAttributes()) {
                $remove = [];
                foreach ($node->attributes ?? [] as $attr) {
                    $name = strtolower($attr->name);
                    if (str_starts_with($name, 'on') || !in_array($name, self::ALLOWED_ATTRS, true)) {
                        $remove[] = $attr->name;
                        continue;
                    }
                    if ($name === 'href') {
                        $href = trim($attr->value);
                        if ($href !== '' && !preg_match('#^(https?:|mailto:|/)#i', $href)) {
                            $remove[] = $attr->name;
                        }
                    }
                    if ($name === 'target' && $attr->value !== '_blank') {
                        $remove[] = $attr->name;
                    }
                }
                foreach ($remove as $name) {
                    $node->removeAttribute($name);
                }
                if ($node->getAttribute('target') === '_blank' && $node->getAttribute('rel') === '') {
                    $node->setAttribute('rel', 'noopener noreferrer');
                }
            }
        }

        // Copy child list — walk may mutate
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }
        foreach ($children as $child) {
            self::walk($child);
        }
    }
}
