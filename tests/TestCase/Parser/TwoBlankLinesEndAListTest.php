<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TwoBlankLinesEndAListTest extends TestCase
{
    public function testOneBlankLineStillMakesOneLooseList(): void
    {
        $this->assertSame(
            "<ol>\n  <li><p>a</p></li>\n  <li><p>b</p></li>\n</ol>\n",
            (new CarveConverter())->convert("1. a\n\n2. b\n"),
        );
    }

    #[DataProvider('compatibleLists')]
    public function testTwoBlankLinesMakeTwoLists(string $source, string $html): void
    {
        $converter = new CarveConverter();
        $this->assertSame($html, $converter->convert($source));
        $this->assertSame($source, CarveConverter::toCarve($source));
        $this->assertSame($source, CarveConverter::toCarve(CarveConverter::toCarve($source)));
    }

    /**
     * Compatible ordered and bullet list cases.
     *
     * @return array<string, array{string, string}>
     */
    public static function compatibleLists(): array
    {
        return [
            'ordered' => [
                "1. a\n\n\n2. b\n",
                "<ol>\n  <li>a</li>\n</ol>\n<ol start=\"2\">\n  <li>b</li>\n</ol>\n",
            ],
            'bullet' => [
                "- a\n\n\n- b\n",
                "<ul>\n  <li>a</li>\n</ul>\n<ul>\n  <li>b</li>\n</ul>\n",
            ],
        ];
    }
}
