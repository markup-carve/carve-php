<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Extension;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\CodeGroupExtension;
use MarkupCarve\Carve\Extension\TabsExtension;
use MarkupCarve\Carve\Renderer\RenderMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Extensions §2.5 states the static flatten once, for both constructs: "each
 * panel as a `<section>` headed by its `[label]`", and
 * `docs/graceful-degradation.md` calls that label a caption HEADING.
 *
 * `CodeGroupExtension` wrote a `<p>` instead, unindented, where
 * `TabsExtension` beside it - and carve-js for both - writes an indented
 * `<h3>`. The label was the one thing a reader of the PDF or the archival page
 * had to tell the panels apart, and a paragraph keeps it out of the document
 * outline, which is the point of surfacing it in a medium that cannot click
 * (markup-carve/carve-php#1535).
 *
 * Stated as a property of BOTH extensions rather than as one more expectation
 * in `StaticRenderModeTest`, because the defect was that the two constructs had
 * drifted apart with nothing asserting they should not.
 */
class AStaticPanelLabelIsAHeadingInBothExtensionsTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: object, 2: string}>
     */
    public static function staticPanelProvider(): array
    {
        return [
            'tabs' => [
                ":::: tabs\n::: tab [First]\nx\n:::\n::::\n",
                new TabsExtension(),
                'tabs-label',
            ],
            'code group' => [
                "::: code-group\n``` php [Install]\ncomposer require x\n```\n:::\n",
                new CodeGroupExtension(),
                'code-group-label',
            ],
        ];
    }

    protected function staticHtml(string $source, object $extension): string
    {
        $converter = new CarveConverter(mode: RenderMode::STATIC);
        $converter->addExtension($extension);

        return $converter->convert($source);
    }

    #[DataProvider('staticPanelProvider')]
    public function testTheLabelIsAHeading(string $source, object $extension, string $labelClass): void
    {
        $html = $this->staticHtml($source, $extension);

        $this->assertStringContainsString('<h3 class="' . $labelClass . '">', $html);
        $this->assertStringNotContainsString('<p class="' . $labelClass . '">', $html);
    }

    /**
     * The section is indented, in both. Two spaces is what this engine already
     * wrote for tabs and what carve-js writes for both, so the code-group
     * panels being flush left was the same drift one line further out.
     */
    #[DataProvider('staticPanelProvider')]
    public function testTheSectionIsIndented(string $source, object $extension, string $labelClass): void
    {
        $html = $this->staticHtml($source, $extension);

        $this->assertStringContainsString("\n  <section class=", $html);
        $this->assertStringContainsString("\n  </section>\n", $html);
        $this->assertStringNotContainsString("\n<section class=", $html);
    }

    /**
     * And no interaction survives - the half of the flatten that was never in
     * question, asserted so the two above cannot be satisfied by an output that
     * forgot to flatten at all.
     */
    #[DataProvider('staticPanelProvider')]
    public function testNoInteractionSurvives(string $source, object $extension, string $labelClass): void
    {
        $html = $this->staticHtml($source, $extension);

        $this->assertStringNotContainsString('<input', $html);
        $this->assertStringNotContainsString('role="tab"', $html);
        $this->assertStringContainsString('<section class="', $html);
    }
}
