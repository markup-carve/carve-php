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
     * A block nests in the description whenever the run reaches column 3, and
     * nests identically however the run is spelled.
     *
     * The SECOND spelling in isolation is invisible in the paragraph shape,
     * because a paragraph line that Form A rejects still folds by LAZY
     * continuation and renders the same text. A block opener does not: folded
     * lazily it keeps its indentation and comes back as paragraph text, so this
     * is where the two spellings separate. carve-rs renders every row here the
     * same for a tab and for three spaces.
     */
    #[DataProvider('blockBodyProvider')]
    public function testAnIndentedBlockNestsInTheDescriptionAtColumnThree(string $body): void
    {
        $reference = $this->html(":: t\n:  a\n\n" . str_replace('%s', '   ', $body));

        foreach (self::reachingRunProvider() as $label => [$run]) {
            $this->assertSame(
                $reference,
                $this->html(":: t\n:  a\n\n" . str_replace('%s', $run, $body)),
                $label,
            );
        }
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
