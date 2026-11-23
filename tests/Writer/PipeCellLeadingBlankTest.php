<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-B-171, C2-W-117: the padding of a pipe cell is trimmed, so a leading space or tab of its text is a loss
 * (carve-php#5638). A trailing one is insignificant (C2-W-151).
 */
final class PipeCellLeadingBlankTest extends TestCase
{
    /**
     * @return array<string, array{array<array<mixed>>, string, array<string>}>
     */
    public static function cells(): array
    {
        $text = static fn (string $value): array => ['type' => 'text', 'value' => $value];
        $emphasis = ['type' => 'emphasis', 'children' => [$text('c')]];
        $at = '/children/0/children/0/children/1/children/0/children/0/children/0/value';

        return [
            'leading space' => [[$text(' b')], "| /a/ |\n|  b |\n", ["field-unspellable $at"]],
            'leading tab' => [[$text("\tb")], "| /a/ |\n| \tb |\n", ["field-unspellable $at"]],
            'leading space before markup' => [[$text(' b'), $emphasis], "| /a/ |\n|  b{/c/} |\n", ["field-unspellable $at"]],
            'trailing space' => [[$text('b ')], "| /a/ |\n| b |\n", []],
            'space inside markup' => [[['type' => 'emphasis', 'children' => [$text(' c')]]], "| /a/ |\n| {/ c/} |\n", []],
        ];
    }

    /**
     * @param array<array<mixed>> $inlines
     * @param string $source
     * @param array<string> $losses
     *
     * @return void
     */
    #[DataProvider('cells')]
    public function testLeadingBlankOfAPipeCellIsReported(array $inlines, string $source, array $losses): void
    {
        $row = static fn (array $children): array => [
            'type' => 'table_row',
            'children' => [
                ['type' => 'table_cell', 'header' => false, 'children' => [['type' => 'cell_paragraph', 'children' => $children]]],
            ],
        ];
        $table = [
            'type' => 'table',
            'columns' => [['type' => 'table_column']],
            'children' => [
                [
                    'type' => 'table_body',
                    'children' => [$row([['type' => 'emphasis', 'children' => [['type' => 'text', 'value' => 'a']]]]), $row($inlines)],
                ],
            ],
        ];

        $result = Writer::writeBlocksWithDiagnostics([$table]);

        $this->assertSame($source, $result->source);
        $this->assertSame($losses, array_map(static fn ($d): string => $d->code . ' ' . $d->path, $result->diagnostics));
    }
}
