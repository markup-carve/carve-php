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
        $out = ['type' => 'table', 'children' => $children, 'columns' => $this->columns($children, $node)];
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
        $lead = $this->leadSource($node);
        $out = [];
        if ($lead === $node) {
            $out = $this->split($node, ['type' => 'table_body']);
        } else {
            $direct = $this->rows($node, false);
            if ($direct !== []) {
                $out[] = ['type' => 'table_body', 'children' => $direct];
            }
        }
        $types = ['thead' => 'table_head', 'tbody' => 'table_body', 'tfoot' => 'table_foot'];
        $head = null;
        $foot = null;
        foreach ($node->elements() as $group) {
            $type = $types[$group->name] ?? null;
            if ($type === null) {
                continue;
            }
            if ($group === $lead) {
                $entries = $this->split($group, ['type' => 'table_body'] + Attrs::of($group));
                if ($group->name !== 'tbody') {
                    $this->context->report($group, 'import-structure-repaired', 'Further table head or foot read as a body');
                }
                if (count($entries) > 1 || $entries[0]['type'] === 'table_head') {
                    $head = array_shift($entries);
                }
                array_push($out, ...$entries);

                continue;
            }
            $entry = ['type' => $type, 'children' => $this->rows($group, $type === 'table_head')] + Attrs::of($group);
            if ($type === 'table_head' && $head === null) {
                $entry['children'] = $this->headCells($group, $entry['children']);
                $head = $entry;
            } elseif ($type === 'table_foot' && $foot === null) {
                $foot = $entry;
            } else {
                if ($type !== 'table_body') {
                    $entry['type'] = 'table_body';
                    $this->context->report($group, 'import-structure-repaired', 'Further table head or foot read as a body');
                }
                $out[] = $entry;
            }
        }
        if ($lead === $node && $out !== [] && $out[0]['type'] === 'table_head') {
            $head = array_shift($out);
        }

        return array_merge($head === null ? [] : [$head], $out, $foot === null ? [] : [$foot]);
    }

    /**
     * carve#6663: a head holds header cells only (C2-B-178), so each `<td>` of the `<thead>` is one, reported once.
     *
     * @param \MarkupCarve\Carve\Import\HtmlNode $head
     * @param array<array<string, mixed>> $rows
     *
     * @return array<array<string, mixed>>
     */
    private function headCells(HtmlNode $head, array $rows): array
    {
        $changed = false;
        foreach ($rows as $index => $row) {
            $cells = J::nodes($row);
            foreach ($cells as $at => $cell) {
                if ($cell['header'] !== true) {
                    $cells[$at]['header'] = true;
                    $changed = true;
                    // `scope="col"` is implied on a head header cell, as on a `<th>`.
                    if (($cell['scope'] ?? null) === 'col') {
                        unset($cells[$at]['scope']);
                    }
                }
            }
            $rows[$index]['children'] = $cells;
        }
        if ($changed) {
            $this->context->report($head, 'import-structure-repaired', 'The data cells of a table head read as header cells');
        }

        return $rows;
    }

    /**
     * C2-M-037: the element whose rows form the first `table_body` of a table with no `<thead>`, or null.
     */
    private function leadSource(HtmlNode $node): ?HtmlNode
    {
        $first = null;
        $foot = false;
        foreach ($node->elements() as $child) {
            if ($child->name === 'thead') {
                return null;
            }
            if ($child->name === 'tr') {
                return $node;
            }
            if ($first === null && ($child->name === 'tbody' || ($child->name === 'tfoot' && $foot))) {
                $first = $child;
            }
            $foot = $foot || $child->name === 'tfoot';
        }

        return $first;
    }

    /**
     * C2-M-037: the leading rows of all unscoped or `scope` `col` `<th>` cells form the head; an emptied body is dropped.
     *
     * @param \MarkupCarve\Carve\Import\HtmlNode $node
     * @param array<string, mixed> $body
     *
     * @return array<array<string, mixed>>
     */
    private function split(HtmlNode $node, array $body): array
    {
        $count = 0;
        foreach ($node->elements() as $child) {
            if ($child->name !== 'tr') {
                continue;
            }
            if (!self::headRow($child)) {
                break;
            }
            $count++;
        }
        $rows = $this->build($node, false, $count);
        $out = [];
        if ($count > 0) {
            $out[] = ['type' => 'table_head', 'children' => $this->clamp(array_slice($rows, 0, $count), $node)];
        }
        if ($count === 0 || count($rows) > $count) {
            $out[] = $body + ['children' => $this->clamp(array_slice($rows, $count), $node)];
        } elseif (count($body) > 1) {
            // C2-M-082: the dropped body takes its attributes with it.
            $this->context->report($node, 'import-attribute-dropped', 'Attributes of a table body left empty by its head rows dropped');
        }

        return $out;
    }

    private static function headRow(HtmlNode $row): bool
    {
        $cells = 0;
        foreach ($row->elements() as $cell) {
            if ($cell->name === 'td') {
                return false;
            }
            if ($cell->name === 'th') {
                $scope = $cell->attr('scope');
                if ($scope !== null && strtolower($scope) !== 'col') {
                    return false;
                }
                $cells++;
            }
        }

        return $cells > 0;
    }

    /**
     * @return array<array<string, mixed>>
     */
    private function rows(HtmlNode $node, bool $inHead): array
    {
        return $this->clamp($this->build($node, $inHead, 0), $node);
    }

    /**
     * @return array<array<string, mixed>>
     */
    private function build(HtmlNode $node, bool $inHead, int $headRows): array
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
            $rowHead = $inHead || count($rows) < $headRows;
            foreach ($child->elements() as $entry) {
                if ($entry->name === 'th' || $entry->name === 'td') {
                    $cells[] = $this->cell($entry, $rowHead);
                } else {
                    $this->context->report($entry, 'import-structure-repaired', 'Non-cell child in HTML row');
                }
            }
            $rows[] = ['type' => 'table_row', 'children' => $cells] + Attrs::of($child);
        }

        return $rows;
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
        if ($children === []) {
            $this->context->report($node, 'import-structure-repaired', 'Empty block-bearing cell repaired with a cell paragraph');
            $children = [['type' => 'cell_paragraph', 'children' => []]];
        }
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

        return $out + $this->presentation($node, $header, $inHead);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentation(HtmlNode $node, bool $header, bool $inHead): array
    {
        $out = [];
        $scope = $node->attr('scope');
        $scope = $scope === null ? null : strtolower($scope);
        $implied = $header && $scope === ($inHead ? 'col' : 'row');
        if (!$implied && $scope !== null && isset(self::SCOPES[$scope])) {
            $out['scope'] = $scope;
        } elseif ($scope !== null && !isset(self::SCOPES[$scope])) {
            $this->context->report($node, 'import-attribute-dropped', 'Invalid scope attribute dropped');
        }
        [$styleAlign, $styleValign] = self::styleAxes($node->attr('style'));
        $attrAlign = $node->attr('align');
        $attrValign = $node->attr('valign');
        $align = $styleAlign ?? ($attrAlign === null ? null : strtolower($attrAlign));
        if ($align !== null && isset(self::ALIGNS[$align])) {
            $out['align'] = $align;
        }
        $valign = $styleValign ?? ($attrValign === null ? null : strtolower($attrValign));
        if ($valign !== null && isset(self::VALIGNS[$valign])) {
            $out['valign'] = $valign;
        }
        if ($attrAlign !== null && ($styleAlign !== null || !isset(self::ALIGNS[strtolower($attrAlign)]))) {
            $this->context->report($node, 'import-attribute-dropped', 'Align attribute dropped');
        }
        if ($attrValign !== null && ($styleValign !== null || !isset(self::VALIGNS[strtolower($attrValign)]))) {
            $this->context->report($node, 'import-attribute-dropped', 'Valign attribute dropped');
        }
        $omit = ['colspan', 'rowspan', 'scope', 'align', 'valign'];

        return $out + Attrs::of($node, $styleAlign !== null || $styleValign !== null ? [...$omit, 'style'] : $omit);
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private static function styleAxes(?string $style): array
    {
        $pattern = '/\A(?:text-align: (left|right|center);(?: vertical-align: (top|middle|bottom|baseline);)?'
            . '|vertical-align: (top|middle|bottom|baseline);)\z/D';
        if ($style === null || preg_match($pattern, $style, $match) !== 1) {
            return [null, null];
        }

        return [
            $match[1] !== '' ? $match[1] : null,
            ($match[2] ?? '') !== '' ? $match[2] : (($match[3] ?? '') !== '' ? $match[3] : null),
        ];
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
    private function columns(array $groups, HtmlNode $node): array
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
            $style = $col->attr('style');
            [$value, $other] = $style === null ? [null, false] : self::styleWidth($style);
            $widths[] = $value;
            if ($style !== null && ($value === null || $other)) {
                $this->context->report($col, 'import-attribute-dropped', 'Column style dropped: no width or another declaration');
            }
        }
        $out = [];
        for ($i = 0; $i < $width; $i++) {
            $out[] = isset($widths[$i]) ? ['type' => 'table_column', 'width' => $widths[$i]] : ['type' => 'table_column'];
        }

        return $out;
    }

    /**
     * C2-M-041, C2-B-212: the fraction of the last `width: N%` declaration, and whether any other declaration stands.
     *
     * @param string $style
     *
     * @return array{0: float|null, 1: bool}
     */
    private static function styleWidth(string $style): array
    {
        $ws = " \t\n\f\r";
        $width = null;
        $count = 0;
        foreach (explode(';', $style) as $piece) {
            if (trim($piece, $ws) === '') {
                continue;
            }
            $count++;
            $colon = strpos($piece, ':');
            if ($colon === false || strcasecmp(trim(substr($piece, 0, $colon), $ws), 'width') !== 0) {
                continue;
            }
            $value = trim(substr($piece, $colon + 1), $ws);
            if (preg_match('/\A([0-9]+(?:\.[0-9]+)?)%\z/', $value, $m) !== 1) {
                continue;
            }
            $n = (float)$m[1];
            if ($n > 0 && $n <= 100) {
                $width = $n / 100;
            }
        }

        return [$width, $count > ($width === null ? 0 : 1)];
    }
}
