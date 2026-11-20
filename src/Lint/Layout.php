<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

/**
 * The few facts of the source layout (`source-layout.md`) that lint reads, measured from the source on demand: the marker
 * and content columns of an item, a description or a footnote (C2-L-016 to C2-L-018), the blank lines after a block
 * (C2-L-026), the form of a table (C2-L-023) and the raw reference of a link (C2-L-022).
 */
final class Layout
{
    /**
     * @var int
     */
    private const TAB_STOP = 4;

    /**
     * @var array<string, array{0: array<string, mixed>, 1: int, 2: string}> Node, open quotes with marks, parent path.
     */
    private array $nodes = [];

    /**
     * @var array<string, string> C2-L-022: the `rawRef` of the source-layout record, by path.
     */
    private array $rawRefs = [];

    public function __construct(private string $s)
    {
    }

    /**
     * @param array<mixed> $entries The node entries of the source-layout record.
     *
     * @return void
     */
    public function rawRefs(array $entries): void
    {
        foreach ($entries as $entry) {
            if (is_array($entry) && is_string($entry['rawRef'] ?? null) && is_string($entry['path'] ?? null)) {
                $this->rawRefs[$entry['path']] = $entry['rawRef'];
            }
        }
    }

    /**
     * The number of quote prefixes in the source coordinates of a node.
     */
    public function quoteDepth(string $path): int
    {
        return $this->nodes[$path][1] ?? 0;
    }

    public function startOffset(string $path): ?int
    {
        return self::start($this->nodes[$path][0] ?? null);
    }

    public function endOffset(string $path): ?int
    {
        return self::end($this->nodes[$path][0] ?? null);
    }

    /**
     * @param string $path
     * @param array<string, mixed> $node
     * @param int $quotes
     * @param string $parent
     *
     * @return void
     */
    public function register(string $path, array $node, int $quotes, string $parent): void
    {
        $this->nodes[$path] = [$node, $quotes, $parent];
    }

    public function markerColumn(string $path): ?int
    {
        [$node, $quotes] = $this->nodes[$path] ?? [null, 0];
        $start = self::start($node);
        if ($start === null || !in_array($node['type'] ?? '', ['list_item', 'definition_description', 'footnote'], true)) {
            return null;
        }

        return $this->columnOf($start, $quotes);
    }

    public function contentColumn(string $path): ?int
    {
        [$node, $quotes] = $this->nodes[$path] ?? [null, 0];
        $start = self::start($node);
        if ($start === null) {
            return null;
        }
        $end = $this->lineEnd($start);
        $content = match ($node['type'] ?? '') {
            'list_item' => $this->itemContent($start, $end, isset($node['task'])),
            'definition_description' => $this->s[$start] === ':' && ($this->s[$start + 1] ?? '') !== ':'
                ? $this->afterSpace($start + 1, $end, 1) : -1,
            'footnote' => $this->footnoteContent($start, $end),
            default => -1,
        };
        $content = $content < 0 ? $end : $this->skipSpaces($content, $end);
        if ($content >= $end) {
            return null;
        }

        return $this->columnOf($content, $quotes);
    }

    /**
     * C2-L-026: the blank lines after a block, inside its quotes, up to the end of its parent.
     *
     * @param string $path
     *
     * @return int
     */
    public function blankLinesAfter(string $path): int
    {
        [$node, $quotes, $parent] = $this->nodes[$path] ?? [null, 0, ''];
        $end = self::end($node);
        $limit = self::end($this->nodes[$parent][0] ?? null) ?? strlen($this->s);
        if ($end === null) {
            return 0;
        }
        $count = 0;
        $feed = strpos($this->s, "\n", $end);
        $length = strlen($this->s);
        while ($feed !== false && $feed + 1 < $length) {
            $start = $feed + 1;
            if ($start >= $limit || !$this->blankInside($start, $this->lineEnd($start), $quotes)) {
                break;
            }
            $count++;
            $feed = strpos($this->s, "\n", $start);
        }

        return $count;
    }

    /**
     * C2-L-023: `pipe`, `csv` or `tsv`.
     *
     * @param string $path
     *
     * @return string|null
     */
    public function sourceFormat(string $path): ?string
    {
        $node = $this->nodes[$path][0] ?? null;
        $start = self::start($node);
        if ($start === null || ($node['type'] ?? '') !== 'table') {
            return null;
        }
        $at = $start;
        $n = strlen($this->s);
        while ($at < $n) {
            $p = $at + strspn($this->s, " \t>", $at);
            if ($p < $n && $this->s[$p] === '|') {
                return 'pipe';
            }
            if ($p < $n && ($this->s[$p] === '`' || $this->s[$p] === '~')) {
                $run = strspn($this->s, $this->s[$p], $p);

                return str_starts_with(ltrim(substr($this->s, $p + $run, 4)), 'tsv') ? 'tsv' : 'csv';
            }
            $feed = strpos($this->s, "\n", $at);
            if ($feed === false) {
                break;
            }
            $at = $feed + 1;
        }

        return 'pipe';
    }

    /**
     * C2-L-022: the reference of a link or an image as typed, from the source-layout record.
     *
     * @param string $path
     *
     * @return string|null
     */
    public function rawRef(string $path): ?string
    {
        return $this->rawRefs[$path] ?? null;
    }

    /**
     * @param array<string, mixed>|null $node
     *
     * @return int|null
     */
    private static function start(?array $node): ?int
    {
        $pos = $node['pos'] ?? null;

        return is_array($pos) && is_int($pos['startOffset'] ?? null) ? $pos['startOffset'] : null;
    }

    /**
     * @param array<string, mixed>|null $node
     *
     * @return int|null
     */
    private static function end(?array $node): ?int
    {
        $pos = $node['pos'] ?? null;

        return is_array($pos) && is_int($pos['endOffset'] ?? null) ? $pos['endOffset'] : null;
    }

    private function itemContent(int $start, int $end, bool $task): int
    {
        $ch = $this->s[$start] ?? '';
        if ($ch === '-' || $ch === '*') {
            $content = $this->afterSpace($this->braceBlocksEnd($start + 1, $end), $end, 1);
        } else {
            $p = $start;
            $p += strspn($this->s, ctype_digit($ch) ? '0123456789' : 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', $p);
            if ($p >= $end || ($this->s[$p] !== '.' && $this->s[$p] !== ')')) {
                return -1;
            }
            $spaces = $this->s[$p] === '.' && $p > $start && ctype_upper($ch) ? 2 : 1;
            $content = $this->afterSpace($this->braceBlocksEnd($p + 1, $end), $end, $spaces);
        }
        if ($task && $content >= 0 && $content + 3 <= $end && $this->s[$content] === '[' && $this->s[$content + 2] === ']') {
            return $this->afterSpace($content + 3, $end, 1);
        }

        return $content;
    }

    private function footnoteContent(int $start, int $end): int
    {
        if (!str_starts_with(substr($this->s, $start, 2), '[^')) {
            return -1;
        }
        $close = strpos($this->s, ']:', $start);

        return $close === false || $close >= $end ? -1 : $this->afterSpace($close + 2, $end, 1);
    }

    private function braceBlocksEnd(int $p, int $end): int
    {
        while ($p < $end && $this->s[$p] === '{') {
            $close = strpos($this->s, '}', $p);
            if ($close === false || $close >= $end) {
                break;
            }
            $p = $close + 1;
        }

        return $p;
    }

    /**
     * The offset behind the space a marker needs, the end of the line when nothing follows, or -1.
     *
     * @param int $p
     * @param int $end
     * @param int $spaces
     *
     * @return int
     */
    private function afterSpace(int $p, int $end, int $spaces): int
    {
        if ($p >= $end) {
            return $end;
        }
        $run = strspn($this->s, " \t", $p, $end - $p);

        return $run >= $spaces || $p + $run >= $end ? $p + min($run, $spaces) : -1;
    }

    private function skipSpaces(int $p, int $end): int
    {
        return $p + strspn($this->s, " \t", $p, max(0, $end - $p));
    }

    private function lineStart(int $pos): int
    {
        if ($pos <= 0) {
            return 0;
        }
        $feed = strrpos($this->s, "\n", $pos - 1 - strlen($this->s));

        return $feed === false ? 0 : $feed + 1;
    }

    private function lineEnd(int $pos): int
    {
        $feed = strpos($this->s, "\n", $pos);
        if ($feed === false) {
            return strlen($this->s);
        }

        return $feed > $pos && $this->s[$feed - 1] === "\r" ? $feed - 1 : $feed;
    }

    /**
     * C2-B-003: at most `quotes` quote marks, each with its one space.
     *
     * @param int $start
     * @param int $end
     * @param int $quotes
     *
     * @return array{0: int, 1: int, 2: int, 3: int} Offset, column, the column that counts as 0, marks read.
     */
    private function prefix(int $start, int $end, int $quotes): array
    {
        $pos = $start === 0 && str_starts_with($this->s, "\u{FEFF}") ? 3 : $start;
        $column = 0;
        $origin = 0;
        $marks = 0;
        while ($marks < $quotes) {
            $p = $pos;
            $c = $column;
            while ($p < $end && ($this->s[$p] === ' ' || $this->s[$p] === "\t")) {
                $c = self::advance($c, $this->s[$p++]);
            }
            if ($p >= $end || $this->s[$p] !== '>') {
                break;
            }
            $p++;
            $c++;
            if ($p < $end && $this->s[$p] === ' ') {
                $p++;
                $c++;
            }
            [$pos, $column, $origin] = [$p, $c, $c];
            $marks++;
        }

        return [$pos, $column, $origin, $marks];
    }

    /**
     * The zero-based column of an offset inside its open quotes; columns count code points, tab stops from the line start.
     *
     * @param int $offset
     * @param int $quotes
     *
     * @return int
     */
    public function columnOf(int $offset, int $quotes): int
    {
        [$pos, $column, $origin] = $this->prefix($this->lineStart($offset), $offset, $quotes);
        for (; $pos < $offset; $pos++) {
            if ((ord($this->s[$pos]) & 0xC0) !== 0x80) {
                $column = self::advance($column, $this->s[$pos]);
            }
        }

        return $column - $origin;
    }

    private function blankInside(int $start, int $end, int $quotes): bool
    {
        [$pos, , , $marks] = $this->prefix($start, $end, $quotes);

        return $marks === $quotes && $this->skipSpaces($pos, $end) >= $end;
    }

    private static function advance(int $column, string $ch): int
    {
        return $ch === "\t" ? $column + self::TAB_STOP - ($column % self::TAB_STOP) : $column + 1;
    }
}
