<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\DecisionTrace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The block a marker-line `+` attaches at the marker column is the item's one next block,
 * so its trace row names the item and closes nothing (carve#5400).
 */
final class AnAttachedBlockAtTheMarkerColumnIsTracedInItsHostTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, array<string, mixed>}>
     */
    public static function attachedBlocks(): iterable
    {
        yield 'colon block' => ["- +\n::: note\n\n  tail\n", 1, ['owner' => 'doc/item:1/div:2', 'form' => 'div', 'plus' => 1]];
        yield 'code fence' => ["- +\n```\nx\n```\n", 1, ['owner' => 'doc/item:1/code:2', 'form' => 'code', 'plus' => 1]];
        yield 'heading' => ["- +\n# h\n", 1, ['owner' => 'doc/item:1', 'form' => 'leaf', 'plus' => 1]];
        yield 'quote' => ["- +\n> q\n", 1, ['owner' => 'doc/item:1/quote:2', 'form' => 'quote', 'plus' => 1]];
        yield 'nested item' => ["- a\n  * +\n  ::: note\n\n    - q\n", 2, ['owner' => 'doc/item:1/item:2/div:3', 'form' => 'div', 'plus' => 2]];

        // The spec model still closes a quoted host here (carve#5399).
        yield 'quoted item' => ["> - +\n> ::: note\n", 1, ['owner' => 'doc/quote:1/div:2', 'form' => 'div', 'closed' => 1, 'reached' => 'doc/quote:1', 'plus' => 1]];
    }

    /**
     * @param string $source
     * @param int $row
     * @param array<string, mixed> $expected
     */
    #[DataProvider('attachedBlocks')]
    public function testTheAttachedBlockRow(string $source, int $row, array $expected): void
    {
        $record = DecisionTrace::records($source)[$row];

        foreach (['owner', 'form', 'closed', 'reached', 'plus'] as $key) {
            self::assertSame($expected[$key] ?? null, $record[$key] ?? null, $key);
        }
    }
}
