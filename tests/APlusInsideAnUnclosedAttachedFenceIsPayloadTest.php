<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §17 L3: a `+` at the fence's content column stays payload, with or without a later blank (carve#5494).
 */
class APlusInsideAnUnclosedAttachedFenceIsPayloadTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideCases(): array
    {
        return [
            'blank later' => [
                "- +\n```\n+\n\ny\n",
                "<ul>\n  <li>\n    <pre><code>+\n</code></pre>\n  </li>\n</ul>\n<p>y</p>",
            ],
            'no blank' => [
                "- +\n```\n+\n",
                "<ul>\n  <li>\n    <pre><code>+\n</code></pre>\n  </li>\n</ul>",
            ],
            'after payload' => [
                "- +\n```\nx\n+\n\ny\n",
                "<ul>\n  <li>\n    <pre><code>x\n+\n</code></pre>\n  </li>\n</ul>\n<p>y</p>",
            ],
            'nested item' => [
                "- - +\n  ```\n  +\n\n",
                "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code>+\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'quote host' => [
                "> +\n```\n+\n\ny\n",
                "<blockquote>\n  <pre><code>+\n</code></pre>\n</blockquote>\n<p>y</p>",
            ],
            'note host' => [
                "a[^1]\n\n[^1]: +\n```\n+\n\ny\n",
                "<p>a<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<p>y</p>\n"
                . "<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n"
                . "      <pre><code>+\n</code></pre>\n"
                . "      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n"
                . "    </li>\n  </ol>\n</section>",
            ],
            // The sibling marker is payload too, at every level (carve#5499, carve#5531).
            'sibling then plus' => [
                "- +\n```\n- z\n+\n\ny\n",
                "<ul>\n  <li>\n    <pre><code>- z\n+\n</code></pre>\n  </li>\n</ul>\n<p>y</p>",
            ],
            'nested sibling then plus' => [
                "- - +\n  ```\n  - z\n  +\n\ny\n",
                "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code>- z\n+\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>y</p>",
            ],
        ];
    }

    #[DataProvider('provideCases')]
    public function testRendersAsTheSpecModel(string $source, string $expected): void
    {
        $converter = CarveConverter::create();

        self::assertSame($expected, $converter->convert($source));
    }

    #[DataProvider('provideCases')]
    public function testTheFormatterKeepsTheReading(string $source, string $expected): void
    {
        $formatted = CarveConverter::toCarve($source);

        self::assertSame($expected, CarveConverter::create()->convert($formatted));
        self::assertSame($formatted, CarveConverter::toCarve($formatted));
    }
}
