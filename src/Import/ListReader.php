<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import;

use MarkupCarve\Carve\Generated\Tables;

/**
 * Lists and description lists (C2-M-031 to C2-M-036).
 */
final class ListReader
{
    /**
     * C2-B-219: the bounds of an ordered start.
     *
     * @var int
     */
    private const START_MAX = 999999999;

    /**
     * @var array<string>
     */
    private const BOX_FIELDS = ['type', 'checked', 'disabled', 'aria-label'];

    private ImportContext $context;

    public function __construct(private BlockReader $blocks)
    {
        $this->context = $blocks->inline->context;
    }

    /**
     * C2-M-031, C2-M-034, C2-M-070.
     *
     * @return array<array<string, mixed>>
     */
    public function list(HtmlNode $node): array
    {
        foreach ($node->children as $child) {
            if (!$child->isBlank() && !$child->isComment() && !$child->isElement('li')) {
                $this->context->report($child, 'import-structure-repaired', 'Non-item child of an HTML list dropped');
            }
        }
        $items = [];
        foreach ($node->elements() as $child) {
            if ($child->name === 'li') {
                $items[] = $this->item($child);
            }
        }
        if ($items === []) {
            $this->context->report($node, 'import-structure-repaired', 'HTML list with no item dropped');

            return [];
        }
        $mapped = Attrs::of($node, ['start', 'type', 'data-delim']);
        $tasks = array_filter($items, static fn (array $item): bool => isset($item['task'])) !== [];
        $out = ['type' => 'list', 'children' => $items, 'marker' => $this->marker($node), 'spacing' => $this->spacing($node, $items)];

        return [$out + ($tasks ? Attrs::withoutClasses($mapped, ['task-list']) : $mapped)];
    }

    /**
     * C2-M-031, C2-H-186 `data-marker`.
     *
     * @return array<string, mixed>
     */
    private function marker(HtmlNode $node): array
    {
        $carried = $node->attr('data-marker');
        if ($node->name !== 'ol') {
            if ($carried === null || $carried === '-' || $carried === '*') {
                return ['kind' => 'bullet', 'char' => $carried ?? '-'];
            }
            $this->context->ignoredCarrier($node, 'data-marker', 'the bullet is not a list marker');

            return ['kind' => 'bullet', 'char' => '-'];
        }
        if ($carried !== null) {
            $this->context->ignoredCarrier($node, 'data-marker', 'an ordered list has no bullet');
        }
        $type = $node->attr('type');
        $marker = [
            'kind' => 'ordered',
            'style' => in_array($type, ['a', 'A', 'i', 'I'], true) ? $type : '1',
            'delimiter' => $node->attr('data-delim') === ')' ? ')' : '.',
        ];
        $raw = $node->attr('start');
        $start = TableReader::htmlInteger($raw);
        if ($start !== null && $start >= 0 && $start <= self::START_MAX) {
            if ($start !== 1) {
                $marker['start'] = $start;
            }
        } elseif ($raw !== null) {
            $this->context->report($node, 'import-attribute-dropped', 'Ordered start ' . $raw . ' dropped: it lies outside 0 to 999999999');
        }

        return $marker;
    }

    /**
     * C2-M-033, C2-H-186 `data-carve-tight`.
     *
     * @param \MarkupCarve\Carve\Import\HtmlNode $node
     * @param array<array<string, mixed>> $items
     *
     * @return string
     */
    private function spacing(HtmlNode $node, array $items): string
    {
        if ($node->has('data-carve-tight')) {
            $loose = self::hasLooseItem($node, true);
            if (!$loose) {
                return 'tight';
            }
            $this->context->ignoredCarrier($node, 'data-carve-tight', 'an item writes its first paragraph as an element');

            return count($items) > 1 ? 'loose' : 'tight';
        }
        if (count($items) <= 1) {
            return 'tight';
        }
        if (self::hasLooseItem($node, false)) {
            return 'loose';
        }
        foreach ($items as $item) {
            if (count(array_filter(J::nodes($item), static fn (array $c): bool => $c['type'] !== 'list')) > 1) {
                return 'loose';
            }
        }

        return 'tight';
    }

    /**
     * C2-H-034: a loose list writes the first paragraph of an item as `<p>`, which a tight list leaves bare.
     *
     * @param \MarkupCarve\Carve\Import\HtmlNode $node
     * @param bool $carrier Under `data-carve-tight`, a `<p>` that C2-H-018 keeps for its run is no loose sign (C2-H-186).
     *
     * @return bool
     */
    private static function hasLooseItem(HtmlNode $node, bool $carrier): bool
    {
        foreach ($node->elements() as $item) {
            if ($item->name !== 'li') {
                continue;
            }
            foreach ($item->children as $at => $child) {
                if ($child->isElement('p')) {
                    if ($child->attrs === [] && !($carrier && self::keptForRun($child, array_slice($item->children, $at + 1)))) {
                        return true;
                    }

                    break;
                }
                if (!$child->isBlank() && !$child->isElement('input')) {
                    break;
                }
            }
        }

        return false;
    }

    /**
     * C2-H-018: a tight item keeps its first `<p>` when the bare run would not read back as that paragraph: the
     * content is only `<img>` elements, whitespace and comments (C2-M-013), or a bare `<img>` follows (C2-M-012).
     *
     * @param \MarkupCarve\Carve\Import\HtmlNode $p
     * @param array<\MarkupCarve\Carve\Import\HtmlNode> $after The later children of the item.
     *
     * @return bool
     */
    private static function keptForRun(HtmlNode $p, array $after): bool
    {
        foreach ($after as $next) {
            if ($next->isBlank() || $next->isComment()) {
                continue;
            }
            if ($next->isElement('img')) {
                return true;
            }

            break;
        }
        $image = false;
        foreach ($p->children as $kid) {
            if ($kid->isElement('img')) {
                $image = true;
            } elseif (!$kid->isBlank() && !$kid->isComment()) {
                return false;
            }
        }

        return $image;
    }

    /**
     * C2-M-032: the task box of an item, its state, and the class and style every render gives the item.
     *
     * @return array<string, mixed>
     */
    private function item(HtmlNode $node): array
    {
        $boxes = array_values(array_filter($node->elements(), static fn (HtmlNode $c): bool => $c->name === 'input'
            && $c->attr('type') === 'checkbox'));
        $box = $boxes[0] ?? null;
        foreach (array_slice($boxes, 1) as $further) {
            $this->context->report($further, 'import-element-dropped', 'A further checkbox of a task item dropped');
        }
        if ($box !== null && array_diff(array_keys($box->attrs), self::BOX_FIELDS) !== []) {
            $this->context->report($box, 'import-attribute-dropped', 'Checkbox attributes outside task state dropped');
        }
        $nodes = array_values(array_filter($node->children, static fn (HtmlNode $c): bool => !in_array($c, $boxes, true)));
        $boxAt = $box === null ? -1 : (int)array_search($box, $node->children, true);
        $after = $node->children[$boxAt + 1] ?? null;
        if ($box !== null && $after !== null && $after->isText() && str_starts_with($after->name, ' ')) {
            $after->name = substr($after->name, 1);
        }
        $mapped = Attrs::of($node, ['data-task-state']);
        $out = ['type' => 'list_item', 'children' => $this->blocks->flow($nodes, $node)];
        if ($box === null) {
            return $out + $mapped;
        }
        $out['task'] = ['mark' => $this->taskMark($node, $box)];

        return $out + self::withoutStyle(Attrs::withoutClasses($mapped, ['task']));
    }

    private function taskMark(HtmlNode $node, HtmlNode $box): string
    {
        $state = $node->attr('data-task-state');
        if ($state !== null) {
            $mark = array_search($state, Tables::TASK_STATE_ATTRIBUTE, true);
            if (is_string($mark)) {
                return $mark;
            }
            $this->context->report($node, 'import-attribute-dropped', 'Unknown task state ' . $state . ' dropped');
        }

        return $box->has('checked') ? 'x' : ' ';
    }

    /**
     * C2-M-032: the declaration `list-style: none` that a render gives a task item is not kept.
     *
     * @param array<string, mixed> $mapped
     *
     * @return array<string, mixed>
     */
    private static function withoutStyle(array $mapped): array
    {
        $attrs = J::attrs($mapped);
        if (!isset($attrs['keyValues'])) {
            return $mapped;
        }
        $pairs = J::pairs($attrs);
        $out = [];
        foreach ($pairs as [$key, $value]) {
            if ($key === 'style' && $value === 'list-style: none') {
                continue;
            }
            $out[] = $key === 'style' && str_starts_with($value, 'list-style: none; ') ? [$key, substr($value, 18)] : [$key, $value];
        }
        $attrs['keyValues'] = $out;
        $mapped['attrs'] = $attrs;

        return Attrs::withoutKeys($mapped, []);
    }

    /**
     * C2-M-036, C2-M-070.
     *
     * @return array<array<string, mixed>>
     */
    public function definitionList(HtmlNode $node): array
    {
        $children = $this->entries($node->children, false);
        if ($children === []) {
            $this->context->report($node, 'import-structure-repaired', 'Description list with no entry dropped');

            return [];
        }

        return [['type' => 'definition_list', 'children' => $children] + Attrs::of($node)];
    }

    /**
     * @param array<\MarkupCarve\Carve\Import\HtmlNode> $nodes
     * @param bool $inGroup
     *
     * @return array<array<string, mixed>>
     */
    private function entries(array $nodes, bool $inGroup): array
    {
        $out = [];
        foreach ($nodes as $child) {
            if ($child->isElement('dt')) {
                $term = ['type' => 'definition_term', 'children' => $this->blocks->inline->children($child)] + Attrs::of($child);
                array_push($out, ...$this->blocks->withContent($term, $child));
            } elseif ($child->isElement('dd')) {
                $out[] = ['type' => 'definition_description', 'children' => $this->blocks->blocks($child)] + Attrs::of($child);
            } elseif ($child->isElement('div') && !$inGroup) {
                array_push($out, ...$this->entries($child->children, true));
            } elseif (!$child->isBlank() && !$child->isComment()) {
                $this->context->report($child, 'import-structure-repaired', 'Non-entry child of a description list dropped');
            }
        }

        return $out;
    }
}
