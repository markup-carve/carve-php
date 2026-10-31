<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TicketReproductionsStayFixedTest extends TestCase
{
    #[DataProvider('rows')]
    public function testTheReproductionRendersAsTheTicketExpects(string $source, string $html): void
    {
        self::assertSame($html, trim((new CarveConverter())->convert($source)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rows(): iterable
    {
        $rows = json_decode((string)file_get_contents(__DIR__ . '/fixtures/ticket-reproductions.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($rows as $index => $row) {
            yield $row['ticket'] . ' row ' . $index => [$row['source'], $row['html']];
        }
    }
}
