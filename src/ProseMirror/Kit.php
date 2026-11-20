<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\ProseMirror;

use stdClass;

/**
 * The tables and helpers both bridge directions share: a port of carve-grammars `tiptap/tree.js`.
 *
 * Each ProseMirror node and mark keeps the tree fields the editor does not own in its `carve` attribute. `$`-prefixed
 * keys of `carve` are the kit's own bookkeeping; no tree field starts with `$`. An empty JSON object is a `stdClass`,
 * so it is written back as `{}`.
 *
 * @internal
 */
final class Kit
{
    /**
     * Inline containers that become marks: tree type => [mark name, the fields the mark owns].
     *
     * @var array<string, array{0: string, 1: array<string>}>
     */
    public const MARKS = [
        'strong' => ['bold', []],
        'emphasis' => ['italic', []],
        'underline' => ['underline', []],
        'strike' => ['strike', []],
        'highlight' => ['highlight', []],
        'subscript' => ['subscript', []],
        'superscript' => ['superscript', []],
        'insert' => ['carveInsert', []],
        'delete' => ['carveDelete', []],
        'small_caps' => ['carveSmallCaps', []],
        'span' => ['carveSpan', []],
        'link' => ['link', ['href']],
        'inline_footnote' => ['carveInlineFootnote', []],
        'inline_extension' => ['carveInlineExtension', []],
    ];

    /**
     * Block containers whose children are blocks.
     *
     * @var array<string, array{0: string, 1: array<string>}>
     */
    public const CONTAINERS = [
        'block_quote' => ['blockquote', []],
        'div' => ['carveDiv', []],
        'admonition' => ['carveAdmonition', ['kind']],
        'directive' => ['carveDirective', []],
        'section' => ['carveSection', []],
        'figure_group' => ['carveFigureGroup', []],
        'block_extension' => ['carveBlockExtension', []],
        'footnote' => ['carveFootnote', []],
        'definition_description' => ['carveDefinitionDescription', []],
        'caption' => ['carveCaption', []],
    ];

    /**
     * Textblocks whose children are inlines.
     *
     * @var array<string, array{0: string, 1: array<string>}>
     */
    public const TEXTBLOCKS = [
        'paragraph' => ['paragraph', []],
        'heading' => ['heading', ['level']],
        'definition_term' => ['carveDefinitionTerm', []],
        'line' => ['carveLine', []],
        'citation_definition' => ['carveCitationDefinition', []],
    ];

    /**
     * Blocks whose `value` is their editable text.
     *
     * @var array<string, string>
     */
    public const CODE_BLOCKS = [
        'comment' => 'carveComment',
        'raw_block' => 'carveRawBlock',
        'frontmatter' => 'carveFrontmatter',
    ];

    /**
     * Nodes whose title is edited as a leading `carveTitle` child.
     *
     * @var array<string, true>
     */
    public const TITLED = ['div' => true, 'admonition' => true, 'directive' => true];

    /**
     * @var array<string, true>
     */
    private const DROPPED = ['type' => true, 'pos' => true, 'segments' => true, 'srcByteLength' => true];

    /**
     * @var array<string, true>
     */
    private const POSITIONS = ['pos' => true, 'segments' => true, 'srcByteLength' => true];

    /**
     * `$tables` keyed by the second name: node or mark name => [tree type, owned fields].
     *
     * @param array<string, array{0: string, 1: array<string>}> $table
     *
     * @return array<string, array{0: string, 1: array<string>}>
     */
    public static function inverse(array $table): array
    {
        $out = [];
        foreach ($table as $tree => [$name, $owned]) {
            $out[$name] = [$tree, $owned];
        }

        return $out;
    }

    /**
     * The width of each row of `$rows` (`[colspan, rowspan]` per cell), row spans from above included.
     *
     * @param array<array<array{0: int, 1: int}>> $rows
     *
     * @return array<int>
     */
    public static function gridWidths(array $rows): array
    {
        $below = [];
        $out = [];
        foreach ($rows as $cells) {
            $taken = array_map(static fn (int $count): bool => $count > 0, $below);
            $column = 0;
            foreach ($cells as [$colspan, $rowspan]) {
                while ($taken[$column] ?? false) {
                    $column++;
                }
                for ($offset = 0; $offset < $colspan; $offset++) {
                    if ($rowspan > 1) {
                        $below[$column + $offset] = max($below[$column + $offset] ?? 0, $rowspan);
                    }
                    $taken[$column + $offset] = true;
                }
                $column += $colspan;
            }
            $out[] = max($column, $taken === [] ? 0 : max(array_keys($taken)) + 1);
            $below = array_map(static fn (int $count): int => max(0, $count - 1), $below);
        }

        return $out;
    }

    /**
     * A span count of an editor attribute: an integer above 1, or 1.
     */
    public static function spanCount(mixed $value): int
    {
        return is_int($value) && $value > 1 ? $value : 1;
    }

    /**
     * A copy of `$value` without positions, at any depth.
     */
    public static function clean(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            return $value;
        }
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::clean(...), $value);
        }
        $out = [];
        foreach ($value as $key => $item) {
            if (isset(self::POSITIONS[$key])) {
                continue;
            }
            $out[$key] = self::clean($item);
        }

        return $out;
    }

    /**
     * The fields of `$node` other than its type, positions and the names in `$omit`.
     *
     * @param array<string, mixed> $node
     * @param array<string> $omit
     *
     * @return array<string, mixed>
     */
    public static function fields(array $node, array $omit = []): array
    {
        $out = [];
        foreach ($node as $key => $value) {
            if (isset(self::DROPPED[$key]) || in_array($key, $omit, true)) {
                continue;
            }
            $out[$key] = self::clean($value);
        }

        return $out;
    }

    /**
     * `carve` as an attribute value: null when it holds nothing.
     *
     * @param array<string, mixed> $carve
     *
     * @return array<string, mixed>|null
     */
    public static function carveAttr(array $carve): ?array
    {
        return $carve !== [] ? $carve : null;
    }

    /**
     * The tree fields of a `carve` attribute, without the kit's own keys.
     *
     * @return array<string, mixed>
     */
    public static function treeFields(mixed $carve): array
    {
        $out = [];
        if (!is_array($carve)) {
            return $out;
        }
        foreach ($carve as $key => $value) {
            if (!str_starts_with((string)$key, '$')) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $pm
     *
     * @return array<string, mixed>
     */
    public static function carveOf(array $pm): array
    {
        return self::map(self::map($pm['attrs'] ?? null)['carve'] ?? null);
    }

    /**
     * An object member of decoded JSON, or `[]`.
     *
     * @return array<string, mixed>
     */
    public static function map(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[(string)$key] = $item;
        }

        return $out;
    }

    /**
     * The objects of a decoded JSON array, or `[]`.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function nodes(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $out[] = self::map($item);
            }
        }

        return $out;
    }

    public static function str(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string)$value : '';
    }

    /**
     * JSON objects as arrays, except that an empty object stays an object.
     */
    public static function decode(string $json): mixed
    {
        return self::arrays(json_decode($json, false, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * The JSON of a bridge value, with the empty objects of the input still objects.
     *
     * @param array<string, mixed> $value
     */
    public static function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function arrays(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $members = get_object_vars($value);
            if ($members === []) {
                return $value;
            }

            return array_map(self::arrays(...), $members);
        }
        if (is_array($value)) {
            return array_map(self::arrays(...), $value);
        }

        return $value;
    }
}
