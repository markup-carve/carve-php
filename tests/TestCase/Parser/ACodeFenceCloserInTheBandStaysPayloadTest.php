<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A fence's closer is measured from the authored base its opener establishes
 * (CARVE-P0-004) and from the container's content column, and from nothing
 * between them. A run in that band reaches neither, so it stays payload and
 * keeps what sits past the content column.
 *
 * Expectations come from the executable spec - scripts/spec/layout.mjs plus
 * scripts/spec/html.mjs in markup-carve/carve at 97e47ae2, the commit
 * tests/spec is pinned to.
 */
class ACodeFenceCloserInTheBandStaysPayloadTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function documents(): array
    {
        return [
            'M7 item cc 2 opener 6 closer 4 band' => [
                "- item\n\n      ```\n      a\n    ```\n\n  tail\n",
                "<ul>\n  <li>item\n    <pre><code>a\n  ```\n\ntail\n</code></pre>\n  </li>\n</ul>\n",
            ],
            'M11 footnote body cc 2 opener 6 closer 4 band' => [
                "x[^1]\n\n[^1]: note\n\n      ```\n      a\n    ```\n\n  tail\n",
                "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>note</p>\n      <pre><code>a\n  ```\n\ntail\n</code></pre>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>\n",
            ],
            'M13 footnote body cc 2 opener 8 closer 5 band' => [
                "x[^1]\n\n[^1]: note\n\n        ```\n        a\n     ```\n\n  tail\n",
                "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>note</p>\n      <pre><code>a\n   ```\n\ntail\n</code></pre>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>\n",
            ],
            'M16 nested item cc 4 opener 8 closer 6 band' => [
                "- a\n  - b\n\n        ```\n        a\n      ```\n\n    tail\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <pre><code>a\n  ```\n\ntail\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>\n",
            ],
            'M19 description cc 3 opener 7 closer 5 band' => [
                ":: term\n:  desc\n\n       ```\n       a\n     ```\n\n   tail\n",
                "<dl>\n  <dt>term</dt>\n  <dd>\n    <p>desc</p>\n    <pre><code>a\n  ```\n\ntail\n</code></pre>\n  </dd>\n</dl>\n",
            ],
            'M22 item in a quote cc 2 opener 6 closer 4 band' => [
                "> - item\n>\n>       ```\n>       a\n>     ```\n>\n>   tail\n",
                "<blockquote>\n  <ul>\n    <li>item\n      <pre><code>a\n  ```\n\ntail\n</code></pre>\n    </li>\n  </ul>\n</blockquote>\n",
            ],
            'M1 top level opener 0 closer 0' => [
                "\n```\na\n```\n\ntail\n",
                "<pre><code>a\n</code></pre>\n<p>tail</p>\n",
            ],
            'M4 item opener 6 closer 6' => [
                "- item\n\n      ```\n      a\n      ```\n\n  tail\n",
                "<ul>\n  <li><p>item</p>\n    <pre><code>a\n</code></pre>\n    <p>tail</p>\n  </li>\n</ul>\n",
            ],
            'M5 item opener 4 closer 4' => [
                "- item\n\n    ```\n    a\n    ```\n\n  tail\n",
                "<ul>\n  <li><p>item</p>\n    <pre><code>a\n</code></pre>\n    <p>tail</p>\n  </li>\n</ul>\n",
            ],
            'M9 footnote body opener 6 closer 6' => [
                "x[^1]\n\n[^1]: note\n\n      ```\n      a\n      ```\n\n  tail\n",
                "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>note</p>\n      <pre><code>a\n</code></pre>\n      <p>tail<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>\n",
            ],
            'M10 footnote body opener 8 closer 8' => [
                "x[^1]\n\n[^1]: note\n\n        ```\n        a\n        ```\n\n  tail\n",
                "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>note</p>\n      <pre><code>a\n</code></pre>\n      <p>tail<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>\n",
            ],
            'M15 nested item opener 8 closer 8' => [
                "- a\n  - b\n\n        ```\n        a\n        ```\n\n    tail\n",
                "<ul>\n  <li>a\n    <ul>\n      <li><p>b</p>\n        <pre><code>a\n</code></pre>\n        <p>tail</p>\n      </li>\n    </ul>\n  </li>\n</ul>\n",
            ],
            'M18 description opener 7 closer 7' => [
                ":: term\n:  desc\n\n       ```\n       a\n       ```\n\n   tail\n",
                "<dl>\n  <dt>term</dt>\n  <dd>\n    <p>desc</p>\n    <pre><code>a\n</code></pre>\n    <p>tail</p>\n  </dd>\n</dl>\n",
            ],
            'M21 item in a quote opener 6 closer 6' => [
                "> - item\n>\n>       ```\n>       a\n>       ```\n>\n>   tail\n",
                "<blockquote>\n  <ul>\n    <li><p>item</p>\n      <pre><code>a\n</code></pre>\n      <p>tail</p>\n    </li>\n  </ul>\n</blockquote>\n",
            ],
            'M6 item opener 6 closer 2 at the content column' => [
                "- item\n\n      ```\n      a\n  ```\n\n  tail\n",
                "<ul>\n  <li><p>item</p>\n    <pre><code>a\n</code></pre>\n    <p>tail</p>\n  </li>\n</ul>\n",
            ],
            'M12 footnote body opener 6 closer 2 at the content column' => [
                "x[^1]\n\n[^1]: note\n\n      ```\n      a\n  ```\n\n  tail\n",
                "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>note</p>\n      <pre><code>a\n</code></pre>\n      <p>tail<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>\n",
            ],
            'M17 nested item opener 8 closer 4 at the content column' => [
                "- a\n  - b\n\n        ```\n        a\n    ```\n\n    tail\n",
                "<ul>\n  <li>a\n    <ul>\n      <li><p>b</p>\n        <pre><code>a\n</code></pre>\n        <p>tail</p>\n      </li>\n    </ul>\n  </li>\n</ul>\n",
            ],
            'M20 description opener 5 closer 3 at the content column' => [
                ":: term\n:  desc\n\n     ```\n     a\n   ```\n\n   tail\n",
                "<dl>\n  <dt>term</dt>\n  <dd>\n    <p>desc</p>\n    <pre><code>a\n</code></pre>\n    <p>tail</p>\n  </dd>\n</dl>\n",
            ],
            'M23 item in a quote opener 6 closer 2 at the content column' => [
                "> - item\n>\n>       ```\n>       a\n>   ```\n>\n>   tail\n",
                "<blockquote>\n  <ul>\n    <li><p>item</p>\n      <pre><code>a\n</code></pre>\n      <p>tail</p>\n    </li>\n  </ul>\n</blockquote>\n",
            ],
            'M2 top level opener 0 closer 2 past the base' => [
                "\n```\na\n  ```\n\ntail\n",
                "<pre><code>a\n  ```\n\ntail\n</code></pre>\n",
            ],
            'M8 item opener 6 closer 8 past the base' => [
                "- item\n\n      ```\n      a\n        ```\n\n  tail\n",
                "<ul>\n  <li>item\n    <pre><code>a\n  ```\n\ntail\n</code></pre>\n  </li>\n</ul>\n",
            ],
            'M14 footnote body opener 6 closer 8 past the base' => [
                "x[^1]\n\n[^1]: note\n\n      ```\n      a\n        ```\n\n  tail\n",
                "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>note</p>\n      <pre><code>a\n  ```\n\ntail\n</code></pre>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>\n",
            ],
            'M24 footnote body opener 6 unterminated' => [
                "x[^1]\n\n[^1]: note\n\n      ```\n      a\n\n  tail\n",
                "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>note</p>\n      <pre><code>a\n\ntail\n</code></pre>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>\n",
            ],
            'M25 item opener 6 unterminated' => [
                "- item\n\n      ```\n      a\n\n  tail\n",
                "<ul>\n  <li>item\n    <pre><code>a\n\ntail\n</code></pre>\n  </li>\n</ul>\n",
            ],
            'G7 item opener 6 a payload line at 4 in the band' => [
                "- item\n\n      ```\n      a\n    z\n      b\n      ```\n",
                "<ul>\n  <li>item\n    <pre><code>a\n  z\nb\n</code></pre>\n  </li>\n</ul>\n",
            ],
            'G9 item opener 6 a payload line at the content column' => [
                "- item\n\n      ```\n      a\n  z\n      b\n      ```\n",
                "<ul>\n  <li>item\n    <pre><code>a\nz\nb\n</code></pre>\n  </li>\n</ul>\n",
            ],
            'G8 item opener 6 a whitespace-only line at 4' => [
                "- item\n\n      ```\n      a\n    \n      b\n      ```\n",
                "<ul>\n  <li>item\n    <pre><code>a\n\nb\n</code></pre>\n  </li>\n</ul>\n",
            ],
        ];
    }

    #[DataProvider('documents')]
    public function testTheClosersColumnDecidesTheExtent(string $source, string $expected): void
    {
        $this->assertSame($expected, (new CarveConverter())->convert($source));
    }
}
