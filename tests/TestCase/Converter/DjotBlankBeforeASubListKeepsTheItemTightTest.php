<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * djot.js keeps an item tight across a blank before its sub-list, and Carve reads
 * that blank as loosening (carve#5015), so the importer drops it (carve#5187).
 */
final class DjotBlankBeforeASubListKeepsTheItemTightTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function glued(): array
    {
        return [
            'bullet' => ["- one\n\n  - two\n", "- one\n  - two\n"],
            'task' => ["- [x] parent\n\n  - [ ] child\n", "- [x] parent\n  - [ ] child\n"],
            'two blanks' => ["1. one\n\n\n   - two\n", "1. one\n   - two\n"],
            'quoted' => ["> - one\n>\n>   - two\n", "> - one\n>   - two\n"],
            'sibling after' => ["- a\n\n  - b\n- c\n", "- a\n  - b\n- c\n"],
        ];
    }

    #[DataProvider('glued')]
    public function testTheBlankIsDropped(string $djot, string $carve): void
    {
        $this->assertSame($carve, (new DjotToCarve())->convert($djot));
    }

    public function testATightItemImportsTight(): void
    {
        $html = (new CarveConverter())->convert((new DjotToCarve())->convert("- one\n\n  - two\n"));
        $this->assertStringNotContainsString('<p>', $html);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function loose(): array
    {
        return [
            'quote' => ["- one\n\n  > q\n"],
            'paragraph' => ["- one\n\n  para\n"],
        ];
    }

    #[DataProvider('loose')]
    public function testABlankBeforeAnotherBlockStays(string $djot): void
    {
        $html = (new CarveConverter())->convert((new DjotToCarve())->convert($djot));
        $this->assertStringContainsString('<p>', $html);
    }
}
