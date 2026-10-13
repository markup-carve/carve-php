<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SpecParityTest extends TestCase
{
    #[DataProvider('cases')]
    public function testSpecParity(string $source, string $html): void
    {
        self::assertSame(trim($html), trim((new CarveConverter())->convert($source)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        foreach (glob(dirname(__DIR__, 2) . '/fixtures/spec-parity/*.json') ?: [] as $path) {
            $rows = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            foreach ($rows as $row) {
                yield $row['id'] => [$row['source'], $row['html']];
            }
        }
    }
}
