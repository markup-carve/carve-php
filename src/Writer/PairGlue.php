<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

/**
 * The emphasis and strong nodes one write of `Writer\Check\TreeGroupWriter` braced for their neighbors (carve-js
 * `gluedPart` and `markForm`), by path. Collected only while that write runs.
 *
 * @internal
 */
final class PairGlue
{
    /**
     * @var array<string, true>|null
     */
    private static ?array $paths = null;

    public static function start(): void
    {
        self::$paths = [];
    }

    public static function note(string $path): void
    {
        if (self::$paths !== null) {
            self::$paths[$path] = true;
        }
    }

    /**
     * Ends the collection.
     *
     * @return array<string, true>
     */
    public static function take(): array
    {
        $paths = self::$paths ?? [];
        self::$paths = null;

        return $paths;
    }
}
