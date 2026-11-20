<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\ProseMirror;

use Closure;
use InvalidArgumentException;
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Html\UrlPolicy;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Options;

/**
 * ProseMirror JSON of a Carve 0.2 tree, in the schema of the carve-grammars Tiptap kit (`tiptap/tree.js`).
 *
 * `ProseMirrorToCarve::toTree()` of the result gives the same tree for any document: a node the editor content
 * model cannot hold becomes an atom that carries it whole. A link or image destination the URL policy denies is
 * null in the attribute the editor renders and kept in `carve`, so the editor never resolves it (carve#6009).
 */
final class ProseMirrorRenderer
{
    private int $id = 0;

    private Options $options;

    /**
     * @var array<string>
     */
    private array $blanked = [];

    /**
     * @param array<string, mixed> $options `urlPolicy`, as for `Carve::render()`.
     */
    public function __construct(array $options = [])
    {
        $this->options = Options::from(array_intersect_key($options, ['urlPolicy' => true]));
    }

    /**
     * The denied destinations the last `render()` kept out of the editor's link and image attributes.
     *
     * @return array<string>
     */
    public function blanked(): array
    {
        return $this->blanked;
    }

    /**
     * The ProseMirror `doc` of `$tree`.
     *
     * @param \MarkupCarve\Carve\Node|array<string, mixed> $tree A `document` tree, or its decoded AST JSON.
     *
     * @throws \InvalidArgumentException
     *
     * @return array<string, mixed>
     */
    public function render(Node|array $tree): array
    {
        if (is_array($tree) && ($tree['type'] ?? null) !== 'document') {
            throw new InvalidArgumentException('ProseMirrorRenderer expects a Carve document tree');
        }
        // A copy, resolved as `Carve::render()` resolves its input (C2-T-005).
        $resolve = Closure::bind(static fn (Node|array $t, Options $o): array => Carve::resolvedTree($t, $o), null, Carve::class);
        [$doc] = $resolve($tree, Options::from([]));
        $tree = Kit::map(Kit::decode(Carve::encodeTree($doc)));
        if (($tree['type'] ?? null) !== 'document') {
            throw new InvalidArgumentException('ProseMirrorRenderer expects a Carve document tree');
        }
        $this->id = 0;
        $this->blanked = [];
        $children = $this->list($tree['children'] ?? []);
        $content = $this->blocks($children);
        if ($children !== []) {
            foreach ($content as $index => $node) {
                $carve = Kit::carveOf($node);
                $carve['$index'] = $index;
                $attrs = Kit::map($node['attrs'] ?? null);
                $attrs['carve'] = $carve;
                $node['attrs'] = $attrs;
                $content[$index] = $node;
            }
        }

        return [
            'type' => 'doc',
            'attrs' => ['carve' => Kit::carveAttr(Kit::fields($tree, ['children']))],
            'content' => $content,
        ];
    }

    /**
     * `render()` as JSON, for the editor's `setContent()`.
     *
     * @param \MarkupCarve\Carve\Node|array<string, mixed> $tree
     */
    public function renderJson(Node|array $tree): string
    {
        return Kit::encode($this->render($tree));
    }

    /**
     * The ProseMirror `doc` of Carve source.
     *
     * @param string $source
     * @param array<string, mixed> $options Passed to `Carve::parse()`.
     *
     * @return array<string, mixed>
     */
    public function renderSource(string $source, array $options = []): array
    {
        return $this->render(Carve::parse($source, $options));
    }

    private function denied(mixed $url): bool
    {
        if (!is_string($url) || UrlPolicy::passes($url, $this->options)) {
            return false;
        }
        $this->blanked[] = $url;

        return true;
    }

    /**
     * @return array<array<string, mixed>>
     */
    private function list(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? $value : [];
    }

    /**
     * Blocks as ProseMirror nodes; an empty list becomes one empty paragraph, which the way back drops.
     *
     * @param array<array<string, mixed>> $children
     *
     * @return array<array<string, mixed>>
     */
    private function blocks(array $children): array
    {
        $out = array_map($this->block(...), $children);

        return $out !== [] ? $out : [['type' => 'paragraph']];
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>
     */
    private function block(array $node): array
    {
        $type = Kit::str($node['type'] ?? null);
        if (array_key_exists('caption', $node) && $type !== 'caption') {
            return $this->captioned($node);
        }
        if (isset(Kit::TEXTBLOCKS[$type])) {
            return $this->textblock($node);
        }
        if (isset(Kit::CONTAINERS[$type])) {
            return $this->container($node);
        }
        if (isset(Kit::CODE_BLOCKS[$type])) {
            return $this->codeLike($node, Kit::CODE_BLOCKS[$type]);
        }

        return match ($type) {
            'code_block' => $this->codeBlock($node),
            'thematic_break' => ['type' => 'horizontalRule', 'attrs' => ['carve' => Kit::carveAttr(Kit::fields($node))]],
            'list' => $this->listNode($node),
            'definition_list' => $this->mixed($node, 'carveDefinitionList'),
            'line_block' => $this->mixed($node, 'carveLineBlock'),
            'table' => $this->table($node),
            default => self::blockAtom($node),
        };
    }

    /**
     * A block with its caption: the tree keeps the caption as a field of the block.
     *
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>
     */
    private function captioned(array $node): array
    {
        $caption = Kit::map($node['caption']);
        unset($node['caption']);
        $captionNode = $this->container($caption);
        if ($this->list($caption['children'] ?? []) === []) {
            $carve = Kit::carveOf($captionNode);
            $carve['$empty'] = true;
            $captionNode['attrs'] = ['carve' => $carve] + Kit::map($captionNode['attrs']);
        }

        return ['type' => 'carveCaptioned', 'content' => [$this->block($node), $captionNode]];
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>
     */
    private static function blockAtom(array $node): array
    {
        return ['type' => 'carveBlock', 'attrs' => ['carve' => Kit::clean($node)]];
    }

    /**
     * @param array<string, mixed> $node
     * @param array<array<string, mixed>> $marks
     *
     * @return array<string, mixed>
     */
    private static function inlineAtom(array $node, array $marks): array
    {
        return self::withMarks(['type' => 'carveInline', 'attrs' => ['carve' => Kit::clean($node)]], $marks);
    }

    /**
     * @param array<string, mixed> $pm
     * @param array<array<string, mixed>> $marks
     *
     * @return array<string, mixed>
     */
    private static function withMarks(array $pm, array $marks): array
    {
        if ($marks !== []) {
            $pm['marks'] = $marks;
        }

        return $pm;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function textblock(array $node, array $extra = []): array
    {
        [$name, $owned] = Kit::TEXTBLOCKS[Kit::str($node['type'] ?? null)];
        $carve = Kit::fields($node, ['children', ...$owned]) + $extra;
        $children = $this->list($node['children'] ?? []);
        // A loaded empty paragraph is content; only one the editor made is a placeholder.
        if ($children === [] && $carve === []) {
            $carve['$empty'] = true;
        }
        $attrs = ['carve' => Kit::carveAttr($carve)];
        foreach ($owned as $key) {
            if (array_key_exists($key, $node)) {
                $attrs[$key] = $node[$key];
            }
        }
        $content = $this->inlines($children);
        $pm = ['type' => $name, 'attrs' => $attrs];
        if ($content !== []) {
            $pm['content'] = $content;
        }

        return $pm;
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>
     */
    private function container(array $node): array
    {
        $type = Kit::str($node['type'] ?? null);
        $type = isset(Kit::CONTAINERS[$type]) ? $type : 'caption';
        [$name, $owned] = Kit::CONTAINERS[$type];
        $title = $this->list($node['title'] ?? null);
        $titled = isset(Kit::TITLED[$type]) && $title !== [];
        $attrs = ['carve' => Kit::carveAttr(Kit::fields($node, ['children', ...$owned, ...($titled ? ['title'] : [])]))];
        foreach ($owned as $key) {
            if (array_key_exists($key, $node)) {
                $attrs[$key] = $node[$key];
            }
        }
        $content = $this->blocks($this->list($node['children'] ?? []));
        if ($titled) {
            $inlines = $this->inlines($title);
            array_unshift($content, $inlines !== [] ? ['type' => 'carveTitle', 'content' => $inlines] : ['type' => 'carveTitle']);
        }

        return ['type' => $name, 'attrs' => $attrs, 'content' => $content];
    }

    /**
     * @param array<string, mixed> $node
     * @param string $name
     *
     * @return array<string, mixed>
     */
    private function codeLike(array $node, string $name): array
    {
        $value = Kit::str($node['value'] ?? null);
        $pm = ['type' => $name, 'attrs' => ['carve' => Kit::carveAttr(Kit::fields($node, ['value']))]];
        if ($value !== '') {
            $pm['content'] = [['type' => 'text', 'text' => $value]];
        }

        return $pm;
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>
     */
    private function codeBlock(array $node): array
    {
        $value = Kit::str($node['value'] ?? null);
        $newline = str_ends_with($value, "\n");
        $text = $newline && !str_ends_with($value, "\n\n") ? substr($value, 0, -1) : $value;
        $carve = Kit::fields($node, ['value', 'syntax']);
        if (!$newline) {
            $carve['$newline'] = false;
        }
        $pm = ['type' => 'codeBlock', 'attrs' => ['language' => $node['syntax'] ?? null, 'carve' => Kit::carveAttr($carve)]];
        if ($text !== '') {
            $pm['content'] = [['type' => 'text', 'text' => $text]];
        }

        return $pm;
    }

    private static function taskChecked(mixed $task): bool
    {
        $mark = is_array($task) ? ($task['mark'] ?? null) : null;

        return $mark === 'x' || $mark === 'X';
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>
     */
    private function listNode(array $node): array
    {
        $items = $this->list($node['children'] ?? []);
        if ($items === []) {
            return self::blockAtom($node);
        }
        $tasks = true;
        foreach ($items as $item) {
            if (!array_key_exists('task', $item)) {
                $tasks = false;
            }
        }
        $marker = is_array($node['marker'] ?? null) ? $node['marker'] : [];
        $type = $tasks ? 'taskList' : (($marker['kind'] ?? null) === 'ordered' ? 'orderedList' : 'bulletList');
        $attrs = ['carve' => Kit::carveAttr(Kit::fields($node, ['children']))];
        if ($type === 'orderedList') {
            $attrs['start'] = $marker['start'] ?? 1;
        }
        $content = [];
        foreach ($items as $item) {
            $content[] = $this->listItem($item, $tasks);
        }

        return ['type' => $type, 'attrs' => $attrs, 'content' => $content];
    }

    /**
     * @param array<string, mixed> $item
     * @param bool $tasks
     *
     * @return array<string, mixed>
     */
    private function listItem(array $item, bool $tasks): array
    {
        $itemFields = Kit::fields($item, ['children']);
        $children = $this->list($item['children'] ?? []);
        if ($children === []) {
            $itemFields['$empty'] = true;
        }
        $itemAttrs = ['carve' => Kit::carveAttr($itemFields)];
        if ($tasks) {
            $itemAttrs['checked'] = self::taskChecked($item['task'] ?? null);
        }
        // Tiptap items start with a paragraph; an empty one, which the way back drops, leads any other block.
        $blocks = $this->blocks($children);
        if ($blocks[0]['type'] !== 'paragraph') {
            array_unshift($blocks, ['type' => 'paragraph']);
        }

        return ['type' => $tasks ? 'taskItem' : 'listItem', 'attrs' => $itemAttrs, 'content' => $blocks];
    }

    /**
     * Definition lists and line blocks: their children are their own node types and comments.
     *
     * @param array<string, mixed> $node
     * @param string $name
     *
     * @return array<string, mixed>
     */
    private function mixed(array $node, string $name): array
    {
        $children = $this->list($node['children'] ?? []);
        if ($children === []) {
            return self::blockAtom($node);
        }

        return [
            'type' => $name,
            'attrs' => ['carve' => Kit::carveAttr(Kit::fields($node, ['children']))],
            'content' => array_map($this->block(...), $children),
        ];
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>
     */
    private function table(array $node): array
    {
        $sections = [];
        $rows = [];
        foreach ($this->list($node['children'] ?? []) as $index => $section) {
            $sections[] = ($section['type'] ?? null) === 'comment'
                ? ['$node' => Kit::clean($section)]
                : $this->tableSection($section, $index, $rows);
        }
        if ($rows === [] || in_array([], array_column($rows, 'content'), true)) {
            return self::blockAtom($node);
        }
        // ProseMirror tables are rectangular: short rows get marked padding cells, which the way back drops while empty.
        $widths = Kit::gridWidths(array_map(
            static fn (array $row): array => array_map(static function (array $cell): array {
                $attrs = Kit::map($cell['attrs'] ?? null);

                return [Kit::spanCount($attrs['colspan'] ?? null), Kit::spanCount($attrs['rowspan'] ?? null)];
            }, Kit::nodes($row['content'])),
            $rows,
        ));
        $width = $widths === [] ? 0 : max($widths);
        $pad = ['type' => 'tableCell', 'attrs' => ['carve' => ['$pad' => true]], 'content' => [['type' => 'paragraph']]];
        foreach ($rows as $i => $row) {
            $content = Kit::nodes($row['content']);
            for ($column = $widths[$i]; $column < $width; $column++) {
                $content[] = $pad;
            }
            $rows[$i]['content'] = $content;
        }
        $carve = Kit::fields($node, ['children']);
        $carve['$sections'] = $sections;
        if ($this->bareSpans($node)) {
            $carve['$bareSpans'] = true;
        }

        return ['type' => 'table', 'attrs' => ['carve' => $carve], 'content' => $rows];
    }

    /**
     * Whether the table has counts and no mark cells: an imported span (C2-A-093), which saves back without marks.
     *
     * @param array<string, mixed> $node
     */
    private function bareSpans(array $node): bool
    {
        $counted = false;
        foreach ($this->list($node['children'] ?? []) as $section) {
            foreach ($this->list($section['children'] ?? []) as $row) {
                foreach ($this->list($row['children'] ?? []) as $cell) {
                    if (array_key_exists('span', $cell)) {
                        return false;
                    }
                    $counted = $counted || ($cell['colspan'] ?? 1) > 1 || ($cell['rowspan'] ?? 1) > 1;
                }
            }
        }

        return $counted;
    }

    /**
     * The `$sections` entry of a table section; its rows are appended to `$rows`.
     *
     * @param array<string, mixed> $section
     * @param int $index
     * @param array<array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private function tableSection(array $section, int $index, array &$rows): array
    {
        $entry = ['type' => $section['type'] ?? null] + Kit::fields($section, ['children']);
        $before = [];
        $hasRow = false;
        foreach ($this->list($section['children'] ?? []) as $row) {
            if (($row['type'] ?? null) === 'comment') {
                $before[] = Kit::clean($row);

                continue;
            }
            $hasRow = true;
            $carve = Kit::fields($row, ['children']);
            $carve['$section'] = $index;
            if ($before !== []) {
                $carve['$before'] = $before;
            }
            $before = [];
            // ProseMirror has no cell where a span mark stands: the covering cell's counts span it.
            $cells = array_values(array_filter(
                $this->list($row['children'] ?? []),
                static fn (array $cell): bool => !array_key_exists('span', $cell),
            ));
            $content = array_map($this->cell(...), $cells);
            $th = array_filter($content, static fn (array $c): bool => $c['type'] !== 'tableHeader') === [];
            if (($section['type'] ?? null) !== 'table_head' && $content !== [] && $th) {
                $carve['$th'] = true;
            }
            $rows[] = [
                'type' => 'tableRow',
                'attrs' => ['carve' => $carve],
                'content' => $content,
            ];
        }
        if ($before !== []) {
            $entry['$after'] = $before;
        }
        if (!$hasRow) {
            $entry['$empty'] = true;
        }

        return $entry;
    }

    /**
     * @param array<string, mixed> $cell
     *
     * @return array<string, mixed>
     */
    private function cell(array $cell): array
    {
        $content = [];
        foreach ($this->list($cell['children'] ?? []) as $child) {
            $content[] = ($child['type'] ?? null) === 'cell_paragraph'
                ? $this->textblock(['type' => 'paragraph'] + $child, ['$type' => 'cell_paragraph'])
                : $this->block($child);
        }
        $carve = Kit::fields($cell, ['children', 'header', 'colspan', 'rowspan']);
        if ($content === []) {
            $carve['$empty'] = true;
        }
        $attrs = ['carve' => Kit::carveAttr($carve)];
        foreach (['colspan', 'rowspan'] as $key) {
            if (is_int($cell[$key] ?? null) && $cell[$key] > 1) {
                $attrs[$key] = $cell[$key];
            }
        }

        return [
            'type' => ($cell['header'] ?? false) === true ? 'tableHeader' : 'tableCell',
            'attrs' => $attrs,
            'content' => $content !== [] ? $content : [['type' => 'paragraph']],
        ];
    }

    /**
     * @param array<array<string, mixed>> $children
     *
     * @return array<array<string, mixed>>
     */
    private function inlines(array $children): array
    {
        $out = [];
        $this->pushInlines($children, [], [], $out);

        return $out;
    }

    /**
     * @param array<array<string, mixed>> $children
     * @param array<array<string, mixed>> $marks
     * @param array<string, true> $open
     * @param array<array<string, mixed>> $out
     */
    private function pushInlines(array $children, array $marks, array $open, array &$out): void
    {
        foreach ($children as $child) {
            $spec = Kit::MARKS[Kit::str($child['type'] ?? null)] ?? null;
            $inner = $this->list($child['children'] ?? null);
            if ($spec !== null && $inner !== [] && !isset($open[$spec[0]])) {
                [$mark, $owned] = $spec;
                $carve = Kit::fields($child, ['children', ...$owned]);
                $carve['$id'] = ++$this->id;
                $carve['$depth'] = count($marks);
                $attrs = ['carve' => $carve];
                foreach ($owned as $key) {
                    $attrs[$key] = $child[$key] ?? null;
                }
                if ($mark === 'link' && $this->denied($attrs['href'] ?? null)) {
                    $carve['href'] = $attrs['href'] ?? null;
                    $attrs['carve'] = $carve;
                    $attrs['href'] = null;
                }
                $this->pushInlines($inner, [...$marks, ['type' => $mark, 'attrs' => $attrs]], $open + [$mark => true], $out);

                continue;
            }
            $out[] = $this->leaf($child, $marks);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param array<array<string, mixed>> $marks
     *
     * @return array<string, mixed>
     */
    private function leaf(array $node, array $marks): array
    {
        return match ($node['type'] ?? null) {
            'text' => Kit::str($node['value'] ?? null) === ''
                ? self::inlineAtom($node, $marks)
                : self::withMarks(['type' => 'text', 'text' => Kit::str($node['value'])], $marks),
            'code' => $this->code($node, $marks),
            'hard_break' => self::withMarks(['type' => 'hardBreak', 'attrs' => ['carve' => Kit::carveAttr(Kit::fields($node))]], $marks),
            'soft_break' => self::withMarks(
                ['type' => 'carveSoftBreak', 'attrs' => ['carve' => Kit::carveAttr(Kit::fields($node))]],
                $marks,
            ),
            'image' => self::withMarks($this->image($node), $marks),
            'math' => self::withMarks([

                'type' => 'carveMath',
                'attrs' => [
                    'value' => $node['value'] ?? null,
                    'display' => ($node['display'] ?? false) === true,
                    'carve' => Kit::carveAttr(Kit::fields($node, ['value', 'display'])),
                ],
            ], $marks),
            'footnote_ref' => self::withMarks([

                'type' => 'carveFootnoteRef',
                'attrs' => [
                    'key' => $node['key'] ?? null,
                    'carve' => Kit::carveAttr(Kit::fields($node, ['key', 'namespace'])),
                ],
            ], $marks),
            default => self::inlineAtom($node, $marks),
        };
    }

    /**
     * @param array<string, mixed> $node
     * @param array<array<string, mixed>> $marks
     *
     * @return array<string, mixed>
     */
    private function code(array $node, array $marks): array
    {
        $value = Kit::str($node['value'] ?? null);
        if ($value === '') {
            return self::inlineAtom($node, $marks);
        }
        $carve = Kit::fields($node, ['value']);
        $carve['$id'] = ++$this->id;
        $carve['$depth'] = count($marks);

        return ['type' => 'text', 'text' => $value, 'marks' => [...$marks, ['type' => 'code', 'attrs' => ['carve' => $carve]]]];
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>
     */
    private function image(array $node): array
    {
        $denied = $this->denied($node['src'] ?? null);

        return [

            'type' => 'image',
            'attrs' => [
                'src' => $denied ? null : $node['src'] ?? null,
                'alt' => $node['alt'] ?? null,
                'title' => $node['tooltip'] ?? null,
                'carve' => Kit::carveAttr(Kit::fields($node, $denied ? ['alt', 'tooltip'] : ['src', 'alt', 'tooltip'])),
            ],
        ];
    }
}
