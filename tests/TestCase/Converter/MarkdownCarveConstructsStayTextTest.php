<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The stays-text escapes ruled on markup-carve/carve#1130 (the
 * carve-js#1060 class): CommonMark plus GFM is the conversion contract, so a
 * construct only Carve or another Markdown flavour spells must reach the
 * migrated document as the LITERAL TEXT a CommonMark reader shows - not as
 * live Carve markup the author never wrote.
 *
 * Pinned by render, the way the converter corpus pins it: source to Carve,
 * Carve to HTML, and the visible result must equal what `marked` in GFM mode
 * renders for the same source. The corpus cases 01-06 in
 * tests/corpus-convert/ carry the same rulings; this file adds the flag
 * opt-ins the corpus (which runs converters at their defaults) cannot reach.
 */
class MarkdownCarveConstructsStayTextTest extends TestCase
{
    protected function render(string $markdown, ?MarkdownToCarve $converter = null): string
    {
        $carve = ($converter ?? new MarkdownToCarve())->convert($markdown);

        return rtrim((new CarveConverter())->convert($carve), "\n");
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function staysTextCases(): array
    {
        return [
            'inline footnote' => ["a ^[note] b\n", '<p>a ^[note] b</p>'],
            'abbreviation definition' => [
                "*[HTML]: HyperText Markup Language\n\nHTML is a language.\n",
                "<p>*[HTML]: HyperText Markup Language</p>\n<p>HTML is a language.</p>",
            ],
            'fenced div' => ["::: note\nx\n:::\n", "<p>::: note\nx\n:::</p>"],
            'attributed span and block attribute line' => [
                "a [t]{.c} b\n\n{.cls}\n\npara\n",
                "<p>a [t]{.c} b</p>\n<p>{.cls}</p>\n<p>para</p>",
            ],
            'math and literal sigils' => [
                "a \$`x+y` b\n\nc \$\$`x+y` d\n\ne !`x` f\n",
                "<p>a \$<code>x+y</code> b</p>\n<p>c \$\$<code>x+y</code> d</p>\n<p>e !<code>x</code> f</p>",
            ],
            'extension call' => ["a :term[x] b\n", '<p>a :term[x] b</p>'],
        ];
    }

    #[DataProvider('staysTextCases')]
    public function testTheConstructStaysLiteralAtTheDefaults(string $markdown, string $expected): void
    {
        $this->assertSame($expected, $this->render($markdown));
    }

    public function testAFootnoteReferenceIsNotAnInlineFootnote(): void
    {
        // The caret in `a[^1]` is followed by the label, not by a bracket, so
        // the escape must not touch the reference spelling.
        $html = $this->render("text[^1]\n\n[^1]: note\n");

        $this->assertStringContainsString('fnref', $html);
    }

    public function testAColonWithoutABracketStaysBare(): void
    {
        // `at 10:30[x]`-shaped text is untouched: the extension-call escape
        // requires a letter-led name reaching the bracket without a break.
        $this->assertSame('<p>at 10:30 sharp</p>', $this->render("at 10:30 sharp\n"));
        $this->assertStringContainsString('10:30[x]', $this->render("at 10:30[x]\n"));
    }

    public function testTheInlineFootnoteFlagOptsBackIn(): void
    {
        $html = $this->render("a ^[note] b\n", new MarkdownToCarve(convertInlineFootnotes: true));

        $this->assertStringContainsString('doc-noteref', $html);
    }

    public function testTheAbbreviationsFlagOptsBackIn(): void
    {
        $html = $this->render(
            "*[HTML]: HyperText Markup Language\n\nHTML is a language.\n",
            new MarkdownToCarve(convertAbbreviations: true),
        );

        $this->assertStringContainsString('<abbr title="HyperText Markup Language">HTML</abbr>', $html);
    }

    public function testTheFencedDivsFlagOptsBackIn(): void
    {
        $html = $this->render("::: note\nx\n:::\n", new MarkdownToCarve(convertFencedDivs: true));

        $this->assertStringContainsString('<aside class="admonition note" aria-label="Note">', $html);
    }

    public function testTheAttributesFlagOptsBackIn(): void
    {
        $html = $this->render("a [t]{.c} b\n", new MarkdownToCarve(convertAttributes: true));

        $this->assertStringContainsString('<span class="c">t</span>', $html);
    }

    public function testABracedDelimiterPairIsNotAnAttributeList(): void
    {
        // This converter emits `{,x,}` itself for `<sub>x</sub>`, and Carve
        // reads it as a subscript wherever it stands - so the attribute-list
        // escape must leave the form alone even directly after a closer.
        $html = $this->render("H<sub>2</sub>O\n");

        $this->assertSame('<p>H<sub>2</sub>O</p>', $html);
    }
}
