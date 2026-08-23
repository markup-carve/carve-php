<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * PART 11 §2b: the scope of an escalation is the smallest unit that fails.
 *
 * §4's two-render strategy asks whether the minimal form of the WHOLE document
 * re-parses to the same tree, and until this clause landed the answer decided
 * the whole document: one character that genuinely needed its escape put every
 * other candidate into the conservative class with it. §2b bounds the fallback
 * to the smallest unit whose minimal form fails - the inline run, or the block
 * containing it - and every other unit is emitted by §2's own test, which for a
 * character nothing needs means bare.
 *
 * AND INSIDE THE UNIT THAT FAILS, §2's test is taken PER OPENER OCCURRENCE
 * (markup-carve/carve#1533). §2b bounds how far the fallback reaches; it left
 * the unit itself as one knob, so a unit that failed was written conservatively
 * IN FULL and every candidate in it was escaped beside the one that needed it.
 * The cases below pin both halves: which unit escalates, and which occurrences
 * inside it carry a backslash.
 *
 * WHY THE ASSERTIONS ARE ON BYTES. §1 forgives escaping on purpose: both
 * spellings render the same HTML and re-parse to the same tree, so a round-trip
 * check cannot see the difference and neither can the corpus HTML. That is
 * exactly why three engines carried the wider scope with every gate green
 * (markup-carve/carve#1516). The bytes are the only witness, so each case pins
 * them - and then re-parses the written form to show the narrowing did not buy
 * the minimality by changing the document.
 */
class AnEscalationReachesTheBlockThatFailedTest extends TestCase
{
    private function written(string $source): string
    {
        $converter = new CarveConverter();
        $out = CarveConverter::toCarve($source);
        $this->assertSame(
            $converter->convert($source),
            $converter->convert($out),
            'fmt changed the document: ' . json_encode($out),
        );
        $this->assertSame($out, CarveConverter::toCarve($out), 'the written form is not settled');

        return $out;
    }

    /**
     * Indented, so the text IS `## H` rather than a heading. At column zero the
     * minimal form would open one, so this block escalates - in full, by §2's
     * THE UNIT IS THE OPENER: the run is `##`, not its first character.
     */
    public function testABlockWhoseMinimalFormOpensAHeadingItDoesNotHaveEscalates(): void
    {
        $this->assertSame("\\#\\# H\n", $this->written("  ## H\n"));
    }

    public function testABlockWhoseMinimalFormReParsesAsItselfIsLeftAlone(): void
    {
        $this->assertSame("plain (b) text\n", $this->written("plain (b) text\n"));
    }

    /**
     * Corpus 396 in markup-carve/carve#1516. Before §2b the second paragraph
     * came back `plain \(b\) text`, escaped because a DIFFERENT block failed.
     */
    public function testTheEscalationDoesNotSpreadFromTheBlockThatNeededIt(): void
    {
        $this->assertSame("\\#\\# H\n\nplain (b) text\n", $this->written("  ## H\n\nplain (b) text\n"));
    }

    /**
     * `/a/` is written braced, which puts `_b_` after a `}` instead of after a
     * `/` - so the run that was TEXT on the way in would re-parse as emphasis,
     * and it escalates. The run after the code span is in the SAME paragraph
     * and needs nothing, so a fallback that stopped at the block would escape
     * its parentheses too.
     *
     * WITHIN the run the escape is the OPENER's alone (§2, per opener
     * occurrence): emphasis needs both delimiters, so the opening `_` escaped
     * is already the whole suppression and the closing one opens nothing on its
     * own. The unit-scoped form wrote `\\_b\\_` and the second backslash was
     * idle (markup-carve/carve#1533).
     */
    public function testTheInlineRunIsReachedBeforeTheBlockContainingIt(): void
    {
        $this->assertSame("{/a/}\\_b_ `x` plain (d)\n", $this->written("/a/_b_ `x` plain (d)\n"));
    }

    /**
     * The failing occurrence is a `|` opening a table row, and it is a property
     * of the LINE the run begins rather than of the run: both lines of this one
     * paragraph carry one, so both are written conservatively while the
     * paragraph beside them keeps its bare candidates.
     *
     * A ROW OPENS ON ITS LEADING PIPE, so that is the occurrence escaped and
     * the closing pipe stays bare - the block is what escalates, and §2 still
     * decides each occurrence inside it (markup-carve/carve#1533).
     */
    public function testTheUnitWidensToTheBlockWhenEscapingTheRunIsNotEnough(): void
    {
        $this->assertSame(
            "\\| a |\n\\| b |\n\nsee (c) 50% now\n",
            $this->written(" | a |\n | b |\n\nsee (c) 50% now\n"),
        );
    }

    /**
     * The conservative form is still reachable - it is just arrived at because
     * each block needed it, rather than because one did.
     */
    public function testEveryBlockEscalatesInADocumentWhereEveryBlockFails(): void
    {
        $this->assertSame("\\#\\# H\n\n\\#\\#\\# I\n", $this->written("  ## H\n\n  ### I\n"));
    }
}
