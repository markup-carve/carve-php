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
 * A table interrupts no paragraph in GFM, so a closed pipe row under an open
 * paragraph is text of it. Carve opens a table at its container's content
 * column, which split the paragraph in two around a table nobody spelled
 * (carve-php#2359).
 *
 * The direction is what makes this its own defect rather than a duplicate: the
 * two pipe tickets already closed took an escape AWAY (carve-php#2339) and
 * stopped PROMOTING a bare hyphen run to a delimiter row (carve-php#2349), and
 * carve-php#2340 dedented markers Carve declines to read as openers. Here Carve
 * interrupts where GFM does not, so the remedy is the escape rather than a
 * column.
 *
 * PINNED AT THE RENDERED LEVEL, since the converter corpus compares an importer
 * by rendering its output.
 *
 * Measured against cmark-gfm 0.29.0.gfm.13, the reader the importers answer to
 * (carve#2187); commonmark 0.31.2 agrees on every case here.
 */
final class AClosedPipeRowUnderAnOpenParagraphIsTextTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function rowProvider(): array
    {
        return [
            'at the top level' => [
                "foo\n| a | b |\n",
                '<p>foo | a | b |</p>',
            ],
            'in a quote' => [
                "> foo\n> | a | b |\n",
                '<blockquote><p>foo | a | b |</p></blockquote>',
            ],
            'two quotes deep' => [
                "> > foo\n> > | a | b |\n",
                '<blockquote><blockquote><p>foo | a | b |</p></blockquote></blockquote>',
            ],
            'behind a doubled marker' => [
                ">> foo\n>> | a | b |\n",
                '<blockquote><blockquote><p>foo | a | b |</p></blockquote></blockquote>',
            ],
            // At the item's content column, where the row needs no dedent to
            // reach the column Carve opens a block at.
            'at a list item content column' => [
                "- foo\n  | a | b |\n",
                '<ul><li>foo | a | b |</li></ul>',
            ],
            // One column past it, which the importer dedents to the content
            // column - so the dedent is what put the row where a table opens.
            'one column past a list item content column' => [
                "- foo\n   | a | b |\n",
                '<ul><li>foo | a | b |</li></ul>',
            ],
            'in an item a quote holds' => [
                "> - foo\n>   | a | b |\n",
                '<blockquote><ul><li>foo | a | b |</li></ul></blockquote>',
            ],
            'in a nested item' => [
                "- - foo\n    | a | b |\n",
                '<ul><li><ul><li>foo | a | b |</li></ul></li></ul>',
            ],
            // A delimiter row is a closed row too, and under an open paragraph
            // it closes no header, so it is text of the paragraph as well.
            'a delimiter-shaped row' => [
                "foo\n| - | - |\n",
                '<p>foo | - | - |</p>',
            ],
            // The paragraph goes on past the row, which is what shows it was
            // never split rather than merely re-joined.
            'the paragraph continues past the row' => [
                "foo\n| a | b |\nbaz\n",
                '<p>foo | a | b | baz</p>',
            ],
            // Three columns in was already right, the row being left where the
            // source put it; it is here so the two positions stay pinned
            // together.
            'three columns into a quoted paragraph' => [
                "> foo\n>    | a | b |\n",
                '<blockquote><p>foo | a | b |</p></blockquote>',
            ],
        ];
    }

    #[DataProvider('rowProvider')]
    public function testTheRowStaysParagraphText(string $markdown, string $html): void
    {
        // Element AND text: a table that swallowed the row would leave the same
        // characters on the page, so an absence assertion proves nothing here.
        $this->assertSame($html, $this->render($markdown));
        $this->assertSame($html, $this->render($markdown, SmartTypographyMode::Source));
    }

    /**
     * A row that SHOULD open a table still opens one. Without these a fix that
     * simply never builds a table would pass every case above.
     *
     * @return array<string, array{string, string}>
     */
    public static function controlsProvider(): array
    {
        return [
            'a header and delimiter row still make a table' => [
                "| a | b |\n| - | - |\n| c | d |\n",
                '<table><thead><tr><th scope="col">a</th><th scope="col">b</th></tr></thead>'
                    . '<tbody><tr><td>c</td><td>d</td></tr></tbody></table>',
            ],
            // No paragraph is open after the blank, so nothing is interrupted
            // and the table is the source's own.
            'a table after a blank line still opens' => [
                "foo\n\n| a | b |\n| - | - |\n",
                '<p>foo</p><table><thead><tr><th scope="col">a</th>'
                    . '<th scope="col">b</th></tr></thead></table>',
            ],
            'a table after a blank line inside an item still opens' => [
                "- foo\n\n  | a | b |\n  | - | - |\n",
                '<ul><li><p>foo</p><table><thead><tr><th scope="col">a</th>'
                    . '<th scope="col">b</th></tr></thead></table></li></ul>',
            ],
            // A table CAN follow a heading, so the escape must not reach there.
            'a table under a heading still opens' => [
                "# foo\n| a | b |\n| - | - |\n",
                '<h1>foo</h1><table><thead><tr><th scope="col">a</th>'
                    . '<th scope="col">b</th></tr></thead></table>',
            ],
            // carve-php#2339 ruled that an open row is no table and so needs no
            // escape. It must keep none: the escape added here is for a CLOSED
            // row only.
            'an open pipe row keeps no escape' => [
                "foo\n| bar\n",
                '<p>foo | bar</p>',
            ],
            'an open pipe row in a quote keeps no escape' => [
                "> foo\n> | bar\n",
                '<blockquote><p>foo | bar</p></blockquote>',
            ],
            // A delimiter row answering the row above makes the two a table,
            // and cmark-gfm's table extension DOES interrupt a paragraph when
            // one answers. The quoted path has no header branch of its own, so
            // both rows reach Carve as they were spelled and Carve reads the GFM
            // separator as an alias for its own.
            'a quoted table under an open paragraph keeps its pipes' => [
                "> foo\n> | a | b |\n> | - | - |\n",
                '<blockquote><p>foo</p><table><thead><tr><th scope="col">a</th>'
                    . '<th scope="col">b</th></tr></thead></table></blockquote>',
            ],
            'a quoted table an item holds keeps its pipes' => [
                "- > | a | b |\n  > | - | - |\n",
                '<ul><li><blockquote><table><thead><tr><th scope="col">a</th>'
                    . '<th scope="col">b</th></tr></thead></table></blockquote></li></ul>',
            ],
            'a quoted table under a heading keeps its pipes' => [
                "- > # foo\n  > | a | b |\n  > | - | - |\n",
                '<ul><li><blockquote><h1>foo</h1><table><thead><tr><th scope="col">a</th>'
                    . '<th scope="col">b</th></tr></thead></table></blockquote></li></ul>',
            ],
        ];
    }

    /**
     * Where the escape lands and where it does not, asserted on the Carve the
     * importer WRITES rather than on the render.
     *
     * A decorative escape is invisible once rendered, which is why
     * carve-php#2339's check was the source and not the HTML: with these
     * assertions at the rendered level only, widening the row test from a closed
     * row to any leading pipe passes every case.
     *
     * @return array<string, array{string, string}>
     */
    public static function sourceProvider(): array
    {
        return [
            'a closed row under an open paragraph is escaped' => [
                "> - foo\n>   | a | b |\n",
                "> - foo\n>   \\| a | b |\n",
            ],
            'a second closed row is escaped too' => [
                "foo\n| a | b |\n| c | d |\n",
                "foo\n\\| a | b |\n\\| c | d |\n",
            ],
            'an open row keeps no escape' => [
                "foo\n| bar\n",
                "foo\n| bar\n",
            ],
            'a quoted table keeps both rows bare' => [
                "> foo\n> | a | b |\n> | - | - |\n",
                "> foo\n> | a | b |\n> | - | - |\n",
            ],
            // The header carries no outer pipes, so only the delimiter row
            // reaches the escape - and it is the half that answers the line
            // above rather than the one a line below answers.
            'a delimiter row answering a pipeless header keeps its pipes' => [
                "> a | b\n> | - | - |\n",
                "> a | b\n> | - | - |\n",
            ],
            // No paragraph is open here and the row still answers no delimiter,
            // so it is still prose: cmark-gfm reads `<p>foo</p><p>| a | b |</p>`.
            // This case read the other way while the escape was gated on an open
            // paragraph, which is what carve-php#2365 corrected.
            'a row after a blank takes the escape too' => [
                "foo\n\n| a | b |\n",
                "foo\n\n\\| a | b |\n",
            ],
            // A body row answers no delimiter and is answered by none, so what
            // keeps it bare is the table already under way at its column. It is
            // the control for that test: without it the row is escaped and the
            // table loses its body.
            'a quoted table body row keeps its pipes' => [
                "> | a | b |\n> | - | - |\n> | c | d |\n",
                "> | a | b |\n> | - | - |\n> | c | d |\n",
            ],
            'a quoted table body row after a paragraph and a blank keeps its pipes' => [
                "> foo\n>\n> | a | b |\n> | - | - |\n> | c | d |\n",
                "> foo\n>\n> | a | b |\n> | - | - |\n> | c | d |\n",
            ],
        ];
    }

    #[DataProvider('sourceProvider')]
    public function testWhereTheEscapeLands(string $markdown, string $carve): void
    {
        $this->assertSame($carve, (new MarkdownToCarve())->convert($markdown));
    }

    #[DataProvider('controlsProvider')]
    public function testWhatAlreadyHeldStillHolds(string $markdown, string $html): void
    {
        $this->assertSame($html, $this->render($markdown));
        $this->assertSame($html, $this->render($markdown, SmartTypographyMode::Source));
    }

    /**
     * The escape the importer writes is what `carve fmt` writes, so an imported
     * document is already formatted. carve-php#2339's check was `fmt` for the
     * same reason.
     */
    public function testTheEscapedRowIsWhatTheFormatterWrites(): void
    {
        $carve = (new MarkdownToCarve())->convert("> - foo\n>   | a | b |\n");

        $this->assertSame("> - foo\n>   \\| a | b |\n", $carve);
        $this->assertSame($carve, CarveConverter::toCarve($carve));
    }

    private function render(string $markdown, ?SmartTypographyMode $typography = null): string
    {
        $carve = (new MarkdownToCarve())->convert($markdown);
        $converter = new CarveConverter();
        $renderer = $converter->getRenderer();
        if ($typography !== null && $renderer instanceof HtmlRenderer) {
            $renderer->setSmartTypography($typography);
        }
        $html = $converter->convert($carve);

        return trim((string)preg_replace(
            ['/\s+id="[^"]*"/', '/<\/?section>/', '/>\s+</', '/\s+/'],
            ['', '', '><', ' '],
            $html,
        ));
    }
}
