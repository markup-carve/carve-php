<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Test\Inline;

use MarkupCarve\Carve2\Carve2;
use PHPUnit\Framework\TestCase;

final class BlockLeafTraversalTest extends TestCase
{
    public function testTableCellAndCaptionLeavesReceiveTheirOwnInlinePass(): void
    {
        // C2-I-001: the block pass hands each leaf to the inline pass, including cells and captions.
        $source = "|= Name |= Value |\n| a *bold* | [link](https://example.com) |\n^ A /caption/\n";
        self::assertSame(
            "<table>\n  <caption>A <em>caption</em></caption>\n  <thead>\n"
            . "    <tr><th scope=\"col\">Name</th><th scope=\"col\">Value</th></tr>\n  </thead>\n"
            . "  <tbody>\n    <tr><td>a <strong>bold</strong></td><td><a href=\"https://example.com\">link</a></td></tr>\n"
            . "  </tbody>\n</table>",
            Carve2::toHtml($source),
        );
    }
}
