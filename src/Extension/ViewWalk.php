<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use Closure;
use MarkupCarve\Carve\Node;

/**
 * Internal: visits every child list of a render view, each list before the nodes in it, and never a fallback.
 */
final class ViewWalk
{
    /**
     * @var array<string, true>
     */
    private const SKIP = ['fallback' => true, 'source' => true, 'payload' => true, 'props' => true, 'attrs' => true];

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param \Closure(array<\MarkupCarve\Carve\Node>): array<\MarkupCarve\Carve\Node> $edit Returns the list, edited.
     *
     * @return void
     */
    public static function edit(Node $node, Closure $edit): void
    {
        if ($node->children !== null) {
            $node->children = array_values($edit($node->children));
            foreach ($node->children as $child) {
                self::edit($child, $edit);
            }
        }
        foreach ($node->f as $key => $value) {
            if (is_array($value) && !isset(self::SKIP[$key])) {
                $node->f[$key] = self::list($value, $edit);
            }
        }
    }

    /**
     * @param array<mixed> $value
     * @param \Closure(array<\MarkupCarve\Carve\Node>): array<\MarkupCarve\Carve\Node> $edit
     *
     * @return array<mixed>
     */
    private static function list(array $value, Closure $edit): array
    {
        if ($value !== [] && array_is_list($value) && array_filter($value, static fn (mixed $v): bool => !$v instanceof Node) === []) {
            /** @var array<\MarkupCarve\Carve\Node> $value */
            $value = array_values($edit($value));
            foreach ($value as $child) {
                self::edit($child, $edit);
            }

            return $value;
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::list($item, $edit);
            } elseif ($item instanceof Node) {
                self::edit($item, $edit);
            }
        }

        return $value;
    }
}
