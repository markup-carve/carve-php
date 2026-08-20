<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * An UNRESOLVED footnote reference (`[^label]` with no matching `[^label]:`
 * definition) stays literal `[^label]` source text and is NOT a host for a
 * following attribute block. carve-php used to misread `[^a]{.ref}` as a
 * generic bracketed inline span, emitting `<span class="ref">^a</span>` -- the
 * outlier versus carve-js, carve-rs, and the executable-spec oracle, which all
 * keep the reference literal and drop the orphan attribute.
 *
 * A RESOLVED reference (with a definition) is unaffected: it still renders the
 * superscript noteref link and a trailing attribute block still attaches to the
 * `<a>`. A legitimate bracketed span whose text is not a footnote marker also
 * still forms a `<span>`.
 *
 * Canonical output verified against carve-js and carve-rs (built from main).
 */
class UnresolvedFootnoteRefAttrTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    /**
     * Case 1: unresolved reference + attribute block -> literal, attribute dropped.
     */
    public function testUnresolvedRefWithAttrStaysLiteralAndDropsAttr(): void
    {
        $result = $this->converter->convert('Text[^a]{.ref}.');

        $this->assertSame("<p>Text[^a].</p>\n", $result);
    }

    /**
     * Case 2: resolved reference (no attr) renders unchanged.
     */
    public function testResolvedRefRendersFootnoteUnchanged(): void
    {
        $result = $this->converter->convert("Text[^a].\n\n[^a]: note.");

        $expected = "<p>Text<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a>.</p>\n"
            . "<section role=\"doc-endnotes\">\n"
            . "  <hr>\n"
            . "  <ol>\n"
            . "    <li id=\"fn1\">\n"
            . "      <p>note.<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n"
            . "    </li>\n"
            . "  </ol>\n"
            . "</section>\n";

        $this->assertSame($expected, $result);
    }

    /**
     * Case 3: a legitimate bracketed span (not a footnote marker) still forms a span.
     */
    public function testLegitimateBracketSpanStillForms(): void
    {
        $result = $this->converter->convert('A [span]{.c} here.');

        $this->assertSame("<p>A <span class=\"c\">span</span> here.</p>\n", $result);
    }

    /**
     * Case 4: resolved reference + trailing attr -> attribute attaches to the noteref.
     */
    public function testResolvedRefWithAttrAttachesToNoteref(): void
    {
        $result = $this->converter->convert("Text[^a]{.ref}.\n\n[^a]: note.");

        $expected = "<p>Text<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\" class=\"ref\"><sup>1</sup></a>.</p>\n"
            . "<section role=\"doc-endnotes\">\n"
            . "  <hr>\n"
            . "  <ol>\n"
            . "    <li id=\"fn1\">\n"
            . "      <p>note.<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n"
            . "    </li>\n"
            . "  </ol>\n"
            . "</section>\n";

        $this->assertSame($expected, $result);
    }

    // ---- boundary cases (verified against carve-js / carve-rs) ----

    /**
     * An invalid attribute payload after an unresolved ref is not an attribute
     * block: it stays literal alongside the literal reference.
     */
    public function testUnresolvedRefKeepsInvalidBlockLiteral(): void
    {
        $result = $this->converter->convert('Text[^a]{???}.');

        $this->assertSame("<p>Text[^a]{???}.</p>\n", $result);
    }

    /**
     * An empty attribute block does not attach and is not consumed: both the
     * literal reference and the literal braces survive.
     */
    public function testUnresolvedRefKeepsEmptyBlockLiteral(): void
    {
        $result = $this->converter->convert('Text[^a]{}.');

        $this->assertSame("<p>Text[^a]{}.</p>\n", $result);
    }

    /**
     * Consecutive valid attribute blocks are both consumed and dropped.
     */
    public function testUnresolvedRefDropsConsecutiveAttrBlocks(): void
    {
        $result = $this->converter->convert('Text[^a]{.ref}{.foo}.');

        $this->assertSame("<p>Text[^a].</p>\n", $result);
    }

    /**
     * A bare unresolved reference (no attribute) stays literal.
     */
    public function testBareUnresolvedRefStaysLiteral(): void
    {
        $result = $this->converter->convert('Text[^a] more.');

        $this->assertSame("<p>Text[^a] more.</p>\n", $result);
    }
}
