<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use MarkupCarve\Carve\Test\Support\RoundTrip;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FootnoteIdRegistryTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function cases(): array
    {
        return [
            'html-60 heading takes note id' => ["# fn1\n\na[^x]\n\n[^x]: n"],
            'html-85 authored reference takes note id' => ["a^[x] and b[^n]{#fn1}\n\n[^n]: y"],
            'html-86 authored reference takes another reference id' => ['a^[x]{#fnref2} b^[y]'],
            'no collision' => ["a[^x] b^[y] c[^x]\n\n[^x]: n"],
        ];
    }

    #[DataProvider('cases')]
    public function testGeneratedAndAuthoredIdsRoundTrip(string $source): void
    {
        [$verdict, $expected, $got] = RoundTrip::check($source);
        self::assertSame('equal', $verdict);
        self::assertSame($expected, $got);
    }
}
