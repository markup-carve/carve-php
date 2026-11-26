<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Block;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

/**
 * carve#6675, carve#6610 (ruled B): C2-B-228 runs the interrupter test before the containers that do not own the
 * line close, so C2-B-190 counts every block open before the line.
 */
final class ListMarkerAtDepthCapTest extends TestCase
{
    public function testUnprefixedMarkerBelowAParagraphAtDepth200IsALazyLine(): void
    {
        $source = '. ' . str_repeat('> - ', 66) . "x\n   - y\n";
        self::assertSame(67, $this->items($source));
        self::assertStringContainsString('"value":"- y"', Carve::toJson($source));
    }

    public function testMarkerWithRoomForAListAndItsItemStillOpensThem(): void
    {
        self::assertSame(67, $this->items('. ' . str_repeat('> - ', 65) . "x\n   - y\n"));
    }

    public function testSiblingItemNeedsOneFreeLevel(): void
    {
        self::assertSame(100, $this->items(str_repeat('- ', 100) . "x\n- y\n"));
        self::assertSame(100, $this->items(str_repeat('- ', 99) . "x\n- y\n"));
    }

    public function testDivLineBelowAParagraphAtDepth200IsALazyLine(): void
    {
        $html = Carve::toHtml(str_repeat('> ', 200) . "x\n::: figure\n");
        self::assertStringNotContainsString('<figure', $html);
        self::assertStringContainsString("x\n::: figure</p>", $html);
        self::assertStringContainsString('<figure', Carve::toHtml(str_repeat('> ', 199) . "x\n::: figure\n"));
    }

    private function items(string $source): int
    {
        return substr_count(Carve::toJson($source), '"type":"list_item"');
    }
}
