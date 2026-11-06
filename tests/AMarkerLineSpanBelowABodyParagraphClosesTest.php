<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `%%%` span on a list marker line below a note's or description's paragraph opens in the new item,
 * so a column-0 line ends it (markup-carve/carve#5314, #5300). Expected HTML is the spec model's.
 */
final class AMarkerLineSpanBelowABodyParagraphClosesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        $note = static fn (string $body): string => "<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n"
            . "<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n"
            . $body
            . "      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>";
        $emptyItem = "      <ol>\n        <li></li>\n      </ol>\n";

        yield 'closer at column 0 in a note' => [
            "[^n]: i\n  . %%%\n    n\n%%%\nzz\n\n[^n]\n",
            "<p>zz</p>\n" . $note("      <p>i</p>\n" . $emptyItem),
        ];

        yield 'unclosed span in a note' => [
            "[^n]: a\n  . %%%\n    n\nzz\n\n[^n]\n",
            "<p>zz</p>\n" . $note("      <p>a</p>\n" . $emptyItem),
        ];

        yield 'unclosed span in a description' => [
            ":: t\n: a\n  . %%%\n    n\nzz\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <ol>\n      <li></li>\n    </ol>\n  </dd>\n</dl>\n<p>zz</p>",
        ];

        yield 'two lead lines and a bullet marker' => [
            "[^n]: i\n  j\n  - %%%\n    n\n%%%\nzz\n\n[^n]\n",
            "<p>zz</p>\n" . $note("      <p>i\nj</p>\n      <ul>\n        <li></li>\n      </ul>\n"),
        ];
    }

    #[DataProvider('documents')]
    public function testRender(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
