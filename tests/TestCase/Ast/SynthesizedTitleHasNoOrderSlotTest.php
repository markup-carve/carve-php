<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

/**
 * `attrs.order` is the source-appearance order of the SLOTS in a `{…}` block.
 *
 * The schema says so directly - `attrs` is "The `{#id .class key=value}` block
 * attached to the node", and `order` is "Source-appearance order of the slots:
 * `#id`, `.class`, or a bare key name".
 *
 * A code fence's title is written as fence metadata (``` ``` rust "Example" ```),
 * not as a slot in an attribute block, so it has no source appearance to
 * record. Publishing `order: ["title"]` claims a position in a block the author
 * never wrote (carve#785).
 *
 * The attribute itself still reaches the wire, because the renderer emits it as
 * `<pre title="…">` and all three engines publish it; only the claim about
 * where it appeared in a block is dropped.
 */
class SynthesizedTitleHasNoOrderSlotTest extends TestCase
{
    /**
     * @return array<string, mixed>|null
     */
    protected function codeBlockAttrs(string $source): ?array
    {
        $tree = (new AstCodec())->encode((new CarveConverter())->parse($source));
        $found = null;
        $walk = function (mixed $node) use (&$walk, &$found): void {
            if (!is_array($node)) {
                return;
            }
            if (($node['type'] ?? null) === 'code_block') {
                $found ??= $node['attrs'] ?? [];
            }
            foreach ($node as $value) {
                $walk($value);
            }
        };
        $walk($tree);

        return $found;
    }

    public function testAFenceTitleIsPublishedWithoutAnOrderSlot(): void
    {
        $attrs = $this->codeBlockAttrs("``` rust \"Example\"\ncode\n```\n");

        $this->assertSame(['title' => 'Example'], $attrs['keyValues'] ?? null);
        $this->assertArrayNotHasKey('order', $attrs ?? []);
    }

    public function testAnAuthoredTitleAttributeKeepsItsSlot(): void
    {
        // The control, and the reason this is not "code blocks have no order":
        // a title written in a real attribute block DID appear in one.
        $attrs = $this->codeBlockAttrs("{title=\"Written\"}\n``` rust\ncode\n```\n");

        $this->assertSame(['title' => 'Written'], $attrs['keyValues'] ?? null);
        $this->assertSame(['title'], $attrs['order'] ?? null);
    }

    public function testTheTitleStillRenders(): void
    {
        $html = (new CarveConverter())->convert("``` rust \"Example\"\ncode\n```\n");

        $this->assertStringContainsString('title="Example"', $html);
    }

    public function testTheFenceStillWritesBackWithItsTitle(): void
    {
        $carve = (new CarveConverter(renderer: new CarveRenderer()))
            ->convert("``` rust \"Example\"\ncode\n```\n");

        $this->assertStringContainsString('"Example"', $carve);
        $this->assertStringNotContainsString('{title=', $carve);
    }
}
