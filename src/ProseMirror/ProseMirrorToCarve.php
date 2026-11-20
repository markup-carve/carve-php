<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\ProseMirror;

use InvalidArgumentException;
use MarkupCarve\Carve\Carve;

/**
 * Carve from ProseMirror JSON in the schema of the carve-grammars Tiptap kit (`tiptap/tree.js`).
 *
 * `toTree()` builds the 0.2 tree, `convert()` writes it with `Carve::write()`. A ProseMirror node or mark with no
 * Carve node is an error, not a skip.
 */
final class ProseMirrorToCarve
{
    /**
     * @var array<string, array{0: string, 1: array<string>}>|null
     */
    private static ?array $textblocks = null;

    /**
     * @var array<string, array{0: string, 1: array<string>}>|null
     */
    private static ?array $containers = null;

    /**
     * @var array<string, array{0: string, 1: array<string>}>|null
     */
    private static ?array $marks = null;

    /**
     * The canonical Carve source of a ProseMirror document.
     *
     * @param array<string, mixed>|string $doc The editor's `getJSON()`, decoded or as JSON.
     * @param array<string, mixed> $options Passed to `Carve::write()`.
     */
    public function convert(array|string $doc, array $options = []): string
    {
        return Carve::write($this->toTree($doc), null, $options);
    }

    /**
     * The Carve 0.2 `document` tree of a ProseMirror document, as decoded AST JSON.
     *
     * @param array<string, mixed>|string $doc
     *
     * @return array<string, mixed>
     */
    public function toTree(array|string $doc): array
    {
        return $this->toTreeWithOrigins($doc)['tree'];
    }

    /**
     * The tree, and for each top-level block the index of the loaded block it came from (null for a block the editor
     * added).
     *
     * @param array<string, mixed>|string $doc
     *
     * @throws \InvalidArgumentException
     *
     * @return array{tree: array<string, mixed>, origins: array<int|null>}
     */
    public function toTreeWithOrigins(array|string $doc): array
    {
        if (is_string($doc)) {
            $doc = Kit::decode($doc);
        }
        if (!is_array($doc) || ($doc['type'] ?? null) !== 'doc') {
            throw new InvalidArgumentException('ProseMirrorToCarve expects a ProseMirror doc node');
        }
        $children = [];
        $origins = [];
        foreach (self::content($doc) as $pm) {
            if (self::isPlaceholder($pm)) {
                continue;
            }
            $node = $this->block($pm);
            if ($node === null) {
                continue;
            }
            $children[] = $node;
            $index = Kit::carveOf($pm)['$index'] ?? null;
            $origins[] = is_int($index) ? $index : null;
        }
        $tree = ['type' => 'document', 'astVersion' => '2.0'];
        foreach (Kit::treeFields(Kit::carveOf($doc)) as $key => $value) {
            $tree[$key] = $value;
        }
        $tree['children'] = $children;

        return ['tree' => $tree, 'origins' => $origins];
    }

    /**
     * @param array<string, mixed> $pm
     *
     * @return array<array<string, mixed>>
     */
    private static function content(array $pm): array
    {
        return Kit::nodes($pm['content'] ?? null);
    }

    /**
     * An empty paragraph with nothing carried is editor state (a placeholder, a fresh line), not content.
     *
     * @param array<string, mixed> $pm
     */
    private static function isPlaceholder(array $pm): bool
    {
        if (($pm['type'] ?? null) !== 'paragraph' || self::content($pm) !== []) {
            return false;
        }

        return array_diff(array_keys(Kit::carveOf($pm)), ['$index']) === [];
    }

    /**
     * @param array<array<string, mixed>> $content
     *
     * @return array<array<string, mixed>>
     */
    private function blocks(array $content): array
    {
        $out = [];
        foreach ($content as $pm) {
            if (self::isPlaceholder($pm)) {
                continue;
            }
            $node = $this->block($pm);
            if ($node !== null) {
                $out[] = $node;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private static function with(array $node, array $fields): array
    {
        foreach ($fields as $key => $value) {
            $node[$key] = $value;
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $pm
     *
     * @throws \InvalidArgumentException
     *
     * @return array<string, mixed>|null
     */
    private function block(array $pm): ?array
    {
        $type = Kit::str($pm['type'] ?? null);
        $carve = Kit::carveOf($pm);
        if (isset(self::textblocks()[$type])) {
            return $this->textblock($pm);
        }
        if (isset(self::containers()[$type])) {
            return $this->container($pm);
        }
        $code = array_search($type, Kit::CODE_BLOCKS, true);
        if ($code !== false) {
            return self::with(['type' => $code], Kit::treeFields($carve)) + ['value' => self::textOf($pm)];
        }

        return match ($type) {
            'carveCaptioned' => $this->captioned($pm),
            'carveBlock' => Kit::map(Kit::clean(array_diff_key($carve, ['$index' => true]))),
            'codeBlock' => $this->codeBlock($pm),
            'horizontalRule' => self::with(['type' => 'thematic_break'], Kit::treeFields($carve)),
            'bulletList', 'orderedList', 'taskList' => $this->listNode($pm),
            'carveDefinitionList' => $this->mixed($pm, 'definition_list'),
            'carveLineBlock' => $this->mixed($pm, 'line_block'),
            'table' => $this->table($pm),
            default => throw new InvalidArgumentException(
                sprintf('ProseMirrorToCarve: no Carve node for the ProseMirror node "%s"', $type),
            ),
        };
    }

    /**
     * @return array<string, array{0: string, 1: array<string>}>
     */
    private static function textblocks(): array
    {
        return self::$textblocks ??= Kit::inverse(Kit::TEXTBLOCKS);
    }

    /**
     * @return array<string, array{0: string, 1: array<string>}>
     */
    private static function containers(): array
    {
        return self::$containers ??= Kit::inverse(Kit::CONTAINERS);
    }

    /**
     * Definition lists and line blocks: their children are their own node types and comments.
     *
     * @param array<string, mixed> $pm
     * @param string $type
     *
     * @return array<string, mixed>
     */
    private function mixed(array $pm, string $type): array
    {
        return self::with(['type' => $type], Kit::treeFields(Kit::carveOf($pm))) + ['children' => $this->blocks(self::content($pm))];
    }

    /**
     * @param array<string, mixed> $pm
     *
     * @return array<string, mixed>|null
     */
    private function captioned(array $pm): ?array
    {
        $content = self::content($pm);
        $node = isset($content[0]) ? $this->block($content[0]) : null;
        $caption = $content[1] ?? null;
        // An empty caption the editor made (to fill the wrapper) is no caption.
        $empty = $caption === null || (Kit::carveOf($caption)['$empty'] ?? null) !== true && !self::filled($caption);
        if ($node !== null && $caption !== null && !$empty) {
            $node['caption'] = $this->block($caption);
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $pm
     *
     * @return array<string, mixed>
     */
    private function codeBlock(array $pm): array
    {
        $carve = Kit::carveOf($pm);
        $node = self::with(['type' => 'code_block'], Kit::treeFields($carve));
        $node['value'] = self::textOf($pm) . (($carve['$newline'] ?? null) === false ? '' : "\n");
        $language = Kit::map($pm['attrs'] ?? null)['language'] ?? null;
        if (is_string($language) && $language !== '') {
            $node['syntax'] = $language;
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $pm
     */
    private static function textOf(array $pm): string
    {
        $text = '';
        foreach (self::content($pm) as $child) {
            $text .= Kit::str($child['text'] ?? null);
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $pm
     *
     * @return array<string, mixed>
     */
    private function textblock(array $pm): array
    {
        [$tree, $owned] = self::textblocks()[Kit::str($pm['type'] ?? null)];
        $carve = Kit::carveOf($pm);
        $node = self::with(['type' => $carve['$type'] ?? $tree], Kit::treeFields($carve));
        $attrs = Kit::map($pm['attrs'] ?? null);
        foreach ($owned as $key) {
            if (isset($attrs[$key])) {
                $node[$key] = $attrs[$key];
            }
        }
        $node['children'] = $this->inlines(self::content($pm));

        return $node;
    }

    /**
     * @param array<string, mixed> $pm
     *
     * @return array<string, mixed>
     */
    private function container(array $pm): array
    {
        [$tree, $owned] = self::containers()[Kit::str($pm['type'] ?? null)];
        $node = self::with(['type' => $tree], Kit::treeFields(Kit::carveOf($pm)));
        $attrs = Kit::map($pm['attrs'] ?? null);
        foreach ($owned as $key) {
            if (isset($attrs[$key])) {
                $node[$key] = $attrs[$key];
            }
        }
        $content = self::content($pm);
        if (($content[0]['type'] ?? null) === 'carveTitle') {
            $title = $this->inlines(self::content($content[0]));
            if ($title !== []) {
                $node['title'] = $title;
            }
            $content = array_slice($content, 1);
        }
        $node['children'] = $this->blocks($content);

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    private static function defaultMarker(string $type): array
    {
        return $type === 'orderedList' ? ['kind' => 'ordered', 'style' => '1', 'delimiter' => '.'] : ['kind' => 'bullet', 'char' => '-'];
    }

    private static function taskChecked(mixed $task): bool
    {
        $mark = is_array($task) ? ($task['mark'] ?? null) : null;

        return $mark === 'x' || $mark === 'X';
    }

    /**
     * Whether `$pm` holds anything but placeholders.
     *
     * @param array<string, mixed> $pm
     */
    private static function filled(array $pm): bool
    {
        return array_filter(self::content($pm), static fn (array $c): bool => !self::isPlaceholder($c)) !== [];
    }

    /**
     * @param array<string, mixed> $pm
     *
     * @return array<string, mixed>|null
     */
    private function listNode(array $pm): ?array
    {
        $fields = Kit::treeFields(Kit::carveOf($pm));
        // An item with nothing in it is what Enter or a toggle leaves behind, unless it was loaded empty.
        $items = array_filter(
            self::content($pm),
            static fn (array $item): bool => (Kit::carveOf($item)['$empty'] ?? null) === true || self::filled($item),
        );
        if ($items === []) {
            return null;
        }
        $node = self::with(['type' => 'list', 'spacing' => 'tight'], $fields);
        $node['marker'] = self::marker($pm, $fields['marker'] ?? null);
        $node['children'] = array_map($this->listItem(...), array_values($items));

        return $node;
    }

    /**
     * @param array<string, mixed> $pm
     * @param mixed $marker
     *
     * @return array<string, mixed>
     */
    private static function marker(array $pm, mixed $marker): array
    {
        $type = Kit::str($pm['type'] ?? null);
        $wanted = match ($type) {
            'orderedList' => 'ordered',
            'bulletList' => 'bullet',
            default => null,
        };
        $marker = Kit::map($marker);
        if ($marker === [] || ($wanted !== null && ($marker['kind'] ?? null) !== $wanted)) {
            $marker = self::defaultMarker($type);
        }
        $start = Kit::map($pm['attrs'] ?? null)['start'] ?? null;
        if ($type === 'orderedList' && is_int($start) && $start !== ($marker['start'] ?? 1)) {
            $marker['start'] = $start;
        }

        return $marker;
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     */
    private function listItem(array $item): array
    {
        $itemFields = Kit::treeFields(Kit::carveOf($item));
        $child = self::with(['type' => 'list_item'], $itemFields);
        if (($item['type'] ?? null) === 'taskItem') {
            $checked = (Kit::map($item['attrs'] ?? null)['checked'] ?? null) === true;
            if (!array_key_exists('task', $itemFields) || self::taskChecked($itemFields['task']) !== $checked) {
                $child['task'] = ['mark' => $checked ? 'x' : ' '];
            }
        }
        $child['children'] = $this->blocks(self::content($item));

        return $child;
    }

    /**
     * @param array<string, mixed> $pm
     *
     * @return array<string, mixed>
     */
    private function table(array $pm): array
    {
        $carve = Kit::carveOf($pm);
        $sections = Kit::nodes($carve['$sections'] ?? null);
        if ($sections === []) {
            $sections = [['type' => 'table_body']];
        }
        // A body row added after a head that has no body behind it needs one; loaded rows keep their sections.
        $head = self::find($sections, static fn (array $s, int $i): bool => ($s['type'] ?? null) === 'table_head');
        $added = -1;
        if ($head >= 0 && self::bodyAfter($sections, $head) < 0) {
            array_splice($sections, $head + 1, 0, [['type' => 'table_body']]);
            $added = $head + 1;
        }
        $rowsOf = array_fill(0, count($sections), []);
        $width = $this->placeRows(self::content($pm), $sections, $added, $rowsOf);
        $node = self::with(['type' => 'table'], Kit::treeFields($carve));
        $node['children'] = self::sections($sections, $rowsOf);
        $columns = is_array($node['columns'] ?? null) ? array_slice(array_values($node['columns']), 0, $width) : [];
        $node['columns'] = array_pad($columns, $width, ['type' => 'table_column']);

        return $node;
    }

    /**
     * Sorts the rows into the sections they came from, a new row into the section of the row before it; returns the
     * width of the widest row.
     *
     * @param array<array<string, mixed>> $rows
     * @param array<array<string, mixed>> $sections
     * @param int $added The index of a body section the save added, or -1.
     * @param array<int, array<array<string, mixed>>> $rowsOf
     */
    private function placeRows(array $rows, array $sections, int $added, array &$rowsOf): int
    {
        $current = self::find($sections, static fn (array $s, int $i): bool => ($s['type'] ?? null) === 'table_body');
        if ($current < 0) {
            $current = self::find($sections, static fn (array $s, int $i): bool => !array_key_exists('$node', $s));
        }
        $width = 0;
        foreach ($rows as $row) {
            $rowCarve = Kit::carveOf($row);
            $cells = self::content($row);
            $from = $rowCarve['$section'] ?? null;
            $from = is_int($from) && $added >= 0 && $from >= $added ? $from + 1 : $from;
            if (is_int($from) && isset($rowsOf[$from])) {
                $current = $from;
            } elseif (
                ($sections[$current]['type'] ?? null) === 'table_head'
                && array_filter($cells, static fn (array $c): bool => ($c['type'] ?? null) !== 'tableHeader') !== []
            ) {
                $current = self::bodyAfter($sections, $current);
            }
            $kept = array_filter(
                $cells,
                static fn (array $cell): bool => (Kit::carveOf($cell)['$pad'] ?? null) !== true || self::filled($cell),
            );
            $saved = array_map($this->cell(...), array_values($kept));
            $width = max($width, count($saved));
            foreach (Kit::nodes($rowCarve['$before'] ?? null) as $comment) {
                $rowsOf[$current][] = $comment;
            }
            $rowsOf[$current][] = self::with(['type' => 'table_row'], Kit::treeFields($rowCarve)) + ['children' => $saved];
        }

        return $width;
    }

    /**
     * @param array<array<string, mixed>> $sections
     * @param array<int, array<array<string, mixed>>> $rowsOf
     *
     * @return array<array<string, mixed>>
     */
    private static function sections(array $sections, array $rowsOf): array
    {
        $children = [];
        foreach ($sections as $index => $section) {
            if (array_key_exists('$node', $section)) {
                $children[] = Kit::map($section['$node']);

                continue;
            }
            $rows = $rowsOf[$index];
            $hasRow = array_filter($rows, static fn (array $r): bool => ($r['type'] ?? null) === 'table_row') !== [];
            if (!$hasRow && ($section['$empty'] ?? null) !== true) {
                continue;
            }
            foreach (Kit::nodes($section['$after'] ?? null) as $comment) {
                $rows[] = $comment;
            }
            $sectionNode = Kit::treeFields($section);
            $sectionNode['type'] = $section['type'] ?? 'table_body';
            $sectionNode['children'] = $rows;
            $children[] = $sectionNode;
        }

        return $children;
    }

    /**
     * The index of the first body section after `$index`, or -1.
     *
     * @param array<array<string, mixed>> $sections
     * @param int $index
     */
    private static function bodyAfter(array $sections, int $index): int
    {
        return self::find($sections, static fn (array $s, int $i): bool => $i > $index && ($s['type'] ?? null) === 'table_body');
    }

    /**
     * The index of the first entry `$test` accepts, or -1.
     *
     * @param array<array<string, mixed>> $list
     * @param callable(array<string, mixed>, int): bool $test
     */
    private static function find(array $list, callable $test): int
    {
        foreach ($list as $index => $entry) {
            if ($test($entry, $index)) {
                return $index;
            }
        }

        return -1;
    }

    /**
     * @param array<string, mixed> $pm
     *
     * @return array<string, mixed>
     */
    private function cell(array $pm): array
    {
        $content = self::content($pm);
        $single = count(array_filter($content, static fn (array $c): bool => !self::isPlaceholder($c))) === 1;
        $children = [];
        foreach ($content as $child) {
            if (self::isPlaceholder($child)) {
                continue;
            }
            $node = $this->block($child);
            if ($node === null) {
                continue;
            }
            if (($child['type'] ?? null) === 'paragraph' && !array_key_exists('$type', Kit::carveOf($child)) && $single) {
                $node['type'] = 'cell_paragraph';
            }
            $children[] = $node;
        }
        // A cell the editor created empty still needs content; an empty cell reads as one empty cell paragraph.
        if ($children === [] && (Kit::carveOf($pm)['$empty'] ?? null) !== true) {
            $children[] = ['type' => 'cell_paragraph', 'children' => []];
        }
        $node = self::with(['type' => 'table_cell'], Kit::treeFields(Kit::carveOf($pm)));
        $node['header'] = ($pm['type'] ?? null) === 'tableHeader';
        $node['children'] = $children;

        return $node;
    }

    /**
     * A mark's identity: the loaded node it came from, or for a mark the editor added, its type and attributes.
     *
     * @param array<string, mixed> $mark
     */
    private static function markKey(array $mark): string
    {
        $id = Kit::carveOf($mark)['$id'] ?? null;
        if ($id !== null) {
            return '#' . (is_scalar($id) ? (string)$id : Kit::encode(['id' => $id]));
        }

        return Kit::str($mark['type'] ?? null) . ':' . Kit::encode(Kit::map($mark['attrs'] ?? null));
    }

    /**
     * Rebuilds the nested inline containers from the marks of each leaf: a longer run opens outside a shorter one.
     *
     * @param array<array<string, mixed>> $content
     *
     * @return array<array<string, mixed>>
     */
    private function inlines(array $content): array
    {
        $leaves = [];
        foreach ($content as $pm) {
            $marks = array_values(array_filter(
                Kit::nodes($pm['marks'] ?? null),
                static fn (array $mark): bool => ($mark['type'] ?? null) !== 'code',
            ));
            $leaves[] = ['pm' => $pm, 'marks' => $marks, 'keys' => array_map(self::markKey(...), $marks)];
        }
        $runs = array_fill(0, count($leaves), []);
        for ($index = count($leaves) - 1; $index >= 0; $index--) {
            foreach ($leaves[$index]['keys'] as $key) {
                $runs[$index][$key] = 1 + ($runs[$index + 1][$key] ?? 0);
            }
        }
        // The nodes in order of creation; a container's children are the ids under it in $kids, -1 is the root.
        $nodes = [];
        $kids = [-1 => []];
        $stack = [];
        foreach ($leaves as $index => $leaf) {
            $keep = 0;
            $open = count($stack);
            while ($keep < $open && in_array($stack[$keep]['key'], $leaf['keys'], true)) {
                $keep++;
            }
            $stack = array_slice($stack, 0, $keep);
            foreach (self::fresh($leaf, array_column($stack, 'key'), $runs[$index]) as $entry) {
                $id = count($nodes);
                $nodes[] = $this->markNode($entry['mark']);
                $kids[$stack === [] ? -1 : $stack[count($stack) - 1]['id']][] = $id;
                $kids[$id] = [];
                $stack[] = ['key' => $entry['key'], 'id' => $id];
            }
            $id = count($nodes);
            $nodes[] = $this->leaf($leaf['pm']);
            $kids[$stack === [] ? -1 : $stack[count($stack) - 1]['id']][] = $id;
        }

        return self::assemble($nodes, $kids, -1);
    }

    /**
     * The marks of `$leaf` not open yet, in the order they open: the longest run first, then the loaded depth.
     *
     * @param array{pm: array<string, mixed>, marks: array<array<string, mixed>>, keys: array<string>} $leaf
     * @param array<string> $opened
     * @param array<string, int> $run
     *
     * @return array<array{mark: array<string, mixed>, order: int, key: string}>
     */
    private static function fresh(array $leaf, array $opened, array $run): array
    {
        $fresh = [];
        foreach ($leaf['marks'] as $order => $mark) {
            $key = $leaf['keys'][$order];
            if (!in_array($key, $opened, true)) {
                $fresh[] = ['mark' => $mark, 'order' => $order, 'key' => $key];
            }
        }
        $depth = static function (array $entry): float {
            $depth = Kit::carveOf($entry['mark'])['$depth'] ?? null;

            return is_int($depth) || is_float($depth) ? (float)$depth : INF;
        };
        usort($fresh, static fn (array $a, array $b): int => [$run[$b['key']], $depth($a), $a['order']]
            <=> [$run[$a['key']], $depth($b), $b['order']]);

        return $fresh;
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @param array<int, array<int>> $kids
     * @param int $parent
     *
     * @return array<array<string, mixed>>
     */
    private static function assemble(array $nodes, array $kids, int $parent): array
    {
        $out = [];
        foreach ($kids[$parent] as $id) {
            $node = $nodes[$id];
            if (isset($kids[$id])) {
                $node['children'] = self::assemble($nodes, $kids, $id);
            }
            $out[] = $node;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $mark
     *
     * @throws \InvalidArgumentException
     *
     * @return array<string, mixed>
     */
    private function markNode(array $mark): array
    {
        self::$marks ??= Kit::inverse(Kit::MARKS);
        $type = Kit::str($mark['type'] ?? null);
        $spec = self::$marks[$type] ?? null;
        if ($spec === null) {
            throw new InvalidArgumentException(sprintf('ProseMirrorToCarve: no Carve node for the ProseMirror mark "%s"', $type));
        }
        [$tree, $owned] = $spec;
        $node = self::with(['type' => $tree], Kit::treeFields(Kit::carveOf($mark)));
        $attrs = Kit::map($mark['attrs'] ?? null);
        foreach ($owned as $key) {
            if (isset($attrs[$key])) {
                $node[$key] = $attrs[$key];
            }
        }
        $node['children'] = [];

        return $node;
    }

    /**
     * @param array<string, mixed> $pm
     *
     * @throws \InvalidArgumentException
     *
     * @return array<string, mixed>
     */
    private function leaf(array $pm): array
    {
        $carve = Kit::carveOf($pm);
        $attrs = Kit::map($pm['attrs'] ?? null);
        $type = Kit::str($pm['type'] ?? null);

        return match ($type) {
            'text' => self::text($pm),
            'hardBreak' => self::with(['type' => 'hard_break'], Kit::treeFields($carve)),
            'image' => self::image($pm),
            'carveMath' => self::with(['type' => 'math'], Kit::treeFields($carve))
                + ['display' => ($attrs['display'] ?? null) === true, 'value' => Kit::str($attrs['value'] ?? null)],
            'carveFootnoteRef' => self::with(['type' => 'footnote_ref'], Kit::treeFields($carve))
                + ['key' => Kit::str($attrs['key'] ?? null), 'namespace' => 'footnote'],
            'carveInline' => Kit::map(Kit::clean($carve)),
            default => throw new InvalidArgumentException(
                sprintf('ProseMirrorToCarve: no Carve node for the ProseMirror inline "%s"', $type),
            ),
        };
    }

    /**
     * @param array<string, mixed> $pm
     *
     * @return array<string, mixed>
     */
    private static function text(array $pm): array
    {
        foreach (Kit::nodes($pm['marks'] ?? null) as $mark) {
            if (($mark['type'] ?? null) === 'code') {
                return self::with(['type' => 'code'], Kit::treeFields(Kit::carveOf($mark))) + ['value' => Kit::str($pm['text'] ?? null)];
            }
        }

        return ['type' => 'text', 'value' => Kit::str($pm['text'] ?? null)];
    }

    /**
     * @param array<string, mixed> $pm
     *
     * @return array<string, mixed>
     */
    private static function image(array $pm): array
    {
        $attrs = Kit::map($pm['attrs'] ?? null);
        $node = self::with(['type' => 'image'], Kit::treeFields(Kit::carveOf($pm)));
        foreach (['src' => 'src', 'alt' => 'alt', 'title' => 'tooltip'] as $attr => $key) {
            if (is_string($attrs[$attr] ?? null)) {
                $node[$key] = $attrs[$attr];
            }
        }

        return $node;
    }
}
