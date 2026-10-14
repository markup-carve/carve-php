<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class MarkdownClosedBlocksStayInTightItemsTest extends TestCase
{
    public function testTextCanFollowAClosedCodeFenceWithoutABlank(): void
    {
        $source = "- ```\n  x\n  ```\n  after\n";
        self::assertSame("- ```\n  x\n  ```\n  after\n", CarveConverter::markdown()->convert($source));
    }

    public function testThematicBreakAfterAParagraphUsesAnInterruptingMarker(): void
    {
        $source = "- a\n+\n---\n";
        self::assertSame("- a\n  ***\n", CarveConverter::markdown()->convert($source));
    }
}
