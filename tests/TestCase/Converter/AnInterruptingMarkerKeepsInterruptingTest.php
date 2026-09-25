<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A marker that interrupts the paragraph a quote holds has to go on interrupting
 * it. Carve interrupts for fewer shapes than GFM does, so a marker the source
 * left within three columns was folded back into the paragraph it ended and the
 * imported document said something else (carve-php#2340).
 *
 * Two shapes of remedy, because Carve declines for two different reasons:
 *
 * - a heading or a quote marker opens a block only AT its container's content
 *   column, so it is written there
 * - a list never opens from under a paragraph at all, so the paragraph is closed
 *   with an empty quote line first
 *
 * Both are meaning-preserving: the source's own reading is what they reproduce,
 * and neither changes a list's looseness, since the empty line lands outside the
 * list rather than inside it.
 *
 * PINNED AT THE RENDERED LEVEL, since the converter corpus compares an importer
 * by rendering its output, and the Carve spelling of a preserved interruption is
 * not the source's.
 *
 * Measured against cmark-gfm 0.29.0.gfm.13, the reader the importers answer to
 * (carve#2187), and commonmark 0.31.2. They agree on every case here.
 */
final class AnInterruptingMarkerKeepsInterruptingTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function dedentedProvider(): array
    {
        return [
            'a heading marker three columns in' => [
                "> foo\n>    # bar\n> ---\n",
                '<blockquote><p>foo</p><h1>bar</h1><hr></blockquote>',
            ],
            'a heading marker one column in' => [
                "> foo\n>  # bar\n",
                '<blockquote><p>foo</p><h1>bar</h1></blockquote>',
            ],
            'a deeper heading marker' => [
                "> foo\n>    ### bar\n",
                '<blockquote><p>foo</p><h3>bar</h3></blockquote>',
            ],
            'a quote marker three columns in' => [
                "> foo\n>    > bar\n",
                '<blockquote><p>foo</p><blockquote><p>bar</p></blockquote></blockquote>',
            ],
            'inside a quote' => [
                "> > foo\n> >    # bar\n",
                '<blockquote><blockquote><p>foo</p><h1>bar</h1></blockquote></blockquote>',
            ],
            'under a doubled marker' => [
                ">> foo\n>>    # bar\n",
                '<blockquote><blockquote><p>foo</p><h1>bar</h1></blockquote></blockquote>',
            ],
        ];
    }

    #[DataProvider('dedentedProvider')]
    public function testTheMarkerReachesItsContainerColumn(string $markdown, string $html): void
    {
        $this->assertSame($html, $this->render($markdown));
    }

    /**
     * A list needs the paragraph closed for it, at column 0 as much as at three:
     * Carve opens no list from under a paragraph at any column, so both are the
     * same defect and both are here.
     *
     * @return array<string, array{string, string}>
     */
    public static function partedProvider(): array
    {
        return [
            'a bullet three columns in' => [
                "> foo\n>    - bar\n> ---\n",
                '<blockquote><p>foo</p><ul><li>bar</li></ul><hr></blockquote>',
            ],
            'a bullet at the paragraph column' => [
                "> foo\n> - bar\n> ---\n",
                '<blockquote><p>foo</p><ul><li>bar</li></ul><hr></blockquote>',
            ],
            'a star bullet' => [
                "> foo\n>    * bar\n",
                '<blockquote><p>foo</p><ul><li>bar</li></ul></blockquote>',
            ],
            'a plus bullet' => [
                "> foo\n>    + bar\n",
                '<blockquote><p>foo</p><ul><li>bar</li></ul></blockquote>',
            ],
            'an ordered marker starting at 1' => [
                "> foo\n>    1. bar\n",
                '<blockquote><p>foo</p><ol><li>bar</li></ol></blockquote>',
            ],
            'a paren ordered marker starting at 1' => [
                "> foo\n>    1) bar\n",
                '<blockquote><p>foo</p><ol><li>bar</li></ol></blockquote>',
            ],
            'inside a quote' => [
                "> > foo\n> >    - bar\n",
                '<blockquote><blockquote><p>foo</p><ul><li>bar</li></ul></blockquote></blockquote>',
            ],
            'two items keep one tight list' => [
                "> foo\n>    - a\n>    - b\n",
                '<blockquote><p>foo</p><ul><li>a</li><li>b</li></ul></blockquote>',
            ],
        ];
    }

    #[DataProvider('partedProvider')]
    public function testTheListGetsTheParagraphClosedForIt(string $markdown, string $html): void
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
            // Only `1.` interrupts among the ordered markers (CommonMark 5.2),
            // so closing the paragraph for a `2.` would invent a list. This is
            // the control that caught the first attempt doing exactly that.
            'an ordered marker not starting at 1 stays text' => [
                "> foo\n>    2. bar\n",
                '<blockquote><p>foo 2. bar</p></blockquote>',
            ],
            'a two-digit ordered marker stays text' => [
                "> foo\n>    10. bar\n",
                '<blockquote><p>foo 10. bar</p></blockquote>',
            ],
            // Four columns in nothing interrupts, so the underline folds the
            // paragraph into one heading instead.
            'four columns in still folds' => [
                "> foo\n>     # bar\n> ---\n",
                '<blockquote><h2>foo # bar</h2></blockquote>',
            ],
            'four columns in with no underline stays text' => [
                "> foo\n>     # bar\n",
                '<blockquote><p>foo # bar</p></blockquote>',
            ],
            // A list already open is not a paragraph, and Carve opens a nested
            // list from under an item's text, so nothing is parted there.
            'a list stays tight' => [
                "> - a\n> - b\n",
                '<blockquote><ul><li>a</li><li>b</li></ul></blockquote>',
            ],
            'a nested list under an item paragraph' => [
                "> - a\n>   b\n>   - c\n",
                '<blockquote><ul><li>a b <ul><li>c</li></ul></li></ul></blockquote>',
            ],
            'a held ordered marker under an item stays text' => [
                "> - a\n>   2. b\n",
                '<blockquote><ul><li>a 2. b</li></ul></blockquote>',
            ],
            'a source blank keeps its loose list' => [
                "> - a\n>\n> - b\n",
                '<blockquote><ul><li><p>a</p></li><li><p>b</p></li></ul></blockquote>',
            ],
            'a plain quoted paragraph is untouched' => [
                "> foo\n> bar\n",
                '<blockquote><p>foo bar</p></blockquote>',
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
