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
     * @return array{rows: int, columns: int}
     */
    public static function bounds(DOMElement $table): array
    {
        $rows = self::directTableRows($table);
        $cells = 0;
        foreach ($rows as $row) {
            foreach ($row->childNodes as $child) {
                if ($child instanceof DOMElement && in_array(strtolower(DomLoader::elementName($child)), ['td', 'th'], true)) {
                    ++$cells;
                }
            }
        }

        return ['rows' => count($rows), 'columns' => max(2, $cells * 2)];
    }

    public static function boundedSpan(DOMElement $cell, string $name, int $bound): int
    {
        return min(max(1, (int)$cell->getAttribute($name)), max(1, $bound));
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
