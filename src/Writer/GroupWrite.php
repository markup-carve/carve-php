<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

/**
 * One write of `Writer\Check\TreeGroupWriter`, as carve-js writes a group: the inline writer leaves the escapes it
 * cannot decide alone to the rounds, and reports the emphasis and strong nodes it braced for their neighbors (carve-js
 * `gluedPart` and `markForm`), by path. The `Writer` memo keys on whether one runs.
 *
 * @internal
 */
final class GroupWrite
{
    /**
     * @var array<string, true>|null
     */
    private static ?array $paths = null;

    public static function active(): bool
    {
        return self::$paths !== null;
    }

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
