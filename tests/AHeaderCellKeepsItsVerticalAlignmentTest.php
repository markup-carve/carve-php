<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#1745. A cell's marker run carries BOTH axes, and in the delimiter
 * table form this engine read only the horizontal one off a HEADER cell. The
 * vertical alignment was dropped from the header alone, while the identical run
 * on a body cell one line down kept it.
 *
 * Every expectation here was measured against carve-js built from source, not
 * copied from this engine's own output.
 */
class AHeaderCellKeepsItsVerticalAlignmentTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = CarveConverter::create();
    }

    private function html(string $source): string
    {
        return trim($this->converter->convert($source));
    }

    /**
     * THE HEADER AND THE BODY IN ONE ASSERTION. A fix that reaches the header
     * by breaking the body would pass a header-only check, so the whole table
     * is pinned and the two rows are read together.
     *
     * @param string $run
     * @param string $vertical
     */
    #[DataProvider('theVerticalMarkers')]
    public function testTheDelimiterFormKeepsAHeaderCellsVerticalAlignment(string $run, string $vertical): void
    {
        $expected = <<<HTML
        <table>
          <thead>
            <tr><th scope="col" style="text-align: left; vertical-align: {$vertical};">L</th><th scope="col" style="text-align: right;">R</th></tr>
          </thead>
          <tbody>
            <tr><td style="text-align: left; vertical-align: {$vertical};">a</td><td style="text-align: right;">b</td></tr>
          </tbody>
        </table>
        HTML;

        $source = "|{$run} L | R |\n|:-----|------:|\n|{$run} a | b |\n";

        $this->assertSame($expected, $this->html($source));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function theVerticalMarkers(): array
    {
        return [
            'top' => ['?^', 'top'],
            'bottom' => ['?v', 'bottom'],
        ];
    }

    /**
     * The vertical axis alone, with the delimiter row carrying no horizontal
     * alignment at all: the header cell has nothing to inherit from the column,
     * so this is the axis on its own rather than beside another one.
     */
    public function testTheVerticalAxisAloneReachesTheHeaderCell(): void
    {
        $expected = <<<'HTML'
        <table>
          <thead>
            <tr><th scope="col" style="vertical-align: top;">L</th><th scope="col">R</th></tr>
          </thead>
          <tbody>
            <tr><td style="vertical-align: top;">a</td><td>b</td></tr>
          </tbody>
        </table>
        HTML;

        $this->assertSame($expected, $this->html("|?^ L | R |\n|---|---|\n|?^ a | b |\n"));
    }

    /**
     * THE SECOND HALF OF THE SAME LOSS. A header cell's marker DECLARES the
     * column, so a plain body cell under it inherits the vertical alignment -
     * which a canonical header row does and a promoted delimiter-form one did
     * not, because the row was parsed as a body row before it was promoted and
     * so never reached the line that seeds the column.
     */
    public function testAPlainBodyCellInheritsThePromotedHeadersVerticalAlignment(): void
    {
        $expected = <<<'HTML'
        <table>
          <thead>
            <tr><th scope="col" style="text-align: left; vertical-align: top;">L</th><th scope="col" style="text-align: right;">R</th></tr>
          </thead>
          <tbody>
            <tr><td style="text-align: left; vertical-align: top;">a</td><td style="text-align: right;">b</td></tr>
          </tbody>
        </table>
        HTML;

        $this->assertSame($expected, $this->html("|?^ L | R |\n|:-----|------:|\n| a | b |\n"));
    }

    /**
     * A marker-column header row followed by a delimiter row: the column was
     * already seeded here, so only the header cell's own alignment was missing.
     * It isolates the promotion from the seeding, since the two halves fail
     * separately on this input.
     */
    public function testAMarkerColumnHeaderAboveADelimiterRowKeepsBoth(): void
    {
        $expected = <<<'HTML'
        <table>
          <thead>
            <tr><th scope="col" style="text-align: left; vertical-align: top;">L</th><th scope="col" style="text-align: right;">R</th></tr>
          </thead>
          <tbody>
            <tr><td style="text-align: left; vertical-align: top;">a</td><td style="text-align: right;">b</td></tr>
          </tbody>
        </table>
        HTML;

        $this->assertSame($expected, $this->html("|=?^ L |= R |\n|:-----|------:|\n| a | b |\n"));
    }

    /**
     * THE FORM THAT ALREADY WORKED, pinned so the fix cannot reach the
     * delimiter form by moving the canonical one. The marker column form sets
     * the header's vertical alignment correctly today.
     */
    public function testTheCanonicalMarkerFormDoesNotMove(): void
    {
        $expected = <<<'HTML'
        <table>
          <thead>
            <tr><th scope="col" style="vertical-align: top;">h</th><th scope="col">x</th></tr>
          </thead>
          <tbody>
            <tr><td style="vertical-align: top;">a</td><td>b</td></tr>
          </tbody>
        </table>
        HTML;

        $this->assertSame($expected, $this->html("|=?^ h |= x |\n|?^ a | b |\n"));
    }

    /**
     * A BARE AXIS MARKER IS NOT A MARKER RUN. Without the leading `?` the
     * characters are ordinary cell text, in the header and the body alike, and
     * the fix must not start consuming them.
     */
    public function testABareAxisCharacterStaysCellText(): void
    {
        $expected = <<<'HTML'
        <table>
          <thead>
            <tr><th scope="col" style="text-align: left;">^ L</th><th scope="col" style="text-align: right;">R</th></tr>
          </thead>
          <tbody>
            <tr><td style="text-align: left;">^ a</td><td style="text-align: right;">b</td></tr>
          </tbody>
        </table>
        HTML;

        $this->assertSame($expected, $this->html("|^ L | R |\n|:-----|------:|\n|^ a | b |\n"));
    }

    /**
     * THE ROUND TRIP THIS COSTS. The delimiter form is what this engine's own
     * HTML importer writes for a table it recognized as GFM-sourced, so a
     * header cell carrying a vertical alignment was written back as a correct
     * marker run that the parser could not then read: the second render lost
     * it while every other shape was a fixed point.
     */
    public function testAnImportedHeaderCellsVerticalAlignmentSurvivesTheReRender(): void
    {
        $html = '<table data-djot-col-widths="1,1">'
            . '<thead><tr><th style="text-align: left; vertical-align: top;">L</th>'
            . '<th style="text-align: right;">R</th></tr></thead>'
            . '<tbody><tr><td style="text-align: left; vertical-align: top;">a</td>'
            . '<td style="text-align: right;">b</td></tr></tbody></table>';

        $carve = (new HtmlToCarve(importMode: 'roundtrip'))->convert($html);

        // The importer already wrote a correct run; reading it back is the half
        // that was broken.
        $this->assertStringContainsString('|?^ L ', $carve);
        $this->assertStringContainsString('vertical-align: top;">L</th>', $this->html($carve));
    }

    /**
     * THE WRITER SHED IT TOO, for the same reason and one step further on. A
     * canonical rewrite reads the header cell's alignment off the tree, so a
     * cell that reached the tree carrying nothing was re-emitted without the
     * author's marker: the vertical axis was gone from the SOURCE as well as
     * from the render. The rewrite is a fixed point on both now.
     */
    public function testTheCanonicalRewriteKeepsTheHeadersVerticalMarker(): void
    {
        $source = "|?^ L | R |\n|:-----|------:|\n|?^ a | b |\n";

        $once = CarveConverter::toCarve($source);

        $this->assertStringContainsString('^', explode("\n", $once)[0]);
        $this->assertSame($once, CarveConverter::toCarve($once));
        $this->assertSame($this->html($source), $this->html($once));
    }

    /**
     * And the whole cycle settles: rendering the imported source and importing
     * that again reaches the same Carve, so the header's vertical alignment is
     * carried rather than shed on the way round.
     */
    public function testTheImportRenderCycleIsAFixedPoint(): void
    {
        $source = "|?^ L | R |\n|:-----|------:|\n|?^ a | b |\n";

        $importer = new HtmlToCarve(importMode: 'roundtrip');
        $once = $importer->convert($this->html($source));
        $twice = $importer->convert($this->html($once));

        $this->assertSame($once, $twice);
        $this->assertStringContainsString('vertical-align: top;">L</th>', $this->html($once));
    }
}
