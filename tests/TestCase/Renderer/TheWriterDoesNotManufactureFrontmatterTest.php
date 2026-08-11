<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Test\LegacyCarveConverter as CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * PART 11 section 1: `to_html(fmt(x)) == to_html(x)`.
 *
 * A frontmatter block is an opening fence AT BYTE 0 plus a bare `---` CLOSER
 * anywhere below it. So a `---`-shaped line at the head of the emitted document
 * is only a hazard when something later closes it, which makes the collision a
 * property of the WHOLE emitted text rather than of its first line.
 *
 * Two unrelated writer decisions can put a `---` there:
 *
 * - an authored `---` break can open the document and gain a closer from any
 *   later break (carve-php#1069 cause 1).
 * - a hoisted link or footnote definition is written after the body, promoting
 *   whatever stood second to byte 0 (cause 2). Nothing is respelled there, so
 *   fixing the first cause does not fix this one.
 *
 * And a third shape the ticket does not name: the promoted block can be a
 * PARAGRAPH whose first line is `---yaml`-shaped. No head-of-document
 * respelling repairs that one, because the paragraph's text is not the writer's
 * to change - it is saved by respelling the CLOSER instead.
 *
 * All three are decided by handing the FINISHED BYTES to the parser's own
 * frontmatter test, in one seam, which is what carve-js and carve-rs do.
 */
class TheWriterDoesNotManufactureFrontmatterTest extends TestCase
{
    protected function html(string $source): string
    {
        return CarveConverter::create()->convert($source);
    }

    protected function fmt(string $source): string
    {
        return CarveConverter::toCarve($source);
    }

    /**
     * `fmt` preserved the rendering, and is idempotent.
     */
    protected function assertRoundTrips(string $source): void
    {
        $once = $this->fmt($source);
        $this->assertSame($this->html($source), $this->html($once), 'to_html(fmt(x)) != to_html(x)');
        $this->assertSame($once, $this->fmt($once), 'fmt is not idempotent');
    }

    public function testALeadingBreakNormalizedToHyphensDoesNotSwallowTheDocument(): void
    {
        $source = "***\n\na\n\n---\n\nb\n";
        $this->assertRoundTrips($source);
        // The rendering is the point, not the spelling: two rules and two
        // paragraphs, where the canonical spelling rendered `<p>b</p>` alone.
        $out = $this->html($this->fmt($source));
        $this->assertSame(2, substr_count($out, '<hr>'));
        $this->assertStringContainsString('<p>a</p>', $out);
    }

    public function testTheMinimalPairOfBreaksSurvives(): void
    {
        $source = "***\n\n---\n";
        $this->assertRoundTrips($source);
        $this->assertSame(2, substr_count($this->html($this->fmt($source)), '<hr>'));
    }

    public function testAHoistedDefinitionDoesNotPromoteABreakToByteZero(): void
    {
        $source = "[a]: /u\n\n---\n\np\n\n---\n";
        $this->assertRoundTrips($source);
        $this->assertSame(2, substr_count($this->html($this->fmt($source)), '<hr>'));
    }

    /**
     * The shape neither this ticket nor carve-js#899 / carve-js#901 names: the
     * promoted block is a PARAGRAPH whose first line is `---yaml`-shaped. The
     * writer cannot respell the paragraph, so the document is saved by moving
     * the CLOSER - which is why the fallback is document-wide.
     */
    public function testAHoistedDefinitionDoesNotPromoteAFrontmatterShapedParagraph(): void
    {
        $source = "[^a]: n\n\n---yaml\nk: v\n---\n";
        $this->assertRoundTrips($source);
        $this->assertStringContainsString('<hr>', $this->html($this->fmt($source)));
    }

    /**
     * CONTROL. A leading break with nothing below it to close a block keeps the
     * authored marker. No mutation of the fallback moves this row.
     */
    public function testALeadingBreakWithNoCloserKeepsTheAuthoredSpelling(): void
    {
        $this->assertSame("***\n\np\n", $this->fmt("***\n\np\n"));
        $this->assertSame("***\n", $this->fmt("***\n"));
    }

    /**
     * CONTROL. A document that really carries frontmatter still writes it, and
     * a later break inside that document keeps its authored marker.
     */
    public function testRealFrontmatterIsUntouched(): void
    {
        $source = "---yaml\nk: v\n---\n\np\n\n***\n";
        $this->assertRoundTrips($source);
        $formatted = $this->fmt($source);
        $this->assertStringStartsWith("---yaml\n", $formatted);
        $this->assertStringContainsString("\n---\n", $formatted);
        $this->assertStringContainsString('***', $formatted);
    }

    /**
     * A break anywhere other than byte 0 keeps its authored marker when the
     * document also holds a later break, because nothing opens frontmatter.
     */
    public function testABreakBelowTheHeadKeepsTheAuthoredSpelling(): void
    {
        $formatted = $this->fmt("p\n\n***\n\nq\n\n___\n");
        $this->assertSame("p\n\n***\n\nq\n\n___\n", $formatted);
        $this->assertRoundTrips("p\n\n***\n\nq\n\n___\n");
    }

    /**
     * A document still misread with `***` keeps its authored spelling, and the
     * residual is asserted rather than hidden.
     *
     * Here byte 0 is a paragraph the hoisted definition promoted and the `---`
     * closer is a line INSIDE a fenced block, so neither is the writer's to
     * respell: the document is misread whichever spelling the break takes. The
     * fallback pass therefore produces a DIFFERENT string that is no better, and
     * the canonical one is returned. A first version of this row used
     * `---` / blank / fence, which does not test this at all - that input parses
     * AS frontmatter, so it leaves through the cost gate and never reaches the
     * fallback.
     */
    public function testADocumentNoRespellingSavesKeepsTheAuthoredSpelling(): void
    {
        $source = "[^a]: n\n\n---yaml\nk: v\n\n***\n\n```\n---\n```\n";
        $formatted = $this->fmt($source);
        $this->assertStringStartsWith("---yaml\n", $formatted);
        $this->assertStringContainsString('***', $formatted);
        // The residual, stated: this document does not round-trip in either
        // spelling. PART 11 §1 is broken by the fence's `---`, which no writer
        // decision reaches.
        $this->assertNotSame($this->html($source), $this->html($formatted));
    }
}
