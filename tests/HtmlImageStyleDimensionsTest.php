<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

final class HtmlImageStyleDimensionsTest extends TestCase
{
    public function testIntegralPixelStylesOverrideImageAttributes(): void
    {
        $importer = new HtmlToCarve();
        foreach (
            [
                '<img src="x" alt="x" style="width:20px;height:30px">',
                '<img style="height:30px;width:20px" src="x" alt="x">',
                '<img width="10" height="11" src="x" alt="x" style="width:020.0px;height:+30px">',
                '<img style="width:20px;height:30px" width="10" height="11" src="x" alt="x">',
            ] as $html
        ) {
            $result = $importer->convertWithFidelityReport($html);
            self::assertStringContainsString('width=20', $result->value);
            self::assertStringContainsString('height=30', $result->value);
            self::assertNotContains('style-unmapped', array_column($result->diagnostics, 'code'));
        }
    }

    public function testZeroAndUnmappedValues(): void
    {
        $importer = new HtmlToCarve();
        self::assertStringContainsString('width=0', $importer->convert('<img src="x" alt="x" style="width:0;height:5px">'));
        $result = $importer->convertWithFidelityReport('<img src="x" alt="x" style="width:50%;height:auto">');
        self::assertSame(['style-unmapped', 'style-unmapped'], array_values(array_filter(array_column($result->diagnostics, 'code'), static fn (string $code): bool => $code === 'style-unmapped')));
    }
}
