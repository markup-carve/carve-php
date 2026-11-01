<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ANestedMarkerCommentClosesAtFirstExactRunTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function examples(): iterable
    {
        yield 'first closer at column zero' => [". - %%%\n%%%\nx\n%%%\n"];
        yield 'four percent signs and a shifted closer' => [". - %%%%\n %%%%\nx\n%%%%\n"];
        yield 'no second closer' => [". - %%%\n%%%\nx\n"];
    }

    #[DataProvider('examples')]
    public function testFirstExactWidthCloserEndsTheComment(string $source): void
    {
        $expected = "<ol>\n  <li>\n    <ul>\n      <li></li>\n    </ul>\n  </li>\n</ol>\n<p>x</p>";

        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
