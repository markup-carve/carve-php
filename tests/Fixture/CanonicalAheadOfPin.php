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
            'md' => [
                // The sidecar writes a blank line between `> - a` and the
                // heading below it, which loosens an item the same document's
                // `.html` renders tight; CARVE-P11-047 drops it above an ATX
                // heading. markup-carve/carve#2300 re-cuts the sidecar, and this
                // entry leaves with the bump that carries it.
                '84-single-line-headings-10' => "> - a\n>   ### b \\###\n",
            ],
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
