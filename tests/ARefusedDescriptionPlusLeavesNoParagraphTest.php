<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A refused `+` opens no paragraph, so the line after it is no lazy line of the description (PART 9 §17 L3).
 */
final class ARefusedDescriptionPlusLeavesNoParagraphTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'a heading after the paragraph a refused description plus leaves' => [
            ":: t\n: +\n h\n  # H\n",
            "<dl>\n  <dt>t</dt>\n  <dd></dd>\n</dl>\n<p>h</p>\n<section id=\"H\">\n  <h1>H</h1>\n</section>",
        ];

        yield 'an attribute after the paragraph a refused description plus leaves' => [
            ":: t\n: +\n h\n  {.c}\n  k\n",
            "<dl>\n  <dt>t</dt>\n  <dd></dd>\n</dl>\n<p>h</p>\n<p class=\"c\">k</p>",
        ];

        yield 'a fence after the paragraph a refused description plus leaves' => [
            ":: t\n: +\n h\n  ```\n  code\n  ```\n",
            "<dl>\n  <dt>t</dt>\n  <dd></dd>\n</dl>\n<p>h</p>\n<pre><code>code\n</code></pre>",
        ];

        yield 'a refused plus in a quoted description keeps its next line in the quote' => [
            "> :: t\n> : +\n h\n",
            "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd></dd>\n  </dl>\n  <p>h</p>\n</blockquote>",
        ];
    }

    #[DataProvider('documents')]
    public function testDocument(string $source, string $html): void
    {
        self::assertSame($html, (new CarveConverter())->convert($source));
    }
}
