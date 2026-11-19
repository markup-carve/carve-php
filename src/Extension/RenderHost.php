<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use Closure;
use MarkupCarve\Carve\Html\Escape;

/**
 * The host helpers an extension renderer receives (C2-X-033): child lists, plain text, escaping and generated ids.
 */
final class RenderHost
{
    /**
     * @param \Closure(array<\MarkupCarve\Carve\Node>): string $blocks
     * @param \Closure(array<\MarkupCarve\Carve\Node>): string $inlines
     * @param \Closure(array<\MarkupCarve\Carve\Node>): string $plainText
     * @param \Closure(string): string $nextId
     * @param \Closure(string): bool $idTaken Whether an authored or generated id is already reserved.
     * @param string $profile
     * @param string $groupLabel The `tabsGroup` label (C2-X-047).
     */
    public function __construct(
        public readonly Closure $blocks,
        public readonly Closure $inlines,
        public readonly Closure $plainText,
        public readonly Closure $nextId,
        public readonly string $profile,
        public readonly string $groupLabel,
        public readonly Closure $idTaken,
    ) {
    }

    public static function escapeText(string $value): string
    {
        return Escape::text($value);
    }

    public static function escapeAttribute(string $value): string
    {
        return Escape::attr($value);
    }
}
