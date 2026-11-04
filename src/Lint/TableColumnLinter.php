<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Block\TableRow;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\Block\TableParser;
use MarkupCarve\Carve\Parser\BlockParser;

class TableColumnLinter
{
    /**
     * @return list<\MarkupCarve\Carve\Lint\LintWarning>
     */
    public function lint(string $source): array
    {
        $warnings = [];
        $rows = preg_split('/\r\n|\r|\n/', $source, flags: PREG_SPLIT_OFFSET_CAPTURE) ?: [];
        $lines = array_column($rows, 0);
        $byteAt = SourceOffsets::map($source);
        $validUtf8 = mb_check_encoding($source, 'UTF-8');
        $prefixCounts = [];
        if (!$validUtf8) {
            foreach ($rows as $index => [$text]) {
                $prefixCounts[$index] = SourceOffsets::asciiPrefixCounts($text);
            }
        }
        $parser = new TableParser();
        foreach ($lines as $lineIndex => $line) {
            if (str_starts_with(ltrim($line), '|')) {
                $indent = strlen($line) - strlen(ltrim($line));
                foreach ($parser->splitCells(substr($line, $indent)) as $cell) {
                    $warning = $this->unpaddedRun($parser, $rows, $byteAt, $validUtf8, $prefixCounts, $lineIndex, $indent + $cell['offset'], $cell['content']);
                    if ($warning !== null) {
                        $warnings[] = $warning;
                    }
                }

                continue;
            }
            if (!str_contains($line, 'aligns=') && !str_contains($line, 'valigns=') && !str_contains($line, 'widths=')) {
                continue;
            }
            // THE LINE HAS TO BE A BLOCK-ATTRIBUTE LINE, not merely hold a
            // brace run. A `{...}` with text beside it attaches to nothing
            // (PART 9 §15) - it is paragraph text - so nothing it lists is the
            // table's column metadata, and every rule below was reporting on a
            // table that had not been configured at all
            // (markup-carve/carve-php#2636).
            $attributeLine = rtrim($line);
            if (!str_starts_with(ltrim($attributeLine), '{') || !str_ends_with($attributeLine, '}')) {
                continue;
            }
            $next = $lines[$lineIndex + 1] ?? '';
            if (!str_starts_with(ltrim($next), '|')) {
                continue;
            }
            $columns = max(0, substr_count($next, '|') - 1);
            foreach (['aligns', 'valigns', 'widths'] as $key) {
                if (preg_match('/\b' . $key . '=(?:"([^"]*)"|([^\s}]+))/', $line, $match, PREG_OFFSET_CAPTURE) !== 1) {
                    continue;
                }
                $quoted = $match[1];
                $bare = $match[2] ?? ['', -1];
                $raw = $quoted[1] >= 0 ? $quoted[0] : $bare[0];
                $offset = strpos($line, $key);
                $values = explode(',', $raw);
                if (count($values) < $columns) {
                    $warnings[] = $this->warning($rows, $byteAt, $validUtf8, $prefixCounts, $lineIndex, (int)$offset, strlen($key), 'table-column-arity', sprintf('%s supplies %d column entries for a %d-column table; the unset tail is valid but may be accidental.', $key, count($values), $columns));
                }
                if ($key === 'widths' && array_sum(array_map(static fn (string $value): float => is_numeric(trim($value)) ? (float)trim($value) : 0.0, $values)) > 100) {
                    $warnings[] = $this->warning($rows, $byteAt, $validUtf8, $prefixCounts, $lineIndex, (int)$offset, strlen($key), 'table-width-total', 'The specified table column widths total more than 100%.');
                }
            }
            $markers = null;
            foreach (['aligns', 'valigns'] as $key) {
                if (preg_match('/\b' . $key . '=/', $line, $attribute, PREG_OFFSET_CAPTURE) !== 1) {
                    continue;
                }
                $markers ??= $this->headerMarkerAxes($lines, $lineIndex + 1);
                if ($markers[$key]) {
                    $offset = $attribute[0][1];
                    $warnings[] = $this->warning($rows, $byteAt, $validUtf8, $prefixCounts, $lineIndex, $offset, strlen($key), 'table-column-overlap', 'A column supplies the same alignment axis both in the table and in a table attribute; the in-table marker wins.');
                }
            }
        }

        return $warnings;
    }

    /**
     * Which alignment axes a header cell of the table starting at `$start`
     * sets with an in-table marker, read from the parsed cells so the braced
     * form (`|={>} H |`) counts like the deprecated prefix (`|=> H |`).
     *
     * @param list<string> $lines
     * @param int $start
     *
     * @return array{aligns: bool, valigns: bool}
     */
    private function headerMarkerAxes(array $lines, int $start): array
    {
        $rows = [];
        for ($i = $start; isset($lines[$i]) && str_starts_with(ltrim($lines[$i]), '|'); $i++) {
            $rows[] = ltrim($lines[$i]);
        }
        $axes = ['aligns' => false, 'valigns' => false];
        $this->collectHeaderAxes((new CarveConverter())->parse(implode("\n", $rows) . "\n"), $axes);

        return $axes;
    }

    /**
     * Only the leading header rows (the table head) are column markers; a
     * header cell in a later row is a row header and aligns only itself.
     *
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param array{aligns: bool, valigns: bool} $axes
     */
    private function collectHeaderAxes(Node $node, array &$axes): void
    {
        if ($node instanceof Table) {
            foreach ($node->getChildren() as $row) {
                if (!$row instanceof TableRow) {
                    continue;
                }
                if (!$row->isHeader()) {
                    break;
                }
                foreach ($row->getChildren() as $cell) {
                    if ($cell instanceof TableCell) {
                        $axes['aligns'] = $axes['aligns'] || $cell->hasExplicitAlignment();
                        $axes['valigns'] = $axes['valigns'] || $cell->hasExplicitVerticalAlignment();
                    }
                }
            }

            return;
        }
        foreach ($node->getChildren() as $child) {
            $this->collectHeaderAxes($child, $axes);
        }
    }

    /**
     * Reports a cell that is literal only because its marker run lacks the
     * terminating space (PART 9 §5 T11). A run that is invalid for another
     * reason (`^a`, `=<<`) stays literal with the space added, so it is not
     * reported; a block glued before `<`, `>` or `~` is the retired order,
     * which RetiredSpellingLinter reports.
     *
     * @param \MarkupCarve\Carve\Parser\Block\TableParser $parser
     * @param list<array{string, int}> $rows
     * @param array<int, int>|null $byteAt
     * @param bool $validUtf8
     * @param array<int, array<int, int>> $prefixCounts
     * @param int $lineIndex
     * @param int $offset Byte offset of the cell content in the line.
     * @param string $cell
     */
    private function unpaddedRun(
        TableParser $parser,
        array $rows,
        ?array $byteAt,
        bool $validUtf8,
        array $prefixCounts,
        int $lineIndex,
        int $offset,
        string $cell,
    ): ?LintWarning {
        $rule = RetiredSpellingLinter::RULE_TABLE_MARKER_RUN_PADDING;
        // A braced alignment (`{>}`, carve#5091) is not an attribute payload,
        // so the marker-run scan does not see it as a block.
        $braced = $parser->bracedAlignment($cell, terminated: false);
        if ($braced !== null) {
            if (($cell[$braced['end']] ?? '') === ' ') {
                return null;
            }
            if ($cell[0] === '=') {
                return $this->warning($rows, $byteAt, $validUtf8, $prefixCounts, $lineIndex, $offset, $braced['end'], $rule, 'The table kind marker has no terminating space, so it is literal cell content. Add a space after the marker run and its attribute block to make it a header.');
            }

            return $this->warning($rows, $byteAt, $validUtf8, $prefixCounts, $lineIndex, $offset, $braced['end'], $rule, sprintf('The table cell attribute block "%s" has no terminating space, so it is literal cell content. Add a space after the block to apply it to the cell.', substr($cell, 0, $braced['end'])));
        }
        $scan = $parser->scanCellMarkerRun($cell);
        if ($scan === null || $scan['terminated'] || $scan['runEnd'] !== $scan['glyphEnd']) {
            return null;
        }
        $kindEnd = $scan['kind'] ? 1 : 0;
        $block = $scan['blockEnd'] !== null;
        $suffix = $block ? ' and its attribute block' : '';
        if ($scan['runEnd'] > $kindEnd) {
            $run = substr($cell, $kindEnd, $scan['runEnd'] - $kindEnd);

            return $this->warning($rows, $byteAt, $validUtf8, $prefixCounts, $lineIndex, $offset + $kindEnd, strlen($run), $rule, sprintf('The table alignment run "%s" has no terminating space, so it is literal cell content. Add a space after the run%s to make it alignment.', $run, $suffix));
        }
        if ($scan['kind']) {
            return $this->warning($rows, $byteAt, $validUtf8, $prefixCounts, $lineIndex, $offset, $scan['blockEnd'] ?? 1, $rule, sprintf('The table kind marker has no terminating space, so it is literal cell content. Add a space after the marker run%s to make it a header.', $suffix));
        }
        $blockEnd = (int)$scan['blockEnd'];
        if (isset(BlockParser::TABLE_ALIGNMENT_MARKERS[$cell[$blockEnd] ?? ''])) {
            return null;
        }
        $attributes = substr($cell, 0, $blockEnd);

        return $this->warning($rows, $byteAt, $validUtf8, $prefixCounts, $lineIndex, $offset, $blockEnd, $rule, sprintf('The table cell attribute block "%s" has no terminating space, so it is literal cell content. Add a space after the block to apply it to the cell.', $attributes));
    }

    /**
     * @param list<array{string, int}> $rows
     * @param array<int, int>|null $byteAt
     * @param bool $validUtf8
     * @param array<int, array<int, int>> $prefixCounts
     * @param int $lineIndex
     * @param int $column
     * @param int $length
     * @param string $rule
     * @param string $message
     */
    private function warning(
        array $rows,
        ?array $byteAt,
        bool $validUtf8,
        array $prefixCounts,
        int $lineIndex,
        int $column,
        int $length,
        string $rule,
        string $message,
    ): LintWarning {
        $rowStart = $rows[$lineIndex][1];
        $start = $rowStart + $column;

        return new LintWarning(
            $lineIndex + 1,
            $validUtf8 ? SourceOffsets::toCodepoint($start, $byteAt) - SourceOffsets::toCodepoint($rowStart, $byteAt) + 1
                : (isset($prefixCounts[$lineIndex][$column])
                    ? $prefixCounts[$lineIndex][$column] + 1 : SourceOffsets::toColumn($rows[$lineIndex][0], $column)),
            $rule,
            $message,
            $start,
            $start + $length,
        );
    }
}
