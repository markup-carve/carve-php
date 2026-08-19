<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

/**
 * A node assembled from discontiguous source publishes NO position.
 *
 * A verbatim run a table row leaves open reaches into the `+` continuation row,
 * so its value is built from two authored chunks with the row's closing `|` and
 * the continuation marker between them - owned by neither. Both ends of that
 * range resolve, so a span came out covering markup the value does not contain,
 * and a consumer slicing the source by it read text the node never held.
 *
 * PART 12 §4's reconstruction rule is the one that decides it, and carve-js and
 * carve-rs both publish nothing here (carve-php#1361).
 *
 * The check is general rather than table-shaped: every gap between the segments
 * a span covers has to be the same size in the built string as in the source. A
 * joined line qualifies - the `\n` is one byte on both sides - which is why the
 * multi-line cases below keep their spans.
 */
class ReassembledRunPublishesNoSpanTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function ast(string $source): array
    {
        $converter = new CarveConverter(parser: new BlockParser(false, false, false, true));

        return (new AstCodec())->encode($converter->parse($source));
    }

    /**
     * @param array<string, mixed> $node
     * @param string $type
     *
     * @return array<int, array<string, mixed>>
     */
    private function collect(array $node, string $type): array
    {
        $found = [];
        if (($node['type'] ?? null) === $type) {
            $found[] = $node;
        }
        foreach (['children', 'rows', 'cells'] as $key) {
            foreach ($node[$key] ?? [] as $child) {
                $found = array_merge($found, $this->collect($child, $type));
            }
        }

        return $found;
    }

    public function testARunCarriedAcrossAContinuationRowIsUnplaced(): void
    {
        $codes = $this->collect($this->ast("| a `b |\n+ c` |\n"), 'code');

        $this->assertCount(1, $codes);
        $this->assertSame('b c', $codes[0]['value']);
        $this->assertArrayNotHasKey('pos', $codes[0], 'a value built from two chunks has no contiguous source');
    }

    public function testTheTextBesideItKeepsItsSpan(): void
    {
        // Only the reassembled node loses its position. A node that does sit on
        // one authored chunk is still placed, which is what keeps this from
        // being a blanket "tables have no spans" rule.
        $texts = $this->collect($this->ast("| a `b |\n+ c` |\n"), 'text');

        $this->assertSame('a ', $texts[0]['value']);
        $this->assertSame(2, $texts[0]['pos']['startOffset']);
        $this->assertSame(4, $texts[0]['pos']['endOffset']);
    }

    public function testARunInsideOneRowKeepsItsSpan(): void
    {
        // The same construct without the continuation: one chunk, one run, and
        // a span that slices back to `` `b c` ``.
        $codes = $this->collect($this->ast("| a `b c` |\n"), 'code');

        $this->assertCount(1, $codes);
        $this->assertSame('b c', $codes[0]['value']);
        $this->assertSame(4, $codes[0]['pos']['startOffset']);
        $this->assertSame(9, $codes[0]['pos']['endOffset']);
    }

    public function testAParagraphSpanningJoinedLinesKeepsItsSpan(): void
    {
        // The newline join is a REAL source byte, so the gap is one on both
        // sides and the span survives. A rule written as "one segment only"
        // would have taken every multi-line span with it.
        $paragraphs = $this->collect($this->ast("one\ntwo\n"), 'paragraph');

        $this->assertCount(1, $paragraphs);
        $this->assertSame(0, $paragraphs[0]['pos']['startOffset']);
        $this->assertSame(7, $paragraphs[0]['pos']['endOffset']);
    }

    public function testAnInlineRunSpanningJoinedLinesKeepsItsSpan(): void
    {
        $codes = $this->collect($this->ast("a `b\nc` d\n"), 'code');

        $this->assertCount(1, $codes);
        $this->assertSame("b\nc", $codes[0]['value']);
        $this->assertSame(2, $codes[0]['pos']['startOffset']);
        $this->assertSame(7, $codes[0]['pos']['endOffset']);
    }
}
