<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An unclosed marker-line line block returns the first blank to its host (carve#5414).
 */
final class AnAttachedLineBlockEndsAtItsFirstBlankBeforeWiderColonTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        $footnote = "<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n"
            . "<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n"
            . "    <li id=\"fn1\">\n      <div class=\"line-block\">\n\n      </div>\n"
            . "      <div>\n\n      </div>\n"
            . "      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n"
            . "    </li>\n  </ol>\n</section>";

        yield 'footnote content column' => ["[^1]: +\n::: |\n\n  :::: \n\n[^1]\n", $footnote];
        yield 'footnote one column deeper' => ["[^1]: +\n::: |\n\n   :::: \n\n[^1]\n", $footnote];

        $description = "<dl>\n  <dt>t</dt>\n  <dd>\n    <div class=\"line-block\">\n\n    </div>\n"
            . "    <div>\n\n    </div>\n  </dd>\n</dl>";

        yield 'description one column deeper' => [":: t\n: +\n::: |\n\n   :::: \n", $description];
    }

    #[DataProvider('documents')]
    public function testWiderOpenerIsReadByHost(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
