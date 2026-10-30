<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An unclosed fence in a footnote body inside a list item ends with the note,
 * so the item paragraph after it takes a flush-left lazy line (PART 1 S4). A
 * comment in the note's body leaves the body open.
 */
class AFenceInAListItemsNoteEndsWithTheNoteTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        $note = static fn (string $body): string => "<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n"
            . $body
            . "      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>";
        $ref = '<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a>';

        return [
            'fence with a payload' => [
                "- [^n]: text\n    ~~~\n    code\n  x\nafter [^n]\n",
                "<ul>\n  <li>x\nafter $ref</li>\n</ul>\n"
                    . $note("      <p>text</p>\n      <pre><code>code\n</code></pre>\n"),
            ],
            'comment before the fence' => [
                "- [^n]: text\n    %% c\n    ~~~\n   code\nafter [^n]\n",
                "<ul>\n  <li>code\nafter $ref</li>\n</ul>\n"
                    . $note("      <p>text</p>\n      <pre><code></code></pre>\n"),
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testTheItemParagraphTakesTheLazyLine(string $source, string $expected): void
    {
        $this->assertSame($expected, trim(CarveConverter::create()->convert($source)));
    }
}
