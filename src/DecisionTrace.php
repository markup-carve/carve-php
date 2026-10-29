<?php

declare(strict_types=1);

namespace MarkupCarve\Carve;

use MarkupCarve\Carve\Parser\BlockParser;

/**
 * The decision trace of carve docs/decision-trace.md (format version 1): one
 * block record per source line from the ownership walk, with PART 0's step
 * ids, for `trace-diff.mjs --engine php` to compare with the spec model.
 */
final class DecisionTrace
{
    /**
     * @var string
     */
    public const FORMAT = 'carve-decision-trace';

    /**
     * @var int
     */
    public const VERSION = 1;

    /**
     * @var string
     */
    public const ENGINE = 'carve-php';

    /**
     * @return array<int, array<string, int|string|null>>
     */
    public static function records(string $source): array
    {
        return (new BlockParser())->traceDecisions($source);
    }

    /**
     * The trace as JSON lines: the header, then one record per line.
     */
    public static function jsonLines(string $source): string
    {
        $rows = [['trace' => self::FORMAT, 'version' => self::VERSION, 'engine' => self::ENGINE], ...self::records($source)];
        $out = '';
        foreach ($rows as $row) {
            $out .= json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        }

        return $out;
    }
}
