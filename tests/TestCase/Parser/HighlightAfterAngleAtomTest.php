<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HighlightAfterAngleAtomTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'url' => ['=<https://x>=', '<p><mark><a href="https://x">https://x</a></mark></p>'],
            'cross reference' => ['=</#d>=', '<p><mark>&lt;/#d&gt;</mark></p>'],
            'comparison' => ['a >= b', '<p>a ≥ b</p>'],
        ];
    }

    #[DataProvider('cases')]
    public function testAngleAtomLeavesTheFollowingHighlightCloser(string $input, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($input));
    }
}
