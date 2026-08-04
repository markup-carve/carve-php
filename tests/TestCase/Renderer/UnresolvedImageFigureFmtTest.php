<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * An unresolved reference image inside a figure is written back as the source
 * the author typed.
 *
 * `renderFigure()` called `renderImage()` straight through, so `![a][nope]`
 * came out `![a]()` - the reference label gone and the destination empty. The
 * re-parse was then a different document, which broke PART 11 §1's invariant
 * inside this engine alone (carve-php#751). PART 12 §3a keeps `rawRef` on the
 * node for exactly this, and `renderInline()` already used it for an image in
 * a paragraph; the figure path did not.
 *
 * Whether an unresolved image should be promoted to a figure AT ALL is a
 * separate question - carve-js and carve-rs leave it a paragraph (carve#623).
 * This test pins the writer, whichever way that lands.
 */
class UnresolvedImageFigureFmtTest extends TestCase
{
    private function carve(string $source): string
    {
        return trim(CarveConverter::carve()->convert($source));
    }

    private function html(string $source): string
    {
        return CarveConverter::create()->convert($source);
    }

    public function testTheReferenceLabelSurvivesFormatting(): void
    {
        // Bare `^ cap`: the shape is a PARAGRAPH, not a figure (#751), and an
        // unresolved reference image cannot host a caption - so the marker
        // cannot form and the escape would say nothing (#758). carve-js emits
        // the same bytes.
        $this->assertSame("![a][nope]\n^ cap", $this->carve("![a][nope]\n^ cap\n"));
    }

    public function testFormattingPreservesTheRendering(): void
    {
        // PART 11 §1: to_html(fmt(x)) == to_html(x).
        $source = "![a][nope]\n^ cap\n";
        $this->assertSame($this->html($source), $this->html($this->carve($source) . "\n"));
    }

    public function testAResolvedReferenceImageStillInlinesItsDestination(): void
    {
        // The path that was already right, kept honest: a definition exists, so
        // the writer emits the resolved form rather than the reference.
        $this->assertSame(
            "![a](/u)\n^ cap",
            $this->carve("![a][r]\n^ cap\n\n[r]: /u\n"),
        );
    }

    public function testAnUnresolvedImageWithNoCaptionIsUnchanged(): void
    {
        $this->assertSame('![a][nope]', $this->carve("![a][nope]\n"));
    }
}
