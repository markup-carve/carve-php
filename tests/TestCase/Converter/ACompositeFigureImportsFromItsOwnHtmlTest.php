<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

/**
 * `<figure class="carve-figure-group">` - the shape this engine's own HTML
 * renderer produces for PART 9 §4c - imports back to `::: figure` source.
 * Own-output round trip only: the structural classes are render-time
 * vocabulary and are dropped, everything else returns on attribute lines.
 */
class ACompositeFigureImportsFromItsOwnHtmlTest extends TestCase
{
    protected function roundTrips(string $source): void
    {
        $converter = new CarveConverter();
        $html = $converter->convert($source);
        $imported = (new HtmlToCarve())->convert($html);

        $this->assertSame(
            $html,
            (new CarveConverter())->convert($imported),
            "importing the rendered HTML must reproduce it; imported source was:\n" . $imported,
        );
    }

    public function testATwoPanelGroupRoundTrips(): void
    {
        $this->roundTrips(
            "{#fig-x .columns-2}\n::: figure\n{#fig-x-a}\n![one](a.png)\n^ (a) One\n\n{#fig-x-b}\n![two](b.png)\n^ (b) Two\n:::\n^ Figure #: Group caption\n",
        );
    }

    public function testAnUncaptionedGroupRoundTrips(): void
    {
        $this->roundTrips("::: figure\n![one](a.png)\n^ (a) One\n:::\n");
    }

    public function testStrayContentInsideThePanelsDivRoundTrips(): void
    {
        $this->roundTrips("::: figure\nShot the same day.\n\n![one](a.png)\n^ (a) One\n:::\n^ Figure #: G\n");
    }

    public function testTheImportedSourceUsesTheFigureFence(): void
    {
        $html = (new CarveConverter())->convert("::: figure\n![one](a.png)\n^ (a) One\n:::\n^ Figure #: G\n");
        $imported = (new HtmlToCarve())->convert($html);

        $this->assertStringContainsString('::: figure', $imported);
        $this->assertStringNotContainsString('carve-figure', $imported, 'structural classes must not leak into the source');
    }
}
