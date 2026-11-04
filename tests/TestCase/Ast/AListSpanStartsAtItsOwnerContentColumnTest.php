<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AListSpanStartsAtItsOwnerContentColumnTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<array{int, int}>}>
     */
    public static function positions(): iterable
    {
        yield 'top-level siblings' => ["  - a\n  - b\n", [[0, 1], [0, 1], [6, 1]]];
        yield 'nested list' => ["- a\n   - b\n", [[0, 1], [0, 1], [6, 3], [6, 3]]];
        yield 'nested under indented item' => ["  - a\n    - b\n", [[0, 1], [0, 1], [10, 5], [10, 5]]];
        yield 'nested siblings under indented item' => ["  - a\n    - b\n    - c\n", [[0, 1], [0, 1], [10, 5], [10, 5], [18, 5]]];
        yield 'nested under attributed item' => ["-{#x} a\n  - b\n", [[0, 1], [0, 1], [10, 3], [10, 3]]];
        yield 'tab under wide ordered marker' => ["10. a\n\t- b\n", [[0, 1], [0, 1], [7, 2], [7, 2]]];
        yield 'footnote body siblings' => ["[^f]\n\n[^f]: - a\n  - b\n", [[12, 7], [12, 7], [18, 3]]];
        yield 'attributed footnote body with tab' => ["[^f]\n\n[^f]: -{#x} a\n\t- b\n", [[12, 7], [12, 7], [20, 1]]];
        yield 'ordered siblings' => ["  1. a\n  2. b\n", [[0, 1], [0, 1], [7, 1]]];
    }

    /**
     * @param string $source
     * @param list<array{int, int}> $expected
     */
    #[DataProvider('positions')]
    public function testListAndItemPositions(string $source, array $expected): void
    {
        $converter = new CarveConverter(parser: new BlockParser(false, false, false, true));
        $tree = (new AstCodec())->encode($converter->parse($source));
        $actual = [];
        $walk = static function (array $node) use (&$walk, &$actual): void {
            if (in_array($node['type'], ['list', 'list_item'], true)) {
                $actual[] = [$node['pos']['startOffset'], $node['pos']['startColumn']];
            }
            foreach (['children', 'items'] as $key) {
                foreach ($node[$key] ?? [] as $child) {
                    $walk($child);
                }
            }
        };
        $walk($tree);

        self::assertSame($expected, $actual);
    }
}
