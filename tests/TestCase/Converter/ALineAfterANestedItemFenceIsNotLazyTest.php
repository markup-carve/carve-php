<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A dedented line ends a nested item's unclosed fence. Right after the written
 * closer Carve reads that line as lazy content of the parent item, so a blank
 * line separates them (#2129).
 */
class ALineAfterANestedItemFenceIsNotLazyTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function shapes(): array
    {
        return [
            'a paragraph after a blank line' => [
                "- a\n  - ```\n    code\n\nz",
                "- a\n  - ```\n    code\n\n    ```\n\nz",
                "<ul>\n  <li>a\n    <ul>\n      <li>\n        <pre><code>code\n\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>z</p>\n",
            ],
            'a paragraph right after the code' => [
                "- a\n  - ```\n    code\nz",
                "- a\n  - ```\n    code\n    ```\n\nz",
                "<ul>\n  <li>a\n    <ul>\n      <li>\n        <pre><code>code\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>z</p>\n",
            ],
            'under an ordered item' => [
                "1. a\n   - ```\n     code\n\nz",
                "1. a\n   - ```\n     code\n\n     ```\n\nz",
                "<ol>\n  <li>a\n    <ul>\n      <li>\n        <pre><code>code\n\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ol>\n<p>z</p>\n",
            ],
            'a tilde fence' => [
                "- a\n  - ~~~\n    code\n\nz",
                "- a\n  - ```\n    code\n\n    ```\n\nz",
                "<ul>\n  <li>a\n    <ul>\n      <li>\n        <pre><code>code\n\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>z</p>\n",
            ],
            'a paragraph of the parent item after a blank line' => [
                "- a\n  - ```\n    code\n\n  more",
                "- a\n\n  - ```\n    code\n\n    ```\n\n  more",
                "<ul>\n  <li><p>a</p>\n    <ul>\n      <li>\n        <pre><code>code\n\n</code></pre>\n      </li>\n    </ul>\n    <p>more</p>\n  </li>\n</ul>\n",
            ],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheClosedFenceIsSeparatedFromTheLine(string $markdown, string $carve, string $html): void
    {
        $this->assertSame($carve, rtrim((new MarkdownToCarve())->convert($markdown), "\n"));
    }

    #[DataProvider('shapes')]
    public function testTheLineRendersOutsideTheNestedItem(string $markdown, string $carve, string $html): void
    {
        $this->assertSame($html, (new CarveConverter())->convert((new MarkdownToCarve())->convert($markdown)));
    }
}
