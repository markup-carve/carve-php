<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\SmartTypographyMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A closed pipe row that answers no delimiter row is no table in GFM, so it is
 * prose wherever it stands. carve-php#2359 escaped it only where the line above
 * left a paragraph OPEN, which left every other position reading as a table
 * Carve opens at its container's content column: under a heading, under a
 * thematic break, under a closed fence, after a blank line, and at the very start
 * of the document (carve-php#2365).
 *
 * WHAT REPLACES THE PARAGRAPH GATE. That gate was protecting one case by
 * accident: a table BODY row answers no delimiter and is answered by none, so it
 * looks exactly like a lone row to both of the pair tests. `gfmTableIsUnderWay`
 * asks the question the body row is actually told apart by - is a table already
 * running at this column - by walking back over the run of rows above for the
 * pair that opened it. `a quoted table body row keeps its pipes` and its
 * paragraph-fed twin in `AClosedPipeRowUnderAnOpenParagraphIsTextTest` are the
 * controls; without the walk-back they lose their pipes.
 *
 * AND THE ROW HAS TO SIT AT ITS CONTAINER'S CONTENT COLUMN. Carve opens a table
 * there and nowhere else, so an indented row is already a paragraph and a
 * backslash on it changes nothing. Measured on a 5390-case matrix: the gate
 * without that test writes 755 escapes that matter and 339 that do not, and with
 * it writes the 755 alone - and removes 252 the old code was writing on indented
 * rows, which is the decorative escape carve-php#2339 spent a fix removing.
 *
 * PINNED AT THE RENDERED LEVEL, since the converter corpus compares an importer
 * by rendering its output.
 *
 * Measured against cmark-gfm 0.29.0.gfm.13, the reader the importers answer to
 * (markup-carve/carve#2187).
 */
final class AClosedPipeRowWithNoParagraphAboveIsTextTest extends TestCase
{
    /**
     * Every position where nothing above leaves a paragraph open.
     *
     * @return array<string, array{string, string}>
     */
    public static function noParagraphProvider(): array
    {
        return [
            // The ticket's case.
            'a row after a blank line' => [
                "foo\n\n| a | b |\n",
                '<p>foo</p><p>| a | b |</p>',
            ],
            'a row alone in the document' => [
                "| a | b |\n",
                '<p>| a | b |</p>',
            ],
            'a row under a heading' => [
                "# foo\n| a | b |\n",
                '<h1>foo</h1><p>| a | b |</p>',
            ],
            'a row under a thematic break' => [
                "***\n| a | b |\n",
                '<hr><p>| a | b |</p>',
            ],
            'a row under a closed fence' => [
                "~~~\nx\n~~~\n| a | b |\n",
                '<pre><code>x </code></pre><p>| a | b |</p>',
            ],
            'two rows with no delimiter between them' => [
                "| a | b |\n| c | d |\n",
                '<p>| a | b | | c | d |</p>',
            ],
            'a one-cell row' => [
                "|x|\n",
                '<p>|x|</p>',
            ],
            // The quoted twin of each. A quote's content column is where Carve
            // opens the table, so the row reads as one there too.
            'a quoted row alone' => [
                "> | a | b |\n",
                '<blockquote><p>| a | b |</p></blockquote>',
            ],
            'a quoted row after a quoted blank' => [
                "> foo\n>\n> | a | b |\n",
                '<blockquote><p>foo</p><p>| a | b |</p></blockquote>',
            ],
            'a quoted row under a quoted heading' => [
                "> # foo\n> | a | b |\n",
                '<blockquote><h1>foo</h1><p>| a | b |</p></blockquote>',
            ],
            'a doubly quoted row alone' => [
                "> > | a | b |\n",
                '<blockquote><blockquote><p>| a | b |</p></blockquote></blockquote>',
            ],
        ];
    }

    #[DataProvider('noParagraphProvider')]
    public function testTheRowStaysProse(string $markdown, string $html): void
    {
        // Element AND text: a table holding the same cell contents reads as if
        // nothing went wrong, so an absence assertion proves nothing here.
        $this->assertSame($html, $this->render($markdown));
        $this->assertSame($html, $this->render($markdown, SmartTypographyMode::Source));
    }

    /**
     * A real table still is one. Without these, escaping every closed row passes
     * the provider above.
     *
     * @return array<string, array{string, string}>
     */
    public static function tableProvider(): array
    {
        return [
            'a header answered by a delimiter row' => [
                "| a | b |\n| - | - |\n",
                '<table><thead><tr><th>a</th><th>b</th></tr></thead></table>',
            ],
            'a header, a delimiter and a body row' => [
                "| a | b |\n| - | - |\n| c | d |\n",
                '<table><thead><tr><th>a</th><th>b</th></tr></thead><tbody><tr><td>c</td><td>d</td></tr></tbody></table>',
            ],
            // The body row two lines past the pair that opened the table. This is
            // the case the walk-back exists for - the pair is not the line above.
            'a second body row' => [
                "| a | b |\n| - | - |\n| c | d |\n| e | f |\n",
                '<table><thead><tr><th>a</th><th>b</th></tr></thead><tbody><tr><td>c</td><td>d</td></tr>'
                    . '<tr><td>e</td><td>f</td></tr></tbody></table>',
            ],
            'a quoted table' => [
                "> | a | b |\n> | - | - |\n",
                '<blockquote><table><thead><tr><th>a</th><th>b</th></tr></thead></table></blockquote>',
            ],
            'a quoted table with two body rows' => [
                "> | a | b |\n> | - | - |\n> | c | d |\n> | e | f |\n",
                '<blockquote><table><thead><tr><th>a</th><th>b</th></tr></thead><tbody><tr><td>c</td><td>d</td></tr>'
                    . '<tr><td>e</td><td>f</td></tr></tbody></table></blockquote>',
            ],
            'a quoted table under a quoted heading' => [
                "> # foo\n> | a | b |\n> | - | - |\n> | c | d |\n",
                '<blockquote><h1>foo</h1><table><thead><tr><th>a</th><th>b</th></tr></thead>'
                    . '<tbody><tr><td>c</td><td>d</td></tr></tbody></table></blockquote>',
            ],
            'a table after a blank' => [
                "foo\n\n| a | b |\n| - | - |\n| c | d |\n",
                '<p>foo</p><table><thead><tr><th>a</th><th>b</th></tr></thead>'
                    . '<tbody><tr><td>c</td><td>d</td></tr></tbody></table>',
            ],
        ];
    }

    #[DataProvider('tableProvider')]
    public function testARealTableKeepsItsPipes(string $markdown, string $html): void
    {
        $this->assertSame($html, $this->render($markdown));
        $this->assertSame($html, $this->render($markdown, SmartTypographyMode::Source));
    }

    /**
     * Where the escape lands, asserted on the Carve the importer WRITES.
     *
     * A decorative escape is invisible once rendered, so the render assertions
     * cannot tell a gate that reads the row's column from one that does not.
     * These can.
     *
     * @return array<string, array{string, string}>
     */
    public static function sourceProvider(): array
    {
        return [
            'a row at column 0 takes the escape' => [
                "| a | b |\n",
                "\\| a | b |\n",
            ],
            'a row after a blank takes it' => [
                "foo\n\n| a | b |\n",
                "foo\n\n\\| a | b |\n",
            ],
            'a quoted row at the quote content column takes it' => [
                "> | a | b |\n",
                "> \\| a | b |\n",
            ],
            // Carve opens a table only AT the content column, so one column in
            // the row is a paragraph already and a backslash guards nothing. The
            // indentation surviving the import is a separate gap, carve-php#2384:
            // `fmt` dedents such a line and then DOES escape it, so these three
            // are what the importer writes today rather than what it owes.
            'a row one column in takes none' => [
                " | a | b |\n",
                " | a | b |\n",
            ],
            'a row two columns in takes none' => [
                "  | a | b |\n",
                "  | a | b |\n",
            ],
            'a row three columns in takes none' => [
                "   | a | b |\n",
                "   | a | b |\n",
            ],
            'a quoted row indented inside its quote takes none' => [
                "> foo\n>\n>   | a | b |\n",
                "> foo\n>\n>   | a | b |\n",
            ],
            // A row at the item's own content column DOES open a table there, so
            // it still takes one.
            'a row at an item content column takes it' => [
                "- foo\n\n  | a | b |\n",
                "- foo\n\n  \\| a | b |\n",
            ],
            'a header and its delimiter take none' => [
                "| a | b |\n| - | - |\n",
                "|= a |= b |\n",
            ],
            'a body row of a table under way takes none' => [
                "> | a | b |\n> | - | - |\n> | c | d |\n",
                "> | a | b |\n> | - | - |\n> | c | d |\n",
            ],
        ];
    }

    #[DataProvider('sourceProvider')]
    public function testWhereTheEscapeLands(string $markdown, string $carve): void
    {
        $this->assertSame($carve, (new MarkdownToCarve())->convert($markdown));
    }

    /**
     * Removing the escape changes the parse, which is what makes it not
     * decorative. Asserted by rendering the same Carve with and without it.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function escapeEarnsItsKeepProvider(): array
    {
        return [
            'a lone row' => ['| a | b |', '\| a | b |', '<p>| a | b |</p>'],
            'a quoted lone row' => ['> | a | b |', '> \| a | b |', '<blockquote><p>| a | b |</p></blockquote>'],
            'a one-cell row' => ['|x|', '\|x|', '<p>|x|</p>'],
        ];
    }

    #[DataProvider('escapeEarnsItsKeepProvider')]
    public function testTheEscapeChangesTheParse(string $bare, string $escaped, string $html): void
    {
        $converter = new CarveConverter();

        $this->assertStringContainsString('<table', $converter->convert($bare));
        $this->assertSame($html, $this->squash($converter->convert($escaped)));
    }

    /**
     * The escape the importer writes is what `carve fmt` writes, so an imported
     * document is already formatted.
     */
    public function testTheEscapedRowIsWhatTheFormatterWrites(): void
    {
        // A thematic break is left out: the importer keeps no blank line under
        // one and `fmt` writes it, for a paragraph of any shape. That gap is
        // older than this change and is not about the pipe row (carve-php#2385).
        foreach (["| a | b |\n", "# foo\n| a | b |\n", "> | a | b |\n", "foo\n\n| a | b |\n"] as $markdown) {
            $carve = (new MarkdownToCarve())->convert($markdown);

            $this->assertSame($carve, CarveConverter::toCarve($carve), $markdown);
        }
    }

    private function render(string $markdown, ?SmartTypographyMode $typography = null): string
    {
        $carve = (new MarkdownToCarve())->convert($markdown);
        $converter = new CarveConverter();
        $renderer = $converter->getRenderer();
        if ($typography !== null && $renderer instanceof HtmlRenderer) {
            $renderer->setSmartTypography($typography);
        }

        return $this->squash($converter->convert($carve));
    }

    private function squash(string $html): string
    {
        return trim((string)preg_replace(
            ['/\s+id="[^"]*"/', '/\s+scope="[^"]*"/', '/<\/?section>/', '/>\s+</', '/\s+/'],
            ['', '', '', '><', ' '],
            $html,
        ));
    }
}
