<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Test\Support\Linearity;
use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\TestCase;

final class CommentLineEdgeLinearityTest extends TestCase
{
    public function testEscapedSpaceBeforeEachLineFeedIsCheckedLocally(): void
    {
        $block = static fn (int $lines): array => [
            'type' => 'paragraph',
            'children' => [
                ['type' => 'text', 'value' => 'a'],
                ['type' => 'comment', 'form' => 'braced', 'value' => str_repeat("c\\ \n", $lines) . 'd'],
            ],
        ];
        $small = $block(4000);
        $large = $block(32000);

        $this->assertSame([], Writer::writeBlocksWithDiagnostics([$large])->codes());
        $excess = Linearity::excess(
            static fn () => Writer::writeBlocksWithDiagnostics([$small]),
            static fn () => Writer::writeBlocksWithDiagnostics([$large]),
            8,
        );
        $this->assertLessThanOrEqual(Linearity::LIMIT, $excess);
    }
}
