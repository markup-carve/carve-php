<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Block\TableBuilder;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Resolve\PlainText;

/**
 * Provisional. `::: list-table` (`carve.listTable`): a list of rows, each a list of cells, as a table; `^` and `<`
 * cells span from above and from the left. Both channels write the same HTML.
 */
final class ListTable
{
    /**
     * @var int
     */
    private const MAX_ROWS = 10000;

    /**
     * @var int
     */
    private const MAX_CELLS = 100000;

    public static function bundle(): Bundle
    {
        return new Bundle([
            new ExtensionDefinition(
                'carve.listTable',
                '1',
                ['rows', 'caption'],
                matcher: ['form' => 'div', 'name' => 'list-table'],
                transform: self::transform(...),
                renderer: self::render(...),
                buildTimeRenderer: self::render(...),
            ),
        ]);
    }

    public static function transform(Claim $claim): ?Node
    {
        if ($claim->form !== 'div') {
            return null;
        }
        $body = $claim->node->children ?? [];
        $list = self::tableList($body);
        if ($list === null) {
            return null;
        }
        $node = new Node('block_extension', []);
        $node->f['name'] = 'carve.listTable';
        $node->f['version'] = '1';
        $node->f['slots'] = ['rows' => [$list], 'caption' => PlainText::nodes($claim->node->f['title'] ?? [])];
        $node->f['fallback'] = array_map(Extensions::copy(...), $body);
        $label = $claim->node->f['groupLabel'] ?? null;
        if (is_string($label)) {
            $node->f['props'] = ['label' => $label];
        }

        return $node;
    }

    /**
     * @param array<\MarkupCarve\Carve\Node> $body
     */
    private static function tableList(array $body): ?Node
    {
        $list = $body[0] ?? null;
        if (count($body) !== 1 || $list === null || $list->type !== 'list') {
            return null;
        }
        $rows = $list->children ?? [];
        if ($rows === [] || count($rows) > self::MAX_ROWS) {
            return null;
        }
        $total = 0;
        $width = 0;
        foreach ($rows as $row) {
            $cells = self::rowCells($row);
            if ($cells === null) {
                return null;
            }
            $total += count($cells);
            $width = max($width, count($cells));
            if ($total > self::MAX_CELLS || $width * count($rows) > self::MAX_CELLS) {
                return null;
            }
        }

        return $list;
    }

    /**
     * The cells of a row: the items of its one list; a row item that holds anything else is no row (C2-X-078).
     *
     * @return array<\MarkupCarve\Carve\Node>|null
     */
    private static function rowCells(Node $row): ?array
    {
        $blocks = $row->children ?? [];
        if (count($blocks) !== 1 || $blocks[0]->type !== 'list') {
            return null;
        }
        $items = array_map(Extensions::copy(...), $blocks[0]->children ?? []);

        return $items === [] ? null : $items;
    }

    private static function attrs(?Node $node): ?Attrs
    {
        $attrs = $node?->f['attrs'] ?? null;

        return $attrs instanceof Attrs ? $attrs : null;
    }

    private static function label(mixed $props): string
    {
        $label = is_array($props) && is_string($props['label'] ?? null) ? $props['label'] : '';

        return $label === '' ? '' : '<p class="div-label">' . RenderHost::escapeText($label) . "</p>\n";
    }

    private static function headerCount(?Attrs $attrs, string $key): int
    {
        $value = AuthorAttrs::value($attrs, $key);
        if ($value === null) {
            return 0;
        }

        return preg_match('/^\d+$/', $value) === 1 && (int)$value <= self::MAX_ROWS ? (int)$value : 0;
    }

    private static function marker(Node $item): ?string
    {
        $blocks = $item->children ?? [];
        if (self::attrs($item) !== null || count($blocks) !== 1 || $blocks[0]->type !== 'paragraph') {
            return null;
        }
        $inlines = $blocks[0]->children ?? [];
        if (count($inlines) !== 1 || $inlines[0]->type !== 'text') {
            return null;
        }
        // C2-X-076: only a text node is a mark; a literal-text span `!`<`` is a cell holding `<`.
        $value = PlainText::str($inlines[0]->f['value'] ?? '');

        return $value === '^' || $value === '<' ? $value : null;
    }

    public static function render(Node $node, RenderHost $host): string
    {
        $slots = is_array($node->f['slots'] ?? null) ? $node->f['slots'] : [];
        [$rows, $rowHeads] = self::extract(PlainText::nodes($slots['rows'] ?? [])[0] ?? null);
        if ($rows === []) {
            return ($host->blocks)(PlainText::nodes($node->f['fallback'] ?? []));
        }
        $attrs = self::attrs($node);
        $count = count($rows);
        $head = min(self::headerCount($attrs, 'header-rows'), $count);
        $foot = min(self::headerCount($attrs, 'footer-rows'), $count - $head);
        $headCols = self::headerCount($attrs, 'header-cols');
        $width = max(0, ...array_map('count', $rows));
        $bodyEnd = $count - $foot;
        $boundaries = self::bodyBoundaries($rowHeads, $head, $bodyEnd);
        self::resolveSpans($rows, self::groupIds($count, $head, $foot, $boundaries));
        $props = $node->f['props'] ?? null;
        $label = self::label($props);
        $title = PlainText::nodes($slots['caption'] ?? []);
        $caption = $title === [] ? '' : '  <caption>' . ($host->inlines)($title) . "</caption>\n";
        $consumed = ['header-rows', 'header-cols', 'footer-rows', 'aligns', 'valigns', 'widths'];
        if ($host->sourceLines) {
            $consumed[] = 'data-source-line';
        }
        $table = new ListTableData(
            $rows,
            $rowHeads,
            $attrs,
            $head,
            $headCols,
            $width,
            self::entries($attrs, 'aligns'),
            self::entries($attrs, 'valigns'),
        );
        $bodies = '';
        $last = count($boundaries) - 1;
        for ($i = 0; $i < $last; $i++) {
            $bodies .= self::group($table, $boundaries[$i], $boundaries[$i + 1], 'tbody', $host);
        }
        $html = $label . '<table' . AuthorAttrs::write($attrs, $host, $consumed) . $host->attributes($node, true, true) . ">\n"
            . $caption . self::columns($attrs) . self::group($table, 0, $head, 'thead', $host) . $bodies
            . self::group($table, $bodyEnd, $count, 'tfoot', $host) . '</table>';

        return $host->blockDepth === 0 ? $html : str_replace("\n", "\n" . str_repeat('  ', $host->blockDepth), $html);
    }

    /**
     * The cells of every row, and whether each row is a header row; no rows when a row is no list.
     *
     * @return array{0: array<array<\MarkupCarve\Carve\Extension\ListTableCell>>, 1: array<bool>}
     */
    private static function extract(?Node $list): array
    {
        $rows = [];
        $rowHeads = [];
        foreach ($list?->type === 'list' ? $list->children ?? [] : [] as $row) {
            $items = self::rowCells($row);
            if ($items === null) {
                return [[], []];
            }
            $rowHeads[] = AuthorAttrs::value(self::attrs($items[0]), 'header-row') !== null;
            $rows[] = array_map(static fn (Node $item): ListTableCell => new ListTableCell($item, self::marker($item)), $items);
        }

        return [$rows, $rowHeads];
    }

    /**
     * @param array<bool> $rowHeads
     * @param int $end
     * @param int $start
     *
     * @return array<int>
     */
    private static function bodyBoundaries(array $rowHeads, int $start, int $end): array
    {
        $boundaries = [$start];
        $hasData = false;
        for ($row = $start; $row < $end; $row++) {
            if ($rowHeads[$row] && $hasData) {
                $boundaries[] = $row;
                $hasData = false;
            }
            if (!$rowHeads[$row]) {
                $hasData = true;
            }
        }
        $boundaries[] = $end;

        return $boundaries;
    }

    /**
     * @param int $count
     * @param int $head
     * @param int $foot
     * @param array<int> $boundaries
     *
     * @return array<int>
     */
    private static function groupIds(int $count, int $head, int $foot, array $boundaries): array
    {
        $ids = [];
        for ($row = 0; $row < $count; $row++) {
            if ($row < $head) {
                $ids[] = 0;
            } elseif ($row >= $count - $foot) {
                $ids[] = count($boundaries);
            } else {
                $found = -1;
                foreach ($boundaries as $at => $end) {
                    if ($end > $row) {
                        $found = $at;

                        break;
                    }
                }
                $ids[] = $found;
            }
        }

        return $ids;
    }

    /**
     * @param array<array<\MarkupCarve\Carve\Extension\ListTableCell>> $rows
     * @param array<int> $groups
     */
    private static function resolveSpans(array $rows, array $groups): void
    {
        $above = [];
        foreach ($rows as $row => $cells) {
            $current = [];
            $sameGroup = $row > 0 && $groups[$row] === $groups[$row - 1];
            foreach ($cells as $col => $cell) {
                // C2-X-076: a `<` whose left cell a row span covers from above joins nothing.
                $covered = $col > 0 && $cells[$col - 1]->marker === '^' && $current[$col - 1] !== $cells[$col - 1];
                $current[$col] = self::merge($cell, $covered ? null : $current[$col - 1] ?? null, $above[$col] ?? null, $sameGroup);
            }
            $above = $current;
        }
    }

    private static function merge(ListTableCell $cell, ?ListTableCell $left, ?ListTableCell $up, bool $sameGroup): ListTableCell
    {
        if ($cell->marker === '<' && $left !== null) {
            $cell->skip = true;
            $left->colspan++;

            return $left;
        }
        if ($cell->marker === '^' && $sameGroup && $up !== null && $up->colspan === 1) {
            $cell->skip = true;
            $up->rowspan++;

            return $up;
        }

        return $cell;
    }

    private static function group(ListTableData $table, int $start, int $end, string $name, RenderHost $host): string
    {
        if ($start >= $end) {
            return '';
        }
        $rows = [];
        for ($row = $start; $row < $end; $row++) {
            $rows[] = self::row($table, $row, $host);
        }
        $annotation = $host->attributes($table->rows[$start][0]->item, true, true);

        return '  <' . $name . $annotation . ">\n" . implode("\n", $rows) . "\n  </" . $name . ">\n";
    }

    private static function row(ListTableData $table, int $row, RenderHost $host): string
    {
        $cells = $table->rows[$row];
        $content = '';
        foreach ($cells as $col => $cell) {
            $content .= self::cell($table, $cell, $col, $row, $host);
        }
        $content .= str_repeat('<td></td>', max(0, $table->width - count($cells)));

        return '    <tr' . $host->attributes($cells[0]->item, true, true) . '>' . $content . '</tr>';
    }

    private static function cell(ListTableData $table, ListTableCell $cell, int $col, int $row, RenderHost $host): string
    {
        if ($cell->skip) {
            return '';
        }
        $attrs = self::attrs($cell->item);
        $rowHead = $table->rowHeads[$row] ?? false;
        $heading = $row < $table->head || $rowHead || $col < $table->headCols || AuthorAttrs::value($attrs, 'header') !== null;
        $tag = $heading ? 'th' : 'td';
        $scope = $heading ? ' scope="' . ($row < $table->head || $rowHead ? 'col' : 'row') . '"' : '';
        $spans = ($cell->rowspan > 1 ? ' rowspan="' . $cell->rowspan . '"' : '')
            . ($cell->colspan > 1 ? ' colspan="' . $cell->colspan . '"' : '');
        $style = self::style($cell, $col, $table);
        if ($style !== '') {
            $attrs = $attrs instanceof Attrs ? clone $attrs : new Attrs();
            $authored = AuthorAttrs::value($attrs, 'style');
            $authored = $authored === null ? '' : trim($authored);
            $attrs->setKey('style', ($authored === '' ? '' : rtrim($authored, ';') . '; ') . $style);
        }
        $written = AuthorAttrs::write($attrs, $host, ['rowspan', 'colspan', 'scope', 'header', 'header-row', 'align', 'valign']);

        return '<' . $tag . $scope . $spans . $written . '>'
            . self::content($cell->item, $host) . '</' . $tag . '>';
    }

    private static function content(Node $item, RenderHost $host): string
    {
        $blocks = $item->children ?? [];
        if (count($blocks) === 1 && $blocks[0]->type === 'paragraph') {
            return ($host->inlines)($blocks[0]->children ?? []);
        }

        return implode("\n", array_map(static fn (Node $block): string => ($host->blocks)([$block]), $blocks));
    }

    /**
     * C2-X-078: a cell key is read as C2-H-015 reads `align`; another value is no mark.
     *
     * @param string|null $value
     * @param array<string> $allowed
     */
    private static function alignment(?string $value, array $allowed): ?string
    {
        $folded = $value === null ? null : strtolower(trim($value, " \t\n\f\r"));

        return $folded !== null && in_array($folded, $allowed, true) ? $folded : null;
    }

    /**
     * C2-X-078: the column lists follow C2-B-211, so a list with one malformed entry sets no column.
     *
     * @return array<string>
     */
    private static function entries(?Attrs $table, string $key): array
    {
        $value = AuthorAttrs::value($table, $key);
        $valid = $key === 'valigns' ? TableBuilder::validValigns($value) : TableBuilder::validList($value, false);

        return $valid ? array_map(static fn (string $e): string => trim($e, " \t"), explode(',', (string)$value)) : [];
    }

    private static function style(ListTableCell $cell, int $col, ListTableData $table): string
    {
        $own = self::attrs($cell->item);
        $h = self::alignment(AuthorAttrs::value($own, 'align'), ['left', 'right', 'center'])
            ?? $table->aligns[$col] ?? null;
        $v = self::alignment(AuthorAttrs::value($own, 'valign'), ['top', 'middle', 'bottom', 'baseline'])
            ?? $table->valigns[$col] ?? null;

        return ($h === null ? '' : 'text-align: ' . $h . ';') . ($h !== null && $v !== null ? ' ' : '')
            . ($v === null ? '' : 'vertical-align: ' . $v . ';');
    }

    private static function columns(?Attrs $attrs): string
    {
        $value = AuthorAttrs::value($attrs, 'widths');
        if (!TableBuilder::validList($value, true)) {
            return '';
        }
        $values = explode(',', (string)$value);
        $columns = array_map(
            static fn (string $w): string => '    <col style="width: ' . RenderHost::escapeAttribute(trim($w)) . '%;">',
            $values,
        );

        return "  <colgroup>\n" . implode("\n", $columns) . "\n  </colgroup>\n";
    }
}
