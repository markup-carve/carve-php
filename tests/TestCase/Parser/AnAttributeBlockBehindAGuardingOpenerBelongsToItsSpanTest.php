<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `_` or `/` that opens a span that closes is not the character before the
 * next opener (the note under CARVE-P3-013), so the span behind it holds its
 * attribute block for the outer closer walk (markup-carve/carve-php#4222).
 */
class AnAttributeBlockBehindAGuardingOpenerBelongsToItsSpanTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provider(): array
    {
        return [
            'underline behind italic' => ["/_a_{title=\"/\"} b/\n", '<p><em><u title="/">a</u> b</em></p>'],
            'strong behind underline' => ["_*a*{title=\"_\"} b_\n", '<p><u><strong title="_">a</strong> b</u></p>'],
            'italic behind underline' => ["_/a/{title=\"_\"} b_\n", '<p><u><em title="_">a</em> b</u></p>'],
            'a guard inside the walk' => ["*/_a_{title=\"*/\"} b/ c*\n", '<p><strong><em><u title="*/">a</u> b</em> c</strong></p>'],
            'two guards' => ["/_*a*{title=\"/_\"} b_ c/\n", '<p><em><u><strong title="/_">a</strong> b</u> c</em></p>'],
            'a guard after a space' => ["/x _*a*{title=\"/\"} b_ c/\n", '<p><em>x <u><strong title="/">a</strong> b</u> c</em></p>'],
            'an escaped backslash' => ["\\\\/_a_{title=\"/\"} b/\n", '<p>\\<em><u title="/">a</u> b</em></p>'],
            // The guard closes only inside the block, so the block is not the span's.
            'no closer behind the block' => ["/_a_{title=\"/\"} b /\n", '<p><em><u>a</u>{title=“</em>”} b /</p>'],
            'no closer in the braced scope' => ["{=_*b*{title=\"_\"} x=}\n", '<p><mark><u><strong>b</strong>{title=“</u>”} x</mark></p>'],
            'a guard closing past the walk' => ["*_~a~{title=\"*_\"} c* d_\n", '<p><strong>_~a~{title=“</strong><u>“} c* d</u></p>'],
            'guards closing past the walk in a row' => [
                "*_~a~{title=\"*_\"} _~a~{title=\"*_\"} _~a~{title=\"*_\"} z*\n",
                '<p><strong>_~a~{title=“</strong><u>“} _~a~{title=“*</u>”} <u><s>a</s>{title=“*</u>”} z*</p>',
            ],
            'an escaped guard' => ["\\/_a_{title=\"/\"} b/\n", '<p>/_a_{title=“<em>“} b</em></p>'],
        ];
    }

    #[DataProvider('provider')]
    public function testTheSpanHoldsItsBlock(string $source, string $expected): void
    {
        $this->assertSame($expected, trim(CarveConverter::create()->convert($source)));
    }
}
