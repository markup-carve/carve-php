<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Test\Block;

use MarkupCarve\Carve2\Carve2;
use PHPUnit\Framework\TestCase;

final class TableParsePathTest extends TestCase
{
    /**
     * C2-B-211, C2-B-212: column alignment comes from the head rows; the body rows below them do not move a head cell.
     */
    public function testColumnAlignmentReadsEveryHeadRow(): void
    {
        $tree = json_decode(Carve2::toJson("|= a |={>} b |\n|={<} c |= d |\n| 1 | 2 |\n| 3 | 4 |\n"), true);
        $columns = $tree['children'][0]['columns'];
        self::assertSame(['left', 'right'], array_column($columns, 'resolvedAlign'));
    }

    /**
     * C2-I-125: the inline pass of a cell sees the definitions of the whole document, also those after the table.
     */
    public function testCellsSeeLaterDefinitions(): void
    {
        self::assertStringContainsString('<td><a href="/u">x</a></td>', Carve2::toHtml("| [x][d] |\n\n[d]: /u\n"));
        self::assertStringContainsString('<td>[x][d]</td>', Carve2::toHtml("| [x][d] |\n"));
        self::assertStringContainsString('role="doc-noteref"', Carve2::toHtml("| [^n] |\n\n[^n]: note\n"));
    }

    /**
     * C2-B-184, C2-L-020: a cell joined from a continuation row records where the fragment of that row starts.
     */
    public function testJoinedCellRecordsItsWraps(): void
    {
        $layout = Carve2::parseWithLayout("| a | b |\n+ c | d |\n| e | f |\n")->layout;
        $wraps = array_values(array_filter(array_map(static fn (array $n): ?array => $n['wraps'] ?? null, $layout['nodes'])));
        self::assertCount(2, $wraps);
        self::assertSame([['line' => 1, 'offset' => 2]], array_map(
            static fn (array $w): array => ['line' => $w['line'], 'offset' => $w['offset']],
            $wraps[0],
        ));
    }
}
