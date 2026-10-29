<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `{_` with no closer of either reading behind its guarded child's block guards
 * that child, so the block is text and its `_` closes the underline; a later
 * attached block does not change that (markup-carve/carve-php#4228).
 */
class ABracedFallbackGuardClosesInItsChildsBlockTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provider(): array
    {
        return [
            'the bare guard' => ["_*a*{t=\"_\"} /a/{t=a_}\n", '<p><u><strong>a</strong>{t=“</u>”} <em t="a_">a</em></p>'],
            'a braced guard' => ["{_*a*{t=\"_\"} *a*{t=a_}\n", '<p>{<u><strong>a</strong>{t=“</u>”} <strong t="a_">a</strong></p>'],
            'a strike child' => ["{_~a~{t=\"x_\"} =a={t=a_}\n", '<p>{<u><s>a</s>{t=“x</u>”} <mark t="a_">a</mark></p>'],
            'a later span' => ["{_*a*{t=\"_\"} [x]{t=a_}\n", '<p>{<u><strong>a</strong>{t=“</u>”} <span t="a_">x</span></p>'],
            'in a list item' => ["- {_*a*{t=\"_\"} /a/{t=a_}\n", "<ul>\n  <li>{<u><strong>a</strong>{t=“</u>”} <em t=\"a_\">a</em></li>\n</ul>"],
            'a bare closer behind the block' => ["{_*a*{t=\"_\"} /a/{t=a_} b_\n", '<p>{<u><strong t="_">a</strong> <em t="a_">a</em> b</u></p>'],
            'a braced closer behind the block' => ["{_*a*{t=\"_\"} /a/{t=a_} b_}\n", '<p><u><strong t="_">a</strong> <em t="a_">a</em> b</u></p>'],
            'no closer at all' => ["{_*a*{t=a} /a/{t=a_}\n", '<p>{_*a*{t=a} <em t="a_">a</em></p>'],
        ];
    }

    #[DataProvider('provider')]
    public function testRendersAsTheSpecModel(string $source, string $expected): void
    {
        $this->assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
