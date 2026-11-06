<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AColonAfterAnItemTermAndStanzaTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'term followed by a stanza' => [
            "- a\n  :: t\n\n  ::: |\n    prose\n: z\n",
            "<ul>\n  <li><p>a</p>\n    <dl>\n      <dt>t</dt>\n    </dl>\n    <div class=\"line-block\">\n      <p>&nbsp;&nbsp;prose</p>\n    </div>\n  </li>\n</ul>\n<p>: z</p>",
        ];

        yield 'nested item at its own marker column' => [
            "- - a\n    :: t\n    ::: |\n      prose\n  : z\n",
            "<ul>\n  <li>\n    <ul>\n      <li>a\n        <dl>\n          <dt>t</dt>\n        </dl>\n        <div class=\"line-block\">\n          <p>&nbsp;&nbsp;prose</p>\n        </div>\n      </li>\n    </ul>\n    : z\n  </li>\n</ul>",
        ];

        yield 'quoted paragraph retains its lazy line' => [
            "- a\n  :: t\n  > prose\n: z\n",
            "<ul>\n  <li>a\n    <dl>\n      <dt>t</dt>\n    </dl>\n    <blockquote><p>prose\n: z</p></blockquote>\n  </li>\n</ul>",
        ];

        yield 'literal term in a code fence does not count' => [
            "- a\n  ```\n  :: t\n  ```\n  b\n: z\n",
            "<ul>\n  <li>a\n    <pre><code>:: t\n</code></pre>\n    b\n: z\n  </li>\n</ul>",
        ];
    }

    #[DataProvider('documents')]
    public function testRender(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
