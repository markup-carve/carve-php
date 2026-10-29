<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An attribute block hides a closer only when the pair that takes it forms in
 * the reader's scope; otherwise it is text (markup-carve/carve-php#4229).
 */
class AnAttributeBlockOfAPairThatCannotFormHoldsTheCloserTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provider(): array
    {
        return [
            'a same-kind guard in an underline span' => ["{_x _*a*{t=a_} b_}\n", '<p><u>x _*a*{t=a</u> b_}</p>'],
            'a guard closing past the span' => ["{/x _*a*{t=a/} [a_] /} e_\n", '<p><em>x _<strong t="a/">a</strong> [a_] </em> e_</p>'],
            'a same-kind guard in a strong span' => ["{*x _*a*{t=a*} b*}\n", '<p><strong>x _*a*{t=a</strong> b*}</p>'],
            'an outer guard of the span kind' => ["{/x /_=a={t=\"/}\"} a_ b/}\n", '<p><em>x /_=a={t=“</em>”} a_ b/}</p>'],
            'an outer guard with no closer' => ["{*x /_=a={t=\"*}\"} a_ b*}\n", '<p><strong>x /<u><mark t="*}">a</mark> a</u> b</strong></p>'],
            'a guard closer behind its host block' => ["=x _*a*{t=a_} a_{t=\"=\"} b=\n", '<p><mark>x <u t="="><strong t="a_">a</strong> a</u> b</mark></p>'],
            'two guard levels' => ["{=x _/*a*{t=a_} /_a_{t=\"=}\"} a/b=}\n", '<p><mark>x <u t="=}">/<strong t="a_">a</strong> /_a</u> a/b</mark></p>'],
            'an unguarded host block' => ["{=x _*a*{t=a_} /_a_{t=\"=}\"} a/b=}\n", '<p><mark>x <u t="=}"><strong t="a_">a</strong> /_a</u> a/b</mark></p>'],
            'an outer guard of the walk kind' => ["/a {/x /_=a={t=\"/}\"} a_ _/}\n", '<p><em>a <em>x /_=a={t=“</em>”} a_ _</em>}</p>'],
            'a guard that closes before the span closer' => ["{*x _a_{t=a*} b*}\n", '<p><strong>x <u t="a*">a</u> b</strong></p>'],
            'no span closer behind the block' => ["{=x _/*a*{t=a_} /_a_{t=\"=}\"} a/\n", '<p>{=x <u t="=}">/<strong t="a_">a</strong> /_a</u> a/</p>'],
        ];
    }

    #[DataProvider('provider')]
    public function testRendersAsTheSpecModel(string $source, string $expected): void
    {
        $this->assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
