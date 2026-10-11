<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use InvalidArgumentException;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StoredSourceTrustRequiresRoundTripImportTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function filteredModes(): array
    {
        return ['safe' => ['safe'], 'semantic' => ['semantic']];
    }

    #[DataProvider('filteredModes')]
    public function testTrustRejectsAnExplicitFilteredMode(string $mode): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("trustStoredSource requires importMode 'roundtrip'");
        new HtmlToCarve(trustStoredSource: true, importMode: $mode);
    }

    public function testTrustSelectsRoundTripWhenModeIsOmitted(): void
    {
        $result = (new HtmlToCarve(trustStoredSource: true))->convertWithFidelityReport('<p>x</p>');
        $this->assertSame('roundtrip', $result->report()['mode']);
    }

    public function testDefaultModeIsSafe(): void
    {
        $result = (new HtmlToCarve())->convertWithFidelityReport('<p>x</p>');
        $this->assertSame('safe', $result->report()['mode']);
    }
}
