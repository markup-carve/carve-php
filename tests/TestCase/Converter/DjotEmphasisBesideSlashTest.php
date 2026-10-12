<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DjotEmphasisBesideSlashTest extends TestCase
{
    #[DataProvider('sourceProvider')]
    public function testEmphasisSurvivesLiteralSlash(string $source, string $expected, string $html): void
    {
        $carve = (new DjotToCarve())->convert($source . "\n");
        self::assertSame($expected . "\n", $carve);
        self::assertSame($html, trim((new CarveConverter())->convert($carve)));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function sourceProvider(): array
    {
        return [
            'before' => ['/_e_', '/{/e/}', '<p>/<em>e</em></p>'],
            'both sides' => ['a/_e_/b', 'a/{/e/}/b', '<p>a/<em>e</em>/b</p>'],
            'after' => ['_e_/', '{/e/}/', '<p><em>e</em>/</p>'],
            'alone' => ['_e_', '/e/', '<p><em>e</em></p>'],
            'slash pair' => ['/_e_/', '\/{/e/}/', '<p>/<em>e</em>/</p>'],
            'escaped slash' => ['\/_e_', '\/{/e/}', '<p>/<em>e</em></p>'],
        ];
    }
}
