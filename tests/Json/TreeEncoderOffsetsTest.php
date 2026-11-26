<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Json;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use PHPUnit\Framework\TestCase;

/**
 * The writer's read-back keeps each node's offsets as one integer (carve#6413).
 */
final class TreeEncoderOffsetsTest extends TestCase
{
    public function testOffsetsReadBackEachNodeSpan(): void
    {
        $source = "a {^b *c*^} d\n\n> [e](f)\n";
        $tree = TreeEncoder::decodedWithOffsets(Carve::parse($source), $source);
        $spans = [];
        $walk = static function (array $node) use (&$walk, &$spans, $source): void {
            if (isset($node['pos'])) {
                self::assertIsInt($node['pos']);
                $offsets = TreeEncoder::offsets($node['pos']);
                self::assertNotNull($offsets);
                $spans[] = $node['type'] . ':' . substr($source, $offsets[0], $offsets[1] - $offsets[0]);
            }
            foreach ($node['children'] ?? [] as $child) {
                $walk($child);
            }
        };
        $walk($tree);

        self::assertContains('superscript:{^b *c*^}', $spans);
        self::assertContains('strong:*c*', $spans);
        self::assertContains('link:[e](f)', $spans);
        self::assertContains('block_quote:> [e](f)', $spans);
    }

    public function testOffsetsReadsAnArrayAsItIs(): void
    {
        self::assertSame([3, 9], TreeEncoder::offsets([3, 9]));
        self::assertSame([0, 0], TreeEncoder::offsets(0));
        self::assertNull(TreeEncoder::offsets(['start' => 1]));
        self::assertNull(TreeEncoder::offsets(null));
    }
}
