<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ANestedAttachedFenceEndsBeforeOuterParagraphTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function examples(): iterable
    {
        yield 'corpus 0967' => [
            "- - +\n  ```\n\n  tail\n    ```\n",
            "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code></code></pre>\n      </li>\n    </ul>\n    <p>tail\n<code></code></p>\n  </li>\n</ul>",
        ];

        yield 'tilde fence and shifted columns' => [
            "1. - +\n   ~~~\n\n   tail\n     ~~~\n",
            "<ol>\n  <li>\n    <ul>\n      <li>\n        <pre><code></code></pre>\n      </li>\n    </ul>\n    <p>tail\n~~~</p>\n  </li>\n</ol>",
        ];

        yield 'the following document paragraph stays outside the item' => [
            "- - +\n  ```\n\n  tail\n    ```\nafter\n",
            "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code></code></pre>\n      </li>\n    </ul>\n    <p>tail\n<code></code></p>\n  </li>\n</ul>\n<p>after</p>",
        ];
    }

    #[DataProvider('examples')]
    public function testTheOuterParagraphOwnsTheLaterFenceShapedLine(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
