<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An image on its own line below a description's body column is a lazy line
 * (PART 1 S4): a lone image is a block only when it is a whole paragraph
 * (§15), so it does not interrupt the open paragraph and folds into it.
 */
class ALazyImageLineStaysInTheDescriptionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'image below the body column' => [
            ":: t\n: a\n ![i](u)\n",
            "<dl>\n  <dt>t</dt>\n  <dd>a\n<img src=\"u\" alt=\"i\"></dd>\n</dl>",
        ];

        yield 'image with a title, then a lazy line' => [
            ":: t\n: a\n ![i](u \"t\")\nx\n",
            "<dl>\n  <dt>t</dt>\n  <dd>a\n<img src=\"u\" alt=\"i\" title=\"t\">\nx</dd>\n</dl>",
        ];

        yield 'image after a second body line' => [
            ":: t\n: a\n  b\n ![i](u)\n",
            "<dl>\n  <dt>t</dt>\n  <dd>a\nb\n<img src=\"u\" alt=\"i\"></dd>\n</dl>",
        ];

        yield 'image in a quoted description' => [
            "> :: t\n> : a\n>  ![i](u)\n",
            "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>a\n<img src=\"u\" alt=\"i\"></dd>\n  </dl>\n</blockquote>",
        ];

        yield 'image below a list in the description' => [
            ":: t\n: - a\n ![i](u)\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>a\n<img src=\"u\" alt=\"i\"></li>\n    </ul>\n  </dd>\n</dl>",
        ];

        yield 'image before a reference definition' => [
            ":: t\n: a\n ![i](u)\n[r]: /v\n",
            "<dl>\n  <dt>t</dt>\n  <dd>a\n<img src=\"u\" alt=\"i\"></dd>\n</dl>",
        ];
    }

    #[DataProvider('cases')]
    public function testTheImageFoldsIntoTheDescription(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    #[DataProvider('cases')]
    public function testTheFormattedTextKeepsTheTree(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert(CarveConverter::toCarve($source))));
    }
}
