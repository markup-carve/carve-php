<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A first-block `- +` at a list item's content column, below a description in
 * that item, opens an empty item: the marker ends the description rather than
 * continuing it lazily, so the `+` is the item's continuation marker and not
 * text (PART 9 section 17).
 */
class AMarkerAtAnItemsColumnEndsTheDescriptionAboveTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        $dl = "<ul>\n<li>x\n<dl>\n<dt>t</dt>\n<dd>d</dd>\n</dl>\n<ul>\n";

        return [
            'nothing attached' => ["- x\n  :: t\n  : d\n  - +\n", $dl . "<li></li>\n</ul>\n</li>\n</ul>"],
            'a block attached' => ["- x\n  :: t\n  : d\n  - +\n    y\n", $dl . "<li>y</li>\n</ul>\n</li>\n</ul>"],
            'another bullet' => ["- x\n  :: t\n  : d\n  * +\n", $dl . "<li></li>\n</ul>\n</li>\n</ul>"],
        ];
    }

    #[DataProvider('cases')]
    public function testTheMarkerOpensAnEmptyItem(string $source, string $expected): void
    {
        $html = trim(CarveConverter::create()->convert($source));

        $this->assertSame($expected, (string)preg_replace('/^ +/m', '', $html));
    }
}
