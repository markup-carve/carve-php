<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AMarkerBelowTheItemColumnAfterAPlusQuoteLeavesTheItemTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideShapes(): array
    {
        return [
            'ordered item' => [
                "1. a\n+\n> q\n  - y\n",
                "<ol>\n  <li>a\n    <blockquote><p>q</p></blockquote>\n  </li>\n</ol>\n<ul>\n  <li>y</li>\n</ul>",
            ],
            'bullet item, two quote lines' => [
                "- a\n+\n> q\n> r\n - y\n",
                "<ul>\n  <li>a\n    <blockquote><p>q\nr</p></blockquote>\n  </li>\n</ul>\n<ul>\n  <li>y</li>\n</ul>",
            ],
            'plain text stays lazy quote text' => [
                "1. a\n+\n> q\n  y\n",
                "<ol>\n  <li>a\n    <blockquote><p>q\ny</p></blockquote>\n  </li>\n</ol>",
            ],
            'a marker at the content column stays in the item' => [
                "1. a\n+\n> q\n   - y\n",
                "<ol>\n  <li>a\n    <blockquote><p>q</p></blockquote>\n    <ul>\n      <li>y</li>\n    </ul>\n  </li>\n</ol>",
            ],
        ];
    }

    #[DataProvider('provideShapes')]
    public function testMarker(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
