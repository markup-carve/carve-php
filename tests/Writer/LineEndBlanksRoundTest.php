<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-151: the whole-group read-back of neighboring paired inlines compares the tree without the spaces and tabs
 * that end a line of text, so they no longer make the writer refuse (carve-php#5640). Outputs are carve-js `next2`.
 */
final class LineEndBlanksRoundTest extends TestCase
{
    /**
     * @return array<string, array{array<array<mixed>>, string}>
     */
    public static function trees(): array
    {
        $text = static fn (string $value): array => ['type' => 'text', 'value' => $value];
        $span = static fn (string $type, string $value): array => ['type' => $type, 'children' => [$text($value)]];
        $risk = [$span('strong', 'a'), $text(' `x`'), $span('emphasis', 'a')];
        $cell = static fn (bool $header, array $inlines): array => [
            'type' => 'table_cell',
            'header' => $header,
            'children' => [['type' => 'cell_paragraph', 'children' => $inlines]],
        ];
        $table = static fn (array $head, array $body): array => [
            'type' => 'table',
            'columns' => [['type' => 'table_column']],
            'children' => [
                ['type' => 'table_head', 'children' => [['type' => 'table_row', 'children' => [$cell(true, $head)]]]],
                ['type' => 'table_body', 'children' => [['type' => 'table_row', 'children' => [$cell(false, $body)]]]],
            ],
        ];
        $paragraph = static fn (array $inlines): array => ['type' => 'paragraph', 'children' => $inlines];
        $soft = ['type' => 'soft_break'];

        return [
            'blank head cell' => [[$table([$text(' ')], $risk)], "|= |\n| *a* \\`x\\`/a/ |\n"],
            'tab head cell' => [[$table([$text("\t")], $risk)], "|= |\n| *a* \\`x\\`/a/ |\n"],
            'trailing blank in the risk cell' => [[$table([$text('h')], [...$risk, $text(' ')])], "|= h |\n| *a* \\`x\\`/a/ |\n"],
            'blank text before a soft break' => [[$paragraph([...$risk, $text(" \t"), $soft, $text('b')])], "*a* \\`x\\`/a/\nb\n"],
            'trailing blank of a heading' => [
                [['type' => 'heading', 'level' => 2, 'children' => [...$risk, $text('b  ')]]],
                "== *a* \\`x\\`{/a/}b\n",
            ],
            'blank in another block' => [[$paragraph([$text('c '), $soft, $text('d')]), $paragraph($risk)], "c\nd\n\n*a* \\`x\\`/a/\n"],
            'delimited cell keeps its blanks' => [
                [$table([$text(' a ')], [$text('b')]), $paragraph($risk)],
                "{table}\n```csv\n a \nb\n```\n\n*a* \\`x\\`/a/\n",
            ],
        ];
    }

    /**
     * @param array<array<mixed>> $blocks
     * @param string $source
     *
     * @return void
     */
    #[DataProvider('trees')]
    public function testLineEndBlanksDoNotRefuseTheRound(array $blocks, string $source): void
    {
        $result = Writer::writeBlocksWithDiagnostics($blocks);

        $this->assertSame([], $result->diagnostics);
        $this->assertSame($source, $result->source);
    }
}
