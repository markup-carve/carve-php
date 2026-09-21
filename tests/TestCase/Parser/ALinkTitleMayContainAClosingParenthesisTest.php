<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `link_title` admits any character except its own quote, `)` included.
 *
 * The tail is `'(' link_destination [link_title] ')'`, so the `)` that closes
 * it is the one after the title run. The scan looked for the first `)` with no
 * opener left to pair with and found the one inside the title, which left
 * `[t](/u "T)")` as literal text (markup-carve/carve-php#2191).
 *
 * The destination admits no whitespace, so the quote that opens the slot is the
 * one following a space; a quote reached any other way is a destination
 * character and opens nothing.
 */
class ALinkTitleMayContainAClosingParenthesisTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function titleProvider(): array
    {
        return [
            'a link, double quotes' => ['[t](/u "T)")', '<p><a href="/u" title="T)">t</a></p>' . "\n"],
            'a link, single quotes' => ["[t](/u 'T)')", '<p><a href="/u" title="T)">t</a></p>' . "\n"],
            'an image, double quotes' => ['![a](/i "T)")', '<img src="/i" alt="a" title="T)">' . "\n"],
            'an image, single quotes' => ["![a](/i 'T)')", '<img src="/i" alt="a" title="T)">' . "\n"],
            // The escape rule the title already had still holds inside the run
            // the scan now skips, so the quote it escapes does not end it.
            'an escaped quote before the parenthesis' => [
                '[t](/u "a\\"b)")',
                '<p><a href="/u" title="a&quot;b)">t</a></p>' . "\n",
            ],
            'a trailing attribute block' => [
                '[t](/u "T)"){.x}',
                '<p><a href="/u" title="T)" class="x">t</a></p>' . "\n",
            ],
        ];
    }

    /**
     * Runs the skip must not take, each of which would open a title where the
     * production opens none.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function noTitleProvider(): array
    {
        return [
            // A quote inside the destination is a destination character: the
            // slot is one space wide and this quote does not follow it.
            'a quote inside the destination' => [
                '[t](a"b "c")',
                '<p><a href="a&quot;b" title="c">t</a></p>' . "\n",
            ],
            'an apostrophe inside the destination' => [
                "[t](don't)",
                '<p><a href="don&apos;t">t</a></p>' . "\n",
            ],
            // The tail ends at the `)` inside the destination's quoted run, and
            // what the author wrote after it is text. A skip that fired on any
            // quote would carry the scan past that `)` to the second one and
            // lose the link.
            'a quote in the destination with a later partner' => [
                '[t](a"b)c" d)',
                "<p><a href=\"a&quot;b\">t</a>c\u{201d} d)</p>\n",
            ],
            // PART 7 holds the slot to exactly one space, so a run leaves the
            // quoted text unconsumed and the destination carries a space.
            'two spaces before the quote' => [
                '[t](/u  "T)")',
                "<p>[t](/u  \u{201c}T)\u{201d})</p>\n",
            ],
            'a second quoted run' => [
                '[t](/u "T)" "U")',
                "<p>[t](/u \u{201c}T)\u{201d} \u{201c}U\u{201d})</p>\n",
            ],
            // Nothing closes the run, so the scan may not skip it: the `)` after
            // it is the tail's, and the tail then has no destination.
            'a quote with no partner' => [
                "[t](/u 'x)",
                "<p>[t](/u \u{2018}x)</p>\n",
            ],
            // The run is skipped wherever a space precedes it, inside an open
            // pair included: `balanced_parens` admits no whitespace, so the
            // `(` here can never be part of a destination, and the tail has
            // none. Reading only at depth zero left the `)` inside the quotes
            // closing the tail and handed back the unbalanced href `(`.
            'a quoted run inside an open pair' => [
                '[t](( ")")',
                "<p>[t](( \u{201c})\u{201d})</p>\n",
            ],
            'an image, a quoted run inside an open pair' => [
                "![i](( ')')",
                "<p>![i](( \u{2018})\u{2019})</p>\n",
            ],
        ];
    }

    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    #[DataProvider('titleProvider')]
    public function testTheTitleKeepsItsParenthesis(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->html($source));
    }

    #[DataProvider('noTitleProvider')]
    public function testAQuoteOutsideTheSlotOpensNoTitle(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->html($source));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function roundTripProvider(): array
    {
        return [
            'a link' => ['[t](/u "T)")' . "\n"],
            'an image' => ['![a](/i "T)")' . "\n"],
        ];
    }

    #[DataProvider('roundTripProvider')]
    public function testTheWriterKeepsTheTitle(string $source): void
    {
        $this->assertSame(
            $source,
            CarveConverter::create(renderer: new CarveRenderer())->convert($source),
        );
    }

    public function testEveryRowIsStillCovered(): void
    {
        $this->assertCount(6, self::titleProvider());
        $this->assertCount(8, self::noTitleProvider());
        $this->assertCount(2, self::roundTripProvider());
    }
}
