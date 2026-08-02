<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * §756 (NORMATIVE): a block's FINAL line trailing whitespace is stripped before
 * rendering; interior trailing (before a soft break) is kept. A leading TAB
 * after the `#`/`^` + space delimiter is content (only leading SPACES fold into
 * the delimiter). Headings and captions share this rule; NBSP is content and is
 * never stripped. Matches carve-js and carve-rs.
 */
class HeadingCaptionWhitespaceTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testHeadingStripsFinalTrailingWhitespace(): void
    {
        $this->assertSame(
            "<section id=\"x\">\n  <h1>x</h1>\n</section>\n",
            $this->converter->convert('# x '),
        );
        $this->assertSame(
            "<section id=\"x\">\n  <h1>x</h1>\n</section>\n",
            $this->converter->convert("# x\t"),
        );
    }

    public function testHeadingKeepsLeadingTabAsContent(): void
    {
        $this->assertSame(
            "<section id=\"x\">\n  <h1>\tx</h1>\n</section>\n",
            $this->converter->convert("# \tx"),
        );
    }

    public function testHeadingStripsItsTrailingWhitespace(): void
    {
        // A heading is one line, so §756 has a single line to strip and the
        // following line is a paragraph (which strips its own trailing run).
        $expected = "<section id=\"a\">\n  <h1>a</h1>\n  <p>b</p>\n</section>\n";

        $this->assertSame($expected, $this->converter->convert("# a \nb"));
        $this->assertSame($expected, $this->converter->convert("# a\nb "));
    }

    public function testCaptionStripsFinalTrailingWhitespace(): void
    {
        $this->assertSame(
            "<figure>\n  <img src=\"/u\" alt=\"a\">\n  <figcaption>x</figcaption>\n</figure>\n",
            $this->converter->convert("![a](/u)\n^ x "),
        );
    }

    public function testCaptionKeepsLeadingTabAsContent(): void
    {
        $this->assertSame(
            "<figure>\n  <img src=\"/u\" alt=\"a\">\n  <figcaption>\tx</figcaption>\n</figure>\n",
            $this->converter->convert("![a](/u)\n^ \tx"),
        );
    }
}
