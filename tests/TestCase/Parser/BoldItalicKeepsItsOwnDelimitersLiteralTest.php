<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BoldItalicKeepsItsOwnDelimitersLiteralTest extends TestCase
{
    public static function cases(): array
    {
        return [
            ['/*a *b* c*/ x'],
            ['/*a /b/ c*/ x'],
            ['/*/x/*/ y'],
            ['/*a *b* /c/ d*/ x'],
        ];
    }

    #[DataProvider('cases')]
    public function testCombinedSpanKeepsBareBoldAndItalicPairsLiteral(string $source): void
    {
        $html = (new CarveConverter())->convert($source);
        $this->assertStringNotContainsString('<strong>b</strong>', $html);
        $this->assertStringNotContainsString('<em>b</em>', $html);
        $this->assertStringContainsString('<strong><em>', $html);
    }
}
