<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * HTML text holds the character the author wrote, so a run that Carve reads as
 * a symbol or an arrow is escaped on import (#2138). #2101 covers the hyphen
 * and dot runs; this is every other token the typography pass converts.
 */
class ASmartSymbolInImportedTextIsEscapedTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function tokens(): array
    {
        return [
            'plus minus' => ['a +- b'],
            'a rightwards arrow' => ['a --> b'],
            'a short rightwards arrow' => ['a -> b'],
            'a leftwards arrow' => ['a <-- b'],
            'a left-right arrow' => ['a <-> b'],
            'not equal' => ['a != b'],
            'less than or equal' => ['a <= b'],
            'greater than or equal' => ['a >= b'],
            'copyright' => ['(c) x'],
            'registered' => ['(r) x'],
            'trademark' => ['(tm) x'],
            'a plus-minus before a dash run' => ['a +-- b'],
            'an arrow with no spaces' => ['a<->b'],
            'a dash run after a plus' => ['a+---'],
        ];
    }

    #[DataProvider('tokens')]
    public function testTheTextReadsBackAsTheHtmlHeldIt(string $text): void
    {
        $imported = (new HtmlToCarve())->convert('<p>' . htmlspecialchars($text, ENT_NOQUOTES) . '</p>');

        $rendered = (new CarveConverter())->convert($imported);
        $this->assertSame(
            '<p>' . htmlspecialchars($text, ENT_NOQUOTES) . "</p>\n",
            $rendered,
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function bounds(): array
    {
        return [
            // Nothing the typography pass reads, so nothing is escaped.
            'a tilde equals' => ['a ~= b', "a ~= b\n"],
            'a fraction slash' => ['1/2 x', "1/2 x\n"],
            'a lone plus' => ['a + b', "a + b\n"],
            // A token inside a code span is verbatim already.
            'a token in a code span' => ['x <code>a --> b</code>', "x `a --> b`\n"],
        ];
    }

    #[DataProvider('bounds')]
    public function testWhatIsLeftAlone(string $html, string $carve): void
    {
        $this->assertSame($carve, (new HtmlToCarve())->convert('<p>' . $html . '</p>'));
    }
}
