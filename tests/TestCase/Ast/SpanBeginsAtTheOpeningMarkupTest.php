<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 12 §4: A SPAN BEGINS AT THE CONSTRUCT'S OPENING MARKUP (carve#913).
 *
 * A node's `pos` covers the construct as WRITTEN - the `>` of a block quote,
 * the `#` of a heading, a list item's marker - so a span round-trips to the
 * source text that produced it. The rule the spec repo enforces reads the
 * OPENING CHARACTER OUT OF THE SOURCE and never out of what the node says it
 * holds, and so does this: every case asserts the character the offset lands
 * on, not just the number.
 *
 * WHAT WENT WRONG. Everything inside a container was parsed from lines the
 * container prefix had already been cut off, and the only offset table left by
 * then records where the whole SOURCE LINE starts. So a heading in a block
 * quote began at the `>` that opens the quote, a fenced block in a list item at
 * the `-` that opens the item, and a `- +` item at the flush-left table that is
 * its body rather than at its own marker line.
 *
 * PRESENCE IS ASSERTED BEFORE EXTENT, deliberately. `trackPositions` is an
 * opt-in parse option, so a probe that does not request it hands every one of
 * these cases a node with no `pos` at all - and a comparison against a missing
 * key passes against the unfixed parser just as happily as against the fixed
 * one (carve#755, carve-php#978).
 */
class SpanBeginsAtTheOpeningMarkupTest extends TestCase
{
    /**
     * Every case is a construct nested in a container, plus the controls that
     * say the rule did not simply shift everything.
     *
     * @return array<string, array{0: string, 1: string, 2: int, 3: string}>
     */
    public static function nestedConstructs(): array
    {
        return [
            'heading in a block quote' => ["> # h\n", 'children.0.children.0', 2, '#'],
            'heading in a list item' => ["- # h\n", 'children.0.items.0.children.0', 2, '#'],
            'heading two quotes deep' => ["> > # h\n", 'children.0.children.0.children.0', 4, '#'],
            'inner quote in a quote' => ["> > # h\n", 'children.0.children.0', 2, '>'],
            'code block in a block quote' => ["> ```\n> a\n> ```\n", 'children.0.children.0', 2, '`'],
            'code block in a list item' => ["- ```\n  a\n  ```\n", 'children.0.items.0.children.0', 2, '`'],
            'raw block in a block quote' => ["> ```=html\n> <pre>\n> ```\n", 'children.0.children.0', 2, '`'],
            'comment in a block quote' => ["> %%%\n> x\n> %%%\n", 'children.0.children.0', 2, '%'],
            'admonition in a list item' => ["- ::: note\n  - x\n  :::\n", 'children.0.items.0.children.0', 2, ':'],
            'definition list in a block quote' => ["> :: t\n~\n", 'children.0.children.0', 2, ':'],
            'block quote in a list item' => ["- >\nlazy\n", 'children.0.items.0.children.0', 2, '>'],
            'list in a block quote' => ["> - a\n", 'children.0.children.0', 2, '-'],
            'list item in a block quote' => ["> - a\n", 'children.0.children.0.items.0', 2, '-'],
            // The `+` item's body is FLUSH LEFT, so a span derived from the
            // children began at the table and skipped the marker line entirely.
            'continuation-marker item' => ["- +\n| a | b |\n|---|---|\n| c | d |\n", 'children.0.items.0', 0, '-'],
            // CONTROLS. Nothing outside a container moves, and a list item
            // still opens at its own marker rather than at its content.
            'heading at top level' => ["# h\n", 'children.0', 0, '#'],
            'list item at top level' => ["- # h\n", 'children.0.items.0', 0, '-'],
            'block quote at top level' => ["> # h\n", 'children.0', 0, '>'],
        ];
    }

    #[DataProvider('nestedConstructs')]
    public function testASpanBeginsAtTheOpeningMarkup(
        string $source,
        string $path,
        int $expectedOffset,
        string $expectedOpener,
    ): void {
        $node = $this->at($this->parseWithPositions($source), $path);

        // PRESENCE FIRST: see the class docblock. Without this the two
        // assertions below compare a missing key and report nothing.
        $this->assertArrayHasKey('pos', $node, "no pos on $path");
        $this->assertArrayHasKey('startOffset', $node['pos'], "no startOffset on $path");

        $this->assertSame($expectedOffset, $node['pos']['startOffset'], "start offset of $path");
        $codepoints = preg_split('//u', $source, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $this->assertSame(
            $expectedOpener,
            $codepoints[$node['pos']['startOffset']] ?? '',
            "the character $path opens at",
        );
    }

    /**
     * A parent still contains every child, which is the OTHER half of §4 and a
     * separate pass in the conformance checker for the reason carve#913 gives:
     * the two point the same way today, and deriving either from the other
     * would go quiet the day that changed.
     */
    #[DataProvider('nestedConstructs')]
    public function testAParentStillContainsEveryChild(
        string $source,
        string $path,
        int $expectedOffset,
        string $expectedOpener,
    ): void {
        $compared = 0;
        $this->assertContainment($this->parseWithPositions($source), null, '$', $compared);

        // A containment pass that examined nothing is indistinguishable from a
        // clean one, so the count is asserted rather than the findings alone.
        $this->assertGreaterThan(0, $compared, 'no parent/child pair was compared');
    }

    /**
     * PAST THE NESTING CAP the opener is ordinary paragraph text (PART 9 §25),
     * and the paragraph still begins where its own text does.
     *
     * The cap branch returns from `parseBlocks()` BEFORE the level's content
     * column is swapped in, so it is the one path that has to record the column
     * for itself. Left to the level above, the degraded paragraph is stamped at
     * the second-to-last container prefix, two characters before the text it
     * holds - and the slice comparison, which is what the conformance checker
     * can reach here, is what says so.
     */
    public function testAnOverCapParagraphBeginsAtItsOwnText(): void
    {
        $depth = 201;
        $source = str_repeat('> ', $depth) . "x\n";
        $paragraph = $this->parseWithPositions($source);
        while (isset($paragraph['children'][0])) {
            $paragraph = $paragraph['children'][0];
            if (($paragraph['type'] ?? '') === 'paragraph') {
                break;
            }
        }

        $this->assertSame('paragraph', $paragraph['type'] ?? null);
        $this->assertArrayHasKey('pos', $paragraph, 'the over-cap paragraph carries no position');

        $codepoints = preg_split('//u', $source, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $slice = implode('', array_slice(
            $codepoints,
            $paragraph['pos']['startOffset'],
            $paragraph['pos']['endOffset'] - $paragraph['pos']['startOffset'],
        ));

        $this->assertSame('> x', $slice);
        $this->assertSame('> x', $paragraph['children'][0]['value'] ?? null);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed>|null $parent
     * @param string $path
     * @param int $compared
     */
    private function assertContainment(array $node, ?array $parent, string $path, int &$compared): void
    {
        $placed = isset($node['type']) && isset($node['pos']);
        if ($placed && $parent !== null) {
            $compared++;
            $this->assertGreaterThanOrEqual(
                $parent['pos']['startOffset'],
                $node['pos']['startOffset'],
                "$path starts before its parent",
            );
            $this->assertLessThanOrEqual(
                $parent['pos']['endOffset'],
                $node['pos']['endOffset'],
                "$path ends after its parent",
            );
        }
        $nextParent = $placed ? $node : $parent;
        foreach ($node as $key => $value) {
            if ($key === 'pos' || !is_array($value)) {
                continue;
            }
            if (isset($value['type'])) {
                $this->assertContainment($value, $nextParent, $path . '.' . $key, $compared);

                continue;
            }
            foreach ($value as $i => $item) {
                if (is_array($item)) {
                    $this->assertContainment($item, $nextParent, $path . '.' . $key . '.' . $i, $compared);
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function parseWithPositions(string $source): array
    {
        $parser = new BlockParser(false, false, false, true);

        return (new AstCodec())->encode($parser->parse($source));
    }

    /**
     * @param array<string, mixed> $tree
     * @param string $path
     *
     * @return array<string, mixed>
     */
    private function at(array $tree, string $path): array
    {
        $node = $tree;
        foreach (explode('.', $path) as $step) {
            $this->assertIsArray($node[$step] ?? null, "no node at $step in $path");
            $node = $node[$step];
        }

        return $node;
    }
}
