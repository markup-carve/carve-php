<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class AFirstBlockDescriptionInAnItemTakesItsListTest extends TestCase
{
    public function testNestedDescriptionTakesItsFirstBlock(): void
    {
        $source = "- x\n  :: t\n  : +\n  - li\n";
        $expected = "<ul>\n  <li>x\n    <dl>\n      <dt>t</dt>\n      <dd>\n        <ul>\n          <li>li</li>\n        </ul>\n      </dd>\n    </dl>\n  </li>\n</ul>";

        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
