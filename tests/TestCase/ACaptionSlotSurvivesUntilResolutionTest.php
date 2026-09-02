<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * PART 9R R7: block-image status is a property of the RESOLVED tree, and one
 * phase settles it after reference resolution.
 *
 * Definitions are collected during the document walk when a document holds two
 * or more definition KINDS, so a reference defined below its image is still
 * unresolved when the caption line is read. The captionable gate asked about
 * resolution there and folded the caption into the paragraph - which nothing
 * later could take back, because the line had stopped being a separate line.
 *
 * The effect was that an unrelated footnote elsewhere in the document decided
 * whether an image was a figure (carve-php#1851).
 */
class ACaptionSlotSurvivesUntilResolutionTest extends TestCase
{
    /**
     * @var string
     */
    private const FIGURE = "<figure>\n  <img src=\"/a.png\" alt=\"a\">\n  <figcaption>cap</figcaption>\n</figure>";

    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    private function firstBlock(string $source): string
    {
        $html = trim($this->converter->convert($source));
        $end = strpos($html, "\n<p>");

        return $end === false ? $html : substr($html, 0, $end);
    }

    public function testOneKindIsAFigure(): void
    {
        // The control. This path collects definitions in a pre-pass, so the
        // reference already resolved when the caption line was read.
        $this->assertSame(
            self::FIGURE,
            $this->firstBlock("![a][ref]\n^ cap\n\n[ref]: /a.png\n"),
        );
    }

    public function testAnUnrelatedFootnoteDoesNotChangeIt(): void
    {
        // The bug: the same image, plus a footnote that shares nothing with
        // it. The second kind moves the document onto the integrated path.
        $this->assertSame(
            self::FIGURE,
            $this->firstBlock("![a][ref]\n^ cap\n\n[ref]: /a.png\n\n[^fn]: note\n\nsee [^fn]\n"),
        );
    }

    public function testAnUnrelatedAbbreviationDoesNotChangeItEither(): void
    {
        // The third kind reaches the same path by a different door, so this
        // fails too if the fix were keyed to footnotes rather than to the path.
        $this->assertSame(
            self::FIGURE,
            $this->firstBlock("![a][ref]\n^ cap\n\n[ref]: /a.png\n\n*[HTML]: HyperText\n\nHTML\n"),
        );
    }

    public function testAnUnresolvedImageKeepsItsCaptionLineAsText(): void
    {
        // The near miss, and the line this fix must not lose. Holding the slot
        // is only safe if an unresolved image gets every source line back.
        $this->assertSame(
            "<p>![a][nope]\n^ cap</p>",
            $this->firstBlock("![a][nope]\n^ cap\n\n[other]: /o.png\n\n[^fn]: n\n\nsee [^fn]\n"),
        );
    }

    public function testAMultiLineSlotComesBackWhole(): void
    {
        // PART 9R R7 says ALL of them. A slot wider than one line is where a
        // give-back loses a line of the document if it hands back only the
        // first.
        $html = $this->firstBlock("![a][nope]\n^ cap\n  more\n\n[other]: /o.png\n\n[^fn]: n\n\nsee [^fn]\n");

        $this->assertStringContainsString('^ cap', $html);
        $this->assertStringContainsString('more', $html);
    }
}
