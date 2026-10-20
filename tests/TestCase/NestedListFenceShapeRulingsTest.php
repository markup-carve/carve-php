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
        $plus = "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code>x\n</code></pre>\n      </li>\n    </ul>\n    +\n  </li>\n</ul>\n<p>tail</p>";
        $deepPlus = "<ul>\n  <li>\n    <ul>\n      <li>\n        <ul>\n          <li>\n            <pre><code>x\n</code></pre>\n          </li>\n        </ul>\n        +\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>tail</p>";
        $deepAttach = "<ul>\n  <li>\n    <ul>\n      <li>\n        <ul>\n          <li>\n            <pre><code>x\n</code></pre>\n          </li>\n        </ul>\n        +\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>tail</p>";
        $emptyPlus = "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code></code></pre>\n      </li>\n    </ul>\n    +\n  </li>\n</ul>\n<p>tail</p>";
        $laterAttributePrefix = "<ul>\n  <li><p>outer</p>\n    <ul>\n      <li>head\n<code>\na\n</code></li>\n    </ul>\n    <p>tail</p>\n  </li>\n";
        $laterAttributeSource = "- outer\n  - head\n     ```\n     a\n```\n\n  tail\n";

        $div2 = "<ul>\n  <li>\n    <ul>\n      <li>\n        <div class=\"d\">\n\n        </div>\n      </li>\n    </ul>\n    +\ntail\n  </li>\n</ul>";
        $div3 = "<ul>\n  <li>\n    <ul>\n      <li>\n        <ul>\n          <li>\n            <div class=\"d\">\n\n            </div>\n          </li>\n        </ul>\n        +\ntail\n      </li>\n    </ul>\n  </li>\n</ul>";
        $div2Content = "<ul>\n  <li>\n    <ul>\n      <li>\n        <div class=\"d\">\n          <p>x\n+\ntail</p>\n        </div>\n      </li>\n    </ul>\n  </li>\n</ul>";
        $div3Content = "<ul>\n  <li>\n    <ul>\n      <li>\n        <ul>\n          <li>\n            <div class=\"d\">\n              <p>x\n+\ntail</p>\n            </div>\n          </li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>";
        $div2ContentBlank = "<ul>\n  <li>\n    <ul>\n      <li>\n        <div class=\"d\">\n          <p>x</p>\n        </div>\n      </li>\n    </ul>\n    <p>+\ntail</p>\n  </li>\n</ul>";
        $div3ContentBlank = "<ul>\n  <li>\n    <ul>\n      <li>\n        <ul>\n          <li>\n            <div class=\"d\">\n              <p>x</p>\n            </div>\n          </li>\n        </ul>\n        <p>+\ntail</p>\n      </li>\n    </ul>\n  </li>\n</ul>";

        return [
            'div-depth-two-empty' => ["- - ::: d\n  +\ntail", $div2],
            'div-depth-three-empty' => ["- - - ::: d\n    +\ntail", $div3],
            'div-depth-two-content' => ["- - ::: d\n    x\n  +\ntail", $div2Content],
            'div-depth-three-content' => ["- - - ::: d\n      x\n    +\ntail", $div3Content],
            'div-depth-two-content-blank' => ["- - ::: d\n    x\n\n  +\ntail", $div2ContentBlank],
            'div-depth-three-content-blank' => ["- - - ::: d\n      x\n\n    +\ntail", $div3ContentBlank],
            'refused-plus-keeps-blank' => ["- - ```\n    x\n  +\n\ntail\n", $plus],
            'third-level-refused-plus-keeps-blank' => ["- - - ```\n      x\n    +\n\ntail\n", $deepPlus],
            'refused-plus-keeps-blank-without-payload' => ["- - ```\n  +\n\ntail\n", $emptyPlus],
            'third-level-plus-attaches' => ["- - - ```\n      x\n    +\ntail\n", $deepAttach],
            'later-sibling-attribute' => [
                $laterAttributeSource . "- next\n\n  {.x}\n  para",
                $laterAttributePrefix . "  <li><p>next</p>\n    <p class=\"x\">para</p>\n  </li>\n</ul>",
            ],
            'third-sibling-attribute' => [
                $laterAttributeSource . "- next\n- third\n\n  {.x}\n  para",
                $laterAttributePrefix . "  <li><p>next</p></li>\n  <li><p>third</p>\n    <p class=\"x\">para</p>\n  </li>\n</ul>",
            ],
            'later-paragraph-attribute' => [
                $laterAttributeSource . "- next\n\n  para\n\n  {.x}\n  after",
                $laterAttributePrefix . "  <li><p>next</p>\n    <p>para</p>\n    <p class=\"x\">after</p>\n  </li>\n</ul>",
            ],
            'same-item-attribute-after-tail' => [
                $laterAttributeSource . "\n  {.x}\n  para",
                "<ul>\n  <li><p>outer</p>\n    <ul>\n      <li>head\n<code>\na\n</code></li>\n    </ul>\n    <p>tail</p>\n    <p class=\"x\">para</p>\n  </li>\n</ul>",
            ],
        ];
    }
}
