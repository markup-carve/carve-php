<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Resolve\PlainText;

/**
 * Internal: `carve.toc` and `carve.tocPlacement` with their renderer, the same in both channels (C2-X-052).
 */
final class Toc
{
    /**
     * @var string
     */
    public const TOC = 'carve.toc';

    /**
     * @var string
     */
    public const PLACEMENT = 'carve.tocPlacement';

    /**
     * @var array<string, true>
     */
    private const WINDOW_KEYS = ['depth' => true, 'from' => true, 'to' => true];

    public static function clamp(?int $value, int $fallback = 1): int
    {
        return $value === null ? $fallback : min(6, max(1, $value));
    }

    /**
     * @param int $minLevel
     * @param int $maxLevel
     * @param bool $ordered
     * @param string $cssClass
     * @param bool $bottom
     * @param array<string, mixed> $props
     *
     * @return \MarkupCarve\Carve\Extension\ExtensionDefinition
     */
    public static function tableOfContents(
        int $minLevel,
        int $maxLevel,
        bool $ordered,
        string $cssClass,
        bool $bottom,
        array $props,
    ): ExtensionDefinition {
        $run = static function (Node $view) use ($minLevel, $maxLevel, $ordered, $cssClass, $bottom, $props): Node {
            $entries = [];
            foreach ($view->children ?? [] as $node) {
                $level = $node->f['level'] ?? 0;
                if ($node->type !== 'heading' || $level < $minLevel || $level > $maxLevel) {
                    continue;
                }
                $found = self::entry($node);
                if ($found !== null) {
                    $entries[] = $found;
                }
            }
            $list = self::entryList($entries, $ordered);
            if ($list === null) {
                return $view;
            }
            NavTree::declare($view, self::TOC, ['list']);
            $toc = new Node('block_extension', []);
            $toc->f['name'] = self::TOC;
            $toc->f['version'] = '1';
            $toc->f['attrs'] = Apparatus::attrs(classes: [$cssClass]);
            $toc->f['slots'] = ['list' => [$list]];
            $toc->f['fallback'] = [Extensions::copy($list)];
            if ($props !== []) {
                $toc->f['props'] = $props;
            }
            $view->children = $bottom ? [...($view->children ?? []), $toc] : [$toc, ...($view->children ?? [])];

            return $view;
        };

        return new ExtensionDefinition(
            self::TOC,
            '1',
            ['list'],
            renderer: self::renderToc(...),
            buildTimeRenderer: self::renderToc(...),
            documentTransform: $run,
        );
    }

    public static function placement(): ExtensionDefinition
    {
        $run = static function (Node $view): Node {
            $entries = [];
            $directives = 0;
            NavTree::visit($view, static function (Node $node, array $ancestors) use (&$entries, &$directives): void {
                if ($node->type === 'directive' && ($node->f['kind'] ?? null) === 'toc') {
                    $directives++;
                }
                // carve#6531: the placed table lists the headings a section wraps, as tableOfContents does.
                if ($node->type !== 'heading' || count($ancestors) > 1) {
                    return;
                }
                $found = self::entry($node);
                if ($found !== null) {
                    $entries[] = $found;
                }
            }, false);
            if ($directives === 0) {
                return $view;
            }
            NavTree::declare($view, self::PLACEMENT, ['title', 'body', 'list']);
            $place = static fn (Node $node): ?array => $node->type === 'directive' && ($node->f['kind'] ?? null) === 'toc'
                ? [self::place($node, $entries)] : null;
            NavTree::rewrite($view, $place, false);

            return $view;
        };

        return new ExtensionDefinition(
            self::PLACEMENT,
            '1',
            ['title', 'body', 'list'],
            renderer: self::renderPlacement(...),
            buildTimeRenderer: self::renderPlacement(...),
            documentTransform: $run,
        );
    }

    /**
     * @return array{level: int, id: string, text: array<\MarkupCarve\Carve\Node>}|null
     */
    private static function entry(Node $heading): ?array
    {
        $id = NavTree::headingId($heading);
        $level = $heading->f['level'] ?? 1;

        if ($id === null) {
            return null;
        }

        return ['level' => is_int($level) ? $level : 1, 'id' => $id, 'text' => NavTree::derivedInlines($heading->children ?? [])];
    }

    private static function emptyList(bool $ordered): Node
    {
        $list = new Node('list', []);
        $list->f['marker'] = $ordered ? ['kind' => 'ordered', 'style' => '1', 'delimiter' => '.'] : ['kind' => 'bullet', 'char' => '-'];
        $list->f['spacing'] = 'tight';

        return $list;
    }

    /**
     * A deeper entry nests under the item before it; a shallower one closes the lists deeper than it.
     *
     * @param array<array{level: int, id: string, text: array<\MarkupCarve\Carve\Node>}> $entries
     * @param bool $ordered
     */
    private static function entryList(array $entries, bool $ordered): ?Node
    {
        if ($entries === []) {
            return null;
        }
        $root = self::emptyList($ordered);
        $levels = [$entries[0]['level']];
        $lists = [$root];
        foreach ($entries as $item) {
            $top = count($lists) - 1;
            $items = $lists[$top]->children ?? [];
            $last = $items[count($items) - 1] ?? null;
            if ($last !== null && $item['level'] > $levels[$top]) {
                $nested = self::emptyList($ordered);
                $last->children = [...($last->children ?? []), $nested];
                $levels[] = $item['level'];
                $lists[] = $nested;
                $top++;
            } elseif ($last !== null) {
                while ($top > 0 && $item['level'] <= $levels[$top - 1]) {
                    array_pop($levels);
                    array_pop($lists);
                    $top--;
                }
                $levels[$top] = $item['level'];
            }
            $link = NavTree::link('#' . $item['id'], $item['text']);
            $lists[$top]->children = [...($lists[$top]->children ?? []), new Node('list_item', [new Node('paragraph', [$link])])];
        }

        return $root;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function window(?Attrs $attrs): array
    {
        $value = static function (string $name) use ($attrs): ?int {
            foreach ($attrs->pairs ?? [] as [$key, $raw]) {
                if ($key === $name) {
                    return preg_match('/^\s*[+-]?\d+/', $raw, $m) === 1 ? (int)$m[0] : null;
                }
            }

            return null;
        };
        $from = $value('from');
        $to = $value('to');
        if ($from !== null || $to !== null) {
            $low = self::clamp($from);
            $high = self::clamp($to, 6);

            return $low <= $high ? [$low, $high] : [$high, $low];
        }

        return [1, self::clamp($value('depth'), 6)];
    }

    /**
     * @param \MarkupCarve\Carve\Node $directive
     * @param array<array{level: int, id: string, text: array<\MarkupCarve\Carve\Node>}> $entries
     *
     * @return \MarkupCarve\Carve\Node
     */
    private static function place(Node $directive, array $entries): Node
    {
        $attrs = NavTree::attrs($directive);
        [$minLevel, $maxLevel] = self::window($attrs);
        $inWindow = static fn (array $e): bool => $e['level'] >= $minLevel && $e['level'] <= $maxLevel;
        $list = self::entryList(array_values(array_filter($entries, $inWindow)), false);
        $body = $directive->children ?? [];
        $title = is_array($directive->f['title'] ?? null) ? PlainText::nodes($directive->f['title']) : null;
        $fallback = [];
        if ($title !== null) {
            $fallback[] = new Node('paragraph', [new Node('strong', array_map(Extensions::copy(...), $title))]);
        }
        array_push($fallback, ...array_map(Extensions::copy(...), $body));
        if ($list !== null) {
            $fallback[] = Extensions::copy($list);
        }
        $slots = ['body' => $body, 'list' => $list === null ? [] : [$list]];
        if ($title !== null) {
            $slots['title'] = $title;
        }
        $node = Node::at('block_extension', $directive->start, $directive->end);
        $node->children = [];
        $node->f['name'] = self::PLACEMENT;
        $node->f['version'] = '1';
        $own = Apparatus::attrs(
            $attrs?->id,
            ['toc', ...array_values(array_filter($attrs->classes ?? [], static fn (string $c): bool => $c !== 'toc'))],
            array_values(array_filter($attrs->pairs ?? [], static fn (array $p): bool => !isset(self::WINDOW_KEYS[$p[0]]))),
        );
        $node->f['attrs'] = $own;
        $node->f['slots'] = $slots;
        $node->f['fallback'] = $fallback;
        if (is_string($directive->f['groupLabel'] ?? null)) {
            $node->f['props'] = ['label' => $directive->f['groupLabel']];
        }

        return $node;
    }

    private static function navName(RenderHost $host, Node $node): string
    {
        $authored = NavTree::pairOf($node, 'aria-label') !== null || NavTree::pairOf($node, 'aria-labelledby') !== null;
        $label = $host->label('tocNav', 'Table of contents');

        return $authored || $label === '' ? '' : ' aria-label="' . RenderHost::escapeAttribute($label) . '"';
    }

    public static function renderToc(Node $node, RenderHost $host): string
    {
        $slots = is_array($node->f['slots'] ?? null) ? $node->f['slots'] : [];
        $list = NavTree::indent(($host->blocks)(PlainText::nodes($slots['list'] ?? [])), $host->blockDepth + 1);
        $close = str_repeat('  ', $host->blockDepth);
        $props = is_array($node->f['props'] ?? null) ? $node->f['props'] : [];
        if (($props['collapsible'] ?? false) === true) {
            $summary = is_string($props['summary'] ?? null) ? $props['summary'] : 'Table of Contents';
            $head = NavTree::indent('<summary>' . RenderHost::escapeText($summary) . '</summary>', $host->blockDepth + 1);

            return '<details' . $host->attributes($node) . (($props['open'] ?? false) === true ? ' open' : '') . ">\n"
                . $head . "\n" . $list . "\n" . $close . '</details>';
        }

        return '<nav' . $host->attributes($node) . self::navName($host, $node) . ">\n" . $list . "\n" . $close . '</nav>';
    }

    public static function renderPlacement(Node $node, RenderHost $host): string
    {
        $slots = is_array($node->f['slots'] ?? null) ? $node->f['slots'] : [];
        $body = PlainText::nodes($slots['body'] ?? []);
        $list = PlainText::nodes($slots['list'] ?? []);
        $title = PlainText::nodes($slots['title'] ?? []);
        [$head, $name] = self::placementHead($node, $title, $host);
        $inner = $head . ($list === [] ? '' : ($host->blocks)($list));
        $close = str_repeat('  ', $host->blockDepth);
        if ($inner !== '') {
            $inner = "\n" . NavTree::indent((string)preg_replace('/\n$/u', '', $inner), $host->blockDepth + 1) . "\n" . $close;
        }
        $nav = '<nav' . $host->attributes($node) . $name . '>' . $inner . '</nav>';

        return $body === [] ? $nav : ltrim(NavTree::indent(($host->blocks)($body), $host->blockDepth)) . "\n" . $close . $nav;
    }

    /**
     * The title and label lines of a placed table, and the attribute that names its nav.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param array<\MarkupCarve\Carve\Node> $title
     * @param \MarkupCarve\Carve\Extension\RenderHost $host
     *
     * @return array{0: string, 1: string}
     */
    private static function placementHead(Node $node, array $title, RenderHost $host): array
    {
        $props = is_array($node->f['props'] ?? null) ? $node->f['props'] : [];
        $head = '';
        $name = self::navName($host, $node);
        if ($title !== []) {
            $id = $name === '' ? '' : ($host->nextId)('toc-title');
            if ($id !== '') {
                $name = ' aria-labelledby="' . RenderHost::escapeAttribute($id) . '"';
            }
            $head .= '<p class="admonition-title"' . ($id === '' ? '' : ' id="' . RenderHost::escapeAttribute($id) . '"') . '>'
                . ($host->inlines)($title) . "</p>\n";
        }
        $label = $props['label'] ?? null;
        if (is_string($label) && $label !== '') {
            $head .= '<p class="div-label">' . RenderHost::escapeText($label) . "</p>\n";
        }

        return [$head, $name];
    }
}
