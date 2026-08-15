<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Table fidelity on HTML import, in two halves.
 *
 * What Carve source CAN spell has to arrive intact: a header cell is a property
 * of the cell, so `|= R | 1 |` is a row-head column beside data cells. Reading
 * header off the ROW instead promoted every cell in the row and dropped the
 * header from every `th` outside the one row that got promoted - and moved that
 * row to the top of the table, whatever its position in the source.
 *
 * What Carve source CANNOT spell has to say so. There is no spelling for the
 * `rowGroups` partition (PART 12 §15), so a foot, a second body group or a head
 * the leading-run rule will not reproduce flattens - and now reports it.
 *
 * The assertions render the imported source back to HTML, because the claim is
 * about which cells are headers on the other side, not about which bytes were
 * written.
 */
class HtmlImportTableFidelityTest extends TestCase
{
    protected HtmlToCarve $converter;

    protected CarveConverter $carve;

    protected function setUp(): void
    {
        $this->converter = new HtmlToCarve();
        $this->carve = new CarveConverter();
    }

    protected function reimport(string $html): string
    {
        return $this->carve->convert($this->converter->convert($html));
    }

    /**
     * @return list<string>
     */
    protected function diagnosticCodes(string $html): array
    {
        return array_map(
            static fn ($diagnostic): string => $diagnostic->code,
            (new HtmlToCarve())->convertWithReport($html)->diagnostics,
        );
    }

    /**
     * @return list<string>
     */
    protected function diagnosticMessages(string $html): array
    {
        return array_map(
            static fn ($diagnostic): string => $diagnostic->message,
            (new HtmlToCarve())->convertWithReport($html)->diagnostics,
        );
    }

    // ==================== what the source can spell ====================

    /**
     * A row-head column: one `th` per row beside ordinary data cells. Every
     * cell in these rows used to come back a header.
     */
    public function testRowHeadColumnKeepsItsCellsApart(): void
    {
        $html = '<table><tr><th>H1</th><th>H2</th></tr>'
            . '<tr><th>R1</th><td>a</td></tr>'
            . '<tr><th>R2</th><td>b</td></tr></table>';

        $this->assertSame(
            "|= H1 |= H2 |\n|= R1 | a |\n|= R2 | b |\n",
            $this->converter->convert($html),
        );
        $this->assertSame(
            "<table>\n"
            . "  <thead><tr><th scope=\"col\">H1</th><th scope=\"col\">H2</th></tr></thead>\n"
            . "  <tbody>\n"
            . "    <tr><th scope=\"row\">R1</th><td>a</td></tr>\n"
            . "    <tr><th scope=\"row\">R2</th><td>b</td></tr>\n"
            . "  </tbody>\n"
            . "</table>\n",
            $this->reimport($html),
        );
    }

    /**
     * A single row mixing a header cell and a data cell. The data cell used to
     * be promoted to a header along with it.
     */
    public function testALoneMixedRowDoesNotPromoteItsDataCell(): void
    {
        $this->assertStringContainsString(
            '<tr><th scope="row">R</th><td>1</td></tr>',
            $this->reimport('<table><tr><th>R</th><td>1</td></tr></table>'),
        );
    }

    /**
     * The row order is the source's. A header row further down used to be
     * hoisted to the top, so the table came back with its rows rearranged.
     */
    public function testAHeaderRowFurtherDownStaysWhereItIs(): void
    {
        $html = '<table><tr><td>one</td></tr><tr><td>two</td></tr>'
            . '<tr><th>Late</th></tr><tr><td>four</td></tr></table>';

        $this->assertSame("| one |\n| two |\n|= Late |\n| four |\n", $this->converter->convert($html));

        $rendered = $this->reimport($html);
        $this->assertSame(
            ['one', 'two', 'Late', 'four'],
            $this->cellOrder($rendered),
        );
        $this->assertStringContainsString('<th scope="row">Late</th>', $rendered);
    }

    /**
     * @return list<string>
     */
    protected function cellOrder(string $html): array
    {
        preg_match_all('/<t[hd][^>]*>([^<]*)</', $html, $matches);

        return $matches[1];
    }

    /**
     * A row-head column carries no structural loss, so it must NOT report one.
     * Nor may the `scope` the renderer regenerates from the cell's position be
     * reported as dropped: it comes back.
     */
    public function testARowHeadColumnReportsNoLoss(): void
    {
        $this->assertSame([], $this->diagnosticCodes('<table><tbody><tr><th scope="row">R</th><td>1</td></tr></tbody></table>'));
    }

    // ==================== what the source cannot spell ====================

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function degradedStructureProvider(): array
    {
        return [
            'a second caption' => [
                '<table><caption>One</caption><tr><td>1</td></tr><caption>Two</caption></table>',
                'Kept the first of 2 <caption> elements',
            ],
            'a caption after the rows, alongside another' => [
                '<table><caption>One</caption><tbody><tr><td>1</td></tr></tbody><caption>Two</caption></table>',
                'Kept the first of 2 <caption> elements',
            ],
            'a table foot' => [
                '<table><thead><tr><th>A</th></tr></thead><tbody><tr><td>1</td></tr></tbody>'
                    . '<tfoot><tr><td>F</td></tr></tfoot></table>',
                'Moved 1 <tfoot> row(s) into the table body',
            ],
            'a second body group' => [
                '<table><tbody><tr><td>1</td></tr></tbody><tbody><tr><td>2</td></tr></tbody></table>',
                'Merged 2 <tbody> groups into one',
            ],
            'a body header row that joins the head' => [
                '<table><thead><tr><th>A</th></tr></thead>'
                    . '<tbody><tr><th>Mid</th></tr><tr><td>1</td></tr></tbody></table>',
                'The table head changes from 1 to 2 row(s)',
            ],
            'a head row holding a data cell' => [
                '<table><thead><tr><th>A</th><td>B</td></tr></thead><tbody><tr><td>1</td><td>2</td></tr></tbody></table>',
                'The table head changes from 1 to 0 row(s)',
            ],
            'a header cell below the head carrying attributes' => [
                '<table><tr><th>H</th><th>H2</th></tr><tr><th class="k">R</th><td>1</td></tr></table>',
                '1 header cell(s) become data cells',
            ],
            'a second leading header row carrying attributes' => [
                '<table><tr><th>A</th><th>B</th></tr><tr><th class="k">C</th><th>D</th></tr>'
                    . '<tr><td>1</td><td>2</td></tr></table>',
                '1 header cell(s) become data cells',
            ],
        ];
    }

    #[DataProvider('degradedStructureProvider')]
    public function testStructuralLossIsReported(string $html, string $expectedMessage): void
    {
        $this->assertContains('table-degraded', $this->diagnosticCodes($html));

        $matched = false;
        foreach ($this->diagnosticMessages($html) as $message) {
            if (str_contains($message, $expectedMessage)) {
                $matched = true;

                break;
            }
        }
        $this->assertTrue($matched, 'no diagnostic said: ' . $expectedMessage);
    }

    /**
     * The first caption is the one that survives, which is the parser's own
     * rule rather than a second one invented here.
     */
    public function testTheFirstCaptionIsTheOneKept(): void
    {
        $this->assertStringContainsString(
            '<caption>One</caption>',
            $this->reimport('<table><caption>One</caption><tr><td>1</td></tr><caption>Two</caption></table>'),
        );
    }

    // ==================== controls ====================

    /**
     * CONTROL. A caption already survives import and must keep doing so.
     */
    public function testASingleCaptionSurvives(): void
    {
        $this->assertStringContainsString(
            '<caption>Monthly totals</caption>',
            $this->reimport('<table><caption>Monthly totals</caption><tr><th>A</th></tr><tr><td>1</td></tr></table>'),
        );
    }

    /**
     * CONTROL. A lone caption after the rows loses nothing - a Carve caption is
     * written after the table anyway - so it reports nothing. Position is
     * normalized, not degraded, and a diagnostic here would be the false kind.
     */
    public function testALoneLateCaptionReportsNothing(): void
    {
        $html = '<table><tr><td>1</td></tr><caption>Late</caption></table>';

        $this->assertSame([], $this->diagnosticCodes($html));
        $this->assertStringContainsString('<caption>Late</caption>', $this->reimport($html));
    }

    /**
     * CONTROL. The ordinary head/body table is the shape most documents have,
     * and it must stay silent: a report that fires on every table is one nobody
     * reads.
     *
     * @return array<string, array{0: string}>
     */
    public static function trivialTableProvider(): array
    {
        return [
            'head and body sections' => ['<table><thead><tr><th>A</th></tr></thead><tbody><tr><td>1</td></tr></tbody></table>'],
            'no sections at all' => ['<table><tr><th>A</th></tr><tr><td>1</td></tr></table>'],
            'a body only' => ['<table><tbody><tr><td>1</td></tr></tbody></table>'],
            'two head rows in the head' => ['<table><thead><tr><th>A</th></tr><tr><th>B</th></tr></thead><tbody><tr><td>1</td></tr></tbody></table>'],
        ];
    }

    #[DataProvider('trivialTableProvider')]
    public function testATrivialTableReportsNothing(string $html): void
    {
        $this->assertSame([], $this->diagnosticCodes($html));
    }

    /**
     * CONTROL. colspan and rowspan already map to grid continuation cells, and
     * the header rework must not disturb them.
     */
    public function testSpansStillBecomeContinuationCells(): void
    {
        $this->assertSame(
            "|= A |= B |\n| wide | < |\n",
            $this->converter->convert('<table><tr><th>A</th><th>B</th></tr><tr><td colspan="2">wide</td></tr></table>'),
        );
        $this->assertStringContainsString(
            '<td colspan="2">wide</td>',
            $this->reimport('<table><tr><th>A</th><th>B</th></tr><tr><td colspan="2">wide</td></tr></table>'),
        );
        $this->assertStringContainsString(
            '<td rowspan="2">tall</td>',
            $this->reimport('<table><tr><th>A</th><th>B</th></tr><tr><td rowspan="2">tall</td><td>x</td></tr><tr><td>y</td></tr></table>'),
        );
    }

    /**
     * CONTROL. Two leading header rows both reach the head: the second is
     * written with its own `|=` markers and the leading-run rule reads it back
     * into the head. Only a cell that also carries attributes breaks that, and
     * the case above reports it.
     */
    public function testTwoLeadingHeaderRowsBothReachTheHead(): void
    {
        $this->assertSame(
            "<table>\n"
            . "  <thead><tr><th scope=\"col\">A</th></tr><tr><th scope=\"col\">B</th></tr></thead>\n"
            . "  <tbody>\n"
            . "    <tr><td>1</td></tr>\n"
            . "  </tbody>\n"
            . "</table>\n",
            $this->reimport('<table><thead><tr><th>A</th></tr><tr><th>B</th></tr></thead><tbody><tr><td>1</td></tr></tbody></table>'),
        );
    }

    /**
     * CONTROL. A header row whose cells carry attributes keeps the delimiter
     * form, where the row after it is what promotes the cells - so those cells
     * must NOT also take a marker.
     */
    public function testAnAttributedHeadRowKeepsTheDelimiterForm(): void
    {
        $this->assertSame(
            "|{.k} A | B |\n|---|---|\n| 1 | 2 |\n",
            $this->converter->convert('<table><tr><th class="k">A</th><th>B</th></tr><tr><td>1</td><td>2</td></tr></table>'),
        );
    }
}
