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

    public function testAWrappedParagraphLoadsEachLineEndAsASoftBreakLeaf(): void
    {
        $sources = [
            'plain' => "one\ntwo\nthree\n",
            'marks' => "*a\nb* /c\nd/\n",
            'link' => "[a\nb](u)\n",
            'quote' => "> a\n> b\n",
            'item' => "- a\n  b\n",
            'admonition' => "::: note\na\nb\n:::\n",
        ];
        $renderer = new ProseMirrorRenderer();
        foreach ($sources as $id => $source) {
            $json = $renderer->renderJson(Carve::parse($source));
            $this->assertStringContainsString('{"type":"carveSoftBreak","attrs":{"carve":null}', $json, $id);
            $this->assertStringNotContainsString('soft_break', $json, $id);
        }
        $this->assertRoundTrips($sources, count($sources), 6);

        $doc = [

            'type' => 'doc',
            'content' => [
                [

                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'oneX'],
                        ['type' => 'carveSoftBreak'],
                        ['type' => 'text', 'text' => 'Ytwo'],
                    ],
                ],
            ],
        ];
        $this->assertSame("oneX\nYtwo\n", (new ProseMirrorToCarve())->convert($doc));
        $this->assertSame('soft_break', ProseMirrorToCarve::carveTypeFor('carveSoftBreak'));
    }

    /**
     * @param array<string, string> $sources
     * @param int $minimum
     * @param int $minimumTypes
     */
    private function assertRoundTrips(array $sources, int $minimum, int $minimumTypes = 60): void
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
        $this->assertGreaterThanOrEqual($minimumTypes, count($types));
        $this->assertSame([], $failures);
    }
}
