<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Three body readings the spec model gives: a comment at a note's body column
 * is a note line (CARVE-P0-005), a sibling description takes its own first-block
 * `+` (CARVE-P9-016), and a term on an item's marker line takes a description
 * past a blank.
 */
final class DescriptionAndNoteBodiesKeepTheirLinesTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        $note = "<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>text</p>\n      <p>more<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>";

        return [
            'comment in an item note' => [
                "- [^a]: text\n    %% c\n    more\n\nsee[^a]\n",
                "<ul>\n  <li></li>\n</ul>\n<p>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n$note",
            ],
            'sibling first-block +' => [
                ":: t\n: a\n: +\nb\n",
                "<dl>\n  <dt>t</dt>\n  <dd>a</dd>\n  <dd>b</dd>\n</dl>",
            ],
            'sibling + after a body line' => [
                ":: t\n: a\n  more\n: +\nb\n",
                "<dl>\n  <dt>t</dt>\n  <dd>a\nmore</dd>\n  <dd>b</dd>\n</dl>",
            ],
            'two stacked +' => [
                ":: t\n:  +\n:  +\n",
                "<dl>\n  <dt>t</dt>\n  <dd></dd>\n  <dd></dd>\n</dl>",
            ],
            'marker-line term past a blank' => [
                "- :: term\n\n  : definition\n",
                "<ul>\n  <li>\n    <dl>\n      <dt>term</dt>\n      <dd>definition</dd>\n    </dl>\n  </li>\n</ul>",
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testItRendersLikeTheModel(string $source, string $html): void
    {
        self::assertSame($html, CarveConverter::create()->convert($source));
    }
}
