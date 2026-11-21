<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Html;

use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Resolve\PlainText;
use MarkupCarve\Carve\Resolve\TableGrid;

/**
 * Tables (C2-H-055 to C2-H-061, C2-H-152, C2-H-153).
 */
final class TableRenderer
{
    /**
     * C2-H-153: table keys that are read and never written.
     *
     * @var array<string>
     */
    private const READ_KEYS = [
        'aligns', 'valigns', 'widths', 'header-rows', 'footer-rows', 'body-rows', 'body-header-rows', 'body-header-cols',
    ];

    /**
     * @var array<string, string>
     */
    private const GROUP_TAGS = ['table_head' => 'thead', 'table_body' => 'tbody', 'table_foot' => 'tfoot'];

    public function __construct(private HtmlRenderer $r)
    {
    }

    /**
     * @param \MarkupCarve\Carve\Node $table
     * @param int $depth
     *
     * @return string
     */
    public function table(Node $table, int $depth): string
    {
        $grid = $this->r->state->tableGrids[spl_object_id($table)] ?? TableGrid::of($table);
        $columns = PlainText::nodes($table->f['columns'] ?? []);
        $lines = [];
        $pad = HtmlRenderer::pad($depth + 1);
        $caption = $table->f['caption'] ?? null;
        if ($caption instanceof Node) {
            $lines[] = $this->r->caption('caption', $caption, $depth + 1);
        }
        $colgroup = $this->colgroup($columns, $depth + 1);
        if ($colgroup !== null) {
            $lines[] = $colgroup;
        }
        foreach ($this->groups($table, $grid) as [$tag, $rows, $groupNode]) {
            $rowLines = [];
            foreach ($rows as [$row, $group]) {
                $rowLines[] = HtmlRenderer::pad($depth + 2) . '<tr' . $this->r->attrs($row) . '>' . $this->cells(
                    $row,
                    $group,
                    $grid,
                    $columns,
                    $depth + 2,
                ) . '</tr>';
            }
            if ($rowLines !== []) {
                $lines[] = $pad . '<' . $tag . $this->r->attrs($groupNode) . '>' . "\n" . implode("\n", $rowLines) . "\n"
                    . $pad . '</' . $tag . '>';
            }
        }
        $attrs = $table->f['attrs'] ?? null;
        $copy = null;
        if ($attrs instanceof Attrs) {
            $copy = clone $attrs;
            foreach (self::READ_KEYS as $key) {
                $copy->remove($key);
            }
        }
        $lang = is_string($table->f['lang'] ?? null) ? $table->f['lang'] : null;
        $line = $this->r->line($table);
        $open = '<table' . $this->r->aw->build([], null, $copy, $lang, sourceLine: $line, dir: AttrWriter::dirOf($table)) . '>';

        if ($lines === []) {
            // An empty table is written with no blank line inside, unlike an empty container (oracle output).
            return HtmlRenderer::pad($depth) . $open . "\n" . HtmlRenderer::pad($depth) . '</table>';
        }

        return $this->r->container($open, '</table>', $lines, $depth);
    }

    /**
     * C2-H-152.
     *
     * @param array<\MarkupCarve\Carve\Node> $columns
     * @param int $depth
     *
     * @return string|null
     */
    private function colgroup(array $columns, int $depth): ?string
    {
        $any = false;
        foreach ($columns as $column) {
            $any = $any || is_int($column->f['width'] ?? null) || is_float($column->f['width'] ?? null);
        }
        if (!$any) {
            return null;
        }
        $pad = HtmlRenderer::pad($depth);
        $out = $pad . '<colgroup>';
        foreach ($columns as $column) {
            $w = $column->f['width'] ?? null;
            if (is_int($w) || is_float($w)) {
                $pct = self::decimal($w * 100);
                $out .= "\n" . $pad . '  <col style="width: ' . $pct . '%;">';
            } else {
                $out .= "\n" . $pad . '  <col>';
            }
        }

        return $out . "\n" . $pad . '</colgroup>';
    }

    private static function decimal(float $value): string
    {
        $raw = json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $parts = preg_split('/[eE]/', $raw);
        if ($parts === false || count($parts) === 1) {
            return str_contains($raw, '.') ? rtrim(rtrim($raw, '0'), '.') : $raw;
        }
        [$mantissa, $exponent] = $parts;
        $negative = str_starts_with($mantissa, '-');
        $unsigned = $negative ? substr($mantissa, 1) : $mantissa;
        $dot = strpos($unsigned, '.');
        $place = ($dot === false ? strlen($unsigned) : $dot) + (int)$exponent;
        $digits = str_replace('.', '', $unsigned);
        if ($place <= 0) {
            $decimal = '0.' . str_repeat('0', -$place) . $digits;
        } elseif ($place >= strlen($digits)) {
            $decimal = $digits . str_repeat('0', $place - strlen($digits));
        } else {
            $decimal = substr($digits, 0, $place) . '.' . substr($digits, $place);
        }

        return ($negative ? '-' : '') . (str_contains($decimal, '.') ? rtrim(rtrim($decimal, '0'), '.') : $decimal);
    }

    /**
     * C2-H-060: a row span that crosses a group boundary puts every row in one body.
     *
     * @param \MarkupCarve\Carve\Node $table
     * @param \MarkupCarve\Carve\Resolve\TableGrid $grid
     *
     * @return array<array{0: string, 1: array<array{0: \MarkupCarve\Carve\Node,
     *     1: \MarkupCarve\Carve\Node}>, 2: \MarkupCarve\Carve\Node}>
     */
    private function groups(Node $table, TableGrid $grid): array
    {
        $groupOf = [];
        foreach ($grid->rows as $r => [, $group]) {
            $groupOf[$r] = spl_object_id($group);
        }
        foreach ($grid->rows as $r => [$row]) {
            foreach ($row->children ?? [] as $cell) {
                $span = TableGrid::count($cell, 'rowspan');
                if ($span > 1 && !isset($cell->f['span']) && $groupOf[min($r + $span - 1, count($grid->rows) - 1)] !== $groupOf[$r]) {
                    return [['tbody', $grid->rows, $grid->rows[0][1]]];
                }
            }
        }
        $out = [];
        foreach ($table->children ?? [] as $group) {
            $tag = self::GROUP_TAGS[$group->type] ?? null;
            if ($tag === null) {
                continue;
            }
            $rows = [];
            foreach ($group->children ?? [] as $row) {
                if ($row->type === 'table_row') {
                    $rows[] = [$row, $group];
                }
            }
            $out[] = [$tag, $rows, $group];
        }

        return $out;
    }

    /**
     * C2-H-056 to C2-H-058.
     *
     * @param \MarkupCarve\Carve\Node $row
     * @param \MarkupCarve\Carve\Node $group
     * @param \MarkupCarve\Carve\Resolve\TableGrid $grid
     * @param array<\MarkupCarve\Carve\Node> $columns
     * @param int $depth
     *
     * @return string
     */
    private function cells(Node $row, Node $group, TableGrid $grid, array $columns, int $depth): string
    {
        $out = '';
        foreach ($row->children ?? [] as $cell) {
            if ($cell->type !== 'table_cell' || isset($cell->f['span'])) {
                continue;
            }
            $index = $grid->column[spl_object_id($cell)] ?? 0;
            $rowHead = $group->type === 'table_body' && $index < ($group->f['rowHeadColumns'] ?? 0);
            $header = ($cell->f['header'] ?? false) === true || $rowHead;
            $tag = $header ? 'th' : 'td';
            $column = $columns[$index] ?? null;
            $authored = $cell->f['attrs'] ?? null;
            if ($authored instanceof Attrs) {
                $authored = clone $authored;
                $authored->pairs = array_values(array_filter(
                    $authored->pairs,
                    static fn (array $pair): bool => !in_array(strtolower($pair[0]), ['rowspan', 'colspan'], true),
                ));
            }
            $lang = $cell->f['lang'] ?? null;
            $attrs = $this->r->aw->build(
                self::cellStructural($cell, $group, $header),
                null,
                $authored instanceof Attrs ? $authored : null,
                is_string($lang) ? $lang : null,
                style: self::cellStyle($cell, $column),
                dir: AttrWriter::dirOf($cell),
            );
            $out .= '<' . $tag . $attrs . '>' . $this->content($cell, $depth) . '</' . $tag . '>';
        }

        return $out;
    }

    /**
     * C2-H-058: the spans above 1, the scope and the header ids of a cell.
     *
     * @param \MarkupCarve\Carve\Node $cell
     * @param \MarkupCarve\Carve\Node $group
     * @param bool $header
     *
     * @return array<array{0: string, 1: string|null}>
     */
    private static function cellStructural(Node $cell, Node $group, bool $header): array
    {
        $structural = [];
        foreach (['rowspan', 'colspan'] as $field) {
            $n = TableGrid::count($cell, $field);
            if ($n > 1) {
                $structural[] = [$field, (string)$n];
            }
        }
        $scope = $cell->f['scope'] ?? null;
        if ($header) {
            $structural[] = ['scope', is_string($scope) ? $scope : ($group->type === 'table_head' ? 'col' : 'row')];
        } elseif (is_string($scope)) {
            $structural[] = ['scope', $scope];
        }
        $headers = $cell->f['headerIds'] ?? null;
        if (is_array($headers) && $headers !== []) {
            $structural[] = ['headers', implode(' ', array_map([PlainText::class, 'str'], $headers))];
        }

        return $structural;
    }

    /**
     * The alignment declarations of a cell, its own before its column's.
     *
     * @param \MarkupCarve\Carve\Node $cell
     * @param \MarkupCarve\Carve\Node|null $column
     *
     * @return array{decl?: string, first?: bool}
     */
    private static function cellStyle(Node $cell, ?Node $column): array
    {
        $decl = [];
        $align = $cell->f['align'] ?? $column?->f['resolvedAlign'] ?? null;
        if (is_string($align) && $align !== 'none') {
            $decl[] = 'text-align: ' . $align . ';';
        }
        $valign = $cell->f['valign'] ?? $column?->f['resolvedValign'] ?? null;
        if (is_string($valign)) {
            $decl[] = 'vertical-align: ' . $valign . ';';
        }

        return $decl === [] ? [] : ['decl' => implode(' ', $decl), 'first' => true];
    }

    /**
     * C2-H-200: a cell of blocks is laid out as a list item with more blocks, its closing tag at the row indent.
     *
     * @param \MarkupCarve\Carve\Node $cell
     * @param int $depth The depth of the row.
     *
     * @return string
     */
    private function content(Node $cell, int $depth): string
    {
        $kids = $cell->children ?? [];
        if (count($kids) === 1 && $kids[0]->type === 'cell_paragraph') {
            return $this->r->inline->leaf($kids[0]->children ?? [], $depth + 2);
        }
        $visible = $this->r->visibleOf($kids);
        $first = '';
        if ($visible !== [] && HtmlRenderer::paragraphLike($visible[0]) && !isset($visible[0]->f['caption'])) {
            $first = (string)$this->r->paragraph(array_shift($visible), 0, false);
        }
        if ($visible === []) {
            return $first;
        }
        $lines = [];
        foreach ($visible as $block) {
            if ($block->type === 'cell_paragraph') {
                $html = HtmlRenderer::pad($depth + 1) . '<p>' . $this->r->inline->leaf($block->children ?? [], $depth + 2) . '</p>';
            } elseif (HtmlRenderer::paragraphLike($block)) {
                $html = $this->r->paragraph($block, $depth + 1, false);
            } else {
                $html = $this->r->block($block, $depth + 1);
            }
            if ($html !== null) {
                $lines[] = $html;
            }
        }

        return $first . "\n" . implode("\n", $lines) . "\n" . HtmlRenderer::pad($depth);
    }
}
