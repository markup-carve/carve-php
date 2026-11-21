<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Block;

use MarkupCarve\Carve\AttributeParser;
use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Generated\Tables;
use MarkupCarve\Carve\Node;

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
     * @return array{seps: array<int>, end: int, attrs: \MarkupCarve\Carve\Attrs|null, comment: int|null}|null
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
            // Only a pipe before `{` or ` {` can carry row attributes; tail() reads them.
            $b = $k + 1 < $end ? $s[$k + 1] : '';
            if ($b === '{' || $b === ' ' && $k + 2 < $end && $s[$k + 2] === '{') {
                $rowAttrs = null;
                $afterAttrs = self::tail($s, $k, $end, $rowAttrs);
                if ($afterAttrs >= 0 && $rowAttrs !== null) {
                    $seps[] = $k;
                    $k = $afterAttrs;

                    continue;
                }
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
     * @param \MarkupCarve\Carve\Attrs|null $attrs
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
     * @return array<\MarkupCarve\Carve\Block\TableCell>
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
     * @return \MarkupCarve\Carve\Block\TableCell
     */
    private static function cell(string $s, int $start, int $end): TableCell
    {
        $cell = new TableCell($start, $end);
        $k = $start;
        if ($k < $end && $s[$k] === '=' && ($k + 1 === $end || str_contains(" \t{<>?~", $s[$k + 1]))) {
            $cell->header = true;
            $k++;
        }
        $first = true;
        while ($k < $end && $s[$k] === '{') {
            $next = self::cellBrace($s, $k, $end, $cell, $first);
            if ($next === $k) {
                break;
            }
            $k = $next;
            $first = false;
        }
        $k += strspn($s, " \t", $k, $end - $k);
        $ce = $end;
        while ($ce > $k && ($s[$ce - 1] === ' ' || $s[$ce - 1] === "\t")) {
            $ce--;
        }
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
     * @param \MarkupCarve\Carve\Block\TableCell $cell
     * @param bool $first
     *
     * @return int
     */
    private static function cellBrace(string $s, int $k, int $end, TableCell $cell, bool $first): int
    {
        $from = $k + 1;
        $align = null;
        $valign = null;
        if ($first && $from < $end && isset(Tables::ALIGNMENT_MARKS[$s[$from]])) {
            $horizontal = $s[$from];
            $vertical = $s[$from + 1] ?? '';
            $verticalMarks = ['^' => 'top', 'v' => 'bottom', '~' => 'middle'];
            if ($horizontal !== '?' || isset($verticalMarks[$vertical])) {
                $align = $horizontal === '?' ? null : Tables::ALIGNMENT_MARKS[$horizontal];
                $from++;
                if (isset($verticalMarks[$vertical])) {
                    $valign = $verticalMarks[$vertical];
                    $from++;
                }
            }
        }
        $attrs = new Attrs();
        if ($from === $k + 1) {
            $next = AttributeParser::read($s, $k, $end, $attrs);
        } else {
            $synthetic = '{' . substr($s, $from, $end - $from);
            $read = AttributeParser::read($synthetic, 0, strlen($synthetic), $attrs);
            $next = $read < 0 ? -1 : $from + $read - 1;
        }
        if ($next < 0 || ($align === null && $valign === null && AttributeParser::$lastEmpty)) {
            return $k;
        }
        if ($align !== null) {
            $cell->align = $align;
        }
        if ($valign !== null) {
            $cell->valign = $valign;
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
     * @param \MarkupCarve\Carve\Block\Frame $f
     *
     * @return \MarkupCarve\Carve\Node
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
        $plan = self::bodyPlan($f->attrs, $footStart - $head);
        $grid = self::spans($rows, $head, $footStart, self::bodyStarts($plan, $head));
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
        $table->children = self::partitionBodies(self::groups($f->rows, $rowNodes, $head, $foot), $plan);
        if ($attrs !== null) {
            self::consumeKeys($attrs, false, $plan);
            $attrs->applyTo($table);
        }

        return $table;
    }

    /**
     * @param array<\MarkupCarve\Carve\Block\TableItem> $rows
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
     * @param array<\MarkupCarve\Carve\Block\TableItem> $rows
     * @param int $head
     * @param int $footStart
     * @param array<int, true> $bodyStarts
     *
     * @return array<int, array<int, \MarkupCarve\Carve\Block\TableCell>>
     */
    private static function spans(array $rows, int $head, int $footStart, array $bodyStarts): array
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
                $grid[$r][$c] = $cell;
                $cover[$r][$c] = $cell;
                if ($cell->mark === '') {
                    continue;
                }
                $target = self::spanTarget($cell, $cover, $head, $footStart, $bodyStarts);
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
     * @param \MarkupCarve\Carve\Block\TableCell $cell
     * @param array<int, array<int, \MarkupCarve\Carve\Block\TableCell>> $cover
     * @param int $head
     * @param int $footStart
     * @param array<int, true> $bodyStarts
     *
     * @return \MarkupCarve\Carve\Block\TableCell|null
     */
    private static function spanTarget(TableCell $cell, array $cover, int $head, int $footStart, array $bodyStarts): ?TableCell
    {
        $r = $cell->row;
        $c = $cell->col;
        if ($cell->mark === '<') {
            $t = $cover[$r][$c - 1] ?? null;

            return $t !== null && $t->rowspan === 1 && $t->col + $t->colspan === $c ? $t : null;
        }
        if ($r === 0 || $r === $head || $r === $footStart || isset($bodyStarts[$r])) {
            return null;
        }
        $t = $cover[$r - 1][$c] ?? null;

        return $t !== null && $t->colspan === 1 && $t->row + $t->rowspan === $r ? $t : null;
    }

    /**
     * C2-B-176, C2-B-184: each fragment joins the cell that covers its column; one past the last column forms a new cell.
     *
     * @param array<int, \MarkupCarve\Carve\Block\TableCell> $cells
     * @param array<int, \MarkupCarve\Carve\Block\TableCell> $cover
     * @param array{0: int, 1: int, 2: array<int>} $at The row, the number of its continuation row and its slot starts.
     * @param array<int> $fragments
     *
     * @return void
     */
    private static function join(array &$cells, array &$cover, array $at, array $fragments): void
    {
        [$r, $line, $slots] = $at;
        $header = self::continuationIsHeader($cells, $cover);
        $n = count($fragments);
        for ($k = 0; $k < $n; $k += 2) {
            $c = intdiv($k, 2);
            if (!isset($cover[$c])) {
                // C2-L-048: a cell that starts in a continuation row ranges from its first fragment.
                $cell = new TableCell($slots[$c] ?? $fragments[$k], -1);
                $cell->row = $r;
                $cell->col = $c;
                $cell->header = $header;
                $cells[$c] = $cell;
                $cover[$c] = $cell;
            }
            if ($fragments[$k + 1] <= $fragments[$k]) {
                continue;
            }
            $target = $cover[$c];
            $target->joined = true;
            // The cell's own row starts its first fragment; wrapAt is only read for joined cells.
            if ($target->wrapAt === [] && $target->lines !== []) {
                $target->wrapAt[] = [0, 0];
            }
            $target->lines[] = $fragments[$k];
            $target->lines[] = $fragments[$k + 1];
            $target->wrapAt[] = [$line, $r - $target->row];
        }
    }

    /**
     * @param array<int, \MarkupCarve\Carve\Block\TableCell> $cells
     * @param array<int, \MarkupCarve\Carve\Block\TableCell> $cover
     */
    private static function continuationIsHeader(array $cells, array $cover): bool
    {
        if ($cells === []) {
            return false;
        }
        foreach ($cells as $c => $cell) {
            if ($cell->mark === '^') {
                return false;
            }
            $effective = $cell->mark === '<' ? ($cover[$c] ?? $cell) : $cell;
            if (!$effective->header) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param \MarkupCarve\Carve\Block\TableItem $row
     * @param array<int, \MarkupCarve\Carve\Block\TableCell> $cells
     *
     * @return \MarkupCarve\Carve\Node
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
     * @param \MarkupCarve\Carve\Block\TableCell $cell
     *
     * @return \MarkupCarve\Carve\Node
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
        $bare = $cell->joined || $cell->start < 0 || $cell->end < $cell->start;
        $node = $bare ? new Node('table_cell') : Node::at('table_cell', $cell->start, $cell->end);
        $node->f = ['header' => $cell->header];
        if ($cell->colspan > 1) {
            $node->f['colspan'] = $cell->colspan;
        }
        if ($cell->rowspan > 1) {
            $node->f['rowspan'] = $cell->rowspan;
        }
        if ($cell->align !== null) {
            $node->f['align'] = $cell->align;
        }
        if ($cell->valign !== null) {
            $node->f['valign'] = $cell->valign;
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
     * @param \MarkupCarve\Carve\Attrs|null $attrs
     *
     * @return array<\MarkupCarve\Carve\Node>
     */
    private static function columns(int $width, ?Attrs $attrs): array
    {
        $aligns = $attrs?->get('aligns');
        $valigns = $attrs?->get('valigns');
        $widths = $attrs?->get('widths');
        $alignList = self::validList($aligns, false) ? explode(',', (string)$aligns) : [];
        $valignList = self::validValigns($valigns) ? explode(',', (string)$valigns) : [];
        $widthList = self::validList($widths, true) ? explode(',', (string)$widths) : [];
        $columns = [];
        for ($c = 0; $c < $width; $c++) {
            $col = new Node('table_column');
            $a = trim($alignList[$c] ?? '', " \t");
            if ($a === 'left' || $a === 'right' || $a === 'center') {
                $col->f['align'] = $a;
            }
            $v = trim($valignList[$c] ?? '', " \t");
            if ($v === 'top' || $v === 'middle' || $v === 'bottom') {
                $col->f['valign'] = $v;
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

    private static function validValigns(?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }
        foreach (explode(',', $value) as $entry) {
            if (!in_array(trim($entry, " \t"), ['top', 'middle', 'bottom'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * C2-B-227: only valid and applicable keys are consumed.
     *
     * @param \MarkupCarve\Carve\Attrs $attrs
     * @param bool $delimited
     * @param array<array{rows: int, headers: int, columns: int|null}>|null $plan
     *
     * @return void
     */
    private static function consumeKeys(Attrs $attrs, bool $delimited, ?array $plan): void
    {
        foreach (['aligns', 'widths'] as $key) {
            if (self::validList($attrs->get($key), $key === 'widths')) {
                $attrs->remove($key);
            }
        }
        if (self::validValigns($attrs->get('valigns'))) {
            $attrs->remove('valigns');
        }
        if (self::count($attrs, 'footer-rows') !== null) {
            $attrs->remove('footer-rows');
        }
        if ($delimited && self::count($attrs, 'header-rows') !== null) {
            $attrs->remove('header-rows');
        }
        if ($plan !== null) {
            foreach (['body-rows', 'body-header-rows', 'body-header-cols'] as $key) {
                $attrs->remove($key);
            }
        }
    }

    /**
     * C2-B-187, C2-B-210: a count attribute: one or more digits, empty counts as 1, else null.
     *
     * @param \MarkupCarve\Carve\Attrs|null $attrs
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
     * @return array<array{rows: int, headers: int, columns: int|null}>|null
     */
    private static function bodyPlan(?Attrs $attrs, int $available): ?array
    {
        $value = $attrs?->get('body-rows');
        if ($value === null) {
            return null;
        }
        $rows = self::bodyEntries($value, false);
        $headersValue = $attrs->get('body-header-rows');
        $columnsValue = $attrs->get('body-header-cols');
        $headers = $headersValue === null ? array_fill(0, count($rows ?? []), 0) : self::bodyEntries($headersValue, false);
        $columns = $columnsValue === null ? array_fill(0, count($rows ?? []), null) : self::bodyEntries($columnsValue, true);
        if (
            $rows === null || $headers === null || $columns === null
            || count($rows) !== count($headers) || count($rows) !== count($columns)
        ) {
            return null;
        }
        $plan = [];
        $used = 0;
        foreach ($rows as $i => $n) {
            $header = $headers[$i];
            if (!is_int($n) || !is_int($header) || $n > $available - $used || $header > $available - $used - $n) {
                return null;
            }
            $used += $n + $header;
            $plan[] = ['rows' => $n, 'headers' => $header, 'columns' => $columns[$i]];
        }

        return $used === $available ? $plan : null;
    }

    /**
     * @return array<int|null>|null
     */
    private static function bodyEntries(string $value, bool $emptyAllowed): ?array
    {
        if ($value === '') {
            return $emptyAllowed ? [null] : [];
        }
        $out = [];
        foreach (explode(',', $value) as $raw) {
            $entry = trim($raw, " \t");
            if ($entry === '' && $emptyAllowed) {
                $out[] = null;
            } elseif ($entry !== '' && ctype_digit($entry)) {
                $out[] = (int)$entry;
            } else {
                return null;
            }
        }

        return $out;
    }

    /**
     * @param array<array{rows: int, headers: int, columns: int|null}>|null $plan
     * @param int $head
     *
     * @return array<int, true>
     */
    private static function bodyStarts(?array $plan, int $head): array
    {
        $out = [];
        if ($plan === null) {
            return $out;
        }
        $at = $head;
        foreach ($plan as $i => $body) {
            if ($i > 0) {
                $out[$at] = true;
            }
            $at += $body['rows'] + $body['headers'];
        }

        return $out;
    }

    /**
     * @param array<\MarkupCarve\Carve\Node> $groups
     * @param array<array{rows: int, headers: int, columns: int|null}>|null $plan
     *
     * @return array<\MarkupCarve\Carve\Node>
     */
    private static function partitionBodies(array $groups, ?array $plan): array
    {
        if ($plan === null) {
            return $groups;
        }
        $out = [];
        $split = false;
        foreach ($groups as $group) {
            if ($group->type === 'table_body') {
                array_push($out, ...self::splitBodyGroup($group, $plan));
                $split = true;
            } else {
                if (!$split && $group->type === 'table_foot') {
                    foreach ($plan as $body) {
                        $out[] = self::emptyBody($body);
                    }
                    $split = true;
                }
                $out[] = $group;
            }
        }
        if (!$split) {
            foreach ($plan as $body) {
                $out[] = self::emptyBody($body);
            }
        }

        return $out;
    }

    /**
     * @param array{rows: int, headers: int, columns: int|null} $entry
     */
    private static function emptyBody(array $entry): Node
    {
        $body = new Node('table_body', []);
        if ($entry['columns'] !== null) {
            $body->f['rowHeadColumns'] = $entry['columns'];
        }

        return $body;
    }

    /**
     * @param \MarkupCarve\Carve\Node $group
     * @param array<array{rows: int, headers: int, columns: int|null}> $plan
     *
     * @return array<\MarkupCarve\Carve\Node>
     */
    private static function splitBodyGroup(Node $group, array $plan): array
    {
        $bodies = array_map(self::emptyBody(...), $plan);
        $index = 0;
        $inBody = 0;
        $last = count($plan) - 1;
        foreach ($group->children ?? [] as $child) {
            if ($child->type === 'table_row') {
                while ($index < $last && $inBody >= $plan[$index]['rows'] + $plan[$index]['headers']) {
                    $index++;
                    $inBody = 0;
                }
                if ($inBody < $plan[$index]['headers']) {
                    foreach ($child->children ?? [] as $cell) {
                        $cell->f['header'] = true;
                    }
                }
                $inBody++;
            }
            $body = $bodies[$index];
            $body->children[] = $child;
            if ($child->start >= 0) {
                $body->start = $body->start < 0 ? $child->start : min($body->start, $child->start);
                $body->end = max($body->end, $child->end);
            }
        }

        return $bodies;
    }

    /**
     * Distributes rows and comments over head, body and foot (C2-A-086, C2-B-187).
     *
     * @param array<\MarkupCarve\Carve\Block\TableItem> $items
     * @param array<\MarkupCarve\Carve\Node> $rowNodes
     * @param int $head
     * @param int|null $foot
     *
     * @return array<\MarkupCarve\Carve\Node>
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
     * @param \MarkupCarve\Carve\Block\Frame $f
     * @param string $body
     * @param string $sep
     *
     * @return \MarkupCarve\Carve\Node
     */
    public static function delimited(Frame $f, string $body, string $sep): Node
    {
        $malformed = false;
        $malformedAt = -1;
        $records = self::records($body, $sep, $malformed, $malformedAt);
        if ($malformed) {
            // C2-B-181
            $f->findings[] = ['delimited-table-malformed', self::bodySourceOffset($f, $malformedAt)];
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
        $foot = self::count($attrs, 'footer-rows');
        $bodyCount = $total - ($foot === null ? $total : max($head, $total - $foot));
        $plan = self::bodyPlan($attrs, $total - $head - $bodyCount);
        $table->children = self::partitionBodies(self::groups($items, $rowNodes, $head, $foot), $plan);
        foreach ($table->children as $group) {
            $group->start = -1;
            $group->end = -1;
        }
        if ($attrs !== null) {
            $attrs->remove('table');
            self::consumeKeys($attrs, true, $plan);
            $attrs->applyTo($table);
        }

        return $table;
    }

    private static function bodySourceOffset(Frame $frame, int $offset): int
    {
        for ($i = 0, $n = count($frame->spans); $i + 1 < $n; $i += 2) {
            $length = $frame->spans[$i + 1] - $frame->spans[$i];
            if ($offset <= $length) {
                return $frame->spans[$i] + $offset;
            }
            $offset -= $length + 1;
        }

        return $frame->start;
    }

    /**
     * Splits a delimited body into records of fields; a double quote quotes a field, `""` is a quote.
     *
     * @param string $body
     * @param string $sep
     * @param bool $malformed Set when a quote is not closed (C2-B-181).
     * @param int $malformedAt Offset of the unclosed quote in the body.
     *
     * @return array<array<string>>
     */
    private static function records(string $body, string $sep, bool &$malformed, int &$malformedAt): array
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
                $quote = $k;
                $k = self::quotedField($body, $k + 1, $field, $malformed);
                if ($malformed && $malformedAt < 0) {
                    $malformedAt = $quote;
                }

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
