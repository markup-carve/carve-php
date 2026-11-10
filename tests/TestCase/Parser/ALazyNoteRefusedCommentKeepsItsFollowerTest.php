<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class ALazyNoteRefusedCommentKeepsItsFollowerTest extends TestCase
{
    public function testAQuoteFollowerAndItsLazyLineRemainInTheNoteParagraph(): void
    {
        $source = "[^n]: p\n {% a\n%}\n  > q\nafter\n\nx[^n]\n";
        $expected = "<p>x<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>p\n{% a\n%}</p>\n      <blockquote><p>q\nafter</p></blockquote>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>";

        self::assertSame($expected, CarveConverter::create()->convert($source));
    }

    public function testAFenceFollowerStopsAtTheNextDocumentLine(): void
    {
        $source = "[^n]: p\n{% a\n%}\n  ```\nafter\n\nx[^n]\n";
        $html = CarveConverter::create()->convert($source);

        self::assertStringStartsWith("<p>after</p>\n", $html);
        self::assertStringContainsString("<p>p\n{% a\n%}</p>\n      <pre><code></code></pre>", $html);
        self::assertStringNotContainsString("<code>\nafter", $html);
    }

    public function testARawFenceFollowerStopsAtTheNextDocumentLine(): void
    {
        $source = "[^n]: p\n{% a\n%}\n  ```=html\nafter\n\nx[^n]\n";
        $html = CarveConverter::create()->convert($source);

        self::assertStringStartsWith("<p>after</p>\n", $html);
        self::assertStringContainsString("<p>p\n{% a\n%}</p>", $html);
        self::assertStringNotContainsString('<code>=html</code>', $html);
        self::assertStringNotContainsString("<code>=html\nafter", $html);
    }
}
