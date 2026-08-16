<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An ESCAPED closing pipe is content, not the row terminator.
 *
 * Ruled on markup-carve/carve#1293 (part 2): the escape is honored wherever it
 * appears, INCLUDING at end of line. carve-js was already correct; carve-php and
 * carve-rs both took `\|` at the end of a row as the terminator and left the
 * backslash orphaned, which the inline parser then read as a hard break - so
 * `| a b \|` rendered `a b <br>` and the literal pipe the author asked for
 * vanished.
 *
 * THE DECIDING FACT IS AN ASYMMETRY INSIDE THIS ENGINE, not a vote. The cell
 * splitter was never escape-blind: it has always honored `\|` MID-cell, so
 * `| a \| b | c |` gives `a | b` + `c` here and always has. The escape was
 * respected at every position except the last one, and a position exception with
 * nothing behind it is what the ruling removed. That mid-cell row is asserted
 * below as a CONTROL for exactly that reason - it is what makes the asymmetry
 * visible, and it must keep passing unchanged.
 *
 * The counter-argument, and why it does not reach: markup-carve/carve#1284 ruled
 * that cells are cut BEFORE inline parsing, so a splitter need not know about
 * escapes. That would support the terminator reading if the splitter WERE
 * escape-blind. It is not, in any engine.
 *
 * There is a plain authoring consequence too: `\|` is the only way to put a
 * literal pipe in a cell, and under the terminator reading it stopped working in
 * the one position an author most naturally reaches for it.
 */
class EscapedClosingPipeIsContentTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    /**
     * The ruled shape and its control, asserted together.
     *
     * They are one provider on purpose. The end-of-line case only means
     * something next to the mid-cell case: alone it reads as a taste call about
     * row terminators, and together the two are one rule applied at two
     * positions.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function escapedPipeProvider(): array
    {
        return [
            // THE RULED SHAPE. Was `<td>a b <br></td>`.
            'an escaped pipe at end of line is a literal pipe' => [
                "| a b \\|\n",
                '<td>a b |</td>',
            ],
            // THE CONTROL. Unchanged by this fix, and the reason the shape above
            // is a defect rather than a preference.
            'an escaped pipe mid-cell is a literal pipe' => [
                "| a \\| b | c |\n",
                '<td>a | b</td><td>c</td>',
            ],
            // The two positions on ONE row, so a fix that handled only the last
            // byte cannot pass by accident.
            'an escaped pipe mid-cell AND at end of line' => [
                "| a \\| b \\|\n",
                '<td>a | b |</td>',
            ],
            // A run of escaped pipes at the end: every one of them is content,
            // so none of them terminates the row.
            'two escaped pipes at end of line' => [
                "| a \\|\\|\n",
                '<td>a ||</td>',
            ],
            // An escaped pipe followed by a REAL terminator still closes the
            // row - the escape does not swallow the delimiter after it.
            'an escaped pipe then an unescaped closer' => [
                "| a \\| |\n",
                '<td>a |</td>',
            ],
        ];
    }

    #[DataProvider('escapedPipeProvider')]
    public function testTheEscapeIsHonoredAtEveryPosition(string $source, string $expectedCells): void
    {
        $out = $this->converter->convert($source);

        $this->assertStringContainsString($expectedCells, preg_replace('/\s*\n\s*/', '', $out) ?? '');
        // The hard break is the FAILURE MODE, not merely a different rendering:
        // it is what an orphaned backslash at end of cell turns into. Asserting
        // its absence names the bug rather than only the fix.
        $this->assertStringNotContainsString('<br>', $out);
    }

    /**
     * PARITY decides, so an escaped BACKSLASH does not escape the pipe after it.
     *
     * `\\` is one escaped backslash, which leaves the following `|` unescaped
     * and therefore still the row terminator: the cell holds a single `\` and
     * the row closes normally. A fix written as "is the byte before the closer a
     * backslash" passes every other row in this file and gets this one wrong, in
     * the direction that eats the terminator - which is why it is asserted
     * separately from the odd-run rows above.
     *
     * This row is UNCHANGED by this PR; it is pinned so the parity rule cannot
     * be dropped later without a red test.
     */
    public function testAnEscapedBackslashLeavesTheClosingPipeADelimiter(): void
    {
        $out = $this->converter->convert("| a b \\\\|\n");

        $this->assertStringContainsString('<td>a b \\</td>', preg_replace('/\s*\n\s*/', '', $out) ?? '');
    }

    /**
     * A `+` continuation row terminates the same way.
     *
     * The continuation row's closing pipe is the same delimiter read by the same
     * splitter, so the ruling reaches it without being extended. (Part 1 of
     * markup-carve/carve#1293 - what a continuation row does with an unclosed
     * verbatim RUN - is a different question, is owed by carve-rs only, and is
     * deliberately not touched here.)
     */
    public function testAContinuationRowHonorsTheEscapeToo(): void
    {
        $out = $this->converter->convert("| a |\n+ b \\|\n");

        $this->assertStringContainsString('<td>a b |</td>', preg_replace('/\s*\n\s*/', '', $out) ?? '');
        $this->assertStringNotContainsString('<br>', $out);
    }

    /**
     * A row still needs a closing pipe BYTE to be a row at all.
     *
     * The scope control. This fix changes what the final `|` MEANS to the cell
     * splitter, never whether `isTableRow()` saw one: a line with no trailing
     * pipe stays a paragraph, exactly as before. Without this row, "the escape
     * is honored" could be widened into "the closing pipe is optional", which
     * the grammar's `standard_row` does not allow and the ruling did not say.
     */
    public function testARowWithNoClosingPipeIsStillAParagraph(): void
    {
        $out = $this->converter->convert("| a b\n");

        $this->assertStringContainsString('<p>| a b</p>', $out);
        $this->assertStringNotContainsString('<table>', $out);
    }
}
