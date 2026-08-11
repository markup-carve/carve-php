<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Test\LegacyCarveConverter as CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A blockquote's lazy continuation (a line without the ">" marker) may only extend
 * an OPEN paragraph, per the djot/CommonMark rule. Previously a non-">" line was
 * swallowed into an open fenced code block or just-opened div inside the quote,
 * corrupting content and stripping ">" markers off following lines.
 */
class BlockQuoteLazyContinuationTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testNonMarkerLineInsideOpenFenceTerminatesQuote(): void
    {
        // The non-">" line must NOT be swallowed into the code block; the quote
        // ends and `b` starts a paragraph. The trailing `> c` then interrupts
        // that paragraph into a fresh block quote (§10 paragraph interruption).
        $djot = "> ```\n> a\nb\n> c";
        $expected = "<blockquote>\n  <pre><code>a\n</code></pre>\n</blockquote>\n"
            . "<p>b</p>\n<blockquote><p>c</p></blockquote>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testGluedTypeWordStaysParagraphInsideQuote(): void
    {
        // `:::note` has no space after the fence, so it is paragraph text even
        // when a fence-shaped line follows.
        $djot = "> :::note\nbody\n> :::";
        $expected = "<blockquote><p>:::note\nbody\n:::</p></blockquote>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testFenceLikeLineInsideOpenParagraphStaysParagraphText(): void
    {
        // Regression guard: a code fence does NOT interrupt an open paragraph in
        // strict mode, so the fence-looking line is paragraph text and the next
        // unquoted line keeps lazily continuing the paragraph.
        $djot = "> text\n> ```\nlazy";
        $expected = "<blockquote><p>text\n<code>\nlazy</code></p></blockquote>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testLazyContinuationOfParagraphStillFolds(): void
    {
        // Regression guard: a non-">" line continuing an OPEN paragraph still folds
        // into the blockquote (unchanged correct behavior).
        $djot = "> p\nlazy\n> more";
        $expected = "<blockquote><p>p\nlazy\nmore</p></blockquote>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testLazyContinuationAfterGluedTypeWordStillFolds(): void
    {
        $djot = "> :::note\n> para\nlazy\n> :::";
        $expected = "<blockquote><p>:::note\npara\nlazy\n:::</p></blockquote>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }
}
