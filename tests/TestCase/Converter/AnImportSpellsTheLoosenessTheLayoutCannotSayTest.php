<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §17 L7 on the IMPORT path (markup-carve/carve-php#1648).
 *
 * A blank line between items is Carve's spelling of looseness, and this writer
 * emits one between every pair - so a multi-item loose list already says it. A
 * ONE-ITEM list has no "between items" for that blank line to stand in, which is
 * exactly the shape L7 exists for, and `HtmlToCarve` never learned it: it writes
 * source straight from the DOM rather than building a tree and running
 * `CarveRenderer`, so the consumed `loose` boolean passed it by.
 *
 * A document with a single footnote imports as exactly one item, so this is the
 * common case rather than a corner. The derived endnotes section came back with
 * its `<p>` gone.
 *
 * ONE DECISION PROCEDURE, TWO WRITERS. The clause is not a shape test: write the
 * body without the key, read it back, and emit the key exactly where the
 * looseness did not survive. `CarveRenderer::looseKeyIsNeededForBody()` is that
 * procedure and both writers call it, because a second reading of §17's
 * looseness rules here would answer differently the day any of them moves.
 */
class AnImportSpellsTheLoosenessTheLayoutCannotSayTest extends TestCase
{
    private function import(string $html): string
    {
        return (new HtmlToCarve())->convert($html);
    }

    /**
     * THE POINT OF THE CLAUSE, stated as a round trip rather than as a string.
     *
     * A loose list that writes no key re-reads TIGHT, and the paragraph the
     * imported tree recorded is gone. This is what the key buys, and it is what
     * a test asserting only the written source would not notice if the spelling
     * were right and the parse still disagreed.
     */
    public function testTheParagraphSurvivesTheRoundTrip(): void
    {
        $html = '<section role="doc-endnotes" aria-label="Footnotes">'
            . '<hr><ol><li><p>Note text.</p></li></ol></section>';

        $back = CarveConverter::create()->convert($this->import($html));

        $this->assertStringContainsString('<li><p>Note text.</p></li>', $back);
    }

    /**
     * The shared fixture's exact source, which markup-carve/carve rewrote to
     * this in commit `d2bd801b` and which carve-js has written since its own
     * L7 change.
     */
    public function testTheDerivedEndnotesSectionIsWrittenWithTheKey(): void
    {
        $html = '<section role="doc-endnotes" aria-label="Footnotes">'
            . '<hr><ol><li><p>Note text.</p></li></ol></section>';

        $this->assertSame("---\n\n{loose}\n1. Note text.\n", $this->import($html));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function looseKeyProvider(): array
    {
        return [
            // ONE ITEM, LOOSE. No blank line can stand between items, so the key
            // is the only spelling left.
            'a one-item list whose item holds a paragraph' => ['<ul><li><p>a</p></li></ul>', true],
            'a one-item ordered list holding a paragraph' => ['<ol><li><p>a</p></li></ol>', true],
            // ONE ITEM, TIGHT. Nothing to spell.
            'a one-item list holding bare text' => ['<ul><li>a</li></ul>', false],
            // TWO ITEMS, LOOSE. The blank line between them already says it, so
            // a key here would say twice what the layout says once.
            'a two-item loose list' => ['<ul><li><p>a</p></li><li><p>b</p></li></ul>', false],
            'a two-item tight list' => ['<ul><li>a</li><li>b</li></ul>', false],
            // ONE ITEM HOLDING TWO BLOCKS. The item's own blank line makes it
            // re-read loose, so the key is redundant - this is the shape the
            // re-parse half of the procedure exists to answer, and a rule that
            // only counted items would wrongly write the key here.
            'a one-item list whose item holds two paragraphs' => ['<ul><li><p>a</p><p>b</p></li></ul>', false],
        ];
    }

    #[DataProvider('looseKeyProvider')]
    public function testTheKeyIsWrittenOnlyWhereTheLayoutCannotSayIt(string $html, bool $expected): void
    {
        $written = $this->import($html);

        $this->assertSame(
            $expected,
            str_contains($written, '{loose}'),
            json_encode($written),
        );
    }

    /**
     * EVERY SHAPE ABOVE RE-READS AS WHAT IT WAS, key or no key. That is the
     * clause's actual claim, and it is what makes writing the key in one place
     * and withholding it in another correct rather than arbitrary.
     *
     * @param string $html
     * @param bool $expected the provider's key expectation, not used here
     */
    #[DataProvider('looseKeyProvider')]
    public function testEveryShapeRoundTripsToItsOwnLooseness(string $html, bool $expected): void
    {
        $direct = CarveConverter::create()->convert(
            // The importer's own source, re-read.
            $this->import($html),
        );
        $wasLoose = str_contains($html, '<p>');

        $this->assertSame($wasLoose, str_contains($direct, '<li><p>'), json_encode($direct));
    }

    /**
     * The key goes FIRST in the slot order, which is where the canonical writer
     * puts it: measured while writing this, both engines' canonical writers
     * normalize `{#x loose}` to `{loose #x}`. Merged into the existing brace
     * list rather than written as a second one, which would attach to nothing.
     *
     * NOT asserted fmt-stable, and deliberately so. This writer emits a BLANK
     * LINE after a non-empty attribute line - `{#x}` then a blank then the
     * marker - which the canonical writer does not, so its output for an
     * attributed list has never been what `fmt` would write. That predates this
     * change and is preserved by it byte for byte; it is filed as
     * markup-carve/carve-php#1653 rather than folded in, because it moves every
     * attributed top-level list and this change moves only the key.
     */
    public function testTheKeyLeadsTheAttributeList(): void
    {
        $written = $this->import('<ul id="x"><li><p>a</p></li></ul>');

        $this->assertStringContainsString('{loose #x}', $written);
        // The key still has to survive a read-back, which is the half that
        // matters: the blank line above does not detach the attribute.
        $this->assertStringContainsString(
            '<li><p>a</p></li>',
            CarveConverter::create()->convert($written),
        );
    }
}
