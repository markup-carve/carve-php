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
        'basefont' => true,
        'bgsound' => true,
        'br' => true,
        'col' => true,
        'embed' => true,
        'frame' => true,
        'hr' => true,
        'img' => true,
        'input' => true,
        'keygen' => true,
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
            if (self::consumesRaw($raw, $source)) {
                continue;
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
            $selfClosed = self::isSelfClosed($tag, $source);
            $implicitEnd = $selfClosed ? $at : $inputEnd;
            $tokens[$tag][] = $implicitEnd;
            $tokens[$tag][] = $implicitEnd;
            if (!$selfClosed) {
                $open[] = [$tag, $nextId];
                $byTag[$tag][] = [$nextId, $index];
                $active[$nextId++] = true;
            }
        }
        $positions = [];
        self::assignNodes($root->children, $positions, $tokens);
        self::bound($root->children, strlen($html));
    }

    /**
     * @param array<\MarkupCarve\Carve\Import\HtmlNode> $nodes
     * @param array<string, int> $positions
     * @param array<string, array<int>> $tokens
     */
    private static function assignNodes(array $nodes, array &$positions, array $tokens): void
    {
        foreach ($nodes as $node) {
            $tag = $node->tag();
            $at = $positions[$tag] ?? 0;
            if (isset($tokens[$tag][4 * $at])) {
                $node->sourceStart = $tokens[$tag][4 * $at];
                $node->contentStart = $tokens[$tag][4 * $at + 1];
                $node->sourceEnd = $tokens[$tag][4 * $at + 2];
                $node->sourceCloseStart = $tokens[$tag][4 * $at + 3];
                $positions[$tag] = $at + 1;
            }
            if ($node->children !== []) {
                self::assignNodes($node->children, $positions, $tokens);
            }
            if ($node->content !== null) {
                self::assignNodes($node->content, $positions, $tokens);
            }
        }
    }

    private static function isSelfClosed(string $tag, string $source): bool
    {
        // SVG and MathML root tags accept a self-closing slash; HTML non-void tags do not.
        $foreignSelfClosing = in_array($tag, ['svg', 'math'], true) && preg_match('/\/\s*>$/', $source) === 1;

        return $tag[0] === '#' || isset(self::VOID[$tag]) || $foreignSelfClosing;
    }

    private static function rawCloses(string $tag, string $source): bool
    {
        // HTML treats every apparent tag after a plaintext opener as text, including </plaintext>.
        return $tag !== 'plaintext' && preg_match('/^<\/\s*' . preg_quote($tag, '/') . '(?=[\s\/>])[^>]*>$/i', $source) === 1;
    }

    private static function consumesRaw(?string &$raw, string $source): bool
    {
        if ($raw === null) {
            return false;
        }
        if (self::rawCloses($raw, $source)) {
            $raw = null;

            return false;
        }

        return true;
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
            self::bound($node->content ?? [], $childLimit);
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
