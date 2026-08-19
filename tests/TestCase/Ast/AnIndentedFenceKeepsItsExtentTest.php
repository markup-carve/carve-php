<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A gap in the map is not evidence of reassembly.
 *
 * carve-php#1361 stopped publishing a position for a node assembled from
 * discontiguous source, which is right for a verbatim run carried across a
 * table continuation row. Asked of EVERY map it was an over-reach: an indented
 * fence folds into a verbatim run whose map has a gap per line for the stripped
 * indentation, and the check read that as reassembly and dropped three honest
 * spans carve-js and carve-rs both publish (carve-php#1369).
 *
 * A node's position is an EXTENT rather than a slice of its value - §4 has it
 * begin at the markup that opens the construct (markup-carve/carve#913) - so a
 * value that is not a byte-for-byte slice of the range is the normal case, not
 * a reason to omit. What separates the two is not the geometry, which is a
 * wider source gap in both, but WHO BUILT THE MAP: only the rebuilt cell joins
 * chunks that belong to no node.
 *
 * Both directions are pinned here, because a fix for either alone is a
 * regression in the other.
 */
class AnIndentedFenceKeepsItsExtentTest extends TestCase
{
    /**
     * @param string $source
     *
     * @return array<int, array<string, mixed>>
     */
    private function codes(string $source): array
    {
        $converter = new CarveConverter(parser: new BlockParser(false, false, false, true));
        $found = [];
        $walk = static function (array $node) use (&$walk, &$found): void {
            if (($node['type'] ?? null) === 'code') {
                $found[] = $node;
            }
            foreach (['children', 'rows', 'cells'] as $key) {
                foreach ($node[$key] ?? [] as $child) {
                    $walk($child);
                }
            }
        };
        $walk((new AstCodec())->encode($converter->parse($source)));

        return $found;
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: int}>
     */
    public static function placedProvider(): array
    {
        $fence = str_repeat('`', 3);

        return [
            // The reported document. Offsets 1-15 are the whole run, opener and
            // closer included; carve-rs publishes exactly that.
            'an indented fence folding into a run' => [' ' . $fence . "\n code\n " . $fence . "\n", 1, 15],
            // Two columns of indent, so two gaps per line rather than one -
            // the same shape, further from the boundary that used to decide it.
            'a fence indented two columns' => ['  ' . $fence . "\n  code\n  " . $fence . "\n", 2, 18],
        ];
    }

    #[DataProvider('placedProvider')]
    public function testAStrippedIndentDoesNotCostTheExtent(string $source, int $start, int $end): void
    {
        $codes = $this->codes($source);

        $this->assertCount(1, $codes);
        $this->assertSame($start, $codes[0]['pos']['startOffset']);
        $this->assertSame($end, $codes[0]['pos']['endOffset']);
    }

    public function testAReassembledRunStillDeclines(): void
    {
        // The other direction: the chunks here belong to two different rows and
        // the markup between them belongs to neither.
        $codes = $this->codes("| a `b |\n+ c` |\n");

        $this->assertCount(1, $codes);
        $this->assertSame('b c', $codes[0]['value']);
        $this->assertArrayNotHasKey('pos', $codes[0]);
    }
}
