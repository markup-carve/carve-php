<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use MarkupCarve\Carve\AttributeParser;
use MarkupCarve\Carve\Generated\Tables;
use stdClass;

/**
 * The attribute records of the lint model (C2-W-148): each part of an attribute block that a node took, with the facts
 * of its owner (`attribute_fields`, `attribute_owner_fields`). Ported from carve-js `lint/data-attributes.ts`.
 */
final class DataAttributes
{
    /**
     * @var array<string, true>
     */
    private const MARKER_OWNERS = ['list_item' => true, 'table_cell' => true];

    /**
     * @var array<string, true>
     */
    private const SPAN_KEYS = ['abbr' => true, 'time' => true, 'kbd' => true];

    /**
     * @var list<\stdClass> The parts read for the current owner.
     */
    private array $parts = [];

    private function __construct(private string $source)
    {
    }

    /**
     * The records of the nodes that carry `attrs` and of every table, in source order within each owner.
     *
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return list<\stdClass>
     */
    public static function records(Context $c): array
    {
        $reader = new self($c->source);
        $semantic = null;
        $out = [];
        foreach ($c->attributed as [$node, $path, $parent, $pos]) {
            $start = $pos['startOffset'];
            $end = $pos['endOffset'];
            $reader->parts = [];
            $opener = $start;
            $parentType = $c->nodeTypes[$parent] ?? 'document';
            $type = is_string($node['type'] ?? null) ? $node['type'] : '';
            if (isset(DataModel::INLINE_PARENTS[$parentType]) || preg_match('~/title/\d+$~', $path) === 1) {
                $reader->inlineParts($node, $start, $end);
            } elseif (isset(self::MARKER_OWNERS[$type])) {
                $reader->markerParts($start, $end, $type === 'table_cell');
            } else {
                $opener = $reader->lineParts($pos, $start, $path, $c);
            }
            if ($reader->parts === []) {
                continue;
            }
            $semantic ??= $c->semanticKeys();
            $owner = self::owner($node, $path, $c, $start, $end, $opener);
            self::finish($reader->parts, $owner, $node, $semantic, $path);
            array_push($out, ...$reader->parts);
        }

        return $out;
    }

    /**
     * C2-I-036: the run of glued blocks that ends at the end of an inline node; it starts behind the node's content.
     *
     * @param array<string, mixed> $node
     * @param int $end
     * @param int $start
     */
    private function inlineParts(array $node, int $start, int $end): void
    {
        if ($end <= $start || ($this->source[$end - 1] ?? '') !== '}') {
            return;
        }
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        $last = $children === [] ? null : $children[count($children) - 1];
        $lastPos = is_array($last) ? $last['pos'] ?? null : null;
        $lastEnd = is_int($lastPos) ? $lastPos & 0xFFFFFFFF
            : (is_array($lastPos) && is_int($lastPos['endOffset'] ?? null) ? $lastPos['endOffset'] : 0);
        $from = max($start + 1, $lastEnd);
        for ($at = strpos($this->source, '{', $from); $at !== false && $at < $end; $at = strpos($this->source, '{', $at + 1)) {
            if ($this->run($at, $end, false) === $end) {
                return;
            }
            $this->parts = [];
        }
    }

    /**
     * C2-B-101, C2-B-171: the run of glued blocks that abuts the marker of an item or a cell.
     */
    private function markerParts(int $start, int $end, bool $cell): void
    {
        $at = $start;
        if ($cell) {
            $at += ($this->source[$at] ?? '') === '|' ? 1 : 0;
            $at += ($this->source[$at] ?? '') === '=' ? 1 : 0;
        } else {
            while ($at < $end && $this->source[$at] !== '{' && ord($this->source[$at]) > 32) {
                $at++;
            }
        }
        if (($this->source[$at] ?? '') === '{') {
            $this->run($at, $end, $cell);
        }
    }

    /**
     * C2-B-090, C2-B-091, C2-B-102: the attribute lines at the start of a block, read behind the prefixes of its
     * quotes. Returns where the block's own text starts.
     *
     * @param array<string, int> $pos
     * @param \MarkupCarve\Carve\Lint\Context $c
     * @param string $path
     * @param int $start
     */
    private function lineParts(array $pos, int $start, string $path, Context $c): int
    {
        $from = $start;
        $marks = null;
        for ($number = $pos['startLine']; $number <= $pos['endLine']; $number++) {
            $line = $c->lines[$number - 1] ?? null;
            if ($line === null) {
                break;
            }
            if ($number > $pos['startLine']) {
                $marks ??= self::quoteDepth($path, $c);
                $from = self::quotedStart($line, $marks);
            }
            if (($this->source[$from] ?? '') !== '{') {
                return $from;
            }
            $end = $line->end;
            while ($end > $from && ($this->source[$end - 1] === ' ' || $this->source[$end - 1] === "\t")) {
                $end--;
            }
            $kept = count($this->parts);
            if ($this->run($from, $end, false) !== $end) {
                array_splice($this->parts, $kept);

                return $from;
            }
        }

        return $start;
    }

    private static function quoteDepth(string $path, Context $c): int
    {
        $depth = 0;
        while ($path !== '') {
            $at = strrpos($path, '/children/');
            $path = $at === false ? '' : substr($path, 0, $at);
            if (($c->nodeTypes[$path] ?? '') === 'block_quote') {
                $depth++;
            }
        }

        return $depth;
    }

    /**
     * The start of a line behind its indent and up to `$marks` quote marks, each with the blanks behind it.
     */
    private static function quotedStart(Line $line, int $marks): int
    {
        $text = $line->text;
        $length = strlen($text);
        $at = strspn($text, " \t");
        while ($marks-- > 0 && ($text[$at] ?? '') === '>' && (($text[$at + 1] ?? '') === ' ' || $at + 1 === $length)) {
            $at = min($at + 2, $length);
            $at += strspn($text, " \t", $at);
        }

        return $line->start + $at;
    }

    /**
     * The run of glued valid blocks at `$from`, its parts taken; in a cell prefix the first block may open with
     * alignment marks (C2-B-182). Returns where the run ends.
     */
    private function run(int $from, int $end, bool $cell): int
    {
        $at = $from;
        while (($this->source[$at] ?? '') === '{') {
            $stop = $this->blockEnd($at, $end);
            if ($stop < 0) {
                return $at;
            }
            $marks = $cell && $at === $from ? $this->cellMarks($at) : 0;
            $kept = count($this->parts);
            $behind = $at + 1 + $marks;
            $marksOnly = $marks > 0 && $behind + strspn($this->source, ' ', $behind, max(0, $stop - $behind)) === $stop - 1;
            if (!$marksOnly && $this->block($at + $marks, $stop) !== $stop) {
                array_splice($this->parts, $kept);

                return $at;
            }
            $at = $stop;
        }

        return $at;
    }

    /**
     * The end of the brace block at `$at`: behind its first `}` outside a quoted value, or -1.
     */
    private function blockEnd(int $at, int $end): int
    {
        $first = $this->source[$at + 1] ?? '';
        if ($at + 1 >= $end || $first === '%' || $first === '{' || $first === '}') {
            return -1;
        }
        $quote = null;
        for ($i = $at + 1; $i < $end; $i++) {
            $char = $this->source[$i];
            if ($char === "\n") {
                return -1;
            }
            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
            } elseif (($char === '"' || $char === "'") && $this->source[$i - 1] === '=') {
                $quote = $char;
            } elseif ($char === '}') {
                return $i + 1;
            }
        }

        return -1;
    }

    /**
     * C2-B-182: the alignment marks at the start of the first block of a cell prefix.
     */
    private function cellMarks(int $at): int
    {
        $vertical = in_array($this->source[$at + 2] ?? '', ['^', '~', 'v'], true) ? 1 : 0;

        return match ($this->source[$at + 1] ?? '') {
            '<', '>', '~' => 1 + $vertical,
            '?' => $vertical === 1 ? 2 : 0,
            default => 0,
        };
    }

    /**
     * One block whose `{` (or last alignment mark) stands at `$at`, ending before `$end`, as `AttributeParser::read`
     * reads it, with the offsets of its parts. Returns the offset behind its `}`, or -1.
     */
    private function block(int $at, int $end): int
    {
        $s = $this->source;
        $i = $at + 1;
        $parts = 0;
        while (true) {
            $i += strspn($s, ' ', $i, max(0, $end - $i));
            if ($i >= $end) {
                return -1;
            }
            if ($s[$i] === '}') {
                break;
            }
            if ($parts > 0 && $s[$i - 1] !== ' ') {
                return -1;
            }
            $i = $this->part($i, $end);
            if ($i < 0) {
                return -1;
            }
            $parts++;
        }

        return $parts === 0 ? -1 : $i + 1;
    }

    private function part(int $i, int $end): int
    {
        $s = $this->source;
        $c = $s[$i];
        if ($c === '#' || $c === '.') {
            $n = strspn($s, Tables::ATTR_NAME_CHARS, $i + 1, $end - $i - 1);
            if ($n === 0) {
                return -1;
            }
            $this->add($c === '#' ? 'id' : 'class', '', substr($s, $i + 1, $n), $i, $i + 1 + $n, $i + 1 + $n);

            return $i + 1 + $n;
        }
        if ($c === ':') {
            return $this->language($i, $end);
        }
        if (strspn($c, Tables::ATTR_KEY_FIRST) === 0) {
            return -1;
        }
        $keyEnd = $i + 1 + strspn($s, Tables::ATTR_KEY_REST, $i + 1, $end - $i - 1);
        $key = substr($s, $i, $keyEnd - $i);
        if ($keyEnd < $end && $s[$keyEnd] === '=') {
            return $this->value($key, $i, $keyEnd, $end);
        }
        // C2-I-031: a bare key does not start with `_`.
        if ($c === '_') {
            return -1;
        }
        $this->add('key', $key, '', $i, $keyEnd, $keyEnd);

        return $keyEnd;
    }

    /**
     * C2-I-030: a language tag is empty or runs of letters and digits joined by single hyphens.
     */
    private function language(int $i, int $end): int
    {
        $s = $this->source;
        $j = $i + 1;
        while (true) {
            $n = strspn($s, Tables::ATTR_LANGUAGE_TAG_CHARS, $j, $end - $j);
            if ($n === 0) {
                if ($j !== $i + 1) {
                    return -1;
                }

                break;
            }
            $j += $n;
            if ($j >= $end || $s[$j] !== '-') {
                break;
            }
            $j++;
        }
        $this->add('lang', '', substr($s, $i + 1, $j - $i - 1), $i, $j, $j);

        return $j;
    }

    /**
     * C2-I-032, C2-I-033: the value of a key behind its `=`, quoted or not.
     */
    private function value(string $key, int $i, int $keyEnd, int $end): int
    {
        $s = $this->source;
        $from = $keyEnd + 1;
        if ($from >= $end) {
            return -1;
        }
        $value = '';
        if ($s[$from] === '"' || $s[$from] === "'") {
            $after = AttributeParser::quoted($s, $from, $end, $value);
        } else {
            $n = strcspn($s, Tables::ATTR_UNQUOTED_VALUE_EXCLUDED, $from, $end - $from);
            $after = $n === 0 ? -1 : $from + $n;
            $value = substr($s, $from, $n);
        }
        if ($after < 0) {
            return -1;
        }
        $this->add('key', $key, $value, $i, $keyEnd, $after);

        return $after;
    }

    private function add(string $kind, string $key, string $value, int $start, int $keyEnd, int $end): void
    {
        $this->parts[] = (object)[
            'kind' => $kind,
            'key' => $key,
            'value' => $value,
            'start' => $start,
            'key_end' => $keyEnd,
            'end' => $end,
            'text' => substr($this->source, $start, $end - $start),
            'index' => count($this->parts),
            'last_key' => $kind === 'key',
            'kept' => false,
            'semantic' => false,
            'semantic_value' => false,
            'entries' => 0,
            'entry_total' => 0,
            'owner' => null,
            'path' => '',
        ];
    }

    /**
     * @param array<string, mixed> $node
     * @param int $opener
     * @param int $end
     * @param int $start
     * @param \MarkupCarve\Carve\Lint\Context $c
     * @param string $path
     */
    private static function owner(array $node, string $path, Context $c, int $start, int $end, int $opener): stdClass
    {
        $type = is_string($node['type'] ?? null) ? $node['type'] : '';
        $table = $type === 'table';
        [$align, $valign] = $table ? self::overlaps($node) : ['', ''];

        return (object)[
            'type' => $type === 'comment' && ($node['form'] ?? null) === 'block' ? 'comment_block' : $type,
            'start' => $start,
            'end' => $end,
            'opener_start' => $opener,
            'node' => $node,
            'format' => $table ? ($c->layout->sourceFormat($path) ?? '') : '',
            'columns' => $table && is_array($node['columns'] ?? null) ? count($node['columns']) : 0,
            'head_rows' => $table ? self::groupRows($node, 'table_head') : 0,
            'body_rows' => $table ? self::groupRows($node, 'table_body') : 0,
            'foot_rows' => $table ? self::groupRows($node, 'table_foot') : 0,
            'align_overlap' => $align,
            'valign_overlap' => $valign,
        ];
    }

    /**
     * @param array<string, mixed> $node
     * @param string $type
     */
    private static function groupRows(array $node, string $type): int
    {
        $rows = 0;
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $group) {
            if (is_array($group) && ($group['type'] ?? null) === $type) {
                foreach (is_array($group['children'] ?? null) ? $group['children'] : [] as $row) {
                    $rows += is_array($row) && ($row['type'] ?? null) === 'table_row' ? 1 : 0;
                }
            }
        }

        return $rows;
    }

    /**
     * C2-B-213: the columns where `aligns` or `valigns` and a mark of the first head row set the same axis.
     *
     * @param array<string, mixed> $node
     *
     * @return array{0: string, 1: string}
     */
    private static function overlaps(array $node): array
    {
        $first = self::firstHeadRow($node);
        $align = [];
        $valign = [];
        if ($first !== null) {
            $cells = is_array($first['children'] ?? null) ? $first['children'] : [];
            foreach (is_array($node['columns'] ?? null) ? $node['columns'] : [] as $at => $column) {
                $cell = $cells[$at] ?? null;
                if (!is_array($cell) || !is_array($column)) {
                    continue;
                }
                if (isset($column['align'], $cell['align'])) {
                    $align[] = $at + 1;
                } elseif (isset($column['valign'], $cell['valign'])) {
                    $valign[] = $at + 1;
                }
            }
        }

        return [implode(' ', $align), implode(' ', $valign)];
    }

    /**
     * The first row of the first head group of a table.
     *
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>|null
     */
    private static function firstHeadRow(array $node): ?array
    {
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $group) {
            if (is_array($group) && ($group['type'] ?? null) === 'table_head') {
                foreach (is_array($group['children'] ?? null) ? $group['children'] : [] as $row) {
                    if (is_array($row) && ($row['type'] ?? null) === 'table_row') {
                        return $row;
                    }
                }

                return null;
            }
        }

        return null;
    }

    /**
     * The fields a part takes from its owner and from the later parts of that owner.
     *
     * @param list<\stdClass> $parts
     * @param \stdClass $owner
     * @param array<string, mixed> $node
     * @param array<array{0: string, 1: string|null}> $semantic
     * @param string $path
     */
    private static function finish(array $parts, stdClass $owner, array $node, array $semantic, string $path): void
    {
        $kept = [];
        $attrs = $node['attrs'] ?? null;
        foreach (is_array($attrs) && is_array($attrs['keyValues'] ?? null) ? $attrs['keyValues'] : [] as $pair) {
            if (is_array($pair) && is_string($pair[0] ?? null)) {
                $kept[$pair[0]] = true;
            }
        }
        $span = $owner->type === 'span';
        $count = count($parts);
        foreach ($parts as $at => $part) {
            $part->owner = $owner;
            $part->path = $path;
            if ($part->kind !== 'key') {
                continue;
            }
            $part->kept = isset($kept[$part->key]);
            foreach ($semantic as [$name, $element]) {
                if ($name === $part->key) {
                    $part->semantic = true;
                    $part->semantic_value = $element !== null;

                    break;
                }
            }
            $part->entries = substr_count($part->value, ',') + 1;
            $part->entry_total = self::entryTotal($part->value);
            for ($later = $at + 1; $later < $count; $later++) {
                if ($parts[$later]->kind === 'key' && self::sameName($part->key, $parts[$later]->key, $span)) {
                    $part->last_key = false;

                    break;
                }
            }
        }
    }

    /**
     * C2-I-039, C2-A-035: whether two keys of one owner have the name the tree merges by.
     */
    private static function sameName(string $a, string $b, bool $span): bool
    {
        if ($a === $b) {
            return true;
        }
        $lower = strtolower($a);
        if (strlen($a) !== strlen($b) || $lower !== strtolower($b)) {
            return false;
        }

        return !$span || ($a === $lower && isset(self::SPAN_KEYS[$lower])) === ($b === $lower && isset(self::SPAN_KEYS[$lower]));
    }

    /**
     * C2-B-212: the sum of the comma-separated entries, spaces and tabs around an entry ignored, rounded up to a whole
     * number (the rows compare it only with whole numbers); 0 when an entry is no decimal number.
     */
    public static function entryTotal(string $value): int
    {
        $whole = 0;
        $fractions = [];
        foreach (explode(',', $value) as $entry) {
            if (preg_match('/^[ \t]*([0-9]+)(?:\.([0-9]+))?[ \t]*$/', $entry, $m) !== 1) {
                return 0;
            }
            $whole = self::sum($whole, $m[1]);
            $fractions[] = $m[2] ?? '';
        }
        // The fractions are added digit by digit, so the carry into the whole part is exact at any length.
        $scale = max(array_map('strlen', $fractions));
        $carry = 0;
        $fraction = false;
        for ($place = $scale - 1; $place >= 0; $place--) {
            $column = $carry;
            foreach ($fractions as $digits) {
                $column += isset($digits[$place]) ? (int)$digits[$place] : 0;
            }
            $fraction = $fraction || $column % 10 !== 0;
            $carry = intdiv($column, 10);
        }

        return self::sum(self::sum($whole, (string)$carry), $fraction ? '1' : '0');
    }

    private static function sum(int $sum, string $digits): int
    {
        $digits = ltrim($digits, '0');
        if (strlen($digits) > 18) {
            return PHP_INT_MAX;
        }
        $value = (int)$digits;

        return $sum > PHP_INT_MAX - $value ? PHP_INT_MAX : $sum + $value;
    }
}
