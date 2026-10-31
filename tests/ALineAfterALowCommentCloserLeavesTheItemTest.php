<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ALineAfterALowCommentCloserLeavesTheItemTest extends TestCase
{
    #[DataProvider('cases')]
    public function testTheSpanChangesNoOwnership(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        $item = "<ul>\n  <li>a</li>\n</ul>\n<p>x</p>";
        $nested = "<ul>\n  <li>a\n    <ul>\n      <li></li>\n    </ul>\n  </li>\n</ul>\n<p>{ - x</p>";
        $stays = "<ul>\n  <li>a\n    x\n  </li>\n</ul>";

        return [
            'closer at column 0' => ["- a\n  %%%\n  hidden\n%%%\n x\n", $item],
            'closer at column 1' => ["- a\n  %%%\n  hidden\n %%%\nx\n", $item],
            'empty span' => ["- a\n  %%%\n%%%\n x\n", $item],
            'wider fence' => ["- a\n  %%%%\n  hidden\n%%%%\n x\n", $item],
            'nested item' => ["- a\n  - %%%\n    hidden\n%%%\n { - x\n", $nested],
            'follower at the content column stays' => ["- a\n  %%%\n  hidden\n%%%\n  x\n", $stays],
        ];
    }
}
