<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Invisible lines after a lone `+` lead the block it attaches, so an item keeps that block and its caption (CARVE-P9-013, carve-php#4318).
 */
class AnInvisibleLineAfterAPlusLeadsTheAttachedBlockTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function documents(): array
    {
        return [
            'attribute line, captioned fence' => [
                "- a\n+\n{x}\n```\nb\n```\n^ cap\n",
                "<ul>\n  <li>a\n    <figure x=\"\">\n      <pre><code>b\n</code></pre>\n      <figcaption>cap</figcaption>\n    </figure>\n  </li>\n</ul>\n",
            ],
            'two attribute lines, captioned fence' => [
                "- a\n+\n{x}\n{.y}\n```\nb\n```\n^ cap\n",
                "<ul>\n  <li>a\n    <figure x=\"\" class=\"y\">\n      <pre><code>b\n</code></pre>\n      <figcaption>cap</figcaption>\n    </figure>\n  </li>\n</ul>\n",
            ],
            'ordered item, attribute line, captioned fence' => [
                "1. a\n+\n{x}\n```\nb\n```\n^ cap\n",
                "<ol>\n  <li>a\n    <figure x=\"\">\n      <pre><code>b\n</code></pre>\n      <figcaption>cap</figcaption>\n    </figure>\n  </li>\n</ol>\n",
            ],
            'comment line, quote' => [
                "- a\n+\n%% c\n> q\n",
                "<ul>\n  <li>a\n    <blockquote><p>q</p></blockquote>\n  </li>\n</ul>\n",
            ],
            'link definition, heading' => [
                "- a\n+\n[r]: /u\n# H\n",
                "<ul>\n  <li>a\n    <h1 id=\"H\">H</h1>\n  </li>\n</ul>\n",
            ],
            'attribute line, paragraph, tail' => [
                "- a\n+\n{x}\np\n\nz\n",
                "<ul>\n  <li>a\n    <p x=\"\">p</p>\n  </li>\n</ul>\n<p>z</p>\n",
            ],
        ];
    }

    #[DataProvider('documents')]
    public function testRendersAsTheSpecModel(string $source, string $expected): void
    {
        $this->assertSame(trim($expected), trim((new CarveConverter())->convert($source)));
    }
}
