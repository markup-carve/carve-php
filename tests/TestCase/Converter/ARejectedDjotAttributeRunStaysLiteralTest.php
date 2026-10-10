<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ARejectedDjotAttributeRunStaysLiteralTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function runs(): array
    {
        return [
            'own line' => ["[t]{k=\"{\"\"}\n", "[t]\\{k=\"\\{\"\u{201d}\n"],
            'in a paragraph' => ["para [t]{k=\"{\"\"} tail\n", "para [t]\\{k=\"\\{\"\u{201d} tail\n"],
        ];
    }

    /**
     * Only the first brace of a rejected run used to be escaped, so a later pass read the
     * second one as a forced quote and swallowed it.
     */
    #[DataProvider('runs')]
    public function testKeepsEveryBraceOfTheRun(string $djot, string $carve): void
    {
        self::assertSame($carve, (new DjotToCarve())->convert($djot));
    }
}
