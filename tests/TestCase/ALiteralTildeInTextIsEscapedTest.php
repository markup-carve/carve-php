<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Strike;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#1976. GFM's strikethrough extension pairs a run of ONE OR TWO
 * tildes, so a literal tilde in a text node is a Markdown metacharacter and
 * PART 11 §8 M1 escapes it. §8a narrows `_`, `#`, `[` and `<` and M1d leaves
 * every other metacharacter on M1, so the tilde takes no narrowing of its own.
 *
 * Unescaped, the reader does the pairing the renderer never asked for: two
 * literal tildes anywhere in one paragraph close over whatever markup stands
 * between them and the tags interleave.
 *
 * Read back with markdown-it-py 3.0.0 (`commonmark` preset, `strikethrough`
 * enabled) and with pulldown-cmark 0.13.4 (ENABLE_STRIKETHROUGH). The two
 * readers disagree about the one-tilde form, which is why both are used.
 */
class ALiteralTildeInTextIsEscapedTest extends TestCase
{
    private function md(string $carve): string
    {
        return CarveConverter::markdown()->convert($carve);
    }

    public function testItEscapesThePairThatClosesOverTheMarkupBetweenIt(): void
    {
        $this->assertSame("<u>\\~\\~x</u>~~y~~\\~\\~b\n", $this->md("{_~~x_}{~y~}~~b\n"));
    }

    public function testItEscapesATextPairAtTheStartOfALine(): void
    {
        $this->assertSame("\\~\\~*x\\~\\~*\n", $this->md("~~{/x~~/}\n"));
    }

    public function testItEscapesASingleTildeWhichTheOneTildeGfmFormPairs(): void
    {
        $this->assertSame("a \\~ b \\~ c\n", $this->md("a ~ b ~ c\n"));
    }

    public function testItEscapesTheTildeOfAPathWhichIsTheCostOfTheRule(): void
    {
        $this->assertSame("\\~/home/user\n", $this->md("~/home/user\n"));
    }

    public function testItEscapesATildeInATableCellWhichIsTextLikeAnyOther(): void
    {
        $this->assertSame("| a\\~\\~b |\n", $this->md("| a~~b |\n"));
    }

    /**
     * A control: the renderer's own strike delimiters are built on a different
     * path from escapeText(), so no mutation of the tilde rule can reach them.
     */
    public function testItLeavesAStrikeDelimiterTheRendererEmittedAlone(): void
    {
        $this->assertSame("~~x~~\n", $this->md("{~x~}\n"));
    }

    /**
     * A control, for the same reason: code is verbatim and never reaches
     * escapeText() at all.
     */
    public function testItLeavesATildeInsideACodeSpanAlone(): void
    {
        $this->assertSame("a `x~~y` b\n", $this->md("a `x~~y` b\n"));
    }

    /**
     * A control: M2 already emits an authored escape AS an escape, whatever the
     * character, so the byte is the same with the tilde rule and without it.
     */
    public function testItLeavesAnAuthoredEscapeAsTheEscapeM2MakesOfIt(): void
    {
        $this->assertSame("a \\~ b\n", $this->md("a \\~ b\n"));
    }

    /**
     * The seam rule of carve-php#1974 still has a shape that reaches it. A run
     * grown by a tilde from TEXT cannot happen any more - M1 escapes that tilde
     * before the seam pass sees it - but a run grown by a NESTED STRIKE'S OWN
     * delimiter can, and that shape is unreachable from Carve source: a strike
     * marker closes the strike, so only a directly built AST holds one.
     *
     * Without this row contentGrowsRun() would be a check nothing can fire.
     */
    public function testANestedStrikeStillGrowsTheRunItSitsIn(): void
    {
        $inner = new Strike();
        $inner->appendChild(new Text('y'));
        $outer = new Strike();
        $outer->appendChild($inner);
        $outer->appendChild(new Text('z'));
        $paragraph = new Paragraph();
        $paragraph->appendChild($outer);
        $document = new Document();
        $document->appendChild($paragraph);

        $this->assertSame("<del>~~y~~z</del>\n", (new MarkdownRenderer())->render($document));
    }
}
