<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

/**
 * C2-W-088, C2-W-125 (carve#6656): a `]` in the text of a bracketed node takes its case-five backslash only after the
 * round that reads the bare form, where it can pair with a bracket before the node, so that round still reports the
 * kind `[` and escapes every potential opener of it in the leaf. Kept out of `RepairPlanner` so that it is loaded only
 * where a round reports a `[`.
 */
final class BracketKind
{
    /**
     * @var array<string, true>
     */
    private const BRACKETED = ['link' => true, 'image' => true, 'span' => true, 'inline_footnote' => true];

    /**
     * Whether the reported `[` at `$at` of the text at `$path` gives the inline kind: not inside a bracketed node
     * (C2-W-094), and not a definition label at the start of a line, which is a line kind.
     *
     * @param array<mixed> $tree
     * @param string $path
     * @param string $value
     * @param int $at
     *
     * @return bool
     */
    public static function expands(array $tree, string $path, string $value, int $at): bool
    {
        $node = $tree;
        $siblings = [];
        $part = '';
        foreach (explode('/', trim($path, '/')) as $part) {
            $type = $node['type'] ?? null;
            if (is_string($type) && isset(self::BRACKETED[$type])) {
                return false;
            }
            $siblings = $node;
            $node = is_array($node[$part] ?? null) ? $node[$part] : [];
        }
        $index = (int)$part;
        $previous = 'soft_break';
        if ($at === 0 && $index > 0) {
            $sibling = $siblings[$index - 1] ?? null;
            $previous = is_array($sibling) ? $sibling['type'] ?? null : null;
        }
        $lineStart = $at > 0 ? $value[$at - 1] === "\n" : $previous === 'soft_break' || $previous === 'hard_break';

        return !$lineStart || preg_match('/\G\[[^\]\n]*\]:/', $value, $m, 0, $at) !== 1;
    }
}
