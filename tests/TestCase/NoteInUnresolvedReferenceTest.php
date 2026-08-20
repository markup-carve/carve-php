<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * PART 9R R2: a footnote inside an unresolved reference is not a reference.
 *
 * R1 degrades an unresolved reference to its literal SOURCE, so the link text
 * that was rendered for it is discarded rather than written into the document.
 * A `[^label]` use or an `^[content]` note sitting in that text therefore
 * references nothing: it draws no number, a definition it was the only use of
 * stays unreferenced and is dropped, and no endnotes section is written on its
 * account (markup-carve/carve#1198).
 *
 * This engine already decides it that way, because `renderLink()` and
 * `renderImage()` return the raw source BEFORE rendering their children, so a
 * note in that subtree is never rendered and never numbered. The corpus pins
 * four documents; the rule reaches every construct that degrades the same way,
 * and the shapes below are the ones a fix keyed on the wrong condition - on
 * brackets, or on the full-reference spelling alone - would take out.
 *
 * The last two cases are the controls: text that DID reach the reader counts,
 * whether the reference resolved (PART 9 §16) or there was no reference at all
 * (PART 9 §14).
 */
class NoteInUnresolvedReferenceTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    /**
     * The endnotes section a discarded note must never produce.
     *
     * Asserted as an ABSENCE in its own right: an exact-output assertion alone
     * passes for a document that renders nothing at all, and the defect this
     * pins is a section appearing where none belongs.
     */
    private function assertNoEndnotes(string $html): void
    {
        $this->assertStringNotContainsString('doc-endnotes', $html);
        $this->assertStringNotContainsString('doc-backlink', $html);
        $this->assertStringNotContainsString('doc-noteref', $html);
        $this->assertStringNotContainsString('fnref', $html);
    }

    /**
     * A full reference with no definition: the corpus shape.
     */
    public function testFullReferenceDiscardsItsNote(): void
    {
        $html = $this->converter->convert("a [t[^1]][nope] b\n\n[^1]: n\n");

        $this->assertSame("<p>a [t[^1]][nope] b</p>\n", $html);
        $this->assertNoEndnotes($html);
    }

    /**
     * A COLLAPSED reference with no definition degrades the same way.
     */
    public function testCollapsedReferenceDiscardsItsNote(): void
    {
        $html = $this->converter->convert("a [t[^1]][] b\n\n[^1]: n\n");

        $this->assertSame("<p>a [t[^1]][] b</p>\n", $html);
        $this->assertNoEndnotes($html);
    }

    /**
     * The IMAGE spellings never reach the rule, and the reason is worth
     * pinning: an image's alt is FLAT TEXT, resolved or not, so the `[^1]` in
     * it is never a note node and there is nothing to number in the first
     * place. Held as a pair with the resolved image, so a parser change that
     * starts building nodes for alt text shows up here rather than as an
     * endnote nobody expected.
     *
     * Stated plainly because it is the one shape in this file that a single
     * mutation to the unresolved branch cannot break - it is decided in the
     * parser, not in the writer.
     */
    public function testAnImageReferenceHoldsNoNoteToDiscard(): void
    {
        $unresolved = $this->converter->convert("a ![t[^1]][nope] b\n\n[^1]: n\n");
        $this->assertSame("<p>a ![t[^1]][nope] b</p>\n", $unresolved);
        $this->assertNoEndnotes($unresolved);

        $collapsed = (new CarveConverter())->convert("a ![t[^1]][] b\n\n[^1]: n\n");
        $this->assertSame("<p>a ![t[^1]][] b</p>\n", $collapsed);
        $this->assertNoEndnotes($collapsed);

        // The resolved counterpart: the alt keeps the literal `[^1]` and the
        // document still writes no endnote, which is what makes the two above
        // a property of alt text rather than of the degradation.
        $resolved = (new CarveConverter())->convert("a ![t[^1]][r] b\n\n[r]: /u\n\n[^1]: n\n");
        $this->assertSame("<p>a <img src=\"/u\" alt=\"t[^1]\"> b</p>\n", $resolved);
        $this->assertNoEndnotes($resolved);
    }

    /**
     * An INLINE note is discarded on the same terms as a labelled use: it has
     * no definition to leave behind, so the whole endnote would be invented.
     */
    public function testInlineNoteInAnUnresolvedReferenceIsNotPlaced(): void
    {
        $html = $this->converter->convert("a [t^[n]][nope] b\n");

        $this->assertSame("<p>a [t^[n]][nope] b</p>\n", $html);
        $this->assertNoEndnotes($html);
    }

    /**
     * The inline note in the COLLAPSED spelling of an unresolved reference.
     */
    public function testInlineNoteInACollapsedUnresolvedReferenceIsNotPlaced(): void
    {
        $html = $this->converter->convert("a [t^[n]][] b\n");

        $this->assertSame("<p>a [t^[n]][] b</p>\n", $html);
        $this->assertNoEndnotes($html);
    }

    /**
     * The numbering, where a reader can see it: the surviving noteref is the
     * FIRST reference, not a repeat of one the document does not contain.
     */
    public function testASurvivingNoterefIsNumberedFirst(): void
    {
        $html = $this->converter->convert("a [t[^1]][nope] b [^1] c\n\n[^1]: n\n");

        $this->assertSame(
            "<p>a [t[^1]][nope] b <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> c</p>\n"
            . "<section role=\"doc-endnotes\">\n"
            . "  <hr>\n"
            . "  <ol>\n"
            . "    <li id=\"fn1\">\n"
            . "      <p>n<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n"
            . "    </li>\n"
            . "  </ol>\n"
            . "</section>\n",
            $html,
        );
        $this->assertStringNotContainsString('fnref1-2', $html);
    }

    /**
     * TWO discarded uses before the live one: the counter is not advanced by
     * either, and the single backlink carries no repeat superscript.
     */
    public function testTwoDiscardedUsesBeforeALiveOne(): void
    {
        $html = $this->converter->convert("a [t[^1]][x] [u[^1]][y] b [^1] c\n\n[^1]: n\n");

        $this->assertStringContainsString('<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a>', $html);
        $this->assertStringNotContainsString('fnref1-2', $html);
        $this->assertStringNotContainsString('fnref1-3', $html);
        $this->assertSame(1, substr_count($html, 'doc-backlink'));
    }

    /**
     * A discarded use of one label does not renumber ANOTHER label's live use:
     * the definition it was the only use of is dropped, and the endnotes hold
     * one entry numbered 1.
     */
    public function testADiscardedUseDoesNotRenumberAnotherLabel(): void
    {
        $html = $this->converter->convert("a [t[^1]][nope] b [^2] c\n\n[^1]: n1\n\n[^2]: n2\n");

        $this->assertStringContainsString('<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a>', $html);
        $this->assertStringContainsString('<p>n2<a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>', $html);
        // `n1` alone is a substring of `#fn1`, so the dropped body is named
        // with the paragraph that would have carried it.
        $this->assertStringNotContainsString('<p>n1', $html);
        $this->assertSame(1, substr_count($html, '<li id='));
    }

    /**
     * The unresolved reference AFTER a live use: the live one keeps `fnref1`
     * and the discarded one adds no second backlink.
     */
    public function testALiveUseBeforeADiscardedOne(): void
    {
        $html = $this->converter->convert("a [^1] b [t[^1]][nope] c\n\n[^1]: n\n");

        $this->assertStringContainsString('<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a>', $html);
        $this->assertStringNotContainsString('fnref1-2', $html);
        $this->assertSame(1, substr_count($html, 'doc-backlink'));
    }

    /**
     * An unresolved reference NESTED in a resolved one: only the inner text is
     * discarded, so the note in it is not placed while the outer link stands.
     */
    public function testANestedUnresolvedReferenceDiscardsOnlyItsOwnNote(): void
    {
        $html = $this->converter->convert("a [t[z[^1]][no]][r] b\n\n[r]: /u\n\n[^1]: n\n");

        $this->assertSame("<p>a <a href=\"/u\">t[z[^1]][no]</a> b</p>\n", $html);
        $this->assertNoEndnotes($html);
    }

    /**
     * The other nesting: a RESOLVED reference inside an unresolved one. The
     * outer degradation discards the inner link's text with everything else.
     */
    public function testAResolvedReferenceInsideAnUnresolvedOneIsDiscardedWithIt(): void
    {
        $html = $this->converter->convert("a [t[z[^1]][r]][no] b\n\n[r]: /u\n\n[^1]: n\n");

        $this->assertSame("<p>a [t[z[^1]][r]][no] b</p>\n", $html);
        $this->assertNoEndnotes($html);
    }

    /**
     * The rule holds wherever the reference sits, not only in a paragraph.
     */
    public function testTheRuleHoldsInsideEveryContainer(): void
    {
        $cell = $this->converter->convert("| a [t[^1]][nope] |\n| --- |\n| b |\n\n[^1]: n\n");
        $this->assertStringContainsString('a [t[^1]][nope]', $cell);
        $this->assertNoEndnotes($cell);

        $heading = (new CarveConverter())->convert("# h [t[^1]][nope]\n\n[^1]: n\n");
        $this->assertStringContainsString('h [t[^1]][nope]', $heading);
        $this->assertNoEndnotes($heading);

        $quote = (new CarveConverter())->convert("> a [t[^1]][nope] b\n\n[^1]: n\n");
        $this->assertSame("<blockquote><p>a [t[^1]][nope] b</p></blockquote>\n", $quote);
        $this->assertNoEndnotes($quote);
    }

    /**
     * An unresolved reference inside a FOOTNOTE BODY: the body itself was
     * referenced, so it is written, and the note use inside the discarded text
     * still draws nothing. The endnotes hold one entry, not two.
     */
    public function testAnUnresolvedReferenceInsideAFootnoteBody(): void
    {
        $html = $this->converter->convert("a [^1] b\n\n[^1]: n [t[^2]][nope] m\n\n[^2]: q\n");

        $this->assertStringContainsString('n [t[^2]][nope] m', $html);
        $this->assertStringNotContainsString('<p>q', $html);
        $this->assertSame(1, substr_count($html, '<li id='));
        $this->assertSame(1, substr_count($html, 'doc-noteref'));
    }

    /**
     * A presentation target sees no shift either: the discarded use does not
     * move the marker a reader is shown. Pinned as a difference against the
     * SAME document without the unresolved reference, because that target
     * numbers by definition order rather than by reference order.
     */
    public function testThePlainTargetMarkerIsUnaffectedByADiscardedUse(): void
    {
        $plain = CarveConverter::plainText();
        $withReference = $plain->convert("a [t[^1]][nope] b [^2] c\n\n[^1]: n1\n\n[^2]: n2\n");
        $without = $plain->convert("a b [^2] c\n\n[^1]: n1\n\n[^2]: n2\n");

        $this->assertStringContainsString('b [2] c', $withReference);
        $this->assertStringContainsString('b [2] c', $without);
    }

    /**
     * CONTROL, PART 9 §16: a note in a reference that DOES resolve is an
     * ordinary reference, because the resolved link text is written.
     */
    public function testANoteInAResolvedReferenceCounts(): void
    {
        $html = $this->converter->convert("a [t[^1]][r] b\n\n[r]: /u\n\n[^1]: n\n");

        $this->assertStringContainsString(
            '<a href="/u">t<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></a>',
            $html,
        );
        $this->assertStringContainsString('doc-endnotes', $html);
    }

    /**
     * CONTROL, PART 9 §14: a bracketed run that never carried a tail is not a
     * reference at all, so its content is rendered and the note in it counts.
     * The case a fix keyed on brackets rather than on resolution would break.
     */
    public function testANoteInABracketedRunWithNoTailCounts(): void
    {
        $html = $this->converter->convert("a [t[^1]] b\n\n[^1]: n\n");

        $this->assertSame(
            "<p>a [t<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a>] b</p>\n"
            . "<section role=\"doc-endnotes\">\n"
            . "  <hr>\n"
            . "  <ol>\n"
            . "    <li id=\"fn1\">\n"
            . "      <p>n<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n"
            . "    </li>\n"
            . "  </ol>\n"
            . "</section>\n",
            $html,
        );
    }
}
