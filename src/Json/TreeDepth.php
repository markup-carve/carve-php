<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Json;

use MarkupCarve\Carve\Generated\Tables;

/**
 * The depth test of `TreeReader::read()` (C2-A-171) on a tree in array form, apart from the reader: an import checks
 * the depth of every tree it builds and reads only some of them.
 */
final class TreeDepth
{
    /**
     * C2-B-190: the containers that count one level each.
     *
     * @var array<string, true>
     */
    public const CONTAINERS = [
        'block_quote' => true,
        'list' => true,
        'list_item' => true,
        'definition_list' => true,
        'definition_description' => true,
        'footnote' => true,
        'div' => true,
        'admonition' => true,
        'directive' => true,
        'figure_group' => true,
        'section' => true,
    ];

    /**
     * C2-I-118: every inline node with children counts one level of its inline content.
     *
     * @var array<string, true>
     */
    public const INLINE = [
        'emphasis' => true,
        'strong' => true,
        'span' => true,
        'link' => true,
        'image' => true,
        'delete' => true,
        'insert' => true,
        'underline' => true,
        'strike' => true,
        'subscript' => true,
        'superscript' => true,
        'highlight' => true,
        'small_caps' => true,
        'inline_footnote' => true,
        'inline_extension' => true,
        'citation' => true,
        'ruby' => true,
        'ruby_pair' => true,
        'xref' => true,
        'abbreviation' => true,
        'autolink' => true,
        'mention' => true,
        'tag' => true,
        'text' => true,
        'code' => true,
    ];

    /**
     * Node fields whose values are not walked for child nodes.
     *
     * @var array<string, true>
     */
    public const OPAQUE_FIELDS = [
        'data' => true,
        'props' => true,
        'payload' => true,
        'source' => true,
        'resources' => true,
        'extensions' => true,
    ];

    /**
     * Whether a tree in array form is deeper than `TreeReader::read()` takes, counted as it counts, without encoding or
     * decoding it.
     *
     * @param array<string, mixed> $tree
     */
    public static function exceeds(array $tree): bool
    {
        // Parallel lists, as in `checkTree()`: a three-item array per pending node costs about 200 bytes.
        $nodes = [$tree];
        $levels = [0, 0];
        while ($nodes !== []) {
            $node = array_pop($nodes);
            $inlines = array_pop($levels) ?? 0;
            $blocks = array_pop($levels) ?? 0;
            $type = is_string($node['type'] ?? null) ? $node['type'] : '';
            if (isset(self::INLINE[$type])) {
                $inlines += isset($node['children']) ? 1 : 0;
            } else {
                $inlines = 0;
                $blocks += isset(self::CONTAINERS[$type]) ? 1 : 0;
            }
            if ($blocks > Tables::BLOCK_DEPTH || $inlines > Tables::INLINE_DEPTH) {
                return true;
            }
            if ($type === 'include') {
                $blocks = 0;
            }
            foreach (self::arrayNodes($node) as $child) {
                $nodes[] = $child;
                $levels[] = $blocks;
                $levels[] = $inlines;
            }
        }

        return false;
    }

    /**
     * The nodes in the fields of a node in array form, as `TreeReader::pushNodes()` finds them.
     *
     * @param array<mixed> $node
     *
     * @return array<array<mixed>>
     */
    private static function arrayNodes(array $node): array
    {
        $out = [];
        foreach ($node as $key => $value) {
            if (!is_array($value) || isset(self::OPAQUE_FIELDS[$key])) {
                continue;
            }
            $pending = [$value];
            while ($pending !== []) {
                $current = array_pop($pending);
                if (is_string($current['type'] ?? null)) {
                    $out[] = $current;

                    continue;
                }
                foreach ($current as $item) {
                    if (is_array($item)) {
                        $pending[] = $item;
                    }
                }
            }
        }

        return $out;
    }
}
