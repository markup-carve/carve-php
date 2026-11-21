<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\PublicApi;

use Closure;
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Extension\Claim;
use MarkupCarve\Carve\Extension\ExtensionDefinition;
use MarkupCarve\Carve\Extension\RenderHost;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\OptionError;
use MarkupCarve\Carve\Resolve\PlainText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The host helpers of carve-php#5255 and carve-php#5250: plain text, the expanded table grid and the safe fence.
 */
final class HostHelpersTest extends TestCase
{
    public function testPlainTextOfANodeAndAList(): void
    {
        $tree = Carve::parse("A :color[#ff8800] @bob *x* `c` \"q\"\\\nend\n");
        $paragraph = $tree->children[0] ?? null;
        $this->assertInstanceOf(Node::class, $paragraph);
        $this->assertSame("A #ff8800 @bob x c \u{201C}q\u{201D} end", Carve::plainText($paragraph));
        $this->assertSame(Carve::plainText($paragraph), Carve::plainText($paragraph->children ?? []));
        $this->assertSame('', Carve::plainText([]));
    }

    public function testPlainTextAgreesWithTheRenderHost(): void
    {
        $seen = [];
        $renderer = static function (Node $node, RenderHost $host) use (&$seen): string {
            $fallback = PlainText::nodes($node->f['fallback'] ?? []);
            $seen[] = [($host->plainText)($fallback), Carve::plainText($fallback)];

            return '';
        };
        $definition = new ExtensionDefinition(
            'shop.product',
            '1',
            matcher: ['form' => 'inline', 'name' => 'product'],
            transform: static function (Claim $claim): Node {
                $node = new Node('inline_extension', []);
                $fallback = $claim->node->children ?? [];
                $node->f = ['name' => 'shop.product', 'version' => '1', 'props' => [], 'slots' => [], 'fallback' => $fallback];

                return $node;
            },
            renderer: Closure::fromCallable($renderer),
        );
        Carve::render(":product[SKU-#1 @x *b* :color[#ff8800]]\n", ['extensions' => [$definition]]);
        $this->assertNotSame([], $seen);
        $this->assertSame([['SKU-#1 @x b #ff8800', 'SKU-#1 @x b #ff8800']], $seen);
    }

    public function testTableGridFillsAColspanAndARowspan(): void
    {
        $grid = Carve::tableGrid($this->table("|= a |= b |= c |\n| x | < | y |\n| d | e | ^ |\n"));
        $this->assertSame([['a', 'b', 'c'], ['x', 'x', 'y'], ['d', 'e', 'y']], $this->texts($grid));
        $this->assertSame(['table_head', 'table_body', 'table_body'], array_map(static fn (array $r): string => $r['group']->type, $grid));
        $this->assertSame($grid[1]['cells'][0], $grid[1]['cells'][1]);
        $this->assertSame($grid[1]['cells'][2], $grid[2]['cells'][2]);
    }

    public function testTableGridFillsARowspan(): void
    {
        $grid = Carve::tableGrid($this->table("| a | b |\n| ^ | c |\n| ^ | d |\n"));
        $this->assertSame([['a', 'b'], ['a', 'c'], ['a', 'd']], $this->texts($grid));
    }

    public function testTableGridPadsAShortRow(): void
    {
        $grid = Carve::tableGrid($this->table("| a | b | c |\n| d |\n"));
        $this->assertSame([['a', 'b', 'c'], ['d', null, null]], $this->texts($grid));
    }

    public function testTableGridReadsTheCountsOfASuppliedTree(): void
    {
        $cell = static fn (string $text, array $extra = []): array => [
            'type' => 'table_cell',
            'header' => false,
            'children' => [['type' => 'cell_paragraph', 'children' => [['type' => 'text', 'value' => $text]]]],
        ] + $extra;
        $tree = Carve::decodeTree((string)json_encode([

            'type' => 'document',
            'astVersion' => '2.0',
            'children' => [
                [
                    'type' => 'table',
                    'columns' => [['type' => 'table_column'], ['type' => 'table_column'], ['type' => 'table_column']],
                    'children' => [
                        [

                            'type' => 'table_body',
                            'children' => [
                                ['type' => 'table_row', 'children' => [$cell('a', ['colspan' => 2, 'rowspan' => 2]), $cell('b')]],
                                ['type' => 'table_row', 'children' => [$cell('c')]],
                                ['type' => 'table_row', 'children' => [$cell('d'), $cell('e'), $cell('f')]],
                            ],
                        ],
                    ],
                ],
            ],
        ]));
        $table = $tree->children[0] ?? null;
        $this->assertInstanceOf(Node::class, $table);
        $this->assertSame([['a', 'a', 'b'], ['a', 'a', 'c'], ['d', 'e', 'f']], $this->texts(Carve::tableGrid($table)));
    }

    public function testTableGridRefusesAnotherNode(): void
    {
        $this->expectException(OptionError::class);
        Carve::tableGrid(Carve::parse("text\n"));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function fences(): array
    {
        return [
            'plain' => ["a\nb\n", '`', '```'],
            'inner three' => ["```\ncode\n```\n", '`', '````'],
            'inner five indented' => ["   `````\n", '`', '``````'],
            'four spaces is not a fence' => ["    `````\n", '`', '```'],
            'run inside a line' => ["a ````` b\n", '`', '```'],
            'tildes' => ["~~~~\n", '~', '~~~~~'],
            'tildes ignore backticks' => ["`````\n", '~', '~~~'],
        ];
    }

    #[DataProvider('fences')]
    public function testSafeCodeFence(string $content, string $marker, string $expected): void
    {
        $this->assertSame($expected, Carve::safeCodeFence($content, $marker));
    }

    public function testSafeCodeFenceRefusesAnotherMarker(): void
    {
        $this->expectException(OptionError::class);
        Carve::safeCodeFence('a', '-');
    }

    private function table(string $source): Node
    {
        $table = Carve::parse($source)->children[0] ?? null;
        $this->assertInstanceOf(Node::class, $table);

        return $table;
    }

    /**
     * @param array<int, array{
     *     row: \MarkupCarve\Carve\Node,
     *     group: \MarkupCarve\Carve\Node,
     *     cells: array<int, \MarkupCarve\Carve\Node|null>,
     * }> $grid
     *
     * @return array<int, array<int, string|null>>
     */
    private function texts(array $grid): array
    {
        $text = static fn (?Node $cell): ?string => $cell === null ? null : Carve::plainText($cell->children[0] ?? []);

        return array_map(static fn (array $row): array => array_map($text, $row['cells']), $grid);
    }
}
