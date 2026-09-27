<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AnUnspellableFenceClassStaysGenericTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function unspellableProvider(): array
    {
        return [
            'a bar is not an identifier' => ['|', '{.-}'],
            'a leading hyphen is not' => ['-foo', '{.-foo}'],
        ];
    }

    #[DataProvider('unspellableProvider')]
    public function testImporterKeepsAnUnspellableClassOnAGenericDiv(string $class, string $opener): void
    {
        self::assertSame(
            $opener . "\n:::\ny\n:::",
            trim((new HtmlToCarve())->convert('<div class="' . $class . '"><p>y</p></div>')),
        );
    }

    public function testImporterStillConsumesSpellableFenceClass(): void
    {
        self::assertSame(
            "::: col2\ny\n:::",
            trim((new HtmlToCarve())->convert('<div class="col2"><p>y</p></div>')),
        );
    }
}
