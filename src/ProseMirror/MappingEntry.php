<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\ProseMirror;

use Closure;

/**
 * One checked entry of the `mapping` option: declared (`as` set) or a pair of callbacks (`as` null).
 *
 * @internal
 */
final class MappingEntry
{
    /**
     * @param string|null $as `span` or `div`; null for a callback entry.
     * @param string $class
     * @param array<string>|null $attributes The attributes a declared entry keeps; null for a callback entry, which reads them all.
     * @param bool $content Whether a declared node has content; without it the node is an atom.
     * @param \Closure|null $toTree
     * @param \Closure|null $fromTree
     * @param bool $carve Whether the host node type has a `carve` attribute, so a top-level node keeps its place.
     */
    public function __construct(
        public readonly ?string $as,
        public readonly string $class = '',
        public readonly ?array $attributes = null,
        public readonly bool $content = false,
        public readonly ?Closure $toTree = null,
        public readonly ?Closure $fromTree = null,
        public readonly bool $carve = false,
    ) {
    }
}
