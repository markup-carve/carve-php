<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Extension;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\FencedRenderExtension;
use MarkupCarve\Carve\Extension\IndexExtension;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#1469 and the diagram half of #1468: an element this engine
 * writes carries its own accessible name.
 */
class IndexBackrefAndDiagramNameTest extends TestCase
{
    /**
     * @param string $source
     * @param object $extension
     * @param array<string, string> $labels
     */
    protected function convert(string $source, object $extension, array $labels = []): string
    {
        $converter = new CarveConverter(labels: $labels);
        $converter->addExtension($extension);

        return $converter->convert($source);
    }

    public function testALoneBackLinkIsNamedByLabelAndTerm(): void
    {
        $out = $this->convert("A :index[widget] here.\n\n::: index\n:::\n", new IndexExtension());
        $this->assertStringContainsString(
            '<a href="#idx-widget-1" class="index-backref" aria-label="Back to widget">↩</a>',
            $out,
        );
    }

    /**
     * The whole reason the ordinal is there: an index entry carries ONE
     * back-link per occurrence, so without it a reader meets a row of identical
     * unnamed arrows. PART 9 §16's rule is mirrored - the name is the label plus
     * what the link VISIBLY says - so the ordinal appears in both (WCAG 2.5.3).
     */
    public function testTheKthBackLinkIsNumberedVisiblyAndInItsName(): void
    {
        $out = $this->convert(
            "A :index[widget] and :index[widget] again.\n\n::: index\n:::\n",
            new IndexExtension(),
        );
        $this->assertStringContainsString('aria-label="Back to widget 1">↩<sup>1</sup></a>', $out);
        $this->assertStringContainsString('aria-label="Back to widget 2">↩<sup>2</sup></a>', $out);
    }

    public function testTheLeadingWordsComeFromTheLabelsMap(): void
    {
        $out = $this->convert(
            "A :index[Gerät] hier.\n\n::: index\n:::\n",
            new IndexExtension(),
            ['indexBackref' => 'Zurück zu'],
        );
        $this->assertStringContainsString('aria-label="Zurück zu Gerät"', $out);
    }

    public function testAConstructorArgumentOverridesTheMap(): void
    {
        $out = $this->convert(
            "A :index[widget] here.\n\n::: index\n:::\n",
            new IndexExtension('Explicit'),
            ['indexBackref' => 'Zurück zu'],
        );
        $this->assertStringContainsString('aria-label="Explicit widget"', $out);
        $this->assertStringNotContainsString('Zurück zu', $out);
    }

    public function testATermCarryingMarkupIsAttributeEscapedOnce(): void
    {
        $out = $this->convert(
            "A :index[\"quoted\"] here.\n\n::: index\n:::\n",
            new IndexExtension(),
        );
        $this->assertMatchesRegularExpression('/aria-label="[^"]*"/', $out);
        $this->assertStringNotContainsString('aria-label=""quoted', $out);
    }

    public function testADiagramFenceIsAnImageWithAName(): void
    {
        $out = $this->convert("``` mermaid\ngraph TD;\n```\n", FencedRenderExtension::mermaid());
        $this->assertStringContainsString('<pre class="mermaid" role="img" aria-label="mermaid">', $out);
    }

    /**
     * An `img` with no accessible name is SKIPPED, which is worse than the
     * source being read out - so an empty label removes the role as well.
     */
    public function testRoleAndNameAreWrittenTogetherOrNotAtAll(): void
    {
        $out = $this->convert(
            "``` mermaid\ngraph TD;\n```\n",
            new FencedRenderExtension('mermaid', label: ''),
        );
        $this->assertStringContainsString('<pre class="mermaid">', $out);
        $this->assertStringNotContainsString('role="img"', $out);
    }

    /**
     * The author who cared enough to NAME the fence is exactly the one who must
     * not lose the role: without it the source is still announced as prose.
     */
    public function testAnAuthoredNameStillGetsTheRole(): void
    {
        $out = $this->convert(
            "{aria-label=\"Deploy flow\"}\n``` mermaid\ngraph TD;\n```\n",
            FencedRenderExtension::mermaid(),
        );
        $this->assertStringContainsString('aria-label="Deploy flow"', $out);
        $this->assertStringContainsString('role="img"', $out);
        $this->assertStringNotContainsString('aria-label="mermaid"', $out);
    }

    public function testAnAuthoredRoleIsKept(): void
    {
        $out = $this->convert(
            "{role=\"none\"}\n``` mermaid\ngraph TD;\n```\n",
            FencedRenderExtension::mermaid(),
        );
        $this->assertStringContainsString('role="none"', $out);
        $this->assertStringNotContainsString('role="img"', $out);
    }
}
