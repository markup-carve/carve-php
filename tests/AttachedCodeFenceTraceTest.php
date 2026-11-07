<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\DecisionTrace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AttachedCodeFenceTraceTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<array<string, int|string>>}>
     */
    public static function cases(): iterable
    {
        yield 'plain tail resumes the item' => [
            "- +\n```\n\n  tail\n", [
                ['line' => 3, 'step' => 'S5', 'owner' => 'doc/item:1', 'rule' => 'CARVE-P0-003', 'form' => 'blank'],
                ['line' => 4, 'step' => 'S5', 'owner' => 'doc/item:1', 'rule' => 'CARVE-P0-024', 'form' => 'text'],
            ],
        ];

        yield 'nested list resumes the item' => [
            "- +\n```\n\n  - nested\n", [
                ['line' => 3, 'step' => 'S5', 'owner' => 'doc/item:1', 'rule' => 'CARVE-P0-003', 'form' => 'blank'],
                ['line' => 4, 'step' => 'S5', 'owner' => 'doc/item:1/item:4', 'rule' => 'CARVE-P0-024', 'form' => 'item', 'cell' => 'B-item-start'],
            ],
        ];

        yield 'sibling below the content column stays payload' => [
            "- +\n```\n\n- sibling\n", [
                ['line' => 3, 'step' => 'S1', 'owner' => 'doc/item:1/code:2', 'rule' => 'CARVE-P0-005', 'form' => 'payload', 'cell' => 'B-code-keep'],
                ['line' => 4, 'step' => 'S1', 'owner' => 'doc/item:1/code:2', 'rule' => 'CARVE-P0-005', 'form' => 'payload', 'cell' => 'B-code-keep'],
            ],
        ];

        yield 'nested item resumes at its content column' => [
            "- outer\n  - +\n  ```\n\n    tail\n", [
                ['line' => 4, 'step' => 'S5', 'owner' => 'doc/item:1/item:2', 'rule' => 'CARVE-P0-003', 'form' => 'blank'],
                ['line' => 5, 'step' => 'S5', 'owner' => 'doc/item:1/item:2', 'rule' => 'CARVE-P0-024', 'form' => 'text'],
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testTraceAfterAnUnclosedAttachedFence(string $source, array $expected): void
    {
        self::assertSame($expected, array_slice(DecisionTrace::records($source), -2));
    }
}
