<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ALineAtAPlusDescriptionsBodyColumnStaysInTheDescriptionTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideShapes(): array
    {
        return [
            'bullet item' => [
                "- a\n+\n:: term\n: d\n  # z\n",
                "<ul>\n  <li>a\n    <dl>\n      <dt>term</dt>\n      <dd>\n        <p>d</p>\n        <h1 id=\"z\">z</h1>\n      </dd>\n    </dl>\n  </li>\n</ul>",
            ],
            'ordered item, below its content column' => [
                "1. a\n+\n:: term\n: d\n  # z\n",
                "<ol>\n  <li>a\n    <dl>\n      <dt>term</dt>\n      <dd>\n        <p>d</p>\n        <h1 id=\"z\">z</h1>\n      </dd>\n    </dl>\n  </li>\n</ol>",
            ],
            'after a blank the line must reach the item too' => [
                "1. a\n+\n:: term\n: d\n\n  x\n",
                "<ol>\n  <li>a\n    <dl>\n      <dt>term</dt>\n      <dd>d</dd>\n    </dl>\n  </li>\n</ol>\n<p>x</p>",
            ],
        ];
    }

    #[DataProvider('provideShapes')]
    public function testLine(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
