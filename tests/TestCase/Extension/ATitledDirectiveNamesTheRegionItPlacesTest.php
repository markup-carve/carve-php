<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Extension;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\GlossaryExtension;
use MarkupCarve\Carve\Extension\TocPlacementExtension;
use PHPUnit\Framework\TestCase;

/**
 * CARVE-P9-072: a titled directive names the element it places, and the two
 * tokens are that element's first children - except where the element admits no
 * paragraph, which is the `<dl>` a glossary places and the `<ul>` an index does.
 */
class ATitledDirectiveNamesTheRegionItPlacesTest extends TestCase
{
    public function testTheTocNavTakesTheTitleAsItsName(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new TocPlacementExtension());
        $out = $converter->convert("::: toc \"Contents\" [T]\n:::\n\n# Intro\n");

        $this->assertStringContainsString(
            "<nav class=\"toc\" aria-labelledby=\"adm-1\">\n"
                . "<p class=\"admonition-title\" id=\"adm-1\">Contents</p>\n"
                . "<p class=\"div-label\">T</p>\n<ul>",
            $out,
        );
        $this->assertStringNotContainsString('aria-label="Table of contents"', $out);
    }

    public function testAnUntitledNavKeepsTheLabelsMapName(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new TocPlacementExtension());
        $out = $converter->convert("::: toc\n:::\n\n# Intro\n");

        $this->assertStringContainsString('<nav class="toc" aria-label="Table of contents">', $out);
        $this->assertStringNotContainsString('admonition-title', $out);
    }

    public function testAnAuthorWrittenNameWinsAndMintsNoId(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new TocPlacementExtension());
        $out = $converter->convert("{aria-label=\"Mine\"}\n::: toc \"Contents\"\n:::\n\n# Intro\n");

        $this->assertStringContainsString('aria-label="Mine"', $out);
        $this->assertStringContainsString('<p class="admonition-title">Contents</p>', $out);
        $this->assertStringNotContainsString('aria-labelledby', $out);
    }

    public function testTheNavTakesItsIdBeforeItsOwnChildren(): void
    {
        // Document order, not render order: the extension reads the children's
        // HTML, which renders them, so the id has to be reserved first.
        $converter = new CarveConverter();
        $converter->addExtension(new TocPlacementExtension());
        $out = $converter->convert("::: toc \"Contents\"\n::: note \"Inner\"\nx\n:::\n:::\n\n# H\n");

        $this->assertStringContainsString('<nav class="toc" aria-labelledby="adm-1">', $out);
        $this->assertStringContainsString('<p class="admonition-title" id="adm-2">Inner</p>', $out);
    }

    public function testAnUnreferencedDefinitionDoesNotSendTheMarkerDownThePlacementPath(): void
    {
        // The definition renders nothing, so no section arrives; a marker that
        // took the placement path on the strength of the inline note inside it
        // lost its title and label with the swept sentinel.
        $out = (new CarveConverter())->convert("::: footnotes \"Notes\" [End]\n:::\n\n[^a]: hidden ^[inline]\n");

        $this->assertStringContainsString('<p class="admonition-title">Notes</p>', $out);
        $this->assertStringContainsString('<p class="div-label">End</p>', $out);
        $this->assertStringNotContainsString('doc-endnotes', $out);
    }

    public function testAGlossaryKeepsBothTokensBeforeItsList(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new GlossaryExtension());
        $out = $converter->convert("::: glossary \"Gloss\" [G]\n:: Term\n: def\n:::\n");

        $this->assertStringContainsString(
            "<p class=\"admonition-title\">Gloss</p>\n<p class=\"div-label\">G</p>\n<dl class=\"glossary\">",
            $out,
        );
        $this->assertStringNotContainsString('aria-labelledby', $out);
    }
}
