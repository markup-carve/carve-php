<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A bare pair whose opener a span of another kind crosses (PART 9 E2), or that
 * a braced marker of an open kind cannot guard (E2a, E3), does not take its
 * attribute block, so a closer in the block stays reachable. Expected HTML is
 * the spec model's.
 */
class ACrossedOrNestedPairLeavesItsBlockTextTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provider(): array
    {
        return [
            'a crossed pair' => ["_/*a//*{t=_} b_\n", '<p><u><em>*a</em>/*{t=</u>} b_</p>'],
            'a crossed highlight' => ["_/=//={t=_} b_\n", '<p><u><em>=</em>/<mark>{t</mark></u>} b_</p>'],
            'a quoted value' => ["a b _/*a//*{t=\"__\"} b_\n", '<p>a b <u><em>*a</em>/*{t=“</u>_”} b_</p>'],
            'a demoted delimiter closes nothing' => ["_a /*/*{c_}\n", '<p><u>a <em>*</em>*{c</u>}</p>'],
            'a braced marker under an open bare one' => ["_z {_~a_b~{c_} a_ b\n", '<p><u>z <u><s>a_b</s>{c</u> a</u> b</p>'],
            'a closer of the marker inside the pair' => ["a {_*=_=*{c__} c_ d/\n", '<p>a <u><strong><mark>_</mark></strong>{c_</u> c_ d/</p>'],
            'a braced closer after the block keeps the pair' => ["/{_*=_=*{t=_} _}\n", '<p>/<u><strong t="_"><mark>_</mark></strong> </u></p>'],
            'a bare one that never pairs holds nothing open' => ["_{_*~*{c_} _}\n", '<p>_<u><strong c_="">~</strong> </u></p>'],
            'a braced marker guard stands' => ["{_~a~{c_}\n", '<p>{_<s c_="">a</s></p>'],
            'the second marker of an empty group opens nothing' => ["_{**}*{c_}\n", '<p><u>{**}*{c</u>}</p>'],
            'an empty italic group before a pair' => ["_z {//}/{t=_}\n", '<p><u>z {//}/{t=</u>}</p>'],
        ];
    }

    #[DataProvider('provider')]
    public function testRendersAsTheSpecModel(string $source, string $expected): void
    {
        $this->assertSame($expected, trim(CarveConverter::create()->convert($source)));
    }
}
