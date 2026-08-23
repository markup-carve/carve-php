<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 12 §4: a span "ends immediately after the last source codepoint the
 * construct owns [...] Containers end at their closer, or at their last child
 * when they have no closer."
 *
 * THIS ASSERTS THE RULE, NOT THREE OFFSETS. The defect it pins was never three
 * defects: `deriveContainerSpans()` carried the closerless-container set as an
 * `instanceof` chain written inline, so a type had to be remembered into it,
 * and `heading`, `footnote`, `definition_term` and `figure` never were. They
 * kept deriving their extent from the LINES they consumed, which takes in
 * whatever the content line dropped - a trailing whitespace run, or the line
 * terminator that ended the block.
 *
 * A test asserting three expected offsets would have passed the moment three
 * types were patched and said nothing about the fourth, or about the next type
 * added to the parser. So this walks every node of every fixture and applies
 * the same bound `checkStopsAtChildren` in the spec repository's
 * `scripts/spec/ast-positions.mjs` applies: no container in the set may end
 * past the furthest point any of its placed children reaches.
 *
 * The fixtures deliberately include shapes that ALREADY passed - a block quote,
 * a list, a nested item - because they share the one site with the shapes that
 * did not. A run in which only the three reported types fail is a run against a
 * per-type patch.
 */
class AClosersLessContainerEndsAtItsLastChildTest extends TestCase
{
    /**
     * The types PART 12 §4 gives no closer, mirroring `ENDS_AT_LAST_CHILD` in
     * the spec repository's `scripts/spec/ast-positions.mjs`.
     *
     * @var list<string>
     */
    private const ENDS_AT_LAST_CHILD = [
        'block_quote',
        'definition_list',
        'definition_term',
        'figure',
        'footnote',
        'heading',
        'list',
        'list_item',
        'paragraph',
    ];

    /**
     * @return array<string, array{string}>
     */
    public static function closerlessContainerProvider(): array
    {
        return [
            // The three shapes the conformance run reported, from
            // markup-carve/carve-php#1638.
            'a footnote definition stops at its body, not at the blank line after it' => [
                "x[^n]\n\n[^n]: b\n\ntail\n",
            ],
            'a heading stops before the trailing whitespace its line drops' => [
                "# h  \n",
            ],
            'a definition term stops where its own code child stops' => [
                ":: `a\nb \n:  d\n",
            ],
            // Shapes that already agreed. They run through the SAME predicate,
            // so they go red together with the three above when it is removed.
            'a block quote stops at its last child' => [
                "> a\n>\n> b\n\ntail\n",
            ],
            'a list and its items stop at their last child' => [
                "- a\n- b\n  - c\n\ntail\n",
            ],
            'a definition list stops at its last description' => [
                ":: t\n:  d\n\ntail\n",
            ],
            'a figure stops at its caption' => [
                "![alt](i.png)\n^ cap\n\ntail\n",
            ],
            'a heading with an inline child stops at that child' => [
                "## *b* t\n\ntail\n",
            ],
            'a footnote whose body is a list stops at the list' => [
                "x[^n]\n\n[^n]: - a\n\ntail\n",
            ],
            // The shapes that make the one-site property VISIBLE. A container
            // whose trailing source produced no child of its own is where a
            // line-derived extent and a child-derived one come apart, so these
            // are the arrangements in which `list`, `list_item` and
            // `block_quote` go red together with the three reported types when
            // the predicate is removed. Reduced from the corpus documents that
            // carry them: 05-lists-28, 194-an-abbreviation-at-a-list-item-s-
            // content-column-is-still-not-a-definition-2, and
            // 266-a-reference-definition-is-anchored-at-end-of-line-14.
            'a quoted list stops before the blank quote lines after it' => [
                "> > - a\n> >\n> >\n> >\n> > - b\n",
            ],
            'a list item stops before a definition collected out of it' => [
                "- a\n  [r]: /u\n\nsee [t][r]\n",
            ],
            'a block quote stops before a definition anchored out of it' => [
                "> text\n> [a]: /u {.c}\nlazy\n",
            ],
        ];
    }

    #[DataProvider('closerlessContainerProvider')]
    public function testAContainerWithNoCloserNeverReachesPastItsLastChild(string $source): void
    {
        $parser = new BlockParser(false, false, false, true);
        $doc = (new AstCodec())->encode($parser->parse($source));

        $findings = [];
        $this->walk($doc, $source, $findings);

        // COLLECTED ACROSS THE WHOLE TREE, asserted once. Asserting inside the
        // walk aborts at the first node that fails, so a tree with six wrong
        // spans reports one and the defect is sized wrong.
        $this->assertSame([], $findings, implode("\n", $findings));
    }

    /**
     * @param array<string, mixed> $node
     * @param list<string> $findings
     */
    private function walk(array $node, string $source, array &$findings, string $path = '$'): void
    {
        $children = [];
        foreach ($node as $key => $value) {
            if ($key === 'pos' || !is_array($value)) {
                continue;
            }
            if (isset($value['type'])) {
                $children[] = $value;
                $this->walk($value, $source, $findings, $path . '.' . $key);

                continue;
            }
            foreach ($value as $i => $item) {
                if (!is_array($item) || !isset($item['type'])) {
                    continue;
                }
                $children[] = $item;
                $this->walk($item, $source, $findings, $path . '.' . $key . "[$i]");
            }
        }

        $type = $node['type'] ?? null;
        if (!is_string($type) || !in_array($type, self::ENDS_AT_LAST_CHILD, true)) {
            return;
        }
        $end = $node['pos']['endOffset'] ?? null;
        if (!is_int($end)) {
            return;
        }

        $bound = null;
        foreach ($children as $child) {
            $childEnd = $child['pos']['endOffset'] ?? null;
            if (is_int($childEnd) && ($bound === null || $childEnd > $bound)) {
                $bound = $childEnd;
            }
        }
        if ($bound === null || $end <= $bound) {
            return;
        }

        $tail = mb_substr($source, $bound, $end - $bound, 'UTF-8');
        $findings[] = sprintf(
            'span reaches past its last child on "%s" at %s: it ends at %d, its last child '
                . 'ends at %d, and %s belongs to no child of it',
            $type,
            $path,
            $end,
            $bound,
            json_encode($tail, JSON_UNESCAPED_UNICODE),
        );
    }
}
