<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * djot.js PR137 / djot-php #264 parity: a trailing backslash at end of input is
 * a hard break, and a bare same-level `#` continues a heading.
 */
class TrailingBackslashAndHeadingContTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testTrailingBackslashAtEofIsHardBreak(): void
    {
        $this->assertSame("<p>para<br>\n</p>\n", $this->converter->convert('para\\'));
    }

    public function testNormalHardBreakUnchanged(): void
    {
        $this->assertSame("<p>a<br>\nb</p>\n", $this->converter->convert("a\\\nb"));
    }

    public function testTrailingEscapedPunctuationUnchanged(): void
    {
        $this->assertSame("<p>a*</p>\n", $this->converter->convert('a\\*'));
    }

    public function testBareSameLevelHashDoesNotContinueAHeading(): void
    {
        // This used to join `h` and `x` into one title with the id `h-x`. Each
        // `#` line now stands alone, and the content-less one is not a heading.
        $this->assertSame(
            "<section id=\"h\">\n  <h1>h</h1>\n  <p>#</p>\n</section>\n"
            . "<section id=\"x\">\n  <h1>x</h1>\n</section>\n",
            $this->converter->convert("# h\n#\n# x\n"),
        );
    }

    public function testDifferentLevelStartsNewHeading(): void
    {
        $this->assertSame(
            "<section id=\"a\">\n  <h1>a</h1>\n</section>\n"
            . "<section id=\"b\">\n  <h1>b</h1>\n</section>\n",
            $this->converter->convert("# a\n\n# b\n"),
        );
    }
}
