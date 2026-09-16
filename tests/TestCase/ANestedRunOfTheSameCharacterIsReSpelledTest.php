<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#1982. A parent and its only child that spell their delimiters with
 * the same character emit one run on each side, and the reader re-pairs it by
 * its own rule. Where the two strengths DIFFER that is the round-trip
 * normalization list's second entry and the document is the same; where they
 * are EQUAL the runs collapse into one element of the wrong kind.
 *
 * Read back with markdown-it-py 3.0.0 (`commonmark` preset, `strikethrough`
 * enabled) and pulldown-cmark 0.13.4; both give the same answer here.
 */
class ANestedRunOfTheSameCharacterIsReSpelledTest extends TestCase
{
    private function md(string $carve): string
    {
        return CarveConverter::markdown()->convert($carve);
    }

    private function html(string $carve): string
    {
        return (new CarveConverter())->convert($carve);
    }

    public function testEmphasisInsideEmphasisIsSeparatedWhereFourAsterisksReadAsOneStrong(): void
    {
        $this->assertSame("<em>*x*</em>\n", $this->md("/{/x/}/\n"));
        $this->assertSame("<p><em><em>x</em></em></p>\n", $this->html("/{/x/}/\n"));
    }

    public function testStrongInsideStrongIsSeparatedWhereEightAsterisksSpellOnlyByLuck(): void
    {
        $this->assertSame("<strong>**x**</strong>\n", $this->md("*{*x*}*\n"));
    }

    public function testABoldItalicIsLeftAloneBecauseItsTwoStrengthsCommute(): void
    {
        $this->assertSame("***x***\n", $this->md("/*x*/\n"));
        $this->assertSame("***x***\n", $this->md("/{*x*}/\n"));
    }

    public function testPaddingAroundANestedRunDoesNotCountAsContent(): void
    {
        $this->assertSame("a ***b*** c\n", $this->md("a{* {/b/} *}c\n"));
    }

    public function testANestingWhoseTwoSpellingsShareNoCharacterIsLeftAlone(): void
    {
        $this->assertSame("*~~x~~*\n", $this->md("/{~x~}/\n"));
    }

    /**
     * markup-carve/carve-php#1999: a child of a DIFFERENT strength at an edge
     * nests, so the run stays and the engines write the same bytes. The ruling
     * recorded on markup-carve/carve-js#1736 picks the plain spelling.
     */
    public function testAStrongThatClosesAnEmphasisKeepsTheRun(): void
    {
        $this->assertSame("*italic **bold***\n", $this->md("{/italic *bold*/}\n"));
    }

    public function testAnEmphasisThatClosesAStrongKeepsTheRun(): void
    {
        $this->assertSame("**bold *italic***\n", $this->md("{*bold /italic/*}\n"));
    }

    public function testAChildThatOpensTheContentKeepsTheRun(): void
    {
        $this->assertSame("***bold** italic*\n", $this->md("{/*bold* italic/}\n"));
    }

    public function testTheRunStaysIntraword(): void
    {
        $this->assertSame("a*x **y***b\n", $this->md("a{/x *y*/}b\n"));
    }

    public function testAnEqualStrengthChildThatOpensTheContentIsReSpelled(): void
    {
        $this->assertSame("<em>*x* tail</em>\n", $this->md("/{/x/} tail/\n"));
    }

    public function testAnEqualStrengthChildThatClosesItIsReSpelled(): void
    {
        $this->assertSame("<em>head *x*</em>\n", $this->md("/head {/x/}/\n"));
    }

    public function testALiteralTheRendererDidNotEscapeAtTheEdgeIsReSpelled(): void
    {
        $this->assertSame("<em>*\\*\\*x*</em>\n", $this->md("{//**x//}\n"));
    }

    public function testAnEscapedEdgeCharacterDoesNotReachTheRun(): void
    {
        $this->assertSame("*x\\**\n", $this->md("{/x\\*/}\n"));
    }
}
