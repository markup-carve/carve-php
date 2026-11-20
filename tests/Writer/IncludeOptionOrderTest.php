<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IncludeOptionOrderTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'delimited table' => [
                "{{ parts/plans.csv @cols:\"Plan,Price\" @rows:3-4 @code:csv }}\n",
                "{{ parts/plans.csv @code:csv @rows:3-4 @cols:\"Plan,Price\" }}\n",
            ],
            'line slice' => [
                "{{ a.txt @lines:2-3 @code:txt }}\n",
                "{{ a.txt @code:txt @lines:2-3 }}\n",
            ],
            'region slice' => [
                "{{ a.js @region:sample @code:js }}\n",
                "{{ a.js @code:js @region:sample }}\n",
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testFormatWritesIncludeModeFirst(string $source, string $expected): void
    {
        self::assertSame($expected, Carve::format($source));
        self::assertSame($expected, Carve::format($expected));
    }
}
