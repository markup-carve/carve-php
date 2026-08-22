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
            'core display math' => ["\$\$`\\int_0^1 x^2 \\, dx`\n"],
        ];
    }

    /**
     * `<div class="math display">` imports as the CORE `$$` form, never as the
     * ``` math ``` fence (PART 9 section 18, ruled at markup-carve/carve#1514).
     *
     * THIS ROW USED TO BE `'the block math fence'` IN THE PROVIDER ABOVE, and
     * it asserted the strictly stronger thing: that the fence's rendered HTML
     * re-rendered BYTE-IDENTICALLY after the import. It did, because the
     * importer wrote the fence back and the extension rendered the same div.
     * The argument was that the extension WRITES that div, so the fence is an
     * exact inverse. The ruling weighed it and it lost: the fence is an
     * EXTENSION, so with the extension not loaded the imported document is a
     * `language-math` code block instead of an equation, and the same file is
     * mathematics for one reader and code for another. The byte round trip was
     * therefore pinning a property nobody wanted - it could only hold while the
     * importer emitted a construct whose meaning depends on the consumer's
     * configuration.
     *
     * So the equality moved down a level, to the one the ruling actually asks
     * for: the imported source means what the HTML meant. It holds a display
     * math node carrying the same TeX, and it needs no extension to say so.
     */
    public function testABlockMathDivImportsAsTheCoreFormAndNotTheFence(): void
    {
        $html = $this->render("``` math\n\\int_0^1 x^2 \\, dx\n```\n");
        $this->assertSame('<div class="math display">\[\int_0^1 x^2 \, dx\]</div>', trim($html));

        $imported = (new HtmlToCarve())->convert($html);

        $this->assertSame("\$\$`\\int_0^1 x^2 \\, dx`\n", $imported);
        $this->assertStringNotContainsString('```', $imported);
        $this->assertSameTree("\$\$`\\int_0^1 x^2 \\, dx`\n", $imported);

        // The point of the ruling, stated where it can be seen: the imported
        // source is an equation with NO extension registered. Under the fence
        // this rendered `<pre><code class="language-math">`.
        $this->assertSame(
            '<p><span class="math display" role="math">\[\int_0^1 x^2 \, dx\]</span></p>',
            trim((new CarveConverter())->convert($imported)),
        );
    }

    /**
     * The importer's whole block-math answer, on the shape the shared contract
     * fixture `tests/html-import/math-block-and-mathml` carries: the div, a
     * block `<math>` and an inline `<math>` in one document.
     */
    public function testTheSharedImportContractsMathDocument(): void
    {
        $imported = (new HtmlToCarve())->convert(
            '<div class="math display">\[E = mc^2\]</div>'
            . '<math display="block" alttext="a - b"></math>'
            . '<p>x <math alttext="c + d"></math> y</p>',
        );

        $this->assertSame("\$\$`E = mc^2`\n\n\$\$`a - b`\n\nx \$`c + d` y\n", $imported);
    }

    /**
     * The display class decides the sigil, so a div spelled `math inline`
     * writes the INLINE form. Under the fence every recognized div became
     * display math, because a ``` math ``` block has no other mode.
     */
    public function testADivSpelledInlineWritesTheInlineForm(): void
    {
        $this->assertSame("\$`x`\n", (new HtmlToCarve())->convert('<div class="math inline">\(x\)</div>'));
    }

    /**
     * The author's attributes ride the math NODE now, not a block attribute
     * line in front of a fence.
     */
    public function testABlockMathDivKeepsTheAuthorsAttributes(): void
    {
        $imported = (new HtmlToCarve())->convert('<div id="eq" class="math display big" data-k="v">\[x\]</div>');

        $this->assertSame("\$\$`x`{#eq .big data-k=v}\n", $imported);
        $this->assertSameTree("\$\$`x`{#eq .big data-k=v}\n", $imported);
    }

    /**
     * A div needs BOTH signals too, exactly as the span does: the class pair
     * AND a payload delimited to match it. Either alone is an ordinary div and
     * must come back as one, or a stylesheet class named `math` would turn a
     * wrapper into an equation.
     *
     * @return array<string, array{0: string}>
     */
    public static function notBlockMathProvider(): array
    {
        return [
            'no delimiters' => ['<div class="math display">x^2</div>'],
            'no display mode' => ['<div class="math">\[x^2\]</div>'],
            'delimiter disagrees with the mode' => ['<div class="math display">\(x^2\)</div>'],
            'element children' => ['<div class="math display">\[<em>x</em>\]</div>'],
            'delimiters with nothing between them' => ['<div class="math display">\[\]</div>'],
        ];
    }

    #[DataProvider('notBlockMathProvider')]
    public function testADivThatOnlyLooksLikeMathStaysADiv(string $html): void
    {
        $imported = (new HtmlToCarve())->convert($html);

        $this->assertStringNotContainsString('$$`', $imported);
        $this->assertStringNotContainsString('$`', $imported);
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
     * A math body folds to ONE LINE, because a code span is one line.
     *
     * THIS TEST USED TO ASSERT THE OPPOSITE - that a body came back
     * BYTE-VERBATIM, tabs and blank runs and all - and it could, because the
     * importer wrote a fence, and a fence's content is its lines joined by
     * newlines. The core `$$` form the ruling requires has nowhere to put a
     * newline: Carve math is a PREFIX on a code span
     * (`math_display = "$$", code_span`), and a code span is one line by
     * construction. So the verbatim guarantee went out with the fence, and what
     * replaces it is the guarantee that actually matters - the equation is the
     * same equation. TeX reads a whitespace run as a single space, so folding
     * one cannot change what is typeset.
     *
     * Each row is the body and the equation it folds to.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function mathBodyProvider(): array
    {
        return [
            'plain' => ['x', 'x'],
            'trailing spaces' => ['x  ', 'x'],
            'leading spaces' => ['  x', 'x'],
            'a trailing blank line' => ["x\n", 'x'],
            'two trailing blank lines' => ["x\n\n", 'x'],
            'a tab' => ["x\ty", 'x y'],
            'an interior blank run' => ["a\n\n\nb", 'a b'],
        ];
    }

    #[DataProvider('mathBodyProvider')]
    public function testABlockMathBodyFoldsToTheEquationItSpells(string $body, string $folded): void
    {
        $html = $this->render("``` math\n" . $body . "\n```\n");
        $imported = (new HtmlToCarve())->convert($html);

        $this->assertSame('$$`' . $folded . "`\n", $imported);

        // What the fold is FOR. Written out verbatim, a body carrying a blank
        // line ends the paragraph the math node lives in, so `\[a\n\n\nb\]`
        // came back as an equation `a`, a paragraph `b` and a stray code span -
        // the document destroyed by whitespace the equation did not need.
        $this->assertSameTree('$$`' . $folded . "`\n", $imported);
    }

    /**
     * The same fold on the inline side, which had the same hole: the payload
     * of `<span class="math inline">` reached the source verbatim, so a blank
     * line inside one split the paragraph around it.
     */
    public function testAnInlineMathBodyFoldsForTheSameReason(): void
    {
        $imported = (new HtmlToCarve())->convert('<p>a <span class="math inline">\(p' . "\n\n" . 'q\)</span> b</p>');

        $this->assertSame("a \$`p q` b\n", $imported);
        $this->assertSameTree("a \$`p q` b\n", $imported);
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
