<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * PART 2, SINGLE-LINE HEADINGS (NORMATIVE, diverges from Djot). A heading ends
 * at the newline: nothing folds into it, so the next line simply begins its own
 * block. Spec corpus 82-single-line-headings pins the same cases.
 */
class SingleLineHeadingTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testAFollowingPlainLineIsAParagraph(): void
    {
        $this->assertSame(
            "<section id=\"Title\">\n  <h1>Title</h1>\n  <p>outside</p>\n</section>\n",
            $this->converter->convert("# Title\noutside"),
        );
    }

    public function testTheAutoIdComesFromTheHeadingLineAlone(): void
    {
        // Folding derived the id from the heading text PLUS every folded line,
        // so `[Heading][]` references and TOC anchors keyed on text the author
        // never put in the title - with nothing reporting it.
        $html = $this->converter->convert("# Title\nSome text.\n");

        $this->assertStringContainsString('id="Title"', $html);
        $this->assertStringNotContainsString('Title-Some-text', $html);
    }

    public function testASameLevelMarkerLineIsASecondHeading(): void
    {
        $this->assertSame(
            "<section id=\"A\">\n  <h2>A</h2>\n</section>\n"
            . "<section id=\"still-A\">\n  <h2>still A</h2>\n</section>\n"
            . "<section id=\"B\">\n  <h1>B</h1>\n</section>\n",
            $this->converter->convert("## A\n## still A\n# B\n"),
        );
    }

    public function testADifferentLevelMarkerStillNests(): void
    {
        $this->assertSame(
            "<section id=\"H\">\n  <h1>H</h1>\n"
            . "  <section id=\"sub\">\n    <h2>sub</h2>\n  </section>\n</section>\n",
            $this->converter->convert("# H\n## sub\n"),
        );
    }

    public function testAListMarkerStartsASiblingList(): void
    {
        $this->assertSame(
            "<section id=\"Title\">\n  <h1>Title</h1>\n  <ul>\n    <li>item</li>\n  </ul>\n</section>\n",
            $this->converter->convert("# Title\n- item\n"),
        );
        $this->assertSame(
            "<section id=\"Title\">\n  <h1>Title</h1>\n  <ol>\n    <li>one</li>\n  </ol>\n</section>\n",
            $this->converter->convert("# Title\n1. one\n"),
        );
    }

    public function testAMarkerWithoutContentIsNotAHeading(): void
    {
        $this->assertSame("<p>#</p>\n", $this->converter->convert("#  \n"));
    }

    public function testACaptionLineAfterAHeadingIsLiteralText(): void
    {
        $this->assertSame(
            "<section id=\"H\">\n  <h1>H</h1>\n  <p>^ cap</p>\n</section>\n",
            $this->converter->convert("# H\n^ cap\n"),
        );
    }

    public function testAPrecedingBlockAttributeLineAppliesToTheHeadingOnly(): void
    {
        // Strict djot: heading attributes come from the PRECEDING block-attribute
        // line. The following text is a separate block and takes none of it.
        $this->assertSame(
            "<section id=\"id\">\n  <h1>Title</h1>\n  <p>more</p>\n</section>\n",
            $this->converter->convert("{#id}\n# Title\nmore\n"),
        );
    }
}
