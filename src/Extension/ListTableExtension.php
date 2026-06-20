<?php

declare(strict_types=1);

namespace Carve\Extension;

use Carve\CarveConverter;
use Carve\Event\RenderEvent;
use Carve\Node\Block\Div;
use Carve\Node\Block\ListBlock;
use Carve\Node\Block\ListItem;
use Carve\Node\Block\Paragraph;
use Carve\Renderer\HtmlRenderer;

/**
 * Renders a `::: list-table` block as a real `<table>` whose cells may hold
 * block content (issue #162, Tier-3).
 *
 * Carve pipe-table cells are inline-only. To get cells with multiple
 * paragraphs, lists, or code blocks, author the table as a nested list and let
 * this extension transform it: list items are already block containers, so the
 * cells inherit full block content for free, with no grammar or core AST change.
 *
 * Input djot:
 * ```
 * {header-rows=1}
 * ::: list-table "Quarterly results"
 * - - Region
 *   - Notes
 * - - EMEA
 *   - Strong quarter.
 *
 *     - new logos
 *     - renewals
 * :::
 * ```
 *
 * - The outer list = rows; each inner list item = a cell, left to right.
 * - `{header-rows=N}` (default 0) promotes the first N rows to `<thead>`/`<th>`.
 * - `{header-cols=N}` (default 0) promotes the first N cells of each row to `<th>`.
 * - The quoted title becomes the `<caption>`.
 * - A single-paragraph cell collapses to inline content (no wrapping `<p>`),
 *   matching tight list-item rendering; multi-block cells keep block wrappers.
 * - Ragged rows pad with empty cells (never silently dropped).
 * - With the extension disabled, the block keeps its default `<div
 *   class="list-table">` rendering, so the document stays readable.
 *
 * Spans (rowspan/colspan) are intentionally out of scope: use a pipe table for
 * those. `list-table` is for block-content cells; the two complement each other.
 *
 * Example:
 * ```php
 * $converter = new CarveConverter();
 * $converter->addExtension(new ListTableExtension());
 * ```
 */
class ListTableExtension implements ExtensionInterface
{
    /**
     * The custom admonition type this extension claims.
     *
     * @var string
     */
    public const KIND = 'list-table';

    public function register(CarveConverter $converter): void
    {
        $renderer = $converter->getRenderer();
        if (!$renderer instanceof HtmlRenderer) {
            return;
        }

        $converter->on('render.div', function (RenderEvent $event) use ($renderer): void {
            $node = $event->getNode();
            if (!$node instanceof Div || !$node->hasClass(self::KIND)) {
                return;
            }

            $html = $this->renderListTable($node, $renderer);
            if ($html !== null) {
                $event->setHtml($html);
            }
            // A malformed block (no outer list) defers to the core div renderer.
        });
    }

    /**
     * Transform the `::: list-table` div into a `<table>`, or null when the
     * block has no outer list (then it degrades to the default div rendering).
     */
    protected function renderListTable(Div $node, HtmlRenderer $renderer): ?string
    {
        $outer = $this->firstListBlock($node);
        if ($outer === null) {
            return null;
        }

        $headerRows = (int)($node->getAttribute('header-rows') ?? 0);
        $headerCols = (int)($node->getAttribute('header-cols') ?? 0);

        // Build a grid of rendered cell HTML, tracking the widest row.
        $grid = [];
        $columns = 0;
        foreach ($outer->getChildren() as $rowItem) {
            if (!$rowItem instanceof ListItem) {
                continue;
            }
            $cells = [];
            foreach ($this->cellsOf($rowItem) as $cellItem) {
                $cells[] = $this->renderCellContent($cellItem, $renderer);
            }
            $grid[] = $cells;
            $columns = max($columns, count($cells));
        }

        if ($grid === []) {
            return null;
        }

        $headHtml = '';
        $bodyHtml = '';
        foreach ($grid as $rowIndex => $cells) {
            $rowIsHeader = $rowIndex < $headerRows;
            $cellsHtml = '';
            for ($col = 0; $col < $columns; $col++) {
                $content = $cells[$col] ?? '';
                $tag = ($rowIsHeader || $col < $headerCols) ? 'th' : 'td';
                $cellsHtml .= '<' . $tag . '>' . $content . '</' . $tag . '>';
            }
            $row = '<tr>' . $cellsHtml . '</tr>';
            if ($rowIsHeader) {
                $headHtml .= $row;
            } else {
                $bodyHtml .= '    ' . $row . "\n";
            }
        }

        $lines = [];
        $title = $node->getAttribute('title');
        if ($title !== null && trim($title) !== '') {
            $lines[] = '  <caption>' . $this->escapeHtml($title) . '</caption>';
        }
        if ($headHtml !== '') {
            $lines[] = '  <thead>' . $headHtml . '</thead>';
        }
        if ($bodyHtml !== '') {
            $lines[] = "  <tbody>\n" . rtrim($bodyHtml, "\n") . "\n  </tbody>";
        }

        $attrs = $this->renderTagAttributes($node, $renderer);

        return '<table' . $attrs . ">\n" . implode("\n", $lines) . "\n</table>\n";
    }

    /**
     * The cells of a row item: every list item across the row's child lists.
     *
     * A row's cells may parse either as one nested list with N items or as N
     * single-item lists, so flatten across all child ListBlocks.
     *
     * @return array<int, \Carve\Node\Block\ListItem>
     */
    protected function cellsOf(ListItem $rowItem): array
    {
        $cells = [];
        foreach ($rowItem->getChildren() as $child) {
            if ($child instanceof ListBlock) {
                foreach ($child->getChildren() as $cell) {
                    if ($cell instanceof ListItem) {
                        $cells[] = $cell;
                    }
                }
            }
        }

        return $cells;
    }

    /**
     * Render a cell's content. A single-paragraph cell collapses to inline
     * content; any other shape renders its blocks normally.
     */
    protected function renderCellContent(ListItem $cell, HtmlRenderer $renderer): string
    {
        $blocks = $cell->getChildren();

        if (count($blocks) === 1 && $blocks[0] instanceof Paragraph) {
            $inline = '';
            foreach ($blocks[0]->getChildren() as $child) {
                $inline .= $renderer->renderNodeFragment($child);
            }

            return $inline;
        }

        $html = '';
        foreach ($blocks as $block) {
            $html .= rtrim($renderer->renderNodeFragment($block), "\n");
        }

        return $html;
    }

    /**
     * The first ListBlock child of the div (the rows list).
     */
    protected function firstListBlock(Div $node): ?ListBlock
    {
        foreach ($node->getChildren() as $child) {
            if ($child instanceof ListBlock) {
                return $child;
            }
        }

        return null;
    }

    /**
     * Carry the div's extra attributes onto `<table>`, dropping the ones this
     * extension consumes (the `list-table` class plus the control attributes).
     */
    protected function renderTagAttributes(Div $node, HtmlRenderer $renderer): string
    {
        $attrs = $node->getAttributes();
        unset($attrs['title'], $attrs['header-rows'], $attrs['header-cols']);

        $safeMode = $renderer->getSafeMode();
        if ($safeMode !== null) {
            $attrs = $safeMode->filterAttributes($attrs);
        }

        if (isset($attrs['class'])) {
            $classes = array_values(array_filter(
                preg_split('/\s+/', trim($attrs['class'])) ?: [],
                static fn (string $class): bool => $class !== '' && $class !== self::KIND,
            ));
            if ($classes === []) {
                unset($attrs['class']);
            } else {
                $attrs['class'] = implode(' ', $classes);
            }
        }

        $html = '';
        foreach ($attrs as $key => $value) {
            $html .= ' ' . $this->escapeHtml((string)$key) . '="' . $renderer->escapeAttribute($value) . '"';
        }

        return $html;
    }

    /**
     * Escape text for HTML content, matching the core renderer's `escape()`.
     */
    protected function escapeHtml(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_NOQUOTES | ENT_HTML5, 'UTF-8');

        return str_replace("\u{E000}", '&nbsp;', $escaped);
    }
}
