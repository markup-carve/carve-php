<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\ProseMirror;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEquality;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use MarkupCarve\Carve\Writer\CarveWriteError;
use PHPUnit\Framework\TestCase;

/**
 * Carve to ProseMirror JSON and back gives the same tree, and the same source as writing the tree directly.
 */
final class RoundTripTest extends TestCase
{
    public function testEveryConformanceInputRoundTrips(): void
    {
        $this->assertRoundTrips(Documents::conformance(), 1400);
    }

    public function testAThousandGeneratedDocumentsRoundTrip(): void
    {
        $this->assertRoundTrips(Documents::generated(1000), 1000);
    }

    /**
     * @param array<string, string> $sources
     * @param int $minimum
     */
    private function assertRoundTrips(array $sources, int $minimum): void
    {
        $renderer = new ProseMirrorRenderer();
        $converter = new ProseMirrorToCarve();
        $failures = [];
        $types = [];
        foreach ($sources as $id => $source) {
            $tree = Carve::parse($source);
            $expected = json_decode(Carve::encodeTree($tree), true);
            array_walk_recursive($expected, static function (mixed $value, string|int $key) use (&$types): void {
                if ($key === 'type' && is_string($value)) {
                    $types[$value] = true;
                }
            });
            $json = $renderer->renderJson($tree);
            $back = json_decode((string)json_encode($converter->toTree($json)), true);
            if (!TreeEquality::equal($expected, $back)) {
                $failures[] = $id . ': tree';

                continue;
            }
            try {
                $direct = Carve::write($tree);
            } catch (CarveWriteError) {
                continue;
            }
            if ($converter->convert($json) !== $direct) {
                $failures[] = $id . ': source';
            }
        }

        $this->assertGreaterThanOrEqual($minimum, count($sources));
        $this->assertGreaterThanOrEqual(60, count($types));
        $this->assertSame([], $failures);
    }
}
