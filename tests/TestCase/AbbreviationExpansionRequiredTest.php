<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * PART 5: `abbreviation_expansion = {character - newline}+` - ONE or more.
 *
 * `*[A]: ` with nothing after the separator is not a definition, so the line is
 * paragraph text. This engine consumed it, which deleted it from the document.
 *
 * It was the last definition kind where that was still true: a link reference
 * and a footnote definition with no content are already kept as text in all
 * three engines. carve-js already implements the production; carve-rs is
 * carve-rs#487 (carve-php#674).
 *
 * THE BOUNDARY MOVED. This class used to pin "a SECOND trailing space IS an
 * expansion, because a space is a character", which was the reading while the
 * separator was spelled `space` - exactly one, so everything after it was
 * content. markup-carve/carve#892 spells it `space+`, and MARKER REQUIRES
 * CONTENT then applies AFTER the run: a line of `whitespace` is blank (PART 1),
 * so `*[A]:` followed by spaces and nothing else is a paragraph however many
 * there are. The executable spec answers `<p>*[A]:</p>` to all of them.
 *
 * That is the trap the ruling names out loud - "a patch that implements the run
 * as 'eat spaces then take the rest' makes a spaces-only line define an empty
 * abbreviation" - so the rows below are the ones that catch it.
 */
class AbbreviationExpansionRequiredTest extends TestCase
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

    public function testAnEmptyExpansionIsNotADefinition(): void
    {
        $this->assertSame('<p>*[A]:</p>', $this->squash($this->converter->convert("*[A]: \n")));
    }

    public function testNothingIsSilentlyDropped(): void
    {
        // The sharp end: the line rendered NOTHING, so it vanished.
        $this->assertNotSame('', trim($this->converter->convert("*[A]: \n")));
    }

    public function testAWhitespaceOnlyTailIsNotAnExpansionHoweverLong(): void
    {
        // The run is MAXIMAL, so a second space is more separator rather than
        // the first character of the expansion - and what is left is a blank
        // line, which is not content. A tab after the run is content in general
        // (see below), but a tab that is ALL that follows leaves the line blank
        // too, so it goes the same way.
        foreach (["*[A]:  \n", "*[A]:   \n", "*[A]: \t\n", "*[A]: \t \n"] as $source) {
            $this->assertSame('<p>*[A]:</p>', $this->squash($this->converter->convert($source)), $source);
        }
    }

    /**
     * A tab AFTER the run is the expansion's first character, and it survives.
     *
     * The two markers answer differently one step downstream and the reason is
     * not in the separator: an `abbreviation_expansion` is a raw string, so the
     * tab reaches the `title`, while a footnote's body would read it as that
     * body's own indentation run. This is the one of the two with a single right
     * answer, and it is measured against the executable spec.
     */
    public function testATabAfterTheRunReachesTheTitle(): void
    {
        $html = $this->converter->convert("*[HTML]: \tHyper\n\nHTML\n");

        $this->assertStringContainsString("title=\"\tHyper\"", $html);
    }

    /**
     * A no-break space after the run is content too, and it stays a RAW BYTE.
     *
     * `&nbsp;` would be the same character to a browser and a different string
     * to the corpus, which is byte-exact. The text path serializes the entity
     * and the ATTRIBUTE path does not - the executable spec draws the line in
     * the same place, and `escapeHeadingId()` already did here.
     */
    public function testANoBreakSpaceAfterTheRunReachesTheTitleAsARawByte(): void
    {
        $html = $this->converter->convert("*[HTML]: \u{00A0}Hyper\n\nHTML\n");

        $this->assertStringContainsString("title=\"\u{00A0}Hyper\"", $html);
        $this->assertStringNotContainsString('title="&nbsp;', $html);
    }

    /**
     * The run is a run: two spaces before real content still define.
     *
     * Both productions said `space` while all four readers consumed a run, so
     * the grammar forbade a shape nothing rejected. That is a correction rather
     * than a widening (markup-carve/carve#892).
     */
    public function testARunOfSpacesBeforeRealContentStillDefines(): void
    {
        $html = $this->converter->convert("*[HTML]:  Hyper\n\nHTML\n");

        $this->assertStringContainsString('title="Hyper"', $html);
    }

    public function testARealDefinitionStillWorks(): void
    {
        $html = $this->converter->convert("*[HTML]: HyperText Markup Language\n\nHTML rules.\n");

        $this->assertStringContainsString('<abbr', $html);
        $this->assertStringContainsString('HyperText Markup Language', $html);
    }

    public function testNoSeparatorSpaceIsUnchanged(): void
    {
        $this->assertSame('<p>*[A]:</p>', $this->squash($this->converter->convert("*[A]:\n")));
    }
}
