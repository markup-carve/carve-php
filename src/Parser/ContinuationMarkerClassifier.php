<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

/**
 * @internal
 */
final class ContinuationMarkerClassifier
{
    public static function columnAt(string $line, int $offset, int $column = 0): int
    {
        for ($at = 0, $end = min($offset, strlen($line)); $at < $end; $at++) {
            $column += $line[$at] === "\t" ? 4 - $column % 4 : 1;
        }

        return $column;
    }
}
