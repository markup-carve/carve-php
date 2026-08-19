<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An UNTERMINATED verbatim run in the first cell of a HEADER-LESS row does not
 * stop the line from being a table.
 *
 * A row is split into cells at BLOCK level, before any inline parsing runs -
 * that is what makes a separator row work at all - so a run that never closes
 * is an inline fact reported inside a cell that already exists. This engine
 * used to ask that inline question at block level and answer it by dissolving
 * the block, which no other malformed inline does anywhere in Carve. It also
 * contradicted itself: the identical row under a header separator was a table
 * here already (markup-carve/carve#1284).
 *
 * The row's closing `|` stays a DELIMITER: cells are cut at it before any run
 * is scanned, so the run reaches the end of its CELL and not the end of the
 * line. carve-rs produces every expectation below.
 *
 * Deliberately not asserted: how many cells a row that is SHORT of its header's
 * column count has. That question is open and is not this one.
 */
class UnclosedRunInAHeaderLessRowTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testAHeaderLessRowWithAnUnclosedCodeSpanIsATable(): void
    {
        $expected = <<<'HTML'
        <table>
          <tbody>
            <tr><td>a <code>b | c d</code></td></tr>
          </tbody>
        </table>

        HTML;

        $this->assertSame($expected, $this->converter->convert("| a `b | c d |\n"));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function runKindProvider(): array
    {
        return [
            'code span' => ['`', '<td>a <code>b | c d</code></td>'],
            'inline math' => ['$`', '<td>a <span class="math inline">\(b | c d\)</span></td>'],
            'inline literal' => ['!`', '<td>a b | c d</td>'],
        ];
    }

    /**
     * Every run kind that opens with a backtick reads the same: the run ends at
     * the cell boundary, and the closing pipe is not part of it.
     *
     * @param string $opener
     * @param string $cell
     */
    #[DataProvider('runKindProvider')]
    public function testTheRunStopsAtTheRowsClosingPipe(string $opener, string $cell): void
    {
        $html = $this->converter->convert('| a ' . $opener . "b | c d |\n");

        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString($cell, $html);
        $this->assertStringNotContainsString('<p>|', $html);
    }

    public function testTheSameRowUnderAHeaderSeparatorIsUnchanged(): void
    {
        // This shape was already a table here, and its run already stopped at
        // the pipe. It is the contradiction the fix removes, so it has to hold
        // still afterwards.
        $html = $this->converter->convert("| a | b |\n|---|---|\n| x `y | z |\n");

        $this->assertStringContainsString('<thead>', $html);
        $this->assertStringContainsString('<td>x <code>y | z</code></td>', $html);
    }

    public function testAHeaderLessRowWithAClosedRunKeepsBothCells(): void
    {
        // The control that a looser table gate would not move but a looser CELL
        // split would: the pipe between the two cells is outside the run, so it
        // still splits them.
        $expected = <<<'HTML'
        <table>
          <tbody>
            <tr><td>a <code>b</code></td><td>c d</td></tr>
          </tbody>
        </table>

        HTML;

        $this->assertSame($expected, $this->converter->convert("| a `b` | c d |\n"));
    }

    public function testAnOrdinaryHeaderedTableIsUnchanged(): void
    {
        $expected = <<<'HTML'
        <table>
          <thead><tr><th scope="col">a</th><th scope="col">b</th></tr></thead>
          <tbody>
            <tr><td>x</td><td>y</td></tr>
          </tbody>
        </table>

        HTML;

        $this->assertSame($expected, $this->converter->convert("| a | b |\n|---|---|\n| x | y |\n"));
    }

    public function testALineThatNeverClosesItsRowIsStillProse(): void
    {
        // The gate that went is about the run, not about the pipe: a line with
        // no closing `|` at all is still a paragraph.
        $this->assertSame("<p>| a <code>b c d</code></p>\n", $this->converter->convert("| a `b c d\n"));
    }

    public function testTheRowStillCarriesGluedRowAttributes(): void
    {
        $html = $this->converter->convert("| a `b | c d |{.x}\n");

        $this->assertStringContainsString('<tr class="x">', $html);
        $this->assertStringContainsString('<td>a <code>b | c d</code></td>', $html);
    }

    public function testAFollowingRowJoinsTheSameTable(): void
    {
        // Before the fix the first line was a paragraph and the second opened a
        // table of its own, so the two were not one block.
        $html = $this->converter->convert("| a `b | c d |\n| e | f |\n");

        $this->assertSame(1, substr_count($html, '<table>'));
        $this->assertStringContainsString('<td>a <code>b | c d</code></td>', $html);
        $this->assertStringContainsString('<td>e</td><td>f</td>', $html);
    }

    public function testAContinuationRowStillClosesARunFromTheRowAbove(): void
    {
        // The `+` multi-line cell is untouched: the run opened on the base row
        // still reaches its closer on the continuation row.
        $html = $this->converter->convert("| a `b |\n+ c` |\n");

        $this->assertStringContainsString('<td>a <code>b c</code></td>', $html);
    }
}
