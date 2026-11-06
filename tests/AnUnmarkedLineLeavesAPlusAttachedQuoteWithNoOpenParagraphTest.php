<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An unmarked line continues a quote a `+` attaches only within the quote's lazy extent (PART 1 S4, carve-php#4808).
 */
class AnUnmarkedLineLeavesAPlusAttachedQuoteWithNoOpenParagraphTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function documents(): array
    {
        return [
            'unclosed fence, unmarked line (carve-php#4808)' => [
                "> +\n> ```\n> c\nafter\n",
                "<blockquote>\n  <blockquote>\n    <pre><code>c\n</code></pre>\n  </blockquote>\n</blockquote>\n<p>after</p>\n",
            ],
            'unclosed fence in a nested quote host' => [
                "> > +\n> > ```\n> > c\nafter\n",
                "<blockquote>\n  <blockquote>\n    <blockquote>\n      <pre><code>c\n</code></pre>\n    </blockquote>\n  </blockquote>\n</blockquote>\n<p>after</p>\n",
            ],
            'list in the attached quote, sibling marker' => [
                "> +\n> - l\n- s\n",
                "<blockquote>\n  <blockquote>\n    <ul>\n      <li>l</li>\n    </ul>\n  </blockquote>\n</blockquote>\n<ul>\n  <li>s</li>\n</ul>\n",
            ],
            'unclosed comment span, unmarked line' => [
                "> +\n> %%%\n> k\nafter\n",
                "<blockquote>\n  <blockquote>\n\n  </blockquote>\n</blockquote>\n<p>after</p>\n",
            ],
            'paragraph in the attached quote keeps its lazy line' => [
                "> +\n> p\nafter\n",
                "<blockquote>\n  <blockquote><p>p\nafter</p></blockquote>\n</blockquote>\n",
            ],
            'div-hosted quote, unclosed fence' => [
                "::: n\n> +\n> ```\n> c\nafter\n:::\n",
                "<div class=\"n\">\n  <blockquote>\n    <blockquote>\n      <pre><code>c\n</code></pre>\n    </blockquote>\n  </blockquote>\n  <p>after</p>\n</div>\n",
            ],
        ];
    }

    #[DataProvider('documents')]
    public function testRendersAsTheSpecModel(string $source, string $expected): void
    {
        $this->assertSame(trim($expected), trim((new CarveConverter())->convert($source)));
    }
}
