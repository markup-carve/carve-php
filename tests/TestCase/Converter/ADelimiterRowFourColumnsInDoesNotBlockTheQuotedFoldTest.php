<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A delimiter row four columns into the paragraph a quote holds is continuation
 * text, so it closes no header and the underline below it still makes one
 * heading of the paragraph. The fold refused it instead and the underline stayed
 * a rule (carve-php#2342).
 *
 * The guard tested how far the line ABOVE sat past the content column while the
 * question was where the ROW sits. Both now have to be answerable as a table for
 * the pair to be one.
 *
 * PINNED AT THE RENDERED LEVEL: the converter corpus compares an importer by
 * rendering its output, and what was wrong is what a reader sees.
 *
 * Measured against cmark-gfm 0.29.0.gfm.13, the reader the importers answer to
 * (carve#2187), and against carve-js `d8ca1cd`, which folds these already.
 */
final class ADelimiterRowFourColumnsInDoesNotBlockTheQuotedFoldTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function foldsProvider(): array
    {
        return [
            'at the top level' => [
                "> a | b\n>     | - | - |\n> ---\n",
                '<blockquote><h2>a | b | - | - |</h2></blockquote>',
            ],
            'an equals underline gives h1' => [
                "> a | b\n>     | - | - |\n> ===\n",
                '<blockquote><h1>a | b | - | - |</h1></blockquote>',
            ],
            'inside a list item' => [
                "- > a | b\n  >     | - | - |\n  > ---\n",
                '<ul><li><blockquote><h2>a | b | - | - |</h2></blockquote></li></ul>',
            ],
            'inside a quote' => [
                "> > a | b\n> >     | - | - |\n> > ---\n",
                '<blockquote><blockquote><h2>a | b | - | - |</h2></blockquote></blockquote>',
            ],
            'two items deep' => [
                "- - > a | b\n    >     | - | - |\n    > ---\n",
                '<ul><li><ul><li><blockquote><h2>a | b | - | - |</h2></blockquote></li></ul></li></ul>',
            ],
            'a delimiter row with alignment markers' => [
                "> a | b\n>     |:-:|--:|\n> ---\n",
                '<blockquote><h2>a | b |:-:|--:|</h2></blockquote>',
            ],
        ];
    }

    #[DataProvider('foldsProvider')]
    public function testTheRowFoldsIntoTheQuotedHeading(string $markdown, string $html): void
    {
        $this->assertSame($html, $this->render($markdown));
    }

    /**
     * What held before the change and holds after it. A delimiter row WHERE a
     * table can form still blocks the fold, which is what shows the guard was
     * narrowed rather than removed.
     *
     * @return array<string, array{string, string}>
     */
    public static function controlsProvider(): array
    {
        return [
            // The row at the quote's content column closes the header above it,
            // so there is no paragraph left for the underline to end.
            'a delimiter row at the content column still blocks it' => [
                "> a | b\n> | - | - |\n> ---\n",
                '<blockquote><p>a | b</p><table><tbody><tr><td>-</td><td>-</td></tr></tbody></table><hr></blockquote>',
            ],
            // A row four columns in under a line that is NOT a header row was
            // always continuation text; this is the case carve-php#2333 folded.
            'a pipe row under plain text still folds' => [
                "- > foo\n  >     | bar\n  > ---\n",
                '<ul><li><blockquote><h2>foo | bar</h2></blockquote></li></ul>',
            ],
            'a heading marker four columns in still folds' => [
                "> foo\n>     # bar\n> ---\n",
                '<blockquote><h2>foo # bar</h2></blockquote>',
            ],
            'an equals line four columns in stays text' => [
                "- > foo\n  >     bar\n  >     ===\n",
                '<ul><li><blockquote><p>foo bar ===</p></blockquote></li></ul>',
            ],
        ];
    }

    #[DataProvider('controlsProvider')]
    public function testWhatAlreadyHeldStillHolds(string $markdown, string $html): void
    {
        $this->assertSame($html, $this->render($markdown));
    }

    /**
     * Three columns is not four, and the guard still reaches it.
     *
     * Asserted as the ABSENCE of the fold rather than as the whole rendering:
     * what this engine does with a delimiter row three columns in is itself
     * wrong - cmark-gfm builds the table there, and this engine reads the row
     * as paragraph text - and pinning the current HTML would bake that in.
     */
    public function testTheFoldDoesNotReachThreeColumns(): void
    {
        $this->assertStringNotContainsString('<h2>', $this->render("> a | b\n>    | - | - |\n> ---\n"));
        $this->assertStringNotContainsString('<h1>', $this->render("> a | b\n>    | - | - |\n> ===\n"));
    }

    private function render(string $markdown): string
    {
        $carve = (new MarkdownToCarve())->convert($markdown);
        $html = (new CarveConverter())->convert($carve);

        return trim((string)preg_replace(['/\s+id="[^"]*"/', '/>\s+</', '/\s+/'], ['', '><', ' '], $html));
    }
}
