<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ABracedOpenerReadsPastAnAttachedCombinedSpanTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function readings(): array
    {
        return [
            'a combined span takes its block' => [
                '{~/*/x/*/{t="~"}',
                '<p>{~<strong t="~"><em>/x/</em></strong></p>',
            ],
            'the closer of a formed pair opens no pair of its own' => [
                '{=_*_*_{c==}}',
                '<p><mark><u>*</u>*_{c=</mark>}</p>',
            ],
            'a closer behind the block leaves the block attached' => [
                '{*/*x*/{t=**} z*}',
                '<p><strong><strong t="**"><em>x</em></strong> z</strong></p>',
            ],
            'with no closer behind it the block is text' => [
                '{*/*a b*/{t="*}"}',
                '<p><strong><strong><em>a b</em></strong>{t=“</strong>”}</p>',
            ],
            'a host holding the slash leaves its block text' => [
                '{/p ~/x/~{t="/}"}',
                '<p><em>p <s>/x/</s>{t=“</em>”}</p>',
            ],
        ];
    }

    #[DataProvider('readings')]
    public function testReading(string $source, string $html): void
    {
        $this->assertSame($html, (new CarveConverter())->convert($source));
    }
}
