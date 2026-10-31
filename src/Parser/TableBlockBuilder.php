<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use Closure;
use MarkupCarve\Carve\Ast\SourceSpan;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Block\TableRow;
use MarkupCarve\Carve\Node\Inline\CellWrap;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\Block\TableCellAccumulator;
use MarkupCarve\Carve\Parser\Utility\AttributeParser;
use MarkupCarve\Carve\Renderer\TableSpanGrid;
use MarkupCarve\Carve\Util\CycleCollection;

/**
 * Builds tables and resolves cell spans.
 *
 * @internal
 */
final class TableBlockBuilder
{
    /**
     * @param \MarkupCarve\Carve\Parser\BlockParserState $state
     * @param \MarkupCarve\Carve\Parser\BlockSourceMapper $source
     * @param \Closure(): \MarkupCarve\Carve\Parser\InlineParser $getInlineParser
     * @param \Closure(): \MarkupCarve\Carve\Parser\Block\TableParser $getTableParser
     * @param \Closure(\MarkupCarve\Carve\Node\Node): (void) $applyPendingAttributesCallback
     * @param \Closure(\MarkupCarve\Carve\Node\Block\Table): (void) $applyTableColumnsCallback
     * @param \Closure(array<string>, int, int): (bool) $canCloseCodeSpanWithContinuationsCallback
     * @param \Closure(string): (bool) $isPlainTextCallback
     * @param (\Closure(string, bool): (array{header: bool, align: string|null, valign: string|null, content: string}))|null $parseTableCellMarkerCallback
     * @param (\Closure(array<int, array{content: string, attributes: string, marker: string, offset: int|null, cellOffset?: int|null, verbatim: bool, rawLength: int|null, raw: string|null, sourceChunks?: list<array{int, int, string}>}>, array<int, \MarkupCarve\Carve\Node\Block\TableCell>): (array{cells: array<array{content: string, attributes: string, marker: string, colspan: int<1, max>, gridColumn: int, isEmpty: bool, spanMarker: string|null, offset: int|null, cellOffset?: int|null, rawLength: int|null, raw: string|null, verbatim: bool, sourceChunks: list<array{int, int, string}>}>, consumedRowspanColumns: array<int>, consumedColspanColumns: array<int>}))|null $resolveRowSpansCallback
     */
    public function __construct(
        private BlockParserState $state,
        private BlockSourceMapper $source,
        private Closure $getInlineParser,
        private Closure $getTableParser,
        private Closure $applyPendingAttributesCallback,
        private Closure $applyTableColumnsCallback,
        private Closure $canCloseCodeSpanWithContinuationsCallback,
        private Closure $isPlainTextCallback,
        private ?Closure $parseTableCellMarkerCallback = null,
        private ?Closure $resolveRowSpansCallback = null,
    ) {
    }

    /**
     * Parse a Carve table cell's tight alignment/header marker (written
     * tight against the pipe): optional `=` (header) then optional one of
     * `< > ~` (left/right/center). Returns the flags plus the content with
     * the marker stripped. Spaced markers (`| ^ |`, `| < |`) are span
     * markers, not alignment — their leading space means index 0 is not a
     * marker char, so they are left untouched here.
     *
     * @return array{header: bool, align: string|null, valign: string|null, content: string}
     */
    public function parseTableCellMarker(string $raw, bool $markerOnly = false): array
    {
        // The run's WIDTH is measured once, in the table parser, because
        // `parseTableCellsWithAttributes()` needs the same measurement to find
        // the attribute block that binds after it (PART 9 §5 T10). Only the
        // meaning is read here.
        $run = $markerOnly ? strlen($raw) : ($this->getTableParser)()->cellMarkerRunLength($raw);
        $prefix = substr($raw, 0, $run);
        // A leading `=` glued to the pipe marks a header cell and is stripped;
        // the remaining content is parsed inline. This holds even when the next
        // char is also `=` (`|==|` -> <th>=</th>, `|==x==|` -> header cell whose
        // content `=x==` renders <mark>x</mark>=), matching carve-js / carve-rs.
        // A SPACED `| ==x== |` is not a header cell: the leading space means
        // index 0 is not `=`, so it is left untouched here.
        $header = str_starts_with($prefix, '=');
        $markers = substr($prefix, $header ? 1 : 0);
        $inheritedHorizontal = str_starts_with($markers, '?');
        $align = null;
        $valign = null;
        foreach (str_split($markers) as $i => $marker) {
            if ($marker === '?') {
                continue;
            }
            if ($inheritedHorizontal && $i === 1) {
                $valign = match ($marker) {
                    '^' => TableCell::VALIGN_TOP,
                    '~' => TableCell::VALIGN_MIDDLE,
                    default => TableCell::VALIGN_BOTTOM,
                };

                continue;
            }
            if (isset(BlockGrammar::TABLE_ALIGNMENT_MARKERS[$marker])) {
                if ($align === null) {
                    $align = BlockGrammar::TABLE_ALIGNMENT_MARKERS[$marker];
                } elseif ($marker === '~' && $valign === null) {
                    $valign = TableCell::VALIGN_MIDDLE;
                }
            } elseif ($valign === null) {
                $valign = $marker === '^' ? TableCell::VALIGN_TOP : TableCell::VALIGN_BOTTOM;
            }
        }

        return ['header' => $header, 'align' => $align, 'valign' => $valign, 'content' => substr($raw, $run)];
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    public function tryParseTable(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];
        $count = count($lines);

        // Use TableParser to check if this is a valid table row
        if (!($this->getTableParser)()->isTableRow($line)) {
            // Check if it's a potential table row with unclosed code span
            // that might be closed by continuation rows
            if (!($this->getTableParser)()->isPotentialTableRowWithUnclosedCodeSpan($line)) {
                return null;
            }

            // Look ahead for continuation rows that might close the code span
            if (!$this->canCloseCodeSpanWithContinuations($lines, $start, $count)) {
                return null;
            }
        }

        $table = new Table();
        $i = $start;
        $alignments = [];
        // Per-column alignment from Carve header markers (|=>, |=~, |=<),
        // keyed by column position; propagates to the column's body cells.
        $columnAligns = [];
        $columnValigns = [];
        $headerFound = false;
        // Whether the most recently added data row's own line was shaped like
        // a separator ( |:-:| ): such a row must not be promoted to a header
        // by a following separator - carve-js / carve-rs treat both lines as
        // ordinary data rows.
        $lastRowSeparatorShaped = false;
        // Per-column "open" origin cell, carried down across rows so a `^`
        // marker extends it in O(1) instead of rescanning all prior rows.
        $columnOrigin = [];
        /** @var array<int, array{cell: \MarkupCarve\Carve\Node\Block\TableCell, cellData: array{content: string, attributes: string, marker: string, colspan: int<1, max>, gridColumn: int, isEmpty: bool, spanMarker: string|null, offset: int|null, cellOffset?: int|null, rawLength: int|null, raw: string|null, verbatim: bool, sourceChunks: list<array{int, int, string}>}, line: int, content: string, wraps: array<int, array{int, int, int}>, rowIndex: int, leadLine?: int}> $cellBuilds */
        $cellBuilds = [];
        /** @var array<int, true> $rejoined */
        $rejoined = [];

        while ($i < $count) {
            $currentLine = $lines[$i];

            // Strip row attributes for validation (|...|{.class} → |...|)
            $lineWithoutRowAttrs = ($this->getTableParser)()->stripRowAttributes($currentLine);

            // Trailing whitespace after the closing pipe is insignificant
            // (parity with carve-js / carve-rs).
            if (!preg_match('/^\|.*\|[ \t]*$/', $lineWithoutRowAttrs)) {
                break;
            }

            // Every separator cell contains a hyphen. Most table rows do not,
            // so avoid splitting and validating all their cells merely to ask
            // whether the row is the one GFM delimiter line. Reuse the answer
            // below instead of parsing the same row twice.
            $separator = str_contains($lineWithoutRowAttrs, '-')
                ? ($this->getTableParser)()->analyzeSeparatorRow($lineWithoutRowAttrs)
                : null;
            $separatorShaped = $separator !== null;

            // A GFM header separator is recognized ONLY as the table's second row
            // (exactly one row precedes it and no separator was seen yet): it makes
            // that first row the header. A delimiter line anywhere else -- leading,
            // or after the header/body -- is an ordinary data row. This matches
            // carve-js / carve-rs (the separator is the second row, period).
            if (
                $separatorShaped
                && count($table->getChildren()) === 1
                && !$headerFound
                && !$lastRowSeparatorShaped
            ) {
                $alignments = $separator['alignments'];
                $headerFound = true;

                // Store separator widths for round-trip preservation
                $separatorWidths = $separator['widths'];
                $table->setSeparatorWidths($separatorWidths);

                $this->promoteTableHeader($table, $alignments, $columnAligns, $columnValigns, $columnOrigin, $cellBuilds);
                $i++;

                continue;
            }

            // Extract row attributes (|...|{.class})
            $lastRowSeparatorShaped = $separatorShaped;
            $rowAttributes = ($this->getTableParser)()->extractRowAttributes($currentLine);

            // Parse cells with their attributes
            $cellsWithAttrs = ($this->getTableParser)()->parseTableCellsWithAttributes($currentLine);

            // Store cell contents and attributes for potential merging
            $mergedCells = array_map(fn ($c) => $c['content'], $cellsWithAttrs);
            $cellAttributes = array_map(fn ($c) => $c['attributes'], $cellsWithAttrs);
            $cellMarkers = array_map(fn ($c) => $c['marker'], $cellsWithAttrs);
            $cellSourceChunks = [];
            foreach ($cellsWithAttrs as $idx => $cell) {
                $cellSourceChunks[$idx] = $this->tableCellSourceChunks($i, $currentLine, $cell);
            }
            $baseLineForRow = $i;

            $i++;

            $cellAccumulator = null;
            $continuationLines = 0;
            $baseChunkCounts = array_map(count(...), $cellSourceChunks);
            $baseCells = $mergedCells;
            $rowWidth = count($baseCells);
            /** @var array<int, array{int, \MarkupCarve\Carve\Ast\SourceSpan|null}> $appendedCells continuation line, pipe */
            $appendedCells = [];
            // Check for continuation rows (lines starting with +)
            while ($i < $count && str_starts_with($lines[$i], '+') && ($this->getTableParser)()->isContinuationRow($lines[$i])) {
                $cellAccumulator ??= new TableCellAccumulator(($this->getTableParser)(), $mergedCells);
                $openRuns = $cellAccumulator->openDelimiters();
                $continuationCells = ($this->getTableParser)()->parseContinuationCells($lines[$i], $openRuns);
                // Text past the row's last column is a new cell of the row, and a
                // skipped slot before it an empty one; a trailing empty slot adds nothing (T6).
                $lastSlot = $rowWidth - 1;
                foreach ($continuationCells as $idx => $continuationCell) {
                    if ($idx > $lastSlot && trim($continuationCell, ' ') !== '') {
                        $lastSlot = $idx;
                    }
                }
                $continuationCells = array_slice($continuationCells, 0, $lastSlot + 1);
                if ($lastSlot >= $rowWidth) {
                    $pipes = $this->source->continuationSlotPipeSpans($i, $lines[$i], $openRuns);
                    for ($idx = $rowWidth; $idx <= $lastSlot; $idx++) {
                        $appendedCells[$idx] = [$continuationLines + 1, $pipes[$idx] ?? null];
                    }
                    $rowWidth = $lastSlot + 1;
                }
                foreach ($this->continuationCellSourceChunks($i, $lines[$i], $openRuns) as $idx => $chunks) {
                    if ($chunks === []) {
                        continue;
                    }
                    foreach ($chunks as $chunk) {
                        $cellSourceChunks[$idx][] = $chunk;
                    }
                }
                $cellAccumulator->append($continuationCells);
                $continuationLines++;
                $i++;
            }

            /** @var array<int, array{text: string, chunks: list<array{int, int, string}>, wraps: array<int, int>}> $markerJoins */
            $markerJoins = [];
            $cellWraps = $cellAccumulator?->wraps() ?? [];
            if ($cellAccumulator !== null) {
                $mergedCells = $cellAccumulator->contents();
                // Continuation text on a span marker belongs to the cell the marker
                // resolves into, which the span walk names below (T6).
                foreach ($baseCells as $idx => $rawBase) {
                    $base = trim($rawBase, ' ');
                    $merged = $mergedCells[$idx] ?? $base;
                    if (
                        $merged === $base
                        || ($cellAttributes[$idx] ?? '') !== ''
                        || !(($this->getTableParser)()->isRowspanMarker($base) || ($this->getTableParser)()->isColspanMarker($rawBase))
                    ) {
                        continue;
                    }
                    // Offsets relative to the joined text; -1 is the join itself.
                    $joinWraps = [];
                    foreach ($cellWraps[$idx] ?? [] as $at => $wrapLine) {
                        $joinWraps[$at - strlen($base) - 1] = $wrapLine;
                    }
                    unset($cellWraps[$idx]);
                    $markerJoins[$idx] = [
                        'text' => trim(substr($merged, strlen($base)), ' '),
                        'chunks' => array_slice($cellSourceChunks[$idx] ?? [], $baseChunkCounts[$idx] ?? 0),
                        'wraps' => $joinWraps,
                    ];
                    $mergedCells[$idx] = $base;
                    $cellSourceChunks[$idx] = array_slice($cellSourceChunks[$idx] ?? [], 0, $baseChunkCounts[$idx] ?? 0);
                }
            }

            // Rebuild cellsWithAttrs with merged content
            $mergedCellsWithAttrs = [];
            foreach ($mergedCells as $idx => $content) {
                // A cell whose content was merged from a continuation row is no
                // longer a run of THIS line, so it keeps the offset but loses
                // the claim to be a verbatim slice, and declines a position.
                $original = $cellsWithAttrs[$idx] ?? null;
                $mergedCellsWithAttrs[] = [
                    'content' => $content,
                    'attributes' => $cellAttributes[$idx] ?? '',
                    'marker' => $cellMarkers[$idx] ?? '',
                    'offset' => $original === null ? null : $original['offset'],
                    // Carried alongside `offset` everywhere a cell array is
                    // rebuilt: it is the one `rawLength` measures from.
                    'cellOffset' => $original === null ? null : $original['cellOffset'],
                    'rawLength' => $original === null ? null : $original['rawLength'],
                    'raw' => $original === null ? null : $original['raw'],
                    'verbatim' => $original !== null
                        && $original['verbatim']
                        && $content === $original['content'],
                    'sourceChunks' => $cellSourceChunks[$idx] ?? [],
                ];
            }

            // Resolve `<`/`^` span markers into the row's output cells with the
            // same single grid walk the carve-js renderer uses (see resolveRowSpans).
            // Every column keeps its own placeholder cell now (carve-js parity,
            // uniform row width); a column a marker actually merged into a
            // target - as opposed to a degenerate marker with no target - is
            // recorded here so it stays invisible to the header-row check and
            // to column-origin tracking below, exactly as it was before it had
            // a cell of its own.
            $resolved = $this->callResolveRowSpans(array_diff_key($mergedCellsWithAttrs, $appendedCells), $columnOrigin);
            $processedCells = $resolved['cells'];
            // An appended cell is text, never a span or alignment marker.
            foreach (array_intersect_key($mergedCellsWithAttrs, $appendedCells) as $col => $appendedData) {
                $processedCells[] = [
                    ...$appendedData,
                    'colspan' => 1,
                    'gridColumn' => $col,
                    'isEmpty' => false,
                    'spanMarker' => null,
                    'verbatim' => false,
                ];
            }
            $consumedRowspanColumns = $resolved['consumedRowspanColumns'];
            $consumedColspanColumns = $resolved['consumedColspanColumns'];
            $consumedColumnSet = array_flip(array_merge($consumedRowspanColumns, $consumedColspanColumns));

            // Carve header row: every cell is "=" prefixed (|= Header |).
            // No separator row is used. Whether a cell opens with a header
            // MARKER is the run measurement's question (PART 9 §5 T11: the run
            // ends at a space), not a second regex here - which is also what
            // keeps "==x==" a normal cell holding a highlight, without a
            // special case saying so.
            $isHeaderRow = $processedCells !== [];
            // A row where every column is consumed by a span (an all-`^`
            // rowspan continuation, say) has NOTHING left to examine below,
            // so it must not default to header-row-true just because it is
            // non-empty - before every marker kept its own cell, such a row
            // WAS empty and this defaulted correctly. `$examinedAny` restores
            // that: no examined cell means this is never a Carve header row.
            $examinedAny = false;
            foreach ($processedCells as $cellData) {
                if (isset($consumedColumnSet[$cellData['gridColumn']]) || isset($appendedCells[$cellData['gridColumn']])) {
                    // A placeholder consumed by another cell's span was never
                    // its own entry before it got a cell of its own; it still
                    // is not examined here (carve-js parity).
                    continue;
                }
                $examinedAny = true;
                $content = $cellData['content'];
                // An empty span cell (a `<`/`^` that became its own slot) is
                // never a `|=` header cell. A cell carrying an attribute block
                // now can be: PART 9 §5 T10 puts the block AFTER the marker
                // run, so `|={.total} Total |` is a header cell and the row it
                // sits in is a Carve all-header row. The marker the split
                // already stripped is what decides it.
                if (
                    $cellData['isEmpty']
                    || ($cellData['attributes'] !== ''
                        ? !str_starts_with($cellData['marker'], '=')
                        : !(str_starts_with($content, '=')
                            && ($this->getTableParser)()->cellMarkerRunLength($content) > 0))
                ) {
                    $isHeaderRow = false;

                    break;
                }
            }
            if (!$examinedAny) {
                $isHeaderRow = false;
            }

            // Parse regular row
            $row = new TableRow($isHeaderRow);
            $row->setContinuationLines($continuationLines);
            $rowIndex = count($table->getChildren());
            $rowStartSpan = $this->tableLineSpan($baseLineForRow);
            $rowEndSpan = $this->wholeLineSpan($i - 1);
            if ($rowStartSpan !== null && $rowEndSpan !== null) {
                $row->setPos(new SourceSpan(
                    startLine: $rowStartSpan->startLine,
                    endLine: $rowEndSpan->endLine,
                    startColumn: $rowStartSpan->startColumn,
                    endColumn: $rowEndSpan->endColumn,
                    startOffset: $rowStartSpan->startOffset,
                    endOffset: $rowEndSpan->endOffset,
                ));
            }
            if ($rowAttributes) {
                $row->setAttributes($rowAttributes);
            }

            // Build the row's cells. Spans are already resolved in the grid above,
            // so every entry in $processedCells emits exactly one cell; its
            // gridColumn keys per-column alignment, matching the carve-js renderer.
            /** @var array<array{cell: \MarkupCarve\Carve\Node\Block\TableCell, colPosition: int}> $rowCellData */
            $rowCellData = [];
            foreach ($processedCells as $cellData) {
                $colspan = $cellData['colspan'];
                $col = $cellData['gridColumn'];

                if ($cellData['isEmpty']) {
                    // A `<`/`^` marker that became an empty cell of its own (left
                    // edge, or a degenerate `^` with no cell above). It occupies
                    // its grid position and is never dropped. It still takes the
                    // column's alignment -- the Carve header marker first, then the
                    // GFM separator alignment -- so an empty span cell lines up with
                    // the real cells in its column (carve-js parity).
                    $alignment = $columnAligns[$col]
                        ?? $alignments[$col]
                        ?? TableCell::ALIGN_DEFAULT;
                    // The alignment here is the COLUMN's, taken so the empty
                    // cell lines up; the cell carries no marker of its own, so
                    // it is not explicit.
                    $cell = new TableCell(false, $alignment, 1, $colspan, null, false);
                    $cell->setSpanMarker($cellData['spanMarker']);
                    // The marker character occupies a real slice of this line,
                    // so the cell gets a position the same way an ordinary cell
                    // does (carve-php#510).
                    $cell->setPos($this->cellExtentSpan($baseLineForRow, $cellData));
                    $row->appendChild($cell);
                    // A column this marker actually merged into a target does
                    // NOT become that column's open origin -- the origin
                    // already open above (or to the left) stays the one a
                    // later `^` extends (matches carve-js: a consumed grid
                    // entry is excluded from `lastNonSkip`). Only a degenerate
                    // marker (no target) is eligible, same as before it kept
                    // its own row slot.
                    if (!isset($consumedColumnSet[$col])) {
                        $rowCellData[] = ['cell' => $cell, 'colPosition' => $col];
                        // A blocked marker can still take continuation text.
                        $blockedData = $cellData;
                        $blockedData['sourceChunks'] = [];
                        $cellBuilds[spl_object_id($cell)] = ['cell' => $cell, 'cellData' => $blockedData, 'line' => $baseLineForRow, 'content' => '', 'wraps' => [], 'rowIndex' => $rowIndex];
                    }

                    continue;
                }

                if (isset($appendedCells[$col])) {
                    [$appendLine, $pipe] = $appendedCells[$col];
                    $cell = new TableCell($isHeaderRow, $columnAligns[$col] ?? $alignments[$col] ?? TableCell::ALIGN_DEFAULT, 1, 1, null, false);
                    $trimmedContent = trim($cellData['content'], ' ');
                    $wraps = $this->contentWraps($cellData['content'], $trimmedContent, $cellWraps[$col] ?? []);
                    $this->fillCellContent($cell, $cellData, $baseLineForRow, $trimmedContent, $wraps);
                    $wrap = new CellWrap($appendLine);
                    $wrap->setPos($pipe);
                    $cell->prependChild($wrap);
                    $cell->setPos(null);
                    $this->state->session->unplaceableNodeIds[spl_object_id($cell)] = true;
                    $row->appendChild($cell);
                    $rowCellData[] = ['cell' => $cell, 'colPosition' => $col];

                    continue;
                }

                // Parse the tight alignment/header marker. A header row fixes
                // per-column alignment; a cell's own marker overrides it; a djot
                // separator row is the final fallback.
                //
                // A cell carrying a `{...}` attribute block reads its markers
                // from the run the split already took off the FRONT of the
                // block (PART 9 §5 T10), never from what follows it: everything
                // after the block is content, which is what keeps the `<` in
                // `|{#x}< content |` literal and the `=` in `|{#x}=R|` text.
                $attributed = $cellData['attributes'] !== '';
                $marker = $this->callParseTableCellMarker($attributed ? $cellData['marker'] : $cellData['content'], $attributed);
                if ($attributed) {
                    $marker['content'] = $cellData['content'];
                }
                if ($isHeaderRow && $marker['align'] !== null) {
                    $columnAligns[$col] = $marker['align'];
                }
                $alignment = $marker['align']
                    ?? $columnAligns[$col]
                    ?? $alignments[$col]
                    ?? TableCell::ALIGN_DEFAULT;
                // A cell carries its own `=` marker even in a body row, so a
                // `|=` cell in a data row becomes a row header (<th> inside
                // <tbody>). The row stays a body row; only the cell is a header.
                // The cell's OWN alignment, which is what the writers and the
                // ProseMirror bridge may spell: its marker, or - on a header
                // cell - the GFM delimiter row, which is where `|:---|---:|`
                // declares the column. A body cell that merely INHERITS the
                // column's alignment carries none of its own, which is the
                // shape carve-rs publishes.
                $explicitAlign = $marker['align'] !== null
                    || (($isHeaderRow || $marker['header'])
                        && ($alignments[$col] ?? TableCell::ALIGN_DEFAULT) !== TableCell::ALIGN_DEFAULT);
                $cell = new TableCell(
                    $isHeaderRow || $marker['header'],
                    $alignment,
                    1,
                    $colspan,
                    null,
                    $explicitAlign,
                );
                if ($marker['valign'] !== null) {
                    $cell->setVerticalAlignment($marker['valign']);
                    if ($isHeaderRow) {
                        $columnValigns[$col] = $marker['valign'];
                    }
                } elseif (isset($columnValigns[$col])) {
                    $cell->setVerticalAlignment($columnValigns[$col], false);
                }
                if ($cellData['attributes'] !== '') {
                    // Apply in source order (matching inline attributes and
                    // carve-js), not via setAttributes() which reorders.
                    AttributeParser::applyToNode($cell, $cellData['attributes']);
                }
                $trimmedContent = trim($marker['content'], ' ');
                $wraps = $this->contentWraps($cellData['content'], $trimmedContent, $cellWraps[$col] ?? []);
                $this->fillCellContent($cell, $cellData, $baseLineForRow, $trimmedContent, $wraps);
                $row->appendChild($cell);
                $rowCellData[] = ['cell' => $cell, 'colPosition' => $col];
                $cellBuilds[spl_object_id($cell)] = ['cell' => $cell, 'cellData' => $cellData, 'line' => $baseLineForRow, 'content' => $trimmedContent, 'wraps' => $wraps, 'rowIndex' => $rowIndex];
            }

            // A marker's continuation text joins the cell it resolved into, in
            // row-major order; a blocked marker renders empty and keeps none.
            if ($markerJoins !== []) {
                $rowspanSet = array_flip($consumedRowspanColumns);
                ksort($markerJoins);
                foreach ($markerJoins as $col => $join) {
                    $origin = null;
                    if (isset($consumedColumnSet[$col]) && !isset($rowspanSet[$col])) {
                        foreach ($rowCellData as $cellInfo) {
                            if ($cellInfo['colPosition'] <= $col) {
                                $origin = $cellInfo['cell'];
                            }
                        }
                    } elseif (isset($rowspanSet[$col])) {
                        for ($k = $col; $k >= 0 && $origin === null && isset($rowspanSet[$k]); $k--) {
                            $origin = $columnOrigin[$k] ?? null;
                        }
                    }
                    // A blocked marker is a cell of its own, and its continuation
                    // text is that cell's content (T5, carve#3151).
                    if ($origin === null && !isset($consumedColumnSet[$col]) && !isset($rowspanSet[$col])) {
                        foreach ($rowCellData as $cellInfo) {
                            if ($cellInfo['colPosition'] === $col) {
                                $origin = $cellInfo['cell'];
                            }
                        }
                    }
                    if (!$origin instanceof TableCell || $join['text'] === '' || !isset($cellBuilds[spl_object_id($origin)])) {
                        continue;
                    }
                    $build = &$cellBuilds[spl_object_id($origin)];
                    $joinAt = $build['content'] === '' ? 0 : strlen($build['content']) + 1;
                    $rowsBelow = $rowIndex - $build['rowIndex'];
                    // Text a blocked marker keeps from a later `+` line names that line.
                    if ($joinAt === 0 && $origin->getSpanMarker() !== null && ($join['wraps'][-1] ?? 1) > 1) {
                        $build['leadLine'] = $join['wraps'][-1];
                    }
                    foreach ($join['wraps'] as $at => $wrapLine) {
                        if ($at >= 0 || $joinAt > 0) {
                            $build['wraps'][$joinAt + $at] = [$wrapLine, $rowsBelow, $col - $build['cellData']['gridColumn']];
                        }
                    }
                    $build['content'] = $build['content'] === '' ? $join['text'] : $build['content'] . ' ' . $join['text'];
                    $build['cellData']['sourceChunks'] = array_merge($build['cellData']['sourceChunks'], $join['chunks']);
                    $build['cellData']['verbatim'] = false;
                    unset($build);
                    $rejoined[spl_object_id($origin)] = true;
                }
            }

            // Each `^` the walk resolved grows the cell above it by one row; the
            // rest of its row strip resolved into the same cell.
            $nextOrigin = [];
            foreach ($consumedRowspanColumns as $col) {
                $origin = $columnOrigin[$col] ?? null;
                if ($origin instanceof TableCell) {
                    $origin->setRowspan($origin->getRowspan() + 1);
                    $nextOrigin[$col] = $origin;
                }
            }

            // The next row's `^` resolves against the cell covering its column
            // whose left edge is that column: a cell this row starts, or one a
            // `^` just carried down. A column a colspan merely covers has none.
            foreach ($rowCellData as $cellInfo) {
                $nextOrigin[$cellInfo['colPosition']] = $cellInfo['cell'];
            }
            $columnOrigin = $nextOrigin;
            // Only a column origin can still absorb a later row's marker.
            $live = $rejoined;
            foreach ($columnOrigin as $origin) {
                $live[spl_object_id($origin)] = true;
            }
            $cellBuilds = array_intersect_key($cellBuilds, $live);

            $table->appendChild($row);
            CycleCollection::checkpoint();
        }

        foreach (array_keys($rejoined) as $id) {
            $build = $cellBuilds[$id];
            foreach ($build['cell']->getChildren() as $child) {
                $build['cell']->removeChild($child);
            }
            $this->fillCellContent($build['cell'], $build['cellData'], $build['line'], $build['content'], $build['wraps']);
            if (isset($build['leadLine']) && $build['cell']->getChildren() !== []) {
                $build['cell']->prependChild(new CellWrap($build['leadLine']));
            }
            $rowEnd = $build['cell']->getParent()?->getPos()?->endOffset;
            if ($rowEnd !== null) {
                // Text joined from a later row is reassembled and has no position in this row.
                $pending = $build['cell']->getChildren();
                while ($pending !== []) {
                    $part = array_pop($pending);
                    if ($part->getPos()?->startOffset >= $rowEnd) {
                        $part->setPos(null);
                    }
                    array_push($pending, ...$part->getChildren());
                }
            }
        }

        // A separator-only table is valid (creates empty table)
        // Only return null if we didn't parse anything at all
        if (count($table->getChildren()) === 0 && !$headerFound) {
            return null;
        }

        $this->applyPendingAttributes($table);
        // An authored `header-rows` / `footer-rows` is EXPLICIT structure, so
        // the partition goes on the node - and from there onto the wire - rather
        // than staying an attribute every foreign reader has to reinterpret
        // (markup-carve/carve-php#2633). Set after the attributes arrive and
        // after every row is appended, because the partition is measured against
        // the row count.
        $table->setRowGroups($table->statedRowGroups());
        $this->applyHeaderColumnDefaults($table, $alignments);
        $this->applyTableColumns($table);
        $rows = $table->getChildren();
        if ($rows !== []) {
            $first = $this->tableLineSpan($start);
            $last = $this->wholeLineSpan(max($start, $i - 1));
            if ($first !== null && $last !== null) {
                $table->setPos(new SourceSpan(
                    startLine: $first->startLine,
                    endLine: $last->endLine,
                    startColumn: $first->startColumn,
                    endColumn: $last->endColumn,
                    startOffset: $first->startOffset,
                    endOffset: $last->endOffset,
                ));
            }
        }
        $parent->appendChild($table);

        // Caption parsing is now handled by tryParseCaption

        return $i - $start;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Block\Table $table
     * @param array<int, string> $delimiterAlignments
     */
    private function applyHeaderColumnDefaults(Table $table, array $delimiterAlignments): void
    {
        $rows = $table->getChildren();
        $groups = $table->getRowGroups();
        $headRows = $groups['headRows'] ?? 0;
        if ($groups === null) {
            foreach ($rows as $row) {
                if (!$row instanceof TableRow || !$row->isHeader()) {
                    break;
                }
                $headRows++;
            }
        }

        $horizontal = $headRows > 0 ? $delimiterAlignments : [];
        $vertical = [];
        foreach (array_slice($rows, 0, $headRows) as $row) {
            if (!$row instanceof TableRow) {
                continue;
            }
            foreach ($row->getChildren() as $column => $cell) {
                if (!$cell instanceof TableCell || !$cell->isHeader()) {
                    continue;
                }
                if ($cell->hasExplicitAlignment()) {
                    $horizontal[$column] = $cell->getAlignment();
                }
                if ($cell->hasExplicitVerticalAlignment()) {
                    $vertical[$column] = $cell->getVerticalAlignment();
                }
            }
        }

        foreach ($rows as $row) {
            if (!$row instanceof TableRow) {
                continue;
            }
            foreach ($row->getChildren() as $column => $cell) {
                if (!$cell instanceof TableCell) {
                    continue;
                }
                if (!$cell->hasExplicitAlignment()) {
                    $cell->setAlignment($horizontal[$column] ?? TableCell::ALIGN_DEFAULT, false);
                }
                if (!$cell->hasExplicitVerticalAlignment()) {
                    $cell->setVerticalAlignment($vertical[$column] ?? TableCell::VALIGN_DEFAULT, false);
                }
            }
        }
    }

    /**
     * Move a cell's join offsets from its merged content onto the trimmed
     * content the inline parser reads.
     *
     * @param string $content
     * @param string $trimmed
     * @param array<int, int> $wraps
     *
     * @return array<int, array{int, int, int}>
     */
    private function contentWraps(string $content, string $trimmed, array $wraps): array
    {
        if ($wraps === []) {
            return [];
        }
        $delta = strlen(rtrim($content, ' ')) - strlen($trimmed);
        if ($delta < 0 || substr($content, $delta, strlen($trimmed)) !== $trimmed) {
            return [];
        }
        $mapped = [];
        foreach ($wraps as $at => $line) {
            $at -= $delta;
            if ($at > 0 && ($trimmed[$at] ?? '') === ' ') {
                $mapped[$at] = [$line, 0, 0];
            }
        }

        return $mapped;
    }

    /**
     * Fill a cell's inline content and position from its collected source.
     *
     * @param \MarkupCarve\Carve\Node\Block\TableCell $cell
     * @param array{content: string, attributes: string, marker: string, colspan: int<1, max>, gridColumn: int, isEmpty: bool, spanMarker: string|null, offset: int|null, cellOffset?: int|null, rawLength: int|null, raw: string|null, verbatim: bool, sourceChunks: list<array{int, int, string}>} $cellData
     * @param int $baseLineForRow
     * @param string $trimmedContent
     * @param array<int, array{int, int, int}> $wraps
     */
    private function fillCellContent(TableCell $cell, array $cellData, int $baseLineForRow, string $trimmedContent, array $wraps = []): void
    {
        $cellMap = $this->cellSourceMap($baseLineForRow, $cellData, $trimmedContent)
            ?? $this->rebuiltCellSourceMap($cellData, $trimmedContent);
        $cellSpan = $this->cellExtentSpan($baseLineForRow, $cellData);
        // A cell that took text from a continuation line spans lines its
        // siblings also cross, so it declines a position, as in carve-js.
        $baseSourceLine = $this->source->sourceLineFor($baseLineForRow);
        $joined = false;
        foreach ($cellData['sourceChunks'] as $chunk) {
            $joined = $joined || $chunk[0] !== $baseSourceLine;
        }
        if ($joined || count($cellData['sourceChunks']) > 1) {
            $cellSpan = null;
            $this->state->session->unplaceableNodeIds[spl_object_id($cell)] = true;
        }
        // The text's OWN extent inside the cell: the cell span covers
        // the padding too, so the text is located within the raw slice
        // the split kept for exactly this.
        $cellTextSpan = $this->cellContentSpan($baseLineForRow, $cellData, $trimmedContent);
        if ($wraps !== []) {
            ($this->getInlineParser)()->parseTableCell($cell, $trimmedContent, $baseLineForRow, $cellMap, $wraps);
            $this->placeCellWraps($cell, $cellData['sourceChunks']);
            $cell->setPos($joined ? null : ($cellSpan ?? ($cellMap?->spanFor(0, $trimmedContent))));

            return;
        }
        if ($trimmedContent !== '' && $this->isPlainTableText($trimmedContent) && $this->appendPlainRebuiltCellText($cell, $cellData, $trimmedContent)) {
            $cell->setPos($joined ? null : ($cellSpan ?? ($cellMap?->spanFor(0, $trimmedContent))));

            return;
        }
        if ($trimmedContent !== '' && $this->isPlainText($trimmedContent)) {
            $text = new Text($trimmedContent);
            $text->setPos($cellMap?->spanFor(0, $trimmedContent) ?? $cellTextSpan);
            $cell->appendChild($text);
        } else {
            ($this->getInlineParser)()->parse($cell, $trimmedContent, $baseLineForRow, sourceMap: $cellMap);
        }
        // Prefer the measured extent: it covers the cell even when its
        // text was rewritten (an escaped pipe), where a text lookup
        // cannot match and rightly declines.
        $cell->setPos($joined ? null : ($cellSpan ?? ($cellMap?->spanFor(0, $trimmedContent))));
    }

    /**
     * Give each `cell_wrap` the source byte it stands on. The k-th wrap joins
     * the fragment of the (k+1)-th source chunk; a mismatch leaves them unplaced.
     *
     * @param \MarkupCarve\Carve\Node\Block\TableCell $cell
     * @param list<array{int, int, string}> $chunks
     */
    private function placeCellWraps(TableCell $cell, array $chunks): void
    {
        $wraps = [];
        $walk = static function (Node $node) use (&$walk, &$wraps): void {
            foreach ($node->getChildren() as $child) {
                if ($child instanceof CellWrap) {
                    $wraps[] = $child;
                }
                $walk($child);
            }
        };
        $walk($cell);
        if ($wraps === [] || count($wraps) !== count($chunks) - 1) {
            return;
        }
        foreach ($wraps as $k => $wrap) {
            [$line, $column] = $chunks[$k + 1];
            $wrap->setPos($this->source->cellWrapSpan($line, $column, $chunks[$k][0] === $line));
        }
    }

    /**
     * Resolve a table row's `<`/`^` span markers into output cells using the
     * same single LEFT-TO-RIGHT grid walk the carve-js renderer uses (carve spec
     * section 96).
     *
     * @param array<int, array{content: string, attributes: string, marker: string, offset: int|null, cellOffset?: int|null, verbatim: bool, rawLength: int|null, raw: string|null, sourceChunks?: list<array{int, int, string}>}> $mergedCellsWithAttrs
     * @param array<int, \MarkupCarve\Carve\Node\Block\TableCell> $columnOrigin Per-column open
     *   origin cell carried down from earlier rows.
     *
     * @return array{cells: array<array{content: string, attributes: string, marker: string, colspan: int<1, max>, gridColumn: int, isEmpty: bool, spanMarker: string|null, offset: int|null, cellOffset?: int|null, rawLength: int|null, raw: string|null, verbatim: bool, sourceChunks: list<array{int, int, string}>}>, consumedRowspanColumns: array<int>, consumedColspanColumns: array<int>}
     */
    public function resolveRowSpans(array $mergedCellsWithAttrs, array $columnOrigin): array
    {
        // The T5 walk is shared with the renderer and the list-table extension
        // (TableSpanGrid::walkRow). $columnOrigin names, per column, the cell
        // covering that column in the previous row whose left edge is it.
        $markers = [];
        foreach ($mergedCellsWithAttrs as $col => $cellData) {
            // A cell carrying attributes is never a bare span marker, so its
            // `<`/`^` content is literal (carve-js / carve-rs parity).
            $marker = null;
            if ($cellData['attributes'] === '') {
                if (($this->getTableParser)()->isColspanMarker($cellData['content'])) {
                    $marker = '<';
                } elseif (($this->getTableParser)()->isRowspanMarker($cellData['content'])) {
                    $marker = '^';
                }
            }
            $markers[$col] = $marker;
        }
        $above = [];
        foreach ($columnOrigin as $col => $origin) {
            $above[$col] = $origin->getColspan();
        }
        $walk = TableSpanGrid::walkRow($markers, $above);
        $skip = $walk['skip'];
        $colspan = $walk['colspan'];
        $spanMarkers = $markers;
        $empty = array_map(static fn (?string $marker): bool => $marker !== null, $markers);
        $consumedRowspanColumns = $walk['rowspanSkips'];
        $consumedColspanColumns = $walk['colspanSkips'];

        // Every column emits a cell now - a consumed column's own width is 1;
        // the width it contributed lives on the cell it merged into.
        $cells = [];
        foreach ($mergedCellsWithAttrs as $col => $cellData) {
            $isEmpty = $empty[$col] ?? false;
            $width = ($skip[$col] ?? false) ? 1 : ($colspan[$col] ?? 1);
            $cells[] = [
                'content' => $isEmpty ? '' : $cellData['content'],
                'attributes' => $isEmpty ? '' : $cellData['attributes'],
                'marker' => $isEmpty ? '' : $cellData['marker'],
                'colspan' => max(1, $width),
                'gridColumn' => $col,
                'isEmpty' => $isEmpty,
                'spanMarker' => $spanMarkers[$col],
                // A span marker (`^`/`<`) is still a real character the author
                // wrote at a real column on this line, so the cell built from
                // this entry CAN carry a position - only its CONTENT is
                // suppressed, because a marker is not text (carve-php#510).
                // `verbatim` alone stays false: an empty cell has no text to map
                // inline attributes or spans against.
                'offset' => $cellData['offset'],
                'cellOffset' => $cellData['cellOffset'] ?? $cellData['offset'],
                'rawLength' => $cellData['rawLength'],
                'raw' => $cellData['raw'],
                'verbatim' => !$isEmpty && $cellData['verbatim'],
                'sourceChunks' => $isEmpty ? [] : ($cellData['sourceChunks'] ?? []),
            ];
        }

        return [
            'cells' => $cells,
            'consumedRowspanColumns' => $consumedRowspanColumns,
            'consumedColspanColumns' => $consumedColspanColumns,
        ];
    }

    /**
     * @param \MarkupCarve\Carve\Node\Block\TableCell $cell
     * @param array{sourceChunks?: list<array{int, int, string}>} $cellData
     * @param string $content
     */
    private function appendPlainRebuiltCellText(TableCell $cell, array $cellData, string $content): bool
    {
        return $this->source->appendPlainRebuiltCellText($cell, $cellData, $content);
    }

    private function applyPendingAttributes(Node $node): void
    {
        ($this->applyPendingAttributesCallback)($node);
    }

    private function applyTableColumns(Table $table): void
    {
        ($this->applyTableColumnsCallback)($table);
    }

    /**
     * @param array<string> $lines All lines
     * @param int $start Starting line index
     * @param int $count Total line count
     *
     * @return bool True if continuation rows can close the code spans
     */
    private function canCloseCodeSpanWithContinuations(array $lines, int $start, int $count): bool
    {
        return ($this->canCloseCodeSpanWithContinuationsCallback)($lines, $start, $count);
    }

    /**
     * @param int $index
     * @param array{content: string, attributes: string, offset?: int|null, cellOffset?: int|null, verbatim?: bool, rawLength?: int|null, raw?: string|null} $cellData
     * @param string $content
     */
    private function cellContentSpan(int $index, array $cellData, string $content): ?SourceSpan
    {
        return $this->source->cellContentSpan($index, $cellData, $content);
    }

    /**
     * @param int $index
     * @param array{content: string, attributes: string, offset?: int|null, cellOffset?: int|null, verbatim?: bool, rawLength?: int|null, raw?: string|null} $cellData
     */
    private function cellExtentSpan(int $index, array $cellData): ?SourceSpan
    {
        return $this->source->cellExtentSpan($index, $cellData);
    }

    /**
     * @param int $index
     * @param array{content: string, attributes: string, offset?: int|null, verbatim?: bool, rawLength?: int|null} $cellData
     * @param string $content
     */
    private function cellSourceMap(int $index, array $cellData, string $content): ?SourceMap
    {
        return $this->source->cellSourceMap($index, $cellData, $content);
    }

    /**
     * @param int $index
     * @param string $line
     * @param array<int, int> $openDelimiters Verbatim run width left open by the row above, by cell index.
     *
     * @return array<int, list<array{int, int, string}>>
     */
    private function continuationCellSourceChunks(int $index, string $line, array $openDelimiters = []): array
    {
        return $this->source->continuationCellSourceChunks($index, $line, $openDelimiters);
    }

    private function isPlainText(string $text): bool
    {
        return ($this->isPlainTextCallback)($text);
    }

    private function isPlainTableText(string $text): bool
    {
        return strpbrk($text, "\\`*_[{^~<\$:!\"'-\n/,=") === false;
    }

    /**
     * @param array{sourceChunks?: list<array{int, int, string}>} $cellData
     * @param string $content
     */
    private function rebuiltCellSourceMap(array $cellData, string $content): ?SourceMap
    {
        return $this->source->rebuiltCellSourceMap($cellData, $content);
    }

    /**
     * @param int $index
     * @param string $line The row as the collector holds it, which may be a strip.
     * @param array{content: string, offset?: int|null} $cellData
     *
     * @return list<array{int, int, string}> source line, source column, text
     */
    private function tableCellSourceChunks(int $index, string $line, array $cellData): array
    {
        return $this->source->tableCellSourceChunks($index, $line, $cellData);
    }

    private function tableLineSpan(int $index): ?SourceSpan
    {
        return $this->source->tableLineSpan($index);
    }

    private function wholeLineSpan(int $index): ?SourceSpan
    {
        return $this->source->wholeLineSpan($index);
    }

    /**
     * @return array{header: bool, align: string|null, valign: string|null, content: string}
     */
    private function callParseTableCellMarker(string $raw, bool $markerOnly = false): array
    {
        if ($this->parseTableCellMarkerCallback !== null) {
            return ($this->parseTableCellMarkerCallback)($raw, $markerOnly);
        }

        return $this->parseTableCellMarker($raw, $markerOnly);
    }

    /**
     * @param array<int, array{content: string, attributes: string, marker: string, offset: int|null, cellOffset?: int|null, verbatim: bool, rawLength: int|null, raw: string|null, sourceChunks?: list<array{int, int, string}>}> $mergedCellsWithAttrs
     * @param array<int, \MarkupCarve\Carve\Node\Block\TableCell> $columnOrigin Per-column open
     *
     * @return array{cells: array<array{content: string, attributes: string, marker: string, colspan: int<1, max>, gridColumn: int, isEmpty: bool, spanMarker: string|null, offset: int|null, cellOffset?: int|null, rawLength: int|null, raw: string|null, verbatim: bool, sourceChunks: list<array{int, int, string}>}>, consumedRowspanColumns: array<int>, consumedColspanColumns: array<int>}
     */
    private function callResolveRowSpans(array $mergedCellsWithAttrs, array $columnOrigin): array
    {
        if ($this->resolveRowSpansCallback !== null) {
            return ($this->resolveRowSpansCallback)($mergedCellsWithAttrs, $columnOrigin);
        }

        return $this->resolveRowSpans($mergedCellsWithAttrs, $columnOrigin);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Block\Table $table
     * @param array<int, string|null> $alignments
     * @param array<int, string|null> $columnAligns
     * @param array<int, string|null> $columnValigns
     * @param array<int, \MarkupCarve\Carve\Node\Block\TableCell> $columnOrigin
     * @param array<int, array{cell: \MarkupCarve\Carve\Node\Block\TableCell, cellData: array{content: string, attributes: string, marker: string, colspan: int<1, max>, gridColumn: int, isEmpty: bool, spanMarker: string|null, offset: int|null, cellOffset?: int|null, rawLength: int|null, raw: string|null, verbatim: bool, sourceChunks: list<array{int, int, string}>}, line: int, content: string, wraps: array<int, array{int, int, int}>, rowIndex: int, leadLine?: int}> $cellBuilds
     */
    private function promoteTableHeader(
        Table $table,
        array $alignments,
        array &$columnAligns,
        array &$columnValigns,
        array &$columnOrigin,
        array &$cellBuilds,
    ): void {
        // Mark previous row as header and apply alignments to it
        $children = $table->getChildren();
        if ($children !== []) {
            $lastRow = $children[count($children) - 1];
            if ($lastRow instanceof TableRow) {
                // Recreate as header row with alignments
                $headerRow = new TableRow(true);
                $headerRow->setContinuationLines($lastRow->getContinuationLines());
                // Preserve row attributes from original row
                $headerRow->setAttributes($lastRow->getAttributeEntries());
                // Same source, so the same span; it is a re-typing of
                // the row that was already parsed, not a new one.
                $headerRow->setPos($lastRow->getPos());
                // Every column has its own cell now (placeholders
                // included), so the cell's position in the row IS its
                // grid column - no more `+= colspan` accounting for
                // columns a merge dropped from the array.
                $cellIndex = 0;
                foreach ($lastRow->getChildren() as $cell) {
                    if ($cell instanceof TableCell) {
                        if ($cell->hasExplicitAlignment()) {
                            $columnAligns[$cellIndex] = $cell->getAlignment();
                        }
                        $alignment = $columnAligns[$cellIndex]
                            ?? $alignments[$cellIndex]
                            ?? $cell->getAlignment();
                        // The delimiter row is where a GFM table
                        // DECLARES its column alignment, so a header
                        // cell promoted here carries alignment of its
                        // own - the same shape carve-rs publishes for
                        // `|:---|---:|`, and what keeps the markers in
                        // the written form after a ProseMirror trip.
                        $headerCell = new TableCell(
                            true,
                            $alignment,
                            $cell->getRowspan(),
                            $cell->getColspan(),
                            $cell->getSpanMarker(),
                            (($alignments[$cellIndex] ?? TableCell::ALIGN_DEFAULT) !== TableCell::ALIGN_DEFAULT)
                                || $cell->hasExplicitAlignment(),
                        );
                        // Preserve cell attributes from original cell
                        $headerCell->setAttributes($cell->getAttributeEntries());
                        // Same source as the cell it replaces.
                        $headerCell->setPos($cell->getPos());
                        if ($cell->hasExplicitVerticalAlignment()) {
                            $headerCell->setVerticalAlignment($cell->getVerticalAlignment());
                            $columnValigns[$cellIndex] = $cell->getVerticalAlignment();
                        }
                        $headerCell->setChildren($cell->getChildren());
                        $headerRow->appendChild($headerCell);
                        // The promoted header cell replaces the original, so
                        // repoint the rowspan origin to the NEW cell (else a
                        // later `^` extends the detached old cell and the
                        // header rowspan is lost).
                        if (($columnOrigin[$cellIndex] ?? null) === $cell) {
                            $columnOrigin[$cellIndex] = $headerCell;
                        }
                        if (isset($cellBuilds[spl_object_id($cell)])) {
                            $cellBuilds[spl_object_id($headerCell)] = ['cell' => $headerCell] + $cellBuilds[spl_object_id($cell)];
                            unset($cellBuilds[spl_object_id($cell)]);
                        }
                        $cellIndex++;
                    }
                }
                // Replace last row
                $table->replaceChild(count($children) - 1, $headerRow);
            }
        }
    }
}
