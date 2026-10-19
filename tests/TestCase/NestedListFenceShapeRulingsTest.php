<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NestedListFenceShapeRulingsTest extends TestCase
{
    #[DataProvider('cases')]
    public function testNestedFenceShape(string $source, string $expected): void
    {
        $this->assertSame($expected, rtrim((new CarveConverter())->convert($source), "\n"));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        $plus = "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code>x\n\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>tail</p>";
        $deepPlus = "<ul>\n  <li>\n    <ul>\n      <li>\n        <ul>\n          <li>\n            <pre><code>x\n\n</code></pre>\n          </li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>tail</p>";
        $deepAttach = "<ul>\n  <li>\n    <ul>\n      <li>\n        <ul>\n          <li>\n            <pre><code>x\n</code></pre>\n            tail\n          </li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>";
        $emptyPlus = "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code>\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>tail</p>";

        $div2 = "<ul>\n  <li>\n    <ul>\n      <li>\n        <div class=\"d\">\n\n        </div>\n        tail\n      </li>\n    </ul>\n  </li>\n</ul>";
        $div3 = "<ul>\n  <li>\n    <ul>\n      <li>\n        <ul>\n          <li>\n            <div class=\"d\">\n\n            </div>\n            tail\n          </li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>";
        $div2Content = str_replace("\n\n        </div>", "\n          <p>x</p>\n        </div>", $div2);
        $div3Content = str_replace("\n\n            </div>", "\n              <p>x</p>\n            </div>", $div3);

        return [
            'div-depth-two-empty' => ["- - ::: d\n  +\ntail", $div2],
            'div-depth-three-empty' => ["- - - ::: d\n    +\ntail", $div3],
            'div-depth-two-content' => ["- - ::: d\n    x\n  +\ntail", $div2Content],
            'div-depth-three-content' => ["- - - ::: d\n      x\n    +\ntail", $div3Content],
            'div-depth-two-content-blank' => ["- - ::: d\n    x\n\n  +\ntail", $div2Content],
            'div-depth-three-content-blank' => ["- - - ::: d\n      x\n\n    +\ntail", $div3Content],
            'refused-plus-keeps-blank' => ["- - ```\n    x\n  +\n\ntail\n", $plus],
            'third-level-refused-plus-keeps-blank' => ["- - - ```\n      x\n    +\n\ntail\n", $deepPlus],
            'refused-plus-keeps-blank-without-payload' => ["- - ```\n  +\n\ntail\n", $emptyPlus],
            'third-level-plus-attaches' => ["- - - ```\n      x\n    +\ntail\n", $deepAttach],
        ];
    }
}
