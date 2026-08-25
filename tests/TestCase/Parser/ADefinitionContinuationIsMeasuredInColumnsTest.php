<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A definition body's continuation is measured in COLUMNS.
 *
 * `definition_continuation = (space, space, space, inline_content, newline)` is
 * a leading INDENTATION run, which is the one position where a tab is syntax
 * (grammar.ebnf PART 7, MARKER SEPARATORS AND PADDING SLOTS). PART 9 §24 C1
 * measures such a run in visual columns, a tab advancing to the next multiple
 * of 4. So the threshold is column 3, and every run that reaches it continues
 * the body whatever characters it is spelled with - the direction ruled by
 * markup-carve/carve#888 (signoff `direction=27fba08112af`) and reaffirmed by
 * markup-carve/carve#901.
 *
 * This engine counted leading SPACES, so a tab never continued the body and a
 * mixed run continued it only once three spaces had appeared (carve-php#964).
 * Four readers had four spellings of the one rule; carve-rs is the conforming
 * one and this converges with it.
 *
 * The rule is spelled TWICE here, with different jobs, and the two are on the
 * same path: a blank-line LOOKAHEAD decides whether the blank is an internal
 * paragraph break or the end of the body, and FORM A decides whether the
 * indented line itself folds in. Mutating either alone still renders something
 * - breaking Form A alone lets the line fold by LAZY continuation instead,
 * which keeps the run verbatim - so these compare the whole rendering against
 * the three-space spelling rather than merely looking for the text.
 */
class ADefinitionContinuationIsMeasuredInColumnsTest extends TestCase
{
    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    /**
     * Runs that REACH column 3. Spelled differently, they are the same claim.
     *
     * @return array<string, array{0: string}>
     */
    public static function reachingRunProvider(): array
    {
        return [
            'three spaces' => ['   '],
            'four spaces' => ['    '],
            'a tab' => ["\t"],
            'a space then a tab' => [" \t"],
            'two spaces then a tab' => ["  \t"],
            'three spaces then a tab' => ["   \t"],
            'a tab then a space' => ["\t "],
            'two tabs' => ["\t\t"],
        ];
    }

    /**
     * Runs that do NOT reach column 3.
     *
     * @return array<string, array{0: string}>
     */
    public static function shortRunProvider(): array
    {
        return [
            'one space' => [' '],
            'two spaces' => ['  '],
        ];
    }

    /**
     * A blank-separated continuation folds in whenever the run reaches column
     * 3, and folds in the SAME WAY however it is spelled: the run is
     * indentation and is removed, so the output is the three-space output
     * byte for byte.
     */
    #[DataProvider('reachingRunProvider')]
    public function testABlankSeparatedContinuationFoldsInAtColumnThree(string $run): void
    {
        $this->assertSame(
            $this->html(":: t\n:  a\n\n   b\n"),
            $this->html(":: t\n:  a\n\n" . $run . "b\n"),
        );
    }

    /**
     * Block bodies, one line per element, `%s` marking every indent.
     *
     * A LIST is deliberately not the only one here, and on its own it proves
     * nothing about Form A: a list marker never interrupts (§10 I2), so a
     * lazily folded `- x` still opens a list once the body is re-parsed and the
     * two paths agree by accident. Every other opener in this set is recognized
     * only at the body's column 0, so it survives ONLY if Form A dedented the
     * line - which is what makes these the assertions that separate the two
     * spellings.
     *
     * @return array<string, array{0: string}>
     */
    public static function blockBodyProvider(): array
    {
        return [
            'a list' => ["%s- x\n%s- y\n"],
            'a heading' => ["%s# h\n"],
            'a block quote' => ["%s> q\n"],
            'a fenced code block' => ["%s```\n%sc\n%s```\n"],
            'a thematic break' => ["%s---\n"],
            'a table row' => ["%s| a |\n"],
        ];
    }

    /**
     * THE CLAIM IS ABOUT COLUMNS, and it is that two runs of the SAME COLUMN
     * COUNT render identically however they are spelled - which is what
     * markup-carve/carve#888 ruled and all this file's other assertions ask
     * for. A tab is four columns, so it renders like four spaces and not like
     * three.
     *
     * This asked for something wider: that every run reaching column 3 render
     * identically, tab and three spaces alike. That held only while the body
     * arrived `ltrim`ed, which threw away how far past the column a line sat -
     * and threw away with it the number PART 9 §16's footnote body column and
     * a nested list's own column are both measured against
     * (markup-carve/carve-php#1650). carve-js `ba42673` renders a tab like four
     * spaces in every row here; measured against it, the two engines now agree
     * on all 48 combinations of these six bodies and eight runs.
     *
     * The SECOND spelling in isolation is invisible in the paragraph shape,
     * because a paragraph line that Form A rejects still folds by LAZY
     * continuation and renders the same text. A block opener does not: folded
     * lazily it keeps its indentation and comes back as paragraph text, so this
     * is where the two spellings separate.
     *
     * @return array<string, array{0: int, 1: array<string>}>
     */
    public static function columnGroupProvider(): array
    {
        return [
            'column 3' => [3, ['   ']],
            'column 4' => [4, ['    ', "\t", " \t", "  \t", "   \t"]],
            'column 5' => [5, ["\t "]],
            'column 8' => [8, ["\t\t"]],
        ];
    }

    #[DataProvider('blockBodyProvider')]
    public function testRunsOfTheSameColumnCountRenderIdentically(string $body): void
    {
        foreach (self::columnGroupProvider() as $group => [$columns, $runs]) {
            $reference = $this->html(":: t\n:  a\n\n" . str_replace('%s', $runs[0], $body));
            foreach ($runs as $run) {
                $this->assertSame(
                    $reference,
                    $this->html(":: t\n:  a\n\n" . str_replace('%s', $run, $body)),
                    $group . ': ' . json_encode($run),
                );
            }
        }
    }

    /**
     * A recognized opener at or past the minimum uses an authored base. A tab
     * reaching the same visual column gives the same result.
     */
    public function testTheColumnAndAuthoredBasesNest(): void
    {
        $this->assertStringContainsString('<h1', $this->html(":: t\n:  a\n\n   # h\n"));
        $this->assertStringContainsString('<h1', $this->html(":: t\n:  a\n\n    # h\n"));
        $this->assertStringContainsString('<h1', $this->html(":: t\n:  a\n\n\t# h\n"));
    }

    /**
     * Below column 3 the blank ends the body, and the line is a block of its
     * own outside the list. This is the row that keeps the fix from becoming
     * "any indent continues".
     */
    #[DataProvider('shortRunProvider')]
    public function testARunBelowColumnThreeEndsTheBody(string $run): void
    {
        $html = $this->html(":: t\n:  a\n\n" . $run . "b\n");

        $this->assertStringContainsString('<dd>a</dd>', $html);
        $this->assertStringContainsString('</dl>', $html);
        $this->assertStringContainsString('<p>b</p>', $html);
        $this->assertLessThan(strpos($html, '<p>b</p>'), (int)strpos($html, '</dl>'));
    }

    /**
     * CONTROL, and labelled as one: with NO blank line the shape proves
     * nothing about this rule. A flush-left line folds by LAZY continuation,
     * which never inspects indentation at all, so every run continues the body
     * before and after the fix and no mutation of the threshold moves it. An
     * earlier probe read this shape as agreement that was not there.
     */
    #[DataProvider('reachingRunProvider')]
    public function testWithNoBlankLineTheRunIsNotConsultedAtAll(string $run): void
    {
        $this->assertStringNotContainsString(
            '</dl>',
            substr($this->html(":: t\n:  a\n" . $run . "b\n"), 0, (int)strpos($this->html(":: t\n:  a\n" . $run . "b\n"), 'b')),
        );
    }

    /**
     * A tab advances to the next multiple of 4, not by a fixed width: this is
     * the value the rest of the engine's column arithmetic uses (PART 9 §24
     * C1), and one space before the tab must not change where it lands.
     */
    public function testATabAdvancesToTheNextTabStop(): void
    {
        $expected = $this->html(":: t\n:  a\n\n   b\n");

        $this->assertSame($expected, $this->html(":: t\n:  a\n\n\tb\n"));
        $this->assertSame($expected, $this->html(":: t\n:  a\n\n \tb\n"));
        $this->assertSame($expected, $this->html(":: t\n:  a\n\n  \tb\n"));
    }
}
