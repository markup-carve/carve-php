<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MissedVerseSpanQuoteOwnershipTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function sources(): array
    {
        return [
            'nested empty quote' => [
                "> > ::: |\n> > %%%\n> > \nb\n",
                '<blockquote><blockquote><div class="line-block"></div></blockquote></blockquote><p>b</p>',
            ],
            'nested quote after stanza break' => [
                "> > ::: |\n> > x\n> > \n> > %%%\n> > hidden\nb\n",
                '<blockquote><blockquote><div class="line-block"><p>x</p></div></blockquote></blockquote><p>b</p>',
            ],
            'item quote' => [
                "- > ::: |\n  > %%%\n  > hidden\nb\n",
                '<ul><li><blockquote><div class="line-block"></div></blockquote></li></ul><p>b</p>',
            ],
            'item quote after stanza break' => [
                "- > ::: |\n  > x\n  > \n  > %%%\n  > hidden\nb\n",
                '<ul><li><blockquote><div class="line-block"><p>x</p></div></blockquote></li></ul><p>b</p>',
            ],
            'description still owns the follower' => [
                ":: t\n: > ::: |\n  > %%%\n  > hidden\nb\n",
                '<dl><dt>t</dt><dd><blockquote><div class="line-block"></div></blockquote><p>b</p></dd></dl>',
            ],
        ];
    }

    #[DataProvider('sources')]
    public function testMissedFollowerHasTheModelOwner(string $source, string $expected): void
    {
        $html = CarveConverter::create()->convert($source);

        self::assertSame($expected, trim((string)preg_replace('/>\s+</', '><', $html)));
    }
}
