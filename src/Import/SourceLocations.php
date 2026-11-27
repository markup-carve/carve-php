<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import;

/**
 * Source ranges for imported HTML nodes. DOM's HTML5 parser does not expose source offsets, so explicit tokens are
 * matched to the copied nodes by tag and occurrence. Implied nodes have no range.
 */
final class SourceLocations
{
    /**
     * HTML void elements have no closing token.
     *
     * @var array<string, true>
     */
    private const VOID = [
        'area' => true,
        'base' => true,
        'br' => true,
        'col' => true,
        'embed' => true,
        'hr' => true,
        'img' => true,
        'input' => true,
        'link' => true,
        'meta' => true,
        'param' => true,
        'source' => true,
        'track' => true,
        'wbr' => true,
    ];

    public static function assign(string $html, HtmlNode $root): void
    {
        // Start, opening end, and whole-element end packed per tag: a match array per tag costs hundreds of bytes.
        $tokens = [];
        $open = [];
        $raw = null;
        $at = 0;
        while (preg_match('/<!--.*?-->|<!doctype[^>]*>|<\/?[A-Za-z][^>]*>/is', $html, $match, PREG_OFFSET_CAPTURE, $at) === 1) {
            [$source, $offset] = $match[0];
            $at = $offset + strlen($source);
            if ($raw !== null) {
                if (preg_match('/^<\/\s*' . preg_quote($raw, '/') . '\s*>$/i', $source) === 1) {
                    $raw = null;
                } else {
                    continue;
                }
            }
            if (preg_match('/^<([A-Za-z][A-Za-z0-9:-]*)\b/', $source, $name) === 1) {
                $tag = strtolower($name[1]);
                if (in_array($tag, ['script', 'style', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes', 'plaintext'], true)) {
                    $raw = $tag;
                }
            } elseif (stripos($source, '<!doctype') === 0) {
                $tag = '#doctype';
            } elseif (str_starts_with($source, '<!--')) {
                $tag = '#comment';
            } elseif (preg_match('/^<\/\s*([A-Za-z][A-Za-z0-9:-]*)\b/', $source, $name) === 1) {
                self::close(strtolower($name[1]), $at, $open, $tokens);

                continue;
            } else {
                continue;
            }
            $index = intdiv(count($tokens[$tag] ?? []), 3);
            $tokens[$tag][] = $offset;
            $tokens[$tag][] = $at;
            $tokens[$tag][] = $at;
            if ($tag[0] !== '#' && !isset(self::VOID[$tag]) && !str_ends_with($source, '/>')) {
                $open[] = [$tag, $index];
            }
        }
        $positions = [];
        $walk = static function (HtmlNode $node) use (&$walk, &$positions, $tokens): void {
            $tag = $node->tag();
            $at = $positions[$tag] ?? 0;
            if (isset($tokens[$tag][3 * $at])) {
                $node->sourceStart = $tokens[$tag][3 * $at];
                $node->contentStart = $tokens[$tag][3 * $at + 1];
                $node->sourceEnd = $tokens[$tag][3 * $at + 2];
                $positions[$tag] = $at + 1;
            }
            foreach ($node->children as $child) {
                $walk($child);
            }
        };
        foreach ($root->children as $child) {
            $walk($child);
        }
    }

    /**
     * @param string $tag
     * @param int $end
     * @param array<array{string, int}> $open
     * @param array<string, array<int>> $tokens
     */
    private static function close(string $tag, int $end, array &$open, array &$tokens): void
    {
        for ($i = count($open) - 1; $i >= 0; $i--) {
            if ($open[$i][0] !== $tag) {
                continue;
            }
            $tokens[$tag][3 * $open[$i][1] + 2] = $end;
            for ($k = count($open) - 1; $k >= $i; $k--) {
                array_pop($open);
            }

            return;
        }
    }
}
