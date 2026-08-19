<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Extension\ListTableExtension;
use PHPUnit\Framework\TestCase;

/**
 * A pipe-table cell is one line of inline content, so a cell holding two
 * paragraphs, a list or a code block degrades to its text. ListTable is the
 * construct for that case, and the toggle is off by default because ListTable
 * is Tier-2: emitting one for a processor that has not enabled it renders a
 * nested list in a div, which is worse than the degradation it replaces.
 */
class HtmlToCarveListTableTest extends TestCase
{
    protected HtmlToCarve $enabled;

    protected HtmlToCarve $default;

    protected function setUp(): void
    {
        $this->enabled = new HtmlToCarve(listTableForBlockCells: true);
        $this->default = new HtmlToCarve();
    }

    protected function render(string $carve): string
    {
        $converter = new CarveConverter();
        $converter->addExtension(new ListTableExtension());

        return (string)preg_replace('/\s+/', ' ', $converter->convert($carve));
    }

    public function testTheToggleIsOffByDefault(): void
    {
        $carve = $this->default->convert('<table><tr><td><p>a</p><p>b</p></td></tr></table>');

        $this->assertStringNotContainsString('list-table', $carve);
        $this->assertStringContainsString('| a b |', $carve);
    }

    public function testATableWithOnlyInlineCellsKeepsThePipeForm(): void
    {
        $carve = $this->enabled->convert('<table><tr><td>a</td><td>b</td></tr></table>');

        $this->assertStringNotContainsString('list-table', $carve);
        $this->assertStringContainsString('| a | b |', $carve);
    }

    /**
     * A single paragraph is not a reason to switch: a list-table collapses it
     * to inline content anyway (extensions §5.2).
     */
    public function testASingleParagraphCellKeepsThePipeForm(): void
    {
        $carve = $this->enabled->convert('<table><tr><td><p>a</p></td><td><p>b</p></td></tr></table>');

        $this->assertStringNotContainsString('list-table', $carve);
    }

    public function testBlockContentInACellSurvivesAsBlockContent(): void
    {
        $carve = $this->enabled->convert(
            '<table><caption>Q</caption><thead><tr><th>Region</th><th>Notes</th></tr></thead>'
            . '<tbody><tr><td>EMEA</td><td><p>Strong.</p><ul><li>new</li></ul></td></tr></tbody></table>',
        );

        $this->assertStringContainsString("{header-rows=1}\n::: list-table \"Q\"", $carve);
        $this->assertStringContainsString(
            '<td><p>Strong.</p> <ul> <li>new</li> </ul></td>',
            $this->render($carve),
        );
        $this->assertStringContainsString('<caption>Q</caption>', $this->render($carve));
        $this->assertStringContainsString('<th scope="col">Region</th>', $this->render($carve));
    }

    public function testAListCellIsEnoughOnItsOwn(): void
    {
        $carve = $this->enabled->convert('<table><tr><td><ul><li>one</li></ul></td><td>x</td></tr></table>');

        $this->assertStringContainsString('::: list-table', $carve);
        $this->assertStringContainsString('<li>one</li>', $this->render($carve));
    }

    public function testColspanUsesTheSameMarkerAPipeTableUses(): void
    {
        $carve = $this->enabled->convert(
            '<table><tr><td colspan="2"><p>a</p><p>b</p></td></tr><tr><td>x</td><td>y</td></tr></table>',
        );

        $this->assertStringContainsString('  - <', $carve);
        $this->assertStringContainsString('<td colspan="2">', $this->render($carve));
    }

    public function testRowspanUsesTheSameMarkerAPipeTableUses(): void
    {
        $carve = $this->enabled->convert(
            '<table><tr><td rowspan="2"><p>a</p><p>b</p></td><td>x</td></tr><tr><td>y</td></tr></table>',
        );

        $this->assertStringContainsString('- - ^', $carve);
        $this->assertStringContainsString('<td rowspan="2">', $this->render($carve));
    }

    public function testALeadingHeaderColumnBecomesHeaderCols(): void
    {
        $carve = $this->enabled->convert(
            '<table><tr><th>R</th><td><p>a</p><p>b</p></td></tr><tr><th>S</th><td>y</td></tr></table>',
        );

        $this->assertStringContainsString('{header-cols=1}', $carve);
        $this->assertStringContainsString('<th scope="row">R</th>', $this->render($carve));
    }

    /**
     * A column still spanned after the row's LAST real cell needs its marker
     * too. Without it the row simply ended, the span was lost, and the next
     * row gained an empty cell (raised by codex review).
     */
    public function testARowspanPastTheLastCellOfARowKeepsItsMarker(): void
    {
        $carve = $this->enabled->convert(
            '<table><tr><td>x</td><td rowspan="2"><p>a</p><p>b</p></td></tr><tr><td>y</td></tr></table>',
        );

        $this->assertStringContainsString("- - y\n  - ^", $carve);
        $this->assertStringContainsString('<td rowspan="2">', $this->render($carve));
        $this->assertStringNotContainsString('<td></td>', $this->render($carve));
    }

    /**
     * The table's own attributes go on the block, not into the void: ListTable
     * passes non-structural attributes through to the rendered table.
     */
    public function testTheTablesOwnAttributesSurviveTheSwitch(): void
    {
        $carve = $this->enabled->convert(
            '<table class="striped" id="t1"><tr><td><p>a</p><p>b</p></td></tr></table>',
        );

        $this->assertStringContainsString('{#t1 .striped}', $carve);
        $this->assertStringContainsString('<table class="striped" id="t1">', $this->render($carve));
    }

    public function testTableAttributesAndHeaderMetadataShareOneBlock(): void
    {
        $carve = $this->enabled->convert(
            '<table class="striped"><tr><th>H</th></tr><tr><td><p>a</p><p>b</p></td></tr></table>',
        );

        $this->assertStringContainsString('{.striped header-rows=1}', $carve);
        $this->assertStringContainsString('<table class="striped">', $this->render($carve));
        $this->assertStringContainsString('<th scope="col">H</th>', $this->render($carve));
    }

    /**
     * Known limitation, pinned so it is visible rather than discovered: a
     * cell's own attributes are dropped in this form. Carve has no per-list-item
     * attribute spelling this converter could find - `{.c}` on its own line
     * attaches to the LIST, and after the marker it is literal - so writing one
     * put the class on the cell's first paragraph instead of on the cell.
     */
    public function testACellsOwnAttributesAreDroppedInThisForm(): void
    {
        $carve = $this->enabled->convert(
            '<table><tr><td class="c"><p>a</p><p>b</p></td><td>x</td></tr></table>',
        );

        $this->assertStringNotContainsString('{.c}', $carve);
        $this->assertStringContainsString('<td><p>a</p> <p>b</p></td>', $this->render($carve));
    }
}
