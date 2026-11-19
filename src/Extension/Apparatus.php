<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use Closure;
use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Resolve\PlainText;

/**
 * Internal: the parts the reference apparatus extensions (glossary, index, citations) share: a matcher node, a document
 * transform that resolves it against the whole view, and a renderer that writes the core content the transform built.
 */
final class Apparatus
{
    /**
     * @var array<string, true>
     */
    private const SKIP = ['fallback' => true, 'source' => true, 'payload' => true, 'props' => true, 'attrs' => true];

    /**
     * C2-X-022: core content of the same role; an extension node gives its fallback, or its children as a span.
     *
     * @param array<\MarkupCarve\Carve\Node> $nodes
     *
     * @return array<\MarkupCarve\Carve\Node>
     */
    public static function coreInlines(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if ($node->type === 'inline_extension') {
                $name = PlainText::str($node->f['name'] ?? '');
                if (str_contains($name, '.')) {
                    array_push($out, ...array_map(Extensions::copy(...), PlainText::nodes($node->f['fallback'] ?? [])));

                    continue;
                }
                $span = new Node('span', self::coreInlines($node->children ?? []));
                $attrs = new Attrs();
                $attrs->classes = ['ext-' . $name];
                $span->f['attrs'] = $attrs;
                $out[] = $span;

                continue;
            }
            $copy = Extensions::copy($node);
            if ($copy->children !== null) {
                $copy->children = self::coreInlines($copy->children);
            }
            $out[] = $copy;
        }

        return $out;
    }

    /**
     * A copy of `$nodes` in which every extension node is its fallback, so it can stand in a qualified fallback.
     *
     * @param array<\MarkupCarve\Carve\Node> $nodes
     *
     * @return array<\MarkupCarve\Carve\Node>
     */
    public static function coreBlocks(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if ($node->type === 'block_extension') {
                array_push($out, ...array_map(Extensions::copy(...), PlainText::nodes($node->f['fallback'] ?? [])));

                continue;
            }
            if ($node->type === 'inline_extension') {
                array_push($out, ...self::coreInlines([$node]));

                continue;
            }
            $copy = Extensions::copy($node);
            self::eachList($copy, static fn (array $list): array => self::coreBlocks($list));
            $out[] = $copy;
        }

        return $out;
    }

    /**
     * Visits every node below `$node` in document order, skipping the fallback of an extension node. A visit that
     * returns a node replaces the visited one in its list; `false` skips its subtree.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param \Closure(\MarkupCarve\Carve\Node): (\MarkupCarve\Carve\Node|false|null) $visit
     *
     * @return void
     */
    public static function walk(Node $node, Closure $visit): void
    {
        self::eachList($node, static function (array $list) use ($visit): array {
            foreach ($list as $at => $child) {
                $result = $visit($child);
                if ($result === false) {
                    continue;
                }
                if ($result instanceof Node) {
                    $list[$at] = $result;

                    continue;
                }
                self::walk($child, $visit);
            }

            return $list;
        });
    }

    /**
     * Every child list of a node, the children first, each replaced by what `$edit` returns.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param \Closure(array<\MarkupCarve\Carve\Node>): array<\MarkupCarve\Carve\Node> $edit
     *
     * @return void
     */
    private static function eachList(Node $node, Closure $edit): void
    {
        if ($node->children !== null) {
            $node->children = array_values($edit($node->children));
        }
        foreach ($node->f as $key => $value) {
            if (!is_array($value) || isset(self::SKIP[$key])) {
                continue;
            }
            if (self::isList($value)) {
                $node->f[$key] = array_values($edit(PlainText::nodes($value)));
            } elseif ($key === 'slots') {
                foreach ($value as $slot => $list) {
                    if (is_array($list) && self::isList($list)) {
                        $value[$slot] = array_values($edit(PlainText::nodes($list)));
                    }
                }
                $node->f[$key] = $value;
            }
        }
    }

    /**
     * @param array<mixed> $value
     */
    private static function isList(array $value): bool
    {
        if ($value === [] || !array_is_list($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (!$item instanceof Node) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every authored id of the view, and the generated heading ids, for generated ids that must not take one (C2-X-055).
     *
     * @return array<string, true>
     */
    public static function viewIds(Node $view): array
    {
        $ids = [];
        self::walk($view, static function (Node $node) use (&$ids): null {
            $attrs = $node->f['attrs'] ?? null;
            if ($attrs instanceof Attrs && $attrs->id !== null) {
                $ids[$attrs->id] = true;
            }
            if ($node->type === 'heading' && is_string($node->f['generatedId'] ?? null)) {
                $ids[$node->f['generatedId']] = true;
            }

            return null;
        });

        return $ids;
    }

    /**
     * `$base`, or `$base-2`, `$base-3` and so on: the first that `$taken` does not hold. Takes it.
     *
     * @param string $base
     * @param array<string, true> $taken
     *
     * @return string
     */
    public static function freshId(string $base, array &$taken): string
    {
        $id = $base;
        for ($n = 2; isset($taken[$id]); $n++) {
            $id = $base . '-' . $n;
        }
        $taken[$id] = true;

        return $id;
    }

    /**
     * C2-X-054: an inserted node needs its declaration on the root.
     */
    public static function declare(Node $view, string $name): void
    {
        $declared = is_array($view->f['extensions'] ?? null) ? $view->f['extensions'] : [];
        foreach ($declared as $entry) {
            if (is_array($entry) && ($entry['name'] ?? null) === $name) {
                return;
            }
        }
        $declared[] = ['name' => $name, 'version' => '1', 'required' => false, 'slots' => ['body']];
        usort($declared, static fn (mixed $a, mixed $b): int => strcmp(
            is_array($a) ? PlainText::str($a['name'] ?? '') : '',
            is_array($b) ? PlainText::str($b['name'] ?? '') : '',
        ));
        $view->f['extensions'] = $declared;
    }

    /**
     * A block node that claims a directive: its body is the rewritten directive, its fallback the authored one.
     *
     * @param string $name
     * @param \MarkupCarve\Carve\Node $authored
     * @param array<\MarkupCarve\Carve\Node> $body
     *
     * @return \MarkupCarve\Carve\Node
     */
    public static function claimDirective(string $name, Node $authored, array $body): Node
    {
        $node = Node::at('block_extension', $authored->start, $authored->end);
        $node->children = [];
        $node->f['name'] = $name;
        $node->f['version'] = '1';
        $node->f['slots'] = ['body' => $body];
        $node->f['fallback'] = self::coreBlocks([$authored]);

        return $node;
    }

    /**
     * The schema of a directive claim.
     */
    public static function directiveDefinition(string $name, Closure $render): ExtensionDefinition
    {
        return new ExtensionDefinition($name, '1', ['body'], renderer: $render, buildTimeRenderer: $render);
    }

    /**
     * One renderer for both channels: the transform built core content, so static output is the same (C2-X-052).
     */
    public static function body(Node $node, RenderHost $host): string
    {
        $slots = is_array($node->f['slots'] ?? null) ? $node->f['slots'] : [];

        return ($host->blocks)(PlainText::nodes($slots['body'] ?? []));
    }

    /**
     * The attributes of an inline node with `$base` as its first class and without the keys `$drop` names.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param \MarkupCarve\Carve\Extension\RenderHost $host
     * @param string $base
     * @param \Closure(string): bool $drop
     *
     * @return string
     */
    public static function inlineAttributes(Node $node, RenderHost $host, string $base, Closure $drop): string
    {
        $attrs = $node->f['attrs'] ?? null;
        $attrs = $attrs instanceof Attrs ? clone $attrs : new Attrs();
        $attrs->classes = [$base, ...$attrs->classes];
        $attrs->pairs = array_values(array_filter($attrs->pairs, static fn (array $pair): bool => !$drop($pair[0])));
        $copy = clone $node;
        $copy->f['attrs'] = $attrs;

        return $host->attributes($copy, false);
    }

    /**
     * The value of an attribute, the last one written.
     */
    public static function attribute(Node $node, string $key): ?string
    {
        $attrs = $node->f['attrs'] ?? null;
        $found = null;
        foreach ($attrs instanceof Attrs ? $attrs->pairs : [] as [$name, $value]) {
            if ($name === $key) {
                $found = $value;
            }
        }

        return $found;
    }

    public static function text(string $value): Node
    {
        $node = new Node('text');
        $node->f['value'] = $value;

        return $node;
    }

    /**
     * @param string|null $id
     * @param array<string> $classes
     * @param array<array{0: string, 1: string}> $pairs
     */
    public static function attrs(?string $id = null, array $classes = [], array $pairs = []): Attrs
    {
        $attrs = new Attrs();
        $attrs->id = $id;
        $attrs->classes = $classes;
        $attrs->pairs = $pairs;

        return $attrs;
    }
}
