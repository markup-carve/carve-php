<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#1971, the third rule in this renderer after the padding family
 * (carve-php#1967).
 *
 * A delimiter run only CLOSES emphasis while it is right-flanking and only
 * OPENS it while it is left-flanking (CommonMark 6.2). A run whose inner
 * neighbour is punctuation needs an outer neighbour that is whitespace or
 * punctuation; against an alphanumeric it can do neither, and the emphasis
 * reaches the reader as literal text. Unlike the padding family this needs no
 * padding at all - what decides it is the SIBLING across the seam, so it is
 * answered where the siblings are joined and nowhere else.
 *
 * Read back with markdown-it-py 3.0.0, `commonmark` preset with the
 * `strikethrough` rule ENABLED - the default preset has GFM strikethrough off,
 * which makes every `~~` row read literal for an unrelated reason.
 */
class AMarkdownRunThatCannotFlankFallsBackTest extends TestCase
{
    private function md(string $carve): string
    {
        return CarveConverter::markdown()->convert($carve);
    }

    public function testRespellsStrongWhenTheContentEndsInPunctuationAndALetterFollows(): void
    {
        $this->assertSame("a <strong>x!</strong>b\n", $this->md("a {*x!*}b\n"));
    }

    public function testRespellsEmphasisInTheSameClosingSeam(): void
    {
        $this->assertSame("a <em>x!</em>b\n", $this->md("a {/x!/}b\n"));
    }

    public function testRespellsStrikeInTheSameClosingSeam(): void
    {
        $this->assertSame("a <del>x!</del>b\n", $this->md("a {~x!~}b\n"));
    }

    public function testAnswersTheSameWayForADigitAcrossTheSeam(): void
    {
        $this->assertSame("a <strong>x!</strong>1\n", $this->md("a {*x!*}1\n"));
    }

    public function testAnswersTheSameWayForAClosingBracketAsTheInnerNeighbour(): void
    {
        $this->assertSame("a <strong>x)</strong>b\n", $this->md("a {*x)*}b\n"));
    }

    public function testRespellsStrongWhenTheContentStartsWithPunctuationAfterALetter(): void
    {
        $this->assertSame("a<strong>!x</strong>b\n", $this->md("a{*!x*}b\n"));
    }

    public function testRespellsEmphasisInTheSameOpeningSeam(): void
    {
        $this->assertSame("a<em>!x</em>b\n", $this->md("a{/!x/}b\n"));
    }

    public function testRespellsStrikeInTheSameOpeningSeam(): void
    {
        $this->assertSame("a<del>!x</del>b\n", $this->md("a{~!x~}b\n"));
    }

    /**
     * The sentinels stand for `_`, `#` and `[` until the escapes resolve, and
     * they are private-use code points that no punctuation property matches. A
     * flanking test that asked about the carrier instead of the character it
     * stands for would read these three as ordinary letters and leave the dead
     * run in.
     */
    public function testSeesTheUnderscoreBehindItsCarrier(): void
    {
        $this->assertSame("a <strong>x_</strong>b\n", $this->md("a {*x_*}b\n"));
    }

    public function testSeesTheHashBehindItsCarrier(): void
    {
        $this->assertSame("a <strong>x#</strong>b\n", $this->md("a {*x#*}b\n"));
    }

    public function testSeesTheBracketBehindItsCarrier(): void
    {
        $this->assertSame("a <strong>x[</strong>b\n", $this->md("a {*x[*}b\n"));
    }

    /**
     * The punctuation class is CommonMark 0.31's - ASCII punctuation plus
     * Unicode P* AND S*. 0.30 left the symbol categories out, so the two
     * versions disagree about the copyright sign: a 0.30 reader closes the run,
     * a 0.31 reader does not. The wider class is right under both, because
     * inline HTML reads the same way everywhere.
     */
    public function testTreatsASymbolAsPunctuationWhichThePClassAloneWouldNot(): void
    {
        $this->assertSame("a <strong>x\u{00A9}</strong>b\n", $this->md("a {*x\u{00A9}*}b\n"));
    }

    public function testKeepsTheDelimitersAgainstWhitespace(): void
    {
        $this->assertSame("a **x!** b\n", $this->md("a {*x!*} b\n"));
    }

    public function testKeepsTheDelimitersAtTheEndOfTheInput(): void
    {
        $this->assertSame("a **x!**\n", $this->md("a {*x!*}\n"));
    }

    public function testKeepsTheDelimitersAgainstPunctuation(): void
    {
        $this->assertSame("a **x!**, b\n", $this->md("a {*x!*}, b\n"));
    }

    public function testKeepsTheDelimitersAgainstACodeSpanWhoseBacktickIsPunctuation(): void
    {
        $this->assertSame("a **x!**`c`\n", $this->md("a {*x!*}`c`\n"));
    }

    public function testKeepsTheDelimitersWhenTheContentDoesNotEndInPunctuation(): void
    {
        $this->assertSame("a **x**b\n", $this->md("a {*x*}b\n"));
    }

    public function testKeepsTheDelimitersForANestedRunWhoseNeighbourIsTheOuterDelimiter(): void
    {
        $this->assertSame("a <strong>x *y!*</strong>b\n", $this->md("a {*x {/y!/}*}b\n"));
    }

    /**
     * A wrapper line builds its run at the call site and needs no repair: its
     * neighbours are the start of its own line and the newline after it, and
     * both of those flank. The title is the one wrapper that renders inline
     * SIBLINGS of its own, so the seam pass has to reach inside it.
     */
    public function testLeavesADefinitionTermEndingInPunctuationAsADelimiterRun(): void
    {
        $this->assertStringContainsString('**term!**', $this->md(":: term!\n: body\n"));
    }

    public function testLeavesADefinitionTermStartingWithPunctuationAsADelimiterRun(): void
    {
        $this->assertStringContainsString('**!term**', $this->md(":: !term\n: body\n"));
    }

    public function testRepairsARunInsideAnAdmonitionTitle(): void
    {
        $this->assertStringContainsString('**a <em>x!</em>b**', $this->md("::: note \"a {/x!/}b\"\nbody\n:::\n"));
    }

    public function testLeavesARunInsideAnAdmonitionTitleThatCanFlank(): void
    {
        $this->assertStringContainsString('**a *x!* b**', $this->md("::: note \"a {/x!/} b\"\nbody\n:::\n"));
    }
}
