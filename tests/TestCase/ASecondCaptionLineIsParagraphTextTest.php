<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A second `^ ` line does not replace an attached caption
 * (markup-carve/carve-php#1199).
 *
 * PART 9 section 4, `resources/grammar.ebnf` near line 1101: "a further `^ `
 * line does NOT continue the caption (there is no repeated marker); it ends the
 * caption and, having no captionable block to attach to, is ordinary paragraph
 * text."
 *
 * This engine overwrote instead, and the overwrite was SILENT: the first
 * caption appeared nowhere in the output.
 */
class ASecondCaptionLineIsParagraphTextTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = CarveConverter::create();
    }

    public function testTheFirstTableCaptionSurvivesAndTheSecondIsAParagraph(): void
    {
        $html = $this->converter->convert("| A |\n|---|\n| 1 |\n^ One\n^ Two\n");

        $this->assertStringContainsString('<caption>One</caption>', $html);
        $this->assertStringNotContainsString('<caption>Two</caption>', $html);
        $this->assertStringContainsString('<p>^ Two</p>', $html);
    }

    /**
     * The content-loss half stated directly: whatever happens to the second
     * line, the first caption's text must still reach the page.
     */
    public function testNoAuthoredCaptionTextIsDiscarded(): void
    {
        $html = $this->converter->convert("| A |\n|---|\n| 1 |\n^ One\n^ Two\n");

        $this->assertStringContainsString('One', $html);
        $this->assertStringContainsString('Two', $html);
    }

    /**
     * BOUND, not proof: a SINGLE caption still attaches, which is the behavior
     * the guard must not break. Removing the guard leaves this passing, so it
     * bounds the change rather than proving it.
     */
    public function testASingleCaptionStillAttaches(): void
    {
        $html = $this->converter->convert("| A |\n|---|\n| 1 |\n^ Only\n");

        $this->assertStringContainsString('<caption>Only</caption>', $html);
    }

    /**
     * BOUND: the figure host was already correct in all three engines and is
     * untouched here - `BlockParser` has one `setCaption` call and it is the
     * table's. This row never moved.
     */
    public function testTheFigureHostIsUnchanged(): void
    {
        $html = $this->converter->convert("![a](a.png)\n^ First\n^ Second\n");

        $this->assertStringContainsString('<figcaption>First</figcaption>', $html);
        $this->assertStringContainsString('<p>^ Second</p>', $html);
    }
}
