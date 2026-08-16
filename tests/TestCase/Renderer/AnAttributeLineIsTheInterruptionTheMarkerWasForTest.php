<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

/**
 * The writer stops emitting a `+` where a block-attributes line already
 * interrupts (markup-carve/carve#1275).
 *
 * `TheWriterKeepsAContinuationMarkerTest` states the rule this narrows: a
 * paragraph indented under an item is a LAZY CONTINUATION of the paragraph
 * above it, so the item comes back holding one block where the author wrote
 * two, and the marker is what keeps them apart.
 *
 * The premise stops holding the moment the attached block carries attributes
 * the writer has to put on a line of their own ahead of it. `block_attributes`
 * is one of PART 9 §10's INVISIBLE CONSTRUCTS: it INTERRUPTS an open paragraph.
 * So the fold cannot happen, the item comes back holding two blocks either way,
 * and the marker adds a construct the document did not have.
 *
 * This is not a choice between two spellings. Writing the marker made this
 * engine and carve-js disagree with carve-rs on
 * `322-an-attribute-block-reaches-the-nested-list-it-precedes-3`, whose corpus
 * source is the indented form - the one cross-engine difference left in the
 * spec's `carve` target after everything else landed. It is also the form the
 * other fourteen documents of that family are already written in, by all three
 * engines.
 *
 * The attributed IMAGE is the case that keeps the marker and is a control here:
 * its attributes are written INLINE (`![a](i.png){.c}`), no attribute line is
 * produced, nothing interrupts, and it still folds.
 */
class AnAttributeLineIsTheInterruptionTheMarkerWasForTest extends TestCase
{
    /**
     * @var string
     */
    protected const ATTRIBUTED_PARAGRAPH = "- a\n  {.x}\n  para\n";

    protected function fmt(string $source): string
    {
        return (new CarveConverter(renderer: new CarveRenderer()))->convert($source);
    }

    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    protected function roundTrips(string $source): bool
    {
        return $this->html($this->fmt($source)) === $this->html($source);
    }

    public function testWritesAnAttributedParagraphIndentedWithNoMarker(): void
    {
        $this->assertSame(static::ATTRIBUTED_PARAGRAPH, $this->fmt(static::ATTRIBUTED_PARAGRAPH));
    }

    public function testStillRoundTripsWhichIsWhatTheMarkerWasProtecting(): void
    {
        $this->assertTrue($this->roundTrips(static::ATTRIBUTED_PARAGRAPH));
    }

    public function testIsIdempotent(): void
    {
        $once = $this->fmt(static::ATTRIBUTED_PARAGRAPH);

        $this->assertSame($once, $this->fmt($once));
    }

    public function testReadsTheMarkerFormBackToTheSameDocumentItWritesWithoutOne(): void
    {
        // Both spellings are legal source. The writer picks one; the parser has
        // to keep answering the same for the other, or dropping the marker
        // would be a silent change of document rather than of spelling.
        $this->assertSame(
            $this->html(static::ATTRIBUTED_PARAGRAPH),
            $this->html("- a\n+\n{.x}\npara\n"),
        );
    }

    public function testDoesTheSameForAnAttributedFigure(): void
    {
        // The other kind whose canonical source is a bare inline run and whose
        // attributes go on a line of their own.
        $source = "- x\n  {.c}\n  ![a](i.png)\n  ^ cap\n";

        $this->assertSame($source, $this->fmt($source));
        $this->assertTrue($this->roundTrips($source));
    }

    public function testControlKeepsTheMarkerWhereNoAttributeLineIsWritten(): void
    {
        // The rule this narrows, unchanged. A bare paragraph folds, so the
        // marker stays.
        $source = "- a\n+\npara\n";

        $this->assertStringContainsString("\n+\n", $this->fmt($source));
        $this->assertTrue($this->roundTrips($source));
    }

    public function testControlKeepsTheMarkerForAnAttributedImageWhoseAttributesAreInline(): void
    {
        $source = "- x\n+\n![a](i.png){.c}\n";

        $this->assertStringContainsString("\n+\n", $this->fmt($source));
        $this->assertTrue($this->roundTrips($source));
    }

    public function testControlABracedParagraphIsEscapedNotMistakenForAttributes(): void
    {
        // The writer escapes a leading brace precisely so it cannot come back
        // as attributes - which is why reading the written first line is enough
        // to tell an attribute line from paragraph text.
        $source = "- x\n+\n\\{.c\\}\n";

        $this->assertStringContainsString("\n+\n", $this->fmt($source));
        $this->assertTrue($this->roundTrips($source));
    }
}
