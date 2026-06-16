<?php

declare(strict_types=1);

namespace Carve\Test\TestCase;

use Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A `^` rowspan marker extends the cell above it even across the thead/tbody
 * boundary, including a GFM-delimiter-promoted header (matches carve-js).
 */
class TableHeaderRowspanTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testNativeHeaderCellSpansIntoBody(): void
    {
        $result = $this->converter->convert("|= H |= G |\n| ^ | b |\n| ^ | c |");
        $this->assertStringContainsString('<th rowspan="3">H</th>', $result);
    }

    public function testGfmSeparatorHeaderCellSpansIntoBody(): void
    {
        $result = $this->converter->convert("| H | G |\n|---|---|\n| ^ | c |");
        $this->assertStringContainsString('<th rowspan="2">H</th>', $result);
    }

    public function testHeaderRowspanCoexistsWithBodyRowspan(): void
    {
        // H spans header+row1; b spans row1+row2; x stays in row2 (its column
        // is NOT covered by H, whose span ended). The cell must not be dropped.
        $result = $this->converter->convert("|= H |= G |\n| ^ | b |\n| x | ^ |");
        $this->assertStringContainsString('<th rowspan="2">H</th>', $result);
        $this->assertStringContainsString('<td rowspan="2">b</td>', $result);
        $this->assertStringContainsString('<td>x</td>', $result);
    }
}
