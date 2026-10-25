<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\Html;

use DOMElement;

/**
 * Table row discovery and grid structure shared by import projections.
 *
 * @internal
 *
 * @phpstan-import-type TableCellNode from \MarkupCarve\Carve\Converter\HtmlAstBuildResult
 * @phpstan-import-type TableRowNode from \MarkupCarve\Carve\Converter\HtmlAstBuildResult
 */
final class TableStructure
{
    /**
     * Plan spans against one budget for the table's physical cells. Each
     * source cell receives one position before expansion spends the rest.
     *
     * @return \MarkupCarve\Carve\Converter\Html\TableSpanPlan
     */
    public static function spanPlan(DOMElement $table): TableSpanPlan
    {
        $rows = self::directTableRows($table);
        $cells = [];
        foreach ($rows as $rowIndex => $row) {
            foreach ($row->childNodes as $child) {
                if ($child instanceof DOMElement && in_array(strtolower(DomLoader::elementName($child)), ['td', 'th'], true)) {
                    $cells[] = [$child, $rowIndex];
                }
            }
        }
        $remaining = max(1000, count($cells) + count($rows)) - count($cells);
        $plan = new TableSpanPlan();
        foreach ($cells as [$cell, $rowIndex]) {
            $colspan = min(1000, max(1, (int)$cell->getAttribute('colspan')), $remaining + 1);
            $remaining -= $colspan - 1;
            $rowspan = min(65534, max(1, (int)$cell->getAttribute('rowspan')), count($rows) - $rowIndex);
            $rowspan = min($rowspan, 1 + intdiv($remaining, $colspan));
            $remaining -= ($rowspan - 1) * $colspan;
            $plan->set($cell, $colspan, $rowspan);
        }

        return $plan;
    }

    /**
     * @phpstan-param list<TableRowNode> $rows
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function importedTableNeedsDelimiter(array $rows): bool
    {
        $cells = $rows[0]['cells'] ?? [];
        $firstSpan = null;
        foreach ($cells as $index => $cell) {
            if (isset($cell['span'])) {
                $firstSpan = $index;

                break;
            }
        }
        if ($firstSpan === null) {
            return false;
        }
        if ($firstSpan === 0) {
            return true;
        }
        foreach (array_slice($cells, $firstSpan) as $cell) {
            if (($cell['span'] ?? null) !== 'colspan') {
                return true;
            }
        }

        return false;
    }

    /**
     * @phpstan-return TableCellNode
     *
     * @phpstan-param 'rowspan'|'colspan' $span
     *
     * @return array<string, mixed>
     */
    public static function spanCell(string $span): array
    {
        return [
            'type' => 'table_cell',
            'span' => $span,
            'header' => false,
            'children' => [],
        ];
    }

    /**
     * @return list<\DOMElement>
     */
    public static function directTableRows(DOMElement $table): array
    {
        $rows = [];
        foreach ($table->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower(DomLoader::elementName($child));
            if ($tag === 'tr') {
                $rows[] = $child;

                continue;
            }
            if (!in_array($tag, ['thead', 'tbody', 'tfoot'], true)) {
                continue;
            }
            foreach ($child->childNodes as $row) {
                if ($row instanceof DOMElement && strtolower(DomLoader::elementName($row)) === 'tr') {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }
}
