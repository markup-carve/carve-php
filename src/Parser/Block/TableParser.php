<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser\Block;

use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Parser\Utility\AttributeParser;
use MarkupCarve\Carve\Util\StringUtil;

/**
 * Parser for table blocks.
 *
 * This class handles parsing of:
 * - Table rows (| cell | cell |)
 * - Table alignments from separator rows
 * - Table cells with code span awareness
 * - Row attributes (|...|{.class})
 * - Cell attributes (|{.class} content |)
 */
class TableParser
{
    /**
     * Check if a line could be a table row.
     * A line must start with | and end with | (optionally followed by row attributes).
     *
     * @param string $line The line to check
     *
     * @return bool True if the line could be a table row
     */
    public function isTableRow(string $line): bool
    {
        // Fast early exit: tables start with |
        if (!isset($line[0]) || $line[0] !== '|') {
            return false;
        }

        // Trailing whitespace after the closing pipe is insignificant (parity
        // with carve-js / carve-rs); strip it before the structural checks.
        $line = rtrim($line, " \t");

        // Strip row attributes if present (|...|{.class})
        $lineWithoutRowAttrs = $this->stripRowAttributes($line);

        // Table rows start and end with | (byte check, equivalent to
        // `/^\|.*\|$/`: first byte is `|`, last byte is `|`, length >= 2).
        $len = strlen($lineWithoutRowAttrs);
        if ($len < 2 || $lineWithoutRowAttrs[0] !== '|' || $lineWithoutRowAttrs[$len - 1] !== '|') {
            return false;
        }

        if ($lineWithoutRowAttrs === '||') {
            return false;
        }

        // A single cell containing only padding is still an empty one-cell
        // row, and therefore not a table. `|||` remains the distinct valid
        // two-empty-cell spelling.
        $interior = substr($lineWithoutRowAttrs, 1, -1);
        if (!str_contains($interior, '|') && trim($interior, " \t") === '') {
            return false;
        }

        // An UNTERMINATED verbatim run in a cell does not un-make the row. A row
        // is split into cells at BLOCK level, before any inline parsing runs -
        // which is what lets a separator row work at all - so a run that never
        // closes is an inline fact reported inside a cell that already exists.
        // Requiring the closing `|` to sit outside every run asked an inline
        // question at block level, and answered it the one way no other
        // malformed inline is answered anywhere in Carve: by dissolving the
        // block. It also contradicted this same parser one line down, where the
        // identical row under a header separator was a table
        // (markup-carve/carve#1284).
        //
        // The closing pipe is a DELIMITER either way: splitCells() removes it
        // before it scans for runs, so no code span can swallow it.
        return true;
    }

    /**
     * Strip row attributes from end of line.
     *
     * @param string $line The line to process
     *
     * @return string Line without trailing row attributes
     */
    public function stripRowAttributes(string $line): string
    {
        // Row attributes require a literal `}`; skip the greedy backtracking
        // regex on the common row that has none.
        if (!str_contains($line, '}')) {
            return $line;
        }

        // Row attributes appear after final pipe: |...|{.class}. The payload
        // must be a valid attribute block (§14): an unrecognized name -- a
        // colon-bearing, digit-first or otherwise non-`identifier` one -- makes
        // the whole `{...}` literal content, so the line no longer ends with
        // `|` and is not a table row at all. carve-js and carve-rs leave such a
        // line a paragraph; without this gate the block was stripped whatever
        // it held and the row was built anyway.
        if (preg_match('/^(.*\|)\{([^{}]+)\}[ \t]*$/', $line, $matches)) {
            if (AttributeParser::isValidInlinePayload($matches[2])) {
                return $matches[1];
            }
        }

        return $line;
    }

    /**
     * Extract row attributes from end of line.
     *
     * @param string $line The line to process
     *
     * @return array<string, string> Parsed attributes or empty array
     */
    public function extractRowAttributes(string $line): array
    {
        if (!str_contains($line, '}')) {
            return [];
        }

        if (preg_match('/\|\{([^{}]+)\}[ \t]*$/', $line, $matches)) {
            // Same §14 gate as stripRowAttributes: an invalid payload is not a
            // row-attribute block, so it contributes no attributes either.
            //
            // THE PAIR IS WHAT DECIDES. `stripRowAttributes` runs first, and a
            // payload it refuses leaves the line not ending in `|` - so the
            // line is not a row at all and this never runs. Mutating either
            // gate alone therefore survives; mutating both fails. The pairing
            // is the rule, and it is stated here so the survivor is explained.
            if (!AttributeParser::isValidInlinePayload($matches[1])) {
                return [];
            }

            return AttributeParser::parse($matches[1]);
        }

        return [];
    }

    /**
     * Check if a line is a separator row (contains |, -, with optional : and spaces).
     *
     * @param string $line The line to check
     *
     * @return bool True if the line is a separator row
     */
    public function isSeparatorRow(string $line): bool
    {
        // Trailing whitespace after the closing pipe is insignificant.
        $line = rtrim($line, " \t");

        $len = strlen($line);
        if ($len < 2 || $line[0] !== '|' || $line[$len - 1] !== '|') {
            return false;
        }

        // Every cell must be a delimiter cell: optional whitespace, an optional
        // leading ':', one or more '-', an optional trailing ':', optional
        // whitespace. An EMPTY cell (`|---||`) or any other content disqualifies
        // the row -- it is then an ordinary data row (matches carve-js/carve-rs).
        $cells = $this->parseTableCells($line);
        if ($cells === []) {
            return false;
        }
        foreach ($cells as $cell) {
            if (preg_match('/^ *:?-+:? *$/', $cell) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Parse table alignments from a separator row.
     *
     * @param string $separatorLine The separator row line
     *
     * @return array<string> Array of alignment constants
     */
    public function parseTableAlignments(string $separatorLine): array
    {
        $alignments = [];
        $cells = $this->parseTableCells($separatorLine);

        foreach ($cells as $cell) {
            $cell = trim($cell, ' ');
            if (str_starts_with($cell, ':') && str_ends_with($cell, ':')) {
                $alignments[] = TableCell::ALIGN_CENTER;
            } elseif (str_ends_with($cell, ':')) {
                $alignments[] = TableCell::ALIGN_RIGHT;
            } elseif (str_starts_with($cell, ':')) {
                $alignments[] = TableCell::ALIGN_LEFT;
            } else {
                $alignments[] = TableCell::ALIGN_DEFAULT;
            }
        }

        return $alignments;
    }

    /**
     * Parse separator widths from a separator row for round-trip preservation.
     *
     * @param string $separatorLine The separator row line
     *
     * @return array<int> Array of separator widths (number of dashes per column)
     */
    public function parseSeparatorWidths(string $separatorLine): array
    {
        $widths = [];
        $cells = $this->parseTableCells($separatorLine);

        foreach ($cells as $cell) {
            // Count only the dashes (excluding colons and whitespace)
            $dashes = preg_replace('/[^-]/', '', $cell) ?? '';
            $widths[] = strlen($dashes);
        }

        return $widths;
    }

    /**
     * Parse table cells from a row, respecting code spans and escaped pipes.
     *
     * @param string $line The table row line
     * @param array<int, int> $openDelimiters Verbatim run width left open by the row above, by cell index.
     *
     * @return array<string> Array of cell contents
     */
    public function parseTableCells(string $line, array $openDelimiters = []): array
    {
        return array_column($this->splitCells($line, $openDelimiters), 'content');
    }

    /**
     * Split a row into cells, each with where it began in the ORIGINAL line.
     *
     * The offset is what makes a table cell placeable (PART 12 §4). Locating a
     * cell by searching the row for its text does NOT work and is actively
     * dangerous: `| a | a |` has two cells with identical content, so a search
     * returns the first for both - and a span that selects the right BYTES at
     * the wrong place passes every verification a consumer could apply. The
     * position has to come from the split itself, which is the only place that
     * knows which cell is which.
     *
     * `verbatim` is false when the cell's content is not a byte-for-byte copy
     * of that stretch of source - an escaped pipe collapses `\|` to `|`, so
     * offsets inside it no longer line up. Those cells decline a position
     * rather than carry a drifting one.
     *
     * @param string $line
     * @param array<int, int> $openDelimiters Verbatim run width left open by the row above, by cell index.
     *
     * @return list<array{content: string, offset: int, verbatim: bool, rawLength: int, raw: string}>
     */
    public function splitCells(string $line, array $openDelimiters = []): array
    {
        // Row attributes and trailing whitespace are stripped from the END, and
        // the leading `|` is one byte, so every offset below shifts by exactly
        // that one byte to become an offset in the original line.
        $line = $this->stripRowAttributes($line);
        $line = rtrim($line, " \t");

        // AN ESCAPED CLOSING PIPE IS CONTENT, NOT THE TERMINATOR
        // (markup-carve/carve#1293). Chopping the last byte unconditionally
        // assumed the row's final `|` was always a delimiter. On `| a b \|` it
        // took the ESCAPED pipe as the terminator and left the backslash
        // orphaned at the end of the cell, where the inline parser read it as a
        // hard break - so the row rendered `a b <br>` and the literal pipe the
        // author asked for was gone.
        //
        // The deciding fact is that this splitter was never escape-blind: the
        // scan below already honors `\|` mid-cell, so `| a \| b | c |` has
        // always given `a | b` + `c`. The escape was respected at every position
        // except the last one, which is a position exception with nothing behind
        // it. `\|` is also the only way to put a literal pipe in a cell, so
        // under the terminator reading it stopped working in exactly the place
        // an author most naturally reaches for it.
        //
        // PARITY, not "is the previous byte a backslash". A doubled `\\` is an
        // escaped BACKSLASH, which leaves the `|` after it unescaped and
        // therefore still the terminator: `| a b \\|` closes the row and the
        // cell holds a single `\`. Only an ODD run of backslashes escapes the
        // pipe. Counting the run is what tells the two apart.
        //
        // The row is still a table either way. `isTableRow()` asks whether the
        // line ends with the `|` BYTE and an escaped pipe is one, which is why
        // this stays a cell-splitting question and no row detection changes
        // here; carve-js reaches a table by the same route.
        $line = $this->closingPipeIsEscaped($line)
            ? substr($line, 1)
            : substr($line, 1, -1);
        $shift = 1;

        // Fast path: with no code spans (backticks) and no escaped pipes, every
        // `|` is a delimiter, so a plain split is identical to the scan below.
        // A run left OPEN by the row above disqualifies it: there is a verbatim
        // span here even though this line carries no backtick of its own.
        if ($openDelimiters === [] && !str_contains($line, '`') && !str_contains($line, '\\|')) {
            $result = [];
            $at = 0;
            foreach (explode('|', $line) as $content) {
                $result[] = [
                    'content' => $content,
                    'offset' => $at + $shift,
                    'verbatim' => true,
                    'rawLength' => strlen($content),
                    'raw' => $content,
                ];
                $at += strlen($content) + 1;
            }

            return $result;
        }

        // Split by | but not \| and not | inside code spans
        $cells = [];
        $currentCell = '';
        $cellStart = 0;
        $cellVerbatim = true;
        $offsets = [];
        $verbatims = [];
        $rawLengths = [];
        // A VERBATIM RUN LEFT OPEN BY THE ROW ABOVE REOPENS HERE, at the cell
        // index that opened it. PART 9 §19 ends a run at its closing delimiter,
        // and a row boundary is not one, so a `|` inside it is CONTENT and not
        // a cell delimiter. This splitter started every row from a closed
        // state, so `| a `b |` followed by `+ c | d` |` split the continuation
        // at the pipe INSIDE the still-open span and one cell came back as two,
        // the second holding an empty code element (corpus 333-4).
        //
        // Indexed by CELL rather than carried from the row's start, because the
        // run belongs to the cell it was written in: `| x | a `b |` reopens at
        // cell 1 and cell 0 of the continuation splits as usual (corpus 333-5).
        // The WIDTH is carried too - only a run of the same length closes it.
        $cellIndex = 0;
        $codeDelimLength = $openDelimiters[0] ?? 0;
        $inCode = $codeDelimLength > 0;
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            // Track code spans (backticks)
            if ($char === '`' && !$inCode) {
                // Count backticks for code span opener
                $backtickCount = 1;
                while ($i + $backtickCount < $length && $line[$i + $backtickCount] === '`') {
                    $backtickCount++;
                }
                $inCode = true;
                $codeDelimLength = $backtickCount;
                $currentCell .= substr($line, $i, $backtickCount);
                $i += $backtickCount - 1;

                continue;
            }

            if ($inCode && $char === '`') {
                // Check for matching closing backticks
                $backtickCount = 1;
                while ($i + $backtickCount < $length && $line[$i + $backtickCount] === '`') {
                    $backtickCount++;
                }
                $currentCell .= substr($line, $i, $backtickCount);
                if ($backtickCount === $codeDelimLength) {
                    $inCode = false;
                }
                $i += $backtickCount - 1;

                continue;
            }

            // Check for escaped pipe. The escape is KEPT rather than
            // resolved: the inline parser turns it into an `escaped_text`
            // node, which is what carve-js and carve-rs publish and what the
            // vocabulary defines. Resolving it here produced a single `text`
            // node holding a bare `|`, losing both the node and the author's
            // intent - and, because the cell content was then no longer a
            // verbatim run of the row, nothing in the cell could carry a
            // position either (carve-php#579).
            if ($char === '\\' && $i + 1 < $length && $line[$i + 1] === '|') {
                $currentCell .= '\\|';
                $i++; // Skip the |

                continue;
            }

            // Cell delimiter (unescaped | outside code span)
            if ($char === '|' && !$inCode) {
                $cells[] = $currentCell;
                $offsets[] = $cellStart + $shift;
                $verbatims[] = $cellVerbatim;
                $rawLengths[] = $i - $cellStart;
                $currentCell = '';
                $cellStart = $i + 1;
                $cellVerbatim = true;
                // The next cell inherits ITS OWN open run, not the one that
                // ended here.
                $cellIndex++;
                $codeDelimLength = $openDelimiters[$cellIndex] ?? 0;
                $inCode = $codeDelimLength > 0;

                continue;
            }

            $currentCell .= $char;
        }

        // Add the last cell
        $cells[] = $currentCell;
        $offsets[] = $cellStart + $shift;
        $verbatims[] = $cellVerbatim;
        $rawLengths[] = $length - $cellStart;

        $result = [];
        foreach ($cells as $index => $content) {
            $result[] = [
                'content' => $content,
                'offset' => $offsets[$index],
                'verbatim' => $verbatims[$index],
                // The exact source the cell occupied, so the TEXT inside it can
                // be placed at its own offset rather than taking the cell's -
                // which includes the padding around the content.
                'raw' => substr($line, $offsets[$index] - $shift, $rawLengths[$index]),
                // The bytes the cell occupied in the source, which differ from
                // the content's length whenever an escape was collapsed. This
                // is what lets a rewritten cell still be PLACED even though its
                // text cannot be verified against the source.
                'rawLength' => $rawLengths[$index],
            ];
        }

        return $result;
    }

    /**
     * Does this row's final `|` carry an escape, making it content?
     *
     * Counts the backslash run immediately before the closing pipe and reads its
     * PARITY: an odd run escapes the pipe (`\|`, `\\\|`), an even one does not
     * (`\\|`), because each pair is itself an escaped backslash. A test for "the
     * previous byte is a backslash" would get `\\|` wrong in the direction that
     * silently eats the row terminator.
     *
     * @param string $line The row, already stripped of row attributes and
     *   trailing whitespace
     */
    private function closingPipeIsEscaped(string $line): bool
    {
        if (!str_ends_with($line, '|')) {
            return false;
        }

        $backslashes = 0;
        for ($i = strlen($line) - 2; $i >= 0 && $line[$i] === '\\'; $i--) {
            $backslashes++;
        }

        return $backslashes % 2 === 1;
    }

    /**
     * Parse table cells with their attributes.
     *
     * PART 9 §5 T10: the kind marker comes first, then the alignment marker,
     * then the attribute block - `|={.x} h |`, `|=~{.x} h |`, `|>{.x} d |`,
     * `|{.x} d |`. The block is GLUED to whatever precedes it: to the marker
     * run where the cell has one, to the opening `|` where it has none.
     *
     * The marker run is only consumed HERE when a block actually follows it. A
     * cell without one keeps its markers in `content` and is read downstream by
     * `BlockParser::parseTableCellMarker()`, which is where a lone `<`/`^` is
     * still allowed to be a span marker rather than an alignment sigil.
     *
     * @param string $line The table row line
     *
     * @return array<array{content: string, attributes: string, marker: string, offset: int, cellOffset: int, verbatim: bool, rawLength: int, raw: string}> Cell data:
     *   attributes is the raw `{...}` inner (empty when none); marker is the
     *   marker run this method already stripped off `content` (empty unless it
     *   found a block); offset is where the content begins in the original
     *   line, and verbatim says whether that stretch is a byte-for-byte copy of
     *   it (see splitCells)
     */
    public function parseTableCellsWithAttributes(string $line): array
    {
        $cells = $this->splitCells($line);
        $result = [];

        foreach ($cells as $cell) {
            $cellContent = $cell['content'];
            // Carried through so a cell can be placed in the source. A marker
            // run and an attribute block both shift the content right within
            // their own cell, so the offset is adjusted below rather than
            // reused as-is.
            $cellOffset = $cell['offset'];
            $cellVerbatim = $cell['verbatim'];
            $cellRawLength = $cell['rawLength'];
            $cellRaw = $cell['raw'];
            // The attribute string (raw inner of the `{...}`), empty when the
            // cell has none; applied later in source order via applyToNode.
            $attributes = '';
            $marker = '';
            $content = $cellContent;

            // A `{...}` GLUED to the marker run - or to the opening pipe where
            // the cell has no markers - is the cell's attribute block; the
            // rest, after optional whitespace, is the content. A space before
            // the brace is ordinary content. The closing brace is found
            // quote-aware (so a quoted `}` in a value is kept), and the WHOLE
            // payload must be valid attribute syntax (§15) -- otherwise the `{`
            // stays literal content.
            $markerLength = $this->cellMarkerRunLength($cellContent);
            if (($cellContent[$markerLength] ?? '') === '{') {
                $afterMarker = substr($cellContent, $markerLength);
                $end = $this->findCellAttrEnd($afterMarker);
                if ($end !== null) {
                    $inner = substr($afterMarker, 1, $end - 1);
                    if (
                        $inner !== ''
                        && !$this->isInlineMarker($inner)
                        && AttributeParser::isValidInlinePayload($inner)
                    ) {
                        $attributes = $inner;
                        $marker = substr($cellContent, 0, $markerLength);
                        $rest = substr($afterMarker, $end + 1);
                        // The slot between a cell attribute block and the
                        // cell content is `data_cell`'s own `{space}` run
                        // (PART 7), not a fresh one - so it takes a space and
                        // a tab after `{...}` is content, exactly as it is
                        // after a bare `|`.
                        $content = ltrim($rest, ' ');
                        $cellOffset += $markerLength + $end + 1 + (strlen($rest) - strlen($content));
                    }
                }
            }

            $result[] = [
                'content' => $content,
                'attributes' => $attributes,
                'marker' => $marker,
                'offset' => $cellOffset,
                // Where the CELL starts, before the offset above was advanced
                // past an attribute block. The two are the same for a plain
                // cell and differ by the block's width for an attributed one,
                // and `rawLength` measures from HERE - so a span built from the
                // advanced offset slid right by that width, ending past the end
                // of the line and overlapping the next cell (carve-php#889).
                'cellOffset' => $cell['offset'],
                'verbatim' => $cellVerbatim,
                'rawLength' => $cellRawLength,
                'raw' => $cellRaw,
            ];
        }

        return $result;
    }

    /**
     * Length of the marker run glued to the start of a cell: an optional `=`
     * (kind) and then an optional alignment sigil, in that order (PART 9 §5
     * T10). Zero when the cell opens with neither.
     *
     * ONE SCAN, TWO READERS. `parseTableCellsWithAttributes()` needs the run's
     * WIDTH to find the attribute block that binds after it, and
     * `BlockParser::parseTableCellMarker()` needs the run's MEANING. Both read
     * it from here, and the alignment sigils come off
     * `BlockParser::TABLE_ALIGNMENT_MARKERS`, so the rule has one spelling
     * rather than one per caller.
     */
    public function cellMarkerRunLength(string $cell): int
    {
        $length = ($cell[0] ?? '') === '=' ? 1 : 0;
        $start = $length;
        while (isset($cell[$length]) && str_contains('<>~^v', $cell[$length])) {
            $length++;
        }

        $horizontal = false;
        $vertical = false;
        $valid = !isset($cell[$start])
            || (!str_contains('^v', $cell[$start])
                && !($cell[$start] === '~' && isset($cell[$start + 1]) && str_contains('<>', $cell[$start + 1])));
        for ($i = $start; $i < $length; $i++) {
            $marker = $cell[$i];
            if (str_contains('<>~', $marker)) {
                if (!$horizontal) {
                    $horizontal = true;
                } elseif ($marker === '~' && !$vertical) {
                    $vertical = true;
                } else {
                    $valid = false;

                    break;
                }
            } elseif (!$vertical) {
                $vertical = true;
            } else {
                $valid = false;

                break;
            }
        }

        if ($length === $start) {
            return $length;
        }
        $next = $cell[$length] ?? null;
        $terminated = $next === ' ' || $next === '{';
        if (!$valid || !$horizontal || !$terminated) {
            return $start;
        }

        return $length;
    }

    /**
     * Index of the `}` that closes a `{...}` attribute block at the start of a
     * cell, scanning quote-aware so a quoted `}` in a value does not end it.
     * Null if there is no closing brace.
     */
    protected function findCellAttrEnd(string $cell): ?int
    {
        $length = strlen($cell);
        $quote = null;
        for ($i = 1; $i < $length; $i++) {
            $char = $cell[$i];
            if ($char === '\\' && $i + 1 < $length) {
                $i++;

                continue;
            }
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;

                continue;
            }
            if ($char === '}') {
                return $i;
            }
        }

        return null;
    }

    /**
     * Check if content inside {...} is an inline formatting marker.
     *
     * Inline markers: =text=, +text+, -text-, ~text~, ^text^, _text_, *text*
     * Also quote markers: ', ", '', ""
     *
     * @param string $inner The content inside {...}
     *
     * @return bool True if it's an inline marker (not an attribute)
     */
    protected function isInlineMarker(string $inner): bool
    {
        // Quote markers: ' or " (any number)
        if (preg_match('/^[\'"]+$/', $inner)) {
            return true;
        }

        // Inline formatting: marker at start and end (=text=, +text+, -text-, ~text~, ^text^)
        if (strlen($inner) >= 3) {
            $firstChar = $inner[0];
            $lastChar = $inner[strlen($inner) - 1];
            $inlineMarkers = ['=', '+', '-', '~', '^', '_', '*'];
            if (in_array($firstChar, $inlineMarkers, true) && $firstChar === $lastChar) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a line has unclosed code spans.
     *
     * @param string $line The line to check
     *
     * @return bool True if there's an unclosed code span
     */
    public function hasUnclosedCodeSpan(string $line): bool
    {
        return $this->openCodeSpanDelimiter($line) > 0;
    }

    /**
     * The delimiter WIDTH of a verbatim run this line leaves open, or 0.
     *
     * The same walk `hasUnclosedCodeSpan()` used to make on its own, reporting
     * the width instead of a boolean, because a continuation row has to REOPEN
     * the run at the width that opened it: only a matching run of backticks
     * closes it, and a `|` inside it is content rather than a cell delimiter.
     *
     * @param string $line
     */
    public function openCodeSpanDelimiter(string $line): int
    {
        // Fast path: no backticks means no code spans at all
        if (!str_contains($line, '`')) {
            return 0;
        }

        $length = strlen($line);
        $inCode = false;
        $codeDelimLength = 0;

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            if ($char === '`' && !$inCode) {
                $backtickCount = 1;
                while ($i + $backtickCount < $length && $line[$i + $backtickCount] === '`') {
                    $backtickCount++;
                }
                $inCode = true;
                $codeDelimLength = $backtickCount;
                $i += $backtickCount - 1;

                continue;
            }

            if ($inCode && $char === '`') {
                $backtickCount = 1;
                while ($i + $backtickCount < $length && $line[$i + $backtickCount] === '`') {
                    $backtickCount++;
                }
                if ($backtickCount === $codeDelimLength) {
                    $inCode = false;
                }
                $i += $backtickCount - 1;

                continue;
            }
        }

        return $inCode ? $codeDelimLength : 0;
    }

    /**
     * Parse table cells from a row WITHOUT respecting code spans.
     *
     * This is used for look-ahead when checking if continuation rows
     * can close unclosed code spans. It simply splits on | characters.
     *
     * @param string $line The table row line
     *
     * @return array<string> Array of cell contents
     */
    public function parseTableCellsRaw(string $line): array
    {
        // Strip row attributes first
        $line = $this->stripRowAttributes($line);

        // Trailing whitespace after the closing pipe is insignificant.
        $line = rtrim($line, " \t");

        // Must start with | to be a potential table row
        if (!str_starts_with($line, '|')) {
            return [];
        }

        // Remove leading |
        $line = substr($line, 1);

        // Remove trailing | if present
        if (str_ends_with($line, '|')) {
            $line = substr($line, 0, -1);
        }

        // Simple split on |, handling escaped pipes
        $cells = [];
        $currentCell = '';
        $length = strlen($line);
        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            // Check for escaped pipe. RESOLVED here, unlike in splitCells:
            // this path's cells do not go through the inline parser, so a
            // kept escape would never become an `escaped_text` node and the
            // pipe was dropped from the output entirely.
            if ($char === '\\' && $i + 1 < $length && $line[$i + 1] === '|') {
                $currentCell .= '|';
                $cellVerbatim = false;
                $i++; // Skip the |

                continue;
            }

            // Cell delimiter
            if ($char === '|') {
                $cells[] = $currentCell;
                $currentCell = '';

                continue;
            }

            $currentCell .= $char;
        }

        // Add the last cell
        $cells[] = $currentCell;

        return $cells;
    }

    /**
     * Check if a line looks like a table row but has unclosed code spans.
     *
     * This is used to detect rows where a code span starts but continues
     * into a continuation row.
     *
     * @param string $line The line to check
     *
     * @return bool True if line looks like a table row but has unclosed code span
     */
    public function isPotentialTableRowWithUnclosedCodeSpan(string $line): bool
    {
        $trimmed = trim($line, StringUtil::WHITESPACE_CHARS);
        if ($trimmed === '' || $trimmed[0] !== '|') {
            return false;
        }

        // Strip row attributes if present
        $lineWithoutRowAttrs = $this->stripRowAttributes($line);

        // Must start with |
        if (!str_starts_with($lineWithoutRowAttrs, '|')) {
            return false;
        }

        // Check if it has an unclosed code span
        return $this->hasUnclosedCodeSpan($lineWithoutRowAttrs);
    }

    /**
     * Check if combining base content with continuation content results in balanced code spans.
     *
     * @param string $baseContent The base cell content
     * @param string $continuationContent The continuation cell content
     *
     * @return bool True if the combined content has balanced code spans
     */
    public function combinedContentHasBalancedCodeSpans(string $baseContent, string $continuationContent): bool
    {
        $combined = $baseContent . ' ' . $continuationContent;

        return !$this->hasUnclosedCodeSpan($combined);
    }

    /**
     * Validate that merged cell contents result in a valid table row.
     *
     * @param array<string> $mergedCells The merged cell contents
     *
     * @return bool True if all cells have balanced code spans
     */
    public function mergedCellsAreValid(array $mergedCells): bool
    {
        foreach ($mergedCells as $cell) {
            if ($this->hasUnclosedCodeSpan($cell)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if a cell contains a rowspan marker (^).
     * A cell with only ^ (and optional whitespace) indicates it's spanned from the cell above.
     *
     * @param string $cellContent The cell content to check
     *
     * @return bool True if the cell is a rowspan marker
     */
    public function isRowspanMarker(string $cellContent): bool
    {
        return trim($cellContent, ' ') === '^';
    }

    /**
     * Check if a cell contains a colspan marker (<).
     * A cell with only < (and optional whitespace) indicates it's spanned from the cell to the left.
     * The < points toward the source cell, consistent with ^ pointing up toward its source.
     *
     * @param string $cellContent The cell content to check
     *
     * @return bool True if the cell is a colspan marker
     */
    public function isColspanMarker(string $cellContent): bool
    {
        return trim($cellContent, ' ') === '<';
    }

    /**
     * Check if a line is a continuation row (starts with +).
     * Continuation rows use + prefix instead of | to signal that the contents
     * get added to the cells from the previous row.
     *
     * Syntax: + cell1 | cell2 | cell3 |
     *
     * @param string $line The line to check
     *
     * @return bool True if the line is a continuation row
     */
    public function isContinuationRow(string $line): bool
    {
        // Continuation rows start with + and end with |
        $trimmed = ltrim($line, " \t");

        if (!str_starts_with($trimmed, '+')) {
            return false;
        }

        // Check for standard case: ends with | outside code spans
        if ($this->lineEndsWithPipeOutsideCodeSpan($trimmed)) {
            return true;
        }

        // Also accept continuation rows that might close a code span from the previous row
        // These have an "orphan" closing backtick that makes the | look like it's inside a code span
        return $this->isPotentialContinuationRowWithCodeSpan($trimmed);
    }

    /**
     * Check if a line is a potential continuation row that contains code span syntax.
     *
     * This handles the case where a continuation row closes a code span started
     * in the previous row.
     *
     * @param string $line The trimmed line (starting with +)
     *
     * @return bool True if this looks like a continuation row with code span
     */
    protected function isPotentialContinuationRowWithCodeSpan(string $line): bool
    {
        // Must start with + and contain |
        if (!str_starts_with($line, '+') || !str_contains($line, '|')) {
            return false;
        }

        // Check if it ends with | (even if inside "code span")
        // PHP's default charlist takes a VERTICAL TAB and not a FORM FEED, so a
        // continuation row ending in one folded into the cell above while the same
        // row ending in the other became a paragraph between two tables.
        $trimmed = rtrim($line, StringUtil::WHITESPACE_CHARS);

        return str_ends_with($trimmed, '|');
    }

    /**
     * Parse cells from a continuation row.
     * Continuation rows start with + instead of |.
     *
     * @param string $line The continuation row line (starting with +)
     * @param array<int, int> $openDelimiters Verbatim run width left open by the row above, by cell index.
     *
     * @return array<string> Array of cell contents
     */
    public function parseContinuationCells(string $line, array $openDelimiters = []): array
    {
        $trimmed = ltrim($line, " \t");

        // Replace leading + with | for parsing
        $normalizedLine = '|' . substr($trimmed, 1);

        return $this->parseTableCells($normalizedLine, $openDelimiters);
    }

    /**
     * Merge cell contents from continuation lines.
     * Each cell's content is joined with a space.
     *
     * @param array<string> $baseCells The cells from the base row
     * @param array<string> $continuationCells The cells from the continuation row
     *
     * @return array<string> Merged cell contents
     */
    public function mergeCellContents(array $baseCells, array $continuationCells): array
    {
        $result = [];
        $count = max(count($baseCells), count($continuationCells));

        for ($i = 0; $i < $count; $i++) {
            $base = trim($baseCells[$i] ?? '', ' ');
            $continuation = trim($continuationCells[$i] ?? '', ' ');

            if ($base !== '' && $continuation !== '') {
                // Join with space (like soft breaks in paragraphs)
                $result[] = $base . ' ' . $continuation;
            } elseif ($continuation !== '') {
                $result[] = $continuation;
            } else {
                $result[] = $base;
            }
        }

        return $result;
    }

    /**
     * Check if a line ends with | outside of code spans.
     * Used to verify table row syntax (| `a |` is not a table because final | is in code span).
     *
     * @param string $line The line to check
     *
     * @return bool True if the line ends with | outside code spans
     */
    public function lineEndsWithPipeOutsideCodeSpan(string $line): bool
    {
        $length = strlen($line);

        // Fast path: no backticks means no code spans to worry about
        if (!str_contains($line, '`')) {
            return $line[$length - 1] === '|';
        }

        $inCode = false;
        $codeDelimLength = 0;
        $lastPipeOutsideCode = -1;

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            // Track code spans
            if ($char === '`' && !$inCode) {
                $backtickCount = 1;
                while ($i + $backtickCount < $length && $line[$i + $backtickCount] === '`') {
                    $backtickCount++;
                }
                $inCode = true;
                $codeDelimLength = $backtickCount;
                $i += $backtickCount - 1;

                continue;
            }

            if ($inCode && $char === '`') {
                $backtickCount = 1;
                while ($i + $backtickCount < $length && $line[$i + $backtickCount] === '`') {
                    $backtickCount++;
                }
                if ($backtickCount === $codeDelimLength) {
                    $inCode = false;
                }
                $i += $backtickCount - 1;

                continue;
            }

            // Track pipe positions outside code spans
            if ($char === '|' && !$inCode) {
                $lastPipeOutsideCode = $i;
            }
        }

        // The line ends with | outside code span if the last | is at the end
        return $lastPipeOutsideCode === $length - 1;
    }

    /**
     * Whether a table ROW could start at `$at`, by the row's own first byte.
     *
     * A walk crossing a container prefix reads this at an offset rather than
     * cutting the tail out to hand {@see self::isTableRow()}, which is the copy
     * per level markup-carve/carve-php#1437 removed. *
     * The parser's own fast exit spells the same byte test inline, because it
     * runs on nearly every line the parser reads and one more call for it
     * measured against an ordinary document. The two are held together by
     * `OffsetHeadsAgreeWithTheirParsersTest`, which walks EVERY byte value and
     * asserts the head accepts a line exactly where the parser can, so the pair
     * cannot drift in silence - which is the failure
     * markup-carve/carve-php#969 was.
     */
    public function isTableRowHead(string $line, int $at = 0): bool
    {
        return ($line[$at] ?? '') === '|';
    }

    /**
     * Whether a CONTINUATION row could start at `$at`, by its own first byte.
     *
     * The continuation spelling tolerates leading whitespace where the standard
     * one does not, so the head skips a blank run before it reads the `+`. The
     * offset-side head for {@see self::isContinuationRow()}, pinned by the same
     * test {@see self::isTableRowHead()} names.
     */
    public function isContinuationRowHead(string $line, int $at = 0): bool
    {
        return ($line[$at + strspn($line, " \t", $at)] ?? '') === '+';
    }
}
