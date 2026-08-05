<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * Two attributes `fmt` used to lose (carve-php#831).
 *
 * Both were found by bringing carve-js up to this engine's reference-preserving
 * behavior: they were the only two corpus documents the two still disagreed on.
 */
class CarveWriterDefinitionAttributesTest extends TestCase
{
    private function carve(string $source): string
    {
        return CarveConverter::carve()->convert($source);
    }

    private function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    /**
     * An `#id` on a definition's attribute block survives.
     *
     * The class survived and the id did not: `recordAttributeSlot()` normalized
     * `class` to the `.class` slot and had no matching line for `id`, so a
     * caller recording raw attribute KEYS handed in `id`, which the writer skips
     * on purpose. Recorded, then never emitted.
     */
    public function testDefinitionKeepsItsId(): void
    {
        $src = "[Example][ex]{.internal #b}\n\n[ex]: /u {.external #a}\n";

        $this->assertSame(
            "[Example][ex]{.internal #b}\n\n[ex]: /u {.external #a}\n",
            $this->carve($src),
        );
    }

    /**
     * Its own round trip does NOT catch that one.
     *
     * The reference site carries `#b` and §15 A3 gives the use site a repeated
     * key, so the dropped id was being overridden anyway and the HTML is
     * identical either way. Pinned so the weaker guard is not mistaken for
     * cover.
     */
    public function testTheDefinitionIdLossIsInvisibleToTheRoundTrip(): void
    {
        $src = "[Example][ex]{.internal #b}\n\n[ex]: /u {.external #a}\n";
        $formatted = $this->carve($src);

        $this->assertSame($this->html($src), $this->html($formatted));
    }

    /**
     * A second reference is where it shows, since nothing overrides the id.
     */
    public function testASecondReferenceSeesTheDefinitionId(): void
    {
        $src = "[One][ex] and [Two][ex]\n\n[ex]: /u {#a}\n";
        $formatted = $this->carve($src);

        $this->assertStringContainsString('{#a}', $formatted);
        $this->assertSame($this->html($src), $this->html($formatted));
    }
}
