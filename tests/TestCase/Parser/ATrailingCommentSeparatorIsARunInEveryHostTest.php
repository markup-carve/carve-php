<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\Paragraph;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CARVE-P9-041 in every inline host (markup-carve/carve#2552, ported from
 * markup-carve/carve#2562). A trailing `%%` marker separates on a tab as well
 * as a space, counts as separated when it starts the inline run, and takes the
 * whole separating run with the rest of the line.
 *
 * The shapes are corpus category 518, which arrives with the spec pin bump. A
 * leaf host is what discriminates: its run begins mid-line, so no `%%` line at
 * the block layer reaches it.
 */
class ATrailingCommentSeparatorIsARunInEveryHostTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function hostProvider(): array
    {
        return [
            'paragraph, tab' => ["a\t%% hidden\n", '<p>a</p>'],
            'paragraph, whole run' => ["a  %% hidden\n", '<p>a</p>'],
            'definition term, tab' => [
                ":: a\t%% hidden\n: d\n",
                "<dl>\n  <dt>a</dt>\n  <dd>d</dd>\n</dl>",
            ],
            'definition term, run start' => [
                ":: %% hidden\n: d\n",
                "<dl>\n  <dt></dt>\n  <dd>d</dd>\n</dl>",
            ],
            'table cell, tab' => [
                "| a\t%% hidden | b |\n|---|---|\n| 1 | 2 |\n",
                "<table>\n  <thead>\n    <tr><th scope=\"col\">a</th><th scope=\"col\">b</th></tr>\n"
                    . "  </thead>\n  <tbody>\n    <tr><td>1</td><td>2</td></tr>\n  </tbody>\n</table>",
            ],
            'table cell, run start' => [
                "| %% hidden | b |\n|---|---|\n| 1 | 2 |\n",
                "<table>\n  <thead>\n    <tr><th scope=\"col\"></th><th scope=\"col\">b</th></tr>\n"
                    . "  </thead>\n  <tbody>\n    <tr><td>1</td><td>2</td></tr>\n  </tbody>\n</table>",
            ],
            'figure caption, tab' => [
                "![alt](u)\n^ cap\t%% hidden\n",
                "<figure>\n  <img src=\"u\" alt=\"alt\">\n  <figcaption>cap</figcaption>\n</figure>",
            ],
            'figure caption, run start' => [
                "![alt](u)\n^ %% hidden\n",
                "<figure>\n  <img src=\"u\" alt=\"alt\">\n  <figcaption></figcaption>\n</figure>",
            ],
            'div label, tab' => [
                "::: note [a\t%% hidden]\nbody\n:::\n",
                "<aside class=\"admonition note\" aria-label=\"Note\">\n  <p class=\"div-label\">a</p>\n"
                    . "  <p>body</p>\n</aside>",
            ],
            'div label, run start' => [
                "::: note [%% hidden]\nbody\n:::\n",
                "<aside class=\"admonition note\" aria-label=\"Note\">\n  <p class=\"div-label\"></p>\n"
                    . "  <p>body</p>\n</aside>",
            ],
            'an unseparated marker stays text' => ["a%%b and 50%% stay\n", '<p>a%%b and 50%% stay</p>'],
            'link label, tab' => ["[a\t%% hidden](/u)\n", '<p><a href="/u">a</a></p>'],
            'link label, run start' => ["[%% hidden](/u)\n", '<p><a href="/u"></a></p>'],
        ];
    }

    #[DataProvider('hostProvider')]
    public function testEveryHostReadsTheSeparatorAsARun(string $source, string $expected): void
    {
        $this->assertSame($expected, trim(CarveConverter::create()->convert($source)));
    }

    /**
     * A third percent is the first character of the consumed remainder. The
     * `~"%"` guard the oracle carried lived only on the deleted heading rules,
     * so no host keeps a `%%%` residual.
     *
     * @return array<string, array{string, string}>
     */
    public static function thirdPercentProvider(): array
    {
        return [
            'heading' => ["# a\t%%% b\n", 'a'],
            'div label' => ["::: note [a\t%%% b]\nbody\n:::\n", 'a'],
            'definition term' => [":: a\t%%% b\n: d\n", 'a'],
        ];
    }

    #[DataProvider('thirdPercentProvider')]
    public function testAThirdPercentIsPartOfTheConsumedRemainder(string $source, string $expected): void
    {
        $html = CarveConverter::create()->convert($source);
        $this->assertStringNotContainsString('%', $html, $source);
        $this->assertStringContainsString('>' . $expected . '<', $html, $source);
    }

    /**
     * A div label is not inline-parsed, so its own scan decides where a marker
     * is a marker. Each case names the label the scan has to leave behind, and
     * every one of them agrees with what the inline parser does with the same
     * run in a paragraph.
     *
     * @return array<string, array{string, string}>
     */
    public static function labelProvider(): array
    {
        $tick = '`';

        return [
            'a code span is opaque' => ['a ' . $tick . 'x %% y' . $tick . ' z', 'a ' . $tick . 'x %% y' . $tick . ' z'],
            'a code span is opaque around a tab' => [
                'a ' . $tick . "x\t%% y" . $tick . ' z',
                'a ' . $tick . "x\t%% y" . $tick . ' z',
            ],
            'a raw inline is opaque' => [
                'a ' . $tick . '%% y' . $tick . '{=html} z',
                'a ' . $tick . '%% y' . $tick . '{=html} z',
            ],
            // Only a run of the opener's width closes a span, so the longer run
            // is content and the marker after it stays inside.
            'a longer run inside a span is content' => [
                'a ' . $tick . 'x ' . $tick . $tick . $tick . ' %% y' . $tick . ' z',
                'a ' . $tick . 'x ' . $tick . $tick . $tick . ' %% y' . $tick . ' z',
            ],
            'a closed span leaves the marker outside it' => [
                'a ' . $tick . 'x' . $tick . ' %% hidden',
                'a ' . $tick . 'x' . $tick,
            ],
            'an escaped backtick after an escaped backslash opens a span' => [
                'a \\\\' . $tick . 'x %% y' . $tick . ' z',
                'a \\\\' . $tick . 'x %% y' . $tick . ' z',
            ],
            // The inline reader decides a marker on the preceding source byte,
            // so escaping the separator does not hide it.
            'an escaped tab still separates' => ["a \\\t%% hidden", 'a \\'],
            'an escaped space still separates' => ['a \\ %% hidden', 'a \\'],
            'an escaped percent opens no marker' => ['a \\%% b', 'a \\%% b'],
            // Closed comment constructs own their content inside a label.
            'a comment brace keeps its content' => ['a {% x %% y %} z', 'a {% x %% y %} z'],
            'an editorial brace keeps its content' => ['a {# x %% y #} z', 'a {# x %% y #} z'],
            'a marker after a brace is a marker' => ['a {% x %} %% hidden', 'a {% x %}'],
            'an unopened emphasis run keeps no marker' => ['a *b %% c* d', 'a *b'],
            'an unseparated marker stays text' => ['a%%b and 50%%', 'a%%b and 50%%'],
        ];
    }

    /**
     * ASSERTED ON THE STORED LABEL, not on the caption. The claim is where the
     * scan STOPS, and that is the string the parser keeps; the caption renders
     * the label as an inline run now (markup-carve/carve#2572), so reading the
     * answer off escaped HTML would measure the renderer instead.
     */
    #[DataProvider('labelProvider')]
    public function testALabelScanReadsAMarkerWhereTheInlineParserDoes(string $label, string $expected): void
    {
        $source = '::: note [' . $label . "]\nbody\n:::\n";
        $div = CarveConverter::create()->parse($source)->getChildren()[0] ?? null;
        $this->assertInstanceOf(Div::class, $div, $source);
        $this->assertSame($expected, $div->getLabel(), $source);
    }

    /**
     * THE LABEL SLOT TAKES A BALANCED BRACKET RUN, so an UNCLOSED backtick run
     * inside it swallows the closer and the opener line is prose - which is what
     * a link text does with the same bytes (markup-carve/carve#2576). These three
     * shapes were rows of the provider above while the slot ended at the first
     * `]`; they have no label to scan now, so they moved here rather than being
     * dropped. Byte-identical to carve-js at `db48137e8`.
     *
     * @return array<string, array{string}>
     */
    public static function unclosedRunsInALabel(): array
    {
        $tick = '`';

        return [
            'an unclosed span reaches the label end' => ['a ' . $tick . 'x %% y'],
            'an unclosed longer run reaches the label end' => ['a ' . $tick . $tick . $tick . ' %% hidden'],
            'an escaped backtick opens no span, so the next one is unclosed' => [
                'a \\' . $tick . 'x %% hidden' . $tick . ' z',
            ],
        ];
    }

    #[DataProvider('unclosedRunsInALabel')]
    public function testAnUnclosedRunLeavesTheOpenerAsProse(string $label): void
    {
        $source = '::: note [' . $label . "]\nbody\n:::\n";

        $this->assertInstanceOf(
            Paragraph::class,
            CarveConverter::create()->parse($source)->getChildren()[0] ?? null,
            $source,
        );
    }

    /**
     * The label a group extension reads as its tab name is the stripped one, so
     * the rule is applied where the label is captured and not where it renders.
     */
    public function testTheStoredLabelCarriesNoComment(): void
    {
        $document = CarveConverter::create()->parse("::: note [tab one\t%% hidden]\nbody\n:::\n");
        $labels = [];
        foreach ($document->getChildren() as $child) {
            if (method_exists($child, 'getLabel')) {
                $labels[] = $child->getLabel();
            }
        }
        $this->assertSame(['tab one'], $labels);
    }
}
