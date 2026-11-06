<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AttributedBracketSpanDepthTest extends TestCase
{
    /**
     * @return array<string, array{int, int}>
     */
    public static function depths(): array
    {
        return [
            'below cap' => [199, 199],
            'at cap' => [200, 199],
            'past cap' => [201, 199],
        ];
    }

    #[DataProvider('depths')]
    public function testAttributedBracketsShareTheInlineCap(int $depth, int $expectedSpans): void
    {
        $source = str_repeat('[', $depth) . 'x' . str_repeat(']{.a}', $depth);
        $html = CarveConverter::create()->convert($source);
        $this->assertSame($expectedSpans, substr_count($html, '<span class="a">'));
        $this->assertSame($depth > 199, str_contains($html, '['));
    }
}
