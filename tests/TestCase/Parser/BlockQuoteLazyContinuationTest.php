<?php

declare(strict_types=1);

namespace Carve\Test\TestCase\Parser;

use Carve\CarveConverter;
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
        // ends and `b` starts a paragraph. Djot variant: the trailing `> c` does
        // NOT interrupt that paragraph (a block quote no longer interrupts an open
        // paragraph) -- it lazily continues it, so `> c` folds in as literal
        // `&gt; c` rather than opening a fresh block quote.
        $djot = "> ```\n> a\nb\n> c";
        $expected = "<blockquote>\n  <pre><code>a\n</code></pre>\n</blockquote>\n"
            . "<p>b\n&gt; c</p>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }

    public function testNonMarkerLineAfterDivOpenerTerminatesQuote(): void
    {
        // The `:::note` opener inside the quote has no matching closer within
        // the quote, so it is NOT a div -- it stays literal (§12), and the
        // non-">" `body` line ends the quote. Djot variant: the trailing `> :::`
        // does NOT interrupt the `body` paragraph (a block quote no longer
        // interrupts an open paragraph) -- it lazily continues it, folding in as
        // literal `&gt; :::` rather than opening a fresh quoted paragraph.
        $djot = "> :::note\nbody\n> :::";
        $expected = "<blockquote><p>:::note</p></blockquote>\n"
            . "<p>body\n&gt; :::</p>\n";

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

    public function testLazyContinuationOfParagraphInsideDivStillFolds(): void
    {
        // Regression guard: a paragraph IS open inside the div, so the lazy line
        // folds into it (must not be broken by the fix).
        $djot = "> :::note\n> para\nlazy\n> :::";
        $expected = "<blockquote>\n  <aside class=\"admonition note\">\n    <p>para\nlazy</p>\n  </aside>\n</blockquote>\n";

        $this->assertSame($expected, $this->converter->convert($djot));
    }
}
