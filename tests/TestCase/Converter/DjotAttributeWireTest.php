<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\TestCase;

class DjotAttributeWireTest extends TestCase
{
    public function testAttributeValuesSurviveImport(): void
    {
        $rows = json_decode(file_get_contents(__DIR__ . '/../../fixtures/djot-attribute-wire.json'), true, flags: JSON_THROW_ON_ERROR);
        $importer = new DjotToCarve();
        $converter = new CarveConverter();
        foreach ($rows as $row) {
            self::assertSame($row['html'], trim($converter->convert($importer->convert($row['source']))), $row['name']);
        }
    }
}
