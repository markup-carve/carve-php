<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Block\TableCell;
use PHPUnit\Framework\TestCase;

/**
 * Tests for table colspan and rowspan support.
 *
 * Syntax:
 * - `<` in a cell means it's spanned from the cell to the left (colspan)
 * - `^` in a cell means it's spanned from the cell above (rowspan)
 *
 * @see https://github.com/jgm/djot/issues/368
 */
class TableSpansTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testBasicColspan(): void
    {
        $djot = <<<'DJOT'
| A     | <     |
|-------|-------|
| 1     | 2     |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \MarkupCarve\Carve\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $this->assertInstanceOf(Table::class, $table);

        $rows = $table->getChildren();
        $this->assertCount(2, $rows);

        // Header row keeps a cell for EVERY column - the `<` marker is a
        // placeholder of its own now, not absorbed into "A" (carve-php#527:
        // carve-js parity, uniform row width).
        /** @var \MarkupCarve\Carve\Node\Block\TableRow $headerRow */
        $headerRow = $rows[0];
        $headerCells = $headerRow->getChildren();
        $this->assertCount(2, $headerCells);

        /** @var \MarkupCarve\Carve\Node\Block\TableCell $headerCell */
        $headerCell = $headerCells[0];
        $this->assertSame(2, $headerCell->getColspan());
        $this->assertSame(1, $headerCell->getRowspan());

        /** @var \MarkupCarve\Carve\Node\Block\TableCell $placeholder */
        $placeholder = $headerCells[1];
        $this->assertSame('<', $placeholder->getSpanMarker());
    }

    public function testMultipleColspan(): void
    {
        $djot = <<<'DJOT'
| A     | <     | <     |
|-------|-------|-------|
| 1     | 2     | 3     |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \MarkupCarve\Carve\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        /** @var \MarkupCarve\Carve\Node\Block\TableRow $headerRow */
        $headerRow = $rows[0];
        $headerCells = $headerRow->getChildren();
        // "A" plus its two `<` placeholders - one cell per column.
        $this->assertCount(3, $headerCells);

        /** @var \MarkupCarve\Carve\Node\Block\TableCell $headerCell */
        $headerCell = $headerCells[0];
        $this->assertSame(3, $headerCell->getColspan());
    }

    public function testColspanInMiddle(): void
    {
        $djot = <<<'DJOT'
| A | B     | <     | C |
|---|-------|-------|---|
| 1 | 2     | 3     | 4 |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \MarkupCarve\Carve\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        /** @var \MarkupCarve\Carve\Node\Block\TableRow $headerRow */
        $headerRow = $rows[0];
        $headerCells = $headerRow->getChildren();
        // A, B, B's `<` placeholder, C - one cell per column.
        $this->assertCount(4, $headerCells);

        /** @var \MarkupCarve\Carve\Node\Block\TableCell $cell1 */
        $cell1 = $headerCells[0];
        $this->assertSame(1, $cell1->getColspan());

        /** @var \MarkupCarve\Carve\Node\Block\TableCell $cell2 */
        $cell2 = $headerCells[1];
        $this->assertSame(2, $cell2->getColspan());

        /** @var \MarkupCarve\Carve\Node\Block\TableCell $placeholder */
        $placeholder = $headerCells[2];
        $this->assertSame('<', $placeholder->getSpanMarker());

        /** @var \MarkupCarve\Carve\Node\Block\TableCell $cell3 */
        $cell3 = $headerCells[3];
        $this->assertSame(1, $cell3->getColspan());
    }

    public function testBasicRowspan(): void
    {
        $djot = <<<'DJOT'
| A | B |
|---|---|
| 1 | 2 |
| ^ | 3 |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \MarkupCarve\Carve\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();
        $this->assertCount(3, $rows);

        // First data row should have cell with rowspan=2
        /** @var \MarkupCarve\Carve\Node\Block\TableRow $dataRow1 */
        $dataRow1 = $rows[1];
        $cells1 = $dataRow1->getChildren();
        $this->assertCount(2, $cells1);

        /** @var \MarkupCarve\Carve\Node\Block\TableCell $cell1 */
        $cell1 = $cells1[0];
        $this->assertSame(2, $cell1->getRowspan());

        // Second data row keeps a placeholder cell for the `^` marker (carve-js
        // parity, uniform row width) alongside the real "3" cell.
        /** @var \MarkupCarve\Carve\Node\Block\TableRow $dataRow2 */
        $dataRow2 = $rows[2];
        $cells2 = $dataRow2->getChildren();
        $this->assertCount(2, $cells2);
        $this->assertSame('^', $cells2[0]->getSpanMarker());
    }

    public function testMultipleRowspan(): void
    {
        $djot = <<<'DJOT'
| A | B |
|---|---|
| 1 | 2 |
| ^ | 3 |
| ^ | 4 |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \MarkupCarve\Carve\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();
        $this->assertCount(4, $rows);

        // First data row should have cell with rowspan=3
        /** @var \MarkupCarve\Carve\Node\Block\TableRow $dataRow1 */
        $dataRow1 = $rows[1];
        $cells1 = $dataRow1->getChildren();

        /** @var \MarkupCarve\Carve\Node\Block\TableCell $cell1 */
        $cell1 = $cells1[0];
        $this->assertSame(3, $cell1->getRowspan());
    }

    public function testCombinedRowspanAndColspan(): void
    {
        $djot = <<<'DJOT'
| A     | <     | B |
|-------|-------|---|
| 1     | 2     | 3 |
| ^     | 4     | 5 |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \MarkupCarve\Carve\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        // Header row: "A" with colspan=2, its `<` placeholder, then "B" -
        // one cell per column.
        /** @var \MarkupCarve\Carve\Node\Block\TableRow $headerRow */
        $headerRow = $rows[0];
        $headerCells = $headerRow->getChildren();
        $this->assertCount(3, $headerCells);

        /** @var \MarkupCarve\Carve\Node\Block\TableCell $headerCell1 */
        $headerCell1 = $headerCells[0];
        $this->assertSame(2, $headerCell1->getColspan());

        // First data row: "1" with rowspan=2
        /** @var \MarkupCarve\Carve\Node\Block\TableRow $dataRow1 */
        $dataRow1 = $rows[1];
        $cells1 = $dataRow1->getChildren();

        /** @var \MarkupCarve\Carve\Node\Block\TableCell $dataCell1 */
        $dataCell1 = $cells1[0];
        $this->assertSame(2, $dataCell1->getRowspan());
    }

    public function testColspanHtmlOutput(): void
    {
        $djot = <<<'DJOT'
| Header | <      |
|--------|--------|
| A      | B      |
DJOT;

        $doc = $this->converter->parse($djot);
        $html = $this->converter->render($doc);

        $this->assertStringContainsString('colspan="2"', $html);
        $this->assertStringContainsString('Header', $html);
    }

    public function testRowspanHtmlOutput(): void
    {
        $djot = <<<'DJOT'
| A | B |
|---|---|
| 1 | 2 |
| ^ | 3 |
DJOT;

        $doc = $this->converter->parse($djot);
        $html = $this->converter->render($doc);

        $this->assertStringContainsString('rowspan="2"', $html);
    }

    public function testColspanWithAlignment(): void
    {
        $djot = <<<'DJOT'
| Left   | <      |
|:-------|-------:|
| A      | B      |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \MarkupCarve\Carve\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        /** @var \MarkupCarve\Carve\Node\Block\TableRow $headerRow */
        $headerRow = $rows[0];
        $headerCells = $headerRow->getChildren();

        /** @var \MarkupCarve\Carve\Node\Block\TableCell $headerCell */
        $headerCell = $headerCells[0];
        $this->assertSame(2, $headerCell->getColspan());
        $this->assertSame(TableCell::ALIGN_LEFT, $headerCell->getAlignment());
    }

    public function testColspanWithCellAttributes(): void
    {
        $djot = <<<'DJOT'
|{.highlight} Span | <     |
|------------------|-------|
| A                | B     |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \MarkupCarve\Carve\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        /** @var \MarkupCarve\Carve\Node\Block\TableRow $headerRow */
        $headerRow = $rows[0];
        $headerCells = $headerRow->getChildren();

        /** @var \MarkupCarve\Carve\Node\Block\TableCell $headerCell */
        $headerCell = $headerCells[0];
        $this->assertSame(2, $headerCell->getColspan());
        $this->assertSame('highlight', $headerCell->getAttribute('class'));
    }

    public function testRowspanWithRowAttributes(): void
    {
        $djot = <<<'DJOT'
| A | B |
|---|---|
| 1 | 2 |{.first}
| ^ | 3 |{.second}
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \MarkupCarve\Carve\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        /** @var \MarkupCarve\Carve\Node\Block\TableRow $dataRow1 */
        $dataRow1 = $rows[1];
        $this->assertSame('first', $dataRow1->getAttribute('class'));

        /** @var \MarkupCarve\Carve\Node\Block\TableRow $dataRow2 */
        $dataRow2 = $rows[2];
        $this->assertSame('second', $dataRow2->getAttribute('class'));
    }

    public function testNoSpanWithRegularContent(): void
    {
        // Cells with content other than just < or ^ should not be treated as markers
        $djot = <<<'DJOT'
| A   | B   |
|-----|-----|
| <x  | y<  |
| ^z  | z^  |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \MarkupCarve\Carve\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        // All rows should have 2 cells
        /** @var \MarkupCarve\Carve\Node\Block\TableRow $dataRow1 */
        $dataRow1 = $rows[1];
        $this->assertCount(2, $dataRow1->getChildren());

        /** @var \MarkupCarve\Carve\Node\Block\TableRow $dataRow2 */
        $dataRow2 = $rows[2];
        $this->assertCount(2, $dataRow2->getChildren());
    }

    public function testComplexSpanTable(): void
    {
        // Test a more complex table with multiple spans
        $djot = <<<'DJOT'
| Category | Item   | Price |
|----------|--------|-------|
| Fruits   | Apple  | $1    |
| ^        | Banana | $0.50 |
| ^        | Orange | $0.75 |
| Veggies  | Carrot | $0.30 |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \MarkupCarve\Carve\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();
        $this->assertCount(5, $rows);

        // First data row: "Fruits" should have rowspan=3
        /** @var \MarkupCarve\Carve\Node\Block\TableRow $dataRow1 */
        $dataRow1 = $rows[1];
        $cells1 = $dataRow1->getChildren();

        /** @var \MarkupCarve\Carve\Node\Block\TableCell $categoryCell */
        $categoryCell = $cells1[0];
        $this->assertSame(3, $categoryCell->getRowspan());

        // Rows 2 and 3 keep a placeholder cell for the `^` marker alongside
        // their two real cells (carve-js parity, uniform row width).
        /** @var \MarkupCarve\Carve\Node\Block\TableRow $dataRow2 */
        $dataRow2 = $rows[2];
        $this->assertCount(3, $dataRow2->getChildren());

        /** @var \MarkupCarve\Carve\Node\Block\TableRow $dataRow3 */
        $dataRow3 = $rows[3];
        $this->assertCount(3, $dataRow3->getChildren());

        // Row 4 should have all 3 cells
        /** @var \MarkupCarve\Carve\Node\Block\TableRow $dataRow4 */
        $dataRow4 = $rows[4];
        $this->assertCount(3, $dataRow4->getChildren());
    }

    public function testColspanInDataRow(): void
    {
        $djot = <<<'DJOT'
| A | B | C |
|---|---|---|
| 1 | 2 | < |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \MarkupCarve\Carve\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        /** @var \MarkupCarve\Carve\Node\Block\TableRow $dataRow */
        $dataRow = $rows[1];
        $cells = $dataRow->getChildren();
        // "1", "2", and "2"'s `<` placeholder - one cell per column.
        $this->assertCount(3, $cells);

        /** @var \MarkupCarve\Carve\Node\Block\TableCell $cell2 */
        $cell2 = $cells[1];
        $this->assertSame(2, $cell2->getColspan());

        /** @var \MarkupCarve\Carve\Node\Block\TableCell $placeholder */
        $placeholder = $cells[2];
        $this->assertSame('<', $placeholder->getSpanMarker());
    }

    public function testEscapedMarkers(): void
    {
        // Test that \^ and \< are not treated as markers
        $djot = <<<'DJOT'
| A  | B  |
|----|-----|
| \^ | \< |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \MarkupCarve\Carve\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        /** @var \MarkupCarve\Carve\Node\Block\TableRow $dataRow */
        $dataRow = $rows[1];
        $cells = $dataRow->getChildren();
        $this->assertCount(2, $cells);

        // Both cells should have rowspan=1, colspan=1
        /** @var \MarkupCarve\Carve\Node\Block\TableCell $cell1 */
        $cell1 = $cells[0];
        $this->assertSame(1, $cell1->getRowspan());
        $this->assertSame(1, $cell1->getColspan());
    }

    public function testFullHtmlOutput(): void
    {
        $djot = <<<'DJOT'
| A     | <     |
|-------|-------|
| 1     | 2     |
| ^     | 3     |
DJOT;

        $doc = $this->converter->parse($djot);
        $html = $this->converter->render($doc);

        $expected = <<<'HTML'
<table>
  <thead><tr><th colspan="2">A</th></tr></thead>
  <tbody>
    <tr><td rowspan="2">1</td><td>2</td></tr>
    <tr><td>3</td></tr>
  </tbody>
</table>
HTML;

        $this->assertSame($expected, trim($html));
    }

    /**
     * A `<` whose only neighbour to its left is a `^` rowspan marker has no real
     * cell to merge into (the `^` produces no output cell of its own and its
     * column is taken by the rowspan from above). The blocked `<` must render as
     * an EMPTY cell that occupies its grid position - never dropped, never
     * merged into the marker (carve spec "Table span marker in first column",
     * carve-js / carve-rs parity). Regression guard: the `<` used to be folded
     * into the `^` colspan and silently dropped, shifting later cells left.
     */
    public function testBlockedColspanMarkerRendersAsEmptyCell(): void
    {
        $djot = <<<'DJOT'
| A | B | C |
|---|---|---|
| x | y | z |
| ^ | < | d |
DJOT;

        $html = $this->converter->convert($djot);

        $expected = <<<'HTML'
<table>
  <thead><tr><th>A</th><th>B</th><th>C</th></tr></thead>
  <tbody>
    <tr><td rowspan="2">x</td><td>y</td><td>z</td></tr>
    <tr><td></td><td>d</td></tr>
  </tbody>
</table>
HTML;

        $this->assertSame($expected, trim($html));
    }

    /**
     * Mirror of the blocked-`<` case where the row ends right after the marker:
     * the `<` next to a `^` still becomes an empty cell, not a dropped one.
     */
    public function testBlockedTrailingColspanMarkerRendersAsEmptyCell(): void
    {
        $djot = <<<'DJOT'
| A | B |
|---|---|
| x | y |
| ^ | < |
DJOT;

        $html = $this->converter->convert($djot);

        $expected = <<<'HTML'
<table>
  <thead><tr><th>A</th><th>B</th></tr></thead>
  <tbody>
    <tr><td rowspan="2">x</td><td>y</td></tr>
    <tr><td></td></tr>
  </tbody>
</table>
HTML;

        $this->assertSame($expected, trim($html));
    }

    /**
     * A `<` scans LEFT past a column already consumed by another span and merges
     * into the nearest still-available content cell, becoming an empty cell only
     * if the walk runs off the table edge (carve spec section 96, carve-js /
     * carve-rs parity). Here the second body row is `| p | ^ | < | e |`: the `^`
     * (column 2) continues the rowspan of `b` above it, so column 2 is consumed;
     * the `<` (column 3) walks left, skips that consumed column, and merges into
     * `p` (column 1), giving `p` a colspan of 2. Regression guard: carve-php used
     * to stop at the consumed `^` and emit a separate empty cell instead.
     */
    public function testColspanScansLeftPastConsumedCell(): void
    {
        $djot = <<<'DJOT'
| p | q | r | s |
|---|---|---|---|
| a | b | c | d |
| p | ^ | < | e |
DJOT;

        $html = $this->converter->convert($djot);

        $expected = <<<'HTML'
<table>
  <thead><tr><th>p</th><th>q</th><th>r</th><th>s</th></tr></thead>
  <tbody>
    <tr><td>a</td><td rowspan="2">b</td><td>c</td><td>d</td></tr>
    <tr><td colspan="2">p</td><td>e</td></tr>
  </tbody>
</table>
HTML;

        $this->assertSame($expected, trim($html));
    }

    /**
     * The scan-left walk crosses MULTIPLE consumed columns to reach the nearest
     * available cell. Row `| p | ^ | < | < |`: `b` (column 2) continues its
     * rowspan, and both `<` markers (columns 3 and 4) walk left past the consumed
     * column and chain into `p`, giving it a colspan of 3 (carve-js / carve-rs
     * parity).
     */
    public function testColspanScansLeftPastConsumedCellChained(): void
    {
        $djot = <<<'DJOT'
| p | q | r | s |
|---|---|---|---|
| a | b | c | d |
| p | ^ | < | < |
DJOT;

        $html = $this->converter->convert($djot);

        $expected = <<<'HTML'
<table>
  <thead><tr><th>p</th><th>q</th><th>r</th><th>s</th></tr></thead>
  <tbody>
    <tr><td>a</td><td rowspan="2">b</td><td>c</td><td>d</td></tr>
    <tr><td colspan="3">p</td></tr>
  </tbody>
</table>
HTML;

        $this->assertSame($expected, trim($html));
    }

    /**
     * A header cell promoted by a GFM separator row that carries a colspan does
     * NOT claim the columns it merely covers as a rowspan origin. A `^` in a body
     * row under such a covered column therefore finds no open cell above it and
     * degrades to an empty cell, exactly as in a body-only table (carve-js /
     * carve-rs parity). Regression guard: the separator-promotion path used to
     * seed the origin across the full colspan width, so the `^` wrongly extended
     * the header cell into a spurious `<th rowspan>` crossing thead into tbody.
     */
    public function testHeaderColspanDoesNotSeedCoveredColumnOrigin(): void
    {
        $djot = <<<'DJOT'
| A | < |
|---|---|
| x | ^ |
DJOT;

        $html = $this->converter->convert($djot);

        $expected = <<<'HTML'
<table>
  <thead><tr><th colspan="2">A</th></tr></thead>
  <tbody>
    <tr><td>x</td><td></td></tr>
  </tbody>
</table>
HTML;

        $this->assertSame($expected, trim($html));
    }

    /**
     * A span marker that degrades to its own empty cell still adopts its
     * column's alignment, so it lines up with the real cells below/around it.
     * Here a GFM separator right-aligns column 1; the leading `<` becomes an
     * empty cell and must carry `text-align: right` like any other cell in that
     * column (carve-js / carve-rs parity).
     */
    public function testEmptySpanCellAdoptsSeparatorAlignment(): void
    {
        $djot = <<<'DJOT'
| H | H2 |
|--:|:--|
| < | x |
DJOT;

        $html = $this->converter->convert($djot);

        $expected = <<<'HTML'
<table>
  <thead><tr><th style="text-align: right;">H</th><th style="text-align: left;">H2</th></tr></thead>
  <tbody>
    <tr><td style="text-align: right;"></td><td style="text-align: left;">x</td></tr>
  </tbody>
</table>
HTML;

        $this->assertSame($expected, trim($html));
    }

    public function testColspanAndRowspanSameCell(): void
    {
        // A cell can have both colspan and rowspan
        $djot = <<<'DJOT'
| A | < | B |
|---|---|---|
| 1 | 2 | 3 |
| ^ | 4 | 5 |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \MarkupCarve\Carve\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        // Header: "A" has colspan=2
        /** @var \MarkupCarve\Carve\Node\Block\TableRow $headerRow */
        $headerRow = $rows[0];
        $headerCells = $headerRow->getChildren();

        /** @var \MarkupCarve\Carve\Node\Block\TableCell $headerA */
        $headerA = $headerCells[0];
        $this->assertSame(2, $headerA->getColspan());

        // Data row 1: "1" has rowspan=2
        /** @var \MarkupCarve\Carve\Node\Block\TableRow $dataRow1 */
        $dataRow1 = $rows[1];
        $dataCells1 = $dataRow1->getChildren();

        /** @var \MarkupCarve\Carve\Node\Block\TableCell $cell1 */
        $cell1 = $dataCells1[0];
        $this->assertSame(2, $cell1->getRowspan());
        $this->assertSame(1, $cell1->getColspan());
    }

    public function testMultipleColspanGroups(): void
    {
        // Multiple colspan groups in same row
        $djot = <<<'DJOT'
| A | < | B | < |
|---|---|---|---|
| 1 | 2 | 3 | 4 |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \MarkupCarve\Carve\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        /** @var \MarkupCarve\Carve\Node\Block\TableRow $headerRow */
        $headerRow = $rows[0];
        $headerCells = $headerRow->getChildren();
        // A, A's `<` placeholder, B, B's `<` placeholder - one cell per column.
        $this->assertCount(4, $headerCells);

        /** @var \MarkupCarve\Carve\Node\Block\TableCell $cellA */
        $cellA = $headerCells[0];
        $this->assertSame(2, $cellA->getColspan());
        $this->assertSame('<', $headerCells[1]->getSpanMarker());

        /** @var \MarkupCarve\Carve\Node\Block\TableCell $cellB */
        $cellB = $headerCells[2];
        $this->assertSame(2, $cellB->getColspan());
        $this->assertSame('<', $headerCells[3]->getSpanMarker());
    }

    public function testRowspanAcrossMultipleColumns(): void
    {
        // Rowspan markers in multiple columns
        $djot = <<<'DJOT'
| A | B |
|---|---|
| 1 | 2 |
| ^ | ^ |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \MarkupCarve\Carve\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        // First data row: both cells should have rowspan=2
        /** @var \MarkupCarve\Carve\Node\Block\TableRow $dataRow1 */
        $dataRow1 = $rows[1];
        $cells = $dataRow1->getChildren();

        /** @var \MarkupCarve\Carve\Node\Block\TableCell $cell1 */
        $cell1 = $cells[0];
        $this->assertSame(2, $cell1->getRowspan());

        /** @var \MarkupCarve\Carve\Node\Block\TableCell $cell2 */
        $cell2 = $cells[1];
        $this->assertSame(2, $cell2->getRowspan());

        // Second data row keeps both placeholder cells (both markers extend a
        // rowspan; carve-js parity, uniform row width).
        /** @var \MarkupCarve\Carve\Node\Block\TableRow $dataRow2 */
        $dataRow2 = $rows[2];
        $placeholderCells = $dataRow2->getChildren();
        $this->assertCount(2, $placeholderCells);
        $this->assertSame('^', $placeholderCells[0]->getSpanMarker());
        $this->assertSame('^', $placeholderCells[1]->getSpanMarker());
    }

    public function testLiteralLessThanInCell(): void
    {
        // A cell with "a < b" comparison should not trigger colspan
        $djot = <<<'DJOT'
| Condition | Result |
|-----------|--------|
| a < b     | true   |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \MarkupCarve\Carve\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        /** @var \MarkupCarve\Carve\Node\Block\TableRow $dataRow */
        $dataRow = $rows[1];
        $cells = $dataRow->getChildren();
        $this->assertCount(2, $cells);

        // First cell should contain "a < b"
        /** @var \MarkupCarve\Carve\Node\Block\TableCell $cell1 */
        $cell1 = $cells[0];
        $this->assertSame(1, $cell1->getColspan());
    }

    /**
     * A cell carrying both colspan and rowspan does NOT drop the cells whose
     * grid position falls under its span. Span markers only ever GROW an
     * existing cell's rowspan/colspan or, when blocked, become an empty cell;
     * they never delete a sibling content cell. The cell that lands in the
     * spanned region is rendered as-is, exactly like carve-js and carve-rs
     * (and matching the carve spec walk-and-merge model). Regression guard: an
     * earlier carve-php-only pass dropped such cells, diverging from both
     * reference implementations.
     */
    public function testRowspanColspanIntersectionKeepsOverlappingCells(): void
    {
        // A gains colspan=2 (the `<`) and rowspan=2 (the `^` below it). The
        // `B` in the second body row sits under A's span area but is kept.
        $djot = <<<'DJOT'
|     | H1  | H2  |
|-----|-----|-----|
| L1  | A   | <   |
| L2  | ^   | B   |
DJOT;

        $html = $this->converter->convert($djot);

        $expected = <<<'HTML'
<table>
  <thead><tr><th></th><th>H1</th><th>H2</th></tr></thead>
  <tbody>
    <tr><td>L1</td><td rowspan="2" colspan="2">A</td></tr>
    <tr><td>L2</td><td>B</td></tr>
  </tbody>
</table>
HTML;

        $this->assertSame($expected, trim($html));
    }

    public function testRowspanColspanIntersection3x3KeepsOverlappingCells(): void
    {
        // A gains colspan=3 and rowspan=3; B/C/D/E land under its span area but
        // are all kept (carve-js / carve-rs parity).
        $djot = <<<'DJOT'
|     | H1  | H2  | H3  |
|-----|-----|-----|-----|
| L1  | A   | <   | <   |
| L2  | ^   | B   | C   |
| L3  | ^   | D   | E   |
DJOT;

        $html = $this->converter->convert($djot);

        $expected = <<<'HTML'
<table>
  <thead><tr><th></th><th>H1</th><th>H2</th><th>H3</th></tr></thead>
  <tbody>
    <tr><td>L1</td><td rowspan="3" colspan="3">A</td></tr>
    <tr><td>L2</td><td>B</td><td>C</td></tr>
    <tr><td>L3</td><td>D</td><td>E</td></tr>
  </tbody>
</table>
HTML;

        $this->assertSame($expected, trim($html));
    }

    public function testRowspanColspanNoIntersectionKeepsCells(): void
    {
        // A has colspan=2 but no rowspan into L2
        // X and B should both be kept
        $djot = <<<'DJOT'
|     | H1  | H2  |
|-----|-----|-----|
| L1  | A   | <   |
| L2  | X   | B   |
DJOT;

        $doc = $this->converter->parse($djot);
        $html = $this->converter->render($doc);

        // A should have colspan=2 but no rowspan
        $this->assertStringContainsString('colspan="2"', $html);
        $this->assertStringNotContainsString('rowspan', $html);

        // Both X and B should be in output
        $this->assertStringContainsString('>X<', $html);
        $this->assertStringContainsString('>B<', $html);
    }

    public function testExplicitMarkersFor2x2Block(): void
    {
        // When all cells in the 2x2 area have markers, it creates a proper block
        $djot = <<<'DJOT'
|     | H1  | H2  |
|-----|-----|-----|
| L1  | A   | <   |
| L2  | ^   | ^   |
DJOT;

        $doc = $this->converter->parse($djot);
        $html = $this->converter->render($doc);

        // A should have rowspan=2 colspan=2
        $this->assertStringContainsString('rowspan="2"', $html);
        $this->assertStringContainsString('colspan="2"', $html);

        // Row L2 keeps its "L2" label plus a placeholder cell for each `^`
        // marker (carve-js parity, uniform row width).
        /** @var \MarkupCarve\Carve\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $rows = $table->getChildren();

        /** @var \MarkupCarve\Carve\Node\Block\TableRow $dataRow2 */
        $dataRow2 = $rows[2];
        $cells = $dataRow2->getChildren();
        $this->assertCount(3, $cells);
    }

    /**
     * A long run of stacked `^` markers must extend the single origin cell to a
     * rowspan covering every continuation row. This also guards the parser's
     * complexity: rowspans resolve via a per-column origin map (O(cells)), not a
     * per-marker rescan of all prior rows (which made all-`^` tables O(rows^3)).
     */
    public function testDeepStackedRowspanResolvesAndStaysLinear(): void
    {
        $rows = 300;
        $djot = "|= Tier |= User |\n| Gold | u0 |\n"
            . str_repeat("| ^ | u |\n", $rows);

        $doc = $this->converter->parse($djot);

        /** @var \MarkupCarve\Carve\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        $this->assertInstanceOf(Table::class, $table);

        // Header + 1 origin row + $rows continuation rows.
        $this->assertCount($rows + 2, $table->getChildren());

        // The single "Gold" origin cell spans itself plus all `^` rows.
        /** @var \MarkupCarve\Carve\Node\Block\TableRow $originRow */
        $originRow = $table->getChildren()[1];
        /** @var \MarkupCarve\Carve\Node\Block\TableCell $originCell */
        $originCell = $originRow->getChildren()[0];
        $this->assertSame($rows + 1, $originCell->getRowspan());
    }

    /**
     * carve-php#527: carve-js keeps a placeholder cell for every span marker
     * instead of merging it into the origin as a count, so a consumer walking
     * `rows[i].cells` gets the same length for every row. Every row of this
     * 3-column table has 3 cells, the two markers in the last row carry `span`
     * on the wire, and no cell - marker or origin - carries a `rowspan`/
     * `colspan` count (the schema's `additionalProperties: false` rejects
     * one).
     */
    public function testEveryRowHasAUniformCellCountAndNoCellCarriesACountOnTheWire(): void
    {
        $djot = <<<'DJOT'
| A | B | C |
|---|---|---|
| x | y | z |
| ^ | < | d |
DJOT;

        $doc = $this->converter->parse($djot);

        /** @var \MarkupCarve\Carve\Node\Block\Table $table */
        $table = $doc->getChildren()[0];
        foreach ($table->getChildren() as $row) {
            $this->assertCount(3, $row->getChildren(), 'every row has one cell per grid column');
        }

        $encoded = (new AstCodec())->encode($doc);
        $spanRow = $encoded['children'][0]['rows'][2]['cells'];
        $this->assertCount(3, $spanRow);
        $this->assertSame('rowspan', $spanRow[0]['span']);
        $this->assertSame('colspan', $spanRow[1]['span']);
        $this->assertArrayNotHasKey('span', $spanRow[2]);

        foreach ($encoded['children'][0]['rows'] as $encodedRow) {
            foreach ($encodedRow['cells'] as $cell) {
                $this->assertArrayNotHasKey('rowspan', $cell);
                $this->assertArrayNotHasKey('colspan', $cell);
            }
        }
    }

    /**
     * A rowspan reaching three rows down renders identical HTML whether the
     * origin's count is read directly or - as carve-php#527 now requires -
     * resolved fresh from the placeholder cells in rows 2 and 3.
     */
    public function testRowspanSpanningThreeRowsRendersTheSameHtml(): void
    {
        $djot = <<<'DJOT'
| A | B |
|---|---|
| 1 | 2 |
| ^ | 3 |
| ^ | 4 |
DJOT;

        $html = $this->converter->convert($djot);

        $expected = <<<'HTML'
<table>
  <thead><tr><th>A</th><th>B</th></tr></thead>
  <tbody>
    <tr><td rowspan="3">1</td><td>2</td></tr>
    <tr><td>3</td></tr>
    <tr><td>4</td></tr>
  </tbody>
</table>
HTML;

        $this->assertSame($expected, trim($html));
    }

    /**
     * `bin/carve --json` output for a spanned table validates against the
     * published schema (`resources/ast-schema.json` in the spec repo) -
     * carve-php#527's actual acceptance criterion. Runs only when that sibling
     * checkout and a JSON Schema validator are available, since neither is a
     * dependency of this package; it is a real, non-fragile check on a
     * machine that has both; a bare structural check for the schema's
     * `additionalProperties: false` on `table_cell` (no rowspan/colspan,
     * `span` limited to "rowspan"/"colspan") still runs unconditionally above.
     */
    public function testJsonOutputValidatesAgainstThePublishedSchema(): void
    {
        // The repo root, however this checkout got here: a normal clone
        // (`.../carve-php`, sibling to `.../carve`) or a worktree under
        // `/tmp` for isolated work, which is not a sibling of anything -
        // hence the second, well-known-path fallback.
        $repoRoot = dirname(__DIR__, 2);
        $candidates = [
            dirname($repoRoot) . '/carve/resources/ast-schema.json',
            '/media/mark/data/work/git/carve/resources/ast-schema.json',
        ];
        $schemaPath = null;
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $schemaPath = $candidate;

                break;
            }
        }
        if ($schemaPath === null) {
            $this->markTestSkipped('the carve spec repo checkout is not available at ' . implode(' or ', $candidates));
        }

        $binCarve = $repoRoot . '/bin/carve';
        $source = "| A | B | C |\n|---|---|---|\n| x | y | z |\n| ^ | < | d |\n";
        $sourcePath = tempnam(sys_get_temp_dir(), 'carve527-');
        $this->assertNotFalse($sourcePath);
        file_put_contents($sourcePath, $source);
        $pythonScriptPath = null;

        try {
            $json = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($binCarve) . ' --json ' . escapeshellarg($sourcePath));
            $this->assertIsString($json, 'bin/carve --json produced no output');

            $pythonScript = <<<'PY'
import json, sys
try:
    import jsonschema
except ImportError:
    print("SKIP: jsonschema not installed")
    sys.exit(0)
schema = json.load(open(sys.argv[1]))
doc = json.loads(sys.stdin.read())
try:
    jsonschema.validate(instance=doc, schema=schema)
    print("VALID")
except jsonschema.ValidationError as e:
    print("INVALID: " + e.message)
PY;
            $pythonScriptPath = tempnam(sys_get_temp_dir(), 'carve527-validate-');
            $this->assertNotFalse($pythonScriptPath);
            file_put_contents($pythonScriptPath, $pythonScript);

            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = proc_open(
                ['python3', $pythonScriptPath, $schemaPath],
                $descriptors,
                $pipes,
            );
            if (!is_resource($process)) {
                $this->markTestSkipped('python3 is not available to validate the schema');
            }

            fwrite($pipes[0], $json);
            fclose($pipes[0]);
            $result = trim((string)stream_get_contents($pipes[1]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            if (str_starts_with($result, 'SKIP')) {
                $this->markTestSkipped($result);
            }
            $this->assertSame('VALID', $result);
        } finally {
            unlink($sourcePath);
            if ($pythonScriptPath !== null) {
                unlink($pythonScriptPath);
            }
        }
    }
}
