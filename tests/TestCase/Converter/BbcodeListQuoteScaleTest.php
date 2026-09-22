<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\BbcodeToCarve;
use MarkupCarve\Carve\Test\TestCase\ScalingGuardTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The list and quote passes are linear in the input, including text with no
 * `[` in it, which is what they see once the formatting pass has turned every
 * tag into Carve (markup-carve/carve-php#2214).
 */
#[Group('scaling')]
class BbcodeListQuoteScaleTest extends TestCase
{
    use ScalingGuardTrait;

    /**
     * @return array<string, array{string, string, int}>
     */
    public static function passProvider(): array
    {
        return [
            'list pass, no bracket' => ['lists', '\\*{*x*} ', 6000],
            'list pass, plain words' => ['lists', 'plain words ', 5000],
            'list pass, items' => ['lists', '[list][*]a[*]b[/list] ', 2500],
            'quote pass, no bracket' => ['quotes', '\\*{*x*} ', 6000],
            'quote pass, plain words' => ['quotes', 'plain words ', 5000],
            'quote pass, quotes' => ['quotes', '[quote]a[/quote] ', 3000],
        ];
    }

    /**
     * @param string $pass
     * @param string $fragment
     * @param int $smallRepeats
     */
    #[DataProvider('passProvider')]
    public function testThePassScalesLinearly(string $pass, string $fragment, int $smallRepeats): void
    {
        $bbcode = new class extends BbcodeToCarve {
            public function lists(string $text): string
            {
                return $this->convertLists($text);
            }

            public function quotes(string $text): string
            {
                return $this->convertQuotes($text);
            }
        };
        $largeRepeats = $smallRepeats * 4;

        $this->assertConversionScalesLinearly(
            static function (string $input) use ($bbcode, $pass): void {
                $bbcode->{$pass}($input);
            },
            str_repeat($fragment, $smallRepeats),
            str_repeat($fragment, $largeRepeats),
            $pass . ': ' . $fragment,
            $smallRepeats,
            $largeRepeats,
        );
    }

    public function testTheWholeImportScalesLinearlyOnShortFormattedRuns(): void
    {
        $bbcode = new BbcodeToCarve();
        $fragment = '*[b]x[/b] ';

        $this->assertConversionScalesLinearly(
            static function (string $input) use ($bbcode): void {
                $bbcode->convert($input);
            },
            str_repeat($fragment, 6000),
            str_repeat($fragment, 24000),
            'convert: ' . $fragment,
            6000,
            24000,
        );
    }
}
