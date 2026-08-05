<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

/**
 * A reference image's block-attribute line is SUBTRACTED against what the
 * reference already says, not dropped whenever the reference says anything.
 *
 * carve-php#832 gave a captionless reference image its attribute line back, and
 * guarded against writing the same block twice by bailing out whenever `rawRef`
 * ended in `}`:
 *
 *     if (str_ends_with(rtrim($raw), '}')) {
 *         return '';
 *     }
 *
 * That is right when the two blocks are the SAME set and wrong when they differ.
 * With `{#f}` above and `{.c}` at the reference, the line was dropped wholesale
 * and `id="f"` went with it - the same loss #832 set out to fix, one shape over.
 *
 * So the guard is now a subtraction: whatever the authored `rawRef` states is
 * removed, and only the remainder becomes a line. carve-js and carve-rs both keep
 * the `{#f}` here.
 *
 * QUOTE-AWARE, because the subtraction has to find the reference's own block: a
 * value may contain a brace (`{k="{y}"}`), and locating the opener with `strrpos()`
 * finds the one INSIDE the value. That mis-parse showed up as corpus 71 differing
 * from carve-js, which is how this test's last case earned its place.
 */
class ReferenceImageAttributeLineSubtractionTest extends TestCase
{
    protected function fmt(string $source): string
    {
        return (new CarveRenderer())->render((new CarveConverter())->parse($source));
    }

    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    protected function assertRoundTrips(string $source): void
    {
        $this->assertSame($this->html($source), $this->html($this->fmt($source)));
    }

    public function testTheLineSurvivesWhenTheReferenceCarriesADifferentBlock(): void
    {
        // The shape #832's bail-out dropped.
        $source = "{#f}\n![a][r]{.c}\n\n[r]: /u\n";

        $this->assertSame($source, $this->fmt($source));
        $this->assertRoundTrips($source);
        $this->assertStringContainsString('id="f"', $this->html($source));
        $this->assertStringContainsString('class="c"', $this->html($source));
    }

    public function testACaptionlessReferenceImageStillKeepsItsLine(): void
    {
        // #832's own case, so this fix cannot regress it.
        $source = "{#f}\n![a][r]\n\n[r]: /u\n";

        $this->assertSame($source, $this->fmt($source));
        $this->assertRoundTrips($source);
    }

    public function testABlockWrittenOnlyAtTheReferenceGetsNoLine(): void
    {
        // Nothing above it, so nothing to subtract from and nothing to emit -
        // writing a line here would state `{.c}` twice.
        $source = "![a][r]{.c}\n\n[r]: /u\n";

        $this->assertSame($source, $this->fmt($source));
    }

    public function testAValueContainingABraceIsNotMisparsed(): void
    {
        // The quote-aware half. `strrpos()` finds the `{` inside the VALUE and
        // reads the payload as `y}"`, which parsed as nothing - so nothing was
        // subtracted and the whole block was repeated as a line (corpus 71).
        $direct = "![a](u){k=\"{y}\"}\n";
        $this->assertSame($direct, $this->fmt($direct));

        $reference = "{#f}\n![a][r]{k=\"{y}\"}\n\n[r]: /u\n";
        $this->assertSame($reference, $this->fmt($reference));
        $this->assertRoundTrips($reference);
    }

    public function testADirectImageStillWritesItsAttributesInline(): void
    {
        // Unchanged by this: a direct image has no `rawRef`, so it never reaches
        // the subtraction and keeps appending its attributes inline.
        $this->assertSame("![a](/u){#f}\n", $this->fmt("{#f}\n![a](/u)\n"));
    }

    public function testACaptionedReferenceImageIsUnchanged(): void
    {
        // The figure path, which already worked - it goes through the ordinary
        // attribute-line wrapper rather than this helper.
        $source = "{#f}\n![a][r]\n^ cap\n\n[r]: /u\n";

        $this->assertSame($source, $this->fmt($source));
        $this->assertRoundTrips($source);
    }

    public function testAnAttributeTheDefinitionAlreadyStatesIsNotRepeated(): void
    {
        // The definition's own keys reach the node by resolution (PART 9R R1) and
        // are written once on the definition line, so they must not become a line
        // as well. The round trip is what matters here: both spellings render the
        // same document, and carve-js writes the line - a byte difference in a
        // shape where the definition's id wins either way.
        $source = "{#f}\n![a][r]\n\n[r]: /u {#d}\n";

        $this->assertRoundTrips($source);
        $this->assertStringContainsString('id="d"', $this->html($source));
    }
}
