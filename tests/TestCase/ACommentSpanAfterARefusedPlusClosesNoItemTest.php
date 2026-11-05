<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `%%%` span after a refused `+` closes no item, so the next line at the content column stays
 * in the host, as it does after a `%%` line (PART 0 FAMILIES `invisible`, CARVE-P9-067, carve#5170).
 */
class ACommentSpanAfterARefusedPlusClosesNoItemTest extends TestCase
{
    #[DataProvider('cases')]
    public function testTheLineAfterTheSpan(string $source, string $expected): void
    {
        $this->assertSame($expected, rtrim((new CarveConverter())->convert($source), "\n"));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'marker-line plus' => [
                "- +\n%%%\nx\n%%%\n  text\n",
                "<ul>\n  <li>text</li>\n</ul>",
            ],
            'own-line plus' => [
                "- a\n+\n%%%\nx\n%%%\n  text\n",
                "<ul>\n  <li>a\n    text\n  </li>\n</ul>",
            ],
            'ordered item' => [
                "1. +\n%%%\nx\n%%%\n   text\n",
                "<ol>\n  <li>text</li>\n</ol>",
            ],
            'description body' => [
                ":: t\n: +\n%%%\nx\n%%%\n  text\n",
                "<dl>\n  <dt>t</dt>\n  <dd>text</dd>\n</dl>",
            ],
            'footnote definition after the span' => [
                "- +\n%%%\n[r]: /opaque\n%%%\n  [^n]: note\n\nSee [^n].\n",
                "<ul>\n  <li></li>\n</ul>\n<p>See <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a>.</p>\n"
                . "<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n"
                . "      <p>note<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n"
                . "    </li>\n  </ol>\n</section>",
            ],
            'a %% line reads the same' => [
                "- +\n%% c\n  text\n",
                "<ul>\n  <li>text</li>\n</ul>",
            ],
        ];
    }
}
