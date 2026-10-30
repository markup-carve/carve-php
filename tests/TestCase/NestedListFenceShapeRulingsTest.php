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
        // The column-0 run is a valid opener and runs to the end (carve#4032).
        $laterAttributePrefix = "<ul>\n  <li>outer\n    <ul>\n      <li>head\n        <pre><code>a\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>\n<pre><code>\n  tail\n";
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
                $laterAttributePrefix . "- next\n\n  {.x}\n  para</code></pre>",
            ],
            'third-sibling-attribute' => [
                $laterAttributeSource . "- next\n- third\n\n  {.x}\n  para",
                $laterAttributePrefix . "- next\n- third\n\n  {.x}\n  para</code></pre>",
            ],
            'later-paragraph-attribute' => [
                $laterAttributeSource . "- next\n\n  para\n\n  {.x}\n  after",
                $laterAttributePrefix . "- next\n\n  para\n\n  {.x}\n  after</code></pre>",
            ],
            'same-item-attribute-after-tail' => [
                $laterAttributeSource . "\n  {.x}\n  para",
                $laterAttributePrefix . "\n  {.x}\n  para</code></pre>",
            ],
        ];
    }
}
