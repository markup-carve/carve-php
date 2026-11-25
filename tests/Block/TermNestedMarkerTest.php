<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Block;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#6758: inside an item, a list marker indented past a term's mark ends the term as it ends a heading
 * (C2-B-234, C2-B-015).
 */
final class TermNestedMarkerTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        $nested = "<ul>\n  <li>\n    <dl>\n      <dt>t</dt>\n    </dl>\n    <ul>\n      <li>x</li>\n    </ul>\n  </li>\n</ul>";

        return [
            'bullet past the term mark opens a nested list' => ["- :: t\n     - x\n", $nested],
            'bullet one column past the item opens a nested list' => ["- :: t\n   - x\n", $nested],
            'value 1 in an ordered item opens a nested list' => [
                "1. :: t\n       1. x\n",
                "<ol>\n  <li>\n    <dl>\n      <dt>t</dt>\n    </dl>\n    <ol>\n      <li>x</li>\n    </ol>\n  </li>\n</ol>",
            ],
            'item in a quote' => [
                "> - :: t\n>      * x\n",
                "<blockquote>\n  <ul>\n    <li>\n      <dl>\n        <dt>t</dt>\n      </dl>\n      <ul>\n"
                . "        <li>x</li>\n      </ul>\n    </li>\n  </ul>\n</blockquote>",
            ],
            'footnote body' => [
                "r[^f]\n\n[^f]: :: t\n         - x\n",
                "<p>r<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n"
                . "<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n"
                . "      <dl>\n        <dt>t</dt>\n      </dl>\n      <ul>\n        <li>x</li>\n      </ul>\n"
                . "      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n"
                . "    </li>\n  </ol>\n</section>",
            ],
            'value 2 stays term text' => [
                "- :: t\n     2. x\n",
                "<ul>\n  <li>\n    <dl>\n      <dt>t\n2. x</dt>\n    </dl>\n  </li>\n</ul>",
            ],
            'top level stays term text' => [
                ":: t\n   - x\n",
                "<dl>\n  <dt>t\n- x</dt>\n</dl>",
            ],
        ];
    }

    /**
     * @param string $src
     * @param string $html
     *
     * @return void
     */
    #[DataProvider('cases')]
    public function testRender(string $src, string $html): void
    {
        $this->assertSame($html, Carve::toHtml($src));
    }

    /**
     * @return void
     */
    public function testLintNoLongerReportsTheMarker(): void
    {
        $this->assertStringNotContainsString(
            'list-marker-without-blank-line',
            (string)json_encode(Carve::lint("- :: t\n     - x\n")),
        );
    }

    /**
     * @return void
     */
    public function testWriterEscapesAMarkerKeptAsTermText(): void
    {
        $src = "- :: t\n     \\- x\n";
        $this->assertSame($src, Carve::format($src));
        $this->assertSame("<ul>\n  <li>\n    <dl>\n      <dt>t\n- x</dt>\n    </dl>\n  </li>\n</ul>", Carve::toHtml($src));
    }
}
