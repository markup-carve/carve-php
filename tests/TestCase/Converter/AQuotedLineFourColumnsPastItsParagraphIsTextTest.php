<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A quoted line four columns past the column its open paragraph's content starts
 * at is continuation text, whatever shape it takes: indented code cannot
 * interrupt a paragraph, so nothing opens there.
 *
 * The quoted path decided by SHAPE alone, so a heading, a break, a bullet or a
 * quote marker at that column became a block of its own (carve-php#2348), and
 * the bullet normalization reached a plus that was never a marker and replaced
 * it with a hyphen in prose (carve-php#2350).
 *
 * The column is the one the paragraph's content starts at, which inside a quote
 * is the held ITEM's content column and not the quote's. `> - foo` opens its
 * paragraph at column 2, so four columns in is column 6 - which is why a marker
 * at column 4 there still opens a heading.
 *
 * PINNED AT THE RENDERED LEVEL, since the converter corpus compares an importer
 * by rendering its output.
 *
 * Measured against cmark-gfm 0.29.0.gfm.13, the reader the importers answer to
 * (carve#2187), and commonmark 0.31.2; the two agree on every case here.
 */
final class AQuotedLineFourColumnsPastItsParagraphIsTextTest extends TestCase
{
    /**
     * Every paragraph-interrupting shape, swept rather than sampled: each opens
     * a block within three columns and none does at four.
     *
     * @return array<string, array{string, string}>
     */
    public static function heldItemProvider(): array
    {
        return [
            'a heading marker' => ["> - foo\n>       # bar\n", 'foo # bar'],
            'a star run' => ["> - foo\n>       ***\n", 'foo ***'],
            'an underscore run' => ["> - foo\n>       ___\n", 'foo ___'],
            'a bullet' => ["> - foo\n>       - bar\n", 'foo - bar'],
            'a star bullet' => ["> - foo\n>       * bar\n", 'foo * bar'],
            'a plus bullet' => ["> - foo\n>       + bar\n", 'foo + bar'],
            'a quote marker' => ["> - foo\n>       > bar\n", 'foo &gt; bar'],
            'an ordered marker' => ["> - foo\n>       1. bar\n", 'foo 1. bar'],
            'a link definition' => ["> - foo\n>       [a]: /u\n", 'foo [a]: /u'],
        ];
    }

    #[DataProvider('heldItemProvider')]
    public function testTheHeldLineStaysTextOfTheItemParagraph(string $markdown, string $text): void
    {
        $html = $this->render($markdown);

        // Element AND text: a shape that opens no element can still have been
        // swallowed, and an absence assertion cannot tell the two apart.
        $this->assertSame('<blockquote><ul><li>' . $text . '</li></ul></blockquote>', $html);
    }

    /**
     * Two quotes deep, which is what shows the reference is the item's content
     * column inside whatever container holds it rather than one fixed depth.
     */
    public function testTheHeldLineStaysTextTwoQuotesDeep(): void
    {
        $this->assertSame(
            '<blockquote><blockquote><ul><li>foo # bar</li></ul></blockquote></blockquote>',
            $this->render("> > - foo\n> >       # bar\n"),
        );
    }

    /**
     * The plus is the one bullet spelling Carve does not take, so it is the one
     * the normalization rewrites - and on a continuation line that rewrite lands
     * in prose a reader sees.
     *
     * @return array<string, array{string, string}>
     */
    public static function plusProvider(): array
    {
        return [
            'four columns into a quoted paragraph' => [
                "> foo\n>     + bar\n",
                '<blockquote><p>foo + bar</p></blockquote>',
            ],
            'four columns into a doubly quoted paragraph' => [
                "> > foo\n> >     + bar\n",
                '<blockquote><blockquote><p>foo + bar</p></blockquote></blockquote>',
            ],
            'four columns into a doubled-marker paragraph' => [
                ">> foo\n>>     + bar\n",
                '<blockquote><blockquote><p>foo + bar</p></blockquote></blockquote>',
            ],
        ];
    }

    #[DataProvider('plusProvider')]
    public function testThePlusKeepsItsCharacter(string $markdown, string $html): void
    {
        $this->assertSame($html, $this->render($markdown));
    }

    /**
     * What held before the change and holds after it.
     *
     * @return array<string, array{string, string}>
     */
    public static function controlsProvider(): array
    {
        return [
            // Three columns past the ITEM's content column, which is column 5
            // inside the quote: the marker still opens its heading. Measured on
            // both oracles, which read the heading there too.
            'three columns past the item content column still opens a block' => [
                "> - foo\n>      # bar\n",
                '<blockquote><ul><li>foo <h1>bar</h1></li></ul></blockquote>',
            ],
            // Four columns past the QUOTE but only two past the item, which is
            // what shows the reference is the item rather than the quote.
            'four columns past the quote but not the item still opens a block' => [
                "> - foo\n>     # bar\n",
                '<blockquote><ul><li>foo <h1>bar</h1></li></ul></blockquote>',
            ],
            // A plus that IS a marker still takes Carve's spelling.
            'a real plus list still becomes a hyphen list' => [
                "> + a\n> + b\n",
                '<blockquote><ul><li>a</li><li>b</li></ul></blockquote>',
            ],
            'a plain quoted paragraph is untouched' => [
                "> foo\n> bar\n",
                '<blockquote><p>foo bar</p></blockquote>',
            ],
            // The quoted setext fold reads the same four columns and still folds.
            'a quoted fold still folds' => [
                "> foo\n>     # bar\n> ---\n",
                '<blockquote><h2>foo # bar</h2></blockquote>',
            ],
            'an item-held quoted fold still folds' => [
                "- > foo\n  >     # bar\n  > ---\n",
                '<ul><li><blockquote><h2>foo # bar</h2></blockquote></li></ul>',
            ],
            // A plain item at the top level always escaped this position.
            'a plain item at the top level still escapes it' => [
                "- foo\n      # bar\n",
                '<ul><li>foo # bar</li></ul>',
            ],
        ];
    }

    #[DataProvider('controlsProvider')]
    public function testWhatAlreadyHeldStillHolds(string $markdown, string $html): void
    {
        $this->assertSame($html, $this->render($markdown));
    }

    private function render(string $markdown): string
    {
        $carve = (new MarkdownToCarve())->convert($markdown);
        $html = (new CarveConverter())->convert($carve);

        return trim((string)preg_replace(['/\s+id="[^"]*"/', '/>\s+</', '/\s+/'], ['', '><', ' '], $html));
    }
}
