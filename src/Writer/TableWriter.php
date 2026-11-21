<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

use Generator;
use MarkupCarve\Carve\Json\TreeDecoder;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Resolve\TableGrid;

/**
 * Tables as Carve source (C2-W-061 to C2-W-068, C2-W-118, C2-W-135, C2-W-136): a pipe table, or a delimited table where
 * the sidecar records that form or the table has no rows.
 */
final class TableWriter
{
    /**
     * @var array<string, string>
     */
    private const ALIGN_MARKS = ['left' => '<', 'right' => '>', 'center' => '~'];

    /**
     * @var array<string, string>
     */
    private const VALIGN_MARKS = ['top' => '^', 'middle' => '~', 'bottom' => 'v'];

    /**
     * @var array<string>
     */
    private const GROUPS = ['table_head', 'table_body', 'table_foot'];

    /**
     * C2-W-145: the marks of the span counts of the table being written, or null where it needs none.
     */
    private ?SpanMarks $spans = null;

    public function __construct(private State $w, private Inline $inline)
    {
    }

    /**
     * The attribute line of a table: its attributes and the keys its fields need (C2-W-066, C2-W-136).
     *
     * @param array<mixed> $node
     * @param string $path
     * @param bool $delimited
     *
     * @return string
     */
    public function attributeLine(array $node, string $path, bool $delimited = false): string
    {
        $keys = $this->fieldKeys($node, $path, $delimited);
        $own = [];
        $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
        foreach (is_array($attrs['keyValues'] ?? null) ? $attrs['keyValues'] : [] as $pair) {
            if (is_array($pair) && is_string($pair[0] ?? null)) {
                $own[$pair[0]] = true;
            }
        }
        $skip = [];
        foreach ($keys as [$key]) {
            if (isset($own[$key])) {
                $skip[] = $key;
                $this->w->lose($path, 'table', 'attrs');
            }
        }
        if ($delimited) {
            array_unshift($keys, ['table', '']);
            $skip[] = 'table';
        }

        return BraceBlock::of($node, ['keys' => $keys, 'skipKeys' => $skip]);
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     * @param bool $delimited
     *
     * @return array<array{0: string, 1: string}>
     */
    private function fieldKeys(array $node, string $path, bool $delimited): array
    {
        $keys = [];
        [$aligns, $valigns, $widths] = $this->columnKeys($node, $path);
        if ($aligns !== '') {
            $keys[] = ['aligns', $aligns];
        }
        if ($valigns !== '') {
            $keys[] = ['valigns', $valigns];
        }
        if ($widths !== '') {
            $keys[] = ['widths', $widths];
        }
        $bodies = self::bodyFacts($node);
        $partitioned = $bodies !== [] && (
            count($bodies) > 1 || $bodies[0]['dataRows'] === 0 || $bodies[0]['headerRows'] > 0
            || $bodies[0]['rowHeadColumns'] !== null
        );
        if ($partitioned) {
            $keys[] = ['body-rows', implode(',', array_column($bodies, 'dataRows'))];
            if (array_filter($bodies, static fn (array $body): bool => $body['headerRows'] > 0) !== []) {
                $keys[] = ['body-header-rows', implode(',', array_column($bodies, 'headerRows'))];
            }
            if (array_filter($bodies, static fn (array $body): bool => $body['rowHeadColumns'] !== null) !== []) {
                $columns = array_map(
                    static fn (array $body): string => $body['rowHeadColumns'] === null ? '' : (string)$body['rowHeadColumns'],
                    $bodies,
                );
                $keys[] = ['body-header-cols', implode(',', $columns)];
            }
        }
        $groups = $this->groups($node);
        if ($delimited) {
            $head = count($groups['table_head'] ?? []);
            $fact = $this->w->fact($path, 'headerRows');
            $rows = count($groups['table_head'] ?? []) + count($groups['table_body'] ?? []) + count($groups['table_foot'] ?? []);
            if ($head !== min(1, $rows) || ($fact === $head)) {
                $keys[] = ['header-rows', (string)$head];
            }
        }
        if (isset($groups['table_foot'])) {
            $keys[] = ['footer-rows', (string)count($groups['table_foot'])];
        }

        return $keys;
    }

    /**
     * C2-W-136: record the data rows and leading header rows of each body.
     *
     * @param array<mixed> $node
     *
     * @return array<array{dataRows: int, headerRows: int, rowHeadColumns: int|null}>
     */
    private static function bodyFacts(array $node): array
    {
        $bodies = array_values(array_filter(
            is_array($node['children'] ?? null) ? $node['children'] : [],
            static fn (mixed $group): bool => is_array($group) && ($group['type'] ?? '') === 'table_body',
        ));
        $facts = [];
        foreach ($bodies as $body) {
            $rows = array_values(array_filter(
                is_array($body['children'] ?? null) ? $body['children'] : [],
                static fn (mixed $row): bool => is_array($row) && ($row['type'] ?? '') === 'table_row',
            ));
            $headers = self::leadingHeaderRows($rows);
            $facts[] = [
                'dataRows' => count($rows) - $headers,
                'headerRows' => $headers,
                'rowHeadColumns' => is_int($body['rowHeadColumns'] ?? null) ? $body['rowHeadColumns'] : null,
            ];
        }

        return $facts;
    }

    /**
     * @param array<mixed> $rows
     */
    private static function leadingHeaderRows(array $rows): int
    {
        $count = 0;
        foreach ($rows as $row) {
            if (!is_array($row) || ($row['type'] ?? '') !== 'table_row') {
                continue;
            }
            $cells = array_values(array_filter(
                is_array($row['children'] ?? null) ? $row['children'] : [],
                static fn (mixed $cell): bool => is_array($cell) && ($cell['type'] ?? '') === 'table_cell',
            ));
            if ($cells === [] || array_filter($cells, static fn (array $cell): bool => ($cell['header'] ?? false) !== true) !== []) {
                break;
            }
            $count++;
        }

        return $count;
    }

    /**
     * C2-B-211, C2-B-212: alignment and width lists from the column fields, up to the last column that sets one.
     *
     * @param array<mixed> $node
     * @param string $path
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function columnKeys(array $node, string $path): array
    {
        $aligns = [];
        $valigns = [];
        $widths = [];
        foreach (is_array($node['columns'] ?? null) ? $node['columns'] : [] as $index => $column) {
            $aligns[] = is_array($column) && is_string($column['align'] ?? null) ? $column['align'] : '';
            $valign = is_array($column) && is_string($column['valign'] ?? null) ? $column['valign'] : '';
            if ($valign === 'baseline') {
                $this->w->lose($path . '/columns/' . $index, 'table_column', 'valign');
                $valign = '';
            }
            $valigns[] = $valign;
            $width = is_array($column) ? ($column['width'] ?? null) : null;
            $widths[] = is_int($width) || is_float($width) ? self::percent((float)$width) : '';
        }

        return [$this->listKey($aligns, $path), $this->listKey($valigns, $path), $this->listKey($widths, $path)];
    }

    private static function percent(float $width): string
    {
        $text = rtrim(rtrim(sprintf('%.10F', $width * 100), '0'), '.');

        return $text === '' ? '0' : $text;
    }

    /**
     * @param array<string> $entries
     * @param string $path
     *
     * @return string
     */
    private function listKey(array $entries, string $path): string
    {
        while ($entries !== [] && end($entries) === '') {
            array_pop($entries);
        }
        if (in_array('', $entries, true)) {
            // C2-B-211: an empty entry makes the whole value invalid.
            $this->w->lose($path, 'table', 'columns');

            return '';
        }

        return implode(',', $entries);
    }

    /**
     * @param array<mixed> $node
     *
     * @return array<string, array<array<string, mixed>>>
     */
    private function groups(array $node): array
    {
        $groups = [];
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $group) {
            if (!is_array($group) || !in_array($group['type'] ?? '', self::GROUPS, true)) {
                continue;
            }
            $rows = [];
            foreach (is_array($group['children'] ?? null) ? $group['children'] : [] as $row) {
                if (is_array($row) && ($row['type'] ?? '') === 'table_row') {
                    $rows[] = $row;
                }
            }
            $groups[(string)$group['type']] = $rows;
        }

        return $groups;
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     * @param string $prefix
     * @param bool $prewritten
     *
     * @return void
     */
    public function write(array $node, string $path, string $prefix, bool $prewritten): void
    {
        // A table in a cell is written while the rows of the table around it are.
        $outer = $this->spans;
        $this->spans = SpanMarks::of($node, $path);
        try {
            $this->table($node, $path, $prefix, $prewritten);
        } finally {
            $this->spans = $outer;
        }
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     * @param string $prefix
     * @param bool $prewritten
     *
     * @return void
     */
    private function table(array $node, string $path, string $prefix, bool $prewritten): void
    {
        $groups = $this->groups($node);
        $rows = array_sum(array_map('count', $groups));
        $format = $this->w->fact($path, 'sourceFormat');
        $wanted = in_array($format, ['csv', 'tsv'], true) || ($format === null && $this->pipeCannotSpell($node));
        // C2-W-066: span marks have no delimited form, so a table that needs them is a pipe table.
        $delimited = $rows === 0 || ($wanted && $this->spans === null && $this->allowsDelimited($node));
        $this->spanCounts($node, $path);
        if ($delimited) {
            $this->delimited($node, $path, $prefix, is_string($format) && $format !== 'pipe' ? $format : 'csv', $prewritten);

            return;
        }
        if (!$prewritten) {
            $line = $this->attributeLine($node, $path);
            if ($line !== '') {
                $this->w->emit($prefix, $line);
            }
        }
        $lines = $this->lines($node, $path);
        foreach ($this->w->alignTables ? self::aligned($lines) : $lines as $line) {
            $this->w->emit($prefix, is_array($line) ? self::joined($line) : $line);
        }
    }

    /**
     * C2-A-093, C2-M-074: the span marks are the only spelling of a count, so a count they do not derive, as an
     * imported span has, is lost.
     *
     * @param array<mixed> $node
     * @param string $path
     *
     * @return void
     */
    private function spanCounts(array $node, string $path): void
    {
        if ($this->spans !== null) {
            foreach ($this->spans->unspelled as $at) {
                $this->w->lose($at, 'table_cell', 'rowspan');
            }

            return;
        }
        if (!self::hasSpanFields($node)) {
            return;
        }
        $table = TreeDecoder::fromArray(['type' => 'table'] + $node);
        if ($table === null) {
            return;
        }
        $supplied = self::cells($table, $path);
        $grid = TableGrid::of($table);
        $grid->deriveSpans();
        foreach ($supplied as [$cell, $at, $fields]) {
            foreach (['colspan', 'rowspan'] as $field) {
                $given = $fields[$field] ?? 1;
                $derived = $grid->marks ? $cell->f[$field] ?? 1 : 1;
                if ($given !== $derived) {
                    $this->w->lose($at, 'table_cell', $field);
                }
            }
        }
    }

    /**
     * Without a mark or a count there is no span to compare and no tree copy is
     * needed. Plain wide tables otherwise duplicate every cell in JSON form.
     *
     * @param array<mixed> $table
     */
    private static function hasSpanFields(array $table): bool
    {
        foreach (is_array($table['children'] ?? null) ? $table['children'] : [] as $group) {
            if (!is_array($group)) {
                continue;
            }
            foreach (is_array($group['children'] ?? null) ? $group['children'] : [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                foreach (is_array($row['children'] ?? null) ? $row['children'] : [] as $cell) {
                    if (
                        is_array($cell) && (isset($cell['span']) || ($cell['colspan'] ?? 1) > 1
                        || ($cell['rowspan'] ?? 1) > 1)
                    ) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param \MarkupCarve\Carve\Node $table
     * @param string $path
     *
     * @return array<array{0: \MarkupCarve\Carve\Node, 1: string, 2: array<string, mixed>}> Each cell, its path and its
     *   fields as supplied.
     */
    private static function cells(Node $table, string $path): array
    {
        $out = [];
        foreach ($table->children ?? [] as $g => $group) {
            foreach ($group->type === 'comment' ? [] : $group->children ?? [] as $r => $row) {
                foreach ($row->type === 'table_row' ? $row->children ?? [] : [] as $k => $cell) {
                    $out[] = [$cell, $path . '/children/' . $g . '/children/' . $r . '/children/' . $k, $cell->f];
                }
            }
        }

        return $out;
    }

    /**
     * The rows and comments of a pipe table, one entry per line.
     *
     * @param array<mixed> $node
     * @param string $path
     *
     * @return array<array{lead: string, segments: array<string>, tail: string}|string>
     */
    private function lines(array $node, string $path): array
    {
        $lines = [];
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        $lastGroup = -1;
        foreach ($children as $g => $group) {
            if (is_array($group) && in_array($group['type'] ?? '', self::GROUPS, true)) {
                $lastGroup = $g;
            }
        }
        foreach ($children as $g => $group) {
            if (!is_array($group)) {
                continue;
            }
            if (($group['type'] ?? '') === 'comment') {
                $lines[] = self::commentLine($group);

                continue;
            }
            $this->group($group, $path . '/children/' . $g, $g === $lastGroup, $lines);
        }

        return $lines;
    }

    /**
     * @param array<mixed> $group
     * @param string $path
     * @param bool $last
     * @param array<array{lead: string, segments: array<string>, tail: string}|string> $lines
     *
     * @return void
     */
    private function group(array $group, string $path, bool $last, array &$lines): void
    {
        $items = is_array($group['children'] ?? null) ? $group['children'] : [];
        $bodyHeaders = ($group['type'] ?? '') === 'table_body' ? self::leadingHeaderRows($items) : 0;
        $rowIndex = 0;
        $lastAt = count($items) - 1;
        foreach ($items as $at => $item) {
            if (!is_array($item)) {
                continue;
            }
            if (($item['type'] ?? '') === 'comment') {
                $lines[] = self::commentLine($item);
                // C2-W-135: a comment that ends the last group is kept in it by an empty continuation row.
                if ($last && $at === $lastAt) {
                    $lines[] = ['lead' => '+', 'segments' => [' '], 'tail' => ''];
                }

                continue;
            }
            $head = ($group['type'] ?? '') === 'table_head';
            foreach ($this->row($item, $path . '/children/' . $at, $head, $rowIndex < $bodyHeaders) as $line) {
                $lines[] = $line;
            }
            $rowIndex++;
        }
    }

    /**
     * C2-W-135: a comment among the rows is a comment-only line.
     *
     * @param array<mixed> $comment
     *
     * @return string
     */
    private static function commentLine(array $comment): string
    {
        $value = rtrim(str_replace("\n", ' ', is_string($comment['value'] ?? null) ? $comment['value'] : ''), " \t");

        return $value === '' ? '%%' : '%% ' . $value;
    }

    /**
     * The lines of one row: the row and its continuation rows (C2-W-067).
     *
     * @param array<mixed> $row
     * @param string $path
     * @param bool $head
     * @param bool $bodyHeader
     *
     * @return array<array{lead: string, segments: array<string>, tail: string}>
     */
    private function row(array $row, string $path, bool $head, bool $bodyHeader): array
    {
        $first = [];
        $continued = [];
        $height = 1;
        foreach ($this->spans->rows[$path] ?? self::rowCells($row, $path) as [$cell, $at]) {
            [$prefix, $fragments] = $this->cell($cell, $at, $head, $bodyHeader);
            $text = $fragments[0] ?? '';
            $first[] = $text === '' ? $prefix . ' ' : $prefix . ' ' . $text . ' ';
            if (count($fragments) > 1) {
                $continued[count($first) - 1] = $fragments;
                $height = max($height, max(array_keys($fragments)) + 1);
            }
        }
        if ($first === []) {
            // C2-W-057: a row with no cell has no spelling, `||` is a row of one empty cell.
            $this->w->lose($path, 'table_row', null);
        }
        $tail = $this->rowAttributes($row, $path);
        $lines = [];
        $lines[] = ['lead' => '|', 'segments' => $first, 'tail' => $tail];
        for ($line = 1; $line < $height; $line++) {
            $segments = array_fill(0, count($first), ' ');
            foreach ($continued as $at => $fragments) {
                $text = $fragments[$line] ?? '';
                if ($text !== '') {
                    $segments[$at] = ' ' . $text . ' ';
                }
            }
            $lines[] = ['lead' => '+', 'segments' => $segments, 'tail' => ''];
        }

        return $lines;
    }

    /**
     * @param array<mixed> $row
     * @param string $path
     *
     * @return \Generator<int, array{0: array<mixed>, 1: string}> Each cell and its path.
     */
    private static function rowCells(array $row, string $path): Generator
    {
        foreach (is_array($row['children'] ?? null) ? $row['children'] : [] as $at => $cell) {
            if (is_array($cell)) {
                yield [$cell, $path . '/children/' . $at];
            }
        }
    }

    /**
     * C2-W-065, C2-W-098: a row attribute glued to the closing pipe.
     *
     * @param array<mixed> $row
     * @param string $path
     *
     * @return string
     */
    private function rowAttributes(array $row, string $path): string
    {
        return BraceBlock::of($row);
    }

    /**
     * The prefix and the content lines of a cell (C2-W-062, C2-W-063).
     *
     * @param array<mixed> $cell
     * @param string $path
     * @param bool $head
     * @param bool $bodyHeader
     *
     * @return array{0: string, 1: array<string>}
     */
    private function cell(array $cell, string $path, bool $head, bool $bodyHeader): array
    {
        $header = !$bodyHeader && (($cell['header'] ?? false) === true || ($head && isset($cell['span']))) ? '=' : '';
        $this->unspellable($cell, $path);
        if (isset($cell['span'])) {
            return [$header . BraceBlock::of($cell, ['pipes' => true]), [$cell['span'] === 'colspan' ? '<' : '^']];
        }
        $prefix = $header . BraceBlock::of($cell, ['mark' => self::alignmentMark($cell), 'pipes' => true]);
        $blocks = is_array($cell['children'] ?? null) ? $cell['children'] : [];
        $lines = $this->registerWraps($path);
        $content = $this->content($blocks, $path);
        // C2-W-063: a cell whose content is only a span mark is written with an escape.
        if ($content === '^' || $content === '<') {
            $content = '\\' . $content;
        }

        $first = is_array($blocks[0] ?? null) ? $blocks[0] : [];
        $inlines = is_array($first['children'] ?? null) ? $first['children'] : [];
        $last = end($inlines);
        $terminalHardBreak = is_array($last) && ($last['type'] ?? '') === 'hard_break';

        return [$prefix, $this->fragments($content, $lines, $path, $terminalHardBreak)];
    }

    /**
     * @param array<mixed> $cell
     */
    private static function alignmentMark(array $cell): string
    {
        $align = is_string($cell['align'] ?? null) ? (self::ALIGN_MARKS[$cell['align']] ?? '') : '';
        $valign = is_string($cell['valign'] ?? null) ? (self::VALIGN_MARKS[$cell['valign']] ?? '') : '';

        return $valign === '' ? $align : ($align === '' ? '?' : $align) . $valign;
    }

    /**
     * C2-W-011: the cell fields no source spells.
     *
     * @param array<mixed> $cell
     * @param string $path
     *
     * @return void
     */
    private function unspellable(array $cell, string $path): void
    {
        foreach (['scope', 'headerIds'] as $field) {
            if (isset($cell[$field])) {
                $this->w->lose($path, 'table_cell', $field);
            }
        }
        if (($cell['valign'] ?? null) === 'baseline') {
            $this->w->lose($path, 'table_cell', 'valign');
        }
        $blocks = is_array($cell['children'] ?? null) ? $cell['children'] : [];
        if (count($blocks) > 1 || (is_array($blocks[0] ?? null) && ($blocks[0]['type'] ?? '') !== 'cell_paragraph')) {
            $this->w->lose($path, 'table_cell', null);
        }
    }

    /**
     * C2-W-012: the blocks of a cell as one line of inline content.
     *
     * @param array<mixed> $blocks
     * @param string $path
     *
     * @return string
     */
    private function content(array $blocks, string $path): string
    {
        $parts = [];
        foreach ($blocks as $at => $block) {
            $inlines = is_array($block) && is_array($block['children'] ?? null) ? $block['children'] : [];
            $c = new Context();
            $c->inCell = true;
            $c->blockLines = false;
            $c->counts = Inline::counts($inlines);
            $parts[] = $this->inline->content($inlines, $path . '/children/' . $at . '/children', $c, -1);
        }

        return implode(' ', $parts);
    }

    /**
     * The fragments of a cell by continuation line (C2-L-020); without wraps a line break has no spelling (C2-W-067).
     *
     * @param string $content
     * @param array<int> $lines
     * @param string $path
     * @param bool $terminalHardBreak
     *
     * @return array<int, string>
     */
    private function fragments(string $content, array $lines, string $path, bool $terminalHardBreak): array
    {
        if ($lines !== []) {
            $fragments = [];
            foreach (explode("\n", $content) as $k => $fragment) {
                $fragments[$k === 0 ? 0 : ($lines[$k - 1] ?? $k)] = $fragment;
            }

            return $fragments;
        }
        if (str_contains($content, "\n")) {
            if (!$terminalHardBreak || !str_ends_with($content, "\\\n") || substr_count($content, "\n") !== 1) {
                $this->w->lose($path, 'table_cell', 'children');
            }
            $content = str_replace("\n", ' ', $content);
        }

        return [$content];
    }

    /**
     * C2-L-020: the wraps of a cell, as split points of its text nodes; returns the continuation line of each.
     *
     * @param string $path
     *
     * @return array<int>
     */
    private function registerWraps(string $path): array
    {
        $wraps = $this->w->fact($path, 'wraps');
        if (!is_array($wraps)) {
            return [];
        }
        $lines = [];
        foreach ($wraps as $wrap) {
            if (
                !is_array($wrap) || !is_int($wrap['line'] ?? null) || !is_string($wrap['path'] ?? null) || !is_int($wrap['offset'] ?? null)
                || isset($wrap['row'])
            ) {
                continue;
            }
            $this->w->wrapPoints[$wrap['path']][] = $wrap['offset'];
            $lines[] = $wrap['line'];
        }

        return $lines;
    }

    /**
     * @param array{lead: string, segments: array<string>, tail: string} $line
     *
     * @return string
     */
    private static function joined(array $line): string
    {
        return $line['lead'] . implode('|', $line['segments']) . '|' . $line['tail'];
    }

    /**
     * C2-W-118: with `alignTables` each segment is padded to the width of its column.
     *
     * @param array<array{lead: string, segments: array<string>, tail: string}|string> $lines
     *
     * @return array<array{lead: string, segments: array<string>, tail: string}|string>
     */
    private static function aligned(array $lines): array
    {
        $widths = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            foreach ($line['segments'] as $at => $segment) {
                $widths[$at] = max($widths[$at] ?? 0, Width::of($segment));
            }
        }
        foreach ($lines as $l => $line) {
            if (!is_array($line)) {
                continue;
            }
            $segments = [];
            foreach ($line['segments'] as $at => $segment) {
                $segments[] = $segment . str_repeat(' ', $widths[$at] - Width::of($segment));
            }
            $line['segments'] = $segments;
            $lines[$l] = $line;
        }

        return $lines;
    }

    /**
     * C2-W-005: a cell text with a line break or with whitespace at an end needs the delimited form.
     *
     * @param array<mixed> $node
     *
     * @return bool
     */
    private function pipeCannotSpell(array $node): bool
    {
        foreach ($this->groups($node) as $rows) {
            foreach ($rows as $row) {
                foreach (is_array($row['children'] ?? null) ? $row['children'] : [] as $cell) {
                    $text = is_array($cell) ? self::plainCell($cell) : null;
                    if ($text !== null && $text !== '' && (str_contains($text, "\n") || trim($text, " \t") !== $text)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * C2-W-066: a delimited table needs cells of plain text, its head as its first rows and no spans.
     *
     * @param array<mixed> $node
     *
     * @return bool
     */
    private function allowsDelimited(array $node): bool
    {
        foreach ($this->groups($node) as $type => $rows) {
            foreach ($rows as $row) {
                if (isset($row['attrs'])) {
                    return false;
                }
                foreach (is_array($row['children'] ?? null) ? $row['children'] : [] as $cell) {
                    $header = is_array($cell) && ($cell['header'] ?? false) === true;
                    if (!is_array($cell) || self::plainCell($cell) === null || $header !== ($type === 'table_head')) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * @param array<mixed> $cell
     *
     * @return string|null
     */
    private static function plainCell(array $cell): ?string
    {
        if (isset($cell['span']) || isset($cell['attrs']) || isset($cell['align']) || isset($cell['valign'])) {
            return null;
        }
        $blocks = is_array($cell['children'] ?? null) ? $cell['children'] : [];
        if ($blocks === []) {
            return '';
        }
        if (count($blocks) !== 1 || !is_array($blocks[0]) || ($blocks[0]['type'] ?? '') !== 'cell_paragraph') {
            return null;
        }
        $inlines = is_array($blocks[0]['children'] ?? null) ? $blocks[0]['children'] : [];
        if ($inlines === []) {
            return '';
        }
        if (count($inlines) !== 1 || !is_array($inlines[0]) || ($inlines[0]['type'] ?? '') !== 'text' || count($inlines[0]) > 2) {
            return null;
        }

        return Inline::str($inlines[0], 'value');
    }

    /**
     * C2-W-066: a delimited table; a cell with the separator, a quote or a line break is quoted.
     *
     * @param array<mixed> $node
     * @param string $path
     * @param string $prefix
     * @param string $format
     * @param bool $prewritten
     *
     * @return void
     */
    private function delimited(array $node, string $path, string $prefix, string $format, bool $prewritten): void
    {
        if (!$prewritten) {
            $this->w->emit($prefix, $this->attributeLine($node, $path, true));
        }
        $separator = $format === 'tsv' ? "\t" : ',';
        $body = [];
        $all = array_merge(...array_values($this->groups($node)));
        $last = count($all) - 1;
        foreach ($all as $r => $row) {
            $cells = is_array($row['children'] ?? null) ? $row['children'] : [];
            $out = [];
            foreach ($cells as $cell) {
                $value = is_array($cell) ? (self::plainCell($cell) ?? '') : '';
                $quote = strpbrk($value, $separator . "\"\n\r") !== false || ($value === '' && count($cells) === 1 && $r === $last);
                $out[] = $quote ? '"' . str_replace('"', '""', $value) . '"' : $value;
            }
            $body[] = implode($separator, $out);
        }
        $lines = explode("\n", implode("\n", $body));
        $run = 3;
        foreach ($lines as $line) {
            $trimmed = ltrim($line, " \t");
            $n = strspn($trimmed, '`');
            if ($n >= $run && trim(substr($trimmed, $n)) === '') {
                $run = $n + 1;
            }
        }
        $fence = str_repeat('`', $run);
        $this->w->emit($prefix, $fence . $format);
        if ($body !== []) {
            foreach ($lines as $line) {
                $this->w->lines[] = $prefix . $line;
            }
        }
        $this->w->emit($prefix, $fence);
    }
}
