<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A container's span ends at its last placed child.
 *
 * A list, an item and a block quote have no closer, so their extent came from
 * the lines they CONSUMED - and a container consumes lines whose content ends
 * up somewhere else.
 *
 * A definition written at an item's content column is collected and hoisted to
 * the DOCUMENT by PART 12 §7, so it becomes the list's sibling; the list went
 * on covering it, which put the same offsets in two nodes and left a consumer
 * resolving one offset with two answers. An attribute block that attaches to
 * nothing yields no child at all, which PART 12 §4 excludes by name.
 *
 * Nothing caught either, because all three engines did the same thing and the
 * spec repository's span panel compares the engines against EACH OTHER
 * (markup-carve/carve#1522, markup-carve/carve#1524).
 */
class AContainerEndsAtItsLastPlacedChildTest extends TestCase
{
    /**
     * @return array<string, mixed>|null
     */
    private function nthOfType(string $source, string $type, int $nth = 0): ?array
    {
        $converter = new CarveConverter(parser: new BlockParser(false, false, false, true));
        $seen = 0;
        $found = null;
        $walk = static function (array $node) use (&$walk, &$found, &$seen, $type, $nth): void {
            if (($node['type'] ?? null) === $type) {
                if ($seen === $nth) {
                    $found ??= $node;
                }
                $seen++;
            }
            foreach (['children', 'items', 'rows', 'cells'] as $key) {
                foreach ($node[$key] ?? [] as $child) {
                    $walk($child);
                }
            }
        };
        $walk((new AstCodec())->encode($converter->parse($source)));

        return $found;
    }

    public function testAListStopsBeforeTheDefinitionHoistedOutOfIt(): void
    {
        $source = "- a\n\n  [r]: /u\n";
        $list = $this->nthOfType($source, 'list');
        $definition = $this->nthOfType($source, 'link_reference_definition');

        $this->assertNotNull($list);
        $this->assertNotNull($definition);
        // The list used to end at 14, which is where the definition ends.
        $this->assertSame(0, $list['pos']['startOffset']);
        $this->assertSame(3, $list['pos']['endOffset']);
        // And the two no longer claim the same offsets, so offset 8 resolves to
        // one node.
        $this->assertGreaterThanOrEqual($list['pos']['endOffset'], $definition['pos']['startOffset']);
    }

    public function testAQuoteStopsBeforeTheDefinitionHoistedOutOfIt(): void
    {
        $source = "> a\n> [r]: /u\n";
        $quote = $this->nthOfType($source, 'block_quote');
        $definition = $this->nthOfType($source, 'link_reference_definition');

        $this->assertNotNull($quote);
        $this->assertNotNull($definition);
        $this->assertSame(3, $quote['pos']['endOffset']);
        $this->assertGreaterThanOrEqual($quote['pos']['endOffset'], $definition['pos']['startOffset']);
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function stopsProvider(): array
    {
        return [
            // An attribute block that attaches to nothing yields no child, and
            // PART 12 §4 excludes it by name.
            'an unattached attribute block' => ["- a\n  {.x}\ntail\n", 3],
            // The blank-run half. It is filed separately as
            // markup-carve/carve-js#1304 and markup-carve/carve-rs#1232, and it
            // is subsumed here rather than excluded: a container that must stop
            // at its last placed child cannot reach into a blank run at all.
            'a trailing blank run' => ["- a\n\n\n", 3],
            'a trailing line terminator' => ["- a\n", 3],
        ];
    }

    #[DataProvider('stopsProvider')]
    public function testAListStopsAtItsLastItem(string $source, int $endOffset): void
    {
        $list = $this->nthOfType($source, 'list');

        $this->assertNotNull($list);
        $this->assertSame($endOffset, $list['pos']['endOffset']);
    }

    public function testAnEmptiedContainerSpansTheMarkupThatOpenedIt(): void
    {
        // "Ends at its last placed child" is silent where there is none, and a
        // definition written as an item's only content is collected out of it
        // and leaves nothing behind (markup-carve/carve-rs#1233). Zero width was
        // rejected: it discards the marker the author typed, and is a shape
        // every consumer has to special-case.
        $source = "* * [d]: u\n :\n";
        $inner = $this->nthOfType($source, 'list', 1);

        $this->assertNotNull($inner);
        $this->assertSame(2, $inner['pos']['startOffset']);
        $this->assertSame(4, $inner['pos']['endOffset']);
    }

    public function testAContainerWithChildrenIsUnchanged(): void
    {
        // The rule has to be the reason the spans moved, not the documents.
        $this->assertSame(7, $this->nthOfType("- a\n- b", 'list')['pos']['endOffset']);
        $this->assertSame(7, $this->nthOfType("> a\n> b", 'block_quote')['pos']['endOffset']);
        // And a container that DOES have a closer still ends at it.
        $this->assertSame(11, $this->nthOfType("::: n\na\n:::\n", 'admonition')['pos']['endOffset']);
    }
}
