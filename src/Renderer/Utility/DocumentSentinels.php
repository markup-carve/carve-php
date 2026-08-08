<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer\Utility;

/**
 * Picks in-band sentinels a document cannot contain.
 *
 * A renderer that has to mark a position inside a string it is still building
 * needs a character the finished bytes will never carry by accident. A FIXED
 * character cannot give that guarantee: whatever it is, an author may write it,
 * and then the restore pass rewrites the author's own character into whatever
 * the sentinel stood for. Three of the canonical writer's four fixed sentinels
 * were found corrupting authored occurrences into a space, a tab or nothing
 * (markup-carve/carve#678), and the HTML target's soft-break guard was
 * substituting a newline for an authored U+0001 (markup-carve/carve-php#1077).
 *
 * The fix is not to escape the authored occurrences: any escape needs a
 * reserved character of its own, which has exactly the same collision. It is to
 * choose sentinels the DOCUMENT does not contain, from the private-use area,
 * before rendering starts. That cannot collide by construction.
 *
 * Two renderers need this and the reasoning is one rule, so it lives in one
 * place rather than being spelled twice.
 *
 * A private-use code point is THREE BYTES standing for one position, so any
 * byte-length arithmetic around a sentinel has to be checked rather than
 * assumed.
 */
final class DocumentSentinels
{
    /**
     * The last code point of the Basic Multilingual Plane's private-use area.
     *
     * @var int
     */
    public const PRIVATE_USE_LAST = 0xF8FF;

    /**
     * Every string in the tree, joined.
     *
     * ITERATIVE on purpose. `json_encode()` would be one line and it recurses,
     * so on a document deeper than its nesting limit it fails - and the callers
     * have a documented PART 9 section 25 depth REFUSAL that has to be what
     * fires instead. The `(array)` cast reaches protected and private
     * properties, so no node type needs to know about this.
     *
     * @param object|array<mixed> $root A node, or the run of nodes a fragment
     *   render is handed.
     */
    public static function collectStrings(object|array $root): string
    {
        $parts = [];
        $stack = [$root];
        // Nodes carry a PARENT reference, so the tree is a cyclic graph and an
        // unguarded walk never terminates. Visiting each object once is what
        // makes this linear rather than infinite.
        $seen = [];
        while ($stack !== []) {
            $node = array_pop($stack);
            if (is_string($node)) {
                $parts[] = $node;

                continue;
            }
            if (is_array($node)) {
                foreach ($node as $key => $value) {
                    // KEYS carry authored text too. A document's abbreviations
                    // are keyed by the TERM, and the term is written back out -
                    // so a values-only walk missed it and the writer rewrote
                    // the author's character in `*[term]: expansion` while
                    // getting every code block right.
                    if (is_string($key)) {
                        $parts[] = $key;
                    }
                    $stack[] = $value;
                }

                continue;
            }
            if (!is_object($node)) {
                continue;
            }
            $id = spl_object_id($node);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            foreach ((array)$node as $value) {
                $stack[] = $value;
            }
        }

        return implode("\0", $parts);
    }

    /**
     * A run of `$count` private-use code points starting at or above `$first`
     * that `$text` does not contain.
     *
     * The common case is the first run, so the search only runs for a document
     * that actually writes one of them.
     *
     * @param string $text Every authored string in the document, joined.
     * @param int $count How many sentinels the caller needs.
     * @param int $first The preferred first code point of the run.
     *
     * @return list<string>
     */
    public static function pick(string $text, int $count, int $first): array
    {
        for ($base = $first; $base + $count - 1 <= self::PRIVATE_USE_LAST; $base += $count) {
            $run = self::run($base, $count);
            if (!self::collides($text, $run)) {
                return $run;
            }
        }

        // Unreachable for any real document: it would have to write every
        // private-use code point above $first. Keep the preferred run rather
        // than throw.
        return self::run($first, $count);
    }

    /**
     * @return list<string>
     */
    protected static function run(int $base, int $count): array
    {
        $run = [];
        for ($i = 0; $i < $count; $i++) {
            $run[] = (string)mb_chr($base + $i, 'UTF-8');
        }

        return $run;
    }

    /**
     * @param string $text
     * @param list<string> $candidates
     */
    protected static function collides(string $text, array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if (str_contains($text, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
