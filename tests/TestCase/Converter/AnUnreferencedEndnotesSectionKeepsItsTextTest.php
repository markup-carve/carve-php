<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A footnote definition nothing references renders to the EMPTY STRING, so
 * rebuilding one out of an endnotes section that carries no reference deleted
 * the note's text from the document (markup-carve/carve-php#1582).
 *
 * `docs/html-import.md` states the rule: "A `<section role=\"doc-endnotes\">`
 * that nothing references imports as the `<hr>` and `<ol>` it is built from, not
 * as a footnote definition. An unreferenced definition renders to the empty
 * string, so rebuilding one there would delete the note's text from the document
 * while reporting nothing - a loss where the degraded form keeps every byte a
 * reader could see. A footnote whose `role=\"doc-noteref\"` reference IS present
 * rebuilds as a footnote, which is the shape a rendered document has."
 *
 * THE TEST IS THE RE-RENDER, not the emitted bytes. The bug was invisible to a
 * source assertion - `[^1]: n` looks like a footnote definition, and every
 * assertion about it agreed - and only reading the source back through this
 * engine's own converter showed the note was gone. Two of the tests in
 * `HtmlToCarveTest` pinned exactly that source for exactly that reason; both now
 * carry the reference their document was missing.
 */
class AnUnreferencedEndnotesSectionKeepsItsTextTest extends TestCase
{
    /**
     * Endnotes sections with NO `doc-noteref` reference anywhere, and the text
     * that has to survive the import.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function referencelessSectionProvider(): array
    {
        return [
            'the reported document' => [
                '<section role="doc-endnotes"><hr><ol><li id="fn1"><p>n</p></li></ol></section>',
                'n',
            ],
            'two notes' => [
                '<section role="doc-endnotes"><hr><ol><li id="fn1"><p>n</p></li>'
                . '<li id="fn2"><p>m</p></li></ol></section>',
                'm',
            ],
            'no separator' => [
                '<section role="doc-endnotes"><ol><li id="fn1"><p>n</p></li></ol></section>',
                'n',
            ],
            'a named section' => [
                '<section role="doc-endnotes" aria-label="Footnotes"><hr><ol><li id="fn1"><p>n</p></li></ol></section>',
                'n',
            ],
            'a two-block note' => [
                '<section role="doc-endnotes"><hr><ol><li id="fn1"><p>n</p><p>o</p></li></ol></section>',
                'o',
            ],
            // The WORSE half of the same defect, and the one no diagnostic
            // mentioned at all: with no `fn`-shaped id the item matched no
            // label, so the section returned the empty string and the note left
            // without even the `id` report the case above produced.
            'a note with no id' => [
                '<section role="doc-endnotes"><hr><ol><li><p>n</p></li></ol></section>',
                'n',
            ],
        ];
    }

    #[DataProvider('referencelessSectionProvider')]
    public function testAReferencelessSectionKeepsItsTextOnTheWayBackOut(string $html, string $text): void
    {
        $imported = (new HtmlToCarve())->convert($html);
        $rendered = (new CarveConverter())->convert($imported);

        $this->assertNotSame('', trim($rendered), 'the imported source rendered to nothing');
        $this->assertStringContainsString($text, $rendered);
        $this->assertStringNotContainsString('[^', $imported, 'a reference-less section is not a footnote definition');
    }

    /**
     * The degraded form, spelled out: the `<hr>` and the `<ol>` the section is
     * built from, which is what carve-js and carve-rs write.
     */
    public function testTheDegradedFormIsTheSeparatorAndTheList(): void
    {
        $imported = (new HtmlToCarve())->convert(
            '<section role="doc-endnotes"><hr><ol><li id="fn1"><p>n</p></li></ol></section>',
        );

        $this->assertStringStartsWith("---\n", $imported);
        $this->assertStringContainsString('1. n', $imported);
    }

    /**
     * THE REFERENCED CASE MUST NOT MOVE. It is the shape a rendered document
     * has, and its round trip is exact.
     */
    public function testAReferencedNoteStillRebuildsAsAFootnote(): void
    {
        $html = '<p>a<sup><a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></sup></p>'
            . '<section role="doc-endnotes" aria-label="Footnotes"><hr><ol><li id="fn1">'
            . '<p>n<a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>'
            . '</li></ol></section>';
        $result = (new HtmlToCarve())->convertWithReport($html);

        $this->assertStringContainsString("[^1]: n\n", $result->value);
        $this->assertSame([], $result->diagnostics);
        $this->assertSame($html . "\n", $this->normalize((new CarveConverter())->convert($result->value)));
    }

    /**
     * A round-trip label rather than an `fn`-shaped id: the reference names
     * `#fn1` and the item carries `data-djot-footnote-label`, so the two have to
     * meet on the label as well as on the id.
     */
    public function testAReferenceMatchesARoundTripLabelToo(): void
    {
        $imported = (new HtmlToCarve())->convert(
            '<p>a<a href="#fn1" role="doc-noteref">1</a></p>'
            . '<section role="doc-endnotes"><hr><ol><li data-djot-footnote-label="1"><p>n</p></li></ol></section>',
        );

        $this->assertStringContainsString("[^1]: n\n", $imported);
    }

    /**
     * A SECTION IS NOT ALL OR NOTHING. The referenced note becomes a footnote
     * and leaves; every other item is still a list item on the page, which is
     * what carve-js does with the same document.
     */
    public function testAPartlyReferencedSectionKeepsTheNotesNothingReferences(): void
    {
        $imported = (new HtmlToCarve())->convert(
            '<p>a<sup><a id="fnref1" href="#fn1" role="doc-noteref">1</a></sup></p>'
            . '<section role="doc-endnotes"><hr><ol><li id="fn1"><p>n</p></li>'
            . '<li id="fn2"><p>m</p></li></ol></section>',
        );
        $rendered = (new CarveConverter())->convert($imported);

        $this->assertStringContainsString("[^1]: n\n", $imported);
        $this->assertStringContainsString('m', $rendered);
        $this->assertStringNotContainsString('[^2]', $imported);
    }

    /**
     * An INLINE footnote's list item is a copy of content the reference site
     * already carries, so it is consumed rather than left behind - leaving it
     * would print the note twice.
     */
    public function testAnInlineFootnoteSectionIsStillConsumedWhole(): void
    {
        $html = (new CarveConverter())->convert("a^[note]\n");
        $imported = (new HtmlToCarve())->convert($html);

        $this->assertSame(1, substr_count($imported, 'note'), $imported);
    }

    /**
     * NO LIST AT ALL is the same silent deletion one shape over: the section
     * held no `<ol>`, so the old path returned the empty string and everything
     * inside it left with no diagnostic. It is an ordinary section now.
     */
    public function testASectionWithNoListKeepsItsContent(): void
    {
        $imported = (new HtmlToCarve())->convert('<section role="doc-endnotes"><p>x</p></section>');

        $this->assertSame("x\n", $imported);
    }

    /**
     * An INLINE note beside a regular one, in the round-trip shape that marks
     * the inline item as one: the inline item is consumed with the rest, so the
     * section still resolves whole rather than leaving a copy of the note behind
     * as a list.
     */
    public function testAnInlineNoteBesideARegularOneIsConsumedWithIt(): void
    {
        $html = (new CarveConverter(roundTripMode: true))->convert("a^[inline] b[^1]\n\n[^1]: note\n");
        $imported = (new HtmlToCarve())->convert($html);

        $this->assertStringNotContainsString('1.', $imported);
        $this->assertStringContainsString("[^1]: note\n", $imported);
    }

    private function normalize(string $html): string
    {
        return (string)preg_replace('/\n\s*/', '', $html) . "\n";
    }
}
