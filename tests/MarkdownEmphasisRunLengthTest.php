<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MarkdownEmphasisRunLengthTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function provideRuns(): array
    {
        return [
            'rule 9 measures the whole run' => ["a***b* c*\n", "a\\*{/{/b/} c/}\n", '<p>a*<em><em>b</em> c</em></p>'],
            'literal star inside strong' => ["**!*!**\n", "{*!*!*}\n", '<p><strong>!*!</strong></p>'],
            'literal star inside underscore strong' => ["__!*!__\n", "{*!*!*}\n", '<p><strong>!*!</strong></p>'],
            'star between letters inside strong' => ["**a*b**\n", "{*a*b*}\n", '<p><strong>a*b</strong></p>'],
            'nested emphasis inside strong' => ["**!*!*!**\n", "*!/!/!*\n", '<p><strong>!<em>!</em>!</strong></p>'],
        ];
    }

    #[DataProvider('provideRuns')]
    public function testImport(string $markdown, string $carve, string $html): void
    {
        self::assertSame($carve, (new MarkdownToCarve())->convert($markdown));
        self::assertSame($html, trim((new CarveConverter())->convert($carve)));
    }
}
