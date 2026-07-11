<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Extension;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\GlossaryExtension;
use MarkupCarve\Carve\Extension\HeadingNumbersExtension;
use MarkupCarve\Carve\Extension\IndexExtension;
use MarkupCarve\Carve\Extension\TocPlacementExtension;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for extension cross-impl divergences fixed in
 * carve-php #305 / #306 / #307.
 */
class ExtensionDifferentialCoverageTest extends TestCase
{
    public function testTocEntryTextExcludesSectionNumber(): void
    {
        $c = new CarveConverter();
        $c->addExtension(new HeadingNumbersExtension());
        $c->addExtension(new TocPlacementExtension());
        $out = $c->convert("::: toc\n:::\n\n# Alpha\n\n## Beta\n");
        $this->assertStringContainsString('<a href="#Alpha">Alpha</a>', $out);
        // The nav entry must be the bare title, never the numbered form.
        $this->assertStringNotContainsString('<a href="#Alpha">1 Alpha</a>', $out);
    }

    public function testIndexMarkerExcludedFromHeadingSlug(): void
    {
        $c = new CarveConverter();
        $c->addExtension(new IndexExtension());
        $out = $c->convert("# Title :index[term]\n\n::: index\n:::\n");
        $this->assertStringContainsString('id="Title"', $out);
        $this->assertStringNotContainsString('id="Title-term"', $out);
    }

    public function testGlossaryKeepsWrapperOnNonDefinitionListBody(): void
    {
        $c = new CarveConverter();
        $c->addExtension(new GlossaryExtension());
        $out = $c->convert("::: glossary\nnot a deflist\n:::\n");
        $this->assertStringContainsString('<div class="glossary">', $out);
        $this->assertStringContainsString('<p>not a deflist</p>', $out);
    }
}
