<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\ProseMirror;

use InvalidArgumentException;
use MarkupCarve\Carve\Carve;

/**
 * Carve from ProseMirror JSON in the schema of the carve-grammars Tiptap kit (`tiptap/tree.js`).
 *
 * `toTree()` builds the 0.2 tree, `convert()` writes it with `Carve::write()`. A ProseMirror node or mark with no
 * Carve node in the kit or the `mapping` option is an error, not a skip.
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
     * The attributes each node and mark reads; any other attribute with a value is reported as ignored.
     *
     * @var array<string, array<string>>
     */
    private const READ = [
        'heading' => ['level'],
        'codeBlock' => ['language'],
        'orderedList' => ['start'],
        'taskItem' => ['checked'],
        'tableCell' => ['colspan', 'rowspan'],
        'tableHeader' => ['colspan', 'rowspan'],
        'image' => ['src', 'alt', 'title'],
        'link' => ['href'],
        'carveAdmonition' => ['kind'],
        'carveMath' => ['value', 'display'],
        'carveFootnoteRef' => ['key'],
    ];

    /**
     * Values the kit's own extensions give every node or mark of a type, which carry nothing to lose.
     *
     * @var array<string, array<string, string>>
     */
    private const STOCK = ['link' => ['target' => '_blank', 'rel' => 'noopener noreferrer nofollow']];

    /**
     * @var array<array{path: string, type: string, attribute: string, value: mixed}>
     */
    private array $ignored = [];

    /**
     * @var array{
     *     nodes: array<string, \MarkupCarve\Carve\ProseMirror\MappingEntry>,
     *     marks: array<string, \MarkupCarve\Carve\ProseMirror\MappingEntry>,
     * }
     */
    private array $mapping;

    /**
     * @var array<string, string>
     */
    private array $pointers = [];

    /**
     * @param array<string, mixed> $options `mapping`: the host's own ProseMirror nodes and marks (docs/prosemirror.md).
     */
    public function __construct(array $options = [])
    {
        $this->mapping = Kit::mapping($options['mapping'] ?? null);
    }

    /**
     * The ProseMirror JSON pointer of the node at `$treePath` in the last `toTree()` or `convert()`, such as the `path`
     * of a lint finding on the saved source: the pointer of the node or mark it came from, or of its nearest ancestor
     * that came from one (a span mark cell, a table section). Null when no ancestor did.
     */
    public function pointerFor(string $treePath): ?string
    {
        $at = $treePath;
        while (true) {
            if (array_key_exists($at, $this->pointers)) {
                return $this->pointers[$at];
            }
            if ($at === '') {
                return null;
            }
            $at = substr($at, 0, (int)strrpos($at, '/'));
        }
    }

    /**
     * The attributes the last `toTree()` or `convert()` did not read, each with the JSON pointer of its node or mark in
     * the saved document. A host that must not lose editor state can refuse a save when this is not empty.
     *
     * @return array<array{path: string, type: string, attribute: string, value: mixed}>
     */
    public function ignoredAttributes(): array
    {
        return $this->ignored;
    }

    /**
     * The Carve tree type of a ProseMirror node or mark name of the kit, or null when the kit does not map it.
     * `carveBlock` and `carveInline` carry a whole node of any type and give null.
     */
    public static function carveTypeFor(string $proseMirrorName): ?string
    {
        return self::proseMirrorNames()[$proseMirrorName] ?? null;
    }

    /**
     * Every ProseMirror node and mark name the kit maps to one Carve tree type, as name => tree type.
     *
     * @return array<string, string>
     */
    public static function proseMirrorNames(): array
    {
        $out = [];
        foreach ([Kit::TEXTBLOCKS, Kit::CONTAINERS, Kit::MARKS] as $table) {
            foreach ($table as $tree => [$name]) {
                $out[$name] = $tree;
            }
        }
        foreach (Kit::CODE_BLOCKS as $tree => $name) {
            $out[$name] = $tree;
        }

        return $out + [
            'codeBlock' => 'code_block',
            'horizontalRule' => 'thematic_break',
            'bulletList' => 'list',
            'orderedList' => 'list',
            'taskList' => 'list',
            'listItem' => 'list_item',
            'taskItem' => 'list_item',
            'carveDefinitionList' => 'definition_list',
            'carveLineBlock' => 'line_block',
            'table' => 'table',
            'tableRow' => 'table_row',
            'tableCell' => 'table_cell',
            'tableHeader' => 'table_cell',
            'text' => 'text',
            'code' => 'code',
            'hardBreak' => 'hard_break',
            'carveSoftBreak' => 'soft_break',
            'image' => 'image',
            'carveMath' => 'math',
            'carveFootnoteRef' => 'footnote_ref',
        ];
    }

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
     * The tree; for each top-level block the index of the loaded block it came from (null for a block the editor
     * added); and by tree path, the JSON pointer of the ProseMirror node or mark each tree node came from.
     *
     * @param array<string, mixed>|string $doc
     *
     * @throws \InvalidArgumentException
     *
     * @return array{tree: array<string, mixed>, origins: array<int|null>, pointers: array<string, string>}
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
        foreach (self::content($doc) as $at => $pm) {
            if (self::isPlaceholder($pm)) {
                continue;
            }
            $node = $this->block($pm, '/content/' . $at);
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
        $tree['$pointer'] = '';
        $this->pointers = [];
        $tree = $this->strip($tree, '');
        $this->ignored = [];
        $this->collectIgnored(Kit::map($doc), '');

        return ['tree' => $tree, 'origins' => $origins, 'pointers' => $this->pointers];
    }

    /**
     * Moves each node's `$pointer` into `$this->pointers` under its tree path.
     *
     * @param array<string, mixed> $node
     * @param string $path
     *
     * @return array<string, mixed>
     */
    private function strip(array $node, string $path): array
    {
        if (array_key_exists('$pointer', $node)) {
            $this->pointers[$path] = Kit::str($node['$pointer']);
            unset($node['$pointer']);
        }
        foreach ($node as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            if (!array_is_list($value)) {
                if (is_string($value['type'] ?? null)) {
                    $node[$key] = $this->strip($value, $path . '/' . $key);
                }

                continue;
            }
            foreach ($value as $index => $item) {
                if (is_array($item) && is_string($item['type'] ?? null)) {
                    $value[$index] = $this->strip($item, $path . '/' . $key . '/' . $index);
                }
            }
            $node[$key] = $value;
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $node
     * @param string $pointer
     *
     * @return array<string, mixed>
     */
    private static function tag(array $node, string $pointer): array
    {
        if (!array_key_exists('$pointer', $node)) {
            $node['$pointer'] = $pointer;
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $pm
     * @param string $path
     */
    private function collectIgnored(array $pm, string $path): void
    {
        $type = Kit::str($pm['type'] ?? null);
        $attrs = Kit::map($pm['attrs'] ?? null);
        $read = $this->reads($type, $attrs, $path);
        foreach ($attrs as $attribute => $value) {
            if ($attribute === 'carve' || $value === null || in_array($attribute, $read, true)) {
                continue;
            }
            if ((self::STOCK[$type][$attribute] ?? null) === $value) {
                continue;
            }
            $this->ignored[] = ['path' => $path, 'type' => $type, 'attribute' => $attribute, 'value' => $value];
        }
        // A `^` below a cell of more than one column joins nothing (C2-B-172), so such a cell keeps only its columns.
        if (
            in_array($type, ['tableCell', 'tableHeader'], true)
            && Kit::spanCount($attrs['colspan'] ?? null) > 1 && Kit::spanCount($attrs['rowspan'] ?? null) > 1
        ) {
            $this->ignored[] = ['path' => $path, 'type' => $type, 'attribute' => 'rowspan', 'value' => $attrs['rowspan']];
        }
        foreach (Kit::nodes($pm['marks'] ?? null) as $index => $mark) {
            $this->collectIgnored($mark, $path . '/marks/' . $index);
        }
        foreach (self::content($pm) as $index => $child) {
            $this->collectIgnored($child, $path . '/content/' . $index);
        }
    }

    /**
     * The attributes a node or mark reads: a declared mapping its own, a callback all of them.
     *
     * @param string $type
     * @param array<string, mixed> $attrs
     * @param string $path
     *
     * @return array<string>
     */
    private function reads(string $type, array $attrs, string $path): array
    {
        $host = $this->mapping[preg_match('~/marks/\d+$~', $path) === 1 ? 'marks' : 'nodes'][$type] ?? null;
        if ($host !== null) {
            return $host->attributes ?? array_map('strval', array_keys($attrs));
        }

        return self::READ[$type] ?? [];
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
     * `$content` is the content of the node at `$base`, from index `$offset` on.
     *
     * @param array<array<string, mixed>> $content
     * @param string $base
     * @param int $offset
     *
     * @return array<array<string, mixed>>
     */
    private function blocks(array $content, string $base, int $offset = 0): array
    {
        $out = [];
        foreach (array_values($content) as $index => $pm) {
            if (self::isPlaceholder($pm)) {
                continue;
            }
            $node = $this->block($pm, $base . '/content/' . ($index + $offset));
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
     * @param string $pointer
     *
     * @return array<string, mixed>|null
     */
    private function block(array $pm, string $pointer): ?array
    {
        $host = $this->mapping['nodes'][Kit::str($pm['type'] ?? null)] ?? null;
        $node = $host !== null ? $this->saveMapped($pm, $host, 'block', $pointer) : $this->blockOf($pm, $pointer);

        return $node === null ? null : self::tag($node, $pointer);
    }

    /**
     * @param array<string, mixed> $pm
     * @param string $pointer
     *
     * @throws \InvalidArgumentException
     *
     * @return array<string, mixed>|null
     */
    private function blockOf(array $pm, string $pointer): ?array
    {
        $type = Kit::str($pm['type'] ?? null);
        $carve = Kit::carveOf($pm);
        if (isset(self::textblocks()[$type])) {
            return $this->textblock($pm, $pointer);
        }
        if (isset(self::containers()[$type])) {
            return $this->container($pm, $pointer);
        }
        $code = array_search($type, Kit::CODE_BLOCKS, true);
        if ($code !== false) {
            return self::with(['type' => $code], Kit::treeFields($carve)) + ['value' => self::textOf($pm)];
        }

        return match ($type) {
            'carveCaptioned' => $this->captioned($pm, $pointer),
            'carveBlock' => Kit::map(Kit::clean(array_diff_key($carve, ['$index' => true]))),
            'codeBlock' => $this->codeBlock($pm),
            'horizontalRule' => self::with(['type' => 'thematic_break'], Kit::treeFields($carve)),
            'bulletList', 'orderedList', 'taskList' => $this->listNode($pm, $pointer),
            'carveDefinitionList' => $this->mixed($pm, 'definition_list', $pointer),
            'carveLineBlock' => $this->mixed($pm, 'line_block', $pointer),
            'table' => $this->table($pm, $pointer),
            // A block image, as Tiptap's Image extension makes by default, is an image paragraph (C2-A-136, C2-H-020).
            'image' => ['type' => 'paragraph', 'role' => 'image', 'children' => [self::tag(self::image($pm), $pointer)]],
            default => throw new InvalidArgumentException(
                sprintf('ProseMirrorToCarve: no Carve node for the ProseMirror node "%s"', $type),
            ),
        };
    }

    /**
     * The tree node of a host node.
     *
     * @param array<string, mixed> $pm
     * @param \MarkupCarve\Carve\ProseMirror\MappingEntry $entry
     * @param string $position `block` or `inline`
     * @param string $pointer
     *
     * @throws \InvalidArgumentException
     *
     * @return array<string, mixed>
     */
    private function saveMapped(array $pm, MappingEntry $entry, string $position, string $pointer): array
    {
        $type = Kit::str($pm['type'] ?? null);
        $content = self::content($pm);
        if ($entry->as === null) {
            $toTree = $entry->toTree;
            if ($toTree === null) {
                throw new InvalidArgumentException(
                    sprintf('ProseMirrorToCarve: the mapping of the ProseMirror node "%s" has no toTree', $type),
                );
            }

            return Kit::map($toTree($pm, [
                'position' => $position,
                'blocks' => fn (mixed $content): array => $this->blocks(Kit::nodes($content), $pointer),
                'inlines' => fn (mixed $content): array => $this->inlines(Kit::nodes($content), $pointer),
            ]));
        }
        $want = $position === 'block' ? 'div' : 'span';
        if ($entry->as !== $want) {
            throw new InvalidArgumentException(sprintf(
                'ProseMirrorToCarve: the ProseMirror node "%s" is mapped to a %s and cannot stand as %s',
                $type,
                $entry->as,
                $position === 'block' ? 'a block' : 'an inline',
            ));
        }
        if (!$entry->content && $content !== []) {
            throw new InvalidArgumentException(
                sprintf('ProseMirrorToCarve: the ProseMirror node "%s" has content; map it with content: true', $type),
            );
        }

        return [
            'type' => $want,
            'attrs' => Kit::mappedAttrs($entry, Kit::map($pm['attrs'] ?? null)),
            'children' => $position === 'block' ? $this->blocks($content, $pointer) : $this->inlines($content, $pointer),
        ];
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
     * @param string $pointer
     *
     * @return array<string, mixed>
     */
    private function mixed(array $pm, string $type, string $pointer): array
    {
        $node = self::with(['type' => $type], Kit::treeFields(Kit::carveOf($pm)));

        return $node + ['children' => $this->blocks(self::content($pm), $pointer)];
    }

    /**
     * @param array<string, mixed> $pm
     * @param string $pointer
     *
     * @return array<string, mixed>|null
     */
    private function captioned(array $pm, string $pointer): ?array
    {
        $content = self::content($pm);
        $node = isset($content[0]) ? $this->block($content[0], $pointer . '/content/0') : null;
        $caption = $content[1] ?? null;
        // An empty caption the editor made (to fill the wrapper) is no caption.
        $empty = $caption === null || (Kit::carveOf($caption)['$empty'] ?? null) !== true && !self::filled($caption);
        if ($node !== null && $caption !== null && !$empty) {
            $node['caption'] = $this->block($caption, $pointer . '/content/1');
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
        $text = self::textOf($pm);
        $node['value'] = $text . ((($carve['$newline'] ?? null) === false || str_ends_with($text, "\n")) ? '' : "\n");
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
     * @param string $pointer
     *
     * @return array<string, mixed>
     */
    private function textblock(array $pm, string $pointer): array
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
        $node['children'] = $this->inlines(self::content($pm), $pointer);

        return $node;
    }

    /**
     * @param array<string, mixed> $pm
     * @param string $pointer
     *
     * @return array<string, mixed>
     */
    private function container(array $pm, string $pointer): array
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
        $offset = 0;
        if (($content[0]['type'] ?? null) === 'carveTitle') {
            $title = $this->inlines(self::content($content[0]), $pointer . '/content/0');
            if ($title !== []) {
                $node['title'] = $title;
            }
            $content = array_slice($content, 1);
            $offset = 1;
        }
        $node['children'] = $this->blocks($content, $pointer, $offset);

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
     * @param string $pointer
     *
     * @return array<string, mixed>|null
     */
    private function listNode(array $pm, string $pointer): ?array
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
        $children = [];
        foreach ($items as $at => $item) {
            $children[] = self::tag($this->listItem($item, $pointer . '/content/' . $at), $pointer . '/content/' . $at);
        }
        $node['children'] = $children;

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
     * @param string $pointer
     *
     * @return array<string, mixed>
     */
    private function listItem(array $item, string $pointer): array
    {
        $itemFields = Kit::treeFields(Kit::carveOf($item));
        $child = self::with(['type' => 'list_item'], $itemFields);
        if (($item['type'] ?? null) === 'taskItem') {
            $checked = (Kit::map($item['attrs'] ?? null)['checked'] ?? null) === true;
            if (!array_key_exists('task', $itemFields) || self::taskChecked($itemFields['task']) !== $checked) {
                $child['task'] = ['mark' => $checked ? 'x' : ' '];
            }
        }
        $child['children'] = $this->blocks(self::content($item), $pointer);

        return $child;
    }

    /**
     * @param array<string, mixed> $pm
     * @param string $pointer
     *
     * @return array<string, mixed>
     */
    private function table(array $pm, string $pointer): array
    {
        $carve = Kit::carveOf($pm);
        $sections = Kit::nodes($carve['$sections'] ?? null);
        if ($sections === []) {
            $sections = [['type' => 'table_body']];
        }
        $pmRows = [];
        foreach (self::content($pm) as $at => $row) {
            $kept = array_filter(
                self::content($row),
                static fn (array $cell): bool => (Kit::carveOf($cell)['$pad'] ?? null) !== true || self::filled($cell),
            );
            $pmRows[] = [
                'carve' => Kit::carveOf($row),
                'cells' => array_values($kept),
                'pointer' => $pointer . '/content/' . $at,
                'cellPointers' => array_map(
                    static fn (int $index): string => $pointer . '/content/' . $at . '/content/' . $index,
                    array_keys($kept),
                ),
            ];
        }
        $lead = self::leadingHead($sections, $pmRows);
        $shift = 0;
        if ($lead > 0) {
            $shift = max(self::find($sections, static fn (array $s, int $i): bool => !array_key_exists('$node', $s)), 0);
            array_splice($sections, $shift, 0, [['type' => 'table_head']]);
        }
        // A body row added after a head that has no body behind it needs one; loaded rows keep their sections.
        $head = self::find($sections, static fn (array $s, int $i): bool => ($s['type'] ?? null) === 'table_head');
        $added = -1;
        if ($head >= 0 && self::bodyAfter($sections, $head) < 0) {
            array_splice($sections, $head + 1, 0, [['type' => 'table_body']]);
            $added = $head + 1;
        }
        $placed = self::place($pmRows, $sections, $lead, $lead > 0 ? $shift : -1, $added, $head);
        $rowsOf = array_fill(0, count($sections), []);
        $width = $this->spanRows($placed, ($carve['$bareSpans'] ?? null) === true, $rowsOf);
        $node = self::with(['type' => 'table'], Kit::treeFields($carve));
        $node['children'] = self::sections($sections, $rowsOf);
        $columns = is_array($node['columns'] ?? null) ? array_slice(array_values($node['columns']), 0, $width) : [];
        $node['columns'] = array_pad($columns, $width, ['type' => 'table_column']);

        return $node;
    }

    /**
     * C2-B-178: in a table without a head, the number of leading rows of header cells, which are the head. A row loaded
     * so from a body stays there.
     *
     * @param array<array<string, mixed>> $sections
     * @param array<array{
     *     carve: array<string, mixed>,
     *     cells: array<array<string, mixed>>,
     *     pointer: string,
     *     cellPointers: array<string>,
     * }> $rows
     */
    private static function leadingHead(array $sections, array $rows): int
    {
        if (self::find($sections, static fn (array $s, int $i): bool => ($s['type'] ?? null) === 'table_head') >= 0) {
            return 0;
        }
        $lead = 0;
        foreach ($rows as $row) {
            if (($row['carve']['$th'] ?? null) === true || !self::headerRow($row['cells'])) {
                break;
            }
            $lead++;
        }

        return $lead;
    }

    /**
     * The rows with the section each goes to: a loaded row its own, a new row that of the row before it.
     *
     * @param array<array{
     *     carve: array<string, mixed>,
     *     cells: array<array<string, mixed>>,
     *     pointer: string,
     *     cellPointers: array<string>,
     * }> $rows
     * @param array<array<string, mixed>> $sections
     * @param int $lead The rows that go to the head at `$head`.
     * @param int $shift Where that head was inserted, or -1.
     * @param int $added Where a body was inserted, or -1.
     * @param int $head
     *
     * @return array<array{
     *     carve: array<string, mixed>,
     *     cells: array<array<string, mixed>>,
     *     pointer: string,
     *     cellPointers: array<string>,
     *     section: int,
     * }>
     */
    private static function place(array $rows, array $sections, int $lead, int $shift, int $added, int $head): array
    {
        $current = self::find($sections, static fn (array $s, int $i): bool => ($s['type'] ?? null) === 'table_body');
        if ($current < 0) {
            $current = self::find($sections, static fn (array $s, int $i): bool => !array_key_exists('$node', $s));
        }
        $placed = [];
        foreach ($rows as $index => $row) {
            $from = $row['carve']['$section'] ?? null;
            if (is_int($from)) {
                $from = $shift >= 0 && $from >= $shift ? $from + 1 : $from;
                $from = $added >= 0 && $from >= $added ? $from + 1 : $from;
            }
            if ($index < $lead) {
                $current = $head;
            } elseif (is_int($from) && isset($sections[$from])) {
                $current = $from;
            } elseif (($sections[$current]['type'] ?? null) === 'table_head' && !self::headerRow($row['cells'])) {
                $current = self::bodyAfter($sections, $current);
            }
            $placed[] = $row + ['section' => $current];
        }

        return $placed;
    }

    /**
     * @param array<array<string, mixed>> $cells
     */
    private static function headerRow(array $cells): bool
    {
        return $cells !== [] && array_filter($cells, static fn (array $c): bool => ($c['type'] ?? null) !== 'tableHeader') === [];
    }

    /**
     * Steps `$column` past the columns a row span from above covers, with a `^` mark cell for each.
     *
     * @param array<int, int> $below
     * @param int $column
     * @param array<array<string, mixed>> $cells
     * @param bool $bare
     */
    private static function covered(array $below, int &$column, array &$cells, bool $bare): void
    {
        while (($below[$column] ?? 0) > 0) {
            if (!$bare) {
                $cells[] = ['type' => 'table_cell', 'header' => false, 'span' => 'rowspan', 'children' => []];
            }
            $column++;
        }
    }

    /**
     * Saves the rows into `$rowsOf`; returns the width of the widest row. A span becomes the cell's counts and the
     * mark cells it covers (C2-B-172 to C2-B-174): a row span stops at the end of its group, and a cell that spans both
     * ways keeps its columns, since a `^` below a cell of more than one column joins nothing.
     *
     * @param array<array{
     *     carve: array<string, mixed>,
     *     cells: array<array<string, mixed>>,
     *     pointer: string,
     *     cellPointers: array<string>,
     *     section: int,
     * }> $placed
     * @param bool $bare
     * @param array<int, array<array<string, mixed>>> $rowsOf
     */
    private function spanRows(array $placed, bool $bare, array &$rowsOf): int
    {
        $below = [];
        $width = 0;
        $count = count($placed);
        foreach ($placed as $index => $row) {
            if ($index > 0 && $placed[$index - 1]['section'] !== $row['section']) {
                $below = [];
            }
            $left = 0;
            while ($index + $left < $count && $placed[$index + $left]['section'] === $row['section']) {
                $left++;
            }
            $cells = [];
            $column = 0;
            foreach ($row['cells'] as $at => $pmCell) {
                self::covered($below, $column, $cells, $bare);
                $cell = $this->cell($pmCell, $row['cellPointers'][$at]);
                $attrs = Kit::map($pmCell['attrs'] ?? null);
                $colspan = Kit::spanCount($attrs['colspan'] ?? null);
                $rowspan = min(Kit::spanCount($attrs['rowspan'] ?? null), $left);
                if ($colspan > 1 && $rowspan > 1 && !$bare) {
                    $rowspan = 1;
                }
                if ($colspan > 1) {
                    $cell['colspan'] = $colspan;
                }
                if ($rowspan > 1) {
                    $cell['rowspan'] = $rowspan;
                }
                $cells[] = $cell;
                for ($offset = 1; $offset < $colspan && !$bare; $offset++) {
                    $cells[] = ['type' => 'table_cell', 'header' => false, 'span' => 'colspan', 'children' => []];
                }
                for ($offset = 0; $offset < $colspan && $rowspan > 1; $offset++) {
                    $below[$column + $offset] = $rowspan;
                }
                $column += $colspan;
            }
            self::covered($below, $column, $cells, $bare);
            $width = max($width, $column);
            $below = array_map(static fn (int $n): int => max(0, $n - 1), $below);
            foreach (Kit::nodes($row['carve']['$before'] ?? null) as $comment) {
                $rowsOf[$row['section']][] = $comment;
            }
            $tableRow = self::with(['type' => 'table_row'], Kit::treeFields($row['carve']));
            $rowsOf[$row['section']][] = $tableRow + ['children' => $cells, '$pointer' => $row['pointer']];
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
     * @param string $pointer
     *
     * @return array<string, mixed>
     */
    private function cell(array $pm, string $pointer): array
    {
        $content = self::content($pm);
        $single = count(array_filter($content, static fn (array $c): bool => !self::isPlaceholder($c))) === 1;
        $children = [];
        foreach ($content as $at => $child) {
            if (self::isPlaceholder($child)) {
                continue;
            }
            $node = $this->block($child, $pointer . '/content/' . $at);
            if ($node === null) {
                continue;
            }
            $inline = in_array($child['type'] ?? null, ['paragraph', 'image'], true);
            if ($inline && !array_key_exists('$type', Kit::carveOf($child)) && $single) {
                $node['type'] = 'cell_paragraph';
                unset($node['role']);
            }
            $children[] = $node;
        }
        // A cell the editor created empty still needs content; an empty cell reads as one empty cell paragraph.
        if ($children === [] && (Kit::carveOf($pm)['$empty'] ?? null) !== true) {
            $children[] = ['type' => 'cell_paragraph', 'children' => []];
        }
        $fields = array_diff_key(Kit::treeFields(Kit::carveOf($pm)), ['colspan' => true, 'rowspan' => true]);
        $node = self::with(['type' => 'table_cell'], $fields);
        $node['header'] = ($pm['type'] ?? null) === 'tableHeader';
        $node['children'] = $children;
        $node['$pointer'] = $pointer;

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
     * @param string $base
     *
     * @return array<array<string, mixed>>
     */
    private function inlines(array $content, string $base): array
    {
        $leaves = [];
        foreach (array_values($content) as $at => $pm) {
            $pointer = $base . '/content/' . $at;
            $kept = array_filter(
                Kit::nodes($pm['marks'] ?? null),
                static fn (array $mark): bool => ($mark['type'] ?? null) !== 'code',
            );
            $marks = array_values($kept);
            $leaves[] = [
                'pm' => $pm,
                'pointer' => $pointer,
                'marks' => $marks,
                'markPointers' => array_map(static fn (int $index): string => $pointer . '/marks/' . $index, array_keys($kept)),
                'keys' => array_map(self::markKey(...), $marks),
            ];
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
                $nodes[] = self::tag($this->markNode($entry['mark']), $leaf['markPointers'][$entry['order']]);
                $kids[$stack === [] ? -1 : $stack[count($stack) - 1]['id']][] = $id;
                $kids[$id] = [];
                $stack[] = ['key' => $entry['key'], 'id' => $id];
            }
            $id = count($nodes);
            $host = $this->mapping['nodes'][Kit::str($leaf['pm']['type'] ?? null)] ?? null;
            $node = $host !== null ? $this->saveMapped($leaf['pm'], $host, 'inline', $leaf['pointer']) : $this->leaf($leaf['pm']);
            $nodes[] = self::tag($node, $leaf['pointer']);
            $kids[$stack === [] ? -1 : $stack[count($stack) - 1]['id']][] = $id;
        }

        return self::assemble($nodes, $kids, -1);
    }

    /**
     * The marks of `$leaf` not open yet, in the order they open: the longest run first, then the loaded depth.
     *
     * @param array{
     *     pm: array<string, mixed>,
     *     pointer: string,
     *     marks: array<array<string, mixed>>,
     *     markPointers: array<string>,
     *     keys: array<string>,
     * } $leaf
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
        $host = $this->mapping['marks'][$type] ?? null;
        if ($host !== null) {
            return $this->saveMappedMark($mark, $host);
        }
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
     * @param array<string, mixed> $mark
     * @param \MarkupCarve\Carve\ProseMirror\MappingEntry $entry
     *
     * @throws \InvalidArgumentException
     *
     * @return array<string, mixed>
     */
    private function saveMappedMark(array $mark, MappingEntry $entry): array
    {
        if ($entry->as !== null) {
            return ['type' => 'span', 'attrs' => Kit::mappedAttrs($entry, Kit::map($mark['attrs'] ?? null)), 'children' => []];
        }
        $toTree = $entry->toTree;
        if ($toTree === null) {
            throw new InvalidArgumentException(sprintf(
                'ProseMirrorToCarve: the mapping of the ProseMirror mark "%s" has no toTree',
                Kit::str($mark['type'] ?? null),
            ));
        }

        return array_merge(Kit::map($toTree($mark)), ['children' => []]);
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
            'carveSoftBreak' => self::with(['type' => 'soft_break'], Kit::treeFields($carve)),
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
