<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `fmt` on a nested list must return the indentation it read.
 *
 * Each level was indented twice - once by an absolute two-spaces-per-level term
 * and again by the parent item's continuation prefix - with a two-space strip of
 * the child's output compensating for part of it. The net per-level indent grew,
 * so output was O(depth^3) bytes where the source is O(depth^2): 1720 bytes in,
 * 23040 out at depth 40. A two-space source came back with four
 * (carve-php#792).
 *
 * The parent's continuation prefix already IS the child list's indentation, so
 * the absolute term was redundant and the strip existed only to offset it.
 *
 * Same defect and same fix as carve-js#653 and carve-rs#594; carve-js was fixed
 * first, which is what left this engine and carve-rs as the outliers on every
 * corpus case containing a nested list.
 */
class CarveWriterListIndentTest extends TestCase
{
    protected function fmt(string $source): string
    {
        return (new CarveRenderer())->render((new BlockParser())->parse($source));
    }

    protected function ladder(int $depth): string
    {
        $lines = [];
        for ($i = 0; $i < $depth; $i++) {
            $lines[] = str_repeat('  ', $i) . '- x';
        }

        return implode("\n", $lines) . "\n";
    }

    public function testATwoSpaceNestingComesBackAsTwoSpaces(): void
    {
        $source = "- fruit\n  - apples\n  - oranges\n- vegetables\n";

        $this->assertSame($source, $this->fmt($source));
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function depthProvider(): array
    {
        return [
            'depth 5' => [5],
            'depth 10' => [10],
            'depth 20' => [20],
            'depth 40' => [40],
        ];
    }

    /**
     * @param int $depth
     */
    #[DataProvider('depthProvider')]
    public function testALadderRoundTripsByteIdentically(int $depth): void
    {
        $source = $this->ladder($depth);

        // Byte equality is the strong form of the claim: no inflation at all,
        // rather than "less inflation than before".
        $this->assertSame($source, $this->fmt($source));
    }

    public function testOutputDoesNotGrowFasterThanTheSource(): void
    {
        // The shape of the defect, not just its size. Doubling the depth
        // quadruples the SOURCE (it is O(depth^2)); the broken form grew ~7x
        // across this step because it was cubic.
        $shallow = strlen($this->fmt($this->ladder(20)));
        $deep = strlen($this->fmt($this->ladder(40)));

        $this->assertLessThan(5.0, $deep / $shallow);
    }

    public function testAnOrderedLadderIsUnaffected(): void
    {
        // An ordered marker is three columns wide, so its continuation prefix
        // differs from a bullet's - the boundary a depth-based term would have
        // got wrong in the other direction.
        $source = "1. a\n   1. b\n      1. c\n";

        $this->assertSame($source, $this->fmt($source));
    }

    public function testAnItemWithTextAndASublistKeepsBoth(): void
    {
        // The strip that was removed only fired when an item's ONLY child was a
        // list. This is the neighbouring shape, which never took that path.
        $source = "- a\n  - b\n";

        $this->assertSame($source, $this->fmt($source));
    }
}
