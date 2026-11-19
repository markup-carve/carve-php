<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Render;

use MarkupCarve\Carve\Html\HtmlRenderer;
use MarkupCarve\Carve\Json\TreeReader;
use MarkupCarve\Carve\Options;
use MarkupCarve\Carve\Test\Support\Linearity;
use PHPUnit\Framework\TestCase;

final class DerivedTextBudgetTest extends TestCase
{
    public function testC2H201JsonLengthCapsDerivedTextAndWorkScalesLinearly(): void
    {
        $nodes = [1000 => TreeReader::read($this->json(1000))];
        foreach ([2000, 4000, 8000] as $count) {
            $json = $this->json($count);
            $nodes[$count] = TreeReader::read($json);
            [$html, $log] = HtmlRenderer::renderWithLog($nodes[$count], Options::from([]));
            $this->assertSame(['derived-text-budget'], array_column($log->entries, 'code'));
            $this->assertLessThan(8 * strlen($json) + 30 * $count, strlen($html));
        }

        $excess = Linearity::excess(
            static fn () => HtmlRenderer::renderWithLog($nodes[1000], Options::from([])),
            static fn () => HtmlRenderer::renderWithLog($nodes[8000], Options::from([])),
            8,
        );
        $this->assertLessThanOrEqual(Linearity::LIMIT, $excess);
    }

    private function json(int $count): string
    {
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

        return json_encode($tree, JSON_THROW_ON_ERROR);
    }
}
