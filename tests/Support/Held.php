<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Support;

final class Held
{
    /**
     * @var array<string, true>|null
     */
    private static ?array $ids = null;

    public static function has(string $id): bool
    {
        if (self::$ids === null) {
            $ids = [];
            foreach (file(__DIR__ . '/../HELD.md', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $key = strstr($line, ':', true);
                if ($key !== false) {
                    $ids[$key] = true;
                }
            }
            self::$ids = $ids;
        }

        return isset(self::$ids[$id]);
    }
}
