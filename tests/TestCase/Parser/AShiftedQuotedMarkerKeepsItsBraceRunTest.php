<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AShiftedQuotedMarkerKeepsItsBraceRunTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'bullet shifted one column' => [" - > {% a\n   >  b %}\n", 'ul'],
            'bullet shifted two columns' => ["  - > {% a\n    >  b %}\n", 'ul'],
            'ordered item shifted one column' => [" 1. > {% a\n     >  b %}\n", 'ol'],
            'star item shifted one column' => [" * > {% a\n   >  b %}\n", 'ul'],
        ];
    }

    #[DataProvider('cases')]
    public function testCommentRunStaysHidden(string $source, string $list): void
    {
        self::assertSame(
            "<$list>\n  <li>\n    <blockquote>\n\n    </blockquote>\n  </li>\n</$list>",
            trim((new CarveConverter())->convert($source)),
        );
    }
}
