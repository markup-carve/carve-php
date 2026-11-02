<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\Utility\LayoutWork;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each line of an item's body asked whether a visible child had followed the
 * blank, and re-read every comment since the blank to answer. Counted, so it can
 * gate pull requests: 16,000 repeats took 16.7 s on the shape below.
 */
class AnItemsCommentRunIsScannedOnceTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function commentRuns(): array
    {
        return [
            'line comments after a description' => ["- o\n  :: t\n  : x\n\n", "  %% c\n\n"],
            'line comments after a paragraph' => ["- o\n\n", "  %% c\n\n"],
            'spans after a paragraph' => ["- o\n\n", "  %%%\n  c\n  %%%\n\n"],
            'braced comments after a sub-list' => ["- o\n  - p\n\n", "  {% c %}\n\n"],
        ];
    }

    #[DataProvider('commentRuns')]
    public function testTheCountedReadsGrowWithTheRun(string $head, string $unit): void
    {
        $small = $this->reads($head . str_repeat($unit, 500));
        $large = $this->reads($head . str_repeat($unit, 2000));

        $this->assertLessThanOrEqual(2 * 2000 + 10, $large);
        $this->assertLessThanOrEqual(4 * $small + 10, $large);
    }

    public function testAVisibleChildAfterTheRunStillLoosensTheItem(): void
    {
        $html = (new CarveConverter())->convert("- o\n  - p\n\n  %% c\n  %% d\n  para\n");

        $this->assertStringContainsString('<li><p>o</p>', $html);
        $this->assertStringContainsString('<p>para</p>', $html);
    }

    public function testACommentRunAloneLeavesTheItemTight(): void
    {
        $html = (new CarveConverter())->convert("- o\n\n  %% c\n\n  %% d\n");

        $this->assertStringContainsString('<li>o</li>', $html);
    }

    private function reads(string $source): int
    {
        LayoutWork::reset();
        LayoutWork::$on = true;
        try {
            (new CarveConverter())->parse($source);
        } finally {
            LayoutWork::$on = false;
        }

        return LayoutWork::$itemBlankChildReads;
    }
}
