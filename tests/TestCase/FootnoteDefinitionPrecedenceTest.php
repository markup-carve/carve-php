<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * `[^…]:` is a footnote definition, not a reference definition.
 *
 * PART 4 states the rule while explaining the `@` reservation: "A leading `@` is
 * reserved: `[@key]: …` is never a reference definition … This parallels the
 * `[^...]:` footnote-definition PRECEDENCE".
 *
 * This engine implemented the `@` half and not the `^` half, so `[^a]: u`
 * registered as BOTH a footnote definition and a link reference, and
 * `[t][^a]` resolved to a link. carve-rs and carve-js leave it literal.
 *
 * Only a LEADING `^` is the footnote marker - a caret inside a label is an
 * ordinary character.
 */
class FootnoteDefinitionPrecedenceTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    private function squash(string $html): string
    {
        return trim((string)preg_replace('/\s+/', ' ', $html));
    }

    public function testAFootnoteLabelIsNotAlsoALinkReference(): void
    {
        $this->assertSame(
            '<p>see [t][^a].</p>',
            $this->squash($this->converter->convert("see [t][^a].\n\n[^a]: u\n")),
        );
    }

    public function testTheFootnoteItselfStillWorks(): void
    {
        $html = $this->converter->convert("see [^a].\n\n[^a]: u\n");

        $this->assertStringContainsString('doc-noteref', $html);
    }

    public function testACitationLabelIsStillReserved(): void
    {
        // The half this engine already had. The reference stays literal AND the
        // definition line stays on the page - where core renders the `@a` as a
        // mention, since the citation extension is not enabled here. Pinned as
        // MEASURED: all three engines agree on this exact output.
        $this->assertSame(
            '<p>see [t][@a].</p> <p>[<span class="mention"><strong>@a</strong></span>]: u</p>',
            $this->squash($this->converter->convert("see [t][@a].\n\n[@a]: u\n")),
        );
    }

    public function testAnOrdinaryLabelStillResolves(): void
    {
        $this->assertSame(
            '<p>see <a href="u">t</a>.</p>',
            $this->squash($this->converter->convert("see [t][a].\n\n[a]: u\n")),
        );
    }

    public function testACaretInsideALabelIsOrdinary(): void
    {
        // Only a LEADING caret marks a footnote.
        $this->assertSame(
            '<p>see <a href="u">t</a>.</p>',
            $this->squash($this->converter->convert("see [t][a^b].\n\n[a^b]: u\n")),
        );
    }

    /**
     * `[^]:` is NOT a footnote definition, so it is a reference definition.
     *
     * `footnote_label` is one-or-more characters, so an empty label never forms
     * a footnote definition; `reference_label` admits `^`, being neither `]`
     * nor `@`. So the line is a reference definition and renders nothing.
     *
     * Excluding every `[^` from the reference-definition pattern - rather than
     * excluding a VALID footnote definition - left this line as paragraph text,
     * where carve-js and carve-rs both render nothing.
     */
    public function testEmptyFootnoteLabelIsAReferenceDefinition(): void
    {
        $html = CarveConverter::create()->convert("[^]: http://x.de\n\ny\n");

        $this->assertStringNotContainsString('[^]', $html);
        $this->assertSame('<p>y</p>', trim($html));
    }

    public function testANonEmptyFootnoteLabelStillWins(): void
    {
        $html = CarveConverter::create()->convert("see [^a]\n\n[^a]: note\n");

        $this->assertStringContainsString('doc-noteref', $html);
        $this->assertStringContainsString('note', $html);
    }
}
