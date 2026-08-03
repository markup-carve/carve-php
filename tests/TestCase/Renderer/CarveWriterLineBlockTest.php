<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\LineBlock;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

/**
 * The canonical writer must reproduce a line block as a line block.
 *
 * It used to emit a bare `:::` fence and tag the node with a `line-block`
 * class, so a formatted line block re-parsed as an ordinary div. The rendered
 * HTML matched, which is why nothing caught it - but the node type changed
 * across a format round trip, so `parse(fmt(x)) == parse(x)` did not hold and a
 * profile denying `line_block` stopped matching after `fmt`.
 */
class CarveWriterLineBlockTest extends TestCase
{
    private function firstBlockType(string $source): string
    {
        $children = (new CarveConverter())->parse($source)->getChildren();

        return $children === [] ? '' : $children[0]->getType();
    }

    public function testTheWriterEmitsTheLineBlockOpener(): void
    {
        $formatted = trim(CarveConverter::carve()->convert("::: |\nRoses are red,\n  Violets are blue.\n:::"));

        $this->assertStringStartsWith('::: |', $formatted);
        $this->assertStringNotContainsString('line-block', $formatted);
    }

    public function testAFormattedLineBlockIsStillALineBlock(): void
    {
        $source = "::: |\nRoses are red,\n  Violets are blue.\n:::";

        $this->assertSame('line_block', $this->firstBlockType($source));
        $this->assertSame('line_block', $this->firstBlockType(CarveConverter::carve()->convert($source)));
    }

    public function testTheNodeSurvivesAsALineBlockInstance(): void
    {
        $formatted = CarveConverter::carve()->convert("::: |\na\nb\n:::");

        $this->assertInstanceOf(LineBlock::class, (new CarveConverter())->parse($formatted)->getChildren()[0]);
    }

    public function testFormattingPreservesTheRenderedHtml(): void
    {
        // The container already makes every newline a hard break, so the writer
        // must not also emit the explicit backslash - that doubled the breaks.
        $source = "::: |\nRoses are red,\n  Violets are blue.\n:::";
        $converter = new CarveConverter();

        $this->assertSame(
            $converter->convert($source),
            $converter->convert(CarveConverter::carve()->convert($source)),
        );
    }

    public function testFormattingIsIdempotent(): void
    {
        $once = CarveConverter::carve()->convert("::: |\na\n  b\n:::");

        $this->assertSame(trim($once), trim(CarveConverter::carve()->convert($once)));
    }

    public function testAMultiStanzaLineBlockRoundTrips(): void
    {
        $source = "::: |\nfirst line\nsecond line\n\nnew stanza\n:::";
        $converter = new CarveConverter();

        $this->assertSame('line_block', $this->firstBlockType(CarveConverter::carve()->convert($source)));
        $this->assertSame(
            $converter->convert($source),
            $converter->convert(CarveConverter::carve()->convert($source)),
        );
    }

    public function testAnOrdinaryDivIsStillADiv(): void
    {
        $formatted = CarveConverter::carve()->convert("::: note\nbody\n:::");

        $this->assertSame('div', $this->firstBlockType($formatted));
    }

    /**
     * The writer emits a medial gap as the plain spaces it was authored with
     * (PART 9 §23, carve#487).
     *
     * This has to be checked on a COALESCED tree, which is what a JSON round
     * trip produces (PART 12 §1a) and what any consumer building a document
     * programmatically produces. Straight off the parser the gap is a text node
     * of its own, so it starts at offset 0 and the old leading-run-only rule
     * matched it by accident - the writer looked correct while the rule it
     * implemented was wrong. Coalesced, the run sits mid-node, falls through to
     * normalize(), and the line comes back as `Two roads\ \ \ \ diverged`: the
     * same HTML, a different document. That is how the corpus round trip found
     * it, and a test off the parser alone could never fail.
     */
    private function writeCoalesced(string $source): string
    {
        $codec = new AstCodec();
        $decoded = $codec->decode($codec->encode((new CarveConverter())->parse($source)));

        return (new CarveRenderer())->render($decoded);
    }

    public function testAGappedLineIsWrittenBackWithPlainSpaces(): void
    {
        $source = "::: |\nTwo roads    diverged in a yellow wood,\nAnd looked   down one as far as I could\n:::\n";

        $this->assertSame($source, $this->writeCoalesced($source));
    }

    public function testATrailingGapIsWrittenBackWithPlainSpaces(): void
    {
        $source = "::: |\nword   \nnext\n:::\n";

        $this->assertSame($source, $this->writeCoalesced($source));
    }

    public function testAnIndentAndAGapOnTheSameLineBothSurvive(): void
    {
        $source = "::: |\n  indented    gapped\n:::\n";

        $this->assertSame($source, $this->writeCoalesced($source));
    }

    /**
     * A LONE inner sentinel can only have come from an escaped space - a single
     * authored space is collapsible and never reaches the sentinel - so it
     * still writes back as `\ `. This is the boundary that keeps the wider rule
     * from swallowing the escape.
     */
    public function testALoneEscapedSpaceStillWritesBackAsAnEscape(): void
    {
        $this->assertStringContainsString('a\\ b', $this->writeCoalesced("::: |\na\\ b\n:::\n"));
    }
}
