<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What an inline-only slot does with content it cannot hold
 * (markup-carve/carve-php#2371).
 *
 * A cell and a caption flatten their blocks to inlines, and the flatten walks
 * `children`, `items`, `rows`, `cells` and `caption`. A node carrying its
 * payload anywhere else projected to nothing: a code block's `content`, a ruby's
 * `pairs`, an admonition's `title`, a nested table's own `caption`.
 *
 * Each assertion pins what a reader sees - the Carve, and the text the rendered
 * document holds - rather than that a report row exists.
 */
class ACodeBlockARubyAndADetailsReachAnInlineOnlySlotTest extends TestCase
{
    /**
     * A code block becomes a code SPAN, the inline spelling of the same kind.
     *
     * @return array<string, array{0: string, 1: string, 2?: string}>
     */
    public static function codeBlocks(): array
    {
        return [
            'the only cell of a table' => [
                '<table><tr><td><pre><code>f</code></pre></td></tr></table>',
                '| `f` |',
            ],
            'one cell of two' => [
                '<table><tr><td><pre><code>f</code></pre></td><td>b</td></tr></table>',
                '| `f` | b |',
            ],
            'a header cell' => [
                '<table><thead><tr><th><pre><code>f</code></pre></th></tr></thead><tbody><tr><td>z</td></tr></tbody></table>',
                "|= `f` |\n| z |",
            ],
            'a pre with no code child' => [
                '<table><tr><td><pre>f</pre></td></tr></table>',
                '| `f` |',
            ],
            'content holding a backtick' => [
                '<table><tr><td><pre><code>a`b</code></pre></td></tr></table>',
                '| ``a`b`` |',
                'a`b',
            ],
            'a code block and a paragraph' => [
                '<table><tr><td><pre><code>f</code></pre><p>t</p></td></tr></table>',
                '| `f` t |',
            ],
            "a table's caption" => [
                '<table><caption><pre><code>f</code></pre></caption><tr><td>z</td></tr></table>',
                "| z |\n^ `f`",
            ],
            'a figcaption' => [
                '<figure><img src="i.png" alt="a"><figcaption><pre><code>f</code></pre></figcaption></figure>',
                "![a](i.png)\n^ `f`",
            ],
        ];
    }

    #[DataProvider('codeBlocks')]
    public function testACodeBlockBecomesACodeSpan(string $html, string $carve, string $code = 'f'): void
    {
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            $result = (new HtmlToCarve(importMode: $mode))->convertWithReport($html);
            $this->assertSame($carve, rtrim($result->value, "\n"), $mode);
            $rendered = (new CarveConverter())->convert($result->value);
            $this->assertStringContainsString('<code>' . $code . '</code>', $rendered, $mode);
        }
    }

    /**
     * A ROW is one line, so the span's newlines fold to a space. A caption's
     * continuation line would take one, but a line that opens a block ends the
     * caption instead of continuing it, so the fold is the answer that reads
     * back from either slot.
     *
     * @return array<string, array{string, string}>
     */
    public static function multiLineCode(): array
    {
        return [
            'a cell' => [
                "<table><tr><td><pre><code>f\ng</code></pre></td></tr></table>",
                '| `f g` |',
            ],
            "a table's caption" => [
                "<table><caption><pre><code>f\ng</code></pre></caption><tr><td>z</td></tr></table>",
                "| z |\n^ `f g`",
            ],
        ];
    }

    #[DataProvider('multiLineCode')]
    public function testTheSpanHoldsNoNewline(string $html, string $carve): void
    {
        $result = (new HtmlToCarve())->convertWithReport($html);
        $this->assertSame($carve, rtrim($result->value, "\n"));
        $rendered = (new CarveConverter())->convert($result->value);
        $this->assertStringContainsString('<table>', $rendered, 'the row ended at a newline');
        $this->assertStringContainsString('f g', strip_tags($rendered));
    }

    /**
     * A ruby is inline content, and the writer already spells one it cannot keep
     * as `base(annotation)` with a `structure-unspellable` row. The slot only
     * needed to carry it there.
     *
     * @return array<string, array{string, string}>
     */
    public static function rubies(): array
    {
        return [
            'a cell' => ['<table><tr><td><ruby>f<rt>g</rt></ruby></td></tr></table>', '| f(g) |'],
            'a cell with text around it' => ['<table><tr><td>a<ruby>f<rt>g</rt></ruby>b</td></tr></table>', '| af(g)b |'],
            'an explicit base' => ['<table><tr><td><ruby><rb>f</rb><rt>g</rt></ruby></td></tr></table>', '| f(g) |'],
            'two pairs' => ['<table><tr><td><ruby>f<rt>g</rt>h<rt>i</rt></ruby></td></tr></table>', '| f(g)h(i) |'],
            "a table's caption" => ['<table><caption><ruby>f<rt>g</rt></ruby></caption><tr><td>z</td></tr></table>', "| z |\n^ f(g)"],
        ];
    }

    #[DataProvider('rubies')]
    public function testARubyKeepsItsBaseAndItsAnnotation(string $html, string $carve): void
    {
        $result = (new HtmlToCarve())->convertWithReport($html);
        $this->assertSame($carve, rtrim($result->value, "\n"));
        $text = strip_tags((new CarveConverter())->convert($result->value));
        $this->assertStringContainsString('f', $text);
        $this->assertStringContainsString('g', $text);
        $this->assertContains(
            'structure-unspellable',
            array_map(static fn ($diagnostic): string => $diagnostic->toArray()['code'], $result->diagnostics),
        );
    }

    /**
     * A `<summary>` reaches the slot with the rest of the `<details>`. The
     * caption takes the cell's path for it, so both slots put the summary in the
     * same inline run as the body and the `element-unwrapped` row is true of
     * what comes out.
     *
     * @return array<string, array{string, string}>
     */
    public static function details(): array
    {
        return [
            'a cell' => [
                '<table><tr><td><details><summary>s</summary>f</details></td></tr></table>',
                '| sf |',
            ],
            "a table's caption" => [
                '<table><caption><details><summary>s</summary>f</details></caption><tr><td>z</td></tr></table>',
                "| z |\n^ sf",
            ],
            'a figcaption' => [
                '<figure><img src="i.png" alt="a"><figcaption><details><summary>s</summary>f</details></figcaption></figure>',
                "![a](i.png)\n^ sf",
            ],
            'a caption, under a div' => [
                '<table><caption><div><details><summary>s</summary>f</details></div></caption><tr><td>z</td></tr></table>',
                "| z |\n^ sf",
            ],
        ];
    }

    #[DataProvider('details')]
    public function testADetailsKeepsItsSummary(string $html, string $carve): void
    {
        $result = (new HtmlToCarve())->convertWithReport($html);
        $this->assertSame($carve, rtrim($result->value, "\n"));
        $this->assertStringContainsString('s', strip_tags((new CarveConverter())->convert($result->value)));
        $this->assertContains(
            'element-unwrapped',
            array_map(static fn ($diagnostic): string => $diagnostic->toArray()['code'], $result->diagnostics),
        );
    }

    /**
     * Two more payloads the flatten did not reach, found while measuring: an
     * admonition's title, and a nested table's own caption.
     *
     * @return array<string, array{string, string}>
     */
    public static function otherPayloads(): array
    {
        return [
            "an admonition's title in a cell" => [
                '<table><tr><td><aside class="admonition note"><p class="admonition-title">t</p><p>b</p></aside></td></tr></table>',
                '| t b |',
            ],
            "an admonition's title in a caption" => [
                '<table><caption><aside class="admonition note"><p class="admonition-title">t</p><p>b</p></aside></caption><tr><td>z</td></tr></table>',
                "| z |\n^ t b",
            ],
            "a nested table's caption in a cell" => [
                '<table><tr><td><table><caption>c</caption><tr><td>i</td></tr></table></td></tr></table>',
                '| c \| i \| |',
            ],
            "a nested table's caption in a caption" => [
                '<table><caption><table><caption>c</caption><tr><td>i</td></tr></table></caption><tr><td>z</td></tr></table>',
                "| z |\n^ c i",
            ],
        ];
    }

    #[DataProvider('otherPayloads')]
    public function testTheSlotCarriesTheOtherPayloadsToo(string $html, string $carve): void
    {
        $result = (new HtmlToCarve())->convertWithReport($html);
        $this->assertSame($carve, rtrim($result->value, "\n"));
    }

    /**
     * The controls. MathML with no TeX annotation is dropped at every position
     * by markup-carve/carve#1210's D6 ruling and stays dropped here. A code
     * block outside an inline-only slot is still a fenced block, and a ruby
     * outside one still spells the same way. A blank row is still not a row.
     *
     * @return array<string, array{string, string, list<string>}>
     */
    public static function untouched(): array
    {
        return [
            'MathML in a cell is still dropped' => [
                '<table><tr><td><math><mi>x</mi></math></td></tr></table>',
                '',
                ['element-dropped'],
            ],
            'a blank row is still not a row' => [
                '<table><tr><td></td></tr></table>',
                '',
                ['structure-unspellable'],
            ],
            'a code block in a list item' => [
                '<ul><li><pre><code>f</code></pre></li></ul>',
                "- ```\n  f\n  ```",
                [],
            ],
            'a code block in a div' => [
                '<div><pre><code>f</code></pre></div>',
                "```\nf\n```",
                [],
            ],
            'a code block in a quote' => [
                '<blockquote><pre><code>f</code></pre></blockquote>',
                "> ```\n> f\n> ```",
                [],
            ],
            'a ruby in a paragraph' => [
                '<p><ruby>f<rt>g</rt></ruby></p>',
                'f(g)',
                ['structure-unspellable'],
            ],
            'a details in a div' => [
                '<div><details><summary>s</summary>f</details></div>',
                "::: details \"s\"\nf\n:::",
                [],
            ],
        ];
    }

    #[DataProvider('untouched')]
    public function testTheArmsReachNothingElse(string $html, string $carve, array $codes): void
    {
        $result = (new HtmlToCarve())->convertWithReport($html);
        $this->assertSame($carve, trim($result->value));
        $this->assertSame(
            $codes,
            array_map(static fn ($diagnostic): string => $diagnostic->toArray()['code'], $result->diagnostics),
        );
    }
}
