<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `whitespace` run at the END of a CONTENT LINE is DROPPED.
 *
 * PART 2's NO TRAILING WHITESPACE clause (markup-carve/carve#926). The rule was
 * already written down for a paragraph's FINAL line and implemented here as a
 * single `rtrim($content)` over the joined paragraph - which by construction
 * could not reach an interior line. So these were two different documents:
 *
 *     abc<SP>
 *     def
 *
 *     abc
 *     def
 *
 * They are one document. PART 12 §7 asserted the opposite and argued from it
 * that stripping would break `to_html(fmt(x)) == to_html(x)`; it has been
 * corrected, and the PARSER is the half that moves.
 *
 * THE RUN IS `whitespace`, AND NOTHING ELSE IS. `' '` or `'\t'`, the same
 * two-character terminal `blank_line = {whitespace}` and `indent` take (PART 1,
 * markup-carve/carve#890). Every other character is CONTENT and survives,
 * however invisible - an implementation that strips with a Unicode whitespace
 * PROPERTY, or with a language's legacy `\s`, fails seven of the nine rows
 * below, and a plain-space fixture cannot see any of it.
 */
class TrailingWhitespaceIsDroppedTest extends TestCase
{
    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    /**
     * One context per row, each at the SOFT BREAK position.
     *
     * The soft break is the discriminating position: the block-final one was
     * already implemented, so a fixture that only tested it would pass against
     * the unfixed parser. Every row here has a line AFTER the one carrying the
     * run.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function softBreakProvider(): array
    {
        return [
            'paragraph, a space' => ["abc \ndef\n", "<p>abc\ndef</p>\n"],
            'paragraph, a tab' => ["abc\t\ndef\n", "<p>abc\ndef</p>\n"],
            'paragraph, a mixed run' => ["abc \t \ndef\n", "<p>abc\ndef</p>\n"],
            'list item' => ["- a \n  b\n", "<ul>\n  <li>a\nb</li>\n</ul>\n"],
            'block quote' => ["> a \n> b\n", "<blockquote><p>a\nb</p></blockquote>\n"],
            'quote inside a list item' => [
                "- > a \n  > b\n",
                "<ul>\n  <li>\n    <blockquote><p>a\nb</p></blockquote>\n  </li>\n</ul>\n",
            ],
        ];
    }

    #[DataProvider('softBreakProvider')]
    public function testTheRunIsDroppedBeforeASoftBreak(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->html($source));
    }

    public function testAFootnoteBodyLineDropsItToo(): void
    {
        $this->assertStringContainsString(
            "<p>a\nb<a href=",
            $this->html("x[^f]\n\n[^f]: a \n    b\n"),
        );
    }

    /**
     * Every character that is NOT `whitespace`, one row each.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function survivingCharacterProvider(): array
    {
        return [
            // The rendered form, not the source byte: the HTML text path
            // serializes U+00A0 to `&nbsp;` (the ATTRIBUTE path does not). Both
            // are the character surviving; asserting the raw byte would fail
            // for a reason that has nothing to do with this rule.
            'no-break space' => ["\u{00A0}", '&nbsp;'],
            'zero width space' => ["\u{200B}", "\u{200B}"],
            'byte order mark' => ["\u{FEFF}", "\u{FEFF}"],
            'en quad' => ["\u{2000}", "\u{2000}"],
            'ideographic space' => ["\u{3000}", "\u{3000}"],
            'form feed' => ["\u{000C}", "\u{000C}"],
            'vertical tab' => ["\u{000B}", "\u{000B}"],
            'next line' => ["\u{0085}", "\u{0085}"],
            'line separator' => ["\u{2028}", "\u{2028}"],
        ];
    }

    #[DataProvider('survivingCharacterProvider')]
    public function testANonWhitespaceCharacterSurvivesAtBothPositions(string $char, string $rendered): void
    {
        // Both positions, because the two are separate code: the block-final
        // `rtrim` and the per-line one. A class widened at one of them only
        // would pass half of this.
        $this->assertStringContainsString($rendered, $this->html("a{$char}\nb\n"), 'soft break');
        $this->assertStringContainsString($rendered, $this->html("a{$char}\n"), 'block final');
    }

    /**
     * `<SP>U+FEFF<SP>` - the shape the ticket was raised on.
     *
     * The BOM is CONTENT and what is dropped is the trailing SPACE, so the BOM
     * was a red herring. The leading space is an indentation run and goes for a
     * different reason.
     */
    public function testTheBomIsContentAndTheSpaceIsWhatGoes(): void
    {
        $this->assertSame("<p>\u{FEFF}</p>\n", $this->html(" \u{FEFF} \n"));
    }

    /**
     * Where the rule does NOT reach.
     *
     * A verbatim payload keeps its bytes; whitespace INSIDE a construct ends at
     * the construct's delimiter rather than at the line's end. Each of these
     * would be broken by a blanket per-line strip applied before parsing, which
     * is why the rule is applied to the folded paragraph and to the line block
     * rather than to the source.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function outOfReachProvider(): array
    {
        return [
            'a fenced code body' => ["```\nabc \n```\n", "<pre><code>abc \n</code></pre>\n"],
            'a code span' => ["`x ` and !`y `\n", "<p><code>x </code> and y </p>\n"],
            'the run before a hard-break backslash' => ["a \\\nb\n", "<p>a <br>\nb</p>\n"],
        ];
    }

    #[DataProvider('outOfReachProvider')]
    public function testTheRuleDoesNotReachIt(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->html($source));
    }

    /**
     * A line block, where the ORDER is what makes the rule reachable at all.
     *
     * PART 9 §23 converts an inner or trailing run of TWO OR MORE columns into
     * NBSP CONTENT first, and content is not whitespace - so the rule never
     * reaches that run. What it does reach is §23's ONE-column case, which is
     * dropped like anywhere else. A patch that ran the strip first would delete
     * the medial gap and pass a one-column fixture anyway.
     */
    public function testALineBlockDropsOnlyItsOneColumnTrailingRun(): void
    {
        $this->assertSame(
            "<div class=\"line-block\">\n  <p>abc&nbsp;&nbsp;<br>\ndef</p>\n</div>\n",
            $this->html("::: |\nabc  \ndef \n:::\n"),
        );
    }

    public function testEveryRowIsStillCovered(): void
    {
        $this->assertCount(6, self::softBreakProvider());
        $this->assertCount(9, self::survivingCharacterProvider());
        $this->assertCount(3, self::outOfReachProvider());
    }
}
