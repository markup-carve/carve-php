<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Extension;

use MarkupCarve\Carve\Extension\ColumnReservations;
use PHPUnit\Framework\TestCase;

class ColumnReservationsTest extends TestCase
{
    public function testSparseHoldsOverlappingExtensionsAndExpiryMatchColumnScans(): void
    {
        $index = new ColumnReservations(64);
        $held = array_fill(0, 64, 0);
        self::assertSame(0, $index->nextFree(0, 0));
        self::assertSame(0, $index->reach(0));
        for ($row = 0; $row < 20; $row++) {
            if ($row < 12) {
                for ($j = 0; $j < 16; $j++) {
                    $col = ($row * 17 + $j * 13) % 64;
                    $end = $row + 2 + $j % 7;
                    $held[$col] = max($held[$col], $end);
                    $index->hold($col, $end);
                }
            }
            $reach = 0;
            foreach ($held as $col => $end) {
                if ($end > $row) {
                    $reach = $col + 1;
                }
            }
            self::assertSame($reach, $index->reach($row));
            for ($from = 0; $from <= 65; $from++) {
                $expected = $from;
                while (($held[$expected] ?? 0) > $row) {
                    $expected++;
                }
                self::assertSame($expected, $index->nextFree($from, $row));
            }
        }
    }
}
