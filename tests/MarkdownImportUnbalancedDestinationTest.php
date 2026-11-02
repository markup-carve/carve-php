<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A bare destination with unbalanced unescaped parentheses is no link reference
 * definition (CommonMark 0.31.2 §4.7, §6.3; markup-carve/carve#4922).
 */
class MarkdownImportUnbalancedDestinationTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function provideImports(): array
    {
        return [
            'lone opener' => ["[x]: (\n\n[x]\n", "[x]: (\n\n[x]\n", "<p>[x]: (</p>\n<p>[x]</p>"],
            'opener inside the destination' => ["[x]: a(b\n\n[x]\n", "[x]: a(b\n\n[x]\n", "<p>[x]: a(b</p>\n<p>[x]</p>"],
            'one pair short in a quote' => [
                "> [x]: a((b)\n>\n> [x]\n",
                "> [x]: a((b)\n>\n> [x]\n",
                "<blockquote>\n  <p>[x]: a((b)</p>\n  <p>[x]</p>\n</blockquote>",
            ],
            'balanced pair stays a definition' => ["[x]: a(b)\n\n[x]\n", "[x][]\n\n[x]: a(b)\n", '<p><a href="a(b)">x</a></p>'],
            'escaped opener stays a definition' => ["[x]: a\\(b\n\n[x]\n", "[x][]\n\n[x]: a\\(b\n", '<p><a href="a(b">x</a></p>'],
        ];
    }

    #[DataProvider('provideImports')]
    public function testImport(string $markdown, string $carve, string $html): void
    {
        self::assertSame($carve, (new MarkdownToCarve())->convert($markdown));
        self::assertSame($html, trim((new CarveConverter())->convert($carve)));
    }
}
