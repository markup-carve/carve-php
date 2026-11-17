<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Block;

use MarkupCarve\Carve2\AttributeParser;
use MarkupCarve\Carve2\Attrs;
use MarkupCarve\Carve2\Generated\Tables;
use MarkupCarve\Carve2\Node;

/**
 * Pipe tables (C2-B-166 to C2-B-187) and delimited tables (C2-B-180, C2-B-181, C2-B-209).
 * Rows are recorded on the table frame while the lines are read; the nodes are made at close,
 * when the head, the spans and the footer are known (C2-B-191).
 */
final class TableBuilder
{
    /**
     * Splits a table row (C2-B-166, C2-B-167, C2-B-169). Returns the separator offsets up to the
     * closing pipe, the row end and the row attributes, or null when the line is no row.
     *
     * @param string $s
     * @param int $j Offset of the first pipe or of the `+` of a continuation row.
     * @param int $le
     *
     * @return array{seps: array<int>, end: int, attrs: \MarkupCarve\Carve2\Attrs|null, comment: int|null}|null
     */
    public static function split(string $s, int $j, int $le): ?array
    {
        $end = LineShapes::trimEnd($s, $j, $le);
        $seps = [];
        $k = $j + 1;
        while ($k < $end) {
            $k += strcspn($s, '|\\`', $k, $end - $k);
            if ($k >= $end) {
                break;
            }
            $ch = $s[$k];
            if ($ch === '\\') {
                $k += 2;

                continue;
            }
            if ($ch === '`') {
                $run = strspn($s, '`', $k, $end - $k);
                $close = self::findRun($s, $k + $run, $end, $run);
                $k = $close < 0 ? $k + $run : $close + $run;

                continue;
            }
            $seps[] = $k;
            $k++;
        }
        // C2-B-166: the last separator is the closing pipe; only a row attribute and a line comment follow it.
        $n = count($seps) - 1;
        if ($n < 0) {
            return null;
        }
        $attrs = null;
        $rowEnd = self::tail($s, $seps[$n], $end, $attrs);

        $commentAt = $rowEnd >= 0 && $rowEnd < $end
            ? $rowEnd + strspn($s, " \t", $rowEnd, $end - $rowEnd)
            : null;
        // C2-B-166: a pipe in a trailing comment is still later than the proposed closing pipe.
        if ($commentAt !== null && strcspn($s, '|', $commentAt, $end - $commentAt) < $end - $commentAt) {
            return null;
        }

        return $rowEnd < 0 ? null : [
            'seps' => $seps,
            'end' => $commentAt === null ? $le : $rowEnd,
            'attrs' => $attrs,
            'comment' => $commentAt,
        ];
    }

    /**
     * After the closing pipe: a row attribute glued or after one space, then a line comment.
     * Returns the end of the row without the comment, or -1.
     *
     * @param string $s
     * @param int $p
     * @param int $end
     * @param \MarkupCarve\Carve2\Attrs|null $attrs
     *
     * @return int
     */
    private static function tail(string $s, int $p, int $end, ?Attrs &$attrs): int
    {
        $k = $p + 1;
        $rowEnd = $k;
        $b = $k < $end && $s[$k] === '{' ? $k : ($k + 1 < $end && $s[$k] === ' ' && $s[$k + 1] === '{' ? $k + 1 : -1);
        if ($b >= 0) {
            $a = new Attrs();
            while ($b < $end && $s[$b] === '{') {
                $next = AttributeParser::read($s, $b, $end, $a);
                if ($next < 0 || AttributeParser::$lastEmpty) {
                    return -1;
                }
                $k = $next;
                $b = $next;
            }
            $attrs = $a;
            $rowEnd = $k;
        }
        if ($k === $end) {
            return $rowEnd;
        }
        // C2-B-133, C2-B-167: `%%` after whitespace starts a line comment, also as the head of a longer run.
        $ws = strspn($s, " \t", $k, $end - $k);
        if ($ws > 0 && strspn($s, '%', $k + $ws, $end - $k - $ws) >= 2) {
            return $rowEnd;
        }

        return -1;
    }

    /**
     * @param string $s
     * @param int $k
     * @param int $le
     * @param int $run
     *
     * @return int
     */
    private static function findRun(string $s, int $k, int $le, int $run): int
    {
        while ($k < $le) {
            $p = strpos($s, '`', $k);
            if ($p === false || $p >= $le) {
                return -1;
            }
            $n = strspn($s, '`', $p, $le - $p);
            if ($n === $run) {
                return $p;
            }
            $k = $p + $n;
        }

        return -1;
    }

    /**
     * Reads the cells of a split row (C2-B-171, C2-B-172, C2-B-182).
     *
     * @param string $s
     * @param int $j
     * @param array<int> $seps
     *
     * @return array<\MarkupCarve\Carve2\Block\TableCell>
     */
    public static function cells(string $s, int $j, array $seps): array
    {
        $cells = [];
        $start = $j + 1;
        foreach ($seps as $sep) {
            $cells[] = self::cell($s, $start, $sep);
            $start = $sep + 1;
        }

        return $cells;
    }

    /**
     * @param string $s
     * @param int $start
     * @param int $end
     *
     * @return \MarkupCarve\Carve2\Block\TableCell
     */
    private static function cell(string $s, int $start, int $end): TableCell
    {
        $cell = new TableCell($start, $end);
        $k = $start;
        if ($k < $end && $s[$k] === '=') {
            $cell->header = true;
            $k++;
        }
        while ($k < $end && $s[$k] === '{') {
            $next = self::cellBrace($s, $k, $end, $cell);
            if ($next === $k) {
                break;
            }
            $k = $next;
        }
        $k += strspn($s, " \t", $k, $end - $k);
        $ce = LineShapes::trimEnd($s, $k, $end);
        $cell->cs = $k;
        $cell->ce = $ce;
        if ($ce - $k === 1 && ($s[$k] === '<' || $s[$k] === '^')) {
            $cell->mark = $s[$k];
        }

        return $cell;
    }

    /**
     * C2-B-182: the alignment mark comes first in the brace block of a cell prefix.
     *
     * @param string $s
     * @param int $k
     * @param int $end
     * @param \MarkupCarve\Carve2\Block\TableCell $cell
     *
     * @return int
     */
    private static function cellBrace(string $s, int $k, int $end, TableCell $cell): int
    {
        $align = null;
        $from = $k;
        if ($k + 1 < $end && isset(Tables::ALIGNMENT_MARKS[$s[$k + 1]])) {
            $align = Tables::ALIGNMENT_MARKS[$s[$k + 1]];
            if ($k + 2 < $end && $s[$k + 2] === '}') {
                $cell->align = $align;

                return $k + 3;
            }
            $from = $k + 1;
        }
        $attrs = new Attrs();
        $next = AttributeParser::read($s, $from, $end, $attrs);
        if ($next < 0 || ($align === null && AttributeParser::$lastEmpty)) {
            return $k;
        }
        if ($align !== null) {
            $cell->align = $align;
        }
        if (!$attrs->isEmpty()) {
            $cell->attrs ??= new Attrs();
            $cell->attrs->merge($attrs);
        }

        return $next;
    }

    /**
     * Splits a continuation row into fragment content spans (C2-B-175, C2-B-185).
     *
     * @param string $s
     * @param int $j
     * @param array<int> $seps
     *
     * @return array<int>
     */
    public static function fragments(string $s, int $j, array $seps): array
    {
        $out = [];
        $start = $j + 1;
        foreach ($seps as $sep) {
            $k = $start + strspn($s, " \t", $start, $sep - $start);
            $out[] = $k;
            $out[] = LineShapes::trimEnd($s, $k, $sep);
            $start = $sep + 1;
        }

        return $out;
    }

    /**
     * Makes the table node of a pipe table frame.
     *
     * @param \MarkupCarve\Carve2\Block\Frame $f
     *
     * @return \MarkupCarve\Carve2\Node
     */
    public static function pipe(Frame $f): Node
    {
        $rows = [];
        foreach ($f->rows as $item) {
            if ($item->isRow) {
                $rows[] = $item;
            }
        }
        $head = self::headRows($rows);
        $foot = self::count($f->attrs, 'footer-rows');
        $footStart = $foot === null || $foot <= 0 ? count($rows) : max($head, count($rows) - $foot);
        $grid = self::spans($rows, $head, $footStart);
        $width = 0;
        $rowNodes = [];
        foreach ($grid as $r => $cells) {
            $width = max($width, count($cells));
            $rowNodes[] = self::rowNode($rows[$r], $cells);
        }
        $table = Node::at('table', $f->start, $f->end);
        $table->layout['sourceFormat'] = 'pipe';
        $attrs = $f->attrs;
        $table->f['columns'] = self::columns($width, $attrs);
        $table->children = self::groups($f->rows, $rowNodes, $head, $foot);
        if ($attrs !== null) {
            self::consumeKeys($attrs, false);
            $attrs->applyTo($table);
        }

        return $table;
    }

    /**
     * @param array<\MarkupCarve\Carve2\Block\TableItem> $rows
     *
     * @return int
     */
    private static function headRows(array $rows): int
    {
        $head = 0;
        $above = [];
        $aboveSingle = [];
        foreach ($rows as $row) {
            $current = [];
            $single = [];
            $cover = [];
            $tall = [];
            $hasHeader = false;
            $hasOther = false;
            foreach ($row->cells as $c => $cell) {
                $left = $cover[$c - 1] ?? null;
                if ($cell->mark === '<' && $left !== null && !($tall[$left] ?? false)) {
                    $current[$c] = $current[$left];
                    $cover[$c] = $left;
                    $single[$c] = false;
                    $single[$left] = false;
                    $hasHeader = $hasHeader || $cell->header;

                    continue;
                }
                $aboveCell = $head > 0 && ($above[$c] ?? false) && ($aboveSingle[$c] ?? false);
                $validUp = $cell->mark === '^' && $aboveCell;
                if ($validUp) {
                    $hasHeader = $hasHeader || $cell->header;
                } elseif ($cell->header) {
                    $hasHeader = true;
                } else {
                    $hasOther = true;
                }
                $current[$c] = $validUp ? $above[$c] : $cell->header;
                $cover[$c] = $c;
                $single[$c] = true;
                $tall[$c] = $validUp;
            }
            if (!$hasHeader || $hasOther) {
                break;
            }
            $head++;
            $above = $current;
            $aboveSingle = $single;
        }

        return $head;
    }

    /**
     * C2-B-172 to C2-B-174, C2-B-183, C2-B-184: resolves the span marks, then joins the fragments of
     * continuation rows. Returns the cells of each row.
     *
     * @param array<\MarkupCarve\Carve2\Block\TableItem> $rows
     * @param int $head
     * @param int $footStart
     *
     * @return array<int, array<int, \MarkupCarve\Carve2\Block\TableCell>>
     */
    private static function spans(array $rows, int $head, int $footStart): array
    {
        $grid = [];
        $cover = [];
        foreach ($rows as $r => $row) {
            $grid[$r] = [];
            $cover[$r] = [];
            foreach ($row->cells as $c => $cell) {
                $cell->row = $r;
                $cell->col = $c;
                $cell->lines = $cell->ce > $cell->cs ? [$cell->cs, $cell->ce] : [];
                $cell->wrapAt = $cell->lines === [] ? [] : [[0, 0]];
                $grid[$r][$c] = $cell;
                $cover[$r][$c] = $cell;
                if ($cell->mark === '') {
                    continue;
                }
                $target = self::spanTarget($cell, $cover, $head, $footStart);
                if ($target === null) {
                    // C2-B-173: a blocked mark is ordinary cell text, with its authored prefix.
                    $cell->mark = '';

                    continue;
                }
                if ($cell->mark === '<') {
                    $target->colspan++;
                    $cell->span = 'colspan';
                } else {
                    $target->rowspan++;
                    $cell->span = 'rowspan';
                }
                $cover[$r][$c] = $target;
            }
            foreach ($row->cont as $n => $fragments) {
                self::join($grid[$r], $cover[$r], [$r, $n + 1, $row->contSlots[$n] ?? []], $fragments);
            }
        }

        return $grid;
    }

    /**
     * The cell a span mark joins (C2-B-172, C2-B-174, C2-B-183), or null when the span fails.
     *
     * @param \MarkupCarve\Carve2\Block\TableCell $cell
     * @param array<int, array<int, \MarkupCarve\Carve2\Block\TableCell>> $cover
     * @param int $head
     * @param int $footStart
     *
     * @return \MarkupCarve\Carve2\Block\TableCell|null
     */
    private static function spanTarget(TableCell $cell, array $cover, int $head, int $footStart): ?TableCell
    {
        $r = $cell->row;
        $c = $cell->col;
        if ($cell->mark === '<') {
            $t = $cover[$r][$c - 1] ?? null;

            return $t !== null && $t->rowspan === 1 && $t->col + $t->colspan === $c ? $t : null;
        }
        if ($r === 0 || $r === $head || $r === $footStart) {
            return null;
        }
        $t = $cover[$r - 1][$c] ?? null;

        return $t !== null && $t->colspan === 1 && $t->row + $t->rowspan === $r ? $t : null;
    }

    /**
     * C2-B-176, C2-B-184: each fragment joins the cell that covers its column; one past the last column forms a new cell.
     *
     * @param array<int, \MarkupCarve\Carve2\Block\TableCell> $cells
     * @param array<int, \MarkupCarve\Carve2\Block\TableCell> $cover
     * @param array{0: int, 1: int, 2: array<int>} $at The row, the number of its continuation row and its slot starts.
     * @param array<int> $fragments
     *
     * @return void
     */
    private static function join(array &$cells, array &$cover, array $at, array $fragments): void
    {
        [$r, $line, $slots] = $at;
        $n = count($fragments);
        for ($k = 0; $k < $n; $k += 2) {
            $c = intdiv($k, 2);
            if (!isset($cover[$c])) {
                // C2-L-048: a cell that starts in a continuation row ranges from its first fragment.
                $cell = new TableCell($slots[$c] ?? $fragments[$k], -1);
                $cell->row = $r;
                $cell->col = $c;
                $cells[$c] = $cell;
                $cover[$c] = $cell;
            }
            if ($fragments[$k + 1] <= $fragments[$k]) {
                continue;
            }
            $target = $cover[$c];
            $target->joined = true;
            $target->lines[] = $fragments[$k];
            $target->lines[] = $fragments[$k + 1];
            $target->wrapAt[] = [$line, $r - $target->row];
        }
    }

    /**
     * @param \MarkupCarve\Carve2\Block\TableItem $row
     * @param array<int, \MarkupCarve\Carve2\Block\TableCell> $cells
     *
     * @return \MarkupCarve\Carve2\Node
     */
    private static function rowNode(TableItem $row, array $cells): Node
    {
        // C2-A-158: a row that a continuation row joined carries no position.
        $node = $row->cont !== [] ? new Node('table_row', []) : Node::at('table_row', $row->start, $row->end);
        if ($row->cont !== []) {
            // C2-L-013, C2-L-019: the range from the row to its last continuation row.
            $node->layout['continuationLines'] = count($row->cont);
            $node->layout['startByte'] = $row->start;
            $node->layout['endByte'] = max($row->end, $row->continuationEnd);
        }
        $node->children = [];
        foreach ($cells as $cell) {
            $node->children[] = self::cellNode($cell);
        }
        $row->attrs?->applyTo($node);

        return $node;
    }

    /**
     * @param \MarkupCarve\Carve2\Block\TableCell $cell
     *
     * @return \MarkupCarve\Carve2\Node
     */
    private static function cellNode(TableCell $cell): Node
    {
        if ($cell->span !== '') {
            // C2-A-158: a cell that holds a span mark spans the text between its pipes.
            $node = Node::at('table_cell', $cell->start, $cell->end);
            $node->children = [];
            $node->f['header'] = false;
            $node->f['span'] = $cell->span;

            return $node;
        }
        $bare = $cell->joined || $cell->start < 0;
        $node = $bare ? new Node('table_cell') : Node::at('table_cell', $cell->start, $cell->end);
        $node->f['header'] = $cell->header;
        if ($cell->colspan > 1) {
            $node->f['colspan'] = $cell->colspan;
        }
        if ($cell->rowspan > 1) {
            $node->f['rowspan'] = $cell->rowspan;
        }
        if ($cell->align !== null) {
            $node->f['align'] = $cell->align;
        }
        $lines = $cell->lines;
        $para = $cell->joined || $lines === [] ? new Node('cell_paragraph') : Node::at('cell_paragraph', $lines[0], $lines[1]);
        $para->lines = $lines;
        if ($cell->joined) {
            $para->layout['wrapAt'] = $cell->wrapAt;
            $para->layout['spansRows'] = $cell->rowspan > 1;
            // C2-L-013: the range of a joined cell, from its own row to the end of its last fragment.
            $node->layout['startByte'] = $cell->start;
            $node->layout['contentEnd'] = $lines[count($lines) - 1];
        }
        $node->children = [$para];
        $cell->attrs?->applyTo($node);

        return $node;
    }

    /**
     * C2-B-211, C2-B-212, C2-B-220: the columns with their authored alignment and width.
     *
     * @param int $width
     * @param \MarkupCarve\Carve2\Attrs|null $attrs
     *
     * @return array<\MarkupCarve\Carve2\Node>
     */
    private static function columns(int $width, ?Attrs $attrs): array
    {
        $aligns = $attrs?->get('aligns');
        $widths = $attrs?->get('widths');
        $alignList = self::validList($aligns, false) ? explode(',', (string)$aligns) : [];
        $widthList = self::validList($widths, true) ? explode(',', (string)$widths) : [];
        $columns = [];
        for ($c = 0; $c < $width; $c++) {
            $col = new Node('table_column');
            $a = trim($alignList[$c] ?? '', " \t");
            if ($a === 'left' || $a === 'right' || $a === 'center') {
                $col->f['align'] = $a;
            }
            $w = trim($widthList[$c] ?? '', " \t");
            // C2-B-220: anchored, no nested repetition, linear; once per column of a table.
            if ($w !== '' && preg_match('/^[0-9]+(\.[0-9]+)?$/', $w) === 1 && (float)$w > 0 && (float)$w <= 100) {
                $col->f['width'] = (float)$w / 100;
            }
            $columns[] = $col;
        }

        return $columns;
    }

    /**
     * C2-B-211, C2-B-212: every list entry must be valid before any column changes.
     *
     * @param string|null $value
     * @param bool $width
     *
     * @return bool
     */
    private static function validList(?string $value, bool $width): bool
    {
        if ($value === null || $value === '') {
            return false;
        }
        foreach (explode(',', $value) as $entry) {
            $entry = trim($entry, " \t");
            if ($width) {
                // C2-B-220: anchored decimal entry, with no nested repetition.
                if (preg_match('/^[0-9]+(\.[0-9]+)?$/', $entry) !== 1 || (float)$entry <= 0 || (float)$entry > 100) {
                    return false;
                }
            } elseif ($entry !== 'left' && $entry !== 'right' && $entry !== 'center') {
                return false;
            }
        }

        return true;
    }

    /**
     * C2-B-227: only valid and applicable keys are consumed.
     *
     * @param \MarkupCarve\Carve2\Attrs $attrs
     * @param bool $delimited
     *
     * @return void
     */
    private static function consumeKeys(Attrs $attrs, bool $delimited): void
    {
        foreach (['aligns', 'widths'] as $key) {
            if (self::validList($attrs->get($key), $key === 'widths')) {
                $attrs->remove($key);
            }
        }
        if (self::count($attrs, 'footer-rows') !== null) {
            $attrs->remove('footer-rows');
        }
        if ($delimited && self::count($attrs, 'header-rows') !== null) {
            $attrs->remove('header-rows');
        }
    }

    /**
     * C2-B-187, C2-B-210: a count attribute: one or more digits, empty counts as 1, else null.
     *
     * @param \MarkupCarve\Carve2\Attrs|null $attrs
     * @param string $key
     *
     * @return int|null
     */
    public static function count(?Attrs $attrs, string $key): ?int
    {
        $v = $attrs?->get($key);
        if ($v === null) {
            return null;
        }
        if ($v === '') {
            return 1;
        }

        return ctype_digit($v) ? (int)$v : null;
    }

    /**
     * Distributes rows and comments over head, body and foot (C2-A-086, C2-B-187).
     *
     * @param array<\MarkupCarve\Carve2\Block\TableItem> $items
     * @param array<\MarkupCarve\Carve2\Node> $rowNodes
     * @param int $head
     * @param int|null $foot
     *
     * @return array<\MarkupCarve\Carve2\Node>
     */
    private static function groups(array $items, array $rowNodes, int $head, ?int $foot): array
    {
        $total = count($rowNodes);
        $footStart = $foot === null || $foot <= 0 ? $total : max($head, $total - $foot);
        $groups = [];
        $trailing = [];
        $current = null;
        $lastRow = null;
        $kind = '';
        $r = 0;
        foreach ($items as $item) {
            if (!$item->isRow) {
                $comment = Node::at('comment', $item->start, $item->end);
                $comment->f['form'] = 'line';
                $comment->f['value'] = NodeFactory::v($item->value);
                // C2-A-086: a comment before a continuation stays in the row's group.
                $beforeContinuation = $lastRow !== null && $lastRow->continuationEnd > $lastRow->end
                    && $item->start < $lastRow->continuationEnd;
                if (($r >= $total && !$beforeContinuation) || $current === null) {
                    $trailing[] = $comment;

                    continue;
                }
                $current->children[] = $comment;
                $current->end = max($current->end, $item->end);

                continue;
            }
            $want = $r < $head ? 'table_head' : ($r >= $footStart ? 'table_foot' : 'table_body');
            if ($want !== $kind) {
                $current = new Node($want, []);
                $groups[] = $current;
                $kind = $want;
            }
            assert($current !== null);
            $current->children[] = $rowNodes[$r];
            $lastRow = $item;
            if ($item->start >= 0) {
                if ($current->start < 0) {
                    $current->start = $item->start;
                }
                $current->end = max($item->end, $item->continuationEnd);
            }
            $r++;
        }

        return array_merge($groups, $trailing);
    }

    /**
     * Makes the table of a delimited fence (C2-B-180, C2-B-181, C2-B-209, C2-B-221).
     *
     * @param \MarkupCarve\Carve2\Block\Frame $f
     * @param string $body
     * @param string $sep
     *
     * @return \MarkupCarve\Carve2\Node
     */
    public static function delimited(Frame $f, string $body, string $sep): Node
    {
        $malformed = false;
        $records = self::records($body, $sep, $malformed);
        if ($malformed) {
            // C2-B-181
            $f->findings[] = ['delimited-table-malformed', -1];
        }
        $attrs = $f->attrs;
        $declared = self::count($attrs, 'header-rows');
        $total = count($records);
        $head = min($declared ?? 1, $total);
        $width = 0;
        $rowNodes = [];
        $items = [];
        foreach ($records as $r => $fields) {
            $width = max($width, count($fields));
            $row = new Node('table_row', []);
            foreach ($fields as $value) {
                $cell = new Node('table_cell', []);
                $cell->f['header'] = $r < $head;
                $para = new Node('cell_paragraph', []);
                if ($value !== '') {
                    $text = new Node('text');
                    $text->f['value'] = $value;
                    $para->children[] = $text;
                }
                $cell->children[] = $para;
                $row->children[] = $cell;
            }
            $rowNodes[] = $row;
            $items[] = new TableItem(true, -1, -1);
        }
        $table = Node::at('table', $f->start, $f->end);
        // C2-L-023, C2-L-057
        $table->layout['sourceFormat'] = $sep === ',' ? 'csv' : 'tsv';
        if ($declared !== null) {
            $table->layout['headerRows'] = $head;
        }
        $table->f['columns'] = self::columns($width, $attrs);
        $table->children = self::groups($items, $rowNodes, $head, self::count($attrs, 'footer-rows'));
        foreach ($table->children as $group) {
            $group->start = -1;
            $group->end = -1;
        }
        if ($attrs !== null) {
            $attrs->remove('table');
            self::consumeKeys($attrs, true);
            $attrs->applyTo($table);
        }

        return $table;
    }

    /**
     * Splits a delimited body into records of fields; a double quote quotes a field, `""` is a quote.
     *
     * @param string $body
     * @param string $sep
     * @param bool $malformed Set when a quote is not closed (C2-B-181).
     *
     * @return array<array<string>>
     */
    private static function records(string $body, string $sep, bool &$malformed = false): array
    {
        $records = [];
        $fields = [];
        $field = '';
        $n = strlen($body);
        $k = 0;
        $recordStart = 0;
        $emptyLine = false;
        $stop = $sep . "\n\"";
        while ($k < $n) {
            $m = strcspn($body, $stop, $k);
            $field .= substr($body, $k, $m);
            $k += $m;
            if ($k >= $n) {
                break;
            }
            $ch = $body[$k];
            if ($ch === '"' && $field !== '') {
                $field .= '"';
                $k++;

                continue;
            }
            if ($ch === '"') {
                $k = self::quotedField($body, $k + 1, $field, $malformed);

                continue;
            }
            $fields[] = $field;
            $field = '';
            $k++;
            if ($ch === "\n") {
                $emptyLine = $k - 1 === $recordStart;
                $recordStart = $k;
                $records[] = $fields;
                $fields = [];
            }
        }
        if ($field !== '' || $fields !== []) {
            $fields[] = $field;
            $records[] = $fields;
            $emptyLine = false;
        }
        // C2-B-226: only an empty line at the end is dropped, not a quoted empty cell (C2-W-066).
        if ($emptyLine && str_ends_with($body, "\n")) {
            array_pop($records);
        }

        return $records;
    }

    /**
     * C2-B-181: an unclosed quote runs to the end of the body, without its final line ending.
     *
     * @param string $body
     * @param int $k
     * @param string $field
     * @param bool $malformed
     *
     * @return int
     */
    private static function quotedField(string $body, int $k, string &$field, bool &$malformed): int
    {
        $n = strlen($body);
        while ($k < $n) {
            $q = strpos($body, '"', $k);
            if ($q === false) {
                $malformed = true;
                $rest = substr($body, $k);
                $field .= str_ends_with($rest, "\n") ? substr($rest, 0, -1) : $rest;

                return $n;
            }
            $field .= substr($body, $k, $q - $k);
            if ($q + 1 < $n && $body[$q + 1] === '"') {
                $field .= '"';
                $k = $q + 2;

                continue;
            }

            return $q + 1;
        }
        $malformed = true;

        return $n;
    }
}
