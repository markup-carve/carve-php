<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Extension;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\ListTableExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ListTableExtensionTest extends TestCase
{
    /**
     * Convert with the list-table extension registered, trimmed for exact compare.
     */
    protected function render(string $djot): string
    {
        $converter = new CarveConverter();
        $converter->addExtension(new ListTableExtension());

        return trim($converter->convert($djot));
    }

    public function testBasicTwoColumnWithHeaderRowAndCaption(): void
    {
        $djot = implode("\n", [
            '{header-rows=1}',
            '::: list-table "Quarterly results"',
            '- - Region',
            '  - Notes',
            '- - EMEA',
            '  - Strong quarter.',
            ':::',
        ]);

        $expected = implode("\n", [
            '<table>',
            '  <caption>Quarterly results</caption>',
            '  <thead><tr><th scope="col">Region</th><th scope="col">Notes</th></tr></thead>',
            '  <tbody>',
            '    <tr><td>EMEA</td><td>Strong quarter.</td></tr>',
            '  </tbody>',
            '</table>',
        ]);
        $this->assertSame($expected, $this->render($djot));
    }

    public function testCaptionRendersInlineContent(): void
    {
        $djot = implode("\n", [
            '::: list-table "Q *totals* `2026`"',
            '- - x',
            ':::',
        ]);

        $this->assertStringContainsString('<caption>Q <strong>totals</strong> <code>2026</code></caption>', $this->render($djot));
    }

    public function testImageOnlyTitleRendersCaptionInsteadOfBeingEmpty(): void
    {
        $djot = implode("\n", [
            '::: list-table "![alt](/x.png)"',
            '- - x',
            ':::',
        ]);

        $this->assertStringContainsString('<caption><img src="/x.png" alt="alt"></caption>', $this->render($djot));
    }

    public function testExplicitEmptyTitleOmitsCaption(): void
    {
        $djot = implode("\n", [
            '::: list-table ""',
            '- - x',
            ':::',
        ]);

        $this->assertStringNotContainsString('<caption>', $this->render($djot));
    }

    public function testMultiBlockCellStaysWrappedWhileSingleParagraphCollapses(): void
    {
        $djot = implode("\n", [
            '::: list-table',
            '- - EMEA',
            '  - Strong quarter.',
            '',
            '    Drivers:',
            '',
            '    - new logos',
            '    - renewals',
            ':::',
        ]);

        $expected = implode("\n", [
            '<table>',
            '  <tbody>',
            '    <tr><td>EMEA</td><td><p>Strong quarter.</p>',
            '<p>Drivers:</p>',
            '<ul>',
            '  <li>new logos</li>',
            '  <li>renewals</li>',
            '</ul></td></tr>',
            '  </tbody>',
            '</table>',
        ]);
        $this->assertSame($expected, $this->render($djot));
    }

    public function testHeaderCols(): void
    {
        $djot = implode("\n", [
            '{header-cols=1}',
            '::: list-table',
            '- - Region',
            '  - Revenue',
            '- - EMEA',
            '  - 1.2M',
            ':::',
        ]);

        $expected = implode("\n", [
            '<table>',
            '  <tbody>',
            '    <tr><th scope="row">Region</th><td>Revenue</td></tr>',
            '    <tr><th scope="row">EMEA</th><td>1.2M</td></tr>',
            '  </tbody>',
            '</table>',
        ]);
        $this->assertSame($expected, $this->render($djot));
    }

    public function testBooleanHeaderRowsPromotesFirstRow(): void
    {
        // `{header-rows}` with no value is the boolean form: the first row is
        // the header, the default behavior a table with headers wants.
        $djot = implode("\n", [
            '{header-rows}',
            '::: list-table',
            '- - Region',
            '  - Notes',
            '- - EMEA',
            '  - ok',
            ':::',
        ]);

        $expected = implode("\n", [
            '<table>',
            '  <thead><tr><th scope="col">Region</th><th scope="col">Notes</th></tr></thead>',
            '  <tbody>',
            '    <tr><td>EMEA</td><td>ok</td></tr>',
            '  </tbody>',
            '</table>',
        ]);
        $this->assertSame($expected, $this->render($djot));
    }

    public function testBooleanHeaderColsPromotesFirstColumn(): void
    {
        $djot = implode("\n", [
            '{header-cols}',
            '::: list-table',
            '- - Region',
            '  - Notes',
            '- - EMEA',
            '  - ok',
            ':::',
        ]);

        $expected = implode("\n", [
            '<table>',
            '  <tbody>',
            '    <tr><th scope="row">Region</th><td>Notes</td></tr>',
            '    <tr><th scope="row">EMEA</th><td>ok</td></tr>',
            '  </tbody>',
            '</table>',
        ]);
        $this->assertSame($expected, $this->render($djot));
    }

    public function testHeaderRowsAndHeaderColsCombine(): void
    {
        $djot = implode("\n", [
            '{header-rows=1}',
            '{header-cols=1}',
            '::: list-table',
            '- - Metric',
            '  - Q1',
            '  - Q2',
            '- - EMEA',
            '  - 1.0',
            '  - 1.2',
            ':::',
        ]);

        // The whole header row and the first column are all <th scope="col">.
        $expected = implode("\n", [
            '<table>',
            '  <thead><tr><th scope="col">Metric</th><th scope="col">Q1</th><th scope="col">Q2</th></tr></thead>',
            '  <tbody>',
            '    <tr><th scope="row">EMEA</th><td>1.0</td><td>1.2</td></tr>',
            '  </tbody>',
            '</table>',
        ]);
        $this->assertSame($expected, $this->render($djot));
    }

    public function testFooterRowsRenderOnePerLine(): void
    {
        $djot = implode("\n", [
            '{footer-rows=2}',
            '{header-cols=1}',
            '::: list-table',
            '- - Region',
            '  - Q1',
            '- - EMEA',
            '  - 10',
            '- - Region',
            '  - Q1',
            '- - EMEA',
            '  - 10',
            ':::',
        ]);

        $expected = implode("\n", [
            '<table>',
            '  <tbody>',
            '    <tr><th scope="row">Region</th><td>Q1</td></tr>',
            '    <tr><th scope="row">EMEA</th><td>10</td></tr>',
            '  </tbody>',
            '  <tfoot>',
            '    <tr><th scope="row">Region</th><td>Q1</td></tr>',
            '    <tr><th scope="row">EMEA</th><td>10</td></tr>',
            '  </tfoot>',
            '</table>',
        ]);
        $this->assertSame($expected, $this->render($djot));
    }

    public function testRaggedRowsArePadded(): void
    {
        $djot = implode("\n", [
            '::: list-table',
            '- - A',
            '  - B',
            '  - C',
            '- - D',
            '  - E',
            ':::',
        ]);

        $expected = implode("\n", [
            '<table>',
            '  <tbody>',
            '    <tr><td>A</td><td>B</td><td>C</td></tr>',
            '    <tr><td>D</td><td>E</td><td></td></tr>',
            '  </tbody>',
            '</table>',
        ]);
        $this->assertSame($expected, $this->render($djot));
    }

    public function testNoCaption(): void
    {
        $djot = implode("\n", [
            '::: list-table',
            '- - A',
            '  - B',
            ':::',
        ]);

        $expected = implode("\n", [
            '<table>',
            '  <tbody>',
            '    <tr><td>A</td><td>B</td></tr>',
            '  </tbody>',
            '</table>',
        ]);
        $this->assertSame($expected, $this->render($djot));
    }

    public function testInlineMarkupInCell(): void
    {
        $djot = implode("\n", [
            '::: list-table',
            '- - Use `flat` markup',
            ':::',
        ]);

        $expected = implode("\n", [
            '<table>',
            '  <tbody>',
            '    <tr><td>Use <code>flat</code> markup</td></tr>',
            '  </tbody>',
            '</table>',
        ]);
        $this->assertSame($expected, $this->render($djot));
    }

    public function testExtensionOffRendersDefaultDiv(): void
    {
        $djot = implode("\n", [
            '::: list-table',
            '- - A',
            '  - B',
            ':::',
        ]);

        $converter = new CarveConverter();
        $html = trim($converter->convert($djot));

        // Marker-line sublists merge+absorb into a single nested list (carve
        // main #170 / #196), so `- - A` / `  - B` is one <ul> with two items.
        $expected = implode("\n", [
            '<div class="list-table">',
            '  <ul>',
            '    <li>',
            '      <ul>',
            '        <li>A</li>',
            '        <li>B</li>',
            '      </ul>',
            '    </li>',
            '  </ul>',
            '</div>',
        ]);
        $this->assertSame($expected, $html);
    }

    public function testOtherDivsAreNotClaimed(): void
    {
        $djot = implode("\n", [
            '::: note',
            'Hello.',
            ':::',
        ]);

        $expected = implode("\n", [
            '<aside class="admonition note">',
            '  <p>Hello.</p>',
            '</aside>',
        ]);
        $this->assertSame($expected, $this->render($djot));
    }

    public function testDivWithoutListDefersToDefault(): void
    {
        $djot = implode("\n", [
            '::: list-table',
            'Just a paragraph, no list.',
            ':::',
        ]);

        $expected = implode("\n", [
            '<div class="list-table">',
            '  <p>Just a paragraph, no list.</p>',
            '</div>',
        ]);
        $this->assertSame($expected, $this->render($djot));
    }

    public function testRowspanMarker(): void
    {
        $djot = implode("\n", [
            '::: list-table',
            '- - A',
            '  - B',
            '- - ^',
            '  - C',
            ':::',
        ]);

        $expected = implode("\n", [
            '<table>',
            '  <tbody>',
            '    <tr><td rowspan="2">A</td><td>B</td></tr>',
            '    <tr><td>C</td></tr>',
            '  </tbody>',
            '</table>',
        ]);
        $this->assertSame($expected, $this->render($djot));
    }

    public function testColspanSingleMarker(): void
    {
        $djot = implode("\n", [
            '::: list-table',
            '- - A',
            '  - <',
            '- - C',
            '  - D',
            ':::',
        ]);

        $expected = implode("\n", [
            '<table>',
            '  <tbody>',
            '    <tr><td colspan="2">A</td></tr>',
            '    <tr><td>C</td><td>D</td></tr>',
            '  </tbody>',
            '</table>',
        ]);
        $this->assertSame($expected, $this->render($djot));
    }

    public function testColspanTwoMarkers(): void
    {
        $djot = implode("\n", [
            '::: list-table',
            '- - Total',
            '  - <',
            '  - <',
            '- - a',
            '  - b',
            '  - c',
            ':::',
        ]);

        $expected = implode("\n", [
            '<table>',
            '  <tbody>',
            '    <tr><td colspan="3">Total</td></tr>',
            '    <tr><td>a</td><td>b</td><td>c</td></tr>',
            '  </tbody>',
            '</table>',
        ]);
        $this->assertSame($expected, $this->render($djot));
    }

    /**
     * A `<` whose left neighbour is a `^` rowspan marker has no real cell to
     * merge into, so it renders as an EMPTY cell occupying its grid position
     * rather than being dropped or folded into the marker. Mirrors the pipe
     * table's blocked-`<` case (carve-js / carve-rs parity). Regression guard:
     * the `<` used to widen the `^` colspan and disappear, shifting later cells.
     */
    public function testBlockedColspanMarkerRendersAsEmptyCell(): void
    {
        $djot = implode("\n", [
            '::: list-table',
            '- - A',
            '  - B',
            '  - C',
            '- - ^',
            '  - <',
            '  - D',
            ':::',
        ]);

        $expected = implode("\n", [
            '<table>',
            '  <tbody>',
            '    <tr><td rowspan="2">A</td><td>B</td><td>C</td></tr>',
            '    <tr><td></td><td>D</td></tr>',
            '  </tbody>',
            '</table>',
        ]);
        $this->assertSame($expected, $this->render($djot));
    }

    public function testRowspanAndColspanCombinedMatchesPipeTable(): void
    {
        $djot = implode("\n", [
            '{header-rows=1}',
            '::: list-table "Sales"',
            '- - Region',
            '  - Q1',
            '  - Q2',
            '- - EMEA',
            '  - 10',
            '  - 12',
            '- - ^',
            '  - 14',
            '  - 16',
            '- - Total',
            '  - <',
            '  - <',
            ':::',
        ]);

        $expected = implode("\n", [
            '<table>',
            '  <caption>Sales</caption>',
            '  <thead><tr><th scope="col">Region</th><th scope="col">Q1</th><th scope="col">Q2</th></tr></thead>',
            '  <tbody>',
            '    <tr><td rowspan="2">EMEA</td><td>10</td><td>12</td></tr>',
            '    <tr><td>14</td><td>16</td></tr>',
            '    <tr><td colspan="3">Total</td></tr>',
            '  </tbody>',
            '</table>',
        ]);
        $this->assertSame($expected, $this->render($djot));
    }

    /**
     * The list-table span output must match what the equivalent pipe table
     * produces for the same spans (the body only - the list-table adds a caption
     * from its title, which the pipe table here has none of).
     */
    public function testListTableSpanOutputMatchesEquivalentPipeTable(): void
    {
        $listTable = implode("\n", [
            '{header-rows=1}',
            '::: list-table',
            '- - Region',
            '  - Q1',
            '  - Q2',
            '- - EMEA',
            '  - 10',
            '  - 12',
            '- - ^',
            '  - 14',
            '  - 16',
            '- - Total',
            '  - <',
            '  - <',
            ':::',
        ]);

        $pipe = implode("\n", [
            '| Region | Q1 | Q2 |',
            '|--------|----|----|',
            '| EMEA   | 10 | 12 |',
            '| ^      | 14 | 16 |',
            '| Total  | <  | <  |',
        ]);

        // Render the pipe table without the list-table extension.
        $pipeHtml = trim((new CarveConverter())->convert($pipe));

        $this->assertSame($pipeHtml, $this->render($listTable));
    }

    public function testRowspanInColumnZeroDoesNotCrossHeaderBoundary(): void
    {
        // A `^` in a BODY row whose origin sits in the header rows must NOT pull
        // a rowspan across the <thead>/<tbody> boundary (an HTML cell cannot
        // reliably span row groups). The header cell stays a plain <th scope="col"> and the
        // `^` degrades to an empty body cell. This deliberately diverges from the
        // equivalent pipe table, which has no such row-group boundary.
        $djot = implode("\n", [
            '{header-rows=1}',
            '::: list-table',
            '- - A',
            '  - B',
            '  - C',
            '- - ^',
            '  - E',
            '  - F',
            ':::',
        ]);

        $expected = implode("\n", [
            '<table>',
            '  <thead><tr><th scope="col">A</th><th scope="col">B</th><th scope="col">C</th></tr></thead>',
            '  <tbody>',
            '    <tr><td></td><td>E</td><td>F</td></tr>',
            '  </tbody>',
            '</table>',
        ]);
        $this->assertSame($expected, $this->render($djot));
    }

    public function testRowspanUnderColspanBodyClampedAtHeaderBoundary(): void
    {
        // A `^` under the BODY column of a wide HEADER cell would extend it both
        // down and across, but the down-span is clamped at the header/body
        // boundary: the header cell keeps only its colspan, and the body row gets
        // plain cells (the `^` degrades to an empty cell). HTML cannot span a
        // <th scope="col"> from <thead> into <tbody>, so this diverges from the pipe table.
        $listTable = implode("\n", [
            '{header-rows=1}',
            '::: list-table',
            '- - A',
            '  - <',
            '  - C',
            '- - x',
            '  - ^',
            '  - y',
            ':::',
        ]);

        $expected = implode("\n", [
            '<table>',
            '  <thead><tr><th scope="col" colspan="2">A</th><th scope="col">C</th></tr></thead>',
            '  <tbody>',
            '    <tr><td>x</td><td></td><td>y</td></tr>',
            '  </tbody>',
            '</table>',
        ]);

        $this->assertSame($expected, $this->render($listTable));
        $this->assertStringNotContainsString('rowspan', $this->render($listTable));
    }

    public function testRowspanWithinBodyStillWorks(): void
    {
        // The clamp only affects spans CROSSING the header boundary. A `^` whose
        // origin and target are both in the body still produces a normal rowspan.
        $djot = implode("\n", [
            '{header-rows=1}',
            '::: list-table',
            '- - H1',
            '  - H2',
            '- - A',
            '  - B',
            '- - ^',
            '  - C',
            ':::',
        ]);

        $expected = implode("\n", [
            '<table>',
            '  <thead><tr><th scope="col">H1</th><th scope="col">H2</th></tr></thead>',
            '  <tbody>',
            '    <tr><td rowspan="2">A</td><td>B</td></tr>',
            '    <tr><td>C</td></tr>',
            '  </tbody>',
            '</table>',
        ]);
        $this->assertSame($expected, $this->render($djot));
    }

    public function testOverlappingSpanMarkersDoNotCrash(): void
    {
        // A `^` whose origin cell was itself dropped (it overlapped a rowspan
        // from above) must not crash on a stale origin pointer; it just emits no
        // span. This is a regression guard for a fuzz-found edge case.
        $djot = implode("\n", [
            '{header-rows=1}',
            '::: list-table',
            '- - A',
            '  - A',
            '  - <',
            '- - A',
            '  - A',
            '  - ^',
            '- - A',
            '  - ^',
            '  - A',
            ':::',
        ]);

        $html = $this->render($djot);

        $this->assertStringStartsWith('<table>', $html);
        $this->assertStringEndsWith('</table>', $html);
    }

    public function testOverlappingSpanCellsAreKeptNotDropped(): void
    {
        // Span markers only ever grow an existing cell or, when blocked, become
        // an empty cell; a cell whose grid position falls under another cell's
        // span is KEPT, never dropped (carve-js parity). A list-table also pads
        // each row to the table's full column count, so the resolved grid is
        // rectangular even when spans shorten a row - this is the one place a
        // list-table legitimately differs from the equivalent pipe table, which
        // does not pad. Output is byte-identical to carve-js's list-table.
        $djot = implode("\n", [
            '::: list-table',
            '- - A',
            '  - <',
            '  - <',
            '- - A',
            '  - <',
            '  - ^',
            '- - ^',
            '  - A',
            '  - A',
            ':::',
        ]);

        $expected = implode("\n", [
            '<table>',
            '  <tbody>',
            '    <tr><td colspan="3">A</td><td></td></tr>',
            '    <tr><td rowspan="2" colspan="2">A</td><td></td><td></td></tr>',
            '    <tr><td>A</td><td>A</td></tr>',
            '  </tbody>',
            '</table>',
        ]);
        $this->assertSame($expected, $this->render($djot));
    }

    public function testCaretBelowRaggedGapExtendsNearestCellAbove(): void
    {
        // A `^` extends the nearest non-skipped cell in its column from any row
        // above, even when the immediately preceding row was ragged and omitted
        // that column: the grid pads short rows, so the column still has an open
        // origin to continue (carve-js parity via the per-column lastNonSkip
        // walk). Here B (row 0, column 1) gains rowspan="2" from the `^` two rows
        // below it.
        $djot = implode("\n", [
            '::: list-table',
            '- - A',
            '  - B',
            '- - C',
            '- - X',
            '  - ^',
            ':::',
        ]);

        $expected = implode("\n", [
            '<table>',
            '  <tbody>',
            '    <tr><td>A</td><td rowspan="2">B</td></tr>',
            '    <tr><td>C</td></tr>',
            '    <tr><td>X</td><td></td></tr>',
            '  </tbody>',
            '</table>',
        ]);
        $this->assertSame($expected, $this->render($djot));
    }

    public function testCaretBelowRaggedGapIsEmptyCellNotRowspan(): void
    {
        // Regression: a `^` under a column the previous row lacked must render as
        // an empty cell, never a spurious rowspan on an invented padding cell.
        $djot = implode("\n", [
            '::: list-table',
            '- - A',
            '- - B',
            '  - ^',
            ':::',
        ]);

        $html = $this->render($djot);

        $this->assertStringNotContainsString('rowspan', $html);
    }

    public function testAttributedCellIsNotASpanMarkerEscape(): void
    {
        // A cell carrying its own attribute block is never a span marker; the
        // `^` stays literal content (the same escape pipe tables use).
        $djot = implode("\n", [
            '::: list-table',
            '- - -{.x} ^',
            '  - B',
            ':::',
        ]);

        $html = $this->render($djot);

        // No span attribute is emitted, and the literal `^` is preserved.
        $this->assertStringNotContainsString('rowspan', $html);
        $this->assertStringNotContainsString('colspan', $html);
        $this->assertStringContainsString('^', $html);
        // Two cells survive (the escaped marker cell and B); no merge happened.
        $this->assertStringContainsString('<td>B</td>', $html);
    }

    public function testRowWithNoCellListDefersAndPreservesContent(): void
    {
        // A row authored without an inner cell list (a plain paragraph row)
        // cannot become table cells without dropping its text. The whole div
        // defers to the default renderer so the literal nested list is emitted
        // and no content is lost - byte-identical to the extension-off output.
        $djot = implode("\n", [
            '::: list-table',
            '- - A',
            '  - B',
            '- not-a-cell-row',
            ':::',
        ]);

        $withExtension = $this->render($djot);
        $plain = trim((new CarveConverter())->convert($djot));

        $this->assertSame($plain, $withExtension);
        $this->assertStringStartsWith('<div class="list-table">', $withExtension);
        $this->assertStringContainsString('not-a-cell-row', $withExtension);
        $this->assertStringNotContainsString('<table', $withExtension);
    }

    public function testMalformedDeferRendersIdenticalToPlainDivNoDuplication(): void
    {
        // The extension records stray blocks against a cell while building. If it
        // then defers (a later row has no cells), that bookkeeping must NOT have
        // mutated the AST - otherwise the default renderer would emit the stray
        // block twice. The deferred render must be byte-identical to plain.
        $djot = implode("\n", [
            '::: list-table',
            '- - A',
            '  - B',
            '',
            '  stray block',
            '- not-a-cell-row',
            ':::',
        ]);

        $withExtension = $this->render($djot);
        $plain = trim((new CarveConverter())->convert($djot));

        $this->assertSame($plain, $withExtension);
        // The stray block appears exactly once (no duplication from a mutated AST).
        $this->assertSame(1, substr_count($withExtension, 'stray block'));
    }

    public function testHeaderRowRowspanDoesNotCrossIntoBody(): void
    {
        // With header-rows=1, a `^` in the body under a header cell must not
        // create a <th rowspan> reaching from <thead> into <tbody>. The header
        // cell stays a plain <th scope="col"> and the `^` degrades to an empty body cell.
        $djot = implode("\n", [
            '{header-rows=1}',
            '::: list-table',
            '- - H1',
            '  - H2',
            '- - ^',
            '  - x',
            ':::',
        ]);

        $expected = implode("\n", [
            '<table>',
            '  <thead><tr><th scope="col">H1</th><th scope="col">H2</th></tr></thead>',
            '  <tbody>',
            '    <tr><td></td><td>x</td></tr>',
            '  </tbody>',
            '</table>',
        ]);

        $html = $this->render($djot);
        $this->assertSame($expected, $html);
        $this->assertStringNotContainsString('rowspan', $html);
    }

    public function testMultiBlockCellStartingWithMarkerCharIsNotASpanMarker(): void
    {
        // A multi-block cell whose first paragraph is a lone `^` (or `<`) is NOT
        // a span marker - the trailing block makes it real content, so the `^`
        // stays literal and the extra block is preserved (not dropped).
        $djot = implode("\n", [
            '::: list-table',
            '- - A',
            '- - ^',
            '',
            '  extra',
            ':::',
        ]);

        $expected = implode("\n", [
            '<table>',
            '  <tbody>',
            '    <tr><td>A</td></tr>',
            '    <tr><td><p>^</p>',
            '<p>extra</p></td></tr>',
            '  </tbody>',
            '</table>',
        ]);

        $html = $this->render($djot);
        $this->assertSame($expected, $html);
        $this->assertStringNotContainsString('rowspan', $html);
    }

    public function testCellOwnAttributesCarryOntoCellTagAndStructuralSpanWins(): void
    {
        // A cell's own list-item attributes are carried onto its <td>/<th scope="col">. Any
        // author-written rowspan/colspan (in any case) is dropped so the computed
        // structural span is the only one emitted (no duplicate, ambiguous attr).
        $converter = new CarveConverter();
        $ast = $converter->parse(implode("\n", [
            '::: list-table',
            '- - A',
            '  - B',
            '- - ^',
            '  - C',
            ':::',
        ]));

        $div = $ast->getChildren()[0];
        $row0 = $div->getChildren()[0]->getChildren()[0];
        $cellA = $row0->getChildren()[0]->getChildren()[0];
        $cellA->setAttribute('class', 'hi');
        $cellA->setAttribute('id', 'a1');
        // Author-written span in non-canonical case must NOT survive.
        $cellA->setAttribute('RowSpan', '99');

        $rendered = new CarveConverter();
        $rendered->addExtension(new ListTableExtension());
        $renderer = $rendered->getRenderer();

        $ext = new ListTableExtension();
        $method = new ReflectionMethod($ext, 'renderListTable');
        $html = trim((string)$method->invoke($ext, $div, $renderer));

        $expected = implode("\n", [
            '<table>',
            '  <tbody>',
            '    <tr><td rowspan="2" class="hi" id="a1">A</td><td>B</td></tr>',
            '    <tr><td>C</td></tr>',
            '  </tbody>',
            '</table>',
        ]);
        $this->assertSame($expected, $html);
        // Exactly one rowspan attribute survives (the computed one).
        $this->assertSame(1, substr_count($html, 'rowspan'));
        $this->assertStringNotContainsString('RowSpan', $html);
    }

    public function testTitleAttributePreservedAlongsideCaptionHeader(): void
    {
        // The caption comes from the quoted opener header; a `title="…"` on the
        // preceding attribute line is a plain HTML attribute and must survive
        // onto the <table> tag (parity with carve-js).
        $converter = new CarveConverter();
        $converter->addExtension(new ListTableExtension());

        $html = trim($converter->convert(implode("\n", [
            '{title="attr"}',
            '::: list-table "cap"',
            '- - A',
            '  - B',
            ':::',
        ])));

        $expected = implode("\n", [
            '<table title="attr">',
            '  <caption>cap</caption>',
            '  <tbody>',
            '    <tr><td>A</td><td>B</td></tr>',
            '  </tbody>',
            '</table>',
        ]);
        $this->assertSame($expected, $html);
    }

    public function testTableAndCellAttributesAreHardened(): void
    {
        $converter = new CarveConverter();
        $ast = $converter->parse(implode("\n", [
            '{onclick="alert(1)" style="background:url(javascript:alert(1))"}',
            '::: list-table',
            '- - A',
            ':::',
        ]));

        $div = $ast->getChildren()[0];
        $row0 = $div->getChildren()[0]->getChildren()[0];
        $cellA = $row0->getChildren()[0]->getChildren()[0];
        $cellA->setAttribute('onclick', 'alert(1)');
        $cellA->setAttribute('style', 'background:url(javascript:alert(1))');

        $rendered = new CarveConverter();
        $rendered->addExtension(new ListTableExtension());
        $renderer = $rendered->getRenderer();

        $ext = new ListTableExtension();
        $method = new ReflectionMethod($ext, 'renderListTable');
        $html = trim((string)$method->invoke($ext, $div, $renderer));

        $this->assertStringNotContainsString('onclick=', $html);
        $this->assertStringNotContainsString('background:url', $html);
        $this->assertSame(2, substr_count($html, 'style=""'));
    }

    public function testStraySiblingContentDefersToDefaultAndIsNotDropped(): void
    {
        $djot = implode("\n", [
            '::: list-table',
            'Intro paragraph.',
            '',
            '- - A',
            '  - B',
            '',
            'Trailing paragraph.',
            ':::',
        ]);

        $html = $this->render($djot);

        // The div is not claimed (extra siblings around the list); it degrades
        // to the default nested-list div so no content is lost.
        $this->assertStringStartsWith('<div class="list-table">', $html);
        $this->assertStringContainsString('<p>Intro paragraph.</p>', $html);
        $this->assertStringContainsString('<p>Trailing paragraph.</p>', $html);
        $this->assertStringContainsString('<li>A</li>', $html);
        $this->assertStringContainsString('<li>B</li>', $html);
        $this->assertStringNotContainsString('<table', $html);
    }

    public function testDefersOverLargeTableToPlainDiv(): void
    {
        // Beyond MAX_ROWS the span resolver would go quadratic; the block must
        // defer to the plain nested-list div (content preserved, no blow-up).
        $rows = str_repeat("- - a\n  - b\n", 10001);
        $out = $this->render("::: list-table\n{$rows}:::");
        $this->assertStringStartsWith('<div class="list-table">', $out);
        $this->assertStringNotContainsString('<table>', $out);
    }

    public function testColumnMetadataAndFooterRenderTogether(): void
    {
        $html = $this->render(implode("\n", [
            '{header-rows=1 footer-rows=1 aligns="left,right" valigns="top,bottom" widths="30,70"}',
            '::: list-table',
            '- - H',
            '  - N',
            '- - A',
            '  - 1',
            '- - F',
            '  - 2',
            ':::',
        ]));

        $this->assertStringContainsString('<colgroup>', $html);
        $this->assertStringContainsString("<tfoot>\n    <tr>", $html);
        $this->assertStringContainsString('text-align: left; vertical-align: top;', $html);
        $this->assertStringNotContainsString('aligns=', $html);
    }

    #[DataProvider('invalidColumnMetadata')]
    public function testInvalidColumnMetadataDefersWithoutDroppingContent(string $attrs): void
    {
        $html = $this->render("{{$attrs}}\n::: list-table\n- - A\n  - B\n:::");
        $this->assertStringStartsWith('<div class="list-table"', $html);
        $this->assertStringContainsString('<li>A</li>', $html);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidColumnMetadata(): iterable
    {
        yield 'too many entries' => ['aligns="left,right,center"'];
        yield 'bad horizontal value' => ['aligns="diagonal"'];
        yield 'bad vertical value' => ['valigns="baseline"'];
        yield 'non numeric width' => ['widths="wide"'];
        yield 'zero width' => ['widths="0"'];
        yield 'oversized width' => ['widths="101"'];
        yield 'overlapping row groups' => ['header-rows=2 footer-rows=1'];
    }

    public function testEmptyColumnSlotsAndStyledPaddingAreHandled(): void
    {
        $html = $this->render("{aligns=\",right\" valigns=\",bottom\" widths=\",50\"}\n::: list-table\n- - A\n  - B\n- - C\n:::");
        $this->assertStringContainsString('<col>', $html);
        $this->assertStringContainsString('<col style="width: 50%;">', $html);
        $this->assertStringContainsString('<td style="text-align: right; vertical-align: bottom;"></td>', $html);
    }
}
