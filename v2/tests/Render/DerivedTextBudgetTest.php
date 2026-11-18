<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Test\Render;

use MarkupCarve\Carve2\Html\HtmlRenderer;
use MarkupCarve\Carve2\Json\TreeReader;
use MarkupCarve\Carve2\Options;
use PHPUnit\Framework\TestCase;

final class DerivedTextBudgetTest extends TestCase
{
    public function testC2H201JsonLengthCapsDerivedTextAndWorkScalesLinearly(): void
    {
        $times = [];
        foreach ([2000, 4000, 8000] as $count) {
            $tree = [
                'type' => 'document',
                'astVersion' => '2.0',
                'srcByteLength' => 1000000000,
                'stages' => ['referenceResolution', 'tableAlignment'],
                'children' => [
                    [
                        'type' => 'heading',
                        'level' => 1,
                        'attrs' => ['id' => 'h'],
                        'children' => [['type' => 'text', 'value' => str_repeat('T', $count)]],
                    ],
                    [
                        'type' => 'paragraph',
                        'children' => array_fill(0, $count, ['type' => 'xref', 'key' => 'h', 'namespace' => 'heading', 'href' => '#h']),
                    ],
                ],
            ];
            $json = json_encode($tree, JSON_THROW_ON_ERROR);
            $node = TreeReader::read($json);
            $samples = [];
            for ($run = 0; $run < 3; $run++) {
                $start = hrtime(true);
                [$html, $log] = HtmlRenderer::renderWithLog($node, Options::from([]));
                $samples[] = (hrtime(true) - $start) / 1e6;
            }
            sort($samples);
            $times[] = $samples[1];
            $this->assertSame(['derived-text-budget'], array_column($log->entries, 'code'));
            $this->assertLessThan(8 * strlen($json) + 30 * $count, strlen($html));
        }

        $this->assertLessThanOrEqual(max(30.0, $times[0] * 8), $times[2]);
    }
}
