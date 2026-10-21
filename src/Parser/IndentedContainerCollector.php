<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use Closure;
use Generator;
use LogicException;
use MarkupCarve\Carve\Parser\Utility\IndentationHelper;

/**
 * Advance list, description, and footnote body cursors through one admission path.
 *
 * The host still decides whether to consume or stop at each admitted line.
 *
 * @internal
 */
final class IndentedContainerCollector
{
    /**
     * @param array<string> $lines
     * @param int $index
     * @param int $count
     * @param int $markerColumn
     * @param \MarkupCarve\Carve\Parser\IndentedContainerColumn $column
     * @param \Closure(int): string $sourceLineAt
     *
     * @throws \LogicException
     *
     * @return \Generator<array{index: int, line: string, source: string, sourceColumn: int, kind: 'blank'|'content'|'below'|'outside', precedingBlanks: array<int>}>
     */
    public static function admissions(
        array $lines,
        int &$index,
        int $count,
        int $markerColumn,
        IndentedContainerColumn $column,
        Closure $sourceLineAt,
    ): Generator {
        $precedingBlanks = [];
        while ($index < $count) {
            $at = $index;
            $line = $lines[$at];
            $source = $sourceLineAt($at);
            $reach = ContainerPrefix::composedReach($source);
            $sourceColumn = $reach[count($reach) - 1]['to'];
            $classification = $column->classify($line, $sourceColumn);
            $kind = $classification === 'outside'
                ? ($sourceColumn < $markerColumn ? 'outside' : 'below') : $classification;

            yield [
                'index' => $at,
                'line' => $line,
                'source' => $source,
                'sourceColumn' => $sourceColumn,
                'kind' => $kind,
                'precedingBlanks' => $precedingBlanks,
            ];

            if ($index <= $at) {
                throw new LogicException('Indentation container host did not advance');
            }
            if (IndentationHelper::isBlankLine($line)) {
                $precedingBlanks[] = $at;
            } else {
                $precedingBlanks = [];
            }
        }
    }
}
