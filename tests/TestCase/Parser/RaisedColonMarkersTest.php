<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class RaisedColonMarkersTest extends TestCase
{
    public function testSharedCorpusCases(): void
    {
        $rows = json_decode(file_get_contents(__DIR__ . '/../../fixtures/raised-colon-markers.json'), true, flags: JSON_THROW_ON_ERROR);
        $converter = new CarveConverter();
        foreach ($rows as $row) {
            $this->assertSame($row['html'], trim($converter->convert($row['source'])), $row['name']);
            $this->assertSame($row['html'], trim($converter->convert(CarveConverter::toCarve($row['source']))), $row['name'] . ' formatted');
        }
    }

    public function testBulletOrderedAndTaskMarkersFold(): void
    {
        $converter = new CarveConverter();
        foreach (['- second', '1. second', '- [x] second'] as $marker) {
            foreach ([1, 2, 4, 6, 8] as $column) {
                $source = "- head\n\n      :::\n      a\n" . str_repeat(' ', $column) . $marker . "\n";
                $this->assertStringContainsString("<p>a\n" . $marker . '</p>', $converter->convert($source));
            }
        }
    }
}
