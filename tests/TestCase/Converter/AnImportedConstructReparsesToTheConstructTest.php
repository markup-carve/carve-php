<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Extension\MathBlockExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Import is a ROUND TRIP, so it is tested as one: what the importer writes has
 * to re-read as the thing it imported (carve-php#1543).
 *
 * The equality is on the TREE, never on the bytes, wherever more than one
 * spelling is allowed - a byte assertion there pins a writer strategy rather
 * than the rule, and pinning the strategy is what let the math importer emit
 * `$`x`$` for years: every assertion agreed with it, and none of them read the
 * result back to notice the trailing `$` was a stray character in the
 * paragraph rather than part of the equation.
 */
class AnImportedConstructReparsesToTheConstructTest extends TestCase
{
    /**
     * Sources whose rendered HTML must import back to the same document.
     *
     * @return array<string, array{0: string}>
     */
    public static function mathSourceProvider(): array
    {
        return [
            'inline math' => ["An inline \$`E = mc^2` formula.\n"],
            'display math' => ["A \$\$`E = mc^2` formula.\n"],
            'math carrying authored attributes' => ["An inline \$`x^2`{#eq .highlight} formula.\n"],
            'math holding a backtick run' => ["An inline \$``x`y`` formula.\n"],
            'the block math fence' => ["``` math\n\\int_0^1 x^2 \\, dx\n```\n"],
        ];
    }

    #[DataProvider('mathSourceProvider')]
    public function testMathSurvivesItsOwnRenderedHtml(string $source): void
    {
        $html = $this->render($source);
        $imported = (new HtmlToCarve())->convert($html);

        $this->assertSame(
            $html,
            $this->render($imported),
            "the equation must survive the import; imported source was:\n" . $imported,
        );
    }

    /**
     * The delimiters must be GONE from the source, not carried as text.
     *
     * The failure this guards was invisible to a "does the output still contain
     * the TeX" assertion: `[\\(E = mc^2\\)]{.math .inline}` contains every
     * character of the equation and is not an equation at all.
     */
    public function testTheImportedMathIsMathAndNotADelimitedSpan(): void
    {
        $imported = (new HtmlToCarve())->convert(
            '<p>An inline <span class="math inline">\(E = mc^2\)</span> formula.</p>',
        );

        $this->assertSame("An inline \$`E = mc^2` formula.\n", $imported);
    }

    /**
     * Carve math has no CLOSING delimiter (grammar §18: `math_inline = '$',
     * code_span`), so a trailing one is the paragraph's next character. The
     * MathML path wrote it, and it came back as a stray `$` beside the span.
     */
    public function testAMappedMathElementWritesNoTrailingDelimiter(): void
    {
        $imported = (new HtmlToCarve())->convert('<p>a <math alttext="x^2"></math> b</p>');

        $this->assertSame("a \$`x^2` b\n", $imported);
        $this->assertSameTree("a \$`x^2` b\n", $imported);
    }

    /**
     * A span that carries the class but not the delimited payload is NOT this
     * engine's math output, and stays the attributed span it already was.
     *
     * Both signals are required precisely so that a stylesheet's `math` class
     * cannot turn arbitrary prose into an equation.
     *
     * @return array<string, array{0: string}>
     */
    public static function notMathProvider(): array
    {
        return [
            'no delimiters' => ['<p>a <span class="math inline">x^2</span> b</p>'],
            'no display mode' => ['<p>a <span class="math">\(x^2\)</span> b</p>'],
            'delimiter disagrees with the mode' => ['<p>a <span class="math display">\(x^2\)</span> b</p>'],
            'element children' => ['<p>a <span class="math inline">\(<em>x</em>\)</span> b</p>'],
        ];
    }

    #[DataProvider('notMathProvider')]
    public function testASpanThatOnlyLooksLikeMathStaysASpan(string $html): void
    {
        $imported = (new HtmlToCarve())->convert($html);

        $this->assertStringNotContainsString('$`', $imported);
        $this->assertStringContainsString('.math', $imported);
    }

    /**
     * Loose text beside a block sibling: the seam that carried no separator.
     *
     * Each row is the HTML and the Carve that spells the same document. The
     * importer used to concatenate the two children, which wrote the block's
     * opener onto the text's line and stopped it opening anything.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function seamProvider(): array
    {
        return [
            'a nested div' => [
                '<div class="tabs">First<div class="tabs-panel"><p>a</p></div></div>',
                ":::: tabs\nFirst\n\n::: tabs-panel\na\n:::\n::::\n",
            ],
            'a label the importer unwraps' => [
                '<div class="tabs"><label>First</label><div class="tabs-panel"><p>a</p></div></div>',
                ":::: tabs\nFirst\n\n::: tabs-panel\na\n:::\n::::\n",
            ],
            'a paragraph' => [
                '<div class="wrap">First<p>a</p></div>',
                "::: wrap\nFirst\n\na\n:::\n",
            ],
            'a blockquote' => [
                '<div class="wrap">First<blockquote><p>a</p></blockquote></div>',
                "::: wrap\nFirst\n\n> a\n:::\n",
            ],
            'a list' => [
                '<div class="wrap">First<ul><li>a</li></ul></div>',
                "::: wrap\nFirst\n\n- a\n:::\n",
            ],
            'a heading' => [
                '<div class="wrap">First<h2>a</h2></div>',
                "::: wrap\nFirst\n\n## a\n:::\n",
            ],
            'a code block' => [
                '<div class="wrap">First<pre><code>a</code></pre></div>',
                "::: wrap\nFirst\n\n```\na\n```\n:::\n",
            ],
            'an admonition body' => [
                '<aside class="admonition note">First<p>a</p></aside>',
                "::: note\nFirst\n\na\n:::\n",
            ],
        ];
    }

    #[DataProvider('seamProvider')]
    public function testALooseTextBesideABlockKeepsTheBlockABlock(string $html, string $expected): void
    {
        $imported = (new HtmlToCarve())->convert($html);

        $this->assertSameTree($expected, $imported);
    }

    /**
     * A math body is the equation's OWN BYTES, so its whitespace comes back.
     *
     * @return array<string, array{0: string}>
     */
    public static function mathBodyProvider(): array
    {
        return [
            'plain' => ['x'],
            'trailing spaces' => ['x  '],
            'leading spaces' => ['  x'],
            'a trailing blank line' => ["x\n"],
            'two trailing blank lines' => ["x\n\n"],
            'a tab' => ["x\ty"],
            'an interior blank run' => ["a\n\n\nb"],
        ];
    }

    #[DataProvider('mathBodyProvider')]
    public function testABlockMathBodyIsCarriedVerbatim(string $body): void
    {
        $html = $this->render("``` math\n" . $body . "\n```\n");
        $imported = (new HtmlToCarve())->convert($html);

        $this->assertSame(
            $html,
            $this->render($imported),
            "the equation's own bytes must survive; imported source was:\n" . $imported,
        );
    }

    /**
     * A blank line inside a fence is CONTENT, and the importer's whole-document
     * blank-line collapse used to rewrite it: two blank lines in a code block
     * came back as one, silently editing the payload.
     */
    public function testAFencedPayloadKeepsItsBlankLines(): void
    {
        $imported = (new HtmlToCarve())->convert("<pre><code>a\n\n\nb</code></pre>");

        $this->assertSame("```\na\n\n\nb\n```\n", $imported);
    }

    /**
     * Outside a fence the collapse still applies - blank lines there are a
     * separator, and one is as good as three.
     */
    public function testBlankLinesBetweenBlocksAreStillCollapsed(): void
    {
        $imported = (new HtmlToCarve())->convert('<p>a</p><p>b</p>');

        $this->assertSame("a\n\nb\n", $imported);
    }

    /**
     * The tab set the extension renders: its panels survive as panels.
     *
     * The tab LABEL is a separate question and is still prose here - binding it
     * back to its panel needs the label's own Carve spelling, which this import
     * does not reconstruct. What it must not do is destroy the panel, and that
     * is what the missing seam did: the panel's opener landed inside the
     * label's paragraph and the panel stopped existing.
     */
    public function testATabSetsPanelsSurviveAsPanels(): void
    {
        $html = <<<'HTML'
        <div class="tabs">
        <input type="radio" name="tabset-1" id="tabset-1-tab-1" class="tabs-radio" checked>
        <label for="tabset-1-tab-1" class="tabs-label">First</label>
        <div class="tabs-panel">
        <p>a</p>
        </div>
        </div>
        HTML;

        $imported = (new HtmlToCarve())->convert($html);
        $document = (new CarveConverter())->parse($imported);
        $encoded = (new AstCodec())->encode($document);

        $tabs = $encoded['children'][0] ?? [];
        $this->assertSame('tabs', $tabs['kind'] ?? null, 'imported source was:' . "\n" . $imported);
        $this->assertSame(
            ['paragraph', 'admonition'],
            array_map(static fn (array $child): string => $child['type'], $tabs['children'] ?? []),
            'imported source was:' . "\n" . $imported,
        );
        $this->assertSame(
            'tabs-panel',
            $tabs['children'][1]['kind'] ?? null,
            'imported source was:' . "\n" . $imported,
        );
    }

    /**
     * Parse both and compare the trees, so the assertion is about the document
     * and not about which of several legal spellings the writer chose.
     */
    protected function assertSameTree(string $expected, string $actual): void
    {
        $this->assertSame(
            $this->tree($expected),
            $this->tree($actual),
            "the imported source must re-read as the expected document; it was:\n" . $actual,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function tree(string $source): array
    {
        $encoded = (new AstCodec())->encode((new CarveConverter())->parse($source));
        // A byte length is a property of the spelling, not of the tree.
        unset($encoded['srcByteLength']);

        return $encoded;
    }

    protected function render(string $source): string
    {
        $converter = new CarveConverter();
        $converter->addExtension(new MathBlockExtension());

        return $converter->convert($source);
    }
}
