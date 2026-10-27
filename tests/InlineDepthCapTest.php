<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InlineDepthCapTest extends TestCase
{
    /**
     * @return array<string, array{int}>
     */
    public static function supportedDepths(): array
    {
        return [
            'first formerly rejected level' => [101],
            'shared cap' => [200],
        ];
    }

    #[DataProvider('supportedDepths')]
    public function testNestedLinksReachTheSharedCapWithoutStackFailure(int $depth): void
    {
        $source = str_repeat('[', $depth) . 'x' . str_repeat('](#)', $depth);

        self::assertSame('<p><a href="#">x</a></p>', CarveConverter::create()->convert($source));
    }

    public function testLevelPastTheSharedCapFallsBackToLiteralContent(): void
    {
        $source = str_repeat('[', 201) . 'x' . str_repeat('](#)', 201);

        self::assertSame('<p><a href="#">[x](#)</a></p>', CarveConverter::create()->convert($source));
    }
}
