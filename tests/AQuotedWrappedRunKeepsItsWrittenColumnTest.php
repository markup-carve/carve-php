<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AQuotedWrappedRunKeepsItsWrittenColumnTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'nested quote' => [
            "> > {.x\n     .y}\nplain\n",
            "<blockquote>\n  <blockquote>\n\n  </blockquote>\n</blockquote>\n<p>plain</p>",
        ];

        yield 'tab before brace' => [
            "> \t{.x\n     .y}\nplain\n",
            "<blockquote>\n\n</blockquote>\n<p>plain</p>",
        ];

        yield 'spaces before brace' => [
            ">   {.x\n     .y}\nplain\n",
            "<blockquote>\n\n</blockquote>\n<p>plain</p>",
        ];

        yield 'quoted list item keeps its own indentation' => [
            "> - x\n>   {.x\n      .y}\n>   z\n",
            "<blockquote>\n  <ul>\n    <li>x\n      <p class=\"x y\">z</p>\n    </li>\n  </ul>\n</blockquote>",
        ];

        yield 'tab continuation at brace column stays literal' => [
            "> \t{.x\n    .y}\nplain\n",
            "<blockquote><p>{.x\n.y}\nplain</p></blockquote>",
        ];

        yield 'space continuation at brace column stays literal' => [
            ">   {.x\n    .y}\nplain\n",
            "<blockquote><p>{.x\n.y}\nplain</p></blockquote>",
        ];
    }

    #[DataProvider('documents')]
    public function testQuotedRunUsesWrittenSourceColumns(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
