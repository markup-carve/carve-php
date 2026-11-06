<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#5340: an unmarked exact closer ends a quoted span that an item or note in the quote opened.
 */
final class AnUnmarkedCloserEndsAHostedQuotedCommentSpanTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideShapes(): array
    {
        return [
            'a span on a list marker line in a quoted note' => [
                "> [^n]: i\n>   . %%%\n%%%\n\n[^n]\n",
                "<blockquote>\n\n</blockquote>\n<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>i</p>\n      <ol>\n        <li></li>\n      </ol>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
            ],
            'a span at a quoted item content column' => [
                "> - a\n>   %%%\n%%%\n\nz\n",
                "<blockquote>\n  <ul>\n    <li>a</li>\n  </ul>\n</blockquote>\n<p>z</p>",
            ],
            'a span on a nested marker line in a quoted item' => [
                "> - a\n>   . %%%\n%%%\n\nz\n",
                "<blockquote>\n  <ul>\n    <li>a\n      <ol>\n        <li></li>\n      </ol>\n    </li>\n  </ul>\n</blockquote>\n<p>z</p>",
            ],
            'a span in an item of a quoted note' => [
                "> [^n]: i\n>   - x\n>     %%%\n%%%\n\n[^n]\n",
                "<blockquote>\n\n</blockquote>\n<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>i</p>\n      <ul>\n        <li>x</li>\n      </ul>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
            ],
        ];
    }

    #[DataProvider('provideShapes')]
    public function testCloser(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
