<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Conformance;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Test\Support\PositionChecker;
use PHPUnit\Framework\TestCase;

/**
 * C2-A-145: the comment between a table and its caption is exempt only below a delimited table.
 */
final class PositionCheckerSiblingTest extends TestCase
{
    public function testDelimitedTableCommentBeforeCaptionIsExempt(): void
    {
        $source = "{table}\n```csv\nName\nAda\n```\n%% source: survey\n^ Members";
        $tree = json_decode(Carve::toJson($source), true, 4096, JSON_THROW_ON_ERROR);

        $this->assertSame([], (new PositionChecker($source))->failures($tree));
    }

    public function testPipeTableOverlappingSiblingCommentIsReported(): void
    {
        $source = "| a |\n%% note\n^ Cap";
        $tree = [
            'type' => 'document',
            'pos' => self::pos(0, 1, 1, 19, 3, 6),
            'children' => [
                ['type' => 'table', 'pos' => self::pos(0, 1, 1, 19, 3, 6)],
                ['type' => 'comment', 'form' => 'line', 'value' => 'note', 'pos' => self::pos(6, 2, 1, 13, 2, 8)],
            ],
        ];

        $this->assertSame(['/children: table overlaps comment (C2-A-145)'], (new PositionChecker($source))->failures($tree));
    }

    /**
     * @return array<string, int>
     */
    private static function pos(int $start, int $line, int $column, int $end, int $endLine, int $endColumn): array
    {
        return [
            'startOffset' => $start,
            'startLine' => $line,
            'startColumn' => $column,
            'endOffset' => $end,
            'endLine' => $endLine,
            'endColumn' => $endColumn,
        ];
    }
}
