<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Node;

/**
 * Internal: the attribute edits the standard extension renderers share.
 */
final class NodeAttributes
{
    /**
     * Whether the authored attributes hold a key, compared without ASCII case.
     */
    public static function authored(?Attrs $attrs, string $name): bool
    {
        foreach ($attrs->pairs ?? [] as [$key]) {
            if (strtolower($key) === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * A shallow copy of the node whose attributes start with `$classes` and end with `$pairs`.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param array<string> $classes
     * @param array<array{0: string, 1: string}> $pairs
     * @param bool $drop Drop authored classes equal to the first of `$classes`.
     *
     * @return \MarkupCarve\Carve\Node
     */
    public static function with(Node $node, array $classes, array $pairs = [], bool $drop = false): Node
    {
        $authored = $node->f['attrs'] ?? null;
        $attrs = $authored instanceof Attrs ? clone $authored : new Attrs();
        $own = $attrs->classes;
        if ($drop && $classes !== []) {
            $own = array_values(array_filter($own, static fn (string $c): bool => $c !== $classes[0]));
        }
        $attrs->classes = [...$classes, ...$own];
        foreach ($pairs as $pair) {
            $attrs->pairs[] = $pair;
        }
        $copy = clone $node;
        $copy->f['attrs'] = $attrs;

        return $copy;
    }
}
