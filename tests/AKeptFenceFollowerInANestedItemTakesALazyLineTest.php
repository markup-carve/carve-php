<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A refused brace run's kept fence-shaped follower is paragraph text in a nested item, so a lazy line joins it (CARVE-P2-035, PART 0 S4).
 */
final class AKeptFenceFollowerInANestedItemTakesALazyLineTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function examples(): iterable
    {
        yield 'carve#5596: a lazy line after the kept fence follower in a nested item' => [
            "- - +\n    {.x\n    .y}\n    ```\np\n",
            "<ul>\n  <li>\n    <ul>\n      <li>{.x\n.y}\n<code>\np</code></li>\n    </ul>\n  </li>\n</ul>",
        ];

        yield 'two lazy lines join the open code span' => [
            "- - +\n    {.x\n    .y}\n    ```\np\nq\n",
            "<ul>\n  <li>\n    <ul>\n      <li>{.x\n.y}\n<code>\np\nq</code></li>\n    </ul>\n  </li>\n</ul>",
        ];

        yield 'a three-level item keeps the lazy line too' => [
            "- - - +\n      {.x\n      .y}\n      ~~~\np\n",
            "<ul>\n  <li>\n    <ul>\n      <li>\n        <ul>\n          <li>{.x\n.y}\n~~~\np</li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>",
        ];

        yield 'a kept closer ends the span before the lazy line' => [
            "1. 1. +\n      {k=1\n      j=2}\n      ```\np\n```\n",
            "<ol>\n  <li>\n    <ol>\n      <li>{k=1\nj=2}\n<code>\np</code></li>\n    </ol>\n  </li>\n</ol>\n<pre><code></code></pre>",
        ];
    }

    #[DataProvider('examples')]
    public function testTheLazyLineJoinsTheKeptParagraph(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
