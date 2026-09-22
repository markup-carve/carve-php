<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A straight quote curls from the previously rendered character, and `(` is
 * one of the openers `isQuoteOpenContext` lists.
 *
 * The unclosed-link fallback flushes the pending buffer and appends `](` as a
 * node of its own. `previousConvertedChar` read that state as word-adjacent,
 * so the `(` right before the quote was never consulted and `[t]("` closed
 * where `("` opens (markup-carve/carve-php#2199).
 *
 * A flushed run of plain text now flanks as its own last character. A LINK, a
 * code span or an emphasis run still flanks as word context, because those end
 * on a construct rather than on a character, and the rows below hold the two
 * apart.
 */
class AQuoteAfterADecliningLinkTailFlanksAgainstTheParenthesisTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function decliningTailProvider(): array
    {
        return [
            'the shortest form' => ['[t]("', "<p>[t](\u{201c}</p>"],
            'a link tail' => ['[t]("()', "<p>[t](\u{201c}()</p>"],
            'an image tail' => ['![a]("()', "<p>![a](\u{201c}()</p>"],
            // One production, both delimiters.
            'a single quote' => ["[t]('", "<p>[t](\u{2018}</p>"],
            // The run is reached the same way whatever precedes the bracket,
            // and a closer later in the line still pairs with it.
            'text before the bracket run' => ['y [t]("()', "<p>y [t](\u{201c}()</p>"],
            'text after the run' => ['[t]("()x', "<p>[t](\u{201c}()x</p>"],
            'a closer after the opener' => ['[t]("|"', "<p>[t](\u{201c}|\u{201d}</p>"],
            // A declining REFERENCE tail ends on `[`, which is an opener too.
            // It reads the same either way, because that `[` stays in the
            // pending buffer instead of becoming a node; it is here for the
            // shape, not as a witness.
            'a declining reference tail' => ['[t]["', "<p>[t][\u{201c}</p>"],
        ];
    }

    /**
     * Rows that read the same either way. The first is the same quote without
     * the bracket run, which is what says the flanking rule was never the
     * problem; the rest pin the word context the fallback keeps.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unchangedProvider(): array
    {
        return [
            'a bare parenthesis' => ['("(', "<p>(\u{201c}(</p>"],
            'a destination that starts with a quote' => [
                '[t]("a)',
                '<p><a href="&quot;a">t</a></p>',
            ],
            'a destination that is one quote' => ['[t](")', '<p><a href="&quot;">t</a></p>'],
            'after a link' => ['[t](/u)"x', "<p><a href=\"/u\">t</a>\u{201d}x</p>"],
            'after a code span' => ['`c`"x', "<p><code>c</code>\u{201d}x</p>"],
            'after an emphasis run' => ['*b*"x', "<p><strong>b</strong>\u{201d}x</p>"],
            // The escaped-text branch above the new one, unchanged.
            'after an escaped brace' => ['\\{"q"', "<p>{\u{201c}q\u{201d}</p>"],
        ];
    }

    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    #[DataProvider('decliningTailProvider')]
    public function testTheQuoteOpensAgainstTheParenthesis(string $source, string $expected): void
    {
        $this->assertSame($expected, trim($this->html($source)));
    }

    #[DataProvider('unchangedProvider')]
    public function testAConstructStillFlanksAsWordContext(string $source, string $expected): void
    {
        $this->assertSame($expected, trim($this->html($source)));
    }

    public function testEveryRowIsStillCovered(): void
    {
        $this->assertCount(8, self::decliningTailProvider());
        $this->assertCount(7, self::unchangedProvider());
    }
}
