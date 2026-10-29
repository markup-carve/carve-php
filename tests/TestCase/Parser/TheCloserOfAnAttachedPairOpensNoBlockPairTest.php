<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The closer of a pair a closer walk steps into does not also open a pair
 * that takes a later attribute block, so that block stays text and its
 * closer is reachable (markup-carve/carve-php#4230).
 */
class TheCloserOfAnAttachedPairOpensNoBlockPairTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provider(): array
    {
        return [
            'a combined token with a block' => ["_x /*a*/{.q} b/{c_} c_\n", '<p><u>x <strong class="q"><em>a</em></strong> b/{c</u>} c_</p>'],
            'two blocks on the token' => ["_x /*a*/{.q}{#i} b/{c_} c_\n", '<p><u>x <strong class="q" id="i"><em>a</em></strong> b/{c</u>} c_</p>'],
            'in a quoted item' => ["> - _x /*a*/{.q} b/{c_} c_\n", "<blockquote>\n  <ul>\n    <li><u>x <strong class=\"q\"><em>a</em></strong> b/{c</u>} c_</li>\n  </ul>\n</blockquote>"],
            'a closer in a quoted value' => ["*x /a!/{.q} b/{t=\"*\"} c*\n", '<p><strong>x <em class="q">a!</em> b/{t=“</strong>”} c*</p>'],
            'a highlight pair' => ["_x =a.={t=a}{#i} b={t=\"_\"} c_\n", '<p><u>x <mark t="a" id="i">a.</mark> b={t=“</u>”} c_</p>'],
            'a combined token walk' => ["/*x _a!_{.q} b_{t=\"*/\"} c*/\n", '<p><strong><em>x <u class="q">a!</u> b_{t=“</em></strong>”} c*/</p>'],
        ];
    }

    #[DataProvider('provider')]
    public function testRendersAsTheSpecModel(string $source, string $expected): void
    {
        $this->assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
