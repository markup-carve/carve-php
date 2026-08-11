<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdjacentAttachedBlockOpenersTest extends TestCase
{
    public static function cases(): iterable
    {
        yield 'block quotes' => ["- x\n+\n> q\n+\n> q\n"];
        yield 'tables' => ["- x\n+\n| a |\n|---|\n| b |\n+\n| a |\n|---|\n| b |\n"];
        yield 'line blocks' => ["- x\n+\n::: |\na\n:::\n+\n::: |\nb\n:::\n"];
        yield 'definition lists' => ["- x\n+\n:: a\n:  b\n+\n:: c\n:  d\n"];
        yield 'compatible lists' => ["- x\n+\n- a\n+\n- b\n"];
    }

    #[DataProvider('cases')]
    public function testAdjacentBlocksStaySeparate(string $source): void
    {
        $converter = new CarveConverter();
        self::assertSame($converter->convert($source), $converter->convert($converter->toCarve($source)));
        self::assertSame($converter->toCarve($source), $converter->toCarve($converter->toCarve($source)));
    }

    public function testIsolatedBlockOpenerKeepsIndentedCanonicalForm(): void
    {
        self::assertSame("- x\n+\n> q\n", (new CarveConverter())->toCarve("- x\n+\n> q\n"));
    }

    public function testIncompatibleListsDoNotNeedContinuationMarkers(): void
    {
        $converter = new CarveConverter();
        $source = "- x\n+\n- a\n+\n* b\n";
        $formatted = $converter->toCarve($source);
        self::assertStringNotContainsString("\n+\n", $formatted);
        self::assertSame($converter->convert($source), $converter->convert($formatted));
    }

    public function testListCompatibilityUsesItsSemanticMarkerSlots(): void
    {
        $renderer = new class extends CarveRenderer {
            public function merge(Node $left, Node $right): bool
            {
                return $this->adjacentBlocksMerge($left, $right);
            }
        };
        $left = new ListBlock(ListBlock::TYPE_ORDERED, marker: '.', style: 'a');
        self::assertTrue($renderer->merge($left, new ListBlock(ListBlock::TYPE_ORDERED, marker: '.', style: 'a')));
        self::assertFalse($renderer->merge($left, new ListBlock(ListBlock::TYPE_ORDERED, marker: ')', style: 'a')));
    }
}
