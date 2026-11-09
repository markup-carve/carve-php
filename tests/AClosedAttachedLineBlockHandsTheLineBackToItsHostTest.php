<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A closed attached line block hands the next line back to its host, and its payload is verse (PART 9 §17 L3, PART 0 S1).
 */
final class AClosedAttachedLineBlockHandsTheLineBackToItsHostTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function examples(): iterable
    {
        yield 'carve#5599: an indented list after a closed attached line block stays in the note' => [
            "r[^a]\n\n[^a]: +\n::: |\n  a\n:::\n  - sib\n",
            "<p>r<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <div class=\"line-block\">\n        <p>&nbsp;&nbsp;a</p>\n      </div>\n      <ul>\n        <li>sib</li>\n      </ul>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];

        yield 'carve#5599: a paragraph after a closed attached line block stays in the note' => [
            "r[^a]\n\n[^a]: +\n::: |\n  a\n:::\n  p\n",
            "<p>r<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <div class=\"line-block\">\n        <p>&nbsp;&nbsp;a</p>\n      </div>\n      <p>p<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];

        yield 'a longer line block closer hands the note back too' => [
            "r[^a]\n\n[^a]: +\n:::: |\n  a\n::::\n  - sib\n",
            "<p>r<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <div class=\"line-block\">\n        <p>&nbsp;&nbsp;a</p>\n      </div>\n      <ul>\n        <li>sib</li>\n      </ul>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];

        yield 'an attached line block in an item hands the item back' => [
            "- +\n::: |\n  a\n:::\n  - sib\n",
            "<ul>\n  <li>\n    <div class=\"line-block\">\n      <p>&nbsp;&nbsp;a</p>\n    </div>\n    <ul>\n      <li>sib</li>\n    </ul>\n  </li>\n</ul>",
        ];

        yield 'carve#5599: a marker inside an open line block in a description is verse' => [
            ":: t\n: +\n::: |\n  a\n  - sib\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <div class=\"line-block\">\n      <p>&nbsp;&nbsp;a<br>\n&nbsp;&nbsp;- sib</p>\n    </div>\n  </dd>\n</dl>",
        ];
    }

    #[DataProvider('examples')]
    public function testTheHostReadsTheLineAfterTheLineBlock(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
