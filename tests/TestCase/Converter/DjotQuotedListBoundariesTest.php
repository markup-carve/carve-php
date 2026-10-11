<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\TestCase;

final class DjotQuotedListBoundariesTest extends TestCase
{
    public function testQuoteAndListOwnership(): void
    {
        $cases = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/djot-quoted-list-boundaries.json'), true, 512, JSON_THROW_ON_ERROR);
        $importer = new DjotToCarve();
        $renderer = new CarveConverter();
        foreach ($cases as $case) {
            self::assertSame($renderer->convert($case['carve']), $renderer->convert($importer->convert($case['source'])), $case['source']);
        }
    }
}
