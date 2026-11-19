<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Resolve\PlainText;

/**
 * Provisional. `<n>` markers that end a code line become callout bubbles, and a following `<n> text` paragraph their
 * list: a document transform (C2-X-053, C2-X-054) that inserts `carve.codeCallouts.code` and `carve.codeCallouts.list`.
 */
final class CodeCallouts
{
    /**
     * @var string
     */
    public const CODE = 'carve.codeCallouts.code';

    /**
     * @var string
     */
    public const LIST = 'carve.codeCallouts.list';

    /**
     * @var string
     */
    private const MARKER = '/<(\d+)>[ \t]*$/';

    /**
     * @var string
     */
    private const ITEM = '/^<(\d+)> /';

    /**
     * @var array<string>
     */
    private const SKIP = ['fallback', 'source', 'payload', 'props', 'attrs'];

    public static function bundle(): Bundle
    {
        // Both channels write the same self-contained HTML (C2-X-052).
        return new Bundle(
            [
                new ExtensionDefinition(self::CODE, '1', renderer: self::renderCode(...), buildTimeRenderer: self::renderCode(...)),
                new ExtensionDefinition(
                    self::LIST,
                    '1',
                    ['lines'],
                    renderer: self::renderList(...),
                    buildTimeRenderer: self::renderList(...),
                ),
            ],
            [new DocumentTransform('carve.codeCallouts', self::transform(...))],
        );
    }

    public static function transform(Node $view): Node
    {
        self::visit($view);
        $declared = is_array($view->f['extensions'] ?? null) ? $view->f['extensions'] : [];
        foreach ([[self::CODE, []], [self::LIST, ['lines']]] as [$name, $slots]) {
            foreach ($declared as $entry) {
                if (is_array($entry) && ($entry['name'] ?? null) === $name) {
                    continue 2;
                }
            }
            $declared[] = ['name' => $name, 'version' => '1', 'required' => false, 'slots' => $slots];
        }
        $view->f['extensions'] = $declared;

        return $view;
    }

    private static function visit(Node $node): void
    {
        if ($node->children !== null) {
            /** @var array<\MarkupCarve\Carve\Node> $children */
            $children = self::bind($node->children);
            $node->children = $children;
            foreach ($children as $child) {
                self::visit($child);
            }
        }
        foreach ($node->f as $key => $value) {
            if (is_array($value) && !in_array($key, self::SKIP, true)) {
                $node->f[$key] = self::visitList($value);
            }
        }
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<mixed>
     */
    private static function visitList(array $value): array
    {
        if (array_filter($value, static fn (mixed $v): bool => $v instanceof Node) !== []) {
            $value = self::bind($value);
        }
        foreach ($value as $key => $item) {
            if ($item instanceof Node) {
                self::visit($item);
            } elseif (is_array($item)) {
                $value[$key] = self::visitList($item);
            }
        }

        return $value;
    }

    /**
     * @param array<mixed> $nodes
     *
     * @return array<mixed>
     */
    private static function bind(array $nodes): array
    {
        $keys = array_keys($nodes);
        $count = count($keys);
        for ($i = 0; $i < $count; $i++) {
            $node = $nodes[$keys[$i]];
            if (!$node instanceof Node || $node->type !== 'code_block' || !self::hasMarker(PlainText::str($node->f['value'] ?? ''))) {
                continue;
            }
            $nodes[$keys[$i]] = self::codeNode($node);
            $next = $i + 1 < $count ? $nodes[$keys[$i + 1]] : null;
            if ($next instanceof Node && self::isList($next)) {
                $nodes[$keys[++$i]] = self::listNode($next);
            }
        }

        return $nodes;
    }

    private static function hasMarker(string $value): bool
    {
        foreach (explode("\n", $value) as $line) {
            if (preg_match(self::MARKER, $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<\MarkupCarve\Carve\Node> $children
     *
     * @return array<array<\MarkupCarve\Carve\Node>>
     */
    private static function lines(array $children): array
    {
        $lines = [[]];
        foreach ($children as $child) {
            if ($child->type === 'soft_break') {
                $lines[] = [];
            } else {
                $lines[count($lines) - 1][] = $child;
            }
        }

        return array_values(array_filter($lines, static fn (array $line): bool => $line !== []));
    }

    private static function isList(Node $node): bool
    {
        if ($node->type !== 'paragraph' || isset($node->f['role']) || isset($node->f['caption'])) {
            return false;
        }
        $lines = self::lines($node->children ?? []);
        foreach ($lines as $line) {
            if ($line[0]->type !== 'text' || preg_match(self::ITEM, PlainText::str($line[0]->f['value'] ?? '')) !== 1) {
                return false;
            }
        }

        return $lines !== [];
    }

    private static function codeNode(Node $block): Node
    {
        $node = Node::at('block_extension', $block->start, $block->end);
        $node->children = [];
        $node->f['name'] = self::CODE;
        $node->f['version'] = '1';
        if (is_string($block->f['syntax'] ?? null)) {
            $node->f['props'] = ['language' => $block->f['syntax']];
        }
        $node->f['payload'] = [['format' => 'text/plain', 'value' => PlainText::str($block->f['value'] ?? '')]];
        $node->f['fallback'] = [FencedRender::bareFallback($block)];
        if (($block->f['attrs'] ?? null) instanceof Attrs) {
            $node->f['attrs'] = clone $block->f['attrs'];
        }

        return $node;
    }

    private static function listNode(Node $paragraph): Node
    {
        $node = Node::at('block_extension', $paragraph->start, $paragraph->end);
        $node->children = [];
        $node->f['name'] = self::LIST;
        $node->f['version'] = '1';
        $node->f['slots'] = ['lines' => array_map(Extensions::copy(...), $paragraph->children ?? [])];
        $bare = Extensions::copy($paragraph);
        unset($bare->f['attrs']);
        $node->f['fallback'] = [$bare];
        if (($paragraph->f['attrs'] ?? null) instanceof Attrs) {
            $node->f['attrs'] = clone $paragraph->f['attrs'];
        }

        return $node;
    }

    public static function renderCode(Node $node, RenderHost $host): string
    {
        $body = [];
        foreach (explode("\n", self::payload($node)) as $line) {
            if (preg_match(self::MARKER, $line, $m, PREG_OFFSET_CAPTURE) !== 1) {
                $body[] = RenderHost::escapeText($line);

                continue;
            }
            $before = substr($line, 0, $m[0][1]);
            $text = rtrim($before);
            $n = $m[1][0];
            $body[] = RenderHost::escapeText($text) . substr($before, strlen($text))
                . '<b class="callout" data-callout="' . $n . '">' . $n . '</b>';
        }
        $props = $node->f['props'] ?? null;
        $language = is_array($props) ? ($props['language'] ?? null) : null;
        $code = is_string($language) ? ' class="language-' . RenderHost::escapeAttribute($language) . '"' : '';

        return '<pre' . $host->attributes($node) . '><code' . $code . '>' . implode("\n", $body) . '</code></pre>';
    }

    public static function renderList(Node $node, RenderHost $host): string
    {
        $slots = $node->f['slots'] ?? null;
        $items = '';
        foreach (self::lines(PlainText::nodes(is_array($slots) ? $slots['lines'] ?? [] : [])) as $line) {
            $head = $line[0];
            if ($head->type !== 'text') {
                continue;
            }
            $value = PlainText::str($head->f['value'] ?? '');
            preg_match(self::ITEM, $value, $m);
            $first = clone $head;
            $first->f['value'] = (string)preg_replace(self::ITEM, '', $value);
            $items .= '<li value="' . ($m[1] ?? '') . '">' . ($host->inlines)([$first, ...array_slice($line, 1)]) . '</li>';
        }

        return '<ol' . $host->attributes(NodeAttributes::with($node, ['callouts'])) . '>' . $items . '</ol>';
    }

    private static function payload(Node $node): string
    {
        $payload = $node->f['payload'] ?? null;
        $value = is_array($payload) && is_array($payload[0] ?? null) ? ($payload[0]['value'] ?? '') : '';

        return is_string($value) ? $value : '';
    }
}
