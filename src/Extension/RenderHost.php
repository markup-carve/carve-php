<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use Closure;
use MarkupCarve\Carve\Html\Escape;
use MarkupCarve\Carve\Node;

/**
 * The host helpers an extension renderer receives (C2-X-033): child lists, plain text, escaping, attributes, generated
 * ids, and the render mode (C2-X-052) with the labels and the profile.
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
     * @param (\Closure(\MarkupCarve\Carve\Node, bool, bool): string)|null $attributes The authored attributes of a node,
     *   and the generated source line of a block element.
     * @param string $mode `interactive` or `static`.
     * @param array<string, string> $labels
     * @param bool $sourceLines
     * @param int $blockDepth The indentation level of the current block, in two-space units (C2-H-042).
     * @param (\Closure(string): bool)|null $urlAllowed Whether a URL passes the URL policy (C2-H-128).
     */
    public function __construct(
        public readonly Closure $blocks,
        public readonly Closure $inlines,
        public readonly Closure $plainText,
        public readonly Closure $nextId,
        public readonly string $profile,
        public readonly string $groupLabel,
        public readonly Closure $idTaken,
        private readonly ?Closure $attributes = null,
        public readonly string $mode = 'interactive',
        public readonly array $labels = [],
        public readonly bool $sourceLines = false,
        public readonly int $blockDepth = 0,
        private readonly ?Closure $urlAllowed = null,
    ) {
    }

    /**
     * C2-H-128: whether a URL passes the URL policy of the render.
     */
    public function isUrlAllowed(string $url): bool
    {
        return $this->urlAllowed === null || ($this->urlAllowed)($url);
    }

    /**
     * C2-X-033: the attribute string of a node, with a leading space, or empty. `$bare` writes only the generated
     * source line of a block element.
     */
    public function attributes(Node $node, bool $block = true, bool $bare = false): string
    {
        return $this->attributes === null ? '' : ($this->attributes)($node, $block, $bare);
    }

    public function label(string $key, string $default = ''): string
    {
        return $this->labels[$key] ?? $default;
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
