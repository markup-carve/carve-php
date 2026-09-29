<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A container's `[label]` is an inline run, so the fallback caption publishes
 * that run and not the characters the author typed.
 *
 * `CARVE-P9-041` names a container label among the delimited regions parsed as
 * `inline_content` in their own right, a definition markup-carve/carve#2604 put
 * in place of the old host enumeration. The engine's old position was not
 * coherent on its own terms either: a trailing `%%` comment inside a label was
 * already consumed as a run, so one host answered two ways.
 *
 * The label's EXTENT moves with it. The `label` production takes a balanced
 * bracket run (markup-carve/carve#2576) and the three slots carrying it were
 * written flat as `\[[^\]]*\]`, so a `]` inside a code span ended the label at
 * that `]` and the whole opener line fell back to prose.
 *
 * Corpus category 529 (markup-carve/carve#2600) pins the ten documents below and
 * is not in this engine's spec pin yet, so they are pinned here.
 */
class AContainerLabelPublishesItsInlineRunTest extends TestCase
{
    /**
     * The ten documents of corpus category 529, verbatim.
     *
     * @return array<string, array{string, string}>
     */
    public static function corpusDocuments(): array
    {
        return [
            'emphasis in a bare opener' => [
                ":::[/i/]\nbody\n:::\n",
                "<div>\n  <p class=\"div-label\"><em>i</em></p>\n  <p>body</p>\n</div>\n",
            ],
            'strong in a bare opener' => [
                ":::[*b*]\nbody\n:::\n",
                "<div>\n  <p class=\"div-label\"><strong>b</strong></p>\n  <p>body</p>\n</div>\n",
            ],
            'a code span in a bare opener' => [
                ":::[`x`]\nbody\n:::\n",
                "<div>\n  <p class=\"div-label\"><code>x</code></p>\n  <p>body</p>\n</div>\n",
            ],
            'the bare opener control' => [
                ":::[plain]\nbody\n:::\n",
                "<div>\n  <p class=\"div-label\">plain</p>\n  <p>body</p>\n</div>\n",
            ],
            'emphasis in a named opener' => [
                "::: note [/i/]\nbody\n:::\n",
                "<aside class=\"admonition note\" aria-label=\"Note\">\n"
                    . "  <p class=\"div-label\"><em>i</em></p>\n  <p>body</p>\n</aside>\n",
            ],
            'a code span in a named opener' => [
                "::: note [`x`]\nbody\n:::\n",
                "<aside class=\"admonition note\" aria-label=\"Note\">\n"
                    . "  <p class=\"div-label\"><code>x</code></p>\n  <p>body</p>\n</aside>\n",
            ],
            'the named opener control' => [
                "::: note [plain]\nbody\n:::\n",
                "<aside class=\"admonition note\" aria-label=\"Note\">\n"
                    . "  <p class=\"div-label\">plain</p>\n  <p>body</p>\n</aside>\n",
            ],
            'a bracket inside a code span in a fence label' => [
                "``` js [a `]` b]\nc\n```\n",
                "<pre><code class=\"language-js\">c\n</code></pre>\n",
            ],
            'emphasis in a fence label' => [
                "``` js [/i/]\nc\n```\n",
                "<pre><code class=\"language-js\">c\n</code></pre>\n",
            ],
            'the fence label control' => [
                "``` js [plain]\nc\n```\n",
                "<pre><code class=\"language-js\">c\n</code></pre>\n",
            ],
        ];
    }

    /**
     * The run's other inline kinds, and the escape that keeps a `]` out of the
     * extent question.
     *
     * @return array<string, array{string, string}>
     */
    public static function furtherRuns(): array
    {
        return [
            '{+a %% secret+}' => [
                ":::[{+a %% secret+}]\nbody\n:::\n",
                "<div>\n  <p class=\"div-label\"><ins>a</ins></p>\n  <p>body</p>\n</div>\n",
            ],
            '{-a %% secret-}' => [
                ":::[{-a %% secret-}]\nbody\n:::\n",
                "<div>\n  <p class=\"div-label\"><del>a</del></p>\n  <p>body</p>\n</div>\n",
            ],
            '{%a %% secret%}' => [
                ":::[{%a %% secret%}]\nbody\n:::\n",
                "<div>\n  <p class=\"div-label\"></p>\n  <p>body</p>\n</div>\n",
            ],
            '{#a %% secret#}' => [
                ":::[{#a %% secret#}]\nbody\n:::\n",
                "<div>\n  <p class=\"div-label\"><span class=\"critic-comment\">a %% secret</span></p>\n  <p>body</p>\n</div>\n",
            ],
            '{+a %% secret+} %% outer' => [
                ":::[{+a %% secret+} %% outer]\nbody\n:::\n",
                "<div>\n  <p class=\"div-label\"><ins>a</ins></p>\n  <p>body</p>\n</div>\n",
            ],
            'two constructs in one label' => [
                ":::[/i/ and *b*]\nbody\n:::\n",
                "<div>\n  <p class=\"div-label\"><em>i</em> and <strong>b</strong></p>\n"
                    . "  <p>body</p>\n</div>\n",
            ],
            'a link' => [
                ":::[a [l](/u) b]\nbody\n:::\n",
                "<div>\n  <p class=\"div-label\">a <a href=\"/u\">l</a> b</p>\n  <p>body</p>\n</div>\n",
            ],
            'an attributed span' => [
                ":::[[s]{.c}]\nbody\n:::\n",
                "<div>\n  <p class=\"div-label\"><span class=\"c\">s</span></p>\n  <p>body</p>\n</div>\n",
            ],
            'an escaped marker stays literal' => [
                ":::[a \\/b\\/]\nbody\n:::\n",
                "<div>\n  <p class=\"div-label\">a /b/</p>\n  <p>body</p>\n</div>\n",
            ],
            'an escaped closing bracket' => [
                ":::[a \\] b]\nbody\n:::\n",
                "<div>\n  <p class=\"div-label\">a ] b</p>\n  <p>body</p>\n</div>\n",
            ],
            'a nested bracket run' => [
                ":::[a [b] c]\nbody\n:::\n",
                "<div>\n  <p class=\"div-label\">a [b] c</p>\n  <p>body</p>\n</div>\n",
            ],
            'an empty label is still a label' => [
                ":::[]\nbody\n:::\n",
                "<div>\n  <p class=\"div-label\"></p>\n  <p>body</p>\n</div>\n",
            ],
            'HTML in the label is escaped, not raw' => [
                ":::[a <b> c]\nbody\n:::\n",
                "<div>\n  <p class=\"div-label\">a &lt;b&gt; c</p>\n  <p>body</p>\n</div>\n",
            ],
        ];
    }

    /**
     * The extent is a balanced run, so an UNBALANCED one is not a label and the
     * opener line is prose - which is what a link text does with the same bytes.
     *
     * @return array<string, array{string, string}>
     */
    public static function openersThatAreProse(): array
    {
        return [
            'an unclosed backtick run swallows the closer' => [
                ":::[a `b]\nbody\n:::\n",
                "<p>:::[a <code>b]\nbody\n:::</code></p>\n",
            ],
            'text after the run' => [
                ":::[a] b\nbody\n:::\n",
                "<p>:::[a] b\nbody\n:::</p>\n",
            ],
            'an unbalanced nested opener' => [
                ":::[a [b]\nbody\n:::\n",
                "<p>:::[a [b]\nbody\n:::</p>\n",
            ],
        ];
    }

    #[DataProvider('corpusDocuments')]
    #[DataProvider('furtherRuns')]
    #[DataProvider('openersThatAreProse')]
    public function testTheOracleReading(string $source, string $expected): void
    {
        $this->assertSame($expected, (new CarveConverter())->convert($source));
    }

    /**
     * The other half the run owes: a label carrying markup now survives an HTML
     * round trip, because the lift has a spelling for it on the opener. Text-only
     * labels lifted before this and keep lifting VERBATIM, padding included.
     *
     * @return array<string, array{string, string}>
     */
    public static function htmlImports(): array
    {
        return [
            'emphasis' => [
                "<div>\n  <p class=\"div-label\"><em>i</em></p>\n  <p>body</p>\n</div>\n",
                "::: [/i/]\nbody\n:::\n",
            ],
            'strong' => [
                "<div>\n  <p class=\"div-label\"><strong>b</strong></p>\n  <p>body</p>\n</div>\n",
                "::: [*b*]\nbody\n:::\n",
            ],
            'a code span' => [
                "<div>\n  <p class=\"div-label\"><code>x</code></p>\n  <p>body</p>\n</div>\n",
                "::: [`x`]\nbody\n:::\n",
            ],
            'two constructs' => [
                "<div>\n  <p class=\"div-label\"><em>i</em> and <strong>b</strong></p>\n  <p>body</p>\n</div>\n",
                "::: [/i/ and *b*]\nbody\n:::\n",
            ],
            'an admonition' => [
                "<aside class=\"admonition note\" aria-label=\"Note\">\n"
                    . "  <p class=\"div-label\"><em>i</em></p>\n  <p>body</p>\n</aside>\n",
                "::: note [/i/]\nbody\n:::\n",
            ],
            'the text-only control' => [
                "<div>\n  <p class=\"div-label\">plain</p>\n  <p>body</p>\n</div>\n",
                "::: [plain]\nbody\n:::\n",
            ],
            'padding a text-only label keeps' => [
                "<div>\n  <p class=\"div-label\"> x </p>\n  <p>body</p>\n</div>\n",
                "::: [ x ]\nbody\n:::\n",
            ],
        ];
    }

    #[DataProvider('htmlImports')]
    public function testTheImportLiftsAMarkupLabel(string $html, string $expected): void
    {
        $this->assertSame($expected, rtrim((new HtmlToCarve())->convert($html), "\n") . "\n");
    }

    /**
     * The refusals the lift still owes, asked of the WRITTEN form rather than
     * enumerated: a `]` that reaches the label through a code span carries no `]`
     * in the label's own text, and an empty code span writes two backticks the
     * opener reads as an unclosed run. Each leaves the paragraph in the body,
     * where it was before the label was a run.
     *
     * @return array<string, array{string}>
     */
    public static function liftsItRefuses(): array
    {
        return [
            'a bracket through a code span' => ['<div><p class="div-label"><code>]</code></p><p>b</p></div>'],
            'an empty code span' => ['<div><p class="div-label"><code></code></p><p>b</p></div>'],
            'a hard break' => ['<div><p class="div-label">a<br>b</p><p>b</p></div>'],
            'a link, whose own closer is a bracket' => ['<div><p class="div-label"><a href="/u">l</a></p><p>b</p></div>'],
        ];
    }

    #[DataProvider('liftsItRefuses')]
    public function testARefusedLiftKeepsTheParagraph(string $html): void
    {
        $source = rtrim((new HtmlToCarve())->convert($html), "\n") . "\n";

        $this->assertStringContainsString('{.div-label}', $source);
        $this->assertStringNotContainsString(':::', $source);
    }

    /**
     * NOT FIXED HERE, and named rather than half-answered. All three need the
     * label's run to be in the tree: this engine resolves references while
     * parsing and numbers footnotes over the tree, and a run held as a string is
     * in neither. The non-HTML targets also still write the label as authored
     * text. Both are the same boundary carve-js#2348 stopped at.
     */
    public function testAReferenceInALabelDoesNotResolve(): void
    {
        $this->assertSame(
            "<div>\n  <p class=\"div-label\">[r][]</p>\n  <p>body</p>\n</div>\n",
            (new CarveConverter())->convert(":::[[r][]]\nbody\n:::\n\n[r]: /u\n"),
        );
    }

    /**
     * NO INTERCHANGE FIELD MOVES. The label stays a string on the wire, the way
     * the reference holds it, and the run is read back off it on decode - so the
     * schema names nothing new and a decoded document publishes the same caption.
     *
     * @return array<string, array{string}>
     */
    public static function interchangeShapes(): array
    {
        return [
            'a div' => [":::[/i/]\nbody\n:::\n"],
            'an admonition' => ["::: note [/i/]\nbody\n:::\n"],
            'a directive' => ["::: toc [/i/]\n:::\n"],
        ];
    }

    #[DataProvider('interchangeShapes')]
    public function testTheLabelSurvivesTheAstRoundTrip(string $source): void
    {
        $converter = new CarveConverter();
        $codec = new AstCodec();
        $decoded = $codec->decode($codec->encode($converter->parse($source)));

        $this->assertSame($converter->convert($source), $converter->render($decoded));
    }

    public function testTheMarkdownTargetStillWritesTheAuthoredText(): void
    {
        $document = (new CarveConverter())->parse(":::[/i/]\nbody\n:::\n");

        $this->assertStringContainsString('/i/', (new MarkdownRenderer())->render($document));
    }
}
