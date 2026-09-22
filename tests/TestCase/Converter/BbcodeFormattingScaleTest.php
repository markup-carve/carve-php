<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\BbcodeToCarve;
use MarkupCarve\Carve\Test\TestCase\ScalingGuardTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The formatting-tag pass is linear in the number of tags, including runs of
 * empty and unclosed ones, which an earlier draft rescanned per tag.
 *
 * It measures that pass alone; `BbcodeListQuoteScaleTest` guards the list
 * and quote passes.
 */
#[Group('scaling')]
class BbcodeFormattingScaleTest extends TestCase
{
    use ScalingGuardTrait;

    /**
     * Small-sample repeats sized so the large sample stays under the input limit.
     *
     * @return array<string, array{string, int}>
     */
    public static function fragmentProvider(): array
    {
        return [
            'closed tags' => ['[b]x[/b] [i][/i]', 3000],
            'empty tags' => ['[b][/b]', 8000],
            'unclosed tags' => ['[b]', 10000],
            'escaped delimiters' => ['*[b]x[/b] ', 5000],
            'constructs the repair escapes' => ['#x [b]y[/b] =z= ', 3000],
        ];
    }

    /**
     * @param string $fragment
     * @param int $smallRepeats
     */
    #[DataProvider('fragmentProvider')]
    public function testTheTagPassScalesLinearly(string $fragment, int $smallRepeats): void
    {
        $bbcode = new class extends BbcodeToCarve {
            public function formatting(string $text): string
            {
                return $this->convertBasicFormatting($text);
            }
        };
        $largeRepeats = $smallRepeats * 4;

        $this->assertConversionScalesLinearly(
            static function (string $input) use ($bbcode): void {
                $bbcode->formatting($input);
            },
            str_repeat($fragment, $smallRepeats),
            str_repeat($fragment, $largeRepeats),
            $fragment,
            $smallRepeats,
            $largeRepeats,
        );
    }
}
