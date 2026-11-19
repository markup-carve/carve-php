<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import;

/**
 * Tables (C2-M-037 to C2-M-041) and the backward resolution of alignments (C2-M-040, C2-B-213).
 */
final class TableReader
{
    /**
     * @var array<string, true>
     */
    private const SCOPES = ['col' => true, 'row' => true, 'colgroup' => true, 'rowgroup' => true];

    /**
     * @var array<string, true>
     */
    private const VALIGNS = ['top' => true, 'middle' => true, 'bottom' => true, 'baseline' => true];

    /**
     * @var array<string, true>
     */
    private const ALIGNS = ['left' => true, 'right' => true, 'center' => true];

    /**
     * @var array<string, true>
     */
    private const CHILDREN = ['caption' => true, 'thead' => true, 'tbody' => true, 'tfoot' => true, 'tr' => true, 'colgroup' => true];

    private ImportContext $context;

    public function __construct(private BlockReader $blocks)
    {
        $this->context = $blocks->inline->context;
    }

    /**
     * The rules for parsing integers of the HTML Standard.
     */
    public static function htmlInteger(?string $value): ?int
    {
        if ($value === null || preg_match('/\A[ \t\n\f\r]*([+-]?[0-9]+)/', $value, $match) !== 1) {
            return null;
        }
        $digits = ltrim($match[1], '+-');

        return strlen(ltrim($digits, '0')) > 15 ? null : (int)$match[1];
    }

    /**
     * @return array<string, mixed>
     */
    public function table(HtmlNode $node): array
    {
        $children = $this->groups($node);
        $captions = array_values(array_filter($node->elements(), static fn (HtmlNode $c): bool => $c->name === 'caption'));
        foreach (array_slice($captions, 1) as $extra) {
            $this->context->report($extra, 'import-element-dropped', 'A second caption of a table dropped');
        }
        foreach ($node->elements() as $child) {
            if (!isset(self::CHILDREN[$child->name])) {
                $this->context->report($child, 'import-structure-repaired', 'Unsupported table child ' . $child->name);
            }
        }
        $out = ['type' => 'table', 'children' => $children, 'columns' => self::columns($children, $node)];
        if ($captions !== []) {
            $out['caption'] = $this->blocks->caption($captions[0]);
        }
        $out += Attrs::of($node);

        return Alignments::resolve($out);
    }

    /**
     * @return array<array<string, mixed>>
     */
    private function groups(HtmlNode $node): array
    {
        $out = [];
        $direct = $this->rows($node, false);
        if ($direct !== []) {
            $out[] = ['type' => 'table_body', 'children' => $direct];
        }
        $types = ['thead' => 'table_head', 'tbody' => 'table_body', 'tfoot' => 'table_foot'];
        foreach ($node->elements() as $group) {
            $type = $types[$group->name] ?? null;
            if ($type !== null) {
                $out[] = ['type' => $type, 'children' => $this->rows($group, $type === 'table_head')] + Attrs::of($group);
            }
        }

        return $out;
    }

    /**
     * @return array<array<string, mixed>>
     */
    private function rows(HtmlNode $node, bool $inHead): array
    {
        $rows = [];
        foreach ($node->elements() as $child) {
            if ($child->name !== 'tr') {
                if ($node->name !== 'table') {
                    $this->context->report($child, 'import-structure-repaired', 'Non-row child in table group');
                }

                continue;
            }
            $cells = [];
            foreach ($child->elements() as $entry) {
                if ($entry->name === 'th' || $entry->name === 'td') {
                    $cells[] = $this->cell($entry, $inHead);
                } else {
                    $this->context->report($entry, 'import-structure-repaired', 'Non-cell child in HTML row');
                }
            }
            $rows[] = ['type' => 'table_row', 'children' => $cells] + Attrs::of($child);
        }

        return $this->clamp($rows, $node);
    }

    /**
     * C2-M-039, C2-A-093: a row span ends at the last row of its group.
     *
     * @param array<array<string, mixed>> $rows
     * @param \MarkupCarve\Carve\Import\HtmlNode $node
     *
     * @return array<array<string, mixed>>
     */
    private function clamp(array $rows, HtmlNode $node): array
    {
        foreach ($rows as $index => $row) {
            $room = count($rows) - $index;
            $cells = J::nodes($row);
            foreach ($cells as $at => $cell) {
                $span = J::int($cell, 'rowspan');
                if ($span === null || $span <= $room) {
                    continue;
                }
                $this->context->report($node, 'import-structure-repaired', 'HTML rowspan past the end of its row group clamped');
                $cells[$at]['rowspan'] = $room;
                if ($room <= 1) {
                    unset($cells[$at]['rowspan']);
                }
            }
            $rows[$index]['children'] = $cells;
        }

        return $rows;
    }

    /**
     * C2-M-038 to C2-M-040.
     *
     * @return array<string, mixed>
     */
    private function cell(HtmlNode $node, bool $inHead): array
    {
        $blocks = false;
        foreach ($node->elements() as $child) {
            $blocks = $blocks || Elements::isBlock($child);
        }
        $header = $node->name === 'th';
        $children = $blocks ? $this->blocks->blocks($node) : [['type' => 'cell_paragraph', 'children' => $this->inline($node)]];
        $out = ['type' => 'table_cell', 'header' => $header, 'children' => $children];
        $colspan = self::htmlInteger($node->attr('colspan'));
        if ($colspan !== null && $colspan > 1) {
            $out['colspan'] = $colspan;
        }
        $rowspan = self::htmlInteger($node->attr('rowspan'));
        if ($rowspan === 0) {
            $this->context->report($node, 'import-structure-repaired', 'HTML rowspan=0 gives no span');
        } elseif ($rowspan !== null && $rowspan > 1) {
            $out['rowspan'] = $rowspan;
        }
        $scope = $node->attr('scope');
        $implied = $header && $scope === ($inHead ? 'col' : 'row');
        if (!$implied && $scope !== null && isset(self::SCOPES[$scope])) {
            $out['scope'] = $scope;
        }
        $align = $node->attr('align') ?? Attrs::alignOf($node->attr('style'));
        if ($align !== null && isset(self::ALIGNS[$align])) {
            $out['align'] = $align;
        }
        $valign = $node->attr('valign');
        if ($valign !== null && isset(self::VALIGNS[$valign])) {
            $out['valign'] = $valign;
        }
        $styled = isset($out['align']) && $node->attr('align') === null;
        $omit = ['colspan', 'rowspan', 'scope', 'align', 'valign'];

        return $out + Attrs::of($node, $styled ? [...$omit, 'style'] : $omit);
    }

    /**
     * C2-M-038: a line break in a cell becomes one space (C2-H-195 row 7).
     *
     * @return array<array<string, mixed>>
     */
    private function inline(HtmlNode $node): array
    {
        $changed = false;
        $children = self::flatten($this->blocks->inline->children($node), $changed);
        if ($changed) {
            $this->context->report($node, 'import-whitespace-normalized', 'HTML table cell line break read as a space');
        }

        return Whitespace::coalesce($children);
    }

    /**
     * @param array<array<string, mixed>> $nodes
     * @param bool $changed
     *
     * @return array<array<string, mixed>>
     */
    private static function flatten(array $nodes, bool &$changed): array
    {
        foreach ($nodes as $i => $child) {
            if ($child['type'] === 'soft_break' || $child['type'] === 'hard_break') {
                $nodes[$i] = ['type' => 'text', 'value' => ' '];
                $changed = true;
            } elseif ($child['type'] === 'ruby') {
                $pairs = J::nodes($child, 'pairs');
                foreach ($pairs as $p => $pair) {
                    $pairs[$p]['base'] = Whitespace::coalesce(self::flatten(J::nodes($pair, 'base'), $changed));
                    $pairs[$p]['annotation'] = Whitespace::coalesce(self::flatten(J::nodes($pair, 'annotation'), $changed));
                }
                $nodes[$i]['pairs'] = $pairs;
            } elseif (isset($child['children'])) {
                $nodes[$i]['children'] = Whitespace::coalesce(self::flatten(J::nodes($child), $changed));
            }
        }

        return $nodes;
    }

    /**
     * C2-M-041, C2-H-152: one column per grid column, with the widths of the first column group.
     *
     * @param array<array<string, mixed>> $groups
     * @param \MarkupCarve\Carve\Import\HtmlNode $node
     *
     * @return array<array<string, mixed>>
     */
    private static function columns(array $groups, HtmlNode $node): array
    {
        $width = 0;
        foreach ($groups as $group) {
            foreach (J::nodes($group) as $row) {
                $width = max($width, array_sum(array_map(static fn (array $c): int => J::int($c, 'colspan') ?? 1, J::nodes($row))));
            }
        }
        $widths = [];
        $colgroup = $node->firstElement('colgroup');
        foreach ($colgroup === null ? [] : $colgroup->elements() as $col) {
            if ($col->name !== 'col') {
                continue;
            }
            $style = $col->attr('style') ?? '';
            $value = preg_match('/\Awidth: ([0-9]+(?:\.[0-9]+)?)%;\z/', $style, $m) === 1 ? (float)$m[1] : 0.0;
            $widths[] = $value > 0 ? $value / 100 : null;
        }
        $out = [];
        for ($i = 0; $i < $width; $i++) {
            $out[] = isset($widths[$i]) ? ['type' => 'table_column', 'width' => $widths[$i]] : ['type' => 'table_column'];
        }

        return $out;
    }
}
