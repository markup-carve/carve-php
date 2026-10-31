<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An invisible line below a refused nested `+` closes the paragraph the `+` folded into (PART 9 §10 I5).
 */
class AnInvisibleLineAfterARefusedNestedPlusTest extends TestCase
{
    use RuleATodo;

    #[DataProvider('cases')]
    public function testInvisibleLineAfterRefusedPlus(string $source, string $expected): void
    {
        $this->assertSame($expected, rtrim((new CarveConverter())->convert($source), "\n"));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'attribute-before-fence' => [
                "- - ```\n  +\n{.c}\n~~~\ny\n~~~\n",
                "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code></code></pre>\n      </li>\n    </ul>\n    +\n  </li>\n</ul>\n<pre class=\"c\"><code>y\n</code></pre>",
            ],
            'attribute-after-paragraph-plus' => [
                "- - b\n  +\n{.c}\npara\n",
                "<ul>\n  <li>\n    <ul>\n      <li>b\n+</li>\n    </ul>\n  </li>\n</ul>\n<p class=\"c\">para</p>",
            ],
            'attribute-after-blank-plus' => [
                "- - b\n\n  +\n{.c}\npara\n",
                "<ul>\n  <li>\n    <ul>\n      <li>b</li>\n    </ul>\n    <p>+</p>\n  </li>\n</ul>\n<p class=\"c\">para</p>",
            ],
            'reference-definition' => [
                "- - ```\n  +\n[r]: /u\npara [x][r]\n",
                "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code></code></pre>\n      </li>\n    </ul>\n    +\n  </li>\n</ul>\n<p>para <a href=\"/u\">x</a></p>",
            ],
            'footnote-definition' => [
                "- - b\n  +\n[^n]: note\npara[^n]\n",
                "<ul>\n  <li>\n    <ul>\n      <li>b\n+</li>\n    </ul>\n  </li>\n</ul>\n<p>para<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>note<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
            ],
            'lazy-text-still-folds' => [
                "- - ```\n  +\nlazy\n",
                "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code></code></pre>\n      </li>\n    </ul>\n    +\nlazy\n  </li>\n</ul>",
            ],
            'comment-still-folds' => [
                "- - ```\n  +\n%% c\npara\n",
                "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code></code></pre>\n      </li>\n    </ul>\n    +\n  </li>\n</ul>\n<p>para</p>",
            ],
        ];
    }
}
