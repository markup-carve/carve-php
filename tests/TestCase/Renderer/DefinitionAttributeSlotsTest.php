<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\LinkReferenceDefinition;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A definition's `#id` survives `fmt`.
 *
 * The hoisted `LinkReferenceDefinition` node received its attribute order as raw
 * STORAGE keys (`array_keys()` gives `id`, `class`), where the writer's slot
 * emitter expects the SOURCE spelling `#id` and `.class` - the same names
 * `Node::setAttribute()` records. A bare `id` slot is exactly what that emitter
 * skips, because the skip exists so the trailing sweep cannot re-emit `id`/`class`
 * after their dedicated slots. So `[ex]: /u {#a}` came back as `[ex]: /u`, with the
 * id silently gone (carve-php#831).
 *
 * `class` survived, which is what made this look like an id-specific quirk rather
 * than a slot-naming mistake.
 *
 * WHY THE ROUND TRIP DID NOT CATCH IT. In the reported shape the reference carries
 * its own `#b`, and §15 A3 has the use site win on a repeated key - so the dropped
 * id was being overridden anyway and the HTML was identical. It only breaks when
 * the reference sets no id, which is the first case below.
 */
class DefinitionAttributeSlotsTest extends TestCase
{
    protected function fmt(string $source): string
    {
        return (new CarveRenderer())->render((new CarveConverter())->parse($source));
    }

    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function definitionAttributeShapes(): array
    {
        return [
            'id alone' => ["text\n\n[ex]: /u {#a}\n"],
            'id then class' => ["text\n\n[ex]: /u {#a .c}\n"],
            'class then id' => ["text\n\n[ex]: /u {.external #a}\n"],
            'id and a key=value' => ["text\n\n[ex]: /u {#a k=v}\n"],
            // The shapes that already worked - kept so a fix cannot trade one
            // slot for another.
            'class alone' => ["text\n\n[ex]: /u {.c}\n"],
            'key=value alone' => ["text\n\n[ex]: /u {k=v}\n"],
        ];
    }

    #[DataProvider('definitionAttributeShapes')]
    public function testTheDefinitionLineIsReproducedExactly(string $source): void
    {
        $this->assertSame($source, $this->fmt($source));
    }

    public function testTheIdSurvivesWhenNothingOverridesIt(): void
    {
        // The case whose ROUND TRIP breaks, not just its bytes: no reference sets
        // an id, so the dropped one was the only one.
        $source = "[t][ex]\n\n[ex]: /u {#a}\n";

        $this->assertSame($source, $this->fmt($source));
        $this->assertSame($this->html($source), $this->html($this->fmt($source)));
        $this->assertStringContainsString('id="a"', $this->html($source));
    }

    public function testAnOverriddenIdIsStillWritten(): void
    {
        // The reported shape. §15 A3 has the use site's `#b` win, so the HTML is
        // the same either way - the loss is invisible to the round trip, which is
        // why the bytes are asserted here.
        $source = "[Example][ex]{.internal #b}\n\n[ex]: /u {.external #a}\n";

        $this->assertSame($source, $this->fmt($source));
    }

    public function testTheNodeRecordsSlotNamesNotStorageKeys(): void
    {
        // The mechanism, pinned directly: a future change that stores the order as
        // raw keys again would pass a bytes-only test on some shapes but fail here.
        $doc = (new CarveConverter())->parse("text\n\n[ex]: /u {#a .c}\n");
        $definition = null;
        foreach ($doc->getChildren() as $child) {
            if ($child instanceof LinkReferenceDefinition) {
                $definition = $child;
            }
        }

        $this->assertNotNull($definition);
        $this->assertSame(['#id', '.class'], $definition->getAttributeOrder());
        $this->assertSame(['id' => 'a', 'class' => 'c'], $definition->getAttributes());
    }

    public function testFormattingIsIdempotent(): void
    {
        $source = "text\n\n[ex]: /u {#a .c}\n";
        $once = $this->fmt($source);

        $this->assertSame($once, $this->fmt($once));
    }
}
