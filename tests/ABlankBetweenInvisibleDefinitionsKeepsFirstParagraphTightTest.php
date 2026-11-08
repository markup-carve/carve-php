<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ABlankBetweenInvisibleDefinitionsKeepsFirstParagraphTightTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'two notes before first visible block' => [
            "- [^n]: d0\n\n  [^m]: x\n\n  flush\n\nsee[^n][^m]\n",
            "<ul>\n  <li>flush</li>\n</ul>",
        ];

        yield 'visible lead remains loose' => [
            "- lead\n\n  [^m]: x\n\n  flush\n\nsee[^m]\n",
            "<ul>\n  <li><p>lead</p>\n    <p>flush</p>\n  </li>\n</ul>",
        ];
    }

    #[DataProvider('cases')]
    public function testListPart(string $source, string $expected): void
    {
        self::assertStringStartsWith($expected, trim((new CarveConverter())->convert($source)));
    }
}
