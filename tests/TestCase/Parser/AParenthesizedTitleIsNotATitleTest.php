<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `link_title` has a double-quoted spelling and a single-quoted one, and no
 * third.
 *
 * `destTitle = titleSp (quoted | squoted)` in `resources/carve-core.ohm` says
 * the same, so the run after the destination in `[t](/u (T))` fills no slot.
 * The destination then carries a space, which `link_destination` admits
 * nowhere, and the bracket run is literal text
 * (markup-carve/carve-php#2194).
 */
class AParenthesizedTitleIsNotATitleTest extends TestCase
{
    /**
     * Rows the production moves: every one of them built a titled construct.
     *
     * Asserted as the whole rendering. "No `title` attribute" would also pass
     * for a titleless link, which is a different wrong answer.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function literalProvider(): array
    {
        return [
            'a link' => ['[t](/u (T))', "<p>[t](/u (T))</p>\n"],
            'an image' => ['![a](/i (T))', "<p>![a](/i (T))</p>\n"],
            'a quoted run inside the parentheses' => [
                '[t](/u ("T"))',
                "<p>[t](/u (\u{201c}T\u{201d}))</p>\n",
            ],
            // The attribute block belongs to the construct the tail opens, so
            // with no construct it is text as well.
            'a trailing attribute block' => ['[t](/u (T)){.x}', "<p>[t](/u (T)){.x}</p>\n"],
            'an image with a trailing attribute block' => [
                '![a](/i (T)){.x}',
                "<p>![a](/i (T)){.x}</p>\n",
            ],
        ];
    }

    /**
     * Rows that read the same either way, which is what keeps the narrowing
     * pointed at the slot rather than at the parenthesis.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unchangedProvider(): array
    {
        return [
            'a double-quoted title' => ['[t](/u "T")', '<p><a href="/u" title="T">t</a></p>' . "\n"],
            'a single-quoted title' => ["[t](/u 'T')", '<p><a href="/u" title="T">t</a></p>' . "\n"],
            'a parenthesis inside a quoted title' => [
                '[t](/u "T(a)")',
                '<p><a href="/u" title="T(a)">t</a></p>' . "\n",
            ],
            'balanced parentheses in the destination' => [
                '[x](http://a/b(c))',
                '<p><a href="http://a/b(c)">x</a></p>' . "\n",
            ],
            'a destination that is one pair' => ['[t]((a))', '<p><a href="(a)">t</a></p>' . "\n"],
            'an unpaired closer ends the tail' => ['[y](e)f)', '<p><a href="e">y</a>f)</p>' . "\n"],
            // Escaping the parentheses does not reach the slot either: the
            // space before the run still lands inside the destination.
            'escaped parentheses' => ['[t](/u \\(T\\))', "<p>[t](/u (T))</p>\n"],
        ];
    }

    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    #[DataProvider('literalProvider')]
    public function testAParenthesizedRunFillsNoTitleSlot(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->html($source));
    }

    #[DataProvider('unchangedProvider')]
    public function testTheTwoQuotedSpellingsAndTheDestinationAreUntouched(
        string $source,
        string $expected,
    ): void {
        $this->assertSame($expected, $this->html($source));
    }

    public function testEveryRowIsStillCovered(): void
    {
        $this->assertCount(5, self::literalProvider());
        $this->assertCount(7, self::unchangedProvider());
    }
}
