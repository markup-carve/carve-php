<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-066, C2-W-136: a delimited table with several bodies keeps the rows of every body (carve#6405).
 */
final class DelimitedTableBodiesTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function sources(): array
    {
        return [
            'two bodies of one row' => ["{table body-rows=1,1}\n```csv\nh\na\nb\n```\n"],
            'second body of two rows' => ["{table body-rows=1,2}\n```csv\nh\na\nb\nc\n```\n"],
            'three bodies' => ["{table body-rows=2,1,1}\n```csv\nh\na\nb\nc\nd\n```\n"],
            'tab separated' => ["{table body-rows=1,1}\n```tsv\nh\tk\na\t1\nb\t2\n```\n"],
            'with a footer' => ["{table body-rows=1,1 footer-rows=1}\n```csv\nh\na\nb\nf\n```\n"],
            // carve#6424: a padded cell before a foot keeps the delimited form.
            'padded cell before a footer' => ["{table footer-rows=1}\n```csv\nh\n a\nc\n```\n"],
            // carve#6425: a lone empty record stays quoted when an empty body follows it.
            'lone empty record before an empty body' => ["{table body-rows=0}\n```csv\n\"\"\n```\n"],
            // C2-B-230: a body's leading header rows are spelled by body-header-rows, not by the pipe form.
            'body header row' => ["{table body-rows=1 body-header-rows=1}\n```csv\nh\n b\nc\n```\n"],
            'multiline body header row' => ["{table body-rows=1 body-header-rows=1}\n```tsv\n\"h\n\"\t x\nh\tc\n1\t  a  \n```\n"],
            'header row in the second body' => [
                "{table body-rows=0,1,0 body-header-rows=0,1,0}\n```csv\n,b , h\n,b,\nh, x,\"h\nb\"\n```\n",
            ],
        ];
    }

    /**
     * @param string $source
     *
     * @return void
     */
    #[DataProvider('sources')]
    public function testEveryBodyKeepsItsRows(string $source): void
    {
        self::assertSame($source, Carve::format($source));
    }

    /**
     * C2-W-066: markup in an earlier body rules out the delimited form even when a later body asks for it.
     *
     * @return void
     */
    public function testEarlierBodyWithMarkupRulesOutTheDelimitedForm(): void
    {
        $row = static fn (array $inline, bool $header = false): array => [
            'type' => 'table_row',
            'children' => [
                [
                    'type' => 'table_cell',
                    'header' => $header,
                    'children' => [['type' => 'cell_paragraph', 'children' => [$inline]]],
                ],
            ],
        ];
        $emphasis = ['type' => 'emphasis', 'children' => [['type' => 'text', 'value' => 'a']]];
        $tree = [
            'type' => 'document',
            'children' => [
                [
                    'type' => 'table',
                    'columns' => [['type' => 'table_column']],
                    'children' => [
                        ['type' => 'table_head', 'children' => [$row(['type' => 'text', 'value' => 'h'], true)]],
                        ['type' => 'table_body', 'children' => [$row($emphasis)]],
                        ['type' => 'table_body', 'children' => [$row(['type' => 'text', 'value' => ' b'])]],
                    ],
                ],
            ],
        ];

        $result = Writer::writeBlocksWithDiagnostics($tree['children']);
        $written = $result->source;

        self::assertStringStartsWith("{body-rows=1,1}\n|= h |\n", $written);
        self::assertStringContainsString('| /a/ |', $written);
        // C2-B-171, C2-W-117: the pipe form trims the leading space of ` b` (carve-php#5638).
        self::assertSame(
            ['field-unspellable /children/0/children/2/children/0/children/0/children/0/children/0/value'],
            array_map(static fn ($d): string => $d->code . ' ' . $d->path, $result->diagnostics),
        );
    }
}
