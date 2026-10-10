<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ATrailingDjotAttributeLineBeforeACloserIsDroppedTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function divs(): array
    {
        return [
            'explicit closer' => ["::: foo\n# b\n{.c}\n:::\n", "::: foo\n# b\n:::\n"],
            'closer the import supplies' => ["::: foo\n# b\n{.c}\n", "::: foo\n# b\n:::\n"],
            'attribute line with a block after it' => ["::: foo\n{.c}\n# b\n:::\n", "::: foo\n{.c}\n# b\n:::\n"],
        ];
    }

    #[DataProvider('divs')]
    public function testKeepsOnlyAnAttributeLineThatHasABlockAfterIt(string $djot, string $carve): void
    {
        self::assertSame($carve, (new DjotToCarve())->convert($djot));
    }
}
