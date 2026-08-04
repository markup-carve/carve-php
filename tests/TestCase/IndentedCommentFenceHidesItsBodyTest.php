<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
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

    public function testInsideAListItemTheFenceIsInvisibleToo(): void
    {
        // markup-carve/carve#629 decided it: §24 C3 recognizes a comment at
        // ANY column, and the fence form is a comment. This test used to pin
        // the opposite as a placeholder so the decision could not land
        // silently - it did land, so the pin moves with it.
        $html = $this->converter->convert("- a\n %%% n\n x\n %%%\n tail\n");

        $this->assertStringNotContainsString('%%%', $html);
        $this->assertStringNotContainsString('>x<', $html);
        $this->assertStringContainsString('tail', $html);
    }
}
