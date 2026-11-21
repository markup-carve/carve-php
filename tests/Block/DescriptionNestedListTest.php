<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Block;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5530: a list marker under a description in a body, left of the description list's column
 * (C2-B-015, C2-B-022, C2-B-228).
 */
final class DescriptionNestedListTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'item, ordered value 1 opens a nested list' => [
                "10. :: t\n    : ---\n  1. ::\n",
                "<ol start=\"10\">\n  <li>\n    <dl>\n      <dt>t</dt>\n      <dd>—</dd>\n    </dl>\n    <ol>\n"
                . "      <li>::</li>\n    </ol>\n  </li>\n</ol>",
            ],
            'footnote body, bullet opens a list' => [
                "r[^f]\n\n[^f]: :: term\n      : d\n * x\n",
                "<p>r<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n"
                . "<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n"
                . "      <dl>\n        <dt>term</dt>\n        <dd>d</dd>\n      </dl>\n      <ul>\n        <li>x</li>\n"
                . "      </ul>\n"
                . "      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n"
                . "    </li>\n  </ol>\n</section>",
            ],
            'value 2 stays a lazy line' => [
                "- :: t\n  : d\n 2. x\n",
                "<ul>\n  <li>\n    <dl>\n      <dt>t</dt>\n      <dd>d\n2. x</dd>\n    </dl>\n  </li>\n</ul>",
            ],
            'at the description list column C2-B-022 keeps a lazy line' => [
                "- :: t\n  : d\n  - x\n",
                "<ul>\n  <li>\n    <dl>\n      <dt>t</dt>\n      <dd>d\n- x</dd>\n    </dl>\n  </li>\n</ul>",
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
    public function testMarkerLeftOfDescriptionList(string $src, string $html): void
    {
        $this->assertSame($html, Carve::toHtml($src));
    }
}
