<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CodeSpanContinuationMarkerTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function markerLines(): array
    {
        return [
            'bullet shaped' => ["`\n. .`\n"],
            'ordered list shaped' => ["`\n1. x`\n"],
        ];
    }

    #[DataProvider('markerLines')]
    public function testContinuationMarkerIsBare(string $canonical): void
    {
        self::assertSame($canonical, CarveConverter::toCarve($canonical));
        self::assertSame(
            CarveConverter::create()->convert($canonical),
            CarveConverter::create()->convert(CarveConverter::toCarve($canonical)),
        );
    }
}
