<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Support;

/**
 * The position invariants C2-A-139 to C2-A-159, as `scripts/spec2-trees.mjs` of the specification checks them.
 */
final class PositionChecker
{
    /**
     * C2-A-158, C2-A-215: the types that may omit `pos`.
     *
     * @var array<string, true>
     */
    private const UNPOSITIONED = [
        'table_column' => true,
        'cell_paragraph' => true,
        'table_row' => true,
        'table_cell' => true,
        'text' => true,
        'table_head' => true,
        'table_body' => true,
        'table_foot' => true,
    ];

    /**
     * C2-A-159
     *
     * @var array<string, true>
     */
    private const NEVER_PARSED = ['section' => true, 'ruby' => true, 'ruby_pair' => true, 'small_caps' => true];

    /**
     * @var array<string, true>
     */
    private const BREAKS = ['soft_break' => true, 'hard_break' => true];

    /**
     * @var array<string, true>
     */
    private const SKIP = ['data' => true, 'pos' => true, 'segments' => true, 'attrs' => true];

    /**
     * @var array<string|int, array{0: array<int, array{0: int, 1: int}>, 1: string}>
     */
    private array $tables = [];

    /**
     * @var array<string>
     */
    private array $found = [];

    /**
     * @param string $source
     * @param array<string, string> $files
     */
    public function __construct(string $source, array $files = [])
    {
        $this->tables[''] = self::table($source);
        foreach ($files as $id => $text) {
            $this->tables[$id] = self::table($text);
        }
    }

    /**
     * @param array<string, mixed> $tree
     *
     * @return array<string>
     */
    public function failures(array $tree): array
    {
        $this->found = [];
        $this->walk($tree, '', '', [], false, false);

        return $this->found;
    }

    /**
     * Line and column of every offset that starts a character.
     *
     * @param string $text
     *
     * @return array{0: array<int, array{0: int, 1: int}>, 1: string}
     */
    private static function table(string $text): array
    {
        $map = [0 => [1, 1]];
        $line = 1;
        $column = 1;
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $offset = 0;
        foreach ($chars as $char) {
            $offset += strlen($char);
            if ($char === "\n") {
                $line++;
                $column = 1;
            } else {
                $column++;
            }
            $map[$offset] = [$line, $column];
        }

        return [$map, $text];
    }

    /**
     * @param mixed $value
     * @param string $path
     * @param string $file
     * @param array<array<string, mixed>> $ancestors
     * @param bool $inCell
     * @param bool $derived
     *
     * @return void
     */
    private function walk(mixed $value, string $path, string $file, array $ancestors, bool $inCell, bool $derived): void
    {
        if (!is_array($value)) {
            return;
        }
        if (array_is_list($value)) {
            $this->siblings($value, $path);
            foreach ($value as $at => $item) {
                $this->walk($item, $path . '/' . $at, $file, $ancestors, $inCell, $derived);
            }

            return;
        }
        $isNode = is_string($value['type'] ?? null);
        if ($isNode) {
            $this->node($value, $path, $file, $ancestors, $inCell, $derived);
            $ancestors[] = $value;
            $inCell = $inCell || $value['type'] === 'table_cell';
        }
        foreach ($value as $key => $child) {
            if (isset(self::SKIP[$key])) {
                continue;
            }
            $childFile = $isNode && $value['type'] === 'include' && $key === 'children' ? (string)($value['file'] ?? '') : $file;
            $childDerived = $derived || $isNode && $key === 'fallback'
                && in_array($value['type'], ['block_extension', 'inline_extension'], true);
            $this->walk($child, $path . '/' . $key, $childFile, $ancestors, $inCell, $childDerived);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param string $file
     * @param array<array<string, mixed>> $ancestors
     * @param bool $inCell
     * @param bool $derived
     *
     * @return void
     */
    private function node(array $node, string $path, string $file, array $ancestors, bool $inCell, bool $derived): void
    {
        $type = (string)$node['type'];
        $where = ($path === '' ? '/' : $path) . ' (' . $type . ')';
        if (isset(self::NEVER_PARSED[$type])) {
            $this->found[] = $where . ': a parser never builds this type (C2-A-159)';
        }
        $pos = $node['pos'] ?? null;
        if (!is_array($pos)) {
            $emptyLine = $type === 'line' && ($node['children'] ?? []) === [];
            if (
                $type !== 'document' && !isset(self::UNPOSITIONED[$type]) && !$inCell && !$emptyLine && !$derived
                && !self::copiedCaption($ancestors, $type)
            ) {
                $this->found[] = $where . ': no pos (C2-A-158)';
            }

            return;
        }
        if ((string)($pos['file'] ?? '') !== $file) {
            $this->found[] = $where . ': pos.file is not the file of the include that holds it (C2-A-142)';

            return;
        }
        $this->offsets($node, $pos, $where, $ancestors);
    }

    /**
     * C2-N-073: the caption that an expanded code include copies into its child.
     *
     * @param array<array<string, mixed>> $ancestors
     * @param string $type
     *
     * @return bool
     */
    private static function copiedCaption(array $ancestors, string $type): bool
    {
        $count = count($ancestors);
        if (
            $type === 'caption' && $count >= 2 && $ancestors[$count - 2]['type'] === 'include'
            && isset($ancestors[$count - 2]['caption'])
        ) {
            return true;
        }
        foreach ($ancestors as $at => $a) {
            if (
                $a['type'] === 'caption' && $at >= 2 && $ancestors[$at - 2]['type'] === 'include'
                && isset($ancestors[$at - 2]['caption'])
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $pos
     * @param string $where
     * @param array<array<string, mixed>> $ancestors
     *
     * @return void
     */
    private function offsets(array $node, array $pos, string $where, array $ancestors): void
    {
        $file = (string)($pos['file'] ?? '');
        if (!isset($this->tables[$file])) {
            $this->found[] = $where . ': pos.file names no file (C2-A-142)';

            return;
        }
        [$map, $text] = $this->tables[$file];
        $start = $pos['startOffset'];
        $end = $pos['endOffset'];
        if ($start > $end || $end > strlen($text) || !isset($map[$start], $map[$end])) {
            $this->found[] = $where . ': offsets out of order, past the source or inside a character (C2-A-143)';

            return;
        }
        if ($map[$start] !== [$pos['startLine'], $pos['startColumn']] || $map[$end] !== [$pos['endLine'], $pos['endColumn']]) {
            $this->found[] = $where . ': line or column is not that of the offset (C2-A-143)';
        }
        $outer = null;
        foreach ($ancestors as $a) {
            if (isset($a['pos']) && (string)($a['pos']['file'] ?? '') === $file) {
                $outer = $a['pos'];
            }
        }
        if ($outer !== null && ($start < $outer['startOffset'] || $end > $outer['endOffset'])) {
            $this->found[] = $where . ': outside its nearest positioned ancestor (C2-A-144)';
        }
        $breaks = isset(self::BREAKS[$node['type']]);
        $first = $text[$start] ?? '';
        $crlf = $first === "\r" && ($text[$start + 1] ?? '') === "\n";
        if (($first === "\n" || $crlf) && !$breaks && $end > $start) {
            $this->found[] = $where . ': starts at a line ending (C2-A-150)';
        }
        if (isset($node['children']) && $start === $end && !$breaks) {
            $this->found[] = $where . ': a node with children is zero-width (C2-A-155)';
        }
    }

    /**
     * C2-A-145: siblings do not overlap.
     *
     * @param array<mixed> $nodes
     * @param string $path
     *
     * @return void
     */
    private function siblings(array $nodes, string $path): void
    {
        $last = null;
        foreach ($nodes as $node) {
            if (!is_array($node) || !is_string($node['type'] ?? null) || !is_array($node['pos'] ?? null)) {
                continue;
            }
            if ($last !== null && $last['pos']['endOffset'] > $node['pos']['startOffset']) {
                $breaks = isset(self::BREAKS[$last['type']], self::BREAKS[$node['type']]);
                $before = $last['type'] === 'comment' && self::inside($last['pos'], $node['pos']);
                $after = $node['type'] === 'comment' && $last['type'] === 'code_block' && self::inside($node['pos'], $last['pos']);
                if (!$breaks && !$before && !$after) {
                    $this->found[] = $path . ': ' . $last['type'] . ' overlaps ' . $node['type'] . ' (C2-A-145)';
                }
            }
            $last = $node;
        }
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     *
     * @return bool
     */
    private static function inside(array $a, array $b): bool
    {
        return $a['startOffset'] >= $b['startOffset'] && $a['endOffset'] <= $b['endOffset'];
    }
}
