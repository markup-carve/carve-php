<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Resolve\PlainText;
use MarkupCarve\Carve\Resolve\Slug;

/**
 * Provisional. Invisible `:index[term]` markers (`carve.indexTerm`), collected into a sorted list by a `::: index`
 * directive (`carve.index`).
 */
final class IndexTerms
{
    /**
     * @var string
     */
    public const MARKER = 'carve.indexTerm';

    /**
     * @var string
     */
    public const INDEX = 'carve.index';

    /**
     * As 0.1: K index directives each repeat the whole list, so the output of one render is capped.
     *
     * @var int
     */
    private const BUDGET_BYTES = 1000000;

    /**
     * @var int
     */
    private const BUDGET_FACTOR = 8;

    /**
     * @param string $backrefLabel The leading words of a back-link's accessible name.
     */
    public static function bundle(string $backrefLabel = 'Back to'): Bundle
    {
        return new Bundle(
            [self::markerDefinition(), Apparatus::directiveDefinition(self::INDEX, Apparatus::body(...))],
            [new DocumentTransform('index', static fn (Node $view): Node => self::transform($view, $backrefLabel))],
        );
    }

    public static function markerDefinition(): ExtensionDefinition
    {
        return new ExtensionDefinition(
            self::MARKER,
            '1',
            ['term'],
            matcher: ['form' => 'inline', 'name' => 'index'],
            transform: static function (Claim $claim): ?Node {
                if ($claim->form !== 'inline') {
                    return null;
                }
                // The marker is invisible: it gives no text (C2-H-143), so its fallback is empty.
                $node = new Node('inline_extension', []);
                $node->f['name'] = self::MARKER;
                $node->f['version'] = '1';
                if (($claim->node->children ?? []) !== []) {
                    $node->f['slots'] = ['term' => $claim->node->children];
                }
                $node->f['fallback'] = [];

                return $node;
            },
            renderer: self::renderMarker(...),
            buildTimeRenderer: self::renderMarker(...),
        );
    }

    public static function transform(Node $view, string $backrefLabel): Node
    {
        $entries = self::entries($view);
        if ($entries === []) {
            return $view;
        }
        $source = $view->f['srcByteLength'] ?? 0;
        $budget = max(self::BUDGET_BYTES, self::BUDGET_FACTOR * (is_int($source) ? $source : 0));
        $claimed = false;
        Apparatus::walk($view, static function (Node $node) use ($entries, $backrefLabel, &$budget, &$claimed): Node|false|null {
            if ($node->type !== 'directive' || ($node->f['kind'] ?? null) !== 'index') {
                return null;
            }
            $list = self::indexList($entries, $backrefLabel, $budget);
            if ($list === null) {
                return false;
            }
            $claimed = true;
            $body = Extensions::copy($node);
            $body->children = [...($body->children ?? []), $list];

            return Apparatus::claimDirective(self::INDEX, $node, [$body]);
        });
        if ($claimed) {
            Apparatus::declare($view, self::INDEX);
        }

        return $view;
    }

    /**
     * Gives each counted marker its id and returns the entries by slug.
     *
     * @return array<string, array{display: array<\MarkupCarve\Carve\Node>, ids: array<string>}>
     */
    private static function entries(Node $view): array
    {
        $taken = Apparatus::viewIds($view);
        $entries = [];
        Apparatus::walk($view, static function (Node $node) use (&$taken, &$entries): ?bool {
            // A marker in a note is not counted: the note may render elsewhere or not at all.
            if ($node->type === 'footnote' || $node->type === 'inline_footnote') {
                return false;
            }
            if ($node->type !== 'inline_extension' || ($node->f['name'] ?? null) !== self::MARKER) {
                return null;
            }
            $slots = is_array($node->f['slots'] ?? null) ? $node->f['slots'] : [];
            $term = PlainText::nodes($slots['term'] ?? []);
            $slug = Slug::of(PlainText::of($term), 'lower');
            $entries[$slug] ??= ['display' => Apparatus::coreInlines($term), 'ids' => []];
            $id = Apparatus::freshId('idx-' . $slug . '-' . (count($entries[$slug]['ids']) + 1), $taken);
            $entries[$slug]['ids'][] = $id;
            $node->f['props'] = ['id' => $id];

            return false;
        });

        return $entries;
    }

    /**
     * @param array<string, array{display: array<\MarkupCarve\Carve\Node>, ids: array<string>}> $entries
     * @param string $backrefLabel
     * @param int $budget
     *
     * @return \MarkupCarve\Carve\Node|null
     */
    private static function indexList(array $entries, string $backrefLabel, int &$budget): ?Node
    {
        $slugs = array_map('strval', array_keys($entries));
        // Ascending code point order, independent of the locale, so every implementation sorts alike.
        usort($slugs, static fn (string $a, string $b): int => self::byCodePoint($a, $b));
        $items = [];
        foreach ($slugs as $slug) {
            $entry = $entries[$slug];
            $budget -= strlen(PlainText::of($entry['display'])) + 10;
            if ($budget < 0) {
                break;
            }
            $items[] = new Node('list_item', [new Node('paragraph', self::line($entry, $backrefLabel, $budget))]);
            if ($budget < 0) {
                break;
            }
        }
        if ($items === []) {
            return null;
        }
        $list = new Node('list', $items);
        $list->f['marker'] = ['kind' => 'bullet', 'char' => '-'];
        $list->f['spacing'] = 'tight';
        $list->f['attrs'] = Apparatus::attrs(classes: ['index']);

        return $list;
    }

    /**
     * One line of the index: the term and a back-link per use, drawn from the budget until it runs out.
     *
     * @param array{display: array<\MarkupCarve\Carve\Node>, ids: array<string>} $entry
     * @param string $backrefLabel
     * @param int $budget
     *
     * @return array<\MarkupCarve\Carve\Node>
     */
    private static function line(array $entry, string $backrefLabel, int &$budget): array
    {
        $label = $backrefLabel . ' ' . PlainText::of($entry['display']);
        $line = [...array_map(Extensions::copy(...), $entry['display']), Apparatus::text(' ')];
        $total = count($entry['ids']);
        foreach ($entry['ids'] as $at => $id) {
            $budget -= strlen($id) + strlen($label) + 80;
            if ($budget < 0) {
                break;
            }
            if ($at > 0) {
                $line[] = Apparatus::text(' ');
            }
            // The back-link is named by what it visibly says, the term and its number among several.
            $children = [Apparatus::text('↩')];
            if ($total > 1) {
                $children[] = new Node('superscript', [Apparatus::text((string)($at + 1))]);
            }
            $link = new Node('link', $children);
            $link->f['href'] = '#' . $id;
            $name = $total === 1 ? $label : $label . ' ' . ($at + 1);
            $link->f['attrs'] = Apparatus::attrs(classes: ['index-backref'], pairs: [['aria-label', $name]]);
            $line[] = $link;
        }

        return $line;
    }

    private static function byCodePoint(string $a, string $b): int
    {
        $left = mb_str_split($a, 1, 'UTF-8');
        $right = mb_str_split($b, 1, 'UTF-8');
        $count = min(count($left), count($right));
        for ($at = 0; $at < $count; $at++) {
            $difference = mb_ord($left[$at], 'UTF-8') - mb_ord($right[$at], 'UTF-8');
            if ($difference !== 0) {
                return $difference;
            }
        }

        return count($left) - count($right);
    }

    public static function renderMarker(Node $node, RenderHost $host): string
    {
        $props = $node->f['props'] ?? null;
        $id = is_array($props) ? ($props['id'] ?? null) : null;

        // A marker the transform did not count, as in a note, has no id, so the index never points at it.
        return is_string($id)
            ? '<span id="' . RenderHost::escapeAttribute($id) . '" class="index-term"></span>'
            : '<span class="index-term"></span>';
    }
}
