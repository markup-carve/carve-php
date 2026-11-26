<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

/**
 * carve#6663 (audit ruling): every `<td>` of the `<thead>` stays a data cell, and `header-rows` spells the head (C2-B-186, C2-M-037).
 */
final class HeadDataCellsTest extends TestCase
{
    public function testDataCellsOfTheHeadStayDataCells(): void
    {
        $result = Carve::importHtml(
            '<table><thead><tr><td>a</td><td>b</td></tr></thead><tbody><tr><td>1</td><td>2</td></tr></tbody></table>',
        );
        $this->assertSame("{header-rows=1}\n| a | b |\n| 1 | 2 |\n", $result->source);
        $this->assertSame([], $result->codes());
    }

    public function testCornerCell(): void
    {
        $result = Carve::importHtml(
            '<table><thead><tr><td></td><td>Q1</td><td>Q2</td></tr></thead>'
            . '<tbody><tr><th>North</th><td>1</td><td>2</td></tr></tbody></table>',
        );
        $this->assertSame("{header-rows=1}\n| | Q1 | Q2 |\n|= North | 1 | 2 |\n", $result->source);
        $this->assertSame([], $result->codes());
    }

    public function testSeveralRowsTakeOneCount(): void
    {
        $result = Carve::importHtml('<table><thead><tr><th>a</th><td>b</td></tr><tr><td>c</td></tr></thead></table>');
        $this->assertSame("{header-rows=2}\n|= a | b |\n| c |\n", $result->source);
        $this->assertSame([], $result->codes());
    }

    public function testHeaderCellHeadIsNotReported(): void
    {
        $result = Carve::importHtml('<table><thead><tr><th>a</th></tr></thead><tr><td>1</td></tr></table>');
        $this->assertSame("|= a |\n| 1 |\n", $result->source);
        $this->assertSame([], $result->codes());
    }

    public function testColumnScopeOfAHeadDataCellIsKept(): void
    {
        $result = Carve::importHtml('<table><thead><tr><td scope="col">a</td></tr></thead><tbody><tr><td>1</td></tr></tbody></table>');
        // C2-M-040: a render writes `scope` on header cells only, so a data cell keeps it and the writer reports it.
        $this->assertSame("{header-rows=1}\n| a |\n| 1 |\n", $result->source);
        $this->assertSame(['field-unspellable'], $result->codes());
    }
}
