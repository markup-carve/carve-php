<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ALineAfterAShiftedAttachedFenceCloserLeavesTheItemTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function examples(): iterable
    {
        foreach (['-', '*'] as $marker) {
            foreach (['```', '~~~'] as $fence) {
                yield "$marker with $fence" => [
                    "$marker +\n$fence\n\n $fence\n  t\n",
                    "<ul>\n  <li>\n    <pre><code>\n</code></pre>\n  </li>\n</ul>\n<p>t</p>",
                ];
            }
        }
    }

    #[DataProvider('examples')]
    public function testLineAfterCloserBelongsToDocument(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
