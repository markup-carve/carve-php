<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Extension\AdmonitionExtension;
use MarkupCarve\Carve\Extension\CodeGroupExtension;
use MarkupCarve\Carve\Extension\FencedRenderExtension;
use MarkupCarve\Carve\Extension\TabsExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Carve -> HTML -> Carve with NO sidecar: the importer reconstructs the source
 * from the rendered HTML alone.
 *
 * `RoundTripThroughTheSidecarTest` runs its whole population with
 * `roundTripMode` and `trustedRoundTrip` on, so the HTML carries the original
 * Carve in `data-djot-src` and the importer reads it back verbatim. That proves
 * the attribute survives a render, which is real - but it is not the claim the
 * class name makes, and for a construct that emits the attribute the assertion
 * is a memo lookup rather than a reconstruction (markup-carve/carve-php#1603).
 *
 * MEASURED at carve-php `78eff68`, running that class with both flags off: of
 * its 80 reachable round trips, 22 come back unchanged and 58 do not. This
 * class is where the two claims are separated, and it pins BOTH sides - because
 * the 58 are not defects. HTML import is lossy by design and reports what it
 * drops; the point of writing the degradations down is that nothing anywhere
 * recorded them, so any of them could move without a single test noticing.
 */
class TheHtmlRoundTripWithoutTheSidecarTest extends TestCase
{
    private CarveConverter $converter;

    private HtmlToCarve $importer;

    protected function setUp(): void
    {
        // NEITHER flag. A converter in `roundTripMode` writes the source into
        // the HTML and a `trustedRoundTrip` importer reads it back, which is
        // the memo this class exists to do without.
        $this->converter = new CarveConverter();
        $this->converter->addExtension(new CodeGroupExtension());
        $this->converter->addExtension(new TabsExtension());
        $this->converter->addExtension(FencedRenderExtension::mermaid());
        $this->converter->addExtension(new AdmonitionExtension());
        $this->importer = new HtmlToCarve();
    }

    /**
     * Sources whose HTML carries everything needed to write them again.
     *
     * @return array<string, array{0: string}>
     */
    public static function reconstructedProvider(): array
    {
        return [
            'a fenced block with no language' => ["```\nplain text here\n```\n"],
            'a heading with an authored id' => ["{#custom-id}\n# My Heading\n"],
            'a heading with an id and a class' => ["{#my-id .special}\n## Section Title\n"],
            'a verbatim span holding backticks' => ["Use `` `code` `` for inline code.\n"],
            'a verbatim span opening with a backtick' => ["Use `` `start`` here.\n"],
            'nested lists' => ["- First\n\n  - Nested one\n\n  - Nested two\n\n- Second\n"],
            'nested ordered lists' => ["1. First\n\n   1. Nested one\n\n   2. Nested two\n\n2. Second\n"],
            'a definition list' => [":: Term\n:  Definition text here\n"],
            'a numbered footnote' => ["Text[^1].\n\n[^1]: The note.\n"],
            'a line block' => ["::: |\nLine one\nLine two\nLine three\n:::\n"],
            'a line block with attributes' => ["{.poem}\n::: |\nRoses are red\nViolets are blue\n:::\n"],
            'an escaped backslash' => ["A backslash: \\\\ here.\n"],
            // The renderer's own slug IS case-preserving, so an authored id
            // differing from it only in case is authored and must be kept.
            // Dropping it regenerated `id="Methods"` and broke every `#methods`
            // anchor (carve-php#1289 in the shape it left open).
            'a heading id the renderer would not regenerate' => ["{#methods}\n## Methods\n"],
            'a heading id equal to the generated slug' => ["## Methods\n"],
        ];
    }

    /**
     * COMPARED WHOLE, not trimmed. A leading blank line and a trailing blank
     * run are source, and a comparison that cannot see them is not a source
     * comparison.
     */
    #[DataProvider('reconstructedProvider')]
    public function testASourceTheHtmlFullyCarriesComesBackUnchanged(string $carve): void
    {
        $this->assertSame($carve, $this->importer->convert($this->converter->convert($carve)));
    }

    /**
     * Sources the HTML does NOT fully carry, with what comes back instead.
     *
     * Each pair names the thing that went missing. None of them is a bug: the
     * rendered HTML has no way to say which of two spellings the author used,
     * so the importer writes the one the canonical writer writes. What was
     * missing is any record of it - so a change here is a change to what the
     * importer promises, and it now has to be made deliberately.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function degradedProvider(): array
    {
        return [
            // A reference link and an inline link render to the same anchor.
            'a reference link' => [
                "See [the documentation][docs] for more info.\n\n[docs]: https://example.com/docs\n",
                "See [the documentation](https://example.com/docs) for more info.\n",
            ],
            'a reference image' => [
                "![Alt text][img]\n\n[img]: https://example.com/pic.png\n",
                "![Alt text](https://example.com/pic.png)\n",
            ],
            // An autolink is an anchor whose text happens to be its href.
            'an autolink' => [
                "Visit <https://example.com> for more info.\n",
                "Visit [https://example.com](https://example.com) for more info.\n",
            ],
            'an email autolink' => [
                "Mail <a@example.com> today.\n",
                "Mail [a@example.com](mailto:a@example.com) today.\n",
            ],
            // The rendered marker is the RESOLVED number; the author's label
            // never reaches the HTML.
            'a named footnote' => [
                "Text[^note].\n\n[^note]: Named footnote.\n",
                "Text[^1].\n\n[^1]: Named footnote.\n",
            ],
            // An abbreviation definition is consumed at render time - what is
            // left is the expansion on the occurrence.
            'an abbreviation definition' => [
                "*[HTML]: Hypertext Markup Language\n\nThe HTML spec defines the standard.\n",
                "The [HTML]{abbr=\"Hypertext Markup Language\"} spec defines the standard.\n",
            ],
            // The admonition shorthand renders to the same container the
            // explicit spelling does.
            'the admonition shorthand' => [
                "::: note\nContent here.\n:::\n",
                "{.note}\n::: admonition \"Note\"\nContent here.\n:::\n",
            ],
            // Three spellings of alignment reach one HTML; the importer writes
            // the cell-marker one (markup-carve/carve#1344).
            'a table alignment row' => [
                "| Left | Center | Right |\n|:--|:--:|--:|\n| L | C | R |\n",
                "|=< Left |=~ Center |=> Right |\n| L | C | R |\n",
            ],
            // Raw HTML is HTML once rendered, so it imports as what it MEANS.
            'a raw inline break' => [
                "Text `<br>`{=html} more.\n",
                "Text \\\nmore.\n",
            ],
            // The writer escapes per opener occurrence, not per unit (#1533),
            // so a closer with nothing to close needs no backslash.
            'a doubly escaped run' => [
                "This is \\*not bold\\* text.\n",
                "This is \\*not bold* text.\n",
            ],
        ];
    }

    #[DataProvider('degradedProvider')]
    public function testASourceTheHtmlCannotCarryComesBackAsItsRecordedDegradation(
        string $carve,
        string $degraded,
    ): void {
        $this->assertSame($degraded, $this->importer->convert($this->converter->convert($carve)));
    }

    /**
     * A degradation does not degrade FURTHER. Importing the degraded source's
     * own HTML gives the degraded source back, so the loss happens once and
     * a document does not drift a little more on each pass through HTML.
     */
    #[DataProvider('degradedProvider')]
    public function testADegradationIsItselfAFixedPoint(string $carve, string $degraded): void
    {
        $this->assertSame($degraded, $this->importer->convert($this->converter->convert($degraded)));
    }
}
