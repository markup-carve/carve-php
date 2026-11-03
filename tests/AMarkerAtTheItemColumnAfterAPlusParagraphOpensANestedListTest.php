<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AMarkerAtTheItemColumnAfterAPlusParagraphOpensANestedListTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideShapes(): array
    {
        return [
            'bullet' => [
                "- a\n+\np\n  - y\n",
                "<ul>\n  <li>a\n    p\n    <ul>\n      <li>y</li>\n    </ul>\n  </li>\n</ul>",
            ],
            'ordered, two-line paragraph, past the column' => [
                "1. a\n+\np\nq\n   2. y\n",
                "<ol>\n  <li>a\n    p\nq\n    <ol start=\"2\">\n      <li>y</li>\n    </ol>\n  </li>\n</ol>",
            ],
            'quoted' => [
                "> - a\n> +\n> p\n>   * y\n",
                "<blockquote>\n  <ul>\n    <li>a\n      p\n      <ul>\n        <li>y</li>\n      </ul>\n    </li>\n  </ul>\n</blockquote>",
            ],
            'below the column stays a sibling' => [
                "- a\n+\np\n - y\n",
                "<ul>\n  <li>a\n    p\n  </li>\n  <li>y</li>\n</ul>",
            ],
        ];
    }

    #[DataProvider('provideShapes')]
    public function testMarker(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
