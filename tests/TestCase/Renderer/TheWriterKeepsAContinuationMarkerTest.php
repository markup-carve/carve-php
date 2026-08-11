<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Test\LegacyCarveConverter as CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

/**
 * The writer keeps a `+` continuation marker before an attached paragraph
 * (carve#861).
 *
 * §17 L3 attaches the following block to the item, so `- a` / `+` / `b` is an
 * item holding TWO blocks. The writer dropped the marker and indented `b`,
 * which re-parses as a LAZY CONTINUATION of the paragraph above it (§10 I2) -
 * one block where the author wrote two, so PART 11 §1's
 * `to_html(fmt(x)) == to_html(x)` failed.
 *
 * A PARAGRAPH is the only attached kind this reaches. A fence, quote, heading,
 * table, div or thematic break cannot fold into an open paragraph, so indenting
 * them into the item is a different spelling of the same document. The corpus
 * pinned exactly those harmless kinds, which is why nothing caught it and why
 * all three engines shared the defect rather than diverging.
 */
class TheWriterKeepsAContinuationMarkerTest extends TestCase
{
    protected function fmt(string $source): string
    {
        return (new CarveConverter(renderer: new CarveRenderer()))->convert($source);
    }

    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    protected function roundTrips(string $source): bool
    {
        return $this->html($this->fmt($source)) === $this->html($source);
    }

    public function testAParagraphAttachedAtTheTopLevelSurvivesFmt(): void
    {
        $source = "- a\n+\nb\n\nx\n";

        $this->assertTrue($this->roundTrips($source), $this->fmt($source));
    }

    public function testAParagraphAttachedInsideANestedItemSurvivesFmt(): void
    {
        // The marker sits at the ITEM's marker column, which is not column 0.
        $source = "- o\n  - a\n  +\n  b\n\nx\n";

        $this->assertTrue($this->roundTrips($source), $this->fmt($source));
    }

    public function testTheMarkerIsWrittenBackRatherThanTheTextIndented(): void
    {
        // The bytes, because the assertions above pass for any spelling whose
        // HTML happens to match - and the point is that the marker survives.
        $this->assertStringContainsString("\n+\n", $this->fmt("- a\n+\nb\n\nx\n"));
    }

    public function testTheWrittenFormIsAFixedPoint(): void
    {
        $once = $this->fmt("- a\n+\nb\n\nx\n");

        $this->assertSame($once, $this->fmt($once));
    }

    public function testTheAttachedKindsThatNeverFoldedAreLeftAlone(): void
    {
        // The control. These already round-tripped by indenting the block into
        // the item, and a fix that emitted `+` everywhere would change them all.
        foreach (["```\nb\n```", '> b', '# b', "::: note\nb\n:::", '---'] as $block) {
            $source = "- a\n+\n" . $block . "\n\nx\n";
            $this->assertTrue($this->roundTrips($source), $source);
            $this->assertStringNotContainsString("\n+\n", $this->fmt($source), $source);
        }
    }

    public function testALooseTwoParagraphItemIsLeftAlone(): void
    {
        // The boundary: a LOOSE item separates its blocks with a blank line and
        // needs no marker. Emitting one would change the item's looseness.
        $source = "- a\n\n  b\n\nx\n";

        $this->assertTrue($this->roundTrips($source), $this->fmt($source));
        $this->assertStringNotContainsString("\n+\n", $this->fmt($source));
    }
}
