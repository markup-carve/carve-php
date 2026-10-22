<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\Markdown;

use DOMDocument;
use DOMElement;
use DOMXPath;

final class HeadingFragments
{
    /**
     * Find IDs supplied by raw HTML heading elements.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, int>
     */
    public static function rawHtmlHeadingIds(array $document): array
    {
        $ids = [];
        $visit = static function (array $node) use (&$visit, &$ids): void {
            if (in_array($node['type'] ?? null, ['raw_inline', 'raw_block'], true) && ($node['format'] ?? null) === 'html' && is_string($node['content'] ?? null)) {
                $dom = new DOMDocument();
                $previous = libxml_use_internal_errors(true);
                try {
                    $dom->loadHTML('<?xml encoding="UTF-8"><body>' . $node['content'], LIBXML_NONET);
                } finally {
                    libxml_clear_errors();
                    libxml_use_internal_errors($previous);
                }
                foreach ((new DOMXPath($dom))->query('//*[(@id and (self::section or self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6))]') ?: [] as $element) {
                    if (!$element instanceof DOMElement) {
                        continue;
                    }
                    $id = $element->getAttribute('id');
                    $ids[$id] = ($ids[$id] ?? 0) + 1;
                }
            }
            foreach ($node as $key => $child) {
                if ($key === 'attrs' || $key === 'position' || !is_array($child)) {
                    continue;
                }
                if (array_is_list($child)) {
                    foreach ($child as $item) {
                        if (is_array($item)) {
                            $visit($item);
                        }
                    }
                } else {
                    $visit($child);
                }
            }
        };
        $visit($document);

        return $ids;
    }

    public static function lowercaseKey(string $value): string
    {
        if (!function_exists('mb_strtolower')) {
            return strtolower($value);
        }
        if (str_contains($value, 'Σ')) {
            $value = preg_replace_callback('/Σ/u', static function (array $match) use ($value): string {
                $offset = $match[0][1];
                $before = substr($value, 0, $offset);
                $after = substr($value, $offset + strlen('Σ'));

                return preg_match('/\p{L}\p{M}*$/u', $before) === 1 && preg_match('/^\p{M}*\p{L}/u', $after) !== 1 ? 'ς' : 'σ';
            }, $value, flags: PREG_OFFSET_CAPTURE) ?? $value;
        }

        return mb_strtolower($value, 'UTF-8');
    }
}
