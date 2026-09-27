<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlImportDiagnostic;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

/**
 * PART 12 section 15 (CARVE-P12-034): a single body's row-head count is what
 * the cells' own header flags say, so it publishes no `rowGroups` and the
 * written table loses nothing.
 */
class ARowHeadCountTheCellsStateIsNotAPartitionTest extends TestCase
{
    /**
     * @var string
     */
    private const ROW_HEADERS = '<table><tr><th>A</th><th>B</th></tr><tr><th>1</th><td>x</td></tr>';

    public function testASingleBodysRowHeadColumnPublishesNoPartition(): void
    {
        $ast = (new HtmlToCarve())->convertToAst(self::ROW_HEADERS . '</table>');

        $this->assertArrayNotHasKey('rowGroups', $ast['children'][0]);
    }

    public function testTheWrittenTableReportsNoLostGrouping(): void
    {
        $result = (new HtmlToCarve())->convertWithReport(self::ROW_HEADERS . '</table>');

        $this->assertSame([], array_map(static fn (HtmlImportDiagnostic $d): string => $d->code, $result->diagnostics));
        $this->assertSame("|= A |= B |\n|= 1 | x |\n", $result->value);
    }

    public function testAFootStillPublishesThePartitionWithTheCount(): void
    {
        $ast = (new HtmlToCarve())->convertToAst(self::ROW_HEADERS . '<tfoot><tr><td>f</td><td>g</td></tr></tfoot></table>');

        $this->assertSame(1, $ast['children'][0]['rowGroups']['bodies'][0]['rowHeadColumns'] ?? null);
    }
}
