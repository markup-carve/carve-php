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

    /**
     * The paths of the mark cells of the group being written that join nothing (C2-B-173).
     *
     * @var array<string, true>
     */
    private array $blocked = [];

    /**
     * @var int
     */
    private const PERCENT_CACHE = 256;

    /**
     * Width entries already written, by the bytes of the width.
     *
     * @var array<string, string>
     */
    private static array $percents = [];

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
            $skip[] = 'table';
        }

        return BraceBlock::of($node, [
            'preKeys' => $delimited ? [['table', '']] : [],
            'keys' => $keys,
            'keysAfterAttrs' => true,
            'skipKeys' => $skip,
        ]);
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
        $groups = self::groups($node);
        $counts = self::rowCounts($groups);
        if ($delimited) {
            $head = $counts['table_head'];
            $fact = $this->w->fact($path, 'headerRows');
            if ($head !== min(1, array_sum($counts)) || ($fact === $head)) {
                $keys[] = ['header-rows', (string)$head];
            }
        }
        if (in_array('table_foot', array_column($groups, 0), true)) {
            $keys[] = ['footer-rows', (string)$counts['table_foot']];
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
            // C2-W-136: a span mark has no header mark of its own, so it neither makes nor breaks a header row.
            $isHeader = static fn (array $cell): bool => ($cell['header'] ?? false) === true;
            $headerOrMark = static fn (array $cell): bool => $isHeader($cell) || isset($cell['span']);
            if (
                array_filter($cells, $isHeader) === []
                || count(array_filter($cells, $headerOrMark)) !== count($cells)
            ) {
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
            // C2-W-117: a width that no decimal percentage reads back as keeps its nearest entry and is reported.
            if ((is_int($width) || is_float($width)) && (float)end($widths) / 100 !== (float)$width) {
                $this->w->lose($path, 'table', 'columns');
            }
        }

        return [$this->listKey($aligns, $path), $this->listKey($valigns, $path), $this->listKey($widths, $path)];
    }

    /**
     * C2-W-117, C2-B-212: the shortest plain decimal percentage that reads back as the width, as carve-js writes it.
     *
     * @param float $width
     *
     * @return string
     */
    private static function percent(float $width): string
    {
        $key = pack('E', $width);
        if (isset(self::$percents[$key])) {
            return self::$percents[$key];
        }
        $percent = $width * 100;
        $text = null;
        for ($digits = 1; $digits < 17 && $text === null; $digits++) {
            $candidate = self::plainDecimal((float)sprintf('%.' . ($digits - 1) . 'e', $percent));
            if ((float)$candidate / 100 === $width) {
                $text = $candidate;
            }
        }
        if (count(self::$percents) >= self::PERCENT_CACHE) {
            self::$percents = [];
        }

        return self::$percents[$key] = $text ?? self::plainDecimal($percent);
    }

    /**
     * The shortest digits that read back as `$value`, in plain decimal notation (JavaScript `String(number)` with its
     * exponent expanded).
     *
     * @param float $value
     *
     * @return string
     */
    private static function plainDecimal(float $value): string
    {
        if ($value === 0.0) {
            return '0';
        }
        // `serialize_precision` -1 gives the shortest digits that read back; another setting takes the search.
        $text = ini_get('serialize_precision') === '-1' ? var_export($value, true) : self::shortestDigits($value);
        $exponentAt = strcspn($text, 'eE');
        $mantissa = substr($text, 0, $exponentAt);
        $sign = $mantissa[0] === '-' ? '-' : '';
        $mantissa = ltrim($mantissa, '-');
        $point = strpos($mantissa, '.');
        $whole = $point === false ? $mantissa : substr($mantissa, 0, $point);
        $digits = $whole . ($point === false ? '' : substr($mantissa, $point + 1));
        $shifted = strlen($whole) + (int)substr($text, $exponentAt + 1);
        $trimmed = ltrim($digits, '0');
        $shifted -= strlen($digits) - strlen($trimmed);
        $digits = rtrim($trimmed, '0');
        if ($shifted <= 0) {
            return $sign . '0.' . str_repeat('0', -$shifted) . $digits;
        }
        if ($shifted >= strlen($digits)) {
            return $sign . $digits . str_repeat('0', $shifted - strlen($digits));
        }

        return $sign . substr($digits, 0, $shifted) . '.' . substr($digits, $shifted);
    }

    private static function shortestDigits(float $value): string
    {
        for ($precision = 1; $precision < 17; $precision++) {
            $text = sprintf('%.' . ($precision - 1) . 'e', $value);
            if ((float)$text === $value) {
                return $text;
            }
        }

        return sprintf('%.16e', $value);
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
     * Every row group in source order; a table may hold several bodies (C2-W-136).
     *
     * @param array<mixed> $node
     *
     * @return array<array{0: string, 1: array<array<string, mixed>>}>
     */
    private static function groups(array $node): array
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
            $groups[] = [(string)$group['type'], $rows];
        }

        return $groups;
    }

    /**
     * @param array<array{0: string, 1: array<array<string, mixed>>}> $groups
     *
     * @return array<string, int>
     */
    private static function rowCounts(array $groups): array
    {
        $counts = array_fill_keys(self::GROUPS, 0);
        foreach ($groups as [$type, $rows]) {
            $counts[$type] += count($rows);
        }

        return $counts;
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
     * C2-W-066: the table is written in a delimited form. `$format` is its recorded `sourceFormat`.
     *
     * @param array<mixed> $node
     * @param mixed $format
     * @param bool $spans The table needs span marks, which have no delimited form.
     *
     * @return bool
     */
    public static function writesDelimited(array $node, mixed $format, bool $spans): bool
    {
        $rows = array_sum(self::rowCounts(self::groups($node)));
        $wanted = in_array($format, ['csv', 'tsv'], true) || ($format === null && self::pipeCannotSpell($node));

        return $rows === 0 || ($wanted && !$spans && self::allowsDelimited($node));
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
        $format = $this->w->fact($path, 'sourceFormat');
        $delimited = self::writesDelimited($node, $format, $this->spans !== null);
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
        // C2-W-117: a pipe table has no spelling of the attributes of a head, a body or a foot.
        if (isset($group['attrs'])) {
            $this->w->lose($path, self::typeOf($group), 'attrs');
        }
        $bodyHeaders = ($group['type'] ?? '') === 'table_body' ? self::leadingHeaderRows($items) : 0;
        $this->blocked = $this->blockedMarks($items, $path);
        $rowIndex = 0;
        $lastAt = count($items) - 1;
        foreach ($items as $at => $item) {
            if (!is_array($item)) {
                continue;
            }
            if (($item['type'] ?? '') === 'comment') {
                // C2-W-135: a comment that ends the last group is kept in it by an empty continuation row, which
                // stands for the empty continuation rows recorded below the last row.
                if ($last && $at === $lastAt) {
                    self::dropBlankTail($lines);
                }
                $lines[] = self::commentLine($item);
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
     * Removes the continuation rows of only empty fragments that end the last row written.
     *
     * @param array<array{lead: string, segments: array<string>, tail: string}|string> $lines
     *
     * @return void
     */
    private static function dropBlankTail(array &$lines): void
    {
        $at = count($lines) - 1;
        while ($at >= 0 && is_string($lines[$at])) {
            $at--;
        }
        while ($at > 0 && is_array($lines[$at]) && $lines[$at]['lead'] === '+' && trim(implode('', $lines[$at]['segments'])) === '') {
            array_splice($lines, $at, 1);
            $at--;
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
        // C2-L-019: the recorded continuation rows include those with only empty fragments.
        $below = $this->w->fact($path, 'continuationLines');
        // Without the count the wraps alone place the continuation rows.
        $below = is_int($below) ? max(0, $below) : PHP_INT_MAX;
        $height = $below === PHP_INT_MAX ? 1 : $below + 1;
        [$first, $continued, $height] = $this->cellLines($row, $path, $head, $bodyHeader, $below, $height);
        if ($first === []) {
            // C2-W-057: a row with no cell has no spelling, `||` is a row of one empty cell.
            $this->w->lose($path, 'table_row', null);
        }
        $tail = $this->rowAttributes($row, $path);
        $lines = [];
        $lines[] = ['lead' => '|', 'segments' => $first, 'tail' => $tail];
        foreach (self::continuedSegments($first, $continued, $height) as $segments) {
            $lines[] = ['lead' => '+', 'segments' => $segments, 'tail' => ''];
        }

        return $lines;
    }

    /**
     * The first-line segment of each cell of a row, the fragments of the cells that continue, and the row's height.
     *
     * @param array<mixed> $row
     * @param string $path
     * @param bool $head
     * @param bool $bodyHeader
     * @param int $below
     * @param int $height
     *
     * @return array{array<string>, array<int, array<int, string>>, int}
     */
    private function cellLines(array $row, string $path, bool $head, bool $bodyHeader, int $below, int $height): array
    {
        $first = [];
        $continued = [];
        foreach ($this->spans->rows[$path] ?? self::rowCells($row, $path) as [$cell, $at]) {
            [$prefix, $fragments] = $this->cell($cell, $at, $head, $bodyHeader, $below);
            $text = $fragments[0] ?? '';
            $first[] = $text === '' ? $prefix . ' ' : $prefix . ' ' . $text . ' ';
            if (count($fragments) > 1) {
                $continued[count($first) - 1] = $fragments;
                $height = max($height, max(array_keys($fragments)) + 1);
            }
        }

        return [$first, $continued, $height];
    }

    /**
     * The segments of each continuation row of a row.
     *
     * @param array<string> $first
     * @param array<int, array<int, string>> $continued
     * @param int $height
     *
     * @return array<array<string>>
     */
    private static function continuedSegments(array $first, array $continued, int $height): array
    {
        $lines = [];
        for ($line = 1; $line < $height; $line++) {
            $segments = array_fill(0, count($first), ' ');
            foreach ($continued as $at => $fragments) {
                $text = $fragments[$line] ?? '';
                if ($text !== '') {
                    $segments[$at] = ' ' . $text . ' ';
                }
            }
            $lines[] = $segments;
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
     * @param int $below The continuation rows recorded for the cell's row.
     *
     * @return array{0: string, 1: array<string>}
     */
    private function cell(array $cell, string $path, bool $head, bool $bodyHeader, int $below): array
    {
        if (isset($cell['span'])) {
            return $this->mark($cell, $path, $head, $bodyHeader);
        }
        $header = !$bodyHeader && ($cell['header'] ?? false) === true ? '=' : '';
        $this->unspellable($cell, $path);
        $prefix = $header . BraceBlock::of($cell, ['mark' => self::alignmentMark($cell), 'pipes' => true]);
        $blocks = is_array($cell['children'] ?? null) ? $cell['children'] : [];
        $lines = $this->registerWraps($path, $below);
        $content = $this->content($blocks, $path);
        $visibleContent = $this->w->repairTrace?->decode($content)['source'] ?? $content;
        $this->loseLeadingBlank($visibleContent, $blocks, $path);

        $first = is_array($blocks[0] ?? null) ? $blocks[0] : [];
        $inlines = is_array($first['children'] ?? null) ? $first['children'] : [];
        $last = end($inlines);
        $terminalHardBreak = is_array($last) && ($last['type'] ?? '') === 'hard_break';

        return [$prefix, $this->escapedLead($this->fragments($content, $lines, $path, $terminalHardBreak, $blocks))];
    }

    /**
     * C2-W-063, C2-B-184: a cell whose first line holds only a span mark is written with an escape, since span marks
     * are read from the first line of the row.
     *
     * @param array<int, string> $fragments
     *
     * @return array<int, string>
     */
    private function escapedLead(array $fragments): array
    {
        $lead = $fragments[0] ?? '';
        $visible = $this->w->repairTrace?->decode($lead)['source'] ?? $lead;
        if ($visible === '^' || $visible === '<') {
            $fragments[0] = '\\' . $lead;
        }

        return $fragments;
    }

    /**
     * C2-B-171, C2-W-117: the padding of a pipe cell is trimmed, so a space or a tab that starts its content is lost
     * by the text that holds it.
     *
     * @param string $content
     * @param array<mixed> $blocks
     * @param string $path
     *
     * @return void
     */
    private function loseLeadingBlank(string $content, array $blocks, string $path): void
    {
        if ($content === '' || ($content[0] !== ' ' && $content[0] !== "\t")) {
            return;
        }
        $first = is_array($blocks[0] ?? null) ? $blocks[0] : [];
        $inlines = is_array($first['children'] ?? null) ? $first['children'] : [];
        foreach ($inlines as $at => $inline) {
            if (!is_array($inline) || ($inline['type'] ?? '') !== 'text') {
                break;
            }
            if (Inline::str($inline, 'value') !== '') {
                $this->w->lose($path . '/children/0/children/' . $at, 'text', 'value');

                return;
            }
        }
        $this->w->lose($path, 'table_cell', 'children');
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

    private static function typeOf(mixed $node): string
    {
        return is_array($node) && is_string($node['type'] ?? null) ? $node['type'] : '';
    }

    /**
     * C2-L-036: what the delimited form of a table without rows cannot hold: a head, a foot, a comment, the attributes
     * of a body and what a body holds.
     *
     * @param array<mixed> $node
     * @param string $path
     *
     * @return void
     */
    private function loseEmptyGroups(array $node, string $path): void
    {
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $g => $group) {
            if (!is_array($group)) {
                continue;
            }
            $at = $path . '/children/' . $g;
            $type = self::typeOf($group);
            if ($type !== 'table_body') {
                $this->w->lose($at, $type, null);

                continue;
            }
            if (isset($group['attrs'])) {
                $this->w->lose($at, $type, 'attrs');
            }
            foreach (is_array($group['children'] ?? null) ? $group['children'] : [] as $e => $entry) {
                if (is_array($entry)) {
                    $this->w->lose($at . '/children/' . $e, self::typeOf($entry), null);
                }
            }
        }
    }

    /**
     * C2-W-063: a mark is written bare, with the header mark in the head only.
     *
     * @param array<mixed> $cell
     * @param string $path
     * @param bool $head
     * @param bool $bodyHeader
     *
     * @return array{0: string, 1: array<string>}
     */
    private function mark(array $cell, string $path, bool $head, bool $bodyHeader): array
    {
        $this->markLoss($cell, $path, isset($this->blocked[$path]) ? $head || $bodyHeader : null);

        return [$head ? '=' : '', [$cell['span'] === 'colspan' ? '<' : '^']];
    }

    /**
     * C2-W-063, C2-A-092: a mark cell reads back with `header` false, no children and no other field, so the first
     * field that differs from that is lost. One entry per cell, as the carve-js read-back comparison reports it.
     *
     * A mark that joins nothing reads back as a text cell (C2-B-173), a header cell where its row reads as one, so its
     * `header` or else its `span` differs, the first in node order.
     *
     * @param array<mixed> $cell
     * @param string $path
     * @param bool|null $blockedHeader The header of the read-back cell of a mark that joins nothing, else null.
     *
     * @return void
     */
    private function markLoss(array $cell, string $path, ?bool $blockedHeader): void
    {
        if ($blockedHeader !== null) {
            $field = ($cell['header'] ?? false) !== $blockedHeader ? 'header' : 'span';
            $this->w->lose($path, 'table_cell', $field);
            $cell = ['children' => $cell['children'] ?? null];
        }
        foreach ($cell as $field => $value) {
            $kept = match ($field) {
                'type', 'pos', 'span', 'children' => true,
                'header' => $value === false,
                default => false,
            };
            if (!$kept) {
                $this->w->lose($path, 'table_cell', (string)$field);

                break;
            }
        }
        foreach (is_array($cell['children'] ?? null) ? $cell['children'] : [] as $at => $block) {
            $this->w->lose($path . '/children/' . $at, self::typeOf($block), null);
        }
    }

    /**
     * The mark cells of one group that join nothing, by the rule the reader applies to the written marks (C2-B-172): a
     * `<` needs a cell of one row that ends at its left, a `^` a cell of one column that ends above it, in the group.
     *
     * @param array<mixed> $items
     * @param string $path
     *
     * @return array<string, true>
     */
    private function blockedMarks(array $items, string $path): array
    {
        $rows = [];
        foreach ($items as $at => $item) {
            if (is_array($item) && ($item['type'] ?? '') === 'table_row') {
                $rows[$path . '/children/' . $at] = $item;
            }
        }
        if (!$this->hasMarks($rows)) {
            return [];
        }
        // The covering cells by number: their first row and column and their spans, so a wide table stays small.
        $cover = ['row' => [], 'col' => [], 'colspan' => [], 'rowspan' => []];
        $blocked = [];
        $previous = [];
        $r = 0;
        foreach ($rows as $rowPath => $row) {
            $current = [];
            $c = 0;
            foreach ($this->spans->rows[$rowPath] ?? self::rowCells($row, (string)$rowPath) as [$cell, $cellPath]) {
                $span = $cell['span'] ?? null;
                $target = self::markTarget($span, $cover, [$previous, $current], [$r, $c]);
                if ($target === null) {
                    if ($span !== null) {
                        $blocked[$cellPath] = true;
                    }
                    $target = count($cover['row']);
                    $cover['row'][] = $r;
                    $cover['col'][] = $c;
                    $cover['colspan'][] = 1;
                    $cover['rowspan'][] = 1;
                } else {
                    $cover[$span === 'colspan' ? 'colspan' : 'rowspan'][$target]++;
                }
                $current[$c] = $target;
                $c++;
            }
            $previous = $current;
            $r++;
        }

        return $blocked;
    }

    /**
     * @param array<array<mixed>> $rows By row path.
     *
     * @return bool
     */
    private function hasMarks(array $rows): bool
    {
        foreach ($rows as $rowPath => $row) {
            foreach ($this->spans->rows[$rowPath] ?? self::rowCells($row, (string)$rowPath) as [$cell]) {
                if (isset($cell['span'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The covering cell a mark at row `$r` and column `$c` joins, as TableBuilder::spanTarget() reads it, or null.
     *
     * @param mixed $span
     * @param array{row: array<int>, col: array<int>, colspan: array<int>, rowspan: array<int>} $cover
     * @param array{0: array<int, int>, 1: array<int, int>} $lines The covering cell of each column of the row above
     *   and of this row so far.
     * @param array{0: int, 1: int} $at
     *
     * @return int|null
     */
    private static function markTarget(mixed $span, array $cover, array $lines, array $at): ?int
    {
        [$r, $c] = $at;
        if ($span === 'colspan') {
            $left = $lines[1][$c - 1] ?? null;

            return $left !== null && $cover['rowspan'][$left] === 1 && $cover['col'][$left] + $cover['colspan'][$left] === $c
                ? $left : null;
        }
        if ($span !== 'rowspan') {
            return null;
        }
        $above = $lines[0][$c] ?? null;

        return $above !== null && $cover['colspan'][$above] === 1 && $cover['row'][$above] + $cover['rowspan'][$above] === $r
            ? $above : null;
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
            $c->writerState = $this->w;
            $c->inCell = true;
            $c->blockLines = false;
            $c->counts = Inline::counts($inlines);
            $outer = $this->w->takeSmartRun();
            $part = $this->inline->content($inlines, $path . '/children/' . $at . '/children', $c, -1);
            if (Inline::endsInLoneEmptyText($inlines)) {
                $this->w->lose($path . '/children/' . $at, 'cell_paragraph', null);
            }
            if ($this->w->takeSmartRun()) {
                SmartReadBack::leafReadBack($this->w, $inlines, $part, $path . '/children/' . $at, 'cell');
            }
            $this->w->smartRun = $outer;
            $parts[] = $part;
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
     * @param array<mixed> $blocks
     *
     * @return array<int, string>
     */
    private function fragments(string $content, array $lines, string $path, bool $terminalHardBreak, array $blocks): array
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
                $this->loseLineFeed($blocks, $path);
            }
            $content = str_replace("\n", ' ', $content);
        }

        return [$content];
    }

    /**
     * C2-W-067, C2-W-117: the first node in the cell whose line feed has no spelling loses it: a text its value, a break
     * itself.
     *
     * @param array<mixed> $blocks
     * @param string $path
     *
     * @return void
     */
    private function loseLineFeed(array $blocks, string $path): void
    {
        $stack = [];
        for ($at = count($blocks) - 1; $at >= 0; $at--) {
            $stack[] = [$blocks[$at] ?? null, $path . '/children/' . $at];
        }
        while ($stack !== []) {
            [$node, $at] = array_pop($stack);
            if (!is_array($node)) {
                continue;
            }
            $type = Inline::str($node, 'type');
            if ($type === 'text' && str_contains(Inline::str($node, 'value'), "\n")) {
                $this->w->lose($at, 'text', 'value');

                return;
            }
            if ($type === 'soft_break' || $type === 'hard_break') {
                $this->w->lose($at, $type, null);

                return;
            }
            $children = is_array($node['children'] ?? null) ? $node['children'] : [];
            for ($k = count($children) - 1; $k >= 0; $k--) {
                $stack[] = [$children[$k] ?? null, $at . '/children/' . $k];
            }
        }
        $this->w->lose($path, 'table_cell', 'children');
    }

    /**
     * C2-L-020: the wraps of a cell, as split points of its text nodes; returns the continuation line of each.
     *
     * C2-L-006: a wrap on a later row of a spanning cell, past the row's continuation rows or not behind the wrap
     * before it contradicts the record, and the cell then keeps no wrap (C2-L-035).
     *
     * @param string $path
     * @param int $below
     *
     * @return array<int>
     */
    private function registerWraps(string $path, int $below): array
    {
        $wraps = $this->w->fact($path, 'wraps');
        if (!is_array($wraps)) {
            return [];
        }
        $lines = [];
        $points = [];
        foreach ($wraps as $wrap) {
            if (
                !is_array($wrap) || !is_int($wrap['line'] ?? null) || !is_string($wrap['path'] ?? null)
                || !is_int($wrap['offset'] ?? null)
            ) {
                return [];
            }
            $line = $wrap['line'];
            if (($wrap['row'] ?? 0) !== 0 || $line < 1 || $line > $below || $line <= (end($lines) ?: 0)) {
                return [];
            }
            $points[] = [$wrap['path'], $wrap['offset']];
            $lines[] = $line;
        }
        foreach ($points as [$at, $offset]) {
            $this->w->wrapPoints[$at][] = $offset;
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
    private static function pipeCannotSpell(array $node): bool
    {
        foreach (self::groups($node) as [, $rows]) {
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
    private static function allowsDelimited(array $node): bool
    {
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $group) {
            if (is_array($group) && isset($group['attrs'])) {
                return false;
            }
        }
        foreach (self::groups($node) as [$type, $rows]) {
            // C2-B-230: `body-header-rows` spells the leading header rows of a body.
            $bodyHeaders = $type === 'table_body' ? self::leadingHeaderRows($rows) : 0;
            foreach ($rows as $r => $row) {
                if (isset($row['attrs'])) {
                    return false;
                }
                $wanted = $type === 'table_head' || $r < $bodyHeaders;
                foreach (is_array($row['children'] ?? null) ? $row['children'] : [] as $cell) {
                    $header = is_array($cell) && ($cell['header'] ?? false) === true;
                    if (!is_array($cell) || self::plainCell($cell) === null || $header !== $wanted) {
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
        if (array_sum(self::rowCounts(self::groups($node))) === 0) {
            $this->loseEmptyGroups($node, $path);
        }
        if (!$prewritten) {
            $this->w->emit($prefix, $this->attributeLine($node, $path, true));
        }
        $separator = $format === 'tsv' ? "\t" : ',';
        $body = [];
        $all = array_merge(...array_column(self::groups($node), 1));
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
