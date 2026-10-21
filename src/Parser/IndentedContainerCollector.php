<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

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
     * @param \MarkupCarve\Carve\Parser\IndentedContainerColumn $column
     *
     * @throws \LogicException
     *
     * @return \Generator<array{index: int, line: string, kind: 'blank'|'content'|'owned-continuation'|'outside', precedingBlanks: list<int>}>
     */
    public static function admissions(
        array &$lines,
        int &$index,
        int $count,
        IndentedContainerColumn $column,
    ): Generator {
        $precedingBlanks = [];
        while ($index < $count) {
            $at = $index;
            $line = $lines[$at];
            $kind = $column->classify($line);

            yield [
                'index' => $at,
                'line' => $line,
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
