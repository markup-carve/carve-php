<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class NoteMarkerLineItemColumnsTest extends TestCase
{
    public function testAnOwnLinePlusStandsAtTheMarkerLineItemsAuthoredColumn(): void
    {
        $source = "[^a]: - i\n      +\n      x\n\n[^a]\n";
        $expected = "<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n"
            . "<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n"
            . "  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <ul>\n        <li>i\n          x\n        </li>\n      </ul>\n"
            . "      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n"
            . "    </li>\n  </ol>\n</section>";

        self::assertSame($expected, (new CarveConverter())->convert($source));
    }

    public function testAFenceInTheMarkerLineItemStripsItsFullContentColumn(): void
    {
        $source = "x[^n]\n\n[^n]: - ~~~js\n        a\n        ~~~\n";
        $expected = "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n"
            . "<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n"
            . "  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <ul>\n        <li>\n"
            . "          <pre><code class=\"language-js\">a\n</code></pre>\n"
            . "        </li>\n      </ul>\n"
            . "      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n"
            . "    </li>\n  </ol>\n</section>";

        self::assertSame($expected, (new CarveConverter())->convert($source));
        self::assertSame(
            str_replace('<p>x<a', '<p><a', $expected),
            (new CarveConverter())->convert("[^n]: - ~~~js\n\t\ta\n\t\t~~~\n\n[^n]\n"),
        );
    }
}
