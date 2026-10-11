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
     * Keyed by render target, then by slug.
     *
     * @return array<string, array<string, string>>
     */
    public static function all(): array
    {
        return [
            'fmt' => [],
            'md' => [],
        ];
    }

    public static function declares(string $target, string $slug): bool
    {
        return array_key_exists($slug, self::all()[$target] ?? []);
    }

    public static function get(string $target, string $slug): string
    {
        return self::all()[$target][$slug];
    }
}
