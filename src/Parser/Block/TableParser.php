<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser\Block;

use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Parser\Utility\AttributeParser;

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

        // Verify the line truly ends with | outside of code spans
        return $this->lineEndsWithPipeOutsideCodeSpan($lineWithoutRowAttrs);
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
        if (preg_match('/^(.*\|)\{([^{}]+)\}\s*$/', $line, $matches)) {
            if (AttributeParser::isValidPayload($matches[2])) {
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

        if (preg_match('/\|\{([^{}]+)\}\s*$/', $line, $matches)) {
            // Same §14 gate as stripRowAttributes: an invalid payload is not a
            // row-attribute block, so it contributes no attributes either.
            if (!AttributeParser::isValidPayload($matches[1])) {
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
            if (preg_match('/^\s*:?-+:?\s*$/', $cell) !== 1) {
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
            $cell = trim($cell);
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
     *
     * @return array<string> Array of cell contents
     */
    public function parseTableCells(string $line): array
    {
        return array_column($this->splitCells($line), 'content');
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
     * @return list<array{content: string, offset: int, verbatim: bool, rawLength: int, raw: string}>
     */
    public function splitCells(string $line): array
    {
        // Row attributes and trailing whitespace are stripped from the END, and
        // the leading `|` is one byte, so every offset below shifts by exactly
        // that one byte to become an offset in the original line.
        $line = $this->stripRowAttributes($line);
        $line = rtrim($line, " \t");
        $line = substr($line, 1, -1);
        $shift = 1;

        // Fast path: with no code spans (backticks) and no escaped pipes, every
        // `|` is a delimiter, so a plain split is identical to the scan below.
        if (!str_contains($line, '`') && !str_contains($line, '\\|')) {
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
        $inCode = false;
        $codeDelimLength = 0;
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
     * Parse table cells with their attributes.
     * Cell attributes appear at the start: |{.class} content |
     *
     * @param string $line The table row line
     *
     * @return array<array{content: string, attributes: string, offset: int, cellOffset: int, verbatim: bool, rawLength: int, raw: string}> Cell data:
     *   attributes is the raw `{...}` inner (empty when none); offset is where the
     *   content begins in the original line, and verbatim says whether that stretch
     *   is a byte-for-byte copy of it (see splitCells)
     */
    public function parseTableCellsWithAttributes(string $line): array
    {
        $cells = $this->splitCells($line);
        $result = [];

        foreach ($cells as $cell) {
            $cellContent = $cell['content'];
            // Carried through so a cell can be placed in the source. An
            // attribute block shifts the content right within its own cell, so
            // the offset is adjusted below rather than reused as-is.
            $cellOffset = $cell['offset'];
            $cellVerbatim = $cell['verbatim'];
            $cellRawLength = $cell['rawLength'];
            $cellRaw = $cell['raw'];
            // The attribute string (raw inner of the `{...}`), empty when the
            // cell has none; applied later in source order via applyToNode.
            $attributes = '';
            $content = $cellContent;

            // A `{...}` GLUED to the opening pipe (index 0, no leading space)
            // is the cell's attribute block; the rest, after optional
            // whitespace, is the content. A space before the brace is ordinary
            // content. The closing brace is found quote-aware (so a quoted `}`
            // in a value is kept), and the WHOLE payload must be valid
            // attribute syntax (§15) -- otherwise the `{` stays literal content.
            if (isset($cellContent[0]) && $cellContent[0] === '{') {
                $end = $this->findCellAttrEnd($cellContent);
                if ($end !== null) {
                    $inner = substr($cellContent, 1, $end - 1);
                    if (
                        $inner !== ''
                        && !$this->isInlineMarker($inner)
                        && AttributeParser::isValidPayload($inner)
                    ) {
                        $attributes = $inner;
                        $rest = substr($cellContent, $end + 1);
                        $content = ltrim($rest);
                        $cellOffset += $end + 1 + (strlen($rest) - strlen($content));
                    }
                }
            }

            $result[] = [
                'content' => $content,
                'attributes' => $attributes,
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
        // Fast path: no backticks means no code spans at all
        if (!str_contains($line, '`')) {
            return false;
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

        return $inCode;
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
        $trimmed = trim($line);
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
        return trim($cellContent) === '^';
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
        return trim($cellContent) === '<';
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
        $trimmed = ltrim($line);

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
        $trimmed = rtrim($line);

        return str_ends_with($trimmed, '|');
    }

    /**
     * Parse cells from a continuation row.
     * Continuation rows start with + instead of |.
     *
     * @param string $line The continuation row line (starting with +)
     *
     * @return array<string> Array of cell contents
     */
    public function parseContinuationCells(string $line): array
    {
        $trimmed = ltrim($line);

        // Replace leading + with | for parsing
        $normalizedLine = '|' . substr($trimmed, 1);

        return $this->parseTableCells($normalizedLine);
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
            $base = trim($baseCells[$i] ?? '');
            $continuation = trim($continuationCells[$i] ?? '');

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
}
