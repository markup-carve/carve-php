<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#1974, the fourth rule in this renderer after the padding family
 * (carve-php#1967) and flanking (carve-php#1971).
 *
 * Runs of the same character that TOUCH are one run to the reader, of their
 * summed length, and the length decides what that run can do. So the run the
 * renderer emitted is not always the run the reader lexes, each half here is
 * individually well-flanked, and no flanking test can see the difference.
 *
 * Read back with markdown-it-py 3.0.0, `commonmark` preset with the
 * `strikethrough` rule ENABLED - the default preset has GFM strikethrough off,
 * which makes every `~~` row read literal for an unrelated reason.
 */
class AdjacentMarkdownRunsMergeTest extends TestCase
{
    private function md(string $carve): string
    {
        return CarveConverter::markdown()->convert($carve);
    }

    /**
     * The content tilde no longer REACHES the run: carve-php#1976 made M1 escape
     * a literal tilde in text, and an escaped character is not part of a
     * delimiter run. The fence these rows exist for is what matters and it is
     * still not openable, so they stay - only the mechanism under them changed.
     */
    public function testATildeInTheContentDoesNotOpenACodeFenceAtTheStartOfALine(): void
    {
        $this->assertSame("~~\\~x~~\n", $this->md("{~~x~}\n"));
    }

    public function testATildeAtTheEndOfTheContentIsAnsweredTheSameWay(): void
    {
        $this->assertSame("~~x\\~~~\n", $this->md("{~x~~}\n"));
    }

    public function testTheSameRunMidLineWhereItIsNotAFence(): void
    {
        $this->assertSame("a ~~\\~x~~b\n", $this->md("a {~~x~}b\n"));
    }

    public function testTheDecisionIsTakenWithNoSiblingToTakeItAgainst(): void
    {
        $this->assertSame("~~\\~x~~\n", $this->md('{~~x~}'));
    }

    /**
     * The text tilde is escaped, so it breaks the run rather than growing it,
     * and the strike beside it keeps its delimiters on both sides.
     */
    public function testTwoTextTildesDoNotOpenACodeFenceWithTheStrikesOwnTwo(): void
    {
        $this->assertSame("\\~\\~~~x~~\n", $this->md("~~{~x~}\n"));
    }

    public function testASingleTextTildeReachingTheRunIsEscaped(): void
    {
        $this->assertSame("a\\~~~x~~\n", $this->md("a~{~x~}\n"));
    }

    public function testATextTildeOnTheClosingSideLeavesTheStrikeSpelled(): void
    {
        $this->assertSame("~~x~~\\~b\n", $this->md("{~x~}~b\n"));
    }

    public function testTwoAdjacentEmphasesCannotBothResolve(): void
    {
        $this->assertSame("a <em>x</em>*y*\n", $this->md("a {/x/}{/y/}\n"));
    }

    public function testTwoAdjacentStrongsCannotEither(): void
    {
        $this->assertSame("a <strong>x</strong>**y**\n", $this->md("a {*x*}{*y*}\n"));
    }

    public function testAChainOfThreeLeavesTheLastOneSpelled(): void
    {
        $this->assertSame("<em>x</em><em>y</em>*z*\n", $this->md("{/x/}{/y/}{/z/}\n"));
    }

    public function testStrongAgainstEmphasisSumsToThreeAndStays(): void
    {
        $this->assertSame("a **x***y*\n", $this->md("a {*x*}{/y/}\n"));
    }

    public function testEmphasisAgainstStrongIsTheSameSumReadTheOtherWay(): void
    {
        $this->assertSame("a *x***y**\n", $this->md("a {/x/}{*y*}\n"));
    }

    public function testThreeAgainstThreeStaysBecauseBothLengthsAreMultiplesOfThree(): void
    {
        $this->assertSame("***x******y***\n", $this->md("{/{*x*}/}{/{*y*}/}\n"));
    }

    public function testTheRunANestedDelimiterLengthensIsMeasuredNotTheNodesOwnWidth(): void
    {
        $this->assertSame("a ***x****y*\n", $this->md("a {/{*x*}/}{/y/}\n"));
    }

    public function testTheRightHandStrikeIsReSpelledBecauseTheReadersSplitFourTildesDifferently(): void
    {
        $this->assertSame("a ~~x~~<del>y</del>\n", $this->md("a {~x~}{~y~}\n"));
    }

    public function testOneStrikeOfAChainOfThreeIsReSpelledWhichBreaksBothSeams(): void
    {
        $this->assertSame("~~x~~<del>y</del>~~z~~\n", $this->md("{~x~}{~y~}{~z~}\n"));
    }

    public function testASpaceBetweenTheSiblingsEndsTheRun(): void
    {
        $this->assertSame("a *x* *y*\n", $this->md("a {/x/} {/y/}\n"));
    }

    public function testTheSpaceThePaddingMovedOutOfTheRunEndsItToo(): void
    {
        $this->assertSame("*x* *y*\n", $this->md("{/x /}{/y/}\n"));
    }

    public function testTheSameSpaceReadOnATildeRun(): void
    {
        $this->assertSame("~~x~~ ~~y~~\n", $this->md("{~x ~}{~y~}\n"));
    }

    public function testASpaceSeparatesAStrikeFromATextTilde(): void
    {
        $this->assertSame("~~x~~ \\~y\n", $this->md("{~x ~}~y\n"));
    }

    public function testTheSameSpaceOnTheOpeningSide(): void
    {
        $this->assertSame("y\\~ ~~x~~\n", $this->md("y~{~ x~}\n"));
    }

    /**
     * A control: `delete` is not spelled with a delimiter run at all, so no
     * mutation of this rule can turn this row red. It is here because a rule
     * that converted on adjacency alone would rewrite it.
     */
    public function testTheInlineHtmlKindsCarryNoRunAndAreLeftAlone(): void
    {
        $this->assertSame("a <del>x</del><del>y</del>\n", $this->md("a {-x-}{-y-}\n"));
    }

    public function testAnEscapedAsteriskIsNotCountedAsPartOfTheRun(): void
    {
        $this->assertSame("<em>x\\*</em>*\\~y*\n", $this->md("{/x*/}{/~y/}\n"));
    }

    public function testTheMergedRunFlanksAgainstTheSiblingsContent(): void
    {
        $this->assertSame("<em>x\\~</em>**y**\n", $this->md("{/x~/}{*y*}\n"));
    }

    public function testTheRightHandSideOfATildeSeamIsReSpelledWhateverTheContent(): void
    {
        $this->assertSame("~~x!~~<del>y</del>\n", $this->md("{~x!~}{~y~}\n"));
    }
}
