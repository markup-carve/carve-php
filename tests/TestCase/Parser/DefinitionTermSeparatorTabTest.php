<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A tab after the definition-term separator is stripped, not content
 * (carve-php#884, spec markup-carve/carve#794).
 *
 * The three `DEFINITION_TERM_*` patterns each had `(?=\S)` or `\S` straight
 * after ` +`, requiring the term to begin on a non-space. So `:: <TAB>x` was a
 * paragraph while `:: x` - differing only in which whitespace follows the
 * separator - was a term.
 *
 * TWO RULES MEET HERE and the fix keeps them apart:
 *
 *   `:: <TAB>x` the separator space IS present; the tab is leading whitespace
 *                on the term and is stripped. This is what changed.
 *   `::<TAB>x` no separator space at all, so the line stays prose. Pinned by
 *                corpus 176-a-marker-separator-is-a-space-never-a-tab, and the
 *                reason the leading space stays required instead of the
 *                separator widening to `[ \t]+`.
 *
 * All three constants moved together. The docblock above them says why they were
 * unified in the first place: "a fix applied to the one a bug report named would
 * have left the rest deciding the old way." The PREFIX one matters most - it is
 * what strips the marker, so leaving it narrow would keep the tab as the term's
 * first character even once the other two matched.
 */
class DefinitionTermSeparatorTabTest extends TestCase
{
    private function html(string $source): string
    {
        return trim((string)preg_replace('/\s+/', ' ', (new CarveConverter())->convert($source)));
    }

    public function testATabAfterTheSeparatorIsStripped(): void
    {
        $this->assertSame('<dl> <dt>x</dt> </dl>', $this->html(":: \tx\n"));
    }

    public function testATabInsteadOfTheSeparatorIsStillProse(): void
    {
        // The neighbouring rule. Widening the separator to accept a tab would
        // make this a term too, and corpus 176 says it is prose.
        $this->assertStringNotContainsString('<dt>', $this->html("::\tx\n"));
    }

    public function testOrdinarySpacingIsUnchanged(): void
    {
        $this->assertSame('<dl> <dt>x</dt> </dl>', $this->html(":: x\n"));
        $this->assertSame('<dl> <dt>x</dt> </dl>', $this->html(":: x\n"));
    }

    public function testAMarkerWithNoTermIsNotATerm(): void
    {
        $this->assertStringNotContainsString('<dt>', $this->html(":: \n"));
        $this->assertStringNotContainsString('<dt>', $this->html(":: \t\n"));
    }

    public function testAThreeColonMarkerIsUntouched(): void
    {
        // `(?!:)` is unchanged: `:::` opens a div, not a definition term.
        $this->assertStringNotContainsString('<dt>', $this->html(":::  x\n"));
    }

    public function testTheTermStripsItsTabWithADescriptionToo(): void
    {
        // The whole construct, not the term line alone - this is the path that
        // goes through the PREFIX constant.
        $html = $this->html(":: \tterm\n:  body\n");

        $this->assertStringContainsString('<dt>term</dt>', $html);
        $this->assertStringContainsString('<dd>', $html);
    }
}
