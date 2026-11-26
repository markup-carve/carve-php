<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Include;

use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Node;

final class Section
{
    /**
     * @return array<\MarkupCarve\Carve\Node>|null
     */
    public static function select(Node $document, string $name): ?array
    {
        $heading = self::find($document, $name, true);
        $found = $heading ?? self::find($document, $name, false);
        if ($found === null) {
            return null;
        }
        [$list, $index] = $found;
        if ($heading === null) {
            $selected = [$list[$index]];
        } else {
            $level = $list[$index]->f['level'];
            $selected = [];
            $count = count($list);
            for ($i = $index; $i < $count; $i++) {
                $node = $list[$i];
                if ($i > $index && $node->type === 'heading' && $node->f['level'] <= $level) {
                    break;
                }
                $selected[] = $node;
            }
        }
        $taken = [];
        foreach ($selected as $node) {
            $taken[spl_object_id($node)] = true;
        }
        foreach (self::definitions($document) as $definition) {
            if (!isset($taken[spl_object_id($definition)])) {
                $selected[] = $definition;
            }
        }

        return $selected;
    }

    /**
     * @return array{0: array<\MarkupCarve\Carve\Node>, 1: int}|null
     */
    private static function find(Node $document, string $name, bool $heading): ?array
    {
        return self::findIn($document->children ?? [], $name, $heading);
    }

    /**
     * @param array<\MarkupCarve\Carve\Node> $list
     * @param bool $heading
     * @param string $name
     *
     * @return array{0: array<\MarkupCarve\Carve\Node>, 1: int}|null
     */
    private static function findIn(array $list, string $name, bool $heading): ?array
    {
        foreach ($list as $index => $node) {
            $attrs = $node->f['attrs'] ?? null;
            $authored = $attrs instanceof Attrs ? $attrs->id : null;
            $generated = $node->f['generatedId'] ?? null;
            if ($heading && $node->type === 'heading' && ($authored === $name || $generated === $name)) {
                return [$list, $index];
            }
            if (!$heading && self::eligible($node) && $authored === $name) {
                return [$list, $index];
            }
            if (
                $node->children !== null && in_array($node->type, [
                    'list', 'list_item', 'block_quote', 'div', 'description_list', 'description_item',
                ], true)
            ) {
                $nested = self::findIn($node->children, $name, $heading);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    private static function eligible(Node $node): bool
    {
        return !in_array($node->type, [
            'include', 'comment', 'frontmatter', 'link_reference_definition', 'abbreviation_def',
            'footnote', 'citation_definition', 'snippet_definition', 'table_row', 'table_cell', 'list_item',
        ], true);
    }

    /**
     * @return array<\MarkupCarve\Carve\Node>
     */
    public static function definitions(Node $document): array
    {
        $out = [];
        $stack = [$document];
        while ($stack !== []) {
            $node = array_pop($stack);
            if (
                in_array($node->type, [
                    'link_reference_definition', 'abbreviation_def', 'footnote', 'citation_definition', 'snippet_definition',
                ], true)
            ) {
                $out[] = $node;
            }
            foreach (array_reverse($node->children ?? []) as $child) {
                $stack[] = $child;
            }
        }

        return $out;
    }
}
