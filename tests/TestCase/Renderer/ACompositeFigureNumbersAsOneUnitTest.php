<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §4c numbering (markup-carve/carve#1122): the group is ONE
 * figure-numbering unit. A `#` in the GROUP caption draws exactly one number
 * from its label's sequence; PANELS draw nothing from the document sequence -
 * a `#` in a panel caption stays LITERAL - and a panel with an id resolves
 * `</#id>` with the group's number plus a letter by panel order.
 */
class ACompositeFigureNumbersAsOneUnitTest extends TestCase
{
    protected function convert(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    public function testTheGroupDrawsOneNumberFromTheSharedSequence(): void
    {
        // Corpus 318-composite-figures-2 pins the full bytes; this pins the
        // COUNT: a two-panel group between two plain figures advances the
        // Figure sequence by one, not by three.
        $html = $this->convert(
            "![a](a.png)\n^ Figure #: First\n\n"
            . "::: figure\n![b](b.png)\n^ (a) B\n\n![c](c.png)\n^ (b) C\n:::\n^ Figure #: Group\n\n"
            . "![d](d.png)\n^ Figure #: Last\n",
        );

        $this->assertStringContainsString('<figcaption>Figure 1: First</figcaption>', $html);
        $this->assertStringContainsString('<figcaption>Figure 2: Group</figcaption>', $html);
        $this->assertStringContainsString('<figcaption>Figure 3: Last</figcaption>', $html);
    }

    public function testAPanelIdResolvesWithTheGroupNumberAndALetter(): void
    {
        $html = $this->convert(
            "{#g}\n::: figure\n{#p-a}\n![a](a.png)\n^ (a) A\n\n{#p-b}\n![b](b.png)\n^ (b) B\n:::\n^ Figure #: Group\n\n"
            . "See </#g>, </#p-a> and </#p-b>.\n",
        );

        $this->assertStringContainsString('<a href="#g">Figure 1</a>', $html);
        $this->assertStringContainsString('<a href="#p-a">Figure 1a</a>', $html);
        $this->assertStringContainsString('<a href="#p-b">Figure 1b</a>', $html);
    }

    public function testTheLetterCountsPanelsNotChildren(): void
    {
        // Stray content between the panels occupies a child slot but no panel
        // slot, so the second panel is still "b".
        $html = $this->convert(
            "::: figure\n{#p-a}\n![a](a.png)\n^ (a) A\n\nA note between.\n\n{#p-b}\n![b](b.png)\n^ (b) B\n:::\n^ Figure #: G\n\n"
            . "See </#p-b>.\n",
        );

        $this->assertStringContainsString('<a href="#p-b">Figure 1b</a>', $html);
    }

    public function testANumberPlaceholderInAPanelCaptionStaysLiteral(): void
    {
        // Panels are not sequence units; the visible failure this language
        // prefers to a silent one (§4c).
        $html = $this->convert("::: figure\n![a](a.png)\n^ Panel # here\n:::\n^ Figure #: G\n");

        $this->assertStringContainsString('<figcaption>Panel # here</figcaption>', $html);
        $this->assertStringContainsString('<figcaption>Figure 1: G</figcaption>', $html);
    }

    public function testAnUnnumberedGroupRegistersNoPanelCrossrefText(): void
    {
        // Panel ids register only when the group itself drew a number, so the
        // crossref stays unresolved - exactly what `</#id>` to an id on an
        // uncaptioned plain figure produces today (§4c).
        $html = $this->convert(
            "::: figure\n{#p-a}\n![a](a.png)\n^ (a) A\n:::\n\nSee </#p-a>.\n",
        );

        $this->assertStringContainsString('<p>See &lt;/#p-a&gt;.</p>', $html);
        $this->assertStringNotContainsString('Figure', $html);
    }

    public function testTheGroupCountsInItsOwnLabelSequenceOnly(): void
    {
        // Label sequences stay per-label: a table before the group does not
        // advance the Figure sequence the group draws from.
        $html = $this->convert(
            "| a |\n|---|\n^ Table #: T\n\n::: figure\n![b](b.png)\n^ (a) B\n:::\n^ Figure #: G\n",
        );

        $this->assertStringContainsString('<caption>Table 1: T</caption>', $html);
        $this->assertStringContainsString('<figcaption>Figure 1: G</figcaption>', $html);
    }
}
