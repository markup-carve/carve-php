<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class QuotedNoteLazyBraceColumnTest extends TestCase
{
    public function testRow1029RefusesTheRunAtItsWrittenColumn(): void
    {
        $source = "> [^a]: +\n> {#i\n .y}\n\nsee[^a]\n";
        $expected = file_get_contents(__DIR__ . '/spec/tests/corpus/1029-a-quoted-note-run-uses-the-lazy-line-written-column.html');
        self::assertIsString($expected);

        self::assertSame(rtrim($expected, "\n"), (new CarveConverter())->convert($source));
    }
}
