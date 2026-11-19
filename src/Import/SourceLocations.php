<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import;

/**
 * Source starts for imported HTML nodes. DOM's HTML5 parser does not expose source offsets, so explicit opening tokens
 * are matched to the copied nodes by tag and occurrence. Implied nodes have no range.
 */
final class SourceLocations
{
    public static function assign(string $html, HtmlNode $root): void
    {
        $tokens = [];
        preg_match_all('/<!--.*?-->|<!doctype[^>]*>|<\/?[A-Za-z][^>]*>/is', $html, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$source, $offset]) {
            if (preg_match('/^<([A-Za-z][A-Za-z0-9:-]*)\b/', $source, $name) === 1) {
                $tag = strtolower($name[1]);
            } elseif (stripos($source, '<!doctype') === 0) {
                $tag = '#doctype';
            } elseif (str_starts_with($source, '<!--')) {
                $tag = '#comment';
            } else {
                continue;
            }
            $tokens[$tag][] = [$offset, $offset + strlen($source)];
        }
        $positions = [];
        $walk = static function (HtmlNode $node) use (&$walk, &$positions, $tokens): void {
            $tag = $node->tag();
            $at = $positions[$tag] ?? 0;
            if (isset($tokens[$tag][$at])) {
                [$node->sourceStart, $node->sourceEnd] = $tokens[$tag][$at];
                $node->contentStart = $node->sourceEnd;
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
}
