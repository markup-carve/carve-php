<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

/**
 * A cell's `text-align` and `vertical-align` reach the cell's MARKER RUN in
 * `semantic` and `roundtrip`, and are dropped and reported in `safe`.
 *
 * `style` was refused wholesale on the way in, so a cell carrying
 * `text-align:right` in a table with no header row came back unaligned AND
 * carrying a `style-unmapped` row that named the loss. The alignment had
 * somewhere faithful to go the whole time: a Carve cell alignment is written
 * back as `style="text-align: right;"`, the very declaration the import was
 * handed. `docs/html-import.md` makes a declared loss a CEILING rather than a
 * LICENCE, and there was no ceiling here (markup-carve/carve#1741).
 *
 * THE MARKER, NOT THE KEY-VALUE. `{align=right}` renders back as
 * `align="right"`, so it changes the attribute on the way through and leaves
 * `carve -> html -> carve -> html` unstable. Only the marker run is a fixed
 * point (markup-carve/carve#1745). `vertical-align` has the same answer through
 * the cell's `valign` (markup-carve/carve#1746).
 *
 * THE BOUNDARY IS THE POINT, so every side of it is pinned: the mapping happens
 * and survives a re-render; `safe` still drops and still reports; a property the
 * language cannot spell still reports, so the change cannot read as a blanket
 * "stop reporting"; and a body cell repeating its column's value writes no run
 * of its own, because the head already says it.
 */
class ACellAlignmentImportsAsTheNativeMarkerTest extends TestCase
{
    /**
     * @var string
     */
    protected const CELL = '<table><tr><td style="text-align:right">a</td><td>b</td></tr></table>';

    /**
     * @return array<string>
     */
    protected function codes(string $html, string $mode): array
    {
        $result = (new HtmlToCarve(importMode: $mode))->convertWithReport($html);

        return array_map(
            static fn (array $diagnostic): string => $diagnostic['code'],
            $result->report()['diagnostics'],
        );
    }

    protected function imported(string $html, string $mode): string
    {
        return (new HtmlToCarve(importMode: $mode))->convertWithReport($html)->value;
    }

    protected function cell(string $declaration): string
    {
        return '<table><tr><td style="' . $declaration . '">a</td></tr></table>';
    }

    public function testACellAlignmentMapsInSemanticAndRoundtrip(): void
    {
        foreach (['semantic', 'roundtrip'] as $mode) {
            $this->assertSame("|> a | b |\n", $this->imported(self::CELL, $mode), $mode);
            $this->assertSame([], $this->codes(self::CELL, $mode), $mode);
        }
    }

    public function testAVerticalAlignmentTakesTheInheritedHorizontalMarker(): void
    {
        foreach (['top' => '?^', 'middle' => '?~', 'bottom' => '?v'] as $value => $run) {
            $html = '<table><tr><td style="vertical-align:' . $value . '">a</td><td>b</td></tr></table>';

            $this->assertSame('|' . $run . " a | b |\n", $this->imported($html, 'semantic'), $value);
            $this->assertSame([], $this->codes($html, 'semantic'), $value);
        }
    }

    public function testBothAxesTakeOneRun(): void
    {
        $this->assertSame("|<^ a |\n", $this->imported($this->cell('text-align:left;vertical-align:top'), 'semantic'));
    }

    /**
     * THE LOAD-BEARING ASSERTION. A test on the emitted Carve alone would pass
     * for a spelling no renderer reads, and which bytes come back out is the
     * whole reason the marker beats the key-value.
     */
    public function testTheDeclarationComesBackAsItWasHandedIn(): void
    {
        $converter = new CarveConverter();
        foreach (
            [
                'text-align:right' => 'text-align: right;',
                'vertical-align:top' => 'vertical-align: top;',
                'text-align:left;vertical-align:bottom' => 'text-align: left; vertical-align: bottom;',
            ] as $declaration => $css
        ) {
            $carve = $this->imported($this->cell($declaration), 'semantic');

            $this->assertStringContainsString(
                '<td style="' . $css . '">a</td>',
                $converter->convert($carve),
                $declaration,
            );
        }
    }

    /**
     * `carve -> html -> carve -> html` has to land on itself. It did not with
     * the key-value spelling, which is why this is not a matter of preference.
     */
    public function testAnImportedAlignmentIsAFixedPointThroughHtml(): void
    {
        $converter = new CarveConverter();
        foreach (
            [
                "|> a | b |\n",
                "|?^ a | b |\n",
                "|<^ a |\n",
                "|=> h |\n| a |\n",
                "| a | b |\n| c |> d |\n",
            ] as $source
        ) {
            $first = $converter->convert($source);
            $back = $this->imported($first, 'roundtrip');

            $this->assertSame($source, $back, $source);
            $this->assertSame($first, $converter->convert($back), $source);
        }
    }

    /**
     * The boundary a careless fix crosses.
     */
    public function testSafeStillDropsTheAlignmentAndStillReportsIt(): void
    {
        foreach (['text-align:right', 'vertical-align:top'] as $declaration) {
            $html = '<table><tr><td style="' . $declaration . '">a</td><td>b</td></tr></table>';

            $this->assertSame("| a | b |\n", $this->imported($html, 'safe'), $declaration);
            $this->assertSame(['style-unmapped'], $this->codes($html, 'safe'), $declaration);
        }
    }

    /**
     * The control. Without it the change reads as a blanket "stop reporting".
     */
    public function testAPropertyTheLanguageCannotSpellStillReports(): void
    {
        foreach (['color:red', 'width:50%', 'font-weight:bold', 'border:1px solid'] as $declaration) {
            $this->assertSame("| a |\n", $this->imported($this->cell($declaration), 'semantic'), $declaration);
            $this->assertSame(['style-unmapped'], $this->codes($this->cell($declaration), 'semantic'), $declaration);
        }
    }

    /**
     * A value outside Carve's enums is not quietly rounded to one that is.
     */
    public function testAValueOutsideTheEnumStillReports(): void
    {
        foreach (
            [
                'text-align:justify',
                'text-align:start',
                'vertical-align:baseline',
                'vertical-align:4px',
            ] as $declaration
        ) {
            $this->assertSame("| a |\n", $this->imported($this->cell($declaration), 'semantic'), $declaration);
            $this->assertSame(['style-unmapped'], $this->codes($this->cell($declaration), 'semantic'), $declaration);
        }
    }

    /**
     * OFF A CELL there is no marker run. `align` is a legacy presentational
     * attribute HTML defines for these elements, so the key-value is faithful;
     * `valign` is defined for table cells and nothing else, so writing it onto a
     * paragraph would emit an attribute no reader honours - a spelling that
     * looks like a mapping and is not one.
     */
    public function testOffACellOnlyTheHorizontalAxisMaps(): void
    {
        $this->assertSame("{align=center}\nx\n", $this->imported('<p style="text-align:center">x</p>', 'semantic'));
        $this->assertSame([], $this->codes('<p style="text-align:center">x</p>', 'semantic'));
        $this->assertSame("x\n", $this->imported('<p style="vertical-align:top">x</p>', 'semantic'));
        $this->assertSame(['style-unmapped'], $this->codes('<p style="vertical-align:top">x</p>', 'semantic'));
    }

    /**
     * A body cell repeating its column's value spells what the head already
     * says, and a round trip that wrote it would grow a marker on every body row
     * on each pass through HTML. A cell that DISAGREES keeps its own run: that
     * is the only thing overriding the column.
     */
    public function testABodyCellTheHeadAlreadyCoversWritesNoRun(): void
    {
        $shared = '<table><thead><tr><th style="text-align:right">h</th></tr></thead>'
            . '<tbody><tr><td style="text-align:right">a</td></tr></tbody></table>';
        $this->assertSame("|=> h |\n| a |\n", $this->imported($shared, 'semantic'));

        $differing = '<table><thead><tr><th style="text-align:right">h</th></tr></thead>'
            . '<tbody><tr><td style="text-align:left">a</td></tr></tbody></table>';
        $this->assertSame("|=> h |\n|< a |\n", $this->imported($differing, 'semantic'));

        $headless = '<table><thead><tr><th>h</th></tr></thead>'
            . '<tbody><tr><td style="text-align:right">a</td></tr></tbody></table>';
        $this->assertSame("|= h |\n|> a |\n", $this->imported($headless, 'semantic'));
    }

    /**
     * The column marker is not this mapping and is not mode-gated: it is how a
     * pipe table spells a column, and a sidecar-less `carve -> html -> carve`
     * reconstruction is pinned on it in the default mode
     * (markup-carve/carve#1344, `TheHtmlRoundTripWithoutTheSidecarTest`). The
     * `style-unmapped` row here is the one this change does not reach - the
     * alignment survived, so the row names a loss that did not happen, and
     * suppressing it needs the report to know WHICH cell the column marker took.
     * Left as it was rather than answered approximately.
     */
    public function testSafeStillReconstructsAColumnMarkerFromTheCss(): void
    {
        $html = '<table><thead><tr><th style="text-align:right">h</th></tr></thead>'
            . '<tbody><tr><td>a</td></tr></tbody></table>';

        $this->assertSame("|=> h |\n| a |\n", $this->imported($html, 'safe'));
        $this->assertSame(['style-unmapped'], $this->codes($html, 'safe'));
    }

    /**
     * CSS beats the presentational attribute, and it has to beat it in BOTH
     * source orders - a browser does not read
     * `<td style="text-align:left" align="right">` as right-aligned just because
     * `align` was written second.
     */
    public function testCssBeatsThePresentationalAttributeInBothSourceOrders(): void
    {
        foreach (
            [
                '<table><tr><td style="text-align:left" align="right">a</td></tr></table>',
                '<table><tr><td align="right" style="text-align:left">a</td></tr></table>',
            ] as $html
        ) {
            $this->assertSame("|< a |\n", $this->imported($html, 'semantic'), $html);
            $this->assertSame(['attribute-dropped'], $this->codes($html, 'semantic'), $html);
        }

        $vertical = '<table><tr><td style="vertical-align:top" valign="bottom">a</td></tr></table>';
        $this->assertSame("|?^ a |\n", $this->imported($vertical, 'semantic'));
        $this->assertSame(['attribute-dropped'], $this->codes($vertical, 'semantic'));
    }

    /**
     * The property name and the value are matched case-insensitively, which is
     * how a browser reads them.
     */
    public function testTheDeclarationIsReadCaseInsensitively(): void
    {
        $html = '<table><tr><td style="TEXT-ALIGN: RIGHT">a</td></tr></table>';

        $this->assertSame("|> a |\n", $this->imported($html, 'semantic'));
        $this->assertSame([], $this->codes($html, 'semantic'));
    }

    /**
     * One mapped declaration does not silence the others sharing the attribute.
     */
    public function testAnUnmappedNeighbourDeclarationStillReports(): void
    {
        $html = '<table><tr><td style="text-align:right;color:red">a</td></tr></table>';

        $this->assertSame("|> a |\n", $this->imported($html, 'semantic'));
        $this->assertSame(['style-unmapped'], $this->codes($html, 'semantic'));
    }

    /**
     * A presentational attribute with no CSS beside it was always kept, and the
     * mapping must not start reporting it.
     */
    public function testABarePresentationalAttributeIsUnaffected(): void
    {
        $html = '<table><tr><td align="right">a</td></tr></table>';

        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            $this->assertSame("|{align=right} a |\n", $this->imported($html, $mode), $mode);
            $this->assertSame([], $this->codes($html, $mode), $mode);
        }
    }

    /**
     * The configured alignment class is a THIRD destination and applies OFF a
     * cell only: a cell has the marker run, so it never gains a class.
     */
    public function testAConfiguredAlignmentClassCarriesItOffACell(): void
    {
        $converter = new HtmlToCarve(alignmentClasses: ['center' => 'text-center'], importMode: 'semantic');
        $result = $converter->convertWithReport('<p class="lead" style="text-align: CENTER">Text</p>');

        $this->assertSame("{.lead .text-center}\nText\n", $result->value);
        $this->assertSame([], array_map(
            static fn (array $diagnostic): string => $diagnostic['code'],
            $result->report()['diagnostics'],
        ));
    }

    /**
     * Junk in the attribute does not take the mapping down with it. A chunk with
     * no colon is not a declaration and a chunk with an empty property names
     * nothing, so both are skipped rather than reported as a loss - there was
     * nothing there to lose.
     */
    public function testAMalformedDeclarationDoesNotStopTheOnesBesideIt(): void
    {
        foreach (['nonsense;text-align:right', ':red;text-align:right'] as $style) {
            $this->assertSame("|> a |\n", $this->imported($this->cell($style), 'semantic'), $style);
            $this->assertSame([], $this->codes($this->cell($style), 'semantic'), $style);
        }
    }

    /**
     * A row holding no cells is not emitted, so the row AFTER it is still the
     * head candidate. Reading the head off the first row blindly would have
     * called this table headerless and written the body cell's run twice.
     */
    public function testAnEmptyLeadingRowDoesNotCostTheTableItsHead(): void
    {
        $html = '<table><tr></tr><tr><th style="text-align:right">h</th></tr>'
            . '<tr><td style="text-align:right">a</td></tr></table>';

        $this->assertSame("|=> h |\n| a |\n", $this->imported($html, 'semantic'));
        $this->assertSame([], $this->codes($html, 'semantic'));
    }
}
