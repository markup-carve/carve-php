<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
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

    public function testInterruptingMarkerKeepsItsRoundTripCarrier(): void
    {
        $converter = CarveConverter::markdown();
        $renderer = $converter->getRenderer();
        self::assertInstanceOf(MarkdownRenderer::class, $renderer);
        $renderer->setRoundTripMode(true);

        $output = $converter->convert("- a\n+\n{.x}\n---\n");
        self::assertStringContainsString('<!-- carve: {.x} -->', $output);
        self::assertStringContainsString("***\n", $output);
    }

    public function testBreakBetweenTwoTightItemsKeepsBothItemsAdjacent(): void
    {
        $source = "- a\n+\n---\n- b\n";
        self::assertSame("- a\n  ***\n- b\n", CarveConverter::markdown()->convert($source));
    }
}
