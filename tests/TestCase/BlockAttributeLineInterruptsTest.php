<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Test\LegacyCarveConverter as CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A standalone `{...}` block-attribute line interrupts an open paragraph
 * (grammar PART 9 §10 + §15). It must not fold into the paragraph as literal
 * text: it ends the paragraph and floats forward to the next block element, or
 * is dropped when none follows. Canonical verified against carve-js.
 *
 * Before this fix carve-php kept a trailing `{...}` line as literal paragraph
 * content (`<p>Para\n{.class}</p>`). The interrupted paragraph now closes the
 * same way every other interrupting construct closes it (`<p>Para</p>`).
 */
class BlockAttributeLineInterruptsTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testTrailingBlockAttributeLineIsDropped(): void
    {
        $result = $this->converter->convert("Para\n{.class}");

        $this->assertSame("<p>Para</p>\n", $result);
    }

    public function testTrailingBlockAttributeLineAfterMultiLineParagraph(): void
    {
        $result = $this->converter->convert("a\nb\n{.c}\n");

        $this->assertSame("<p>a\nb</p>\n", $result);
    }

    public function testBlockAttributeLineFloatsToFollowingBlock(): void
    {
        // After interrupting, the block-attribute line attaches to the next
        // block (separated by a blank line).
        $result = $this->converter->convert("Para\n{.class}\n\nNext\n");

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
