<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
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

        $this->assertStringContainsString("<thead>\n    <tr><th scope=\"col\">A</th><th scope=\"col\">B</th></tr>\n  </thead>", $html);
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

        $this->assertStringContainsString("<thead>\n    <tr><th scope=\"col\">A</th><th scope=\"col\">B</th></tr>\n  </thead>", $html);
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

    public function testLaterAllHeaderRowDoesNotResetColumnDefaults(): void
    {
        $source = "|=> A|<|=~ B|\n|a|b|c|\n|=< D|<|= End|\n|d|e|f|\n";
        $html = $this->converter->convert($source);

        $this->assertStringContainsString('<th scope="row" colspan="2" style="text-align: left;">D</th>', $html);
        $this->assertStringContainsString('<td style="text-align: right;">d</td><td>e</td><td style="text-align: center;">f</td>', $html);
    }

    public function testLaterAllHeaderRowKeepsVerticalMarkerLocal(): void
    {
        $source = "|=>^ A|= B|\n|a|b|\n|=<v C|= D|\n|c|d|\n";
        $html = $this->converter->convert($source);

        $this->assertStringContainsString('<th scope="row" style="text-align: left; vertical-align: bottom;">C</th>', $html);
        $this->assertStringContainsString('<td style="text-align: right; vertical-align: top;">c</td>', $html);
    }

    public function testStatedHeadRowsControlColumnDefaults(): void
    {
        $none = $this->converter->convert("{header-rows=0}\n|=> A|= B|\n|c|d|\n");
        $this->assertStringContainsString('<tr><td>c</td><td>d</td></tr>', $none);

        $one = $this->converter->convert("{header-rows=1}\n|=> A|= B|\n|=< C|= D|\n|c|d|\n");
        $this->assertStringContainsString('<th scope="row" style="text-align: left;">C</th>', $one);
        $this->assertStringContainsString('<td style="text-align: right;">c</td>', $one);

        $three = $this->converter->convert("{header-rows=3}\n|=>^ A|= B|\n|a|b|\n|=<v C|= D|\n|c|d|\n");
        $this->assertStringContainsString('<th scope="col" style="text-align: left; vertical-align: bottom;">a</th>', $three);
        $this->assertStringContainsString('<td style="text-align: left; vertical-align: bottom;">c</td>', $three);
    }

    public function testGfmPromotionAppliesLaterHeaderDefaultToEarlierHeadCell(): void
    {
        $html = $this->converter->convert("|A|B|\n|---|---|\n|=< C|= D|\n|c|d|\n");

        $this->assertStringContainsString('<th scope="col" style="text-align: left;">A</th>', $html);
        $this->assertStringContainsString('<td style="text-align: left;">c</td>', $html);
    }

    public function testAstKeepsInheritedHeadAlignmentOutOfCellFields(): void
    {
        $wire = (new AstCodec())->encode((new BlockParser())->parse("|A|B|\n|---|---|\n|=< C|= D|\n|c|d|\n"));
        $rows = $wire['children'][0]['rows'];

        $this->assertArrayNotHasKey('align', $rows[0]['cells'][0]);
        $this->assertSame('left', $rows[1]['cells'][0]['align']);
        $this->assertArrayNotHasKey('align', $rows[2]['cells'][0]);
    }
}
