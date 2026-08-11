<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Test\LegacyCarveConverter as CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * An indented `%%%` fence is a comment, body included.
 *
 * The fence was recognized only at column 0, so an indented opener fell to the
 * `%%` line-comment path: the opener and the closer were each consumed as their
 * own one-line comment and every line BETWEEN them rendered as ordinary text. A
 * comment renders nothing, and that has to include what it encloses - hiding
 * the delimiters while showing the contents is the one outcome the construct
 * may never have (carve-php#770).
 *
 * carve-js and carve-rs had the same defect (carve-js#630, carve-rs#573).
 *
 * Only where a fence is CONSUMED does the column stop mattering. Whether an
 * indented fence is a comment at all INSIDE a list item is a separate question,
 * still open as markup-carve/carve#629, and this engine's answer there is
 * unchanged - see the last test.
 */
class IndentedCommentFenceHidesItsBodyTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testAnIndentedFenceUnderAParagraphRendersNothing(): void
    {
        $this->assertSame("<p>a</p>\n", $this->converter->convert("a\n  %%% x\n  b\n  %%%\n"));
    }

    public function testAnIndentedFenceAtTheStartOfADocumentRendersNothing(): void
    {
        $this->assertSame('', trim($this->converter->convert("  %%% x\n  b\n  %%%\n")));
    }

    public function testAnIndentedCloserClosesAnIndentedOpener(): void
    {
        // Leading whitespace is not part of the delimiter; the `%` run is.
        $this->assertSame(
            "<p>a</p>\n<p>c</p>\n",
            $this->converter->convert("a\n %%%% x\n b\n   %%%%\nc\n"),
        );
    }

    public function testAnUnclosedIndentedFenceOpensNoBlock(): void
    {
        // PART 9 §28: without a closer it is not a fenced comment, so the
        // following blocks still render instead of being swallowed to EOF.
        $this->assertSame("<p>a</p>\n<p>b</p>\n", $this->converter->convert("a\n  %%% x\nb\n"));
    }

    public function testInsideAListItemTheWholeSpanIsInvisible(): void
    {
        // markup-carve/carve#629 settled this: a comment is recognized at any
        // column, so below an item's content column the fence is a comment and
        // not the text it looks like. Corpus case
        // 186-a-comment-fence-is-a-comment-at-any-column-too pins it.
        //
        // The item stays open across it, so `tail` is still item content - a
        // comment closes nothing.
        $this->assertSame(
            "<ul>\n  <li>a\n    tail\n  </li>\n</ul>\n",
            $this->converter->convert("- a\n %%% n\n x\n %%%\n tail\n"),
        );
    }

    public function testAnUnclosedFenceInAListItemIsInvisibleToo(): void
    {
        // An unclosed `%%%` opens no block (PART 9 §28) but it is still a
        // COMMENT, and §24 C3 keeps a comment invisible at any column. This
        // used to fold the opener into the item's paragraph, because
        // isBlockElementStart() claims a comment fence without asking whether
        // it closes - so the same line rendered as text here and as nothing at
        // the top level, at the content column, and in both other engines.
        // Pinned the other way now that carve-php#775 is fixed.
        $html = $this->converter->convert("- a\n %%% n\n");

        $this->assertStringNotContainsString('%%%', $html);
        $this->assertSame("<ul>\n  <li>a</li>\n</ul>\n", $html);
    }
}
