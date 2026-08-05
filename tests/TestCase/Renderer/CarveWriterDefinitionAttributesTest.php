<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Two authored attributes the canonical writer used to drop (carve-php#831).
 *
 * Both are invisible to this engine's own round trip. The definition's `#id` was
 * overridden by the reference site in the corpus document that carries it, so
 * `toHtml(fmt(x)) == toHtml(x)` held while the id was gone; the reference
 * image's attribute line broke that invariant outright, and nothing was
 * asserting the source.
 */
class CarveWriterDefinitionAttributesTest extends TestCase
{
    private function fmt(string $source): string
    {
        return (new CarveRenderer())->render((new CarveConverter())->parse($source));
    }

    private function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    public function testAnIdOnADefinitionsAttributeBlockSurvives(): void
    {
        $source = "[Example][ex]{.internal #b}\n\n[ex]: /u {.external #a}\n";

        $this->assertStringContainsString('[ex]: /u {.external #a}', $this->fmt($source));
    }

    public function testTheDefinitionsIdReachesALinkThatDoesNotOverrideIt(): void
    {
        // The id only shows in the HTML where the reference does NOT set one -
        // which is why the corpus document alone could not catch the loss.
        $source = "[Example][ex]\n\n[ex]: /u {#a}\n";

        $this->assertSame($this->html($source), $this->html($this->fmt($source)));
        $this->assertStringContainsString('id="a"', $this->html($this->fmt($source)));
    }

    public function testABlockAttributeLineAboveAReferenceImageSurvives(): void
    {
        $source = "{#f}\n![a][r]\n\n[r]: /u\n";
        $formatted = $this->fmt($source);

        $this->assertStringContainsString("{#f}\n![a][r]", $formatted);
        $this->assertSame($this->html($source), $this->html($formatted));
        $this->assertStringContainsString('id="f"', $this->html($formatted));
    }

    public function testAnAttributeBlockWrittenAtTheReferenceIsNotAlsoWrittenAsALine(): void
    {
        // `rawRef` already holds it, so the line would say it twice.
        $source = "![a][r]{#f}\n\n[r]: /u\n";
        $formatted = $this->fmt($source);

        $this->assertStringNotContainsString("{#f}\n![a][r]{#f}", $formatted);
        $this->assertSame($this->html($source), $this->html($formatted));
    }

    public function testADefinitionSourcedAttributeIsNotAlsoWrittenAsALine(): void
    {
        // Resolution copies the definition's attributes onto the image so HTML
        // can render them; they belong to the definition on the wire, so the
        // line would say the same `{#def}` twice.
        $source = "![a][r]\n\n[r]: /u {#def}\n";
        $formatted = $this->fmt($source);

        $this->assertStringNotContainsString("{#def}\n![a][r]", $formatted);
        $this->assertStringContainsString('[r]: /u {#def}', $formatted);
        $this->assertSame($this->html($source), $this->html($formatted));
    }

    public function testSubtractingTheLastAttributeLeavesNoBlock(): void
    {
        // renderAttrsExcept() used to subtract through a node COPY, and both
        // attribute setters merge - so removing every key put them all back and
        // the subtraction could never fire on any input.
        $source = "[a][r] x\n\n[r]: /u {#def}\n";
        $formatted = $this->fmt($source);

        $this->assertStringNotContainsString('[a][r]{#def}', $formatted);
        $this->assertSame($this->html($source), $this->html($formatted));
    }

    public function testAnInlineImageKeepsItsAttributesInline(): void
    {
        $source = "{#f}\n![a](/u)\n";

        $this->assertSame($this->html($source), $this->html($this->fmt($source)));
    }
}
