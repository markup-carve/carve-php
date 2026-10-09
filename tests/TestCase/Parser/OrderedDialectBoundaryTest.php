<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

class OrderedDialectBoundaryTest extends TestCase
{
    public function testADialectCannotCrossAHardListBoundary(): void
    {
        $rows = json_decode(file_get_contents(__DIR__ . '/../../fixtures/ordered-dialect-boundaries.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($rows as $row) {
            $this->assertSame($row['html'], trim((new CarveConverter())->convert($row['source'])), $row['name']);
            $this->assertSame($row['source'], CarveConverter::toCarve($row['source']), $row['name']);
            $import = (new HtmlToCarve())->convertWithReport($row['inputHtml']);
            $this->assertSame($row['source'], $import->value, $row['name']);
            $this->assertSame([], $import->diagnostics, $row['name']);
            $this->assertSame($row['html'], trim((new CarveConverter())->convert($import->value)), $row['name']);
        }
    }

    public function testTwoBlankLinesStillPermitTheSiblingTieBreak(): void
    {
        $this->assertStringContainsString('<ol type="i" start="5">', (new CarveConverter())->convert("v. x\n\n\nvi. y\n"));
    }
}
