<?php

declare(strict_types=1);

namespace Carve\Test\TestCase\Extension;

use Carve\CarveConverter;
use Carve\Extension\ListTableExtension;
use PHPUnit\Framework\TestCase;

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
            '  <thead><tr><th>Region</th><th>Notes</th></tr></thead>',
            '  <tbody>',
            '    <tr><td>EMEA</td><td>Strong quarter.</td></tr>',
            '  </tbody>',
            '</table>',
        ]);
        $this->assertSame($expected, $this->render($djot));
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
            '    <tr><th>Region</th><td>Revenue</td></tr>',
            '    <tr><th>EMEA</th><td>1.2M</td></tr>',
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

        // The whole header row and the first column are all <th>.
        $expected = implode("\n", [
            '<table>',
            '  <thead><tr><th>Metric</th><th>Q1</th><th>Q2</th></tr></thead>',
            '  <tbody>',
            '    <tr><th>EMEA</th><td>1.0</td><td>1.2</td></tr>',
            '  </tbody>',
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

        $expected = implode("\n", [
            '<div class="list-table">',
            '  <ul>',
            '    <li>',
            '      <ul>',
            '        <li>A</li>',
            '      </ul>',
            '      <ul>',
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
            '  <thead><tr><th>Region</th><th>Q1</th><th>Q2</th></tr></thead>',
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

    public function testRowspanInFirstColumnShiftsLaterCells(): void
    {
        // A `^` in column 0 only covers column 0; later cells keep their place.
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
            '  <thead><tr><th rowspan="2">A</th><th>B</th><th>C</th></tr></thead>',
            '  <tbody>',
            '    <tr><td>E</td><td>F</td></tr>',
            '  </tbody>',
            '</table>',
        ]);
        $this->assertSame($expected, $this->render($djot));
    }

    public function testRowspanUnderColspanBodyMatchesPipeTable(): void
    {
        // A `^` under the BODY column of a wide cell still extends that cell,
        // so it grows both colspan and rowspan - matching the pipe table.
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

        $pipe = implode("\n", [
            '| A | < | C |',
            '|---|---|---|',
            '| x | ^ | y |',
        ]);

        $pipeHtml = trim((new CarveConverter())->convert($pipe));

        $this->assertSame($pipeHtml, $this->render($listTable));
        $this->assertStringContainsString('rowspan="2" colspan="2"', $this->render($listTable));
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

    public function testDroppedOverlapCellDoesNotGainRowspan(): void
    {
        // A cell dropped for overlapping a rowspan is kept only for tracking and
        // must not itself gain a rowspan from a later `^`, which would wrongly
        // skip real cells in following rows. Output must match the pipe table.
        $rows = [['A', '<', '<'], ['A', '<', '^'], ['^', 'A', 'A']];

        $listLines = ['::: list-table'];
        foreach ($rows as $row) {
            $first = true;
            foreach ($row as $cell) {
                $listLines[] = ($first ? '- - ' : '  - ') . $cell;
                $first = false;
            }
        }
        $listLines[] = ':::';

        $pipeLines = [];
        foreach ($rows as $row) {
            $pipeLines[] = '| ' . implode(' | ', $row) . ' |';
        }

        $pipeHtml = trim((new CarveConverter())->convert(implode("\n", $pipeLines)));

        $this->assertSame($pipeHtml, $this->render(implode("\n", $listLines)));
    }

    public function testRaggedRowBelowRowspanProducesValidGrid(): void
    {
        // A `^` whose column was omitted by the immediately preceding (ragged)
        // row does NOT jump the gap to extend an older cell; it becomes a plain
        // empty cell. The result is a valid, non-overlapping grid - no column
        // appears twice in a row and no synthetic rowspan is invented.
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
            '    <tr><td>A</td><td>B</td></tr>',
            '    <tr><td>C</td><td></td></tr>',
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
}
