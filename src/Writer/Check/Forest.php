<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer\Check;

use MarkupCarve\Carve\Writer\BraceBlock;

/**
 * The simple span forest of write-check.ts (`simpleSpanForest` and its `forest*` tests): a paragraph whose spans hold
 * only key-value attributes and the text `[ ` has one source, so the escapes of its bracket openers are placed before
 * the first round.
 */
final class Forest
{
    /**
     * Independent formable span branches in one paragraph share a bracket kind in the repair.
     *
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $nodes
     *
     * @return list<\MarkupCarve\Carve\Writer\Check\Escape>
     */
    public static function simpleSpanForest(array $nodes): array
    {
        if (count($nodes) !== 1 || $nodes[0]->type !== 'paragraph') {
            return [];
        }
        $escapes = [];
        $pending = [[$nodes[0], false]];
        $scanned = [];
        while (($item = array_pop($pending)) !== null) {
            [$node, $inside] = $item;
            $scanned[] = $node;
            if (!self::forestNodeCanForm($node, $inside, $escapes)) {
                return [];
            }
            foreach ($node->children() as $child) {
                $pending[] = [$child, $inside || $node->type === 'span'];
            }
        }
        if (!self::forestNodesCanForm($scanned, $escapes)) {
            return [];
        }

        return array_map(static fn (Node $text): Escape => new Escape($text, 0), array_values($escapes));
    }

    /**
     * @param \MarkupCarve\Carve\Writer\Check\Node $node
     * @param bool $inside
     * @param array<int, \MarkupCarve\Carve\Writer\Check\Node> $escapes
     */
    private static function forestNodeCanForm(Node $node, bool $inside, array &$escapes): bool
    {
        if (!self::forestFieldsCanForm($node)) {
            return false;
        }
        if ($node->type === 'span') {
            return true;
        }
        if ($node->type === 'text') {
            // Text of a simple span forest, with its potential openers.
            if (!$inside || $node->value !== '[ ') {
                return false;
            }
            $escapes[spl_object_id($node)] = $node;

            return true;
        }

        return $node->type === 'paragraph' || (!$inside && ($node->type === 'emphasis' || $node->type === 'strong'));
    }

    /**
     * Every node the shortcut writes must be supported by a bracket opener in its subtree.
     *
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $scanned
     * @param array<int, \MarkupCarve\Carve\Writer\Check\Node> $escapes
     */
    private static function forestNodesCanForm(array $scanned, array $escapes): bool
    {
        $hasOpener = [];
        for ($at = count($scanned) - 1; $at >= 0; $at--) {
            $node = $scanned[$at];
            if ($node->type === 'text') {
                $found = isset($escapes[spl_object_id($node)]);
            } else {
                $found = false;
                foreach ($node->children() as $child) {
                    $found = $found || ($hasOpener[spl_object_id($child)] ?? false);
                }
            }
            if ($node->type !== 'paragraph' && $node->type !== 'text' && !$found) {
                return false;
            }
            $hasOpener[spl_object_id($node)] = $found;
        }

        return true;
    }

    /**
     * Only the span attribute block is authored by this shortcut; other fields need the loss check.
     */
    private static function forestFieldsCanForm(Node $node): bool
    {
        foreach (['data', 'metadata', 'lang', 'dir', 'segments'] as $field) {
            if ($node->field($field) !== null) {
                return false;
            }
        }
        if ($node->start !== null || ($node->type !== 'span' && $node->field('attrs') !== null)) {
            return false;
        }
        if ($node->type === 'paragraph' && ($node->field('role') !== null || isset($node->slots['caption']))) {
            return false;
        }

        return $node->type !== 'span' || self::forestSpanAttrsCanForm($node);
    }

    /**
     * A simple set of named values cannot be reframed as id, class, or language fields.
     */
    private static function forestKeyCanForm(string $key): bool
    {
        return BraceBlock::isKey($key) && $key === strtolower($key) && !in_array($key, ['id', 'class', 'lang', 'dir'], true);
    }

    /**
     * The block holds only keys, so it has a line break (C2-I-028) or a bracket the forest cannot write exactly where a
     * value does.
     */
    private static function forestValueCanForm(string $value): bool
    {
        return preg_match('/[\0\r\[\]]/', $value) !== 1;
    }

    private static function forestSpanAttrsCanForm(Node $node): bool
    {
        $attrs = $node->field('attrs');
        $pairs = is_array($attrs) ? ($attrs['keyValues'] ?? null) : null;
        if (!is_array($attrs) || isset($attrs['id']) || isset($attrs['classes']) || !is_array($pairs) || $pairs === []) {
            return false;
        }
        $seen = [];
        foreach ($pairs as $pair) {
            $key = is_array($pair) ? ($pair[0] ?? null) : null;
            $value = is_array($pair) ? ($pair[1] ?? null) : null;
            $plain = is_string($key) && is_string($value) && self::forestKeyCanForm($key) && self::forestValueCanForm($value);
            if (!$plain || isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;
        }

        return true;
    }
}
