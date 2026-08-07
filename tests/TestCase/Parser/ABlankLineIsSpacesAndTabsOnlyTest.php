<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\Utility\IndentationHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A blank line holds spaces and tabs, and nothing else.
 *
 * `blank_line = {whitespace}, newline` over `whitespace = ' ' | '\t'`
 * (grammar.ebnf PART 2 and PART 7). Two characters are named, so a line holding
 * any third one is CONTENT - however invisible that character renders. The
 * leading indentation run is the same position and the same two characters:
 * `indent = whitespace, {whitespace}`.
 *
 * This engine delegated both to PHP's `trim()`/`ltrim()`, whose default
 * charlist is `" \t\n\r\0\x0B"`. That admits U+000B LINE TABULATION, which no
 * production names, so a line holding only a vertical tab ended a paragraph
 * here while carve-rs read it as content (carve-php#963). The same charlist had
 * already let a vertical tab through three other slots (carve-php#955).
 *
 * The remaining two extras cannot reach a line's blankness test at all, and
 * saying so is the point of naming them: `\n` and `\r` are consumed by the line
 * split before any line exists, and `\0` is replaced by U+FFFD before parsing.
 * U+000B is the one character the charlist actually moved - which is why these
 * assert the CLASS ("a character outside the production is content") rather
 * than the single character, and why the form feed rides along as the control:
 * it is equally absent from the production and was already content here, so the
 * two must render alike.
 *
 * Pinned in code rather than as a corpus fixture on purpose: a document whose
 * entire meaning is an invisible character does not survive the first tool that
 * reformats it (markup-carve/carve#755).
 */
class ABlankLineIsSpacesAndTabsOnlyTest extends TestCase
{
    /**
     * U+000B LINE TABULATION - in `trim()`'s charlist, in no production.
     *
     * @var string
     */
    protected const VT = "\x0b";

    /**
     * U+000C FORM FEED - the control: outside both, and already content here.
     *
     * @var string
     */
    protected const FF = "\x0c";

    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    /**
     * Every shape carries a `%s` where the probed line goes.
     *
     * @return array<string, array{0: string}>
     */
    public static function shapeProvider(): array
    {
        return [
            'between two paragraph lines' => ["a\n%s\nb\n"],
            'between two list items' => ["- a\n%s\n- b\n"],
            'before an item continuation' => ["- a\n%s\n  b\n"],
            'between two block-quote lines' => ["> a\n%s\n> b\n"],
            'between two definition entries' => [":: t\n:  a\n%s\n:: u\n:  b\n"],
            'before a definition continuation' => [":: t\n:  a\n%s\n   b\n"],
            'before a link reference definition' => ["[x][r]\n%s\n[r]: /u\n"],
            'inside a footnote body' => ["[^f]\n\n[^f]: a\n%s\n    b\n"],
            'between two table rows' => ["| a |\n%s\n| b |\n"],
            'after a heading' => ["# h\n%s\ntext\n"],
            'as the whole document' => ["%s\n"],
            'as the first line' => ["%s\na\n"],
        ];
    }

    /**
     * A line holding only U+000B renders exactly as a line holding only a form
     * feed: both are characters the production does not name, so both are
     * content. Before the fix the vertical tab ended the block in every one of
     * these shapes and the form feed did not.
     */
    #[DataProvider('shapeProvider')]
    public function testAVerticalTabLineIsContentLikeAnyOtherNonWhitespace(string $shape): void
    {
        $vertical = $this->html(sprintf($shape, self::VT));
        $form = $this->html(sprintf($shape, self::FF));

        $this->assertSame(str_replace(self::FF, self::VT, $form), $vertical);
    }

    /**
     * The other half: the character the author typed is still in the output.
     * Reading the line as blank DELETED it, so a document could not round-trip
     * through the parser at all.
     */
    #[DataProvider('shapeProvider')]
    public function testAVerticalTabLineSurvivesIntoTheOutput(string $shape): void
    {
        $this->assertStringContainsString(self::VT, $this->html(sprintf($shape, self::VT)));
    }

    /**
     * A LEADING vertical tab is content too - `indent` names the same two
     * characters. Stripping it as indentation deleted it from the line.
     */
    public function testALeadingVerticalTabIsNotIndentation(): void
    {
        $this->assertStringContainsString(self::VT, $this->html("a\n" . self::VT . "z\nb\n"));
    }

    /**
     * The definition body's Form A branch: a leading vertical tab on a
     * CONTINUATION line is content, not indentation.
     *
     * `indent = whitespace, {whitespace}` names a space and a tab, so the strip
     * that removes a continuation line's indentation carries those two and
     * stops at anything else. Spelled `ltrim($contLine)` it carried PHP's
     * default charlist as well, and the vertical tab was deleted from the line.
     *
     * NOT COVERED BY THE SHAPES ABOVE, which put the probed character ALONE on
     * a line: alone it is not blank, so it never reaches this strip. Reaching it
     * needs a line that is a valid continuation - indented to the body column -
     * and then carries the character as its first content byte
     * (markup-carve/carve-php#970).
     */
    public function testALeadingVerticalTabSurvivesADefinitionContinuation(): void
    {
        $vertical = $this->html(":: t\n:  a\n   " . self::VT . "b\n");
        $form = $this->html(":: t\n:  a\n   " . self::FF . "b\n");

        // The character is still the first byte of the folded line.
        $this->assertStringContainsString('<dd>a' . "\n" . self::VT . 'b</dd>', $vertical, $vertical);
        // And it renders exactly as the form feed does, which was already
        // content here - same position, same production, same answer.
        $this->assertSame(str_replace(self::FF, self::VT, $form), $vertical);
    }

    /**
     * The definition body's blank-line LOOKAHEAD: a line of spaces then a
     * vertical tab CONTINUES the body rather than ending it.
     *
     * After a blank line the parser looks ahead to decide whether the blank is
     * an internal paragraph break or the end of the body. That predicate is the
     * blank-line rule again, in a second place and with a different job.
     * Spelled `trim($after) !== ''` it read a line of spaces and a vertical tab
     * as blank, so the body ENDED and the line became a top-level paragraph
     * after the list.
     *
     * THE CHECK IS LIVE, NOT DEAD, which is what makes this reachable at all:
     * the loop that skips the blank run uses the same rule, so a line holding a
     * vertical tab is NOT skipped and does reach the lookahead.
     *
     * THE ASSERTIONS ARE STRUCTURAL ONLY - nothing follows the `</dl>` - and
     * that is deliberate, because it is what ISOLATES this predicate from the
     * strip pinned above. Reverting the strip keeps the paragraph inside the
     * `<dd>` and only empties it, so a structural assertion still passes;
     * reverting this predicate moves the paragraph out of the list entirely.
     * Asserting here that the vertical tab survives into the output would fail
     * under BOTH reverts and would pin neither of them on its own - it is the
     * other test's job, in the other position.
     */
    public function testALineOfSpacesAndAVerticalTabContinuesADefinitionBody(): void
    {
        $vertical = $this->html(":: t\n:  a\n\n   " . self::VT . "\n   c\n");

        $this->assertStringEndsWith("</dl>\n", $vertical, $vertical);
        $this->assertStringNotContainsString("</dl>\n<p>", $vertical, $vertical);

        // The control, one character over: a form feed was already content in
        // this position and continued the body, so the two must agree on where
        // the body ends.
        $form = $this->html(":: t\n:  a\n\n   " . self::FF . "\n   c\n");
        $this->assertStringEndsWith("</dl>\n", $form, $form);
        $this->assertStringNotContainsString("</dl>\n<p>", $form, $form);

        // And the boundary the predicate still has to hold. A run of spaces
        // with nothing else IS blank, so it is skipped and the decision falls
        // to the line after it: at column 0 that ends the body, and the
        // paragraph lands outside the list.
        $spaces = $this->html(":: t\n:  a\n\n   \nc\n");
        $this->assertStringContainsString("</dl>\n<p>c</p>", $spaces, $spaces);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function blankProvider(): array
    {
        return [
            'empty' => ['', true],
            'one space' => [' ', true],
            'one tab' => ["\t", true],
            'spaces and tabs' => [" \t  \t", true],
            'U+000B LINE TABULATION' => ["\x0b", false],
            'a space then U+000B' => [" \x0b", false],
            'U+000B then a space' => ["\x0b ", false],
            'U+0000 NUL' => ["\x00", false],
            'U+000C FORM FEED' => ["\x0c", false],
            'U+001F UNIT SEPARATOR' => ["\x1f", false],
            'U+00A0 NO-BREAK SPACE' => ["\u{00a0}", false],
            'U+2000 EN QUAD' => ["\u{2000}", false],
            'U+3000 IDEOGRAPHIC SPACE' => ["\u{3000}", false],
            'U+FEFF BYTE ORDER MARK' => ["\u{feff}", false],
            'ordinary text' => ['x', false],
        ];
    }

    /**
     * The rule itself, stated once and asserted directly. `trim()` answered
     * `true` for the two `\x0b` rows and for `\x00`.
     */
    #[DataProvider('blankProvider')]
    public function testTheHelperNamesSpaceAndTabAndNothingElse(string $line, bool $blank): void
    {
        $this->assertSame($blank, IndentationHelper::isBlankLine($line));
    }
}
