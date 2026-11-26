<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeEquality;
use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TextLineFeedLossTest extends TestCase
{
    /**
     * @return array<string, array{array<mixed>, string, array<string>}>
     */
    public static function lineFeeds(): array
    {
        $text = static fn (string $value): array => ['type' => 'text', 'value' => $value];

        return [
            'paragraph' => [
                ['type' => 'paragraph', 'children' => [$text("line\nnext")]],
                "line\nnext\n",
                ['field-unspellable /children/0/children/0/value'],
            ],
            'heading' => [
                ['type' => 'heading', 'level' => 1, 'children' => [$text("a\nb")]],
                "= a\n  b\n",
                ['field-unspellable /children/0/children/0/value'],
            ],
            'leading line feed' => [
                ['type' => 'paragraph', 'children' => [$text("\na")]],
                "\na\n",
                ['field-unspellable /children/0/children/0/value'],
            ],
            'space before line feed' => [
                ['type' => 'paragraph', 'children' => [$text("a \nb")]],
                "a \nb\n",
                ['field-unspellable /children/0/children/0/value'],
            ],
            'inside emphasis' => [
                ['type' => 'paragraph', 'children' => [['type' => 'emphasis', 'children' => [$text("a\nb")]]]],
                "/a\nb/\n",
                ['field-unspellable /children/0/children/0/children/0/value'],
            ],
            'two text nodes' => [
                ['type' => 'paragraph', 'children' => [$text("a\nb"), $text("c\nd")]],
                "a\nbc\nd\n",
                ['field-unspellable /children/0/children/0/value', 'field-unspellable /children/0/children/1/value'],
            ],
            'before a soft break' => [
                ['type' => 'paragraph', 'children' => [$text("a\nb"), ['type' => 'soft_break'], $text('c')]],
                "a\nb\nc\n",
                ['field-unspellable /children/0/children/0/value'],
            ],
            'block opener on the next line' => [
                ['type' => 'paragraph', 'children' => [$text("a\n- b")]],
                "a\n- b\n",
                ['field-unspellable /children/0/children/0/value'],
            ],
            'in a block quote' => [
                ['type' => 'block_quote', 'children' => [['type' => 'paragraph', 'children' => [$text("a\nb")]]]],
                "> a\n> b\n",
                ['field-unspellable /children/0/children/0/children/0/value'],
            ],
            'carriage return before the line feed' => [
                ['type' => 'paragraph', 'children' => [$text("a\r\nb")]],
                "a\r\nb\n",
                ['field-unspellable /children/0/children/0/value'],
            ],
        ];
    }

    /**
     * @param array<mixed> $block
     * @param string $source
     * @param array<string> $losses
     *
     * @return void
     */
    #[DataProvider('lineFeeds')]
    public function testLineFeedInTextIsReportedAtItsValue(array $block, string $source, array $losses): void
    {
        $result = Writer::writeBlocksWithDiagnostics([$block]);

        $this->assertSame($source, $result->source);
        $this->assertSame($losses, array_map(static fn ($d): string => $d->code . ' ' . $d->path, $result->diagnostics));
        $read = TreeEncoder::decodedForWriter(Carve::parse($result->source));
        $this->assertFalse(TreeEquality::equal([$block], $read['children']));
    }

    public function testDelimitedCellKeepsItsLineFeed(): void
    {
        $cell = [
            'type' => 'table_cell',
            'header' => false,
            'children' => [['type' => 'cell_paragraph', 'children' => [['type' => 'text', 'value' => "a\nb"]]]],
        ];
        $table = [
            'type' => 'table',
            'columns' => [['type' => 'table_column']],
            'children' => [['type' => 'table_body', 'children' => [['type' => 'table_row', 'children' => [$cell]]]]],
        ];

        $result = Writer::writeBlocksWithDiagnostics([$table]);

        $this->assertSame("{table header-rows=0}\n```csv\n\"a\nb\"\n```\n", $result->source);
        $this->assertSame([], $result->codes());
    }
}
