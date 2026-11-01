<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class HostedAbbreviationNoteContinuationTest extends TestCase
{
    public function testAnUnreferencedNoteKeepsTheAbbreviationShapedLine(): void
    {
        self::assertSame(
            "<ul>\n  <li>a</li>\n</ul>",
            CarveConverter::create()->convert("- a\n  [^n]: n\n  *[A]: b\n"),
        );
    }

    public function testAReferencedNoteRendersTheLineInItsParagraph(): void
    {
        self::assertSame(
            "<ul>\n  <li>a <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></li>\n</ul>\n"
                . "<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n"
                . "      <p>n\n*[A]: b<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n"
                . "    </li>\n  </ol>\n</section>",
            CarveConverter::create()->convert("- a [^n]\n  [^n]: n\n  *[A]: b\n"),
        );
    }
}
