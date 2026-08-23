<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\BbcodeToCarve;
use PHPUnit\Framework\TestCase;

/**
 * Every line a pass writes carries the block context it lands in.
 *
 * `convertQuotes()` is the one pass that PREFIXES lines, and two things wrote
 * multi-line source into the document after it had: `convertLists()`, which ran
 * next and matched straight across the `> ` prefixes (carve-php#1619), and
 * `restoreCodeContent()`, which puts a many-line body back where a ONE-LINE key
 * stood (carve-php#1620). Both spliced their lines in at column 0, so a list
 * inside a quote wrote an unquoted blank line and split one quote into two, and
 * a code run inside a quote dropped its body out of both the quote and the
 * fence - where the body's own blank run was then read as a hard list boundary
 * and its literal `- ` markers as bullets.
 *
 * The list pass now runs BEFORE the quote pass, so the quote formatter prefixes
 * finished Carve source; the restore gives lines 2..n of a body the prefix of
 * the line its key sat on.
 */
class BbcodeBlockContextReachesEveryLineTest extends TestCase
{
    protected BbcodeToCarve $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new BbcodeToCarve();
    }

    protected function html(string $bbcode): string
    {
        return (new CarveConverter())->convert($this->converter->convert($bbcode));
    }

    protected function collapsed(string $bbcode): string
    {
        return preg_replace('/\s+/', '', $this->html($bbcode)) ?? '';
    }

    public function testAListInsideAQuoteStaysInsideTheQuote(): void
    {
        $bbcode = "[quote]\nintro text\n[list]\n[*]a\n[/list]\n[/quote]\n";

        $this->assertSame("> intro text\n>\n> - a\n", $this->converter->convert($bbcode));
        $this->assertSame('<blockquote><p>introtext</p><ul><li>a</li></ul></blockquote>', $this->collapsed($bbcode));
    }

    public function testTwoListsInsideOneQuoteStayInsideThatOneQuote(): void
    {
        $bbcode = "[quote]\nintro text\n[list]\n[*]a\n[/list]\n[list]\n[*]b\n[/list]\n[/quote]\n";

        // The boundary inside a quote is three `>` lines: three EMPTY lines
        // would end the quote and drop the second list out of it.
        $this->assertSame("> intro text\n>\n> - a\n>\n>\n>\n> - b\n", $this->converter->convert($bbcode));
        $this->assertSame(
            '<blockquote><p>introtext</p><ul><li>a</li></ul><ul><li>b</li></ul></blockquote>',
            $this->collapsed($bbcode),
        );
    }

    public function testACodeRunInsideAQuoteKeepsItsBodyInTheQuoteAndInTheFence(): void
    {
        $bbcode = "[quote]\n[code]\n- a\n\n\n\n- b\n[/code]\n[/quote]\n";

        $this->assertSame("> ```\n> - a\n>\n>\n>\n> - b\n> ```\n", $this->converter->convert($bbcode));
        $this->assertSame(
            "<blockquote>\n  <pre><code>- a\n\n\n\n- b\n</code></pre>\n</blockquote>",
            trim($this->html($bbcode)),
        );
    }

    // ==================== The bounds ====================

    /**
     * A PAYLOAD AT COLUMN 0 IS LEFT AT COLUMN 0. The prefix is read off the
     * line the key sat on, so a code run outside any container gains nothing -
     * an indent invented here would be content inside the fence.
     */
    public function testACodeRunOutsideAQuoteKeepsItsBodyAtColumnZero(): void
    {
        $this->assertSame(
            "```\nline one\nline two\n```\n",
            $this->converter->convert("[code]\nline one\nline two\n[/code]\n"),
        );
    }

    /**
     * ONLY THE BLOCK PREFIX IS REPEATED, not the line. An inline code span sits
     * mid-line, and the words in front of it are that line's content: repeating
     * them would write them again on every line the span carries.
     */
    public function testAnInlineCodeSpanDoesNotRepeatTheWordsBeforeIt(): void
    {
        $carve = $this->converter->convert("[quote]before [c]one\ntwo[/c] after[/quote]");

        // The `> ` on line two is the quote's prefix and belongs there; the
        // words `before ` are content and appear once.
        $this->assertSame("> before `one\n> two` after\n", $carve);
        $this->assertSame(1, substr_count($carve, 'before'));
    }

    /**
     * A QUOTE WITH NO LIST AND NO CODE IN IT is prefixed and nothing more - and
     * its blank line carries the BARE marker, not `> `: the trailing space is
     * whitespace no author wrote, and inside a fence in that quote it would be
     * content.
     */
    public function testAPlainQuoteIsOnlyPrefixed(): void
    {
        $this->assertSame(
            "> first\n>\n> second\n",
            $this->converter->convert("[quote]\nfirst\n\nsecond\n[/quote]\n"),
        );
    }

    /**
     * A LIST OUTSIDE A QUOTE IS UNTOUCHED by the reordering: the pass that used
     * to run before it, and now runs after it, wrote nothing a list reads.
     */
    public function testAListOutsideAQuoteIsUnchanged(): void
    {
        $this->assertSame("- a\n- b\n", $this->converter->convert("[list]\n[*]a\n[*]b\n[/list]\n"));
    }

    /**
     * A QUOTE INSIDE A LIST ITEM still reaches the quote pass: the reordering
     * moved the list pass ahead of it, so the item's text still carries the
     * `[quote]` tags when the quote pass runs.
     */
    public function testAQuoteInsideAListItemIsStillConverted(): void
    {
        $this->assertStringContainsString('>', $this->converter->convert('[list][*]see [quote]this[/quote][/list]'));
    }
}
