<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

/**
 * The carve target writes the document the author wrote.
 *
 * Links never nest, so an inner link is unwrapped to its text - a property of
 * the DOCUMENT, applied in `parse()`. But the unwrapped tree is not what the
 * author typed, and PART 11 §1's job is to give their document back: writing
 * `[[x](y)](z)` as `[x](z)` drops a destination the source carries, and
 * `[pre <http://h> post](/u)` loses the autolink's angle brackets.
 *
 * Both re-render to the same HTML, which is why PART 11 §1's invariant held and
 * nothing caught it (carve#787). carve-js and carve-rs both reproduce the
 * source; carve-rs does it by parsing in a mode that skips the resolution
 * passes, which is what this engine now does for the carve renderer.
 */
class WriterReproducesNestedSourceTest extends TestCase
{
    protected function carve(string $source): string
    {
        return (new CarveConverter(renderer: new CarveRenderer()))->convert($source);
    }

    public function testANestedLinkKeepsTheInnerDestination(): void
    {
        $this->assertSame("[[x](y)](z)\n", $this->carve("[[x](y)](z)\n"));
    }

    public function testAnAutolinkInsideALabelKeepsItsBrackets(): void
    {
        $this->assertSame(
            "[pre <http://h> post](/u)\n",
            $this->carve("[pre <http://h> post](/u)\n"),
        );
    }

    public function testTheHtmlStillUnwraps(): void
    {
        // The control. Links never nest in the RENDER, and that must not change:
        // if the unwrap stopped happening everywhere, the assertions above would
        // pass for the wrong reason.
        $html = (new CarveConverter())->convert("[[x](y)](z)\n");

        $this->assertSame("<p><a href=\"z\">x</a></p>\n", $html);
    }

    public function testTheWrittenSourceStillRendersTheSameHtml(): void
    {
        // PART 11 §1: to_html(fmt(x)) == to_html(x).
        $source = "[[x](y)](z)\n";
        $converter = new CarveConverter();

        $this->assertSame($converter->convert($source), $converter->convert($this->carve($source)));
    }
}
