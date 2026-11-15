<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Resolve;

use MarkupCarve\Carve2\Node;

/**
 * The child nodes of a node in resolution order (C2-A-025): `title`, then `children`, then `caption`,
 * then every other node-bearing slot. Opaque JSON is never searched (C2-A-029).
 */
final class Walk
{
    /**
     * @var array<string, true>
     */
    private const OPAQUE = ['attrs' => true, 'data' => true, 'props' => true, 'payload' => true, 'source' => true, 'resources' => true, 'extensions' => true, 'columns' => true];

    /**
     * @param \MarkupCarve\Carve2\Node $node
     *
     * @return array<\MarkupCarve\Carve2\Node>
     */
    public static function kids(Node $node): array
    {
        $out = [];
        if (isset($node->f['title'])) {
            self::collect($node->f['title'], $out);
        }
        foreach ($node->children ?? [] as $child) {
            $out[] = $child;
        }
        foreach ($node->f as $key => $value) {
            if ($key === 'title' || isset(self::OPAQUE[$key]) || !is_array($value) && !$value instanceof Node) {
                continue;
            }
            self::collect($value, $out);
        }

        return $out;
    }

    /**
     * The nodes under a root in pre-order (the order of kids()), each with the index after its subtree,
     * built with an explicit stack so depth costs no PHP recursion.
     *
     * @param \MarkupCarve\Carve2\Node $root
     *
     * @return array{0: array<int, \MarkupCarve\Carve2\Node>, 1: array<int, int>, 2: array<int, int>} Nodes, subtree ends, index by object id.
     */
    public static function order(Node $root): array
    {
        $nodes = [];
        $end = [];
        $pos = [];
        $n = 0;
        $stack = [$root];
        $top = 1;
        while ($top > 0) {
            $node = $stack[--$top];
            unset($stack[$top]);
            if (is_int($node)) {
                $end[$node] = $n;

                continue;
            }
            $nodes[$n] = $node;
            $end[$n] = $n + 1;
            $pos[spl_object_id($node)] ??= $n;
            $i = $n++;
            $children = $node->children ?? [];
            $extra = false;
            foreach ($node->f as $key => $value) {
                if (is_array($value) || $value instanceof Node) {
                    if (!isset(self::OPAQUE[$key])) {
                        $extra = true;

                        break;
                    }
                }
            }
            $kids = $extra ? self::kids($node) : $children;
            $k = count($kids);
            if ($k === 0) {
                continue;
            }
            $stack[$top++] = $i;
            while ($k > 0) {
                $stack[$top++] = $kids[--$k];
            }
        }

        return [$nodes, $end, $pos];
    }

    /**
     * @param array<int, int> $list Ascending indexes.
     * @param int $from
     *
     * @return int The position of the first index not below $from.
     */
    public static function firstAtOrAfter(array $list, int $from): int
    {
        $lo = 0;
        $hi = count($list);
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($list[$mid] < $from) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        return $lo;
    }

    /**
     * @param mixed $value
     * @param array<\MarkupCarve\Carve2\Node> $out
     *
     * @return void
     */
    private static function collect(mixed $value, array &$out): void
    {
        if ($value instanceof Node) {
            $out[] = $value;

            return;
        }
        if (is_array($value)) {
            foreach ($value as $v) {
                self::collect($v, $out);
            }
        }
    }
}
