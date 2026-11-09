<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A caret below the selected owner's content column is lazy paragraph text,
 * even when normalization places it after a captionable image (carve-php#5049).
 */
final class ALazyCaptionAfterABlockImageTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'root-column caret misses a list item' => [
            "- a\n\n  ![a](x.png)\n^ cap\n",
            "<ul>\n  <li><p>a</p>\n    <p><img src=\"x.png\" alt=\"a\">\n^ cap</p>\n  </li>\n</ul>",
        ];

        yield 'one-column caret still misses the item' => [
            "- a\n\n  ![a](x.png)\n ^ cap\n",
            "<ul>\n  <li><p>a</p>\n    <p><img src=\"x.png\" alt=\"a\">\n^ cap</p>\n  </li>\n</ul>",
        ];

        yield 'ordered marker has a wider content column' => [
            "1. a\n\n   ![a](x.png)\n^ cap\n",
            "<ol>\n  <li><p>a</p>\n    <p><img src=\"x.png\" alt=\"a\">\n^ cap</p>\n  </li>\n</ol>",
        ];

        yield 'description body keeps its lazy caret' => [
            ":: t\n: a\n\n  ![a](x.png)\n^ cap\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <p><img src=\"x.png\" alt=\"a\">\n^ cap</p>\n  </dd>\n</dl>",
        ];

        yield 'caret at the item column captions the image' => [
            "- a\n\n  ![a](x.png)\n  ^ cap\n",
            "<ul>\n  <li><p>a</p>\n    <figure>\n      <img src=\"x.png\" alt=\"a\">\n      <figcaption>cap</figcaption>\n    </figure>\n  </li>\n</ul>",
        ];
    }

    #[DataProvider('documents')]
    public function testLazyCaretIsNotPromotedToCaption(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
