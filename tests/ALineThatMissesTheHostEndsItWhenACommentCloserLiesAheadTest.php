<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#4770 (ruled B): with an exact-width closer ahead, a body line that misses the span's owner
 * ends the span and that owner at every depth (PART 0 S1, corpus 872-8 and 929).
 */
class ALineThatMissesTheHostEndsItWhenACommentCloserLiesAheadTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        return [
            'nested item, line at column 0 (929)' => [
                ". a\n  . %%%\n    n\n>>\n%%%\n",
                "<ol>\n  <li>a\n    <ol>\n      <li>n</li>\n    </ol>\n  </li>\n</ol>\n<p>&gt;&gt;</p>",
            ],
            'nested item, line in the outer item' => [
                ". a\n  . %%%\n    n\n  zz\n%%%\n",
                "<ol>\n  <li>a\n    <ol>\n      <li>n</li>\n    </ol>\n    zz\n  </li>\n</ol>",
            ],
            'nested item without a closer folds as after %%' => [
                ". a\n  . %%%\n    n\n  zz\n",
                "<ol>\n  <li>a\n    <ol>\n      <li>n\nzz</li>\n    </ol>\n  </li>\n</ol>",
            ],
            'description in an item keeps its payload' => [
                "- a\n  :: t\n  : b\n    %%%\n    n\n  >>\n  %%%\n",
                "<ul>\n  <li>a\n    <dl>\n      <dt>t</dt>\n      <dd>\n        <p>b</p>\n        <p>n</p>\n      </dd>\n    </dl>\n    &gt;&gt;\n  </li>\n</ul>",
            ],
            'description in a quote keeps its payload' => [
                "> :: t\n> : b\n>   %%%\n>   n\n> >>\n> %%%\n",
                "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>\n      <p>b</p>\n      <p>n</p>\n    </dd>\n  </dl>\n  <p>&gt;&gt;</p>\n</blockquote>",
            ],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheMissedLineEndsTheHost(string $source, string $expected): void
    {
        self::assertSame($expected, CarveConverter::create()->convert($source));
    }
}
