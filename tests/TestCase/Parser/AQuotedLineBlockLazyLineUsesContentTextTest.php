<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AQuotedLineBlockLazyLineUsesContentTextTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function cases(): array
    {
        $verse = "<blockquote>\n  <div class=\"line-block\">\n    <p>x<br>\nafter</p>\n  </div>\n</blockquote>";

        return [
            'flush lazy line' => ["> ::: |\n> x\nafter\n", $verse],
            'space before lazy line' => ["> ::: |\n> x\n after\n", $verse],
            'tab before lazy line' => ["> ::: |\n> x\n\tafter\n", $verse],
            'indented quote' => ["  > ::: |\n  > x\n   after\n", $verse],
            'ordinary quoted paragraph keeps its own structure' => [
                ":  definition\n  > > r\n- ![a][r]\n  ^ cap\n",
                "<p>:  definition</p>\n<blockquote>\n  <blockquote><p>r\n- ![a][r]\n^ cap</p></blockquote>\n</blockquote>",
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testLazyLineContent(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
