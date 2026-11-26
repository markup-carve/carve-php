<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Resolve;

use MarkupCarve\Carve\Node;

/**
 * The child nodes of a node in resolution order (C2-A-025): `title`, then `children`, then `caption`,
 * then every other node-bearing slot. Opaque JSON is never searched (C2-A-029).
 */
final class Walk
{
    /**
     * @var array<string, true>
     */
    public const OPAQUE = [

        'attrs' => true,
        'data' => true,
        'props' => true,
        'payload' => true,
        'source' => true,
        'resources' => true,
        'extensions' => true,
        'columns' => true,
    ];

    /**
     * The extensions with a registered renderer, by name and version (C2-X-033), for the walk of C2-X-032.
     *
     * @var array<string, mixed>
     */
    public static array $rendered = [];

    /**
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return array<\MarkupCarve\Carve\Node>
     */
    public static function kids(Node $node): array
    {
        // C2-N-090: a snippet definition renders nothing, and its notes take no number.
        if ($node->type === 'snippet_definition') {
            return [];
        }
        if (isset($node->f['fallback']) && ($node->type === 'block_extension' || $node->type === 'inline_extension')) {
            return self::extensionView($node);
        }
        $out = [];
        if (isset($node->f['title'])) {
            self::collect($node->f['title'], $out);
        }
        foreach ($node->children ?? [] as $child) {
            $out[] = $child;
        }
        // C2-N-074: the caption of an expanded include is skipped; its copy in the child is walked.
        $expanded = $node->type === 'include' && $node->children !== null;
        foreach ($node->f as $key => $value) {
            if ($key === 'title' || isset(self::OPAQUE[$key]) || !is_array($value) && !$value instanceof Node) {
                continue;
            }
            if ($expanded && $key === 'caption') {
                continue;
            }
            self::collect($value, $out);
        }

        return $out;
    }

    /**
     * The `children` a walk enters: none of a snippet definition, which renders nothing (C2-N-090).
     *
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return array<\MarkupCarve\Carve\Node>|null
     */
    public static function children(Node $node): ?array
    {
        return $node->type === 'snippet_definition' ? null : $node->children;
    }

    /**
     * C2-X-032: the one view of an extension node that resolution visits: its children and named slots when a renderer
     * is registered for it or for the extension node whose slots hold it, and its fallback otherwise.
     *
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return array<\MarkupCarve\Carve\Node>
     */
    public static function extensionView(Node $node): array
    {
        $out = [];
        if (!$node->slotView && !isset(self::$rendered[self::key($node)])) {
            self::collect($node->f['fallback'], $out);

            return $out;
        }
        foreach ($node->children ?? [] as $child) {
            $out[] = $child;
        }
        foreach (is_array($node->f['slots'] ?? null) ? $node->f['slots'] : [] as $list) {
            foreach (is_array($list) ? $list : [] as $kid) {
                if ($kid instanceof Node) {
                    $kid->slotView = true;
                    $out[] = $kid;
                }
            }
        }

        return $out;
    }

    public static function key(Node $node): string
    {
        return PlainText::str($node->f['name'] ?? '') . "\0" . PlainText::str($node->f['version'] ?? '');
    }

    /**
     * Whether a field value can hold a node; kids() finds none in an array of scalars (a list marker).
     *
     * @param array<mixed> $value
     *
     * @return bool
     */
    public static function nests(array $value): bool
    {
        foreach ($value as $v) {
            if (is_array($v) || $v instanceof Node) {
                return true;
            }
        }

        return false;
    }

    /**
     * The nodes under a root in pre-order (the order of kids()), each with the index after its subtree,
     * built with an explicit stack so depth costs no PHP recursion.
     *
     * @param \MarkupCarve\Carve\Node $root
     *
     * @return array{0: array<int, \MarkupCarve\Carve\Node>, 1: array<int, int>} Nodes and subtree ends.
     */
    public static function order(Node $root): array
    {
        $nodes = [];
        $end = [];
        $n = 0;
        $stack = [$root];
        $top = 1;
        while ($top > 0) {
            $node = $stack[--$top];
            if (is_int($node)) {
                $end[$node] = $n;

                continue;
            }
            $nodes[$n] = $node;
            $end[$n] = $n + 1;
            $i = $n++;
            $kids = self::kids($node);
            if ($kids === []) {
                continue;
            }
            $stack[$top++] = $i;
            for ($k = count($kids); $k > 0;) {
                $stack[$top++] = $kids[--$k];
            }
        }

        return [$nodes, $end];
    }

    /**
     * The pre-order ranges of the root's children other than footnote definitions, neighbors merged:
     * the body, read before the notes (C2-H-087).
     *
     * @param \MarkupCarve\Carve\Node $root
     * @param array<int, int> $pos The index of each child, by object id.
     * @param array<int, int> $end The index after the subtree, by index.
     *
     * @return array<array{0: int, 1: int}>
     */
    public static function bodyRanges(Node $root, array $pos, array $end): array
    {
        $ranges = [];
        $from = -1;
        $to = -1;
        foreach ($root->children ?? [] as $child) {
            $at = $child->type === 'footnote' ? null : $pos[spl_object_id($child)] ?? null;
            if ($at === null) {
                continue;
            }
            if ($at !== $to) {
                if ($from >= 0) {
                    $ranges[] = [$from, $to];
                }
                $from = $at;
            }
            $to = $end[$at];
        }
        if ($from >= 0) {
            $ranges[] = [$from, $to];
        }

        return $ranges;
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
     * @param array<\MarkupCarve\Carve\Node> $out
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
