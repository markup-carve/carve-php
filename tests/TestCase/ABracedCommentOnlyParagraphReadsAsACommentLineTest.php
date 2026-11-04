<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A paragraph of only braced comments reads as a `%%` line wherever layout skips one (carve#4799, ruling C).
 */
class ABracedCommentOnlyParagraphReadsAsACommentLineTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provider(): array
    {
        return [
            'a list skips it between siblings' => [
                "- a\n\n{% c %}\n\n- b\n",
                "<ul>\n  <li><p>a</p></li>\n  <li><p>b</p></li>\n</ul>",
            ],
            'a multi-line one between siblings' => [
                "- a\n\n{% c\nd %}\n\n- b\n",
                "<ul>\n  <li><p>a</p></li>\n  <li><p>b</p></li>\n</ul>",
            ],
            'a lead of only comments keeps the item tight' => [
                "- {% c\n  d %}\n\n  Body.\n",
                "<ul>\n  <li>Body.</li>\n</ul>",
            ],
            'after a blank in an item it keeps the item tight' => [
                "- p\n\n  {% c %}\n  - b\n",
                "<ul>\n  <li><p>p</p>\n    <ul>\n      <li>b</li>\n    </ul>\n  </li>\n</ul>",
            ],
            'a marker in an item ends it' => [
                "- p\n\n  - a\n\n  {% c %}\n  - b\n",
                "<ul>\n  <li><p>p</p>\n    <ul>\n      <li><p>a</p></li>\n      <li><p>b</p></li>\n    </ul>\n  </li>\n</ul>",
            ],
            'a nested list continues past it' => [
                "- a\n  - a2\n    - a3\n\n  {% c %}\n\n    - b\n",
                "<ul>\n  <li>a\n    <ul>\n      <li><p>a2</p>\n        <ul>\n          <li>a3</li>\n        </ul>\n      </li>\n      <li><p>b</p></li>\n    </ul>\n  </li>\n</ul>",
            ],
            'a plus skips it' => [
                "- +\n{% c %}\n# H\n",
                "<ul>\n  <li>\n    <h1 id=\"H\">H</h1>\n  </li>\n</ul>",
            ],
            'a plus skips it before a fence' => [
                "- +\n{% c %}\n```\nx\n```\n",
                "<ul>\n  <li>\n    <pre><code>x\n</code></pre>\n  </li>\n</ul>",
            ],
            'a plus refused past it' => [
                "- +\n{% c %}\n\n# H\n",
                "<ul>\n  <li></li>\n</ul>\n<section id=\"H\">\n  <h1>H</h1>\n</section>",
            ],
            'a quote of only comments hosts no caption' => [
                "> {% c %}\n^ cap\n",
                "<blockquote>\n\n</blockquote>\n<p>^ cap</p>",
            ],
            'a description list skips it between entries' => [
                ":: t\n: a\n\n{% c %}\n\n: b\n",
                "<dl>\n  <dt>t</dt>\n  <dd>a</dd>\n  <dd>b</dd>\n</dl>",
            ],
            'pending attributes float past it' => [
                "- a\n\n{.x}\n{% c %}\n\nb\n",
                "<ul>\n  <li>a</li>\n</ul>\n<p class=\"x\">b</p>",
            ],
        ];
    }

    #[DataProvider('provider')]
    public function testItRendersAsTheSpecModelDoes(string $source, string $expected): void
    {
        $this->assertSame($expected, CarveConverter::create()->convert($source));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function writerProvider(): array
    {
        $sources = array_map(static fn (array $row): array => [$row[0]], self::provider());

        return $sources + [
            'a tight item keeps the blank above it' => ["- p\n\n  {% c %}\n"],
            'three blanks still part two lists' => ["- a\n\n\n{% c %}\n\n- b\n"],
            'three blanks still part two sublists' => ["- p\n\n  - a\n\n\n  {% c %}\n\n  - b\n"],
        ];
    }

    #[DataProvider('writerProvider')]
    public function testTheWriterKeepsTheHtml(string $source): void
    {
        $html = CarveConverter::create()->convert($source);

        $this->assertSame($html, CarveConverter::create()->convert(CarveConverter::toCarve($source)));
    }
}
