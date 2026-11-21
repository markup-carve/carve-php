<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Block;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

final class RefusedFenceAtDepthCapTest extends TestCase
{
    public function testRefusedFenceLinesRemainInTheirParagraphUntilARealCloser(): void
    {
        $source = '';
        for ($run = 206; $run >= 7; $run--) {
            $source .= str_repeat(':', $run) . "\n";
        }
        $source .= "::::::\n:::::\n::::\n+\n\n::::\n:::::\n::::::\n";
        for ($run = 7; $run <= 206; $run++) {
            $source .= str_repeat(':', $run) . "\n";
        }
        $tree = Carve::parse($source);
        $node = $tree->children[0];
        for ($depth = 0; $depth < 199; $depth++) {
            self::assertSame('div', $node->type);
            $node = $node->children[0];
        }

        self::assertSame('div', $node->type);
        self::assertCount(2, $node->children);
        self::assertSame('paragraph', $node->children[0]->type);
        self::assertSame('paragraph', $node->children[1]->type);
        self::assertCount(7, $node->children[0]->children);
    }
}
