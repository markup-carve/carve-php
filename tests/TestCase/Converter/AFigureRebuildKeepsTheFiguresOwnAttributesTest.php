<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlImportDiagnostic;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A REBUILT FIGURE CARRIES THE FIGURE'S OWN ATTRIBUTES.
 *
 * `<figure id="f" class="c">` around an image, a quote, a fence or a table
 * rebuilds as the target plus a `^ ` caption line, and the caption line makes a
 * figure of that target again. A block attribute line above it lands on the
 * rebuilt node, so `{#f .c}` then `![A](a.png)` then `^ Cap` re-renders as
 * `<figure id="f" class="c">`. This engine wrote no such line: it dropped the
 * attributes and declared each one as `attribute-dropped` (carve-php#1728).
 *
 * A DECLARED LOSS IS A CEILING, NOT A LICENCE - `docs/html-import.md` says so -
 * and here the attributes had somewhere to go. carve-js and carve-rs both write
 * the line, byte for byte, on every arm and in every mode, which is the proof
 * that the target can carry them.
 *
 * ONE CAUSE, NOT ONE PER ARM. The arms differ in what they write for the
 * TARGET, never in whether the figure around it carried attributes, so the line
 * is written once for all of them where they converge.
 *
 * THE ROWS ARE HALF THE CLAIM. An `attribute-dropped` row beside an attribute
 * that arrived is a false statement about a success, so every test here asserts
 * the report as well as the source. The report asks the emitted DOCUMENT
 * whether the attribute came back, so the rows follow the writer without
 * anyone maintaining a second list - which is why an attribute that still
 * genuinely loses, like a figure id a target id outranks, still reports.
 */
class AFigureRebuildKeepsTheFiguresOwnAttributesTest extends TestCase
{
    /**
     * @return list<string>
     */
    protected function codes(string $html, string $mode = 'roundtrip'): array
    {
        return array_map(
            static fn (HtmlImportDiagnostic $diagnostic): string => $diagnostic->code,
            (new HtmlToCarve(importMode: $mode))->convertWithReport($html)->diagnostics,
        );
    }

    protected function carve(string $html, string $mode = 'roundtrip'): string
    {
        return (new HtmlToCarve(importMode: $mode))->convert($html);
    }

    /**
     * Every rebuild arm, in every mode. The mode decides what happens to shapes
     * with no spelling; it has never decided whether an attribute is written,
     * and both sibling engines write the same bytes in all three.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function armsAndModes(): array
    {
        $arms = [
            'image' => [
                '<figure id="f" class="c"><img src="a.png" alt="A"><figcaption>Cap</figcaption></figure>',
                "{#f .c}\n![A](a.png)\n^ Cap\n",
            ],
            'quote' => [
                '<figure id="f" class="c"><blockquote><p>q</p></blockquote><figcaption>Cap</figcaption></figure>',
                "{#f .c}\n> q\n^ Cap\n",
            ],
            'fence' => [
                '<figure id="f" class="c"><pre><code>x</code></pre><figcaption>Cap</figcaption></figure>',
                "{#f .c}\n```\nx\n```\n^ Cap\n",
            ],
            'table' => [
                '<figure id="f" class="c"><table><tr><td>a</td></tr></table><figcaption>Cap</figcaption></figure>',
                "{#f .c}\n| a |\n^ Cap\n",
            ],
        ];

        $cases = [];
        foreach ($arms as $arm => [$html, $expected]) {
            foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
                $cases[$arm . ' in ' . $mode] = [$html, $expected, $mode];
            }
        }

        return $cases;
    }

    #[DataProvider('armsAndModes')]
    public function testEveryArmWritesTheFiguresAttributeLine(string $html, string $expected, string $mode): void
    {
        $this->assertSame($expected, $this->carve($html, $mode));
    }

    /**
     * THE ROW HAD TO GO WITH IT. `attribute-dropped` about an id that is in the
     * output is the failure this repository rates worst, and the table arm's
     * `structure-unspellable` is the only row any of these shapes still owes.
     *
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function armsAndTheirRemainingRows(): array
    {
        return [
            'image' => [
                '<figure id="f" class="c"><img src="a.png" alt="A"><figcaption>Cap</figcaption></figure>',
                [],
            ],
            'quote' => [
                '<figure id="f" class="c"><blockquote><p>q</p></blockquote><figcaption>Cap</figcaption></figure>',
                [],
            ],
            'fence' => [
                '<figure id="f" class="c"><pre><code>x</code></pre><figcaption>Cap</figcaption></figure>',
                [],
            ],
            'table' => [
                '<figure id="f" class="c"><table><tr><td>a</td></tr></table><figcaption>Cap</figcaption></figure>',
                ['structure-unspellable'],
            ],
        ];
    }

    /**
     * @param string $html
     * @param list<string> $expected
     */
    #[DataProvider('armsAndTheirRemainingRows')]
    public function testTheDroppedAttributeRowsAreGone(string $html, array $expected): void
    {
        $this->assertSame($expected, $this->codes($html));
    }

    /**
     * THE DOCUMENT SETTLES IT. Source that spells an id is not the claim; the
     * claim is that the reader's `<figure>` has it back.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function armsAndTheirRenderedElement(): array
    {
        return [
            'image' => [
                '<figure id="f" class="c"><img src="a.png" alt="A"><figcaption>Cap</figcaption></figure>',
                '<figure id="f" class="c">',
            ],
            'quote' => [
                '<figure id="f" class="c"><blockquote><p>q</p></blockquote><figcaption>Cap</figcaption></figure>',
                '<figure id="f" class="c">',
            ],
            'fence' => [
                '<figure id="f" class="c"><pre><code>x</code></pre><figcaption>Cap</figcaption></figure>',
                '<figure id="f" class="c">',
            ],
            // The table arm rebuilds as `<table><caption>`, so the attributes
            // land on the TABLE. That is the ceiling `structure-unspellable`
            // declares, and it is where both sibling engines put them too.
            'table' => [
                '<figure id="f" class="c"><table><tr><td>a</td></tr></table><figcaption>Cap</figcaption></figure>',
                '<table id="f" class="c">',
            ],
        ];
    }

    #[DataProvider('armsAndTheirRenderedElement')]
    public function testTheAttributesComeBackOnTheRenderedElement(string $html, string $element): void
    {
        $this->assertStringContainsString($element, (new CarveConverter())->convert($this->carve($html)));
    }

    /**
     * NOT AN ID-AND-CLASS FEATURE. The line is the element's whole attribute
     * list, so anything the writer can spell rides it.
     */
    public function testAnyAttributeRidesTheLineNotJustIdAndClass(): void
    {
        $html = '<figure data-x="1" title="t"><img src="a.png" alt="A"><figcaption>Cap</figcaption></figure>';

        $this->assertSame("{data-x=1 title=t}\n![A](a.png)\n^ Cap\n", $this->carve($html));
        $this->assertSame([], $this->codes($html));
        $this->assertStringContainsString(
            '<figure data-x="1" title="t">',
            (new CarveConverter())->convert($this->carve($html)),
        );
    }

    /**
     * A FIGURE WITH NO ATTRIBUTES WRITES NO LINE. An empty `{}` would be text,
     * and a blank line before the target would detach the caption.
     */
    public function testAFigureWithNoAttributesIsUnchanged(): void
    {
        $html = '<figure><img src="a.png" alt="A"><figcaption>Cap</figcaption></figure>';

        $this->assertSame("![A](a.png)\n^ Cap\n", $this->carve($html));
        $this->assertSame([], $this->codes($html));
    }

    /**
     * NOTHING TO HANG THEM ON. A CAPTION IS WHAT MAKES A FIGURE (PART 9 §4b),
     * so an uncaptioned wrapper unwraps and the target is a bare image or
     * quote. A block attribute line there would land on the TARGET and say the
     * document carried an id it never carried, so the attributes are dropped
     * and the drop is declared - which is what both sibling engines do.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function uncaptionedFigures(): array
    {
        return [
            'image' => ['<figure id="f" class="c"><img src="a.png" alt="A"></figure>', "![A](a.png)\n"],
            'quote' => ['<figure id="f" class="c"><blockquote><p>q</p></blockquote></figure>', "> q\n"],
        ];
    }

    #[DataProvider('uncaptionedFigures')]
    public function testAnUncaptionedFigureStillDropsThemAndSaysSo(string $html, string $expected): void
    {
        $this->assertSame($expected, $this->carve($html));
        $this->assertSame(
            ['element-unwrapped', 'attribute-dropped', 'attribute-dropped'],
            $this->codes($html),
        );
    }

    /**
     * A TARGET THAT NAMES ITSELF KEEPS ITS OWN SPELLING. An image writes its
     * attributes INLINE, so the two never meet and both come back whole.
     */
    public function testAnImageKeepsItsOwnAttributesBesideTheFigures(): void
    {
        $html = '<figure id="f" class="c"><img id="g" class="d" src="a.png" alt="A"><figcaption>Cap</figcaption></figure>';

        $this->assertSame("{#f .c}\n![A](a.png){#g .d}\n^ Cap\n", $this->carve($html));
        $this->assertSame([], $this->codes($html));
        $this->assertStringContainsString(
            '<figure id="f" class="c">',
            (new CarveConverter())->convert($this->carve($html)),
        );
    }

    /**
     * THE COLLISION HAS A RULE AND IT IS THE PARSER'S. A quote writes its own
     * attributes on a block line too, so two lines stack and the parser merges
     * them: the LAST id wins, the classes union. The figure's id is what loses,
     * and the report says so because it asks the emitted document rather than a
     * list of names - so the ceiling stays declared even where the collision,
     * not the writer, is what ate the value.
     *
     * Both sibling engines write these same two lines; neither reports the id
     * that lost.
     */
    public function testWhenAQuoteNamesItselfTheStackedLinesMergeAndTheLoserIsDeclared(): void
    {
        $html = '<figure id="f" class="c"><blockquote id="g" class="d"><p>q</p></blockquote>'
            . '<figcaption>Cap</figcaption></figure>';

        $this->assertSame("{#f .c}\n{#g .d}\n> q\n^ Cap\n", $this->carve($html));
        $this->assertSame(['attribute-dropped'], $this->codes($html));
        $this->assertStringContainsString(
            '<figure id="g" class="c d">',
            (new CarveConverter())->convert($this->carve($html)),
        );
    }
}
