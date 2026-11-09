<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A refused first-block marker leaves no paragraph for a line below the note body column (CARVE-P9-067).
 */
final class ARefusedNotePlusLeavesNoLazyParagraphTest extends TestCase
{
    public function testQuotedNoteFollowerOneColumnPastItsMarkerStaysInQuote(): void
    {
        $source = "> > [^a]: +\n> >  {#i\n  .y}\n\nsee[^a]\n";
        $expected = "<blockquote>\n  <blockquote><p>{<span class=\"tag\"><strong>#i</strong></span>\n.y}</p></blockquote>\n</blockquote>\n<p>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>";

        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    public function testQuotedNotePlainFollowerShortOfBodyStaysInQuote(): void
    {
        $source = "> [^a]: +\n>  v0\n\nsee[^a]\n";
        $html = (new CarveConverter())->convert($source);

        self::assertStringContainsString('<blockquote><p>v0</p></blockquote>', $html);
        self::assertStringNotContainsString('<p>v0<a href="#fnref1"', $html);
    }

    public function testNestedQuoteSeparatorsMayVaryOnTheFollower(): void
    {
        $source = "> > [^a]: +\n>  >  v0\n\nsee[^a]\n";
        $html = (new CarveConverter())->convert($source);

        self::assertStringContainsString('<blockquote><p>v0</p></blockquote>', $html);
        self::assertStringNotContainsString('<p>v0<a href="#fnref1"', $html);
    }

    public function testInlineBraceTextStillFoldsIntoNote(): void
    {
        $source = "[^a]: +\n {^word^}\n\nsee[^a]\n";
        $html = (new CarveConverter())->convert($source);

        self::assertStringContainsString('<p><sup>word</sup><a href="#fnref1"', $html);
        self::assertStringNotContainsString('<p><sup>word</sup></p>', $html);
    }

    public function testIncompleteAttributeOpenerStillFoldsIntoNote(): void
    {
        $source = "[^a]: +\n {#i\n\nsee[^a]\n";
        $html = (new CarveConverter())->convert($source);

        self::assertStringContainsString('<p>{<span class="tag"><strong>#i</strong></span><a href="#fnref1"', $html);
    }

    public function testQuotedClosingBraceInWrappedAttributeDoesNotDecideTheRun(): void
    {
        $source = "[^a]: +\n {key=\"}\"\n .y}\n\nsee[^a]\n";
        $html = (new CarveConverter())->convert($source);

        self::assertStringContainsString('<p key="}" class="y">see', $html);
        self::assertStringContainsString("<li id=\"fn1\">\n      <p><a href=\"#fnref1\"", $html);
    }

    public function testWrappedLanguageAttributeUsesTheSameOwner(): void
    {
        $source = "[^a]: +\n {:foo\n .y}\n\nsee[^a]\n";
        $html = (new CarveConverter())->convert($source);

        self::assertStringContainsString('<p lang="foo" class="y">see', $html);
        self::assertStringContainsString("<li id=\"fn1\">\n      <p><a href=\"#fnref1\"", $html);
    }

    public function testWrappedBooleanAttributeUsesTheSameOwner(): void
    {
        $source = "[^a]: +\n {disabled\n .y}\n\nsee[^a]\n";
        $html = (new CarveConverter())->convert($source);

        self::assertStringContainsString('<p disabled="" class="y">see', $html);
        self::assertStringContainsString("<li id=\"fn1\">\n      <p><a href=\"#fnref1\"", $html);
    }
}
