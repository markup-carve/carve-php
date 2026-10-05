<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Parser\BracedEndMemo;
use PHPUnit\Framework\TestCase;

class BracedEndMemoTest extends TestCase
{
    public function testDenseAndSparseEntriesKeepTheirExactEnds(): void
    {
        $memo = new BracedEndMemo(4096);
        $reference = [];
        for ($round = 0; $round < 5; $round++) {
            $positions = [];
            for ($at = $round; $at < 4096; $at += 7) {
                $positions[] = $at;
                $reference[$at] = PHP_INT_MAX - $round;
            }
            $memo->record($positions, PHP_INT_MAX - $round);
            for ($at = 0; $at < 4096; $at++) {
                $this->assertSame($reference[$at] ?? null, $memo->get($at));
            }
        }
        $memo->record([], 3);
        $this->assertSame($reference[0], $memo->get(0));
    }
}
