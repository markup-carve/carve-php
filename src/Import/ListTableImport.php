<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import;

use MarkupCarve\Carve\Writer\SpanMarks;

/**
 * C2-M-112: with `listTable` on, a table whose cells hold blocks a pipe row cannot spell becomes the list table that
 * `carve.listTable` reads (C2-B-188).
 */
final class ListTableImport
{
    /**
     * @var array{kind: string, char: string}
     */
    private const BULLET = ['kind' => 'bullet', 'char' => '-'];

    /**
     * @var array<string>
     */
    private const COUNT_KEYS = ['header-rows', 'header-cols', 'footer-rows'];

    /**
     * C2-M-112: a table with a cell of two or more blocks, or of one block that is not a paragraph.
     *
     * @param array<string, mixed> $table
     *
     * @return bool
     */
    public static function wants(array $table): bool
    {
        foreach (J::nodes($table) as $group) {
            if ($group['type'] === 'comment') {
                continue;
            }
            foreach (J::nodes($group) as $row) {
                if ($row['type'] !== 'table_row') {
                    continue;
                }
                foreach (J::nodes($row) as $cell) {
                    if (self::holdsBlocks($cell)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $cell
     *
     * @return bool
     */
    private static function holdsBlocks(array $cell): bool
    {
        $children = J::nodes($cell);
        $type = count($children) === 1 ? $children[0]['type'] : null;

        return $type !== 'cell_paragraph' && $type !== 'paragraph';
    }

    /**
     * C2-M-112: the table as a list table: one tight item per row, each a list of one item per cell slot.
     *
     * @param array<string, mixed> $table
     * @param \MarkupCarve\Carve\Import\HtmlNode $node
     * @param \MarkupCarve\Carve\Import\ImportContext $context
     *
     * @return array<string, mixed>
     */
    public static function convert(array $table, HtmlNode $node, ImportContext $context): array
    {
        self::dropGroupFacts($table, $node, $context);
        [$rows, $head, $foot] = self::slotRows(self::grid($table, $node, $context), $node, $context);
        $headerCols = self::headerColumns($rows, $head);
        $items = [];
        foreach ($rows as $index => $slots) {
            if ($slots === []) {
                $context->report($node, 'import-structure-repaired', 'A list table row with no cell dropped');

                continue;
            }
            $cells = [];
            $loose = false;
            foreach ($slots as $column => [$cell, $mark]) {
                $item = $mark !== null ? self::textItem($mark)
                    : self::cellItem($cell, $cell['header'] === true && $index >= $head && $column >= $headerCols, $node, $context);
                $loose = $loose || count(J::nodes($item)) > 1;
                $cells[] = $item;
            }
            // C2-B-062: a list with one item is tight.
            $spacing = $loose && count($cells) > 1 ? 'loose' : 'tight';
            $list = ['type' => 'list', 'marker' => self::BULLET, 'spacing' => $spacing, 'children' => $cells];
            $items[] = ['type' => 'list_item', 'children' => [$list]];
        }
        $out = [
            'type' => 'div',
            'children' => [['type' => 'list', 'marker' => self::BULLET, 'spacing' => 'tight', 'children' => $items]],
        ];
        $wrapper = J::nodes(['caption' => [$table['caption'] ?? null]], 'caption')[0] ?? null;
        $caption = $wrapper === null ? null : J::nodes($wrapper);
        if ($caption !== null && count($caption) === 1 && $caption[0]['type'] === 'paragraph') {
            $out['title'] = J::nodes($caption[0]);
        } elseif ($caption !== null) {
            $context->report($node, 'import-element-dropped', 'A caption of a list table that is not one paragraph dropped');
        }
        $counts = ['header-rows' => $head, 'header-cols' => $headerCols, 'footer-rows' => $foot];

        $out['attrs'] = self::divAttrs($table, $counts, $node, $context);

        return $out + array_intersect_key($table, ['lang' => true, 'dir' => true]);
    }

    /**
     * The row groups of the table, each with the cells of its rows; a comment between the groups is dropped.
     *
     * @param array<string, mixed> $table
     * @param \MarkupCarve\Carve\Import\HtmlNode $node
     * @param \MarkupCarve\Carve\Import\ImportContext $context
     *
     * @return array<array{type: string, rows: array<array<array<string, mixed>>>}>
     */
    private static function grid(array $table, HtmlNode $node, ImportContext $context): array
    {
        $grid = [];
        foreach (J::nodes($table) as $group) {
            if ($group['type'] === 'comment') {
                $context->report($node, 'import-structure-repaired', 'A comment between the groups of a list table dropped');

                continue;
            }
            $rows = [];
            foreach (J::nodes($group) as $row) {
                if ($row['type'] === 'table_row') {
                    $rows[] = J::nodes($row);
                }
            }
            $grid[] = ['type' => J::str($group, 'type'), 'rows' => $rows];
        }

        return $grid;
    }

    /**
     * The rows of the table in group order, each with its slots: a cell, or the cell a span mark continues (C2-W-145).
     *
     * @param array<array{type: string, rows: array<array<array<string, mixed>>>}> $grid
     * @param \MarkupCarve\Carve\Import\HtmlNode $node
     * @param \MarkupCarve\Carve\Import\ImportContext $context
     *
     * @return array{0: array<array<array{0: array<string, mixed>, 1: string|null}>>, 1: int, 2: int}
     */
    private static function slotRows(array $grid, HtmlNode $node, ImportContext $context): array
    {
        // The marks are placed on cells without their blocks, so they reach neither the blocks nor a nested table.
        $shallow = [];
        foreach ($grid as $group) {
            $rows = [];
            foreach ($group['rows'] as $cells) {
                $bare = array_map(static fn (array $cell): array => ['children' => []] + $cell, $cells);
                $rows[] = ['type' => 'table_row', 'children' => $bare];
            }
            $shallow[] = ['type' => $group['type'], 'children' => $rows];
        }
        $marks = SpanMarks::of(['type' => 'table', 'children' => $shallow], '');
        foreach ($marks->unspelled ?? [] as $ignored) {
            $context->report($node, 'import-structure-repaired', 'A row span that no span mark of a list table can spell dropped');
        }
        $rows = [];
        $counts = ['table_head' => 0, 'table_foot' => 0, 'table_body' => 0];
        foreach ($grid as $g => $group) {
            foreach ($group['rows'] as $r => $cells) {
                $marked = $marks?->rows['/children/' . $g . '/children/' . $r] ?? null;
                $rows[] = $marked === null ? array_map(static fn (array $cell): array => [$cell, null], $cells)
                    : array_map(static fn (array $entry): array => self::slot($grid, $entry), $marked);
                $counts[$group['type']] = ($counts[$group['type']] ?? 0) + 1;
            }
        }

        return [$rows, $counts['table_head'], $counts['table_foot']];
    }

    /**
     * A slot of a row SpanMarks changed: an added mark carries the path of the cell it continues, and an import tree
     * holds no mark of its own.
     *
     * @param array<array{type: string, rows: array<array<array<string, mixed>>>}> $grid
     * @param array{0: array<mixed>, 1: string} $entry
     *
     * @return array{0: array<string, mixed>, 1: string|null}
     */
    private static function slot(array $grid, array $entry): array
    {
        [$cell, $path] = $entry;
        preg_match('#\A/children/(\d+)/children/(\d+)/children/(\d+)\z#', $path, $m);
        $own = $grid[(int)($m[1] ?? 0)]['rows'][(int)($m[2] ?? 0)][(int)($m[3] ?? 0)] ?? [];
        $span = $cell['span'] ?? null;

        return [$own, $span === null ? null : ($span === 'colspan' ? '<' : '^')];
    }

    /**
     * The smallest number of leading header slots over the rows below the head, a covered slot counting as its cell.
     *
     * @param array<array<array{0: array<string, mixed>, 1: string|null}>> $rows
     * @param int $head
     *
     * @return int
     */
    private static function headerColumns(array $rows, int $head): int
    {
        $result = null;
        $count = count($rows);
        for ($index = $head; $index < $count; $index++) {
            $leading = 0;
            while (isset($rows[$index][$leading]) && $rows[$index][$leading][0]['header'] === true) {
                $leading++;
            }
            $result = $result === null ? $leading : min($result, $leading);
        }

        return $result ?? 0;
    }

    /**
     * @param string $value
     *
     * @return array<string, mixed>
     */
    private static function textItem(string $value): array
    {
        return ['type' => 'list_item', 'children' => [['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => $value]]]]];
    }

    /**
     * @param array<string, mixed> $cell
     * @param bool $header
     * @param \MarkupCarve\Carve\Import\HtmlNode $node
     * @param \MarkupCarve\Carve\Import\ImportContext $context
     *
     * @return array<string, mixed>
     */
    private static function cellItem(array $cell, bool $header, HtmlNode $node, ImportContext $context): array
    {
        $children = [];
        foreach (J::nodes($cell) as $block) {
            if ($block['type'] !== 'cell_paragraph') {
                $children[] = $block;
            } elseif (J::nodes($block) !== []) {
                // C2-M-069: an empty cell paragraph gives no paragraph.
                $children[] = ['type' => 'paragraph', 'children' => J::nodes($block)];
            }
        }
        // C2-M-112: an item that holds no block holds an empty line comment, as `- %%` parses.
        if ($children === []) {
            $children[] = ['type' => 'comment', 'form' => 'line', 'value' => ''];
        }
        if (isset($cell['scope'])) {
            $context->report($node, 'import-attribute-dropped', 'The scope of a list table cell dropped');
        }
        $attrs = J::attrs($cell);
        $own = array_column(J::pairs($attrs), 0);
        $keys = [];
        if ($header && !in_array('header', $own, true)) {
            $keys[] = ['header', ''];
        }
        foreach (['align', 'valign'] as $field) {
            if (is_string($cell[$field] ?? null) && !in_array($field, $own, true)) {
                $keys[] = [$field, $cell[$field]];
            }
        }
        if ($keys !== []) {
            $attrs['keyValues'] = array_merge(J::pairs($attrs), $keys);
        }
        $item = ['type' => 'list_item', 'children' => $children];
        if ($attrs !== []) {
            $item['attrs'] = $attrs;
        }
        foreach (['lang', 'dir'] as $field) {
            if (isset($cell[$field])) {
                $item[$field] = $cell[$field];
            }
        }

        return $item;
    }

    /**
     * @param array<string, mixed> $table
     * @param \MarkupCarve\Carve\Import\HtmlNode $node
     * @param \MarkupCarve\Carve\Import\ImportContext $context
     *
     * @return void
     */
    private static function dropGroupFacts(array $table, HtmlNode $node, ImportContext $context): void
    {
        $bodies = 0;
        foreach (J::nodes($table) as $group) {
            if ($group['type'] === 'comment') {
                continue;
            }
            if ($group['type'] === 'table_body') {
                $bodies++;
            }
            if (isset($group['attrs']) || isset($group['lang']) || isset($group['dir'])) {
                $message = 'The attributes of a ' . J::str($group, 'type') . ' of a list table dropped';
                $context->report($node, 'import-attribute-dropped', $message);
            }
            foreach (J::nodes($group) as $row) {
                if ($row['type'] === 'table_row' && (isset($row['attrs']) || isset($row['lang']) || isset($row['dir']))) {
                    $context->report($node, 'import-attribute-dropped', 'The attributes of a list table row dropped');
                }
            }
        }
        if ($bodies > 1) {
            $context->report($node, 'import-structure-repaired', 'The boundary between two bodies of a list table dropped');
        }
        foreach (J::nodes($table, 'columns') as $column) {
            if (isset($column['width'])) {
                $context->report($node, 'import-attribute-dropped', 'A column width of a list table dropped');

                break;
            }
        }
    }

    /**
     * @param array<string, mixed> $table
     * @param array<string, int> $counts
     * @param \MarkupCarve\Carve\Import\HtmlNode $node
     * @param \MarkupCarve\Carve\Import\ImportContext $context
     *
     * @return array<string, mixed>
     */
    private static function divAttrs(array $table, array $counts, HtmlNode $node, ImportContext $context): array
    {
        $attrs = J::attrs($table);
        $keyValues = [];
        foreach (J::pairs($attrs) as [$key, $value]) {
            if (in_array($key, self::COUNT_KEYS, true)) {
                $context->report($node, 'import-attribute-dropped', 'A ' . $key . ' attribute of a list table dropped');

                continue;
            }
            $keyValues[] = [$key, $value];
        }
        foreach ($counts as $key => $count) {
            if ($count > 0) {
                $keyValues[] = [$key, (string)$count];
            }
        }
        $out = [];
        if (isset($attrs['id'])) {
            $out['id'] = $attrs['id'];
        }
        $out['classes'] = ['list-table', ...J::classes($attrs)];
        if ($keyValues !== []) {
            $out['keyValues'] = $keyValues;
        }

        return $out;
    }
}
