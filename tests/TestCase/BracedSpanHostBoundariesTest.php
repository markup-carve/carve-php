<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BracedSpanHostBoundariesTest extends TestCase
{
    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function boundaries(): array
    {
        $rows = json_decode(file_get_contents(__DIR__ . '/../fixtures/braced-span-host-boundaries.json'), true, flags: JSON_THROW_ON_ERROR);
        $cases = [];
        foreach ($rows as $row) {
            $cases[$row['name']] = [$row['source'], $row['html'], $row['roundTrip']];
        }

        return $cases;
    }

    #[DataProvider('boundaries')]
    public function testBoundary(string $source, string $html, bool $roundTrip): void
    {
        $converter = new CarveConverter();
        self::assertSame($html, trim($converter->convert($source)));
        if ($roundTrip) {
            self::assertSame($html, trim($converter->convert(CarveConverter::toCarve($source))));
        }
    }
}
