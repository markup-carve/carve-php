<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TermBelowColumnCommentBreakTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function terms(): array
    {
        return [
            'terminal comment' => ["- :: a\n %%\n", "<ul>\n  <li>\n    <dl>\n      <dt>a\n</dt>\n    </dl>\n  </li>\n</ul>"],
            'continued term' => ["- :: a\n %%\n  b\n", "<ul>\n  <li>\n    <dl>\n      <dt>a\n\nb</dt>\n    </dl>\n  </li>\n</ul>"],
        ];
    }

    #[DataProvider('terms')]
    public function testCommentPreservesTermBreaks(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
