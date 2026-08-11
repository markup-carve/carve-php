<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Test\LegacyCarveConverter as CarveConverter;
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
        // Nothing folds into a heading any more, and a bare `#` has no content
        // so it is not a heading itself: it is a paragraph between two of them.
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
