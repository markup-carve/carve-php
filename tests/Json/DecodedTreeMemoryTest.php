<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Json;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeDecoder;
use MarkupCarve\Carve\Json\TreeError;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5663: a 105 KB run of empty HTML comments (45,007 nodes) took 197 MB to render from its decoded tree.
 * A decoded node now packs its `pos`, and rendering a tree with no source no longer decodes those positions again.
 */
final class DecodedTreeMemoryTest extends TestCase
{
    /**
     * @var string
     */
    private const SOURCE = "# Title\n\npara b\n\n- x\n- y\n";

    public function testDecodedPositionsRoundTrip(): void
    {
        $json = Carve::toJson(self::SOURCE);
        $tree = Carve::decodeTree($json);

        $this->assertNotSame('', $tree->children[0]->treePos);
        $this->assertSame($json, Carve::encodeTree($tree));
    }

    public function testRenderOfADecodedTreeKeepsItsSourceLines(): void
    {
        $html = Carve::render(Carve::decodeTree(Carve::toJson(self::SOURCE)), ['sourceLines' => true]);

        $this->assertSame(Carve::render(self::SOURCE, ['sourceLines' => true]), $html);
        $this->assertStringContainsString('<li data-source-line="6">y</li>', $html);
    }

    public function testAPackedPositionBelowTheSchemaMinimumIsStillRefused(): void
    {
        $tree = json_decode(Carve::toJson(self::SOURCE), true);
        $tree['children'][1]['pos']['startLine'] = 0;
        $node = TreeDecoder::json((string)json_encode($tree));
        $this->assertNotNull($node);

        $this->expectException(TreeError::class);
        $this->expectExceptionMessage('/children/1/pos/startLine');
        Carve::render($node);
    }

    public function testRenderOfALargeDecodedTreeStaysUnderTheWorkerLimit(): void
    {
        $source = '  ' . str_repeat('<!---->', 5000) . "<b>\n  ";
        $tree = Carve::decodeTree(Carve::toJson($source));
        memory_reset_peak_usage();
        $before = memory_get_usage();

        Carve::render($tree);

        // 31 MB before the fix, 15 MB after.
        $this->assertLessThan(24 * 1024 * 1024, memory_get_peak_usage() - $before);
    }
}
