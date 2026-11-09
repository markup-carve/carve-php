<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AListAfterAShiftedAttachedFenceCloserStartsNewListTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function examples(): iterable
    {
        foreach (['-', '*', '1.'] as $host) {
            foreach (['```', '~~~'] as $fence) {
                $tag = $host === '1.' ? 'ol' : 'ul';

                yield "$host and $fence" => [
                    "$host +\n$fence\n\n $fence\n  - x\n",
                    "<$tag>\n  <li>\n    <pre><code>\n</code></pre>\n  </li>\n</$tag>\n<ul>\n  <li>x</li>\n</ul>",
                ];
            }
        }
    }

    #[DataProvider('examples')]
    public function testListStartsOutsideItem(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
