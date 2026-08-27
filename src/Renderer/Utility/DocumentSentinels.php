<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer\Utility;

use MarkupCarve\Carve\Exception\SentinelSpaceExhaustedException;

/**
 * Picks in-band sentinels a document cannot contain.
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
     * @throws \MarkupCarve\Carve\Exception\SentinelSpaceExhaustedException When the
     *   document leaves no run of `$count` unused code points at or above `$first`.
     *
     * @return list<string> Never a run `$text` contains.
     */
    public static function pick(string $text, int $count, int $first): array
    {
        // ONE CODE POINT AT A TIME, not one RUN at a time. Stepping by $count
        // only ever tested ALIGNED runs, so the search gave up after
        // (0xF8FF - $first) / $count candidates - roughly a thousand for a run
        // of six - and a document holding ONE character from each of those was
        // enough to exhaust it and fall through to the colliding preferred run.
        // The comment below used to claim the fallback needed every private-use
        // code point to be written, which is a constraint that did not hold: it
        // needed about a sixth of them, in a document a generator could produce.
        // Stepping by one means the fallback really does need the whole range
        // covered with no gap of $count (markup-carve/carve-php#1087).
        for ($base = $first; $base + $count - 1 <= self::PRIVATE_USE_LAST; $base++) {
            $run = self::run($base, $count);
            if (!self::collides($text, $run)) {
                return $run;
            }
        }

        throw new SentinelSpaceExhaustedException($count, $first, self::PRIVATE_USE_LAST);
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
