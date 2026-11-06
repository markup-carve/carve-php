<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A fence a `+` attaches after skipped invisible lines, or at a nested item's marker column, keeps its blank lines and closer (PART 9 §17 L3, carve#5288).
 */
class APlusAttachedFenceAfterSkippedLinesKeepsItsBlankTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function documents(): array
    {
        return [
            'note before the fence (carve#5288)' => [
                "- +\n  [^n]: x\n```\n\n```\n",
                "<ul>\n  <li>\n    <pre><code>\n</code></pre>\n  </li>\n</ul>\n",
            ],
            'link definition before the fence' => [
                "- +\n  [r]: /u\n```\n\n```\n",
                "<ul>\n  <li>\n    <pre><code>\n</code></pre>\n  </li>\n</ul>\n",
            ],
            'attribute line, then a note' => [
                "- +\n  {x}\n  [^n]: x\n```\n\n```\n",
                "<ul>\n  <li>\n    <pre x=\"\"><code>\n</code></pre>\n  </li>\n</ul>\n",
            ],
            'note, then a link definition' => [
                "- +\n  [^n]: x\n  [r]: /u\n```\n\n```\n",
                "<ul>\n  <li>\n    <pre><code>\n</code></pre>\n  </li>\n</ul>\n",
            ],
            'note before the fence in a footnote host' => [
                "x[^m]\n\n[^m]: +\n  [^n]: x\n```\n\n```\n",
                "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <pre><code>\n</code></pre>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>\n",
            ],
            'nested item, fence at its marker column' => [
                "- a\n  - +\n  ```\n\n  ```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>\n        <pre><code>\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>\n",
            ],
            'deeper nested item, fence at its marker column' => [
                "1. a\n   - b\n     - +\n     ```\n\n     ```\n",
                "<ol>\n  <li>a\n    <ul>\n      <li>b\n        <ul>\n          <li>\n            <pre><code>\n</code></pre>\n          </li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ol>\n",
            ],
            'nested item, unclosed fence ends at its blank' => [
                "- a\n  - +\n  ```\n\n  z\nafter\n",
                "<ul>\n  <li><p>a</p>\n    <ul>\n      <li>\n        <pre><code></code></pre>\n      </li>\n    </ul>\n    <p>z\nafter</p>\n  </li>\n</ul>\n",
            ],
            'nested item, closer left of the fence stays outside' => [
                "- foo\n  - +\n  ```\n  x\n ```\n",
                "<ul>\n  <li>foo\n    <ul>\n      <li>\n        <pre><code>x\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>\n<pre><code></code></pre>\n",
            ],
            'span ended by a fence opener below its owner (carve#5291)' => [
                ". a\n  . %%%\n\n  ```\n%%%\n",
                "<ol>\n  <li><p>a</p>\n    <ol>\n      <li></li>\n    </ol>\n    <pre><code></code></pre>\n  </li>\n</ol>\n",
            ],
        ];
    }

    #[DataProvider('documents')]
    public function testRendersAsTheSpecModel(string $source, string $expected): void
    {
        $this->assertSame(trim($expected), trim((new CarveConverter())->convert($source)));
    }
}
