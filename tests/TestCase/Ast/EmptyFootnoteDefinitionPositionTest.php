<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

/**
 * A footnote definition with an EMPTY body carries a position.
 *
 * A definition's extent was derived from its body, and `[^f]: {empty}` has no
 * body to derive one from - so `deriveContainerSpans` found no child to measure
 * and the node reached the wire with no `pos` at all. PART 12 §4 allows omitting
 * a position only for a node that CANNOT be placed; this one is written on a
 * line of its own, so the definition line is its extent, which is what the
 * reference publishes. markup-carve/carve#1023.
 *
 * The measurement that hides it is any document whose definitions all have
 * content: every one of those derives a span from its first block and looks
 * placed, so a probe over them reports a conformant engine.
 */
class EmptyFootnoteDefinitionPositionTest extends TestCase
{
    /**
     * Positions are OPT-IN in this engine (PART 12 §4 permits that), so the
     * probe has to ask for them - the same thing `bin/carve --json` does.
     * Without the flag every `pos` is absent, and a check that only compares
     * offsets it finds would pass against an engine that publishes none.
     *
     * @return array<string, mixed>
     */
    private function encode(string $source): array
    {
        $converter = new CarveConverter(parser: new BlockParser(false, false, false, true));

        return (new AstCodec())->encode($converter->parse($source));
    }

    /**
     * The source text a span points at, sliced in CODEPOINTS - the unit §4
     * states, and the unit the offsets are published in.
     *
     * @param string $source
     * @param array<string, mixed> $pos
     */
    private function sliceOf(string $source, array $pos): string
    {
        $codepoints = preg_split('//u', $source, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode('', array_slice(
            $codepoints,
            (int)$pos['startOffset'],
            (int)$pos['endOffset'] - (int)$pos['startOffset'],
        ));
    }

    /**
     * @param array<string, mixed> $ast
     *
     * @return array<int, array<string, mixed>>
     */
    private function footnotes(array $ast): array
    {
        $out = [];
        foreach ($ast['children'] as $child) {
            if (($child['type'] ?? '') === 'footnote') {
                $out[] = $child;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>
     */
    private function posOf(array $node): array
    {
        // PRESENT before compared. An absent `pos` is the defect itself, and a
        // test that read `$node['pos'] ?? []` and compared that to nothing would
        // pass against the engine this covers.
        $this->assertArrayHasKey('pos', $node, 'the footnote definition carries no position');

        return $node['pos'];
    }

    /**
     * The spec corpus document, verbatim:
     * `283-an-empty-footnote-body-is-written-with-the-empty-sentinel`.
     */
    public function testAnEmptyDefinitionSpansItsOwnLine(): void
    {
        $source = "See[^f]\n\n[^f]: {empty}\n";
        $footnotes = $this->footnotes($this->encode($source));

        $this->assertCount(1, $footnotes);
        $pos = $this->posOf($footnotes[0]);
        $this->assertSame('[^f]: {empty}', $this->sliceOf($source, $pos));
        $this->assertSame(3, $pos['startLine']);
        $this->assertSame(3, $pos['endLine']);
        $this->assertSame(1, $pos['startColumn']);
    }

    /**
     * A definition with content starts at its own opening marker and reaches
     * through its body.
     */
    public function testADefinitionWithABodyKeepsItsBodyDerivedExtent(): void
    {
        $source = "See[^f]\n\n[^f]: body\n";
        $footnotes = $this->footnotes($this->encode($source));

        $this->assertCount(1, $footnotes);
        $pos = $this->posOf($footnotes[0]);
        $this->assertSame('[^f]: body', $this->sliceOf($source, $pos));
    }

    /**
     * §4 puts a span's start at the markup that OPENS the construct, not at the
     * container prefix that carried the line. A definition inside a block quote
     * therefore starts at its own column, not at column 1.
     */
    public function testAnEmptyDefinitionInsideAContainerStartsAtItsOwnColumn(): void
    {
        $source = "> See[^f]\n>\n> [^f]: {empty}\n";
        $footnotes = $this->footnotes($this->encode($source));

        $this->assertCount(1, $footnotes);
        $pos = $this->posOf($footnotes[0]);
        $this->assertSame('[^f]: {empty}', $this->sliceOf($source, $pos));
        $this->assertSame(3, $pos['startColumn'], 'the span must skip the quote marker');
    }

    /**
     * The last line of the document, with no trailing newline.
     *
     * A line-length lookup that assumed a terminator would run past the end of
     * the source here, and the slice would come back short.
     */
    public function testAnEmptyDefinitionOnAnUnterminatedLastLineIsPlaced(): void
    {
        $source = "See[^f]\n\n[^f]: {empty}";
        $footnotes = $this->footnotes($this->encode($source));

        $this->assertCount(1, $footnotes);
        $this->assertSame('[^f]: {empty}', $this->sliceOf($source, $this->posOf($footnotes[0])));
    }

    /**
     * PART 12 §7: "Definitions appear in DOCUMENT ORDER by source position."
     *
     * The same gap in a second place. `orderCollectedDefinitions` sorts by the
     * published position, and an empty definition had none - so it sorted to the
     * end and was published BELOW a definition the author wrote after it.
     */
    public function testAnEmptyDefinitionWrittenFirstIsPublishedFirst(): void
    {
        $source = "See[^a][^b]\n\n[^a]: {empty}\n\n[^b]: x\n";
        $footnotes = $this->footnotes($this->encode($source));

        $this->assertCount(2, $footnotes);
        $this->assertSame(['a', 'b'], [$footnotes[0]['label'], $footnotes[1]['label']]);
        $this->assertLessThan(
            $this->posOf($footnotes[1])['startOffset'],
            $this->posOf($footnotes[0])['startOffset'],
            'published order must follow source position',
        );
    }

    /**
     * The AST-INGEST path builds a footnote separately from the parser, so a
     * parser-only fix leaves it publishing nothing. §6 makes
     * serialize(ingest(serialize(parse(x)))) equal to serialize(parse(x)).
     */
    public function testAnIngestedEmptyDefinitionKeepsItsPosition(): void
    {
        $source = "See[^f]\n\n[^f]: {empty}\n";
        $encoded = $this->encode($source);

        $reingested = (new AstCodec())->encode((new AstCodec())->decode($encoded));

        $footnotes = $this->footnotes($reingested);
        $this->assertCount(1, $footnotes);
        $this->assertSame(
            $this->posOf($this->footnotes($encoded)[0]),
            $this->posOf($footnotes[0]),
        );
    }
}
