<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Block\TableRow;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\EscapedText;
use MarkupCarve\Carve\Node\Inline\SmartPunctuation;
use MarkupCarve\Carve\Node\Inline\TableCellRef;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;

final class TableCellRefResolver
{
    /**
     * @var array<string, \MarkupCarve\Carve\Node\Block\Table|null>
     */
    private array $tables = [];

    /**
     * @var array<int, array<int, array<int, \MarkupCarve\Carve\Node\Block\TableCell>>>
     */
    private array $grids = [];

    /**
     * @var array<int, string|null>
     */
    private array $values = [];

    /**
     * @var array<int, true>
     */
    private array $active = [];

    /**
     * @var array<int, array<string, int>|null>
     */
    private array $keys = [];

    /**
     * @var array<int, string>
     */
    private array $reasons = [];

    private PlainTextRenderer $plain;

    public function resolve(Document $document): void
    {
        $this->tables = [];
        $this->grids = [];
        $this->values = [];
        $this->active = [];
        $this->keys = [];
        $this->reasons = [];
        $this->plain = new PlainTextRenderer();
        $refs = [];
        $stack = [$document];
        while ($stack !== []) {
            $node = array_pop($stack);
            if ($node instanceof Table) {
                $id = $node->getAttribute('id');
                if ($id !== null) {
                    $this->tables[$id] = array_key_exists($id, $this->tables) ? null : $node;
                }
            }
            if ($node instanceof TableCellRef) {
                $refs[] = $node;
            }
            foreach ($node->getChildren() as $child) {
                $stack[] = $child;
            }
        }
        foreach ($refs as $ref) {
            $ref->setValue($this->valueForRef($ref));
        }
    }

    public function reasonFor(TableCellRef $ref): ?string
    {
        return $this->reasons[spl_object_id($ref)] ?? null;
    }

    private function fail(TableCellRef $ref, string $reason): null
    {
        $this->reasons[spl_object_id($ref)] = $reason;

        return null;
    }

    /**
     * @return array<int, array<int, \MarkupCarve\Carve\Node\Block\TableCell>>
     */
    private function grid(Table $table): array
    {
        $id = spl_object_id($table);
        if (isset($this->grids[$id])) {
            return $this->grids[$id];
        }
        $grid = [];
        foreach (TableSpanGrid::resolve($table) as $r => $row) {
            foreach ($row as $c => $entry) {
                if ($entry['skip']) {
                    continue;
                }
                for ($dr = 0; $dr < $entry['rowspan']; $dr++) {
                    for ($dc = 0; $dc < $entry['colspan']; $dc++) {
                        $grid[$r + $dr][$c + $dc] = $entry['cell'];
                    }
                }
            }
        }

        return $this->grids[$id] = $grid;
    }

    private function valueForCell(?TableCell $cell): ?string
    {
        if ($cell === null || $cell->hasBlockContent()) {
            return null;
        }
        $id = spl_object_id($cell);
        if (isset($this->active[$id])) {
            return null;
        }
        if (array_key_exists($id, $this->values)) {
            return $this->values[$id];
        }
        $this->active[$id] = true;
        $valid = true;
        $stack = $cell->getChildren();
        while ($stack !== []) {
            $child = array_pop($stack);
            if ($child instanceof TableCellRef) {
                $child->setValue($this->valueForRef($child));
                $valid = $valid && $child->getValue() !== null;
            } elseif ($child instanceof Node) {
                foreach ($child->getChildren() as $nested) {
                    $stack[] = $nested;
                }
            }
        }
        $value = $valid ? $this->plain->renderInlineNodesFragment(array_values($cell->getChildren())) : null;
        unset($this->active[$id]);

        return $this->values[$id] = $value;
    }

    private function valueForRef(TableCellRef $ref): ?string
    {
        $table = $this->tables[$ref->getTarget()] ?? null;
        if ($table === null) {
            return $this->fail($ref, array_key_exists($ref->getTarget(), $this->tables) ? 'table id is ambiguous' : 'table id is absent');
        }
        $rows = array_values(array_filter($table->getChildren(), static fn ($row): bool => $row instanceof TableRow));
        $groups = $table->getRowGroups() ?? $table->statedRowGroups();
        $head = $groups['headRows'] ?? 0;
        if ($groups === null) {
            foreach ($rows as $row) {
                if (!$row->isHeader()) {
                    break;
                }
                $head++;
            }
        }
        $bodyEnd = count($rows) - ($groups['footRows'] ?? 0);
        $bodyRows = [];
        if ($groups !== null) {
            $offset = $head;
            foreach ($groups['bodies'] as $group) {
                $offset += $group['headRows'];
                for ($i = 0; $i < $group['bodyRows']; $i++) {
                    if (!($rows[$offset + $i] ?? null)?->isHeader()) {
                        $bodyRows[] = $offset + $i;
                    }
                }
                $offset += $group['bodyRows'];
            }
        } else {
            for ($i = $head; $i < $bodyEnd; $i++) {
                if (!$rows[$i]->isHeader()) {
                    $bodyRows[] = $i;
                }
            }
        }
        $grid = $this->grid($table);
        $columnByName = function (string $name) use ($grid, $head): ?int {
            if ($head === 0 || $name === '') {
                return null;
            }
            $matches = [];
            foreach ($grid[$head - 1] ?? [] as $column => $cell) {
                if ($this->valueForCell($cell) === $name) {
                    $matches[] = $column;
                }
            }

            return count($matches) === 1 ? $matches[0] : null;
        };
        $column = is_int($ref->getColumn()) ? $ref->getColumn() - 1 : $columnByName($ref->getColumn());
        if ($column === null || $column < 0) {
            return $this->fail($ref, 'column is absent or ambiguous');
        }
        if (is_int($ref->getRow())) {
            $row = $bodyRows[$ref->getRow() - 1] ?? -1;
        } else {
            $key = $table->getAttribute('row-key');
            $keyColumn = $key === null ? 0 : $columnByName($key);
            if ($keyColumn === null) {
                return $this->fail($ref, 'row-key column is absent or ambiguous');
            }
            $tableId = spl_object_id($table);
            if (!array_key_exists($tableId, $this->keys)) {
                $keys = [];
                foreach ($bodyRows as $r) {
                    $cell = $grid[$r][$keyColumn] ?? null;
                    $plain = $cell !== null;
                    foreach ($cell?->getChildren() ?? [] as $child) {
                        if (
                            !$child instanceof Text
                            && !$child instanceof EscapedText
                            && !$child instanceof SmartPunctuation
                        ) {
                            $plain = false;

                            break;
                        }
                    }
                    $value = $plain ? $this->valueForCell($cell) : null;
                    $spanned = $cell !== null && (
                        ($grid[$r][$keyColumn - 1] ?? null) === $cell
                        || ($grid[$r][$keyColumn + 1] ?? null) === $cell
                        || ($grid[$r - 1][$keyColumn] ?? null) === $cell
                        || ($grid[$r + 1][$keyColumn] ?? null) === $cell
                        || $cell->getRowspan() > 1 || $cell->getColspan() > 1
                    );
                    if ($cell === null || $spanned || $cell->getSpanMarker() !== null || $value === null || $value === '' || isset($keys[$value])) {
                        $keys = null;

                        break;
                    }
                    $keys[$value] = $r;
                }
                $this->keys[$tableId] = $keys;
            }
            $keys = $this->keys[$tableId];
            if ($keys === null || !array_key_exists($ref->getRow(), $keys)) {
                return $this->fail($ref, $keys === null ? 'row keys are invalid or duplicate' : 'row key is absent');
            }
            $row = $keys[$ref->getRow()];
        }
        if (!in_array($row, $bodyRows, true)) {
            return $this->fail($ref, 'body row is out of range');
        }
        $cell = $grid[$row][$column] ?? null;
        if ($cell === null) {
            return $this->fail($ref, 'cell is absent');
        }
        if ($cell->hasBlockContent()) {
            return $this->fail($ref, 'cell contains blocks');
        }
        if (isset($this->active[spl_object_id($cell)])) {
            return $this->fail($ref, 'cell references form a cycle');
        }

        return $this->valueForCell($cell) ?? $this->fail($ref, 'cell value could not resolve');
    }
}
