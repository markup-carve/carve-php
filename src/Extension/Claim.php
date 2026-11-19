<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use Closure;
use MarkupCarve\Carve\Node;

/**
 * What a matcher receives for the core node it claims (C2-X-007, C2-X-009, C2-X-011).
 */
final class Claim
{
    /**
     * @param string $form `div`, `fence` or `inline`.
     * @param string $name The source name.
     * @param \MarkupCarve\Carve\Node $node The claimed core node.
     * @param array<string, string> $source The source record (C2-X-024).
     * @param \Closure(\MarkupCarve\Carve\Node): ?string $sourceName The source name of a div in the body.
     * @param \Closure(\MarkupCarve\Carve\Node, string): array<string, string> $divSource The source record of a div.
     */
    public function __construct(
        public readonly string $form,
        public readonly string $name,
        public readonly Node $node,
        public readonly array $source,
        public readonly Closure $sourceName,
        public readonly Closure $divSource,
    ) {
    }

    /**
     * A deep copy of the claimed node, for the fallback of the extension node the transform returns (C2-X-016).
     *
     * @return \MarkupCarve\Carve\Node
     */
    public function copyNode(): Node
    {
        return Extensions::copy($this->node);
    }
}
