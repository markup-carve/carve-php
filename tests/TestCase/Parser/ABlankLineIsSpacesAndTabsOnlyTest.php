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
