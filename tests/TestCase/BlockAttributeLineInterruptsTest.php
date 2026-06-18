<?php

declare(strict_types=1);

namespace Carve\Test\TestCase;

use Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * One-rule §10 paragraph interruption: NOTHING interrupts an open paragraph,
 * so a standalone `{...}` block-attribute line that follows prose with NO blank
 * line FOLDS into the open paragraph as literal text. A blank line is required
 * to start it; only then does it float forward to the next block (§15).
 * Canonical verified against carve-js / carve spec PR #156.
 *
 * Earlier carve-php closed the paragraph on a trailing `{...}` line; under the
 * collapsed one-rule model it folds instead (`<p>Para\n{.class}</p>`).
 */
class BlockAttributeLineInterruptsTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testTrailingBlockAttributeLineFoldsAsText(): void
    {
        $result = $this->converter->convert("Para\n{.class}");

        $this->assertSame("<p>Para\n{.class}</p>\n", $result);
    }

    public function testTrailingBlockAttributeLineAfterMultiLineParagraphFolds(): void
    {
        $result = $this->converter->convert("a\nb\n{.c}\n");

        $this->assertSame("<p>a\nb\n{.c}</p>\n", $result);
    }

    public function testBlockAttributeLineFloatsForwardOnlyAfterBlankLine(): void
    {
        // A blank line is required to end the paragraph; only then does the
        // block-attribute line attach to the following block.
        $result = $this->converter->convert("Para\n\n{.class}\n\nNext\n");

        $this->assertSame("<p>Para</p>\n<p class=\"class\">Next</p>\n", $result);
    }

    // ---- preserved behavior ----

    public function testInlineBraceOnSameLineStaysLiteral(): void
    {
        // A `{...}` with content on the same line is inline (host-less) content,
        // not a block-attribute line; unchanged by this fix.
        $result = $this->converter->convert("text {.x} y\n");

        $this->assertSame("<p>text {.x} y</p>\n", $result);
    }

    public function testLeadingBlockAttributeLineStillAttachesToNextBlock(): void
    {
        $result = $this->converter->convert("{.class}\nPara\n");

        $this->assertSame("<p class=\"class\">Para</p>\n", $result);
    }
}
