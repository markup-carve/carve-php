<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Block;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TableParsePathTest extends TestCase
{
    /**
     * C2-B-211, C2-B-212: column alignment comes from the head rows; the body rows below them do not move a head cell.
     */
    public function testColumnAlignmentReadsEveryHeadRow(): void
    {
        $tree = json_decode(Carve::toJson("|= a |={>} b |\n|={<} c |= d |\n| 1 | 2 |\n| 3 | 4 |\n"), true);
        $columns = $tree['children'][0]['columns'];
        self::assertSame(['left', 'right'], array_column($columns, 'resolvedAlign'));
    }

    /**
     * C2-I-125: the inline pass of a cell sees the definitions of the whole document, also those after the table.
     */
    public function testCellsSeeLaterDefinitions(): void
    {
        self::assertStringContainsString('<td><a href="/u">x</a></td>', Carve::toHtml("| [x][d] |\n\n[d]: /u\n"));
        self::assertStringContainsString('<td>[x][d]</td>', Carve::toHtml("| [x][d] |\n"));
        self::assertStringContainsString('role="doc-noteref"', Carve::toHtml("| [^n] |\n\n[^n]: note\n"));
    }

    /**
     * C2-B-184, C2-L-020: a cell joined from a continuation row records where the fragment of that row starts.
     */
    public function testJoinedCellRecordsItsWraps(): void
    {
        $layout = Carve::parseWithLayout("| a | b |\n+ c | d |\n| e | f |\n")->layout;
        $wraps = array_values(array_filter(array_map(static fn (array $n): ?array => $n['wraps'] ?? null, $layout['nodes'])));
        self::assertCount(2, $wraps);
        self::assertSame([['line' => 1, 'offset' => 2]], array_map(
            static fn (array $w): array => ['line' => $w['line'], 'offset' => $w['offset']],
            $wraps[0],
        ));
    }

    /**
     * C2-B-184 (carve#6691): a cell covering several grid positions joins its fragments position by position.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function spannedCellFragmentProvider(): array
    {
        return [
            'two columns' => ["| a | < |\n+ b | c |\n+ d | e |\n", '<td colspan="2">a b d c e</td>'],
            'three columns' => ["| a | < | < |\n+ b | c | d |\n+ e | f | g |\n", '<td colspan="3">a b e c f d g</td>'],
            'span after a plain cell' => ["| p | a | < |\n+ q | b | c |\n+ r | d | e |\n", '<td colspan="2">a b d c e</td>'],
            'empty fragment' => ["| a | < |\n+ | c |\n+ d | e |\n", '<td colspan="2">a d c e</td>'],
            'row span' => ["| a | b |\n+ c | d |\n| ^ | e |\n+ f | g |\n", '<td rowspan="2">a c f</td>'],
        ];
    }

    #[DataProvider('spannedCellFragmentProvider')]
    public function testSpannedCellJoinsFragmentsColumnByColumn(string $source, string $cell): void
    {
        self::assertStringContainsString($cell, Carve::toHtml($source));
    }

    /**
     * C2-L-013: a cell whose last joined fragment comes from an earlier line still ranges to its last source fragment.
     */
    public function testSpannedCellRangeEndsAtItsLastSourceFragment(): void
    {
        $source = "| a | < |\n+ b | c |\n+ d | |\n";
        $layout = Carve::parseWithLayout($source)->layout;
        $cells = array_values(array_filter($layout['nodes'], static fn (array $n): bool => isset($n['wraps'])));
        self::assertCount(1, $cells);
        self::assertSame(strpos($source, 'd') + 2, $cells[0]['endByte']);
    }
}
