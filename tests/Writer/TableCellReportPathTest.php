<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5830: a pipe cell loss is reported at the node that has no spelling, as carve-js reports it.
 */
final class TableCellReportPathTest extends TestCase
{
    /**
     * @var string
     */
    private const CELL = '/children/0/children/0/children/0/children/0';

    /**
     * @param array<mixed> $inlines
     *
     * @return array<string, mixed>
     */
    private static function cellTree(array $inlines): array
    {
        $cell = [
            'type' => 'table_cell',
            'header' => false,
            'attrs' => [
                'id' => 'c',
            ],
            'children' => [
                ['type' => 'cell_paragraph', 'children' => $inlines],
            ],
        ];

        return [
            'astVersion' => '2.0',
            'type' => 'document',
            'children' => [
                [
                    'type' => 'table',
                    'columns' => [['type' => 'table_column']],
                    'children' => [['type' => 'table_body', 'children' => [['type' => 'table_row', 'children' => [$cell]]]]],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array{array<mixed>, string, array<string>}>
     */
    public static function lineFeeds(): array
    {
        $p = self::CELL . '/children/0/children';
        $text = static fn (string $value): array => ['type' => 'text', 'value' => $value];

        return [
            'line feed in a text' => [[$text("a\nb")], "|{#c} a b |\n", ["field-unspellable $p/0/value"]],
            'line feed in a nested text' => [
                [$text('a'), ['type' => 'emphasis', 'children' => [$text("c\nd")]]],
                "|{#c} a{/c d/} |\n",
                ["field-unspellable $p/1/children/0/value"],
            ],
            'first of two line feeds' => [
                [$text("a\nb"), ['type' => 'soft_break'], $text("c\nd")],
                "|{#c} a b c d |\n",
                ["field-unspellable $p/0/value"],
            ],
            'soft break' => [[$text('a'), ['type' => 'soft_break'], $text('b')], "|{#c} a b |\n", ["structure-unspellable $p/1"]],
            'hard break inside a span at the end' => [
                [$text('a'), ['type' => 'emphasis', 'children' => [$text('c'), ['type' => 'hard_break']]]],
                "|{#c} a{/c\\ /} |\n",
                ["structure-unspellable $p/1/children/1"],
            ],
            'hard break at the end of the cell' => [[$text('a'), ['type' => 'hard_break']], "|{#c} a\\  |\n", []],
        ];
    }

    /**
     * @param array<mixed> $inlines
     * @param string $source
     * @param array<string> $losses
     *
     * @return void
     */
    #[DataProvider('lineFeeds')]
    public function testLineFeedIsLostByItsNode(array $inlines, string $source, array $losses): void
    {
        $written = Writer::writeWithDiagnostics(self::cellTree($inlines));
        $this->assertSame($source, $written->source);
        $this->assertSame($losses, array_map(static fn ($d): string => $d->code . ' ' . $d->path, $written->diagnostics));
    }
}
