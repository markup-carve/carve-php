<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Html;

use MarkupCarve\Carve2\Attrs;
use MarkupCarve\Carve2\Node;
use MarkupCarve\Carve2\Resolve\PlainText;
use MarkupCarve\Carve2\Resolve\TableGrid;

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
    private const READ_KEYS = ['aligns', 'widths', 'header-rows', 'footer-rows'];

    /**
     * @var array<string, string>
     */
    private const GROUP_TAGS = ['table_head' => 'thead', 'table_body' => 'tbody', 'table_foot' => 'tfoot'];

    public function __construct(private HtmlRenderer $r)
    {
    }

    /**
     * @param \MarkupCarve\Carve2\Node $table
     * @param int $depth
     *
     * @return string
     */
    public function table(Node $table, int $depth): string
    {
        $grid = TableGrid::of($table);
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
                $rowLines[] = HtmlRenderer::pad($depth + 2) . '<tr' . $this->r->attrs($row) . '>' . $this->cells($row, $group, $grid, $columns, $depth + 2) . '</tr>';
            }
            if ($rowLines !== []) {
                $lines[] = $pad . '<' . $tag . $this->r->attrs($groupNode) . '>' . "\n" . implode("\n", $rowLines) . "\n" . $pad . '</' . $tag . '>';
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
        $open = '<table' . $this->r->aw->build([], null, $copy, $lang, [], [], [], [], $this->r->line($table)) . '>';

        if ($lines === []) {
            // An empty table is written with no blank line inside, unlike an empty container (oracle output).
            return HtmlRenderer::pad($depth) . $open . "\n" . HtmlRenderer::pad($depth) . '</table>';
        }

        return $this->r->container($open, '</table>', $lines, $depth);
    }

    /**
     * C2-H-152.
     *
     * @param array<\MarkupCarve\Carve2\Node> $columns
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
                $pct = rtrim(rtrim(number_format($w * 100, 10, '.', ''), '0'), '.');
                $out .= "\n" . $pad . '  <col style="width: ' . $pct . '%;">';
            } else {
                $out .= "\n" . $pad . '  <col>';
            }
        }

        return $out . "\n" . $pad . '</colgroup>';
    }

    /**
     * C2-H-060: a row span that crosses a group boundary puts every row in one body.
     *
     * @param \MarkupCarve\Carve2\Node $table
     * @param \MarkupCarve\Carve2\Resolve\TableGrid $grid
     *
     * @return array<array{0: string, 1: array<array{0: \MarkupCarve\Carve2\Node, 1: \MarkupCarve\Carve2\Node}>, 2: \MarkupCarve\Carve2\Node}>
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
     * @param \MarkupCarve\Carve2\Node $row
     * @param \MarkupCarve\Carve2\Node $group
     * @param \MarkupCarve\Carve2\Resolve\TableGrid $grid
     * @param array<\MarkupCarve\Carve2\Node> $columns
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
            $tag = ($cell->f['header'] ?? false) === true ? 'th' : 'td';
            $column = $columns[$grid->column[spl_object_id($cell)] ?? 0] ?? null;
            $attrs = $this->r->attrs($cell, [], [], self::cellStructural($cell, $group), [], self::cellStyle($cell, $column), false);
            $out .= '<' . $tag . $attrs . '>' . $this->content($cell, $depth) . '</' . $tag . '>';
        }

        return $out;
    }

    /**
     * C2-H-058: the spans above 1, the scope and the header ids of a cell.
     *
     * @param \MarkupCarve\Carve2\Node $cell
     * @param \MarkupCarve\Carve2\Node $group
     *
     * @return array<array{0: string, 1: string|null}>
     */
    private static function cellStructural(Node $cell, Node $group): array
    {
        $structural = [];
        foreach (['rowspan', 'colspan'] as $field) {
            $n = TableGrid::count($cell, $field);
            if ($n > 1) {
                $structural[] = [$field, (string)$n];
            }
        }
        $scope = $cell->f['scope'] ?? null;
        if (($cell->f['header'] ?? false) === true) {
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
     * @param \MarkupCarve\Carve2\Node $cell
     * @param \MarkupCarve\Carve2\Node|null $column
     *
     * @return array{decl?: string, first?: bool}
     */
    private static function cellStyle(Node $cell, ?Node $column): array
    {
        $decl = [];
        $align = $cell->f['align'] ?? $column?->f['resolvedAlign'] ?? null;
        if (is_string($align)) {
            $decl[] = 'text-align: ' . $align . ';';
        }
        $valign = $cell->f['valign'] ?? $column?->f['valign'] ?? null;
        if (is_string($valign)) {
            $decl[] = 'vertical-align: ' . $valign . ';';
        }

        return $decl === [] ? [] : ['decl' => implode(' ', $decl), 'first' => true];
    }

    /**
     * @param \MarkupCarve\Carve2\Node $cell
     * @param int $depth
     *
     * @return string
     */
    private function content(Node $cell, int $depth): string
    {
        $parts = [];
        foreach ($cell->children ?? [] as $block) {
            if ($block->type === 'cell_paragraph') {
                $parts[] = $this->r->inline->leaf($block->children ?? [], $depth + 2);
            } else {
                $html = $block->type === 'paragraph' ? $this->r->paragraph($block, 0, false) : $this->r->block($block, 0);
                if ($html !== null) {
                    $parts[] = $html;
                }
            }
        }

        return implode("\n", $parts);
    }
}
