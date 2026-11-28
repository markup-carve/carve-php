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

    /**
     * @var array<string, true>|null The glue of a spell write, which carve-js decides in every write of inlines.
     */
    private static ?array $spelled = null;

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
        if (self::$spelled !== null) {
            self::$spelled[$path] = true;
        }
    }

    /**
     * Starts collecting the glue of a spell write, which leaves the group write behavior off.
     *
     * @return array<string, true>|null The collection of an enclosing spell write, for `takeSpelled`.
     */
    public static function startSpelled(): ?array
    {
        $outer = self::$spelled;
        self::$spelled = [];

        return $outer;
    }

    /**
     * @param array<string, true>|null $outer
     *
     * @return array<string, true>
     */
    public static function takeSpelled(?array $outer): array
    {
        $paths = self::$spelled ?? [];
        self::$spelled = $outer;

        return $paths;
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
