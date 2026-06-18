<?php

declare(strict_types=1);

namespace Carve\Test\TestCase\Parser;

use Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * Continuation marker in a block quote (Carve, grammar PART 9 §17).
 *
 * A lone `+` at column 0 immediately after a quoted line attaches the following
 * flush-left block to the quote body, with no `>` prefix and no blank line. It
 * is the un-prefixed analogue of the list-item continuation marker, so a real
 * block (a list, fenced code, table, ...) can join the quote without repeating
 * `>` on every line. The marker only attaches; a blank line still ends the
 * quote, and a `+` outside any container stays literal text.
 */
class BlockQuoteContinuationMarkerTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testAttachesListToQuote(): void
    {
        $this->assertSame(
            "<blockquote>\n  <p>quoted</p>\n  <ul>\n    <li>item</li>\n  </ul>\n</blockquote>\n",
            $this->converter->convert("> quoted\n+\n- item"),
        );
    }

    public function testAttachesFencedCodeToQuote(): void
    {
        $this->assertSame(
            "<blockquote>\n  <p>quoted</p>\n  <pre><code>code\n</code></pre>\n</blockquote>\n",
            $this->converter->convert("> quoted\n+\n```\ncode\n```"),
        );
    }

    public function testAttachesTableToQuote(): void
    {
        $this->assertSame(
            "<blockquote>\n  <p>quoted</p>\n  <table>\n    <tbody>\n      <tr><td>a</td><td>b</td></tr>\n    </tbody>\n  </table>\n</blockquote>\n",
            $this->converter->convert("> quoted\n+\n| a | b |"),
        );
    }

    public function testTwoAttachedBlocks(): void
    {
        $this->assertSame(
            "<blockquote>\n  <p>q</p>\n  <ul>\n    <li>a</li>\n  </ul>\n  <pre><code>c\n</code></pre>\n</blockquote>\n",
            $this->converter->convert("> q\n+\n- a\n+\n```\nc\n```"),
        );
    }

    public function testQuoteResumesAfterAttachedBlock(): void
    {
        $this->assertSame(
            "<blockquote>\n  <p>q</p>\n  <ul>\n    <li>item</li>\n  </ul>\n  <p>more</p>\n</blockquote>\n",
            $this->converter->convert("> q\n+\n- item\n> more"),
        );
    }

    public function testBlankLineBeforeMarkerEndsQuoteSoMarkerIsLiteral(): void
    {
        // A blank line ends the quote; the `+` is then outside any container and
        // stays literal text (with the list marker folding into the paragraph).
        $this->assertSame(
            "<blockquote><p>q</p></blockquote>\n<p>+\n- item</p>\n",
            $this->converter->convert("> q\n\n+\n- item"),
        );
    }

    public function testIndentedMarkerIsNotaContinuationMarker(): void
    {
        // Only a column-0 `+` is the marker; an indented `+` is not. Under the
        // one-rule model both the indented `+` and the col-0 `- item` fold into
        // the quote's open lazy paragraph (nothing interrupts it; a blank line
        // would be required to start the list). So this is a single quote.
        $this->assertSame(
            "<blockquote><p>q\n+\n- item</p></blockquote>\n",
            $this->converter->convert("> q\n  +\n- item"),
        );
    }
}
