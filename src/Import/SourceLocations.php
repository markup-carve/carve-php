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
        // Start and end offsets packed in turn per tag, one match at a time: a match array per tag costs hundreds of bytes.
        $tokens = [];
        $raw = null;
        $at = 0;
        while (preg_match('/<!--.*?-->|<!doctype[^>]*>|<\/?[A-Za-z][^>]*>/is', $html, $match, PREG_OFFSET_CAPTURE, $at) === 1) {
            [$source, $offset] = $match[0];
            $at = $offset + strlen($source);
            if ($raw !== null) {
                if (preg_match('/^<\/\s*' . preg_quote($raw, '/') . '\s*>$/i', $source) === 1) {
                    $raw = null;
                }

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
            } else {
                continue;
            }
            $tokens[$tag][] = $offset;
            $tokens[$tag][] = $at;
        }
        $positions = [];
        $walk = static function (HtmlNode $node) use (&$walk, &$positions, $tokens): void {
            $tag = $node->tag();
            $at = $positions[$tag] ?? 0;
            if (isset($tokens[$tag][2 * $at])) {
                $node->sourceStart = $tokens[$tag][2 * $at];
                $node->sourceEnd = $tokens[$tag][2 * $at + 1];
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
