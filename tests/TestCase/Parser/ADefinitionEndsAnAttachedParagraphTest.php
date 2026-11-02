<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Expected output is the spec model's at carve main 5a82756e (carve-php#4243).
 */
final class ADefinitionEndsAnAttachedParagraphTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function bodies(): iterable
    {
        $link = '<p>See <a href="/out">r</a></p>';

        yield 'a later document definition wins over a note body' => [
            "[^f]: a\n\n  [r]: /in\n\n[r]: /out\n\nSee [r][]\n",
            $link,
        ];

        yield 'a later line definition wins over a note-attached div' => [
            "[^f]: a\n+\n::: d\n[r]: /in\n:::\n[r]: /out\n\nSee [r][]\n",
            $link,
        ];

        yield 'a later line definition wins over a note-attached list' => [
            "[^f]: a\n+\n- inner\n[r]: /in\n [r]: /out\n\nSee [r][]\n",
            $link,
        ];

        yield 'a definition ends an attached paragraph' => [
            "- a\n+\ntext\n[r]: /u\nmore\n",
            "<ul>\n  <li>a\n    text\n  </li>\n</ul>\n<p>more</p>",
        ];

        yield 'an indented definition ends an attached paragraph' => [
            "100. +\nfirst\n+\ntext\n [r]: /u\nmore\n",
            "<ol start=\"100\">\n  <li>first\n    text\n  </li>\n</ol>\n<p>more</p>",
        ];

        yield 'a line comment ends an attached paragraph' => [
            "- a\n+\ntext\n%% c\nmore\n",
            "<ul>\n  <li>a\n    text\n  </li>\n</ul>\n<p>more</p>",
        ];

        yield 'in a quote' => [
            "> - a\n> +\n> text\n> [r]: /u\n> more\n",
            "<blockquote>\n  <ul>\n    <li>a\n      text\n    </li>\n  </ul>\n  <p>more</p>\n</blockquote>",
        ];
    }

    #[DataProvider('bodies')]
    public function testTheDefinitionLeavesNothingToContinue(string $source, string $html): void
    {
        self::assertSame($html, trim((new CarveConverter())->convert($source)));
    }
}
