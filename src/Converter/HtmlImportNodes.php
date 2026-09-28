<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

/**
 * Constructors for import shapes shared across DOM conversion paths.
 *
 * @internal
 *
 * @phpstan-import-type FigureTargetNode from \MarkupCarve\Carve\Converter\HtmlAstBuildResult
 * @phpstan-import-type FigureNode from \MarkupCarve\Carve\Converter\HtmlAstBuildResult
 * @phpstan-import-type ParagraphNode from \MarkupCarve\Carve\Converter\HtmlAstBuildResult
 * @phpstan-import-type TableCellNode from \MarkupCarve\Carve\Converter\HtmlAstBuildResult
 * @phpstan-import-type TableRowNode from \MarkupCarve\Carve\Converter\HtmlAstBuildResult
 */
final class HtmlImportNodes
{
    /**
     * @phpstan-return ParagraphNode
     *
     * @param list<array<string, mixed>> $children
     */
    public static function paragraph(array $children): array
    {
        return ['type' => 'paragraph', 'children' => $children];
    }

    /**
     * @phpstan-return TableCellNode
     *
     * @param list<array<string, mixed>> $children
     * @param bool $header
     */
    public static function tableCell(array $children, bool $header): array
    {
        return ['type' => 'table_cell', 'header' => $header, 'children' => $children];
    }

    /**
     * @phpstan-param list<TableCellNode> $cells
     *
     * @phpstan-return TableRowNode
     *
     * @param array $cells
     */
    public static function tableRow(array $cells): array
    {
        return ['type' => 'table_row', 'cells' => $cells];
    }

    /**
     * @phpstan-param FigureTargetNode $target
     *
     * @phpstan-return FigureNode
     *
     * @param array $target
     * @param list<array<string, mixed>> $caption
     */
    public static function figure(array $target, array $caption): array
    {
        return ['type' => 'figure', 'target' => $target, 'caption' => $caption];
    }
}
