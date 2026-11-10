<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#5756: a block `{%` run whose continuations all pass its `{` holds them, whatever block they spell (CARVE-P2-035).
 */
final class ABlockCommentRunHoldsBlockShapedContinuationsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'heading at the root' => ["{% a\n # h\n z %}\n", ''];
        yield 'fence after a paragraph line' => ["p\n{% a\n ```\n z %} y\n", "<p>p\n y</p>"];
        yield 'heading in an item paragraph' => ["- p\n  {% a\n   # h\n   z %}\n", "<ul>\n  <li>p\n</li>\n</ul>"];
        yield 'list marker in an item' => ["- {% a\n   - li\n   z %}\n", "<ul>\n  <li></li>\n</ul>"];
        yield 'fence in an item keeps the lazy line' => ["- {% a\n   ```\n   z %}\nplain\n", "<ul>\n  <li>\nplain</li>\n</ul>"];
        yield 'quote line in a quote' => ["> {% a\n>  > q\n>  z %} y\n", '<blockquote><p> y</p></blockquote>'];
        yield 'line block in a description' => [":: t\n: {% a\n   ::: |\n   z %}\n", "<dl>\n  <dt>t</dt>\n  <dd></dd>\n</dl>"];
        yield 'table row in an ordered item' => ["1. {% a\n    | t |\n    z %} y\n", "<ol>\n  <li> y</li>\n</ol>"];
        yield 'fence in a quoted item keeps the lazy line' => [
            "> - {% a\n>    ~~~\n>    z %}\nplain\n",
            "<blockquote>\n  <ul>\n    <li>\nplain</li>\n  </ul>\n</blockquote>",
        ];

        yield 'a continuation at the brace refuses the run' => ["- p\n  {% a\n  # h\n  z %}\n", "<ul>\n  <li>p\n{% a\n    <h1 id=\"h\">h</h1>\n    z %}\n  </li>\n</ul>"];
    }

    #[DataProvider('documents')]
    public function testTheRunHoldsItsLines(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
