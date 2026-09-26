<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ALineInitialCodePayloadKeepsItsPaddingTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function sourceProvider(): array
    {
        return [
            'after code' => ["`z` ``\n`\n"],
            'after text' => ["before ``\n`\n"],
            'longer run' => ["before ```\n``\n"],
            'following text' => ["before `` `x` `` after\n"],
            'attributes' => ["before `` `x` ``{.code}\n"],
        ];
    }

    #[DataProvider('sourceProvider')]
    public function testFormattingPreservesTheCodeValue(string $source): void
    {
        $formatted = CarveConverter::toCarve($source);
        $html = CarveConverter::create();
        $this->assertSame($html->convert($source), $html->convert($formatted));
        $this->assertSame($formatted, CarveConverter::toCarve($formatted));
        $this->assertDoesNotMatchRegularExpression('/ +$/m', $formatted);
    }
}
