<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A leading block-attribute line (`{#id}`) before a sole block image lands on
 * the promoted bare <img> (§15) -- consistent with an inline `![…](…){#id}` and
 * with a sole image rendering bare (no <p> wrapper). It does NOT wrap the image
 * in a <p>. A following caption still puts the id on the <figure>. Two images
 * stay a paragraph. Matches carve-js and carve-rs.
 */
class BlockImageAttributeTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testLeadingAttrLineOnDirectBlockImage(): void
    {
        $this->assertSame(
            "<img src=\"/u\" alt=\"a\" id=\"f\">\n",
            $this->converter->convert("{#f}\n![a](/u)"),
        );
    }

    public function testLeadingAttrLineOnReferenceBlockImage(): void
    {
        $this->assertSame(
            "<img src=\"/u\" alt=\"a\" id=\"f\">\n",
            $this->converter->convert("{#f}\n![a][r]\n\n[r]: /u"),
        );
    }

    public function testLeadingAttrLineMergesWithImageOwnAttrs(): void
    {
        $this->assertSame(
            "<img src=\"/u\" alt=\"a\" id=\"f\" class=\"c\">\n",
            $this->converter->convert("{#f}\n![a][r]{.c}\n\n[r]: /u"),
        );
    }

    public function testLeadingAttrLineWithCaptionStaysOnFigure(): void
    {
        $this->assertSame(
            "<figure id=\"f\">\n  <img src=\"/u\" alt=\"a\">\n  <figcaption>cap</figcaption>\n</figure>\n",
            $this->converter->convert("{#f}\n![a](/u)\n^ cap"),
        );
    }

    public function testTwoImagesStayAParagraph(): void
    {
        $this->assertSame(
            "<p id=\"f\"><img src=\"/u\" alt=\"a\">\n<img src=\"/u\" alt=\"b\"></p>\n",
            $this->converter->convert("{#f}\n![a](/u)\n![b](/u)"),
        );
    }
}
