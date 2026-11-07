<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A later note definition is invisible to an earlier marker-line offer at the same column (CARVE-P9-067 L3).
 */
final class AEarlierQuotedNoteClaimsPastALaterDefinitionTest extends TestCase
{
    public function testOnlyLastPreludeNoteKeepsItsLaterIndentedBody(): void
    {
        $source = "[^a]: +\n[^b]: +\n[^c]: +\npara\n\n  own\n\n[^a] [^b] [^c]\n";
        $html = (new CarveConverter())->convert($source);

        self::assertStringContainsString('<p>para<a href="#fnref1"', $html);
        self::assertStringContainsString('<li id="fn2">' . "\n      <p><a href=\"#fnref2\"", $html);
        self::assertStringContainsString('<p>own<a href="#fnref3"', $html);
        self::assertSame(1, substr_count($html, '<p>own'));
    }

    public function testLaterNoteKeepsItsOwnIndentedBodyAfterTheEarlierClaim(): void
    {
        $source = "[^a]: +\n[^b]: +\npara\n\n  own\n\n[^a] [^b]\n";
        $html = (new CarveConverter())->convert($source);

        self::assertStringNotContainsString('<p>own</p>', $html);
        self::assertStringContainsString('<p>para<a href="#fnref1"', $html);
        self::assertStringContainsString('<p>own<a href="#fnref2"', $html);
    }

    public function testPreludeDefinitionIsRegisteredWhenEarlierNoteLabelIsDuplicate(): void
    {
        $source = "[^a]: x\n[^a]: +\n[^b]: +\npara\n\n[^a] [^b]\n";
        $html = (new CarveConverter())->convert($source);

        self::assertStringContainsString('href="#fn2"', $html);
        self::assertStringContainsString('<li id="fn2">', $html);
        self::assertStringNotContainsString('[^b]', $html);
    }

    public function testEarlierNoteClaimsTheParagraphAndLaterNoteStaysEmpty(): void
    {
        $source = "- > [^a]: +\n  > [^b]: +\n  > para\n\n[^a] [^b]\n";
        $expected = "<ul>\n  <li>\n    <blockquote>\n\n    </blockquote>\n  </li>\n</ul>\n"
            . '<p><a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a> '
            . "<a id=\"fnref2\" href=\"#fn2\" role=\"doc-noteref\"><sup>2</sup></a></p>\n"
            . "<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n"
            . "    <li id=\"fn1\">\n      <p>para<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n"
            . "    <li id=\"fn2\">\n      <p><a href=\"#fnref2\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n"
            . "  </ol>\n</section>";

        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
