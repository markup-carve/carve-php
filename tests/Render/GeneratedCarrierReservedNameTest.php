<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Render;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-H-116: an authored reserved name is reported even when the renderer writes a carrier of that name (carve#6339).
 */
final class GeneratedCarrierReservedNameTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function options(): array
    {
        return [
            'shifted under roundtrip' => [['headingShift' => 1, 'roundtrip' => true], '<h2 data-carve-source-level="1">A</h2>'],
            'unshifted under roundtrip' => [['roundtrip' => true], '<h1>A</h1>'],
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @param string $heading
     *
     * @return void
     */
    #[DataProvider('options')]
    public function testAuthoredSourceLevelIsReportedAsDropped(array $options, string $heading): void
    {
        $result = Carve::renderWithDiagnostics("{data-carve-source-level=3}\n= A\n", $options);

        $this->assertStringContainsString($heading, $result->html);
        $this->assertSame(['carrier-attribute-dropped'], $result->codes());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function inlineCarriers(): array
    {
        return [
            'autolink' => ["<https://x.y>{data-carve-autolink=1}\n", '<a href="https://x.y" data-carve-autolink>'],
            'footnote reference' => [
                "x[^1]{data-carve-footnote-label=1}\n\n[^1]: n\n",
                'role="doc-noteref" data-carve-footnote-label="1">',
            ],
        ];
    }

    /**
     * @param string $source
     * @param string $carrier
     *
     * @return void
     */
    #[DataProvider('inlineCarriers')]
    public function testAuthoredNameOfAGeneratedCarrierIsReportedAsDropped(string $source, string $carrier): void
    {
        $result = Carve::renderWithDiagnostics($source, ['roundtrip' => true]);

        $this->assertStringContainsString($carrier, $result->html);
        $this->assertSame(['carrier-attribute-dropped'], $result->codes());
    }
}
