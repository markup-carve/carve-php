<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UnderlineAroundSocialAtomTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'mention' => ['_@a_b_', '<p><u><span class="mention"><strong>@a_b</strong></span></u></p>'],
            'tag' => ['_#a_b_', '<p><u><span class="tag"><strong>#a_b</strong></span></u></p>'],
            'plain intraword underscore' => ['_a_b_', '<p>_a_b_</p>'],
        ];
    }

    #[DataProvider('cases')]
    public function testSocialNameUnderscoreDoesNotDeclineOuterUnderline(string $input, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($input));
    }
}
