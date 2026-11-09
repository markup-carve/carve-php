<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ARefusedBraceRunDescriptionBoundaryTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function examples(): iterable
    {
        foreach (['{.x' => '.y}', '{#a' => '.b}'] as $opener => $continuation) {
            yield $opener => [
                ":: t\n: +\n  $opener\n  $continuation\n  ```\n  wa\n> q\n",
                "<dl>\n  <dt>t</dt>\n  <dd>$opener\n" . ltrim($continuation, ' ') . "\n<code>\nwa</code></dd>\n</dl>\n<blockquote><p>q</p></blockquote>",
            ];
        }
    }

    #[DataProvider('examples')]
    public function testUnclosedSpanEndsAtDescriptionBoundary(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
