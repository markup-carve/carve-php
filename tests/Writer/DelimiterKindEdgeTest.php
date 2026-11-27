<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Writer\RepairPlanner;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-088, C2-I-047: the `/` kind escapes each `/` that can open by the characters written around it, also where the
 * `/` ends a text and the next character is the markup of another node. Outputs are carve-js `next2`.
 */
final class DelimiterKindEdgeTest extends TestCase
{
    public function testASlashBeforeAFootnoteReferenceTakesTheKind(): void
    {
        $source = "^[/\\`[`[/[^-]]][<] and [lck \\[i0][k] text \\/i0 [c][]\n";

        $result = Carve::formatWithDiagnostics($source, ['normalize' => true]);

        $this->assertSame([], $result->diagnostics);
        $this->assertSame("^\\[\\/\\`[\\`\\[\\/[^-]\\]][<] and [lck \\[i0][k] text \\/i0 [c][]\n", $result->source);
    }

    public function testTheNeighborIsOneCodePoint(): void
    {
        // The `/` before a text that starts with `/` and a combining mark has that `/` beside it, so neither opens.
        $tree = [
            'type' => 'doc',
            'children' => [
                [
                    'type' => 'paragraph',
                    'children' => [
                        ['type' => 'text', 'value' => 'a /'],
                        ['type' => 'text', 'value' => "/\u{0301}b"],
                    ],
                ],
            ],
        ];

        $this->assertTrue(RepairPlanner::settlesKinds($tree, ['/children/0/children/1' => [0 => true]]));
    }

    public function testThePlainFormIsUnchanged(): void
    {
        $source = "^[/\\`[`[/[^-]]][<] and [lck \\[i0][k] text \\/i0 [c][]\n";

        $this->assertSame("^\\[\\/\\`[`[/[^-]]][<] and [lck \\[i0][k] text \\/i0 [c][]\n", Carve::formatWithDiagnostics($source)->source);
    }
}
