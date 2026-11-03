<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An attribute and fence at a nested lead item's content column stay in that item (PART 0 S2). Expected HTML is the spec model's.
 */
final class AnAttributeAtANestedItemsColumnStaysWithItsFenceTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'reproduction' => [":: t\n: x\n\n  * - - a\n          {.c}\n        ``` x\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>x</p>\n    <ul>\n      <li>\n        <ul>\n          <li>\n            <ul>\n              <li>a\n                <pre class=\"c\"><code class=\"language-x\"></code></pre>\n              </li>\n            </ul>\n          </li>\n        </ul>\n      </li>\n    </ul>\n  </dd>\n</dl>"];
        yield 'inner item column, top level' => ["- - a\n    {.c}\n    ``` x\n", "<ul>\n  <li>\n    <ul>\n      <li>a\n        <pre class=\"c\"><code class=\"language-x\"></code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>"];
        yield 'closed fence' => ["- - a\n    {.c}\n    ```\n    x\n    ```\n", "<ul>\n  <li>\n    <ul>\n      <li>a\n        <pre class=\"c\"><code>x\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>"];
        yield 'overindented attribute' => ["- - a\n      {.c}\n    ``` x\n", "<ul>\n  <li>\n    <ul>\n      <li>a\n        <pre class=\"c\"><code class=\"language-x\"></code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>"];
        yield "outer item column stays the outer item's" => ["- - a\n  {.c}\n  ``` x\n", "<ul>\n  <li>\n    <ul>\n      <li>a</li>\n    </ul>\n    <pre class=\"c\"><code class=\"language-x\"></code></pre>\n  </li>\n</ul>"];
        yield 'note marker-line chain' => ["[^n]: - - a\n          {.c}\n          ```\n          x\n          ```\n\nx[^n]\n", "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <ul>\n        <li>\n          <ul>\n            <li>a\n              <pre class=\"c\"><code>x\n</code></pre>\n            </li>\n          </ul>\n        </li>\n      </ul>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"];
    }

    #[DataProvider('documents')]
    public function testTheFenceStaysInTheNestedItem(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
