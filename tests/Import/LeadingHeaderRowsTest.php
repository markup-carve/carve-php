<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

/**
 * C2-M-037: leading `<th>` rows of a table with no `<thead>` become its head.
 */
final class LeadingHeaderRowsTest extends TestCase
{
    public function testEmptiedBodyReportsItsAttributes(): void
    {
        $result = Carve::importHtml('<table><tbody id="foo"><tr><th>A</th></tr></tbody></table>');
        $this->assertSame("|= A |\n", $result->source);
        $this->assertSame(['import-attribute-dropped'], $result->codes());
    }

    public function testBodyWithRowsLeftKeepsItsAttributes(): void
    {
        $result = Carve::importHtml('<table><tbody id="foo"><tr><th>A</th></tr><tr><td>b</td></tr></tbody></table>');
        $this->assertSame('table_head', $result->document['children'][0]['children'][0]['type']);
        $this->assertSame(['id' => 'foo'], $result->document['children'][0]['children'][1]['attrs']);
    }

    public function testHeadRowSpanEndsAtTheHead(): void
    {
        $result = Carve::importHtml('<table><tr><th rowspan="3">A</th><th>B</th></tr><tr><td>1</td><td>2</td></tr></table>');
        $this->assertSame("|= A |= B |\n| 1 | 2 |\n", $result->source);
        $this->assertSame(['import-structure-repaired'], $result->codes());
    }
}
