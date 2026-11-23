<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-069, applied left to right: of two adjacent spans of one kind the first keeps its bare form, since its closer
 * is followed by `{`, and the later one takes the braced form (carve-php#5641). Outputs are carve-js `next2`.
 */
final class GluedSameKindSpansTest extends TestCase
{
    /**
     * @return array<string, array{array<array<mixed>>, string}>
     */
    public static function trees(): array
    {
        $text = static fn (string $value): array => ['type' => 'text', 'value' => $value];
        $em = static fn (string $value): array => ['type' => 'emphasis', 'children' => [$text($value)]];
        $strong = static fn (string $value): array => ['type' => 'strong', 'children' => [$text($value)]];
        $paragraph = static fn (array ...$inlines): array => [['type' => 'paragraph', 'children' => $inlines]];
        $cell = static function (array ...$inlines): array {
            $cell = ['type' => 'table_cell', 'header' => false, 'children' => [['type' => 'cell_paragraph', 'children' => $inlines]]];
            $body = ['type' => 'table_body', 'children' => [['type' => 'table_row', 'children' => [$cell]]]];

            return [['type' => 'table', 'columns' => [['type' => 'table_column']], 'children' => [$body]]];
        };

        return [
            'two emphasis' => [$paragraph($em('a'), $em('b')), "/a/{/b/}\n"],
            'two strong' => [$paragraph($strong('a'), $strong('b')), "*a*{*b*}\n"],
            'three emphasis' => [$paragraph($em('a'), $em('b'), $em('c')), "/a/{/b/}/c/\n"],
            'four strong' => [$paragraph($strong('a'), $strong('b'), $strong('c'), $strong('d')), "*a*{*b*}*c*{*d*}\n"],
            'in a table cell' => [$cell($em('a'), $em('b')), "| /a/{/b/} |\n"],
            'between texts' => [$paragraph($text('x '), $em('a'), $em('b'), $text(' y')), "x /a/{/b/} y\n"],
            'in strong' => [$paragraph(['type' => 'strong', 'children' => [$em('a'), $em('b')]]), "*/a/{/b/}*\n"],
            'in a link' => [$paragraph(['type' => 'link', 'href' => '/u', 'children' => [$strong('a'), $strong('b')]]), "[*a*{*b*}](/u)\n"],
            'different kinds stay bare' => [$paragraph($em('a'), $strong('b')), "/a/*b*\n"],
        ];
    }

    /**
     * @param array<array<mixed>> $blocks
     * @param string $source
     *
     * @return void
     */
    #[DataProvider('trees')]
    public function testTheLaterGluedSpanIsBraced(array $blocks, string $source): void
    {
        $result = Writer::writeBlocksWithDiagnostics($blocks);

        $this->assertSame([], $result->diagnostics);
        $this->assertSame($source, $result->source);
    }
}
