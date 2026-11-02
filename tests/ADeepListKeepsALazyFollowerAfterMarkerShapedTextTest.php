<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ADeepListKeepsALazyFollowerAfterMarkerShapedTextTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'nested bullet' => [
            "1. - a\n     - y\n      1. ~~~\nz\n",
            "<ol>\n  <li>\n    <ul>\n      <li>a\n        <ul>\n          <li>y</li>\n        </ul>\n        <ol>\n          <li>\n            <pre><code></code></pre>\n          </li>\n        </ol>\n      </li>\n    </ul>\n  </li>\n</ol>\n<p>z</p>",
        ];

        yield 'ordered sibling' => [
            "1. 1. a\n    1. y\n      - ~~~\nz\n",
            "<ol>\n  <li>\n    <ol>\n      <li>a</li>\n      <li>y</li>\n    </ol>\n    <ul>\n      <li>\n        <pre><code></code></pre>\n      </li>\n    </ul>\n  </li>\n</ol>\n<p>z</p>",
        ];
    }

    #[DataProvider('documents')]
    public function testTheFollowerStaysWithTheDeepestParagraph(string $source, string $expected): void
    {
        self::assertSame($expected, trim(CarveConverter::create()->convert($source)));
    }
}
