<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class AWrappedAttributeOnANoteMarkerLineLeavesNoLazyParagraphTest extends TestCase
{
    public function testAValidRunDoesNotKeepAColumnZeroFollowerInTheNote(): void
    {
        $source = "[^n]: {.x\n     }\nafter\n\nx[^n]\n";
        $expected = "<p>after</p>\n<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n"
            . "<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n"
            . "      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n"
            . "    </li>\n  </ol>\n</section>";

        self::assertSame($expected, CarveConverter::create()->convert($source));
    }

    public function testARefusedRunKeepsItsFollowerInTheNote(): void
    {
        $source = "[^n]: {.x\n }\n z\n\nx[^n]\n";
        self::assertStringContainsString("<p>{.x\n}\nz<a", CarveConverter::create()->convert($source));
    }
}
