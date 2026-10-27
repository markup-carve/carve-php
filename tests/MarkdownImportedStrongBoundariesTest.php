<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkdownImportedStrongBoundariesTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'nested' => ['<strong>**x**</strong>', '<p><strong><strong>x</strong></strong></p>' . "\n"],
            'nested with text' => ['<strong>a **x** b</strong>', '<p><strong>a <strong>x</strong> b</strong></p>' . "\n"],
            'adjacent' => ['**a**<strong>b</strong>', '<p><strong>a</strong><strong>b</strong></p>' . "\n"],
            'b tag' => ['**a**<b>b</b>', '<p><strong>a</strong><strong>b</strong></p>' . "\n"],
            'escaped stars' => ['<strong>\\*x\\*</strong>', '<p><strong>*x*</strong></p>' . "\n"],
        ];
    }

    #[DataProvider('cases')]
    public function testStrongBoundariesSurviveImport(string $markdown, string $expectedHtml): void
    {
        $carve = (new MarkdownToCarve())->convert($markdown);

        self::assertSame($expectedHtml, CarveConverter::create()->convert($carve));
    }
}
