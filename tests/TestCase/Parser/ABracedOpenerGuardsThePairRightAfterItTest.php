<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A bare pair right after a braced `{_` (or `{/` before `/` and `_`) is guarded
 * by it: with no closer behind the pair's block, the block is text and the
 * closer at its end closes the braced span (markup-carve/carve-php#4235).
 */
class ABracedOpenerGuardsThePairRightAfterItTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provider(): array
    {
        return [
            'a strike host' => ["{_~b~{c_}\n", '<p>{_<s c_="">b</s></p>'],
            'an italic host' => ["{c_}x{_/==~/{c_}\n", '<p>{c_}x{_<em c_="">==~</em></p>'],
            'a block after the closer' => ["{_~b~{c_}{.q} x\n", '<p>{_<s c_="" class="q">b</s> x</p>'],
            'in a quote' => ["> {_~b~{t=a_}\n", '<blockquote><p>{_<s t="a_">b</s></p></blockquote>'],
            'a closer behind the block' => ["{_~b~{c_} x_}\n", '<p><u><s c_="">b</s> x</u></p>'],
            'an unguarded host' => ["{_x ~b~{c_}\n", '<p>{_x <s c_="">b</s></p>'],
            'a bare closer behind the block' => ["{c_} c_{_/a/{c_}{c/}{_   b\n", '<p>{c_} c_{<u><em c_="">a</em>{c/}{</u>   b</p>'],
            'a closer in a quoted value' => ["{_~b~{t=\"_}\"_}\n", '<p><u><s>b</s>{t=“</u>”_}</p>'],
        ];
    }

    #[DataProvider('provider')]
    public function testRendersAsTheSpecModel(string $source, string $expected): void
    {
        $this->assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
