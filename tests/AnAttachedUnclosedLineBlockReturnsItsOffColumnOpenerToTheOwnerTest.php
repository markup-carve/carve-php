<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A line block without its exact closer releases its host at the first blank (carve#5450).
 */
final class AnAttachedUnclosedLineBlockReturnsItsOffColumnOpenerToTheOwnerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'footnote' => [
            "[^1]: +\n::: |\n\n :::: \n\n[^1]\n",
            "<div>\n  <p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n</div>\n"
                . "<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n"
                . "      <div class=\"line-block\">\n\n      </div>\n"
                . "      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n"
                . "    </li>\n  </ol>\n</section>",
        ];

        yield 'description' => [
            ":: t\n: +\n::: |\n\n :::: \n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <div class=\"line-block\">\n\n    </div>\n  </dd>\n</dl>\n"
                . "<div>\n\n</div>",
        ];

        yield 'item' => [
            "- +\n::: |\n\n :::: \n",
            "<ul>\n  <li>\n    <div class=\"line-block\">\n\n    </div>\n  </li>\n</ul>\n<div>\n\n</div>",
        ];
    }

    #[DataProvider('documents')]
    public function testOpenerIsReadAtDocumentLevel(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
