<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
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
            'a bar is not an identifier' => ['|', '{class="\|"}'],
            'a leading hyphen is not' => ['-foo', '{class="-foo"}'],
        ];
    }

    #[DataProvider('unspellableProvider')]
    public function testImporterKeepsAnUnspellableClassOnAGenericDiv(string $class, string $opener): void
    {
        $imported = trim((new HtmlToCarve())->convert('<div class="' . $class . '"><p>y</p></div>'));

        self::assertSame($opener . "\n:::\ny\n:::", $imported);
        self::assertStringContainsString(
            '<div class="' . htmlspecialchars($class, ENT_QUOTES) . '">',
            (new CarveConverter())->convert($imported),
        );
    }

    #[DataProvider('unspellableProvider')]
    public function testTheImportIsAFormatterFixedPoint(string $class, string $opener): void
    {
        $imported = (new HtmlToCarve())->convert('<div class="' . $class . '"><p>y</p></div>');

        self::assertSame($imported, CarveConverter::carve()->convert($imported));
    }

    public function testImporterStillConsumesSpellableFenceClass(): void
    {
        self::assertSame(
            "::: col2\ny\n:::",
            trim((new HtmlToCarve())->convert('<div class="col2"><p>y</p></div>')),
        );
    }
}
