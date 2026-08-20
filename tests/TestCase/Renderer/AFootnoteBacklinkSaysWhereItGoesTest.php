<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §16 + §16a (markup-carve/carve#1455, markup-carve/carve#1456).
 *
 * `role="doc-backlink"` was already right and the accessible NAME was the `↩`
 * glyph, so a screen reader announced "leftwards arrow with hook" or skipped
 * the link: correct semantics, no way to know where it goes.
 */
class AFootnoteBacklinkSaysWhereItGoesTest extends TestCase
{
    protected function html(string $source, array $labels = []): string
    {
        return (new CarveConverter(labels: $labels))->convert($source);
    }

    /**
     * @return void
     */
    public function testALoneBacklinkIsNamedByTheLabelAlone(): void
    {
        $this->assertStringContainsString(
            '<a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a>',
            $this->html("Text[^a]\n\n[^a]: Note body.\n"),
        );
    }

    /**
     * The number is the REFERENCE ORDINAL, matching the visible `<sup>k</sup>`
     * (WCAG 2.5.3). The note number appears nowhere in this link's text.
     *
     * @return void
     */
    public function testTheKthOfSeveralTakesWhatItVisiblySays(): void
    {
        $html = $this->html("See[^a] and again[^a].\n\n[^a]: One note, two refs.\n");

        $this->assertStringContainsString(
            '<a href="#fnref1" role="doc-backlink" aria-label="Back to reference 1">↩<sup>1</sup></a>',
            $html,
        );
        $this->assertStringContainsString(
            '<a href="#fnref1-2" role="doc-backlink" aria-label="Back to reference 2">↩<sup>2</sup></a>',
            $html,
        );
    }

    /**
     * @return void
     */
    public function testTheLabelsOptionCarriesTheString(): void
    {
        $html = $this->html("Text[^a]\n\n[^a]: n\n", ['footnoteBacklink' => 'Zurück zur Fußnote']);

        $this->assertStringContainsString('aria-label="Zurück zur Fußnote"', $html);
        $this->assertStringNotContainsString('Back to reference', $html);
    }

    /**
     * A label is TEXT, unlike a symbols-map value: a host reading its strings
     * from a translation catalog must not be handing the renderer an injection
     * vector.
     *
     * @return void
     */
    public function testTheLabelIsEscapedRatherThanEmittedRaw(): void
    {
        $html = $this->html("Text[^a]\n\n[^a]: n\n", ['footnoteBacklink' => '"><script>alert(1)</script>']);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&quot;&gt;&lt;script&gt;', $html);
    }

    /**
     * @return void
     */
    public function testTheEnglishDefaultStandsWhenNoLabelIsGiven(): void
    {
        $this->assertSame('Back to reference', HtmlRenderer::LABEL_DEFAULTS['footnoteBacklink']);
    }
}
