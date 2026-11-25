<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

/**
 * carve#6663: every `<td>` of the `<thead>` that becomes the head is a header cell, reported once at the `<thead>`.
 */
final class HeadDataCellsTest extends TestCase
{
    public function testDataCellsOfTheHeadReadAsHeaderCells(): void
    {
        $result = Carve::importHtml(
            '<table><thead><tr><td>a</td><td>b</td></tr></thead><tbody><tr><td>1</td><td>2</td></tr></tbody></table>',
        );
        $this->assertSame("|= a |= b |\n| 1 | 2 |\n", $result->source);
        $this->assertSame(['import-structure-repaired'], $result->codes());
    }

    public function testCornerCell(): void
    {
        $result = Carve::importHtml(
            '<table><thead><tr><td></td><td>Q1</td><td>Q2</td></tr></thead>'
            . '<tbody><tr><th>North</th><td>1</td><td>2</td></tr></tbody></table>',
        );
        $this->assertSame("|= |= Q1 |= Q2 |\n|= North | 1 | 2 |\n", $result->source);
        $this->assertSame(['import-structure-repaired'], $result->codes());
    }

    public function testOneReportForSeveralRows(): void
    {
        $result = Carve::importHtml('<table><thead><tr><th>a</th><td>b</td></tr><tr><td>c</td></tr></thead></table>');
        $this->assertSame("|= a |= b |\n|= c |\n", $result->source);
        $this->assertSame(['import-structure-repaired'], $result->codes());
    }

    public function testHeaderCellHeadIsNotReported(): void
    {
        $result = Carve::importHtml('<table><thead><tr><th>a</th></tr></thead><tr><td>1</td></tr></table>');
        $this->assertSame("|= a |\n| 1 |\n", $result->source);
        $this->assertSame([], $result->codes());
    }

    public function testColumnScopeOfAHeadDataCellIsImplied(): void
    {
        $result = Carve::importHtml('<table><thead><tr><td scope="col">a</td></tr></thead><tbody><tr><td>1</td></tr></tbody></table>');
        $this->assertSame("|= a |\n| 1 |\n", $result->source);
        $this->assertSame(['import-structure-repaired'], $result->codes());
    }
}
