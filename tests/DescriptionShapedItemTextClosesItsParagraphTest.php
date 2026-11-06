<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DescriptionShapedItemTextClosesItsParagraphTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'nested term' => [': :: |', "<ul>\n  <li>u\n: :: |</li>\n</ul>\n<p>b</p>"];
        yield 'nested div' => [': ::: |', "<ul>\n  <li>u\n: ::: |</li>\n</ul>\n<p>b</p>"];
        yield 'plain text' => [': x', "<ul>\n  <li>u\n: x\nb</li>\n</ul>"];
    }

    #[DataProvider('cases')]
    public function testTheBodyFormDecidesWhetherTheItemKeepsALazyLine(string $body, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert("- u\n  $body\nb\n")));
    }
}
