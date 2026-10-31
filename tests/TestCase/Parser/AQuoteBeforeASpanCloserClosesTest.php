<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A span's closer is not the next source character for CARVE-P3-023 R1, so a
 * quote after a dash or slash at the end of span content closes (ruling on
 * markup-carve/carve#4318).
 */
class AQuoteBeforeASpanCloserClosesTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'strong' => ['*"Wait-"*', '<p><strong>“Wait-”</strong></p>'],
            'underline' => ['_"Wait-"_', '<p><u>“Wait-”</u></p>'],
            'insert' => ['{+"Wait-"+}', '<p><ins>“Wait-”</ins></p>'],
            'single after slash' => ["*a/'*", '<p><strong>a/’</strong></p>'],
            'nested closers' => ['*a _b-"_*', '<p><strong>a <u>b-”</u></strong></p>'],
        ];
    }

    #[DataProvider('cases')]
    public function testQuote(string $source, string $html): void
    {
        $this->assertSame($html, trim((new CarveConverter())->convert($source . "\n")));
    }
}
