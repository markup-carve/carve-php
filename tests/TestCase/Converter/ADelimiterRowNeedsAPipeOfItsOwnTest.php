<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A GFM delimiter row needs a pipe of its own. Without one, a bare `---` under a
 * one-cell header row counted as a row of one cell, so a lone pipe line became a
 * table the source never spelled and the setext underline below it lost its
 * heading (carve-php#2349).
 *
 * cmark-gfm takes the underline: `| bar` then `---` is `<h2>| bar</h2>`, not a
 * table. It reads `| bar |` and `| --- |` as one, so the pipe is what decides and
 * not the cell count.
 *
 * PINNED AT THE RENDERED LEVEL, since the converter corpus compares an importer
 * by rendering its output. Three things went wrong at once here and only the
 * render shows all of them: a table appeared, the heading was lost, and the
 * paragraph was split.
 *
 * Measured against cmark-gfm 0.29.0.gfm.13 (carve#2187) and commonmark 0.31.2,
 * which agree on every case.
 */
final class ADelimiterRowNeedsAPipeOfItsOwnTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function headingsProvider(): array
    {
        return [
            'three columns into a paragraph' => [
                "foo\n   | bar\n---\n",
                '<section><h2>foo | bar</h2></section>',
            ],
            'at the paragraph column' => [
                "foo\n| bar\n---\n",
                '<section><h2>foo | bar</h2></section>',
            ],
            'an equals underline gives h1' => [
                "foo\n| bar\n===\n",
                '<section><h1>foo | bar</h1></section>',
            ],
            'with no paragraph above it' => [
                "| bar\n---\n",
                '<section><h2>| bar</h2></section>',
            ],
            'a closed row above it' => [
                "| bar |\n---\n",
                '<section><h2>| bar |</h2></section>',
            ],
            'inside a list item' => [
                "- foo\n     | bar\n  ---\n",
                '<ul><li><h2>foo | bar</h2></li></ul>',
            ],
            'inside a quote' => [
                "> foo\n>    | bar\n> ---\n",
                '<blockquote><h2>foo | bar</h2></blockquote>',
            ],
            'inside a quote in a quote' => [
                "> > foo\n> >    | bar\n> > ---\n",
                '<blockquote><blockquote><h2>foo | bar</h2></blockquote></blockquote>',
            ],
        ];
    }

    #[DataProvider('headingsProvider')]
    public function testTheUnderlineKeepsItsHeading(string $markdown, string $html): void
    {
        $this->assertSame($html, $this->render($markdown));
    }

    /**
     * What held before the change and holds after it. Every row here carries a
     * pipe in its delimiter, so it is still a table, which is what shows the
     * condition is the pipe and not the table path as a whole.
     *
     * @return array<string, array{string, string}>
     */
    public static function tablesProvider(): array
    {
        return [
            'a one-cell table' => [
                "| bar |\n| --- |\n",
                '<table><thead><tr><th scope="col">bar</th></tr></thead></table>',
            ],
            'a two-cell table' => [
                "| a | b |\n| - | - |\n",
                '<table><thead><tr><th scope="col">a</th><th scope="col">b</th></tr></thead></table>',
            ],
            'a table with no outer pipes' => [
                "a | b\n--- | ---\n",
                '<table><thead><tr><th scope="col">a</th><th scope="col">b</th></tr></thead></table>',
            ],
            'a delimiter row with no spaces' => [
                "a | b\n---|---\n",
                '<table><thead><tr><th scope="col">a</th><th scope="col">b</th></tr></thead></table>',
            ],
            'a table with a body row' => [
                "| a | b |\n| - | - |\n| 1 | 2 |\n",
                '<table><thead><tr><th scope="col">a</th><th scope="col">b</th></tr></thead>'
                    . '<tbody><tr><td>1</td><td>2</td></tr></tbody></table>',
            ],
            'an aligned delimiter row' => [
                "| a | b |\n|:-:|--:|\n",
                '<table><thead><tr><th scope="col" style="text-align: center;">a</th>'
                    . '<th scope="col" style="text-align: right;">b</th></tr></thead></table>',
            ],
        ];
    }

    #[DataProvider('tablesProvider')]
    public function testARowWithAPipeIsStillATable(string $markdown, string $html): void
    {
        $this->assertSame($html, $this->render($markdown));
    }

    /**
     * Two more that pass either side: a header whose cell count never matched
     * the bare underline was already a heading, and the quoted fold reads its
     * own delimiter row and still refuses when one is answerable.
     *
     * @return array<string, array{string, string}>
     */
    public static function controlsProvider(): array
    {
        return [
            'a two-cell header over a bare underline' => [
                "| a | b |\n---\n",
                '<section><h2>| a | b |</h2></section>',
            ],
            'a pipe in the paragraph text' => [
                "foo | bar\n---\n",
                '<section><h2>foo | bar</h2></section>',
            ],
            'a quoted fold over a delimiter row four columns in' => [
                "> a | b\n>     | - | - |\n> ---\n",
                '<blockquote><h2>a | b | - | - |</h2></blockquote>',
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
