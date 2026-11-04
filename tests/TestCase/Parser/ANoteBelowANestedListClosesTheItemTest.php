<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CARVE-P9-052: a note definition below an item's column closes the item, even with a sub-list paragraph open.
 */
final class ANoteBelowANestedListClosesTheItemTest extends TestCase
{
    /**
     * @var string
     */
    private const NOTE = "<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n"
        . "<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>inner</p>\n"
        . "      <ul>\n        <li>c2\n          <ul>\n            <li>c3</li>\n          </ul>\n        </li>\n      </ul>\n"
        . "      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>";

    /**
     * @return array<string, array{string, string}>
     */
    public static function sources(): array
    {
        return [
            'at the document' => [
                "- child\n  - deep\n[^b]: inner\n  - c2\n    - c3\n\n[^b]\n",
                "<ul>\n  <li>child\n    <ul>\n      <li>deep</li>\n    </ul>\n  </li>\n</ul>\n" . self::NOTE,
            ],
            'in a list item' => [
                "- a\n  - child\n    - deep\n  [^b]: inner\n    - c2\n      - c3\n\n[^b]\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>child\n        <ul>\n          <li>deep</li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>\n" . self::NOTE,
            ],
        ];
    }

    #[DataProvider('sources')]
    public function testTheNoteKeepsItsNestedList(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
