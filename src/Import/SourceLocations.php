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
        // Start, opening end, whole-element end and closing start packed per tag.
        $tokens = [];
        $open = [];
        $byTag = [];
        $active = [];
        $nextId = 0;
        $inputEnd = strlen($html);
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
                self::close(strtolower($name[1]), $offset, $at, $open, $byTag, $active, $tokens);

                continue;
            } else {
                continue;
            }
            $index = intdiv(count($tokens[$tag] ?? []), 4);
            $tokens[$tag][] = $offset;
            $tokens[$tag][] = $at;
            $implicitEnd = self::implicitEnd($tag, $at, $inputEnd);
            $tokens[$tag][] = $implicitEnd;
            $tokens[$tag][] = $implicitEnd;
            if ($tag[0] !== '#' && !isset(self::VOID[$tag])) {
                $open[] = [$tag, $nextId];
                $byTag[$tag][] = [$nextId, $index];
                $active[$nextId++] = true;
            }
        }
        $positions = [];
        $walk = static function (HtmlNode $node) use (&$walk, &$positions, $tokens): void {
            $tag = $node->tag();
            $at = $positions[$tag] ?? 0;
            if (isset($tokens[$tag][4 * $at])) {
                $node->sourceStart = $tokens[$tag][4 * $at];
                $node->contentStart = $tokens[$tag][4 * $at + 1];
                $node->sourceEnd = $tokens[$tag][4 * $at + 2];
                $node->sourceCloseStart = $tokens[$tag][4 * $at + 3];
                $positions[$tag] = $at + 1;
            }
            foreach ($node->children as $child) {
                $walk($child);
            }
        };
        foreach ($root->children as $child) {
            $walk($child);
        }
        self::bound($root->children, strlen($html));
    }

    private static function implicitEnd(string $tag, int $openingEnd, int $inputEnd): int
    {
        return $tag[0] === '#' || isset(self::VOID[$tag]) ? $openingEnd : $inputEnd;
    }

    /**
     * The HTML parser's sibling order decides an implicit close. An explicit closer found after the next sibling is
     * outside that element even when the source token stack paired it with the opener.
     *
     * @param array<\MarkupCarve\Carve\Import\HtmlNode> $nodes
     * @param int $limit
     */
    private static function bound(array $nodes, int $limit): void
    {
        $next = $limit;
        for ($i = count($nodes) - 1; $i >= 0; $i--) {
            $node = $nodes[$i];
            if ($node->sourceStart >= 0 && $next >= $node->contentStart) {
                $node->sourceEnd = min($node->sourceEnd, $next);
                $node->sourceCloseStart = min($node->sourceCloseStart, $next);
                $next = min($next, $node->sourceStart);
            }
            $childLimit = $node->sourceCloseStart >= $node->contentStart
                ? min($limit, $node->sourceCloseStart) : $limit;
            self::bound($node->children, $childLimit);
        }
    }

    /**
     * @param string $tag
     * @param int $start
     * @param int $end
     * @param array<array{string, int}> $open
     * @param array<string, array<array{int, int}>> $byTag
     * @param array<int, true> $active
     * @param array<string, array<int>> $tokens
     */
    private static function close(string $tag, int $start, int $end, array &$open, array &$byTag, array &$active, array &$tokens): void
    {
        while (isset($byTag[$tag]) && $byTag[$tag] !== []) {
            [$id, $index] = $byTag[$tag][array_key_last($byTag[$tag])];
            array_pop($byTag[$tag]);
            if (!isset($active[$id])) {
                continue;
            }
            $tokens[$tag][4 * $index + 2] = $end;
            $tokens[$tag][4 * $index + 3] = $start;
            while ($open !== []) {
                [, $popped] = array_pop($open);
                unset($active[$popped]);
                if ($popped === $id) {
                    break;
                }
            }

            return;
        }
    }
}
