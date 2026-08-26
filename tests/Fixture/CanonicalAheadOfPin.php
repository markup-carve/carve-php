<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Fixture;

use function array_key_exists;

/**
 * Canonical fixtures implemented by this engine ahead of its spec pin.
 */
final class CanonicalAheadOfPin
{
    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [];
    }

    public static function declares(string $slug): bool
    {
        return array_key_exists($slug, self::all());
    }

    public static function get(string $slug): string
    {
        return self::all()[$slug];
    }
}
