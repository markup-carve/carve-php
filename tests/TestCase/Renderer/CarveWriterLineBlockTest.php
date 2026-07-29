<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\LineBlock;
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
}
