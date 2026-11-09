<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 0 S2: a footnote that misses the deepest description body belongs to the
 * description it reaches, so the lines after it stay there too (carve-php#5057).
 */
class AFootnoteThatMissesADeeperBodyStaysInTheBodyItReachesTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function sources(): array
    {
        return [
            'term after the footnote' => [
                ":: t\n: a\n  :: t\n  : a\n    :: t\n    : a\n     [^n]: a\n    :: u\n",
                '<dd><p>a</p><dl><dt>t</dt><dd>a</dd></dl><dl><dt>u</dt></dl></dd></dl></dd></dl>',
            ],
            'quoted' => [
                "> :: t\n> : a\n>   :: t\n>   : a\n>     :: t\n>     : a\n>      [^n]: a\n>     :: u\n",
                '<dd><p>a</p><dl><dt>t</dt><dd>a</dd></dl><dl><dt>u</dt></dl></dd></dl></dd></dl>',
            ],
        ];
    }

    #[DataProvider('sources')]
    public function testTheFollowerStaysInTheReachedDescription(string $source, string $tail): void
    {
        $html = (string)preg_replace('/>\s+</', '><', (new CarveConverter())->convert($source));

        $this->assertStringContainsString($tail, $html);
    }
}
