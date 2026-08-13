<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A GFM header separator is recognized only as the table's SECOND row (the row
 * right after the single header row). A delimiter line anywhere else -- leading,
 * or after the body -- is an ordinary data row. Matches carve-js / carve-rs.
 */
class TableSeparatorRowPositionTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testSecondRowSeparatorMakesHeader(): void
    {
        $html = $this->converter->convert("| x | y |\n|---|---|");
        $this->assertStringContainsString('<thead><tr><th scope="col">x</th><th scope="col">y</th></tr></thead>', $html);
    }

    public function testLeadingDelimiterIsADataRow(): void
    {
        // `|---|` as the first row is not a separator (no header precedes it).
        $html = $this->converter->convert("|---|\n| a |");
        $this->assertStringNotContainsString('<thead>', $html);
        $this->assertStringNotContainsString('<th scope="col">', $html);
    }

    public function testMidTableDelimiterIsADataRow(): void
    {
        // A delimiter after the body does not retroactively make a header; the
        // previous bug rendered a <th> inside <tbody>.
        $html = $this->converter->convert("| h |\n| a |\n|---|\n| b |");
        $this->assertStringNotContainsString('<thead>', $html);
        $this->assertStringNotContainsString('<th scope="col">', $html);
    }
}
