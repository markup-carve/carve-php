<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TabIndentedNestedListPositionTest extends TestCase
{
    /**
     * @return array<string, array{string, int, int, int}>
     */
    public static function nestedLists(): array
    {
        return [
            'four spaces' => ["- a\n    - b\n", 6, 2, 3],
            'three spaces' => ["- a\n   - b\n", 6, 2, 3],
            'four-column parent and tab' => ["1.  a\n\t- b\n", 7, 2, 2],
            'four-column parent and tab after blank' => ["1.  a\n\n\t- b\n", 8, 3, 2],
            'two-column parent and tab' => ["- a\n\t- b\n", 4, 2, 1],
            'four spaces under ordered item' => ["10. a\n    - b\n", 10, 2, 5],
            'tab ending at content column' => ["10. a\n\t- b\n", 7, 2, 2],
            'quoted tab' => ["> - a\n> \t- b\n", 9, 2, 4],
            'middle level' => ["- outer\n\t- a\n", 8, 2, 1],
            'three levels' => ["- outer\n\t- a\n\t\t1. a\n", 15, 3, 3],
            'spaces' => ["- outer\n  - a\n", 10, 2, 3],
            'multibyte prefix' => ["> - é\n> \t- b\n", 9, 2, 4],
        ];
    }

    public function testSubsequentNestedItemUsesItsOwnMarker(): void
    {
        $source = "1. outer\n   1. inner\nlazy\n   2. sibling\n";
        $tree = (new AstCodec())->encode(
            (new CarveConverter(parser: new BlockParser(false, false, false, true)))->parse($source),
        );
        $lists = [];
        $visit = function (array $node) use (&$visit, &$lists): void {
            if (($node['type'] ?? null) === 'list') {
                $lists[] = $node;
            }
            foreach (['children', 'items'] as $key) {
                foreach ($node[$key] ?? [] as $child) {
                    $visit($child);
                }
            }
        };
        $visit($tree);
        $nested = $lists[count($lists) - 1];
        $this->assertSame('list', $nested['type']);
        $this->assertSame(2, count($nested['items']));
        $second = $nested['items'][1];
        $this->assertSame('list_item', $second['type']);
        $this->assertSame(29, $second['pos']['startOffset']);
        $this->assertSame(4, $second['pos']['startLine']);
        $this->assertSame(4, $second['pos']['startColumn']);
        $this->assertSame($second['pos']['endOffset'], min($nested['pos']['endOffset'], $second['pos']['endOffset']));
    }

    public function testSubsequentQuotedItemStartsAfterTheQuote(): void
    {
        $source = "> - a\n> \t- b\n> \t- c\n";
        $tree = (new AstCodec())->encode(
            (new CarveConverter(parser: new BlockParser(false, false, false, true)))->parse($source),
        );
        $rootChildren = $tree['children'] ?? null;
        $this->assertIsArray($rootChildren);
        $quote = $rootChildren[0] ?? null;
        $this->assertIsArray($quote);
        $quoteChildren = $quote['children'] ?? null;
        $this->assertIsArray($quoteChildren);
        $outer = $quoteChildren[0] ?? null;
        $this->assertIsArray($outer);
        $outerItems = $outer['items'] ?? null;
        $this->assertIsArray($outerItems);
        $first = $outerItems[0] ?? null;
        $this->assertIsArray($first);
        $firstChildren = $first['children'] ?? null;
        $this->assertIsArray($firstChildren);
        $inner = $firstChildren[1] ?? null;
        $this->assertIsArray($inner);
        $items = $inner['items'] ?? null;
        $this->assertIsArray($items);
        $second = $items[1] ?? null;
        $this->assertIsArray($second);
        $position = $second['pos'] ?? null;
        $this->assertIsArray($position);
        $this->assertSame(16, $position['startOffset']);
        $this->assertSame(3, $position['startLine']);
        $this->assertSame(4, $position['startColumn']);
    }

    #[DataProvider('nestedLists')]
    public function testNestedListAndFirstItemShareTheSourceOpening(
        string $source,
        int $offset,
        int $line,
        int $column,
    ): void {
        $tree = (new AstCodec())->encode(
            (new CarveConverter(parser: new BlockParser(false, false, false, true)))->parse($source),
        );
        $lists = [];
        $visit = function (array $node) use (&$visit, &$lists): void {
            if (($node['type'] ?? null) === 'list') {
                $lists[] = $node;
            }
            foreach (['children', 'items'] as $key) {
                foreach ($node[$key] ?? [] as $child) {
                    $visit($child);
                }
            }
        };
        $visit($tree);
        $node = $lists[count($lists) - 1];
        $this->assertSame('list', $node['type']);
        $item = $node['items'][0];
        $this->assertSame('list_item', $item['type']);
        foreach ([$node, $item] as $placed) {
            $this->assertSame($offset, $placed['pos']['startOffset']);
            $this->assertSame($line, $placed['pos']['startLine']);
            $this->assertSame($column, $placed['pos']['startColumn']);
        }
        $parent = $node['pos'];
        $child = $item['pos'];
        $this->assertSame($child['startOffset'], max($parent['startOffset'], $child['startOffset']));
        $this->assertSame($child['endOffset'], min($parent['endOffset'], $child['endOffset']));
        if (str_starts_with($source, '>')) {
            $rootChildren = $tree['children'] ?? null;
            $quoteNode = is_array($rootChildren) ? ($rootChildren[0] ?? null) : null;
            if (!is_array($quoteNode) || !is_array($quoteNode['pos'] ?? null)) {
                $this->fail('Quote position missing');
            }
            $quote = $quoteNode['pos'];
            $this->assertSame($parent['startOffset'], max($quote['startOffset'], $parent['startOffset']));
            $this->assertSame($parent['endOffset'], min($quote['endOffset'], $parent['endOffset']));
        }
    }
}
