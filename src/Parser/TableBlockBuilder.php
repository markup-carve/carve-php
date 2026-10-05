<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use Closure;
use MarkupCarve\Carve\Ast\SourceSpan;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Block\TableRow;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\Utility\AttributeParser;
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
        // Columns the most recently parsed row consumed via a `<` (keyed by
        // column, present = consumed). Only ever needed for the ONE row that
        // might get promoted to a header on the very next line - referenced
        // there to avoid seeding a column origin for a placeholder that
        // covers no real cell of its own (a `^` under it must still degrade
        // to an empty cell, matching the ordinary body-row walk below).
        $lastRowConsumedColspanColumnSet = [];

        while ($i < $count) {
            $currentLine = $lines[$i];

            // Strip row attributes for validation (|...|{.class} → |...|)
            $lineWithoutRowAttrs = ($this->getTableParser)()->stripRowAttributes($currentLine);

            // Trailing whitespace after the closing pipe is insignificant
            // (parity with carve-js / carve-rs).
            if ($lineWithoutRowAttrs === '||' || !preg_match('/^\|.*\|[ \t]*$/', $lineWithoutRowAttrs)) {
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

                // Mark previous row as header and apply alignments to it
                $children = $table->getChildren();
                if ($children !== []) {
                    $lastRow = $children[count($children) - 1];
                    if ($lastRow instanceof TableRow) {
                        // Recreate as header row with alignments
                        $headerRow = new TableRow(true);
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
                                $alignment = $alignments[$cellIndex] ?? TableCell::ALIGN_DEFAULT;
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
                                    isset($alignments[$cellIndex]),
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
                                // header rowspan is lost). A placeholder this row's
                                // own `<` consumed is not seeded -- a colspan does
                                // not claim the columns it merely covers, so a `^`
                                // under a covered column has no origin and degrades
                                // to an empty cell (matching the body-row grid walk
                                // and carve-js / carve-rs).
                                if (!isset($lastRowConsumedColspanColumnSet[$cellIndex])) {
                                    $columnOrigin[$cellIndex] = $headerCell;
                                }
                                $cellIndex++;
                            }
                        }
                        // Replace last row
                        $table->replaceChild(count($children) - 1, $headerRow);
                    }
                }
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

            // Check for continuation rows (lines starting with +)
            while ($i < $count && ($this->getTableParser)()->isContinuationRow($lines[$i])) {
                // THE ROW ABOVE DECIDES WHERE THIS ROW'S CELLS ARE. A verbatim
                // run left open in cell k reaches ACROSS the row boundary
                // (PART 9 §19 - the run ends at its closing delimiter, and a
                // row boundary is not one), so a `|` inside it is content and
                // not a cell delimiter. Split without that state, `| a `b |`
                // followed by `+ c | d` |` broke one cell into two.
                $openRuns = $this->openVerbatimRunsByCell($mergedCells);
                $continuationCells = ($this->getTableParser)()->parseContinuationCells($lines[$i], $openRuns);
                foreach ($this->continuationCellSourceChunks($i, $lines[$i], $openRuns) as $idx => $chunks) {
                    if ($chunks === []) {
                        continue;
                    }
                    $cellSourceChunks[$idx] = array_merge($cellSourceChunks[$idx] ?? [], $chunks);
                }
                $mergedCells = ($this->getTableParser)()->mergeCellContents($mergedCells, $continuationCells);
                $i++;
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
            $resolved = $this->callResolveRowSpans($mergedCellsWithAttrs, $columnOrigin);
            $processedCells = $resolved['cells'];
            $consumedRowspanColumns = $resolved['consumedRowspanColumns'];
            $consumedColspanColumns = $resolved['consumedColspanColumns'];
            $consumedColumnSet = array_flip(array_merge($consumedRowspanColumns, $consumedColspanColumns));
            $lastRowConsumedColspanColumnSet = array_flip($consumedColspanColumns);

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
                if (isset($consumedColumnSet[$cellData['gridColumn']])) {
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
                    $cell = new TableCell($isHeaderRow, $alignment, 1, $colspan, null, false);
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
                    }

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
                    || (($isHeaderRow || $marker['header']) && ($alignments[$col] ?? null) !== null);
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
                $cellMap = $this->cellSourceMap($baseLineForRow, $cellData, $trimmedContent)
                    ?? $this->rebuiltCellSourceMap($cellData, $trimmedContent);
                $cellSpan = $this->cellExtentSpan($baseLineForRow, $cellData);
                if (count($cellData['sourceChunks']) > 1) {
                    $cellSpan = null;
                    $this->state->session->unplaceableNodeIds[spl_object_id($cell)] = true;
                }
                // The text's OWN extent inside the cell: the cell span covers
                // the padding too, so the text is located within the raw slice
                // the split kept for exactly this.
                $cellTextSpan = $this->cellContentSpan($baseLineForRow, $cellData, $trimmedContent);
                if ($trimmedContent !== '' && $this->isPlainTableText($trimmedContent) && $this->appendPlainRebuiltCellText($cell, $cellData, $trimmedContent)) {
                    $cell->setPos($cellSpan ?? ($cellMap?->spanFor(0, $trimmedContent)));
                    $row->appendChild($cell);
                    $rowCellData[] = ['cell' => $cell, 'colPosition' => $col];

                    continue;
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
                $cell->setPos($cellSpan ?? ($cellMap?->spanFor(0, $trimmedContent)));
                $row->appendChild($cell);
                $rowCellData[] = ['cell' => $cell, 'colPosition' => $col];
            }

            // Resolve rowspan markers: each consumed `^` extends the cell open in
            // its column from a row above. Multiple `^` against one origin extend
            // it only once per row. The grid pass already flagged which columns
            // hold a consumed `^` ($consumedRowspanColumns).
            $extendedCells = [];
            foreach ($consumedRowspanColumns as $col) {
                $origin = $columnOrigin[$col] ?? null;
                if ($origin instanceof TableCell) {
                    $originId = spl_object_id($origin);
                    if (!isset($extendedCells[$originId])) {
                        $origin->setRowspan($origin->getRowspan() + 1);
                        $extendedCells[$originId] = true;
                    }
                }
            }

            // Carry each emitted cell down as the open origin for its grid column.
            // Only the cell's own start column is seeded (matching the carve-js
            // renderer, where a colspan does not claim the columns it merely
            // covers); a column consumed by a `^` keeps the origin above it.
            foreach ($rowCellData as $cellInfo) {
                $columnOrigin[$cellInfo['colPosition']] = $cellInfo['cell'];
            }

            $table->appendChild($row);
            CycleCollection::checkpoint();
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
        // Parallel per-column state (keyed by grid column = source index). Plain
        // scalar maps so the colspan++ / skip mutations stay simple for the
        // static analyzer.
        $count = count($mergedCellsWithAttrs);
        /** @var array<int, bool> $skip */
        $skip = array_fill(0, $count, false);
        /** @var array<int, bool> $empty */
        $empty = array_fill(0, $count, false);
        /** @var array<int, int> $colspan */
        $colspan = array_fill(0, $count, 1);
        /** @var array<int, string|null> $spanMarkers */
        $spanMarkers = array_fill(0, $count, null);
        $consumedRowspanColumns = [];
        $consumedColspanColumns = [];

        foreach ($mergedCellsWithAttrs as $col => $cellData) {
            $isColspanMarker = $cellData['attributes'] === ''
                && ($this->getTableParser)()->isColspanMarker($cellData['content']);
            $isRowspanMarker = $cellData['attributes'] === ''
                && ($this->getTableParser)()->isRowspanMarker($cellData['content']);

            // A cell carrying attributes is never a bare span marker, so its
            // `<`/`^` content is literal (carve-js / carve-rs parity).
            if ($isColspanMarker) {
                // Always its own placeholder cell (carve-js parity, uniform
                // row width); consumed on top of that when a target exists.
                $empty[$col] = true;
                $spanMarkers[$col] = '<';

                if ($col > 0) {
                    // Scan left, skipping columns already consumed by a span.
                    $left = $col - 1;
                    while ($left >= 0 && ($skip[$left] ?? false)) {
                        $left--;
                    }
                    if ($left >= 0) {
                        // Merge into the available cell to the left: its
                        // reported width grows by one column, and this column
                        // is consumed (its own reported width stays 1).
                        $colspan[$left] = ($colspan[$left] ?? 1) + 1;
                        $skip[$col] = true;
                        $consumedColspanColumns[] = $col;
                    }
                    // Ran off the left edge: stays an unconsumed empty cell (a
                    // later `<` can still grow it).
                }

                continue;
            }

            if ($isRowspanMarker) {
                // Always its own placeholder cell; consumed only when an
                // origin is actually open above it (resolved in the rowspan
                // pass). With no cell above it is a degenerate marker.
                $empty[$col] = true;
                $spanMarkers[$col] = '^';

                if (isset($columnOrigin[$col])) {
                    $skip[$col] = true;
                    $consumedRowspanColumns[] = $col;
                }
            }
        }

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
     * @param array<int, string> $cells Merged content of the row so far.
     *
     * @return array<int, int> Cell index => open delimiter width.
     */
    private function openVerbatimRunsByCell(array $cells): array
    {
        return $this->source->openVerbatimRunsByCell($cells);
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
}
