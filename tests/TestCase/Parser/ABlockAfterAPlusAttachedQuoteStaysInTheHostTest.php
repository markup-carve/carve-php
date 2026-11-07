<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#5427: a line without `>` that cannot fold into the quote a marker-line `+` took ends it, and the host resumes.
 */
final class ABlockAfterAPlusAttachedQuoteStaysInTheHostTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'item, heading' => [
            "- +\n> q\n  # g\n",
            "<ul>\n  <li>\n    <blockquote><p>q</p></blockquote>\n    <h1 id=\"g\">g</h1>\n  </li>\n</ul>",
        ];

        yield 'item, div' => [
            "- +\n> q\n  ::: w\n  :::\n",
            "<ul>\n  <li>\n    <blockquote><p>q</p></blockquote>\n    <div class=\"w\">\n\n    </div>\n  </li>\n</ul>",
        ];

        yield 'footnote, heading' => [
            "[^1]: +\n> q\n  # g\n\n[^1]\n",
            "    <li id=\"fn1\">\n      <blockquote><p>q</p></blockquote>\n      <h1 id=\"g\">g</h1>\n      <p><a href=\"#fnref1\"",
        ];

        yield 'footnote, quote holding a list' => [
            "[^1]: +\n> - i\n  ---\n\n[^1]\n",
            "      </blockquote>\n      <hr>\n      <p><a href=\"#fnref1\"",
        ];

        yield 'description, div' => [
            ":: t\n: +\n> q\n  ::: w\n  :::\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <blockquote><p>q</p></blockquote>\n    <div class=\"w\">\n\n    </div>\n  </dd>\n</dl>",
        ];
    }

    #[DataProvider('documents')]
    public function testTheBlockOpensInTheHost(string $source, string $expected): void
    {
        self::assertStringContainsString($expected, (new CarveConverter())->convert($source));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function folds(): iterable
    {
        yield 'item' => ["- +\n> q\n  plain\n"];
        yield 'footnote' => ["[^1]: +\n> q\n  plain\n\n[^1]\n"];
        yield 'footnote, quote holding a list' => ["[^1]: +\n> - i\n  plain\n\n[^1]\n"];
    }

    #[DataProvider('folds')]
    public function testPlainTextStillFoldsIntoTheQuote(string $source): void
    {
        self::assertMatchesRegularExpression('/q\nplain<\/p><\/blockquote>|<li>i\nplain<\/li>/', (new CarveConverter())->convert($source));
    }
}
