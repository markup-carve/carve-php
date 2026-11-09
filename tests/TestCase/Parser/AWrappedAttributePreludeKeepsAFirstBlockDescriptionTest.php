<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AWrappedAttributePreludeKeepsAFirstBlockDescriptionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'list after wrapped attribute' => [
            ":: t\n: +\n{.x\n .y}\n- li\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul class=\"x y\">\n      <li>li</li>\n    </ul>\n  </dd>\n</dl>",
        ];

        yield 'one-line attribute control' => [
            ":: t\n: +\n{.x .y}\n- li\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul class=\"x y\">\n      <li>li</li>\n    </ul>\n  </dd>\n</dl>",
        ];
    }

    #[DataProvider('cases')]
    public function testWrappedAttributeLeadsTheAttachedBlock(string $source, string $html): void
    {
        self::assertSame($html, trim((new CarveConverter())->convert($source)));
    }
}
