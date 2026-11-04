<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A quote whose children are all hoisted out writes only `>`, and a `+` before it is refused,
 * so two quotes in an item are parted by a blank line instead (carve-php#4470).
 */
class AQuoteWithNoContentTakesNoPlusTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provider(): array
    {
        return [
            'footnote-only quote after a quote (carve-php#4470)' => [
                "- y\n\n  > x\n  > %% #region a\n\n  > [^g]: b\n",
                "- y\n  > x\n  > %% #region a\n\n  >\n\n[^g]: b\n",
            ],
            'empty quote after a quote' => [
                "- y\n\n  > x\n\n  >\n",
                "- y\n\n  > x\n\n  >\n",
            ],
            'empty quote before a quote' => [
                "- y\n  >\n\n  > x\n",
                "- y\n\n  >\n\n  > x\n",
            ],
        ];
    }

    #[DataProvider('provider')]
    public function testTheQuotesArePartedByABlankLine(string $source, string $expected): void
    {
        $formatted = CarveConverter::toCarve($source);

        $this->assertSame($expected, $formatted);
        $this->assertSame($formatted, CarveConverter::toCarve($formatted));
        $this->assertSame(CarveConverter::create()->convert($source), CarveConverter::create()->convert($formatted));
    }
}
