<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Parser\Block\TableParser;
use PHPUnit\Framework\TestCase;

class TableSeparatorAnalysisTest extends TestCase
{
    public function testReturnsAlignmentAndWidthsFromOneAnalysis(): void
    {
        $analysis = (new TableParser())->analyzeSeparatorRow('| :---- | ---: | :---: |');

        $this->assertSame(
            [TableCell::ALIGN_LEFT, TableCell::ALIGN_RIGHT, TableCell::ALIGN_CENTER],
            $analysis['alignments'] ?? null,
        );
        $this->assertSame([4, 3, 3], $analysis['widths'] ?? null);
    }

    public function testRejectsNonSeparatorRows(): void
    {
        $parser = new TableParser();

        $this->assertNull($parser->analyzeSeparatorRow('not a table'));
        $this->assertNull($parser->analyzeSeparatorRow('| --- | content |'));
    }
}
