<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A `|=` cell in a body row is a row header: it renders as <th> inside <tbody>,
 * while the row itself stays a body row. This is what the separator-row model
 * cannot express -- per-cell header scoping in data rows.
 */
class TableRowHeaderTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testBodyCellMarkerBecomesRowHeader(): void
    {
        $html = $this->converter->convert("|= A |= B |\n|= R | 1 |");

        $this->assertStringContainsString('<thead><tr><th scope="col">A</th><th scope="col">B</th></tr></thead>', $html);
        // The row header is a <th>, the rest of the row stays <td>, and the row
        // is still part of <tbody> (it is not promoted into <thead>).
        $this->assertStringContainsString('<tbody>', $html);
        $this->assertStringContainsString('<tr><th scope="row">R</th><td>1</td></tr>', $html);
    }

    public function testRowHeaderDoesNotExtendHeaderSection(): void
    {
        // Only the leading all-header row forms <thead>; a later row with a row
        // header must not be pulled into <thead>.
        $html = $this->converter->convert("|= A |= B |\n|= R1 | 1 |\n|= R2 | 2 |");

        $this->assertStringContainsString('<thead><tr><th scope="col">A</th><th scope="col">B</th></tr></thead>', $html);
        $this->assertStringContainsString('<tr><th scope="row">R1</th><td>1</td></tr>', $html);
        $this->assertStringContainsString('<tr><th scope="row">R2</th><td>2</td></tr>', $html);
    }

    public function testHeaderlessTableCanHaveRowHeaders(): void
    {
        // No leading header row at all -- every first cell is a row header.
        $html = $this->converter->convert("|= Mercury | 4,879 |\n|= Venus | 12,104 |");

        $this->assertStringNotContainsString('<thead>', $html);
        $this->assertStringContainsString('<tr><th scope="row">Mercury</th><td>4,879</td></tr>', $html);
        $this->assertStringContainsString('<tr><th scope="row">Venus</th><td>12,104</td></tr>', $html);
    }

    public function testRowHeaderKeepsAlignmentMarker(): void
    {
        // A row-header cell may still carry its own alignment marker (|=>).
        $html = $this->converter->convert("|= Item |= Qty |\n|=> Total | 5 |");

        $this->assertStringContainsString('<th scope="row" style="text-align: right;">Total</th>', $html);
    }
}
