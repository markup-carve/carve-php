<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-117 (carve-php#5939): a table group the written rows read back as another group is reported.
 */
final class UnreadTableGroupTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function lostGroups(): array
    {
        return [
            'foot only, led by header cells' => [
                '<table><tfoot><tr><th>F</th></tr></tfoot></table>',
                "{footer-rows=1}\n|= F |\n",
            ],
        ];
    }

    #[DataProvider('lostGroups')]
    public function testLostGroupIsReported(string $html, string $source): void
    {
        $result = Carve::importHtml($html);
        self::assertSame($source, $result->source);
        $losses = array_map(static fn ($entry): array => [$entry->code, $entry->path], $result->diagnostics);
        self::assertSame([['structure-unspellable', '/children/0/children/0']], $losses);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function keptGroups(): array
    {
        return [
            'head and body' => ['<table><thead><tr><th>H</th></tr></thead><tbody><tr><td>a</td></tr></tbody></table>'],
            'head and data foot' => ['<table><thead><tr><th>H</th></tr></thead><tfoot><tr><td>f</td></tr></tfoot></table>'],
            'head and header foot' => ['<table><thead><tr><th>H</th></tr></thead><tfoot><tr><th>F</th></tr></tfoot></table>'],
            'body only' => ['<table><tr><td>a</td></tr></table>'],
        ];
    }

    #[DataProvider('keptGroups')]
    public function testGroupsThatReadBackReportNothing(string $html): void
    {
        self::assertSame([], Carve::importHtml($html)->diagnostics);
    }

    public function testAHeadOfDataCellsIsSpelledByTheCount(): void
    {
        $cell = [
            'type' => 'table_cell',
            'header' => false,
            'children' => [
                ['type' => 'cell_paragraph', 'children' => [['type' => 'text', 'value' => 'H']]],
            ],
        ];
        $result = Carve::writeWithDiagnostics([
            'type' => 'document',
            'astVersion' => '2.0',
            'children' => [
                [
                    'type' => 'table',
                    'columns' => [['type' => 'table_column']],
                    'children' => [
                        ['type' => 'table_head', 'children' => [['type' => 'table_row', 'children' => [$cell]]]],
                    ],
                ],
            ],
        ]);
        // carve#6663: `header-rows` spells a head that holds data cells (C2-B-186, C2-W-066).
        self::assertSame("{header-rows=1}\n| H |\n", $result->source);
        self::assertSame([], $result->diagnostics);
    }

    public function testAnEmptyHeadIsReported(): void
    {
        $cell = [

            'type' => 'table_cell',
            'header' => false,
            'children' => [
                ['type' => 'cell_paragraph', 'children' => [['type' => 'text', 'value' => 'x']]],
            ],
        ];
        $result = Carve::writeWithDiagnostics([

            'type' => 'document',
            'astVersion' => '2.0',
            'children' => [
                [
                    'type' => 'table',
                    'columns' => [['type' => 'table_column']],
                    'children' => [
                        ['type' => 'table_head', 'children' => []],
                        ['type' => 'table_body', 'children' => [['type' => 'table_row', 'children' => [$cell]]]],
                    ],
                ],
            ],
        ]);
        self::assertSame("| x |\n", $result->source);
        $losses = array_map(static fn ($entry): array => [$entry->code, $entry->path], $result->diagnostics);
        self::assertSame([['structure-unspellable', '/children/0/children/0']], $losses);
    }

    public function testARowWithNoCellIsReportedOnce(): void
    {
        $html = '<table><thead><tr><th>H</th></tr><tr></tr></thead><tbody><tr><td>a</td></tr></tbody></table>';
        $codes = array_map(static fn ($entry): string => $entry->code, Carve::importHtml($html)->diagnostics);
        self::assertSame(['structure-unspellable'], $codes);
    }
}
