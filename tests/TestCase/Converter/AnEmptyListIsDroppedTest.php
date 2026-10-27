<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#2367: a list with no item has no spelling, so it is
 * dropped with one row that covers its attributes too.
 */
class AnEmptyListIsDroppedTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function shapes(): array
    {
        return [
            'attributed, in a container' => [
                '<div class="m"><ul class="vector-menu-content-list"></ul></div><p>after</p>',
                "::: m\n\n:::\n\nafter\n",
                '/div[1]/ul[1]',
            ],
            'bare' => ['<ul></ul><p>a</p>', "a\n", '/ul[1]'],
            'ordered with a start' => ['<ol id="o" start="3"></ol><p>a</p>', "a\n", '/ol[1]'],
            'nested in an item' => ['<ul><li>a<ul class="n"></ul></li></ul>', "- a\n", '/ul[1]/li[1]/ul[2]'],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheListIsDroppedWithOneRow(string $html, string $carve, string $path): void
    {
        $result = (new HtmlToCarve())->convertWithReport($html);

        $this->assertSame($carve, $result->value);
        $this->assertSame($carve, (new CarveConverter())->toCarve($carve));
        $rows = $result->report()['diagnostics'];
        $this->assertSame(['element-dropped'], array_column($rows, 'code'));
        $this->assertSame('warning', $rows[0]['severity']);
        $this->assertSame($path, $rows[0]['path']);
    }
}
