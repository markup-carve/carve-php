<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A marker with nothing after it is not a marker (PART 2 `CARVE-P2-009`), so an
 * empty item is written in the first-block form, `- +` (#2087).
 */
class AnEmptyListItemIsWrittenAsAFirstBlockItemTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function imports(): array
    {
        return [
            'a bullet item' => ['<ul><li>a</li><li></li><li>b</li></ul>', "- a\n- +\n- b\n"],
            'an ordered item' => ['<ol><li>a</li><li></li><li>b</li></ol>', "1. a\n2. +\n3. b\n"],
            'a task item' => ['<ul><li><input type="checkbox"> a</li><li><input type="checkbox" checked></li></ul>', "- [ ] a\n- [x] +\n"],
            'an attributed item' => ['<ul><li id="x"></li></ul>', "-{#x} +\n"],
        ];
    }

    #[DataProvider('imports')]
    public function testTheImporterWritesTheFirstBlockForm(string $html, string $carve): void
    {
        $this->assertSame($carve, (new HtmlToCarve())->convert($html));
    }

    public function testTheFastPathRendersAnEmptyOrderedItem(): void
    {
        $source = "1. a\n2. +\n3. b\n";

        $this->assertSame(CarveConverter::create()->convert($source), (new CarveConverter())->convert($source));
    }
}
