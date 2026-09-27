<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ADigitLeadingFenceClassOpensAFenceTest extends TestCase
{
    /**
     * `admonition_open` resolves through `admonition_type` to
     * `explicit_identifier`, which admits an ASCII digit first. carve-js and
     * carve-rs write the same bytes; markup-carve/carve#2529 ruled the three
     * agree.
     *
     * @return array<string, array{string}>
     */
    public static function modeProvider(): array
    {
        return [
            'safe' => ['safe'],
            'semantic' => ['semantic'],
            'roundtrip' => ['roundtrip'],
        ];
    }

    #[DataProvider('modeProvider')]
    public function testADigitLeadingDivClassOpensAFence(string $mode): void
    {
        $converter = new HtmlToCarve(false, [], false, $mode);

        self::assertSame(
            "::: 2col\ny\n:::",
            trim($converter->convert('<div class="2col"><p>y</p></div>')),
        );
    }

    public function testALetterLeadingDivClassStillOpensAFence(): void
    {
        self::assertSame(
            "::: col2\ny\n:::",
            trim((new HtmlToCarve())->convert('<div class="col2"><p>y</p></div>')),
        );
    }
}
