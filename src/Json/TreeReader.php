<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Json;

use JsonException;
use MarkupCarve\Carve\Extension\ExtensionDefinition;
use MarkupCarve\Carve\Generated\Tables;
use MarkupCarve\Carve\Node;
use stdClass;

final class TreeReader
{
    /**
     * C2-A-171: three JSON levels per tree level at most (object, slot list, a wrapper such as `caption`), for the document and
     * every file of the longest include chain.
     *
     * @var int
     */
    public const JSON_DEPTH = 3 * (Tables::BLOCK_DEPTH + Tables::INLINE_DEPTH + 8) * (Tables::INCLUDE_DEPTH + 1);

    /**
     * C2-B-190: the containers that count one level each.
     *
     * @var array<string, true>
     */
    public const CONTAINERS = TreeDepth::CONTAINERS;

    /**
     * @var array<string, true> C2-A-124 with the common fields of C2-A-027.
     */
    private const UNQUALIFIED_FIELDS = [
        'type' => true,
        'name' => true,
        'children' => true,
        'attrs' => true,
        'data' => true,
        'pos' => true,
        'segments' => true,
        'metadata' => true,
        'dir' => true,
        'lang' => true,
    ];

    /**
     * C2-I-118: every inline node with children counts one level of its inline content.
     *
     * @var array<string, true>
     */
    public const INLINE = TreeDepth::INLINE;

    /**
     * The nodes whose `children` hold blocks (C2-A-221).
     *
     * @var array<string, true>
     */
    private const BLOCK_CHILDREN = [
        'document' => true,
        'include' => true,
        'admonition' => true,
        'block_extension' => true,
        'block_quote' => true,
        'caption' => true,
        'definition_description' => true,
        'directive' => true,
        'div' => true,
        'figure_group' => true,
        'footnote' => true,
        'list_item' => true,
        'section' => true,
        'table_cell' => true,
    ];

    /**
     * Node fields whose values are not walked for child nodes.
     *
     * @var array<string, true>
     */
    public const OPAQUE_FIELDS = TreeDepth::OPAQUE_FIELDS;

    private static ?stdClass $schema = null;

    /**
     * @param string $json
     * @param array<string, mixed> $options
     */
    public static function read(string $json, array $options = []): Node
    {
        try {
            $value = json_decode($json, false, self::JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new TreeError($e->getCode() === JSON_ERROR_DEPTH ? 'depth' : 'schema', '', $e);
        }
        TreeNumbers::check($json);

        // Decoded JSON holds no invalid UTF-8, and TreeNumbers refused every number binary64 does not keep, so the tree is
        // built from it directly.
        $root = self::checked($value, $options);
        unset($value);
        $node = TreeDecoder::consume($root);
        $node->layout['jsonByteLength'] = strlen($json);

        return $node;
    }

    /**
     * `read()` of the JSON of a tree with no source. The positions TreeDecoder packed skip the JSON and move to the
     * copy, so a large tree and its decoded JSON are not both held; any tree that does not pair up is read in full.
     *
     * @param \MarkupCarve\Carve\Node $tree
     * @param array<string, mixed> $options
     */
    public static function readNode(Node $tree, array $options = []): Node
    {
        if (!array_key_exists('annotations', $options)) {
            [$json, $length] = TreeEncoder::jsonWithoutPackedPositions($tree);
            if ($length !== strlen($json)) {
                $node = self::read($json, $options);
                unset($json);
                if (TreeDecoder::movePackedPositions($tree, $node)) {
                    $node->layout['jsonByteLength'] = $length;

                    return $node;
                }
                unset($node);
            }
        }

        return self::read(TreeEncoder::json($tree, null), $options);
    }

    /**
     * @param mixed $decoded
     * @param array<string, mixed> $options
     *
     * @throws \MarkupCarve\Carve\Json\TreeError
     */
    public static function readValue(mixed $decoded, array $options = []): Node
    {
        $root = self::checked($decoded, $options);
        try {
            $json = json_encode($root, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR, self::JSON_DEPTH);
        } catch (JsonException $e) {
            throw new TreeError($e->getCode() === JSON_ERROR_INF_OR_NAN ? 'number' : 'schema', '', $e);
        }
        TreeNumbers::check($json);
        $node = TreeDecoder::json($json, self::JSON_DEPTH);
        if ($node === null) {
            throw new TreeError('schema', '');
        }

        return $node;
    }

    /**
     * Whether a tree in array form is deeper than `read()` takes (C2-A-171), counted as `read()` counts, without
     * encoding or decoding it.
     *
     * @param array<string, mixed> $tree
     */
    public static function exceedsDepth(array $tree): bool
    {
        return TreeDepth::exceeds($tree);
    }

    /**
     * Runs every check of `read()` without building the tree.
     *
     * @param string $json
     * @param array<string, mixed> $options
     *
     * @throws \MarkupCarve\Carve\Json\TreeError
     */
    public static function check(string $json, array $options = []): void
    {
        try {
            $value = json_decode($json, false, self::JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new TreeError($e->getCode() === JSON_ERROR_DEPTH ? 'depth' : 'schema', '', $e);
        }
        TreeNumbers::check($json);
        self::checked($value, $options);
    }

    /**
     * `check(TreeEncoder::json($tree, null))` without the canonical spelling, which `json_decode` does not see: key order
     * and string escapes. A float (whose spelling can decide between an integer and a float), an object or invalid
     * UTF-8 takes the canonical path.
     *
     * @param \MarkupCarve\Carve\Node $tree
     * @param array<string, mixed> $options
     */
    public static function checkTreeOf(Node $tree, array $options = []): void
    {
        $encoded = (new TreeEncoder())->encode($tree, null);
        $plain = true;
        array_walk_recursive($encoded, static function (mixed $value) use (&$plain): void {
            if (is_float($value) || is_object($value)) {
                $plain = false;
            }
        });
        $json = $plain ? json_encode($encoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : false;
        unset($encoded);
        self::check($json === false ? TreeEncoder::json($tree, null) : $json, $options);
    }

    /**
     * @param mixed $decoded
     * @param array<string, mixed> $options
     *
     * @throws \MarkupCarve\Carve\Json\TreeError
     *
     * @return \stdClass The document node.
     */
    private static function checked(mixed $decoded, array $options): stdClass
    {
        $schema = self::schema();
        $validator = new SchemaValidator($schema);
        if (!$validator->valid($decoded, $schema)) {
            throw new TreeError('schema', self::schemaPointer($validator->errors[0] ?? '$'));
        }
        if (!$decoded instanceof stdClass) {
            throw new TreeError('schema', '');
        }
        $envelope = isset($decoded->document);
        $root = $envelope ? $decoded->document : $decoded;
        if (!$root instanceof stdClass) {
            throw new TreeError('schema', '');
        }
        if ($envelope && $decoded->astVersion !== $root->astVersion) {
            throw new TreeError('envelope-version', '/document/astVersion');
        }
        $declared = self::declarations($root, $envelope ? $decoded : null, $options);
        $standardTabs = isset($declared['carve.tabs']);
        $extensions = $options['extensions'] ?? [];
        foreach (is_array($extensions) ? $extensions : [] as $extension) {
            if ($extension === 'carve.tabs' || ($extension instanceof ExtensionDefinition && $extension->name === 'carve.tabs')) {
                $standardTabs = true;

                break;
            }
        }
        self::checkTree($root, $declared, $envelope ? '/document' : '', $standardTabs);
        if (array_key_exists('annotations', $options)) {
            AnnotationRanges::check($root, $options['annotations'], $declared);
        }
        if ($envelope) {
            self::liftDeclarations($root, $decoded);
        }

        return $root;
    }

    /**
     * C2-A-013 (carve#6888): the envelope declarations the root lacks go to the end of the root `extensions`, so the
     * decoded tree is valid without its envelope.
     *
     * @param \stdClass $root
     * @param \stdClass $envelope
     */
    private static function liftDeclarations(stdClass $root, stdClass $envelope): void
    {
        $own = [];
        foreach ($root->extensions ?? [] as $item) {
            $own[$item->name] = true;
        }
        $lifted = $root->extensions ?? [];
        foreach ($envelope->extensions ?? [] as $item) {
            if (!isset($own[$item->name])) {
                $lifted[] = $item;
            }
        }
        if (count($lifted) !== count($root->extensions ?? [])) {
            $root->extensions = $lifted;
        }
    }

    /**
     * C2-A-017: the root and envelope declarations of one name agree in version, required flag and ordered slots.
     *
     * @param \stdClass $one
     * @param \stdClass $other
     */
    private static function sameDeclaration(stdClass $one, stdClass $other): bool
    {
        return $one->version === $other->version && $one->required === $other->required && $one->slots === $other->slots;
    }

    public static function schema(): stdClass
    {
        if (self::$schema === null) {
            $path = dirname(__DIR__, 2) . '/spec/data/ast-schema.json';
            $schema = json_decode((string)file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
            if (!$schema instanceof stdClass) {
                throw new TreeError('schema', '');
            }
            self::$schema = $schema;
        }

        return self::$schema;
    }

    private static function schemaPointer(string $error): string
    {
        $path = explode(': ', $error, 2)[0];
        $path = preg_replace_callback('/\[([0-9]+)\]|\.([^\.\[]+)/', static function (array $match): string {
            return '/' . str_replace(['~', '/'], ['~0', '~1'], $match[1] !== '' ? $match[1] : $match[2]);
        }, $path);

        return substr((string)$path, 1);
    }

    /**
     * @param \stdClass $root
     * @param \stdClass|null $envelope
     * @param array<string, mixed> $options
     *
     * @throws \MarkupCarve\Carve\Json\TreeError
     *
     * @return array<string, \stdClass>
     */
    private static function declarations(stdClass $root, ?stdClass $envelope, array $options): array
    {
        $all = [];
        foreach (['root' => $root, 'envelope' => $envelope] as $place => $holder) {
            if ($holder === null) {
                continue;
            }
            $seen = [];
            foreach ($holder->extensions ?? [] as $i => $item) {
                $path = ($place === 'root' ? ($envelope === null ? '' : '/document') : '') . '/extensions/' . $i;
                if (isset($seen[$item->name])) {
                    throw new TreeError('duplicate-extension-declaration', $path . '/name');
                }
                $seen[$item->name] = true;
                if (isset($all[$item->name]) && !self::sameDeclaration($all[$item->name], $item)) {
                    throw new TreeError('extension-declaration', $path);
                }
                $all[$item->name] = $item;
                $implementations = $options['extensions'] ?? [];
                $implemented = is_array($implementations) ? ($implementations[$item->name] ?? null) : null;
                if ($item->required && $implemented !== $item->version) {
                    throw new TreeError('required-extension', $path);
                }
            }
        }

        return $all;
    }

    /**
     * @param \stdClass $root
     * @param array<string, \stdClass> $declared
     * @param string $prefix
     * @param bool $standardTabs
     *
     * @throws \MarkupCarve\Carve\Json\TreeError
     */
    private static function checkTree(stdClass $root, array $declared, string $prefix, bool $standardTabs): void
    {
        // The stack as parallel lists, with four counters per node: one small array per pending node held about
        // 300 bytes for each child of a long paragraph (carve-php#5722).
        $nodes = [$root];
        $paths = [$prefix];
        $counters = [0, 0, 0, 0];
        $annotationIds = [];
        $acceptedTabs = [];
        $resourceReferences = 0;
        while ($nodes !== []) {
            [$node, $path, $blocks, $inlines, $qualified, $unqualified] = self::popFrame($nodes, $paths, $counters);
            self::checkNode($node, $path, $declared);
            self::checkBounds($node, $path, $resourceReferences);
            self::checkAnnotationIds($node, $path, $annotationIds);
            $type = is_string($node->type ?? null) ? $node->type : '';
            if ($standardTabs) {
                self::checkStandardTabs($node, $path, $acceptedTabs);
            }
            if ($type === 'inline_extension' || $type === 'block_extension') {
                if (str_contains($node->name ?? '', '.')) {
                    $qualified++;
                    $unqualified = 0;
                } else {
                    $unqualified++;
                }
                if ($qualified > 32 || $unqualified > 32) {
                    throw new TreeError('extension-depth', $path);
                }
            } else {
                $unqualified = 0;
            }
            if (isset(self::INLINE[$type])) {
                $inlines += isset($node->children) ? 1 : 0;
            } else {
                $inlines = 0;
                $blocks += isset(self::CONTAINERS[$type]) ? 1 : 0;
            }
            if ($blocks > Tables::BLOCK_DEPTH || $inlines > Tables::INLINE_DEPTH) {
                throw new TreeError('depth', $path);
            }
            if ($type === 'include') {
                // C2-A-171: the children of an expanded include start again at level 0.
                $blocks = 0;
            }
            self::pushNodes($node, $path, [$blocks, $inlines, $qualified, $unqualified], $nodes, $paths, $counters);
        }
    }

    /**
     * C2-A-132: bounds that accumulate across nodes or inspect an extension's opaque values.
     *
     * @param \stdClass $node
     * @param string $path
     * @param int $resourceReferences
     *
     * @throws \MarkupCarve\Carve\Json\TreeError
     */
    private static function checkBounds(stdClass $node, string $path, int &$resourceReferences): void
    {
        if (isset($node->metadata->fields)) {
            self::checkMetadata($node->metadata->fields, $path . '/metadata/fields');
        }
        if (isset($node->resourceIds)) {
            $resourceReferences += count($node->resourceIds);
            if ($resourceReferences > 4096) {
                throw new TreeError('more than 4096 resource references', $path);
            }
        }
        $payloadBytes = 0;
        foreach ($node->payload ?? [] as $item) {
            $payload = $item->value;
            $payloadBytes += is_string($payload)
                ? strlen($payload)
                : strlen((string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            if ($payloadBytes > 8388608) {
                throw new TreeError('extension-payload-bytes', $path . '/payload');
            }
        }
    }

    /**
     * C2-A-132 and C2-A-212: count nested metadata values, including each list or map member.
     *
     * @param \stdClass $fields
     * @param string $path
     *
     * @throws \MarkupCarve\Carve\Json\TreeError
     */
    private static function checkMetadata(stdClass $fields, string $path): void
    {
        $stack = [];
        foreach (get_object_vars($fields) as $name => $value) {
            $stack[] = [$value, $path . '/' . self::escape((string)$name), 1];
        }
        while ($stack !== []) {
            [$value, $valuePath, $depth] = array_pop($stack);
            if (!$value instanceof stdClass) {
                continue;
            }
            if ($depth > 64) {
                throw new TreeError('metadata-depth', $valuePath);
            }
            if ($value->kind === 'list') {
                foreach ($value->value as $i => $child) {
                    $stack[] = [$child, $valuePath . '/value/' . $i, $depth + 1];
                }
            } elseif ($value->kind === 'map') {
                foreach (get_object_vars($value->value) as $name => $child) {
                    $stack[] = [$child, $valuePath . '/value/' . self::escape((string)$name), $depth + 1];
                }
            }
        }
    }

    /**
     * The last pending node of `checkTree()` with its path and counters. The lists move in step, so the fallbacks are
     * never taken.
     *
     * @param-out array<\stdClass> $nodes
     *
     * @param non-empty-array<\stdClass> $nodes
     * @param array<string> $paths
     * @param array<int> $counters
     *
     * @return array{\stdClass, string, int, int, int, int}
     */
    private static function popFrame(array &$nodes, array &$paths, array &$counters): array
    {
        $unqualified = array_pop($counters) ?? 0;
        $qualified = array_pop($counters) ?? 0;
        $inlines = array_pop($counters) ?? 0;

        return [array_pop($nodes), array_pop($paths) ?? '', array_pop($counters) ?? 0, $inlines, $qualified, $unqualified];
    }

    /**
     * @param \stdClass $node
     * @param string $path
     * @param array<int, true> $acceptedTabs
     *
     * @throws \MarkupCarve\Carve\Json\TreeError
     */
    private static function checkStandardTabs(stdClass $node, string $path, array &$acceptedTabs): void
    {
        if (($node->type ?? null) !== 'block_extension') {
            return;
        }
        if (($node->name ?? null) === 'carve.tabs') {
            foreach ($node->slots->tabs ?? [] as $at => $tab) {
                $tabPath = $path . '/slots/tabs/' . $at;
                if (
                    !$tab instanceof stdClass || ($tab->type ?? null) !== 'block_extension'
                    || ($tab->name ?? null) !== 'carve.tab' || !isset($tab->slots->body)
                ) {
                    throw new TreeError('standard-tabs', $tabPath);
                }
                $acceptedTabs[spl_object_id($tab)] = true;
            }
        } elseif (($node->name ?? null) === 'carve.tab' && !isset($acceptedTabs[spl_object_id($node)])) {
            throw new TreeError('standard-tabs', $path);
        }
    }

    /**
     * @param \stdClass $node
     * @param string $path
     * @param array<true> $seen
     *
     * @throws \MarkupCarve\Carve\Json\TreeError
     */
    private static function checkAnnotationIds(stdClass $node, string $path, array &$seen): void
    {
        foreach ($node->data->{'carve.annotation'}->ids ?? [] as $id) {
            if (isset($seen[$id])) {
                throw new TreeError('annotation-id', $path . '/data/carve.annotation');
            }
            $seen[$id] = true;
        }
    }

    /**
     * @param \stdClass $node
     * @param string $path
     * @param array<string, \stdClass> $declared
     *
     * @throws \MarkupCarve\Carve\Json\TreeError
     */
    private static function checkNode(stdClass $node, string $path, array $declared): void
    {
        $seen = [];
        foreach ($node->attrs->keyValues ?? [] as $i => $pair) {
            $key = strtolower($pair[0]);
            if ($node->type === 'span' && $pair[0] === $key && in_array($key, ['abbr', 'time', 'kbd'], true)) {
                $key = 'semantic:' . $key;
            }
            if (isset($seen[$key])) {
                throw new TreeError('duplicate-attribute-key', $path . '/attrs/keyValues/' . $i . '/0');
            }
            $seen[$key] = true;
        }
        foreach (get_object_vars($node->data ?? new stdClass()) as $key => $value) {
            $keyPath = $path . '/data/' . self::escape($key);
            if (!isset($declared[$key])) {
                throw new TreeError('data-undeclared', $keyPath);
            }
            self::opaque($value, $keyPath, 'data-holds-node');
        }
        if (isset($node->props)) {
            self::opaque($node->props, $path . '/props', 'props-holds-node');
        }
        self::typeChecks($node, $path, $declared);
    }

    /**
     * @param \stdClass $node
     * @param string $path
     * @param array<string, \stdClass> $declared
     *
     * @throws \MarkupCarve\Carve\Json\TreeError
     */
    private static function typeChecks(stdClass $node, string $path, array $declared): void
    {
        $type = $node->type ?? '';
        if ($type === 'include') {
            self::ranges($node, $path);
        } elseif ($type === 'table') {
            self::groupOrder($node, $path);
        } elseif (in_array($type, ['table_head', 'table_body', 'table_foot'], true)) {
            self::rowspans($node, $path);
        } elseif ($type === 'autolink' && isset($node->text) && $node->text === ($node->href ?? null)) {
            // C2-A-058: a default has one encoding.
            throw new TreeError('autolink-default-text', $path . '/text');
        } elseif ($type === 'smart_punctuation') {
            self::smartPunctuation($node, $path);
        } elseif ($type === 'table_cell') {
            self::cellChildren($node, $path);
        } elseif ($type === 'snippet_definition') {
            self::snippetDefinition($node, $path);
        }
        self::nodeListModes($node, $path, $type);
        if (in_array($node->type ?? '', ['block_extension', 'inline_extension'], true)) {
            self::extension($node, $path, $declared);
        }
    }

    /**
     * C2-A-220: a snippet definition holds no id, and its value is never empty.
     *
     * @param \stdClass $node
     * @param string $path
     *
     * @throws \MarkupCarve\Carve\Json\TreeError
     */
    private static function snippetDefinition(stdClass $node, string $path): void
    {
        if (isset($node->attrs) && $node->attrs instanceof stdClass && property_exists($node->attrs, 'id')) {
            throw new TreeError('snippet-definition-id', $path . '/attrs/id');
        }
        if (isset($node->children) && $node->children === []) {
            throw new TreeError('snippet-definition-empty', $path . '/children');
        }
    }

    /**
     * C2-A-221: the snippet uses of every node list of `$node`.
     *
     * @param \stdClass $node
     * @param string $path
     * @param string $type
     */
    private static function nodeListModes(stdClass $node, string $path, string $type): void
    {
        foreach (get_object_vars($node) as $key => $value) {
            if (is_array($value) && !isset(self::OPAQUE_FIELDS[$key])) {
                self::useModes($value, $path . '/' . self::escape($key), self::holdsBlocks($type, $key));
            }
        }
        // An inline extension's slots hold inline nodes; a block extension's slots follow its declaration.
        if ($type === 'inline_extension' && isset($node->slots) && $node->slots instanceof stdClass) {
            foreach (get_object_vars($node->slots) as $name => $slot) {
                if (is_array($slot)) {
                    self::useModes($slot, $path . '/slots/' . self::escape((string)$name), false);
                }
            }
        }
    }

    /**
     * Whether the list `$key` of a node of `$type` holds blocks; every other node list holds inline nodes.
     */
    private static function holdsBlocks(string $type, string $key): bool
    {
        return match ($key) {
            'children' => isset(self::BLOCK_CHILDREN[$type]),
            'longDescription' => true,
            'fallback' => $type === 'block_extension',
            default => false,
        };
    }

    /**
     * C2-A-221: the mode of a snippet use matches the list that holds it.
     *
     * @param array<mixed> $list
     * @param string $path
     * @param bool $blocks
     *
     * @throws \MarkupCarve\Carve\Json\TreeError
     */
    private static function useModes(array $list, string $path, bool $blocks): void
    {
        foreach ($list as $i => $child) {
            $mode = $child instanceof stdClass && ($child->type ?? null) === 'snippet_use' ? $child->mode ?? null : false;
            if ($mode !== false && $mode !== ($blocks ? 'block' : 'inline')) {
                throw new TreeError('snippet-use-mode-mismatch', $path . '/' . $i . '/mode');
            }
        }
    }

    private static function rowspans(stdClass $group, string $path): void
    {
        $rows = $group->children ?? [];
        $count = count($rows);
        foreach ($rows as $r => $row) {
            foreach ($row->children ?? [] as $c => $cell) {
                if (($cell->rowspan ?? 1) > $count - $r) {
                    throw new TreeError('table-rowspan-group', $path . '/children/' . $r . '/children/' . $c);
                }
            }
        }
    }

    /**
     * C2-A-098: the glyph and the source the table gives the kind.
     *
     * @param \stdClass $node
     * @param string $path
     *
     * @throws \MarkupCarve\Carve\Json\TreeError
     */
    private static function smartPunctuation(stdClass $node, string $path): void
    {
        $kind = $node->kind ?? '';
        if (isset($node->glyph) && $node->glyph !== self::glyphOf($kind)) {
            throw new TreeError('smart-punctuation-glyph', $path . '/glyph');
        }
        if (is_string($kind) && isset($node->source) && $node->source !== (Tables::SMART_SOURCES[$kind] ?? null)) {
            throw new TreeError('smart-punctuation-source', $path . '/source');
        }
    }

    /**
     * C2-A-091: a cell has children, and a `cell_paragraph` is the only child of its cell; a span mark cell stays empty
     * (C2-A-092).
     *
     * @param \stdClass $node
     * @param string $path
     *
     * @throws \MarkupCarve\Carve\Json\TreeError
     */
    private static function cellChildren(stdClass $node, string $path): void
    {
        $children = is_array($node->children ?? null) ? $node->children : [];
        if ($children === [] && !isset($node->span)) {
            throw new TreeError('table-cell-children', $path . '/children');
        }
        if (count($children) === 1) {
            return;
        }
        foreach ($children as $i => $child) {
            if ($child instanceof stdClass && ($child->type ?? null) === 'cell_paragraph') {
                throw new TreeError('table-cell-children', $path . '/children/' . $i);
            }
        }
    }

    /**
     * C2-A-086: at most one head, then the bodies, then at most one foot; comments come after them.
     *
     * @param \stdClass $node
     * @param string $path
     *
     * @throws \MarkupCarve\Carve\Json\TreeError
     */
    private static function groupOrder(stdClass $node, string $path): void
    {
        $rank = ['table_head' => 0, 'table_body' => 1, 'table_foot' => 2, 'comment' => 3];
        $last = -1;
        foreach ($node->children ?? [] as $i => $child) {
            $r = $rank[$child->type ?? ''] ?? 3;
            if ($r < $last || ($r === $last && $r !== 1 && $r !== 3)) {
                throw new TreeError('table-group-order', $path . '/children/' . $i);
            }
            $last = $r;
        }
    }

    /**
     * C2-A-098: the glyph `data/smart-punctuation.json` gives a kind.
     *
     * @param mixed $kind
     *
     * @return string|null
     */
    private static function glyphOf(mixed $kind): ?string
    {
        if (!is_string($kind)) {
            return null;
        }
        if (isset(Tables::SMART_GLYPHS[$kind])) {
            return Tables::SMART_GLYPHS[$kind];
        }
        foreach (Tables::SMART_RUNS as [$runKind, $glyph]) {
            if ($runKind === $kind) {
                return $glyph;
            }
        }

        return null;
    }

    private static function ranges(stdClass $node, string $path): void
    {
        foreach (['lines', 'rows', 'cols'] as $key) {
            $range = $node->options->{$key} ?? null;
            if (!is_string($range)) {
                continue;
            }
            if (preg_match('/^([0-9]+)-([0-9]+)$/', $range, $m) && (int)$m[1] > (int)$m[2]) {
                throw new TreeError('include-range', $path . '/options/' . $key);
            }
        }
    }

    /**
     * @param \stdClass $node
     * @param string $path
     * @param array<string, \stdClass> $declared
     *
     * @throws \MarkupCarve\Carve\Json\TreeError
     */
    private static function extension(stdClass $node, string $path, array $declared): void
    {
        $name = $node->name ?? '';
        if (!str_contains($name, '.')) {
            // C2-A-124: an unqualified inline extension carries `name`, `children` and the common fields only.
            foreach (get_object_vars($node) as $field => $value) {
                if (!isset(self::UNQUALIFIED_FIELDS[$field])) {
                    throw new TreeError('unqualified-extension-field', $path . '/' . self::escape($field));
                }
            }

            return;
        }
        if (isset($node->source)) {
            self::sourceName($node->source, $path . '/source/name');
        }
        if (!isset($declared[$name]) || $declared[$name]->version !== $node->version) {
            throw new TreeError('extension-declaration', $path . '/name');
        }
        foreach (get_object_vars($node->slots ?? new stdClass()) as $slot => $value) {
            if (!in_array($slot, $declared[$name]->slots, true)) {
                throw new TreeError('extension-slot', $path . '/slots/' . self::escape($slot));
            }
        }
    }

    /**
     * C2-X-025: a source name that a matcher of its form can claim.
     *
     * @param \stdClass $source
     * @param string $path
     *
     * @throws \MarkupCarve\Carve\Json\TreeError
     */
    private static function sourceName(stdClass $source, string $path): void
    {
        $name = is_string($source->name ?? null) ? $source->name : '';
        $form = $source->form ?? '';
        $claimed = match ($form) {
            // C2-B-127: the name of a plain div; `figure` is one only with a title or a label.
            'div' => self::whole($name, Tables::CHARS_DIV_NAME) && (!isset(Tables::DIV_NAME_KINDS[$name])
                || ($name === 'figure' && (isset($source->title) || isset($source->groupLabel)))),
            // A raw fence (`=name`) opens a raw block, not a code block.
            'fence' => self::whole($name, Tables::CHARS_LANGUAGE_WORD),
            'inline' => $name !== '' && strspn($name, Tables::SIGIL_INLINE_EXTENSION_FIRST, 0, 1) === 1
                && strspn($name, Tables::SIGIL_INLINE_EXTENSION_REST, 1) === strlen($name) - 1,
            default => true,
        };
        if (!$claimed) {
            throw new TreeError('extension-source-name', $path);
        }
    }

    private static function whole(string $s, string $chars): bool
    {
        return $s !== '' && strspn($s, $chars) === strlen($s);
    }

    private static function opaque(mixed $value, string $path, string $reason): void
    {
        $stack = [[$value, $path]];
        while ($stack !== []) {
            [$value, $path] = array_pop($stack);
            if ($value instanceof stdClass) {
                if (isset($value->type) && is_string($value->type)) {
                    throw new TreeError($reason, $path);
                }
                $value = get_object_vars($value);
            }
            if (is_array($value)) {
                foreach ($value as $key => $child) {
                    $stack[] = [$child, $path . '/' . self::escape((string)$key)];
                }
            }
        }
    }

    /**
     * @param \stdClass $node
     * @param string $path
     * @param array{int, int, int, int} $levels The block, inline, qualified and unqualified extension counts.
     * @param array<\stdClass> $nodes
     * @param array<string> $paths
     * @param array<int> $counters
     */
    private static function pushNodes(
        stdClass $node,
        string $path,
        array $levels,
        array &$nodes,
        array &$paths,
        array &$counters,
    ): void {
        foreach (get_object_vars($node) as $key => $value) {
            if (isset(self::OPAQUE_FIELDS[$key]) || (!is_array($value) && !$value instanceof stdClass)) {
                continue;
            }
            $pending = [$value];
            $pendingPaths = [$path . '/' . self::escape($key)];
            while ($pending !== []) {
                $current = array_pop($pending);
                $currentPath = array_pop($pendingPaths) ?? '';
                if ($current instanceof stdClass) {
                    if (isset($current->type) && is_string($current->type)) {
                        $nodes[] = $current;
                        $paths[] = $currentPath;
                        array_push($counters, ...$levels);
                    } else {
                        foreach (get_object_vars($current) as $k => $v) {
                            if (is_array($v) || $v instanceof stdClass) {
                                $pending[] = $v;
                                $pendingPaths[] = $currentPath . '/' . self::escape((string)$k);
                            }
                        }
                    }
                } elseif (is_array($current)) {
                    foreach ($current as $k => $v) {
                        if (is_array($v) || $v instanceof stdClass) {
                            $pending[] = $v;
                            $pendingPaths[] = $currentPath . '/' . $k;
                        }
                    }
                }
            }
        }
    }

    private static function escape(string $part): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $part);
    }
}
