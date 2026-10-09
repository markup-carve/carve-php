<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\TestCase;

class DjotFailedBracedOpenersTest extends TestCase
{
    public function testFailedOpenersRemainLiteral(): void
    {
        $rows = json_decode(file_get_contents(__DIR__ . '/../../fixtures/djot-failed-braced-openers.json'), true, flags: JSON_THROW_ON_ERROR);
        $importer = new DjotToCarve();
        $converter = new CarveConverter();
        foreach ($rows as $row) {
            self::assertSame($row['html'], trim($converter->convert($importer->convert($row['source']))), $row['name']);
        }
    }
}
