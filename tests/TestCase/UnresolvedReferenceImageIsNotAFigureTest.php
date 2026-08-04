<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * An UNRESOLVED reference image is not an image: the label resolves to
 * nothing, every writer emits the author's source text, and there is no
 * rendered image for a caption to attach to. Promoting it built a `<figure>`
 * around literal text, which carve-js and carve-rs both decline
 * (carve-php#751).
 *
 * Two answers had to agree: the promotion, and whether the caption line
 * INTERRUPTS the paragraph. Fixing only the first left `^ cap` as its own
 * paragraph, where the other two engines fold it in.
 */
class UnresolvedReferenceImageIsNotAFigureTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testAnUnresolvedReferenceImageIsNotPromoted(): void
    {
        $html = $this->converter->convert("![a][nope]\n^ cap");

        $this->assertSame("<p>![a][nope]\n^ cap</p>\n", $html);
        $this->assertStringNotContainsString('<figure>', $html);
    }

    public function testTheWriterKeepsTheAuthoredReference(): void
    {
        // PART 12 §3a keeps `ref` and `rawRef` so the writer can put the
        // author's text back; it used to emit `![a]()`, losing the label and
        // the destination both.
        $formatted = CarveConverter::toCarve("![a][nope]\n^ cap\n");

        $this->assertStringContainsString('![a][nope]', $formatted);
        $this->assertStringNotContainsString('![a]()', $formatted);
    }

    public function testTheFormatterPreservesTheRendering(): void
    {
        // PART 11 §1: to_html(fmt(x)) == to_html(x), which failed inside this
        // engine alone - the figure it built did not survive its own writer.
        $source = "![a][nope]\n^ cap\n";
        $formatted = CarveConverter::toCarve($source);

        $this->assertSame(
            $this->converter->convert($source),
            $this->converter->convert($formatted),
        );
    }

    public function testAResolvedInlineImageStillBecomesAFigure(): void
    {
        $html = $this->converter->convert("![a](a.png)\n^ cap");

        $this->assertStringContainsString('<figure>', $html);
        $this->assertStringContainsString('<figcaption>cap</figcaption>', $html);
    }

    public function testAResolvedReferenceImageStillBecomesAFigure(): void
    {
        // The label resolves, so there IS an image to caption.
        $html = $this->converter->convert("![a][ok]\n^ cap\n\n[ok]: /a.png");

        $this->assertStringContainsString('<figure>', $html);
        $this->assertStringContainsString('src="/a.png"', $html);
    }
}
