<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#5339: a lazy line after the paragraph that follows an unclosed nested fence's blank folds into that paragraph.
 */
final class ALazyLineAfterAnUnclosedNestedFenceFoldsTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideShapes(): array
    {
        return [
            'a + attached fence in a nested item' => [
                "- a\n  - +\n  ```\n\n    p\ntail\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>\n        <pre><code></code></pre>\n        <p>p\ntail</p>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'an unclosed fence two items deep' => [
                "1. a\n   * a\n     * b\n       ~~~\n       code\n\n     p\ntail\n",
                "<ol>\n  <li>a\n    <ul>\n      <li><p>a</p>\n        <ul>\n          <li>b\n            <pre><code>code\n\n</code></pre>\n          </li>\n        </ul>\n        <p>p\ntail</p>\n      </li>\n    </ul>\n  </li>\n</ol>",
            ],
            'an unclosed fence after a + two items deep' => [
                "1. a\n   * a\n     * +\n       ~~~\n       code\n\n     p\ntail\n",
                "<ol>\n  <li>a\n    <ul>\n      <li><p>a</p>\n        <ul>\n          <li>\n            <pre><code>code\n\n</code></pre>\n          </li>\n        </ul>\n        <p>p\ntail</p>\n      </li>\n    </ul>\n  </li>\n</ol>",
            ],
            'a lazy line at column 1' => [
                "1. b c\n   * a\n     1. b c\n        ~~~\n\n     p q\n tail\n",
                "<ol>\n  <li>b c\n    <ul>\n      <li><p>a</p>\n        <ol>\n          <li>b c\n            <pre><code>\n</code></pre>\n          </li>\n        </ol>\n        <p>p q\ntail</p>\n      </li>\n    </ul>\n  </li>\n</ol>",
            ],
        ];
    }

    #[DataProvider('provideShapes')]
    public function testFold(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
