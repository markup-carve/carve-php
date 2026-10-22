<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use Closure;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\Block\TableParser;
use MarkupCarve\Carve\Parser\Utility\BracketScanner;
use MarkupCarve\Carve\Renderer\CarveRenderer;

final class MarkdownTableStage
{
    /**
     * @var string
     */
    private const THEMATIC_BREAK = '/^([-*_])(?:[ \t]*\1){2,}[ \t]*$/';

    public function __construct(
        private readonly MarkdownTableContext $context,
        private readonly MarkdownInlineContext $inlineContext,
        private readonly MarkdownInlineStage $inlineStage,
        private readonly MarkdownReferenceContext $referenceContext,
        private readonly MarkdownReferenceStage $referenceStage,
        private readonly Closure $columnWidth,
        private readonly Closure $expandLeadingTabs,
        private readonly Closure $htmlBlockInterrupts,
        private readonly Closure $indentWidth,
        private readonly Closure $isHeldOrderedMarker,
        private readonly Closure $moveIndent,
        private readonly Closure $normalizeBlockquoteMarkers,
        private readonly Closure $quoteParagraphIsOpen,
        private readonly Closure $quotedText,
        private readonly Closure $respellQuotedLine,
        private readonly Closure $stripColumns,
        private readonly Closure $writeItemContent,
        private readonly Closure $sourceLine,
    ) {
    }

    /**
     * A GFM delimiter row: `|`-delimited cells, each a run of dashes with
     * optional leading/trailing alignment colons, and nothing else.
     */
    protected function isGfmDelimiterRow(string $line): bool
    {
        if (!preg_match('/^\|.*\|$/', $line)) {
            return false;
        }
        $cells = $this->splitPipeCells($line);
        if ($cells === []) {
            return false;
        }
        foreach ($cells as $cell) {
            if (!preg_match('/^:?-+:?$/', trim($cell))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a converted GFM header row is kept. An all-blank header with no
     * alignment is the scaffolding the Markdown writer adds to a headerless
     * table (PART 11 CARVE-P11-056), so it is omitted and reported. Body rows
     * are always kept: a row of empty cells is a row (PART 9 CARVE-P9-082).
     */
    public function keepHeaderRow(string $row, int $index): bool
    {
        if (!(new TableParser())->isScaffoldHeaderRow($row)) {
            return true;
        }
        $this->context->droppedTableRows = true;
        $this->context->tableDiagnostics[] = new MigrationDiagnostic(
            'structure-unspellable',
            'Dropped an empty header row of ' . (substr_count(rtrim($row), '|') - 1) . ' cells; a headerless GFM table has no Carve header',
            'warning',
            'dropped',
            'exact',
            'line:' . ($this->sourceLine)($index),
        );

        return false;
    }

    /**
     * Convert a GFM header row + its delimiter row to the Carve `|=` header
     * form, carrying the column alignment from the delimiter colons into the
     * `|=<` / `|=>` / `|=~` markers.
     */
    public function gfmHeaderToCarve(string $headerLine, string $delimiterLine): string
    {
        $headers = $this->splitPipeCells($headerLine);
        $prefixes = [];
        foreach ($this->splitPipeCells($delimiterLine) as $delimiter) {
            $d = trim($delimiter);
            $left = str_starts_with($d, ':');
            $right = str_ends_with($d, ':');
            $prefixes[] = match (true) {
                $left && $right => '=~',
                $right => '=>',
                $left => '=<',
                default => '=',
            };
        }

        return $this->writeTableRow($headers, $prefixes, count($headers));
    }

    /**
     * One table row in the spelling `carve fmt` writes: each cell padded by
     * one space, a lone `<` or `^` escaped so it stays text rather than a span
     * marker, and `$width` cells, since GFM drops a body row's cells past the
     * header's count and pads a short row where a Carve row keeps what it spells.
     *
     * @param array<int, string> $cells
     * @param array<int, string> $prefixes
     * @param int $width
     */
    public function writeTableRow(array $cells, array $prefixes, int $width): string
    {
        $row = '';
        for ($c = 0; $c < $width; $c++) {
            $sourceCell = trim($cells[$c] ?? '');
            $cell = str_replace('\\|', '|', $sourceCell);
            $cell = $this->rewriteTablePipeAutolinks($cell);
            $pinned = [];
            if (preg_match_all('~<(https?://[A-Za-z0-9:/._\~?#&=%@+,;\\\\|-]*\\\\\\\\\|[A-Za-z0-9:/._\~?#&=%@+,;\\\\|-]*)>~', $sourceCell, $backslashed) > 0) {
                foreach ($backslashed[1] as $body) {
                    // GFM drops one backslash before each pipe; Carve spells the rest as written.
                    $read = str_replace('\\|', '|', $body);
                    $token = "\x00Q" . count($pinned) . "\x00";
                    $label = str_replace(['\\', '|'], ['\\\\', '\\|'], $read);
                    $pinned[$token] = '[' . $label . '](' . MarkdownDestinationEncoding::destination($read, true) . ')';
                    $cell = str_replace('<' . $read . '>', $token, $cell);
                }
            }
            $cell = $this->inlineStage->convertInlineFormatting($cell, table: true);
            $cell = strtr($cell, $pinned);
            if (str_contains($sourceCell, '\\|') && preg_match_all('~<https?://[^<>\s]+>~', $sourceCell, $autolinks) > 0) {
                foreach ($autolinks[0] as $autolink) {
                    $body = substr($autolink, 1, -1);
                    if (!str_contains($body, '\\|')) {
                        continue;
                    }
                    $plainBody = str_replace(['\\\\|', '\\|'], '|', $body);
                    $before = '](' . MarkdownDestinationEncoding::destination($plainBody, true) . ')';
                    if (str_contains($body, '\\\\|')) {
                        $after = '](' . MarkdownDestinationEncoding::destination(str_replace('\\\\|', '\\|', $body), true) . ')';
                        $cell = str_replace($before, $after, $cell);
                        $escapedBackslash = '!' . chr(96) . '\\' . chr(96) . '{% %}';
                        $label = str_replace('\\\\|', $escapedBackslash . '\\|', $body);
                        $cell = str_replace('[' . $label . '](', '[' . str_replace('\\\\|', str_repeat('\\', 3) . '|', $body) . '](', $cell);
                    }
                }
            }
            foreach (MarkdownBareLinks::rawUrls($sourceCell) as $candidate) {
                $body = substr($sourceCell, $candidate['start'], $candidate['end'] - $candidate['start']);
                if (!str_contains($body, '\\|') || ($sourceCell[$candidate['start'] - 1] ?? '') === '<' || substr($sourceCell, max(0, $candidate['start'] - 2), 2) === '](') {
                    continue;
                }
                $url = str_replace('\\|', '|', $body);
                $label = str_replace('|', '\\|', str_replace('\\', '\\\\', $url));
                $written = '[' . $label . '](' . MarkdownDestinationEncoding::destination($candidate['prefix'] . $url, true) . ')';
                if (preg_match('~\[[^]]*\]\((?:[^()]|\([^()]*\))*\)~', $cell, $link) === 1) {
                    $cell = str_replace($link[0], $written, $cell);
                }
            }
            if ($cell === '<' || $cell === '^') {
                $cell = '\\' . $cell;
            }
            $row .= '|' . ($prefixes[$c] ?? '') . ' ' . ($cell === '' ? '' : $cell . ' ');
        }

        return $row . '|';
    }

    public function escapeTablePipes(string $text): string
    {
        return preg_replace_callback('/(\\\\*)\||\\\\+/', static function (array $match): string {
            if (!isset($match[1])) {
                return $match[0];
            }

            return $match[1] . (strlen($match[1]) % 2 === 0 ? '\\|' : '|');
        }, $text) ?? $text;
    }

    public function escapeTableInlineText(string $cell, bool $minimalBackticks = true): string
    {
        if (strpbrk($cell, '\\`|') !== false) {
            $spans = [];
            $cell = $this->inlineStage->protectCodeSpans($cell, static function (string $span) use (&$spans): string {
                $key = "\x00T" . count($spans) . "\x00";
                $spans[$key] = $span;

                return $key;
            });
            $cell = preg_replace_callback('/\\\\([\\\\`])/', static fn (array $match): string => $match[1] === '`' ? ($minimalBackticks ? $match[0] : '!`` ` ``{% %}') : '!`\\`{% %}', $cell) ?? $cell;
            $cell = $this->escapeTablePipes($cell);
            $cell = strtr($cell, $spans);
            if ($minimalBackticks && str_contains($cell, '](') && !str_contains($cell, '{%')) {
                $cell = preg_replace_callback('~\]\(([^)\n]+)\)~', static fn (array $match): string => '](' . str_replace('\\`', '`', $match[1]) . ')', $cell) ?? $cell;
            }
        }

        return $cell;
    }

    private function rewriteTablePipeAutolinks(string $cell): string
    {
        if (!str_contains($cell, '|') || !str_contains($cell, 'http')) {
            return $cell;
        }
        $spans = [];
        $cell = $this->inlineStage->protectCodeSpans($cell, static function (string $span) use (&$spans): string {
            $key = "\x00U" . count($spans) . "\x00";
            $spans[$key] = $span;

            return $key;
        });
        $out = '';
        $length = strlen($cell);
        $destinationDepth = 0;
        $bracketEnds = [];
        for ($at = 0; $at < $length;) {
            if ($cell[$at] === '[') {
                if (!array_key_exists($at, $bracketEnds)) {
                    $bracketEnds += BracketScanner::balancedBracketEnds($cell, $at);
                }
                $end = $bracketEnds[$at];
                if ($end !== null) {
                    $out .= substr($cell, $at, $end + 1 - $at);
                    $at = $end + 1;

                    continue;
                }
                $out .= substr($cell, $at);

                break;
            }
            if ($destinationDepth > 0 || ($cell[$at] === '(' && $at > 0 && $cell[$at - 1] === ']')) {
                if ($cell[$at] === '(') {
                    $destinationDepth++;
                } elseif ($cell[$at] === ')') {
                    $destinationDepth--;
                }
                $out .= $cell[$at++];

                continue;
            }
            if ($cell[$at] === '<') {
                $end = null;
                if (substr($cell, $at, 4) === '<!--') {
                    $close = strpos($cell, '-->', $at + 4);
                    $end = $close === false ? null : $close + 3;
                } elseif (preg_match('/\G<[^<>\s]+>/', $cell, $angle, 0, $at) === 1) {
                    $end = $at + strlen($angle[0]);
                } else {
                    $end = $this->inlineStage->htmlTagAt($cell, $at)['end'] ?? null;
                }
                if ($end !== null) {
                    $out .= substr($cell, $at, $end - $at);
                    $at = $end;

                    continue;
                }
            }
            if ($cell[$at] !== 'h' || ($at > 0 && preg_match('/[a-z0-9]/i', $cell[$at - 1]) === 1) || preg_match('~\Ghttps?://[^\s<>`\x00]+~', $cell, $match, 0, $at) !== 1) {
                $out .= $cell[$at++];

                continue;
            }
            $body = $match[0];
            if (preg_match('/&(?:#[xX][0-9a-fA-F]+|#[0-9]+|[A-Za-z][A-Za-z0-9]*);$/', $body, $entity) === 1) {
                $body = substr($body, 0, -strlen($entity[0]));
            }
            $body = rtrim($body, "?!.,:;*_~\"'");
            $excess = substr_count($body, ')') - substr_count($body, '(');
            while ($excess > 0 && str_ends_with($body, ')')) {
                $body = substr($body, 0, -1);
                $excess--;
            }
            if (!str_contains($body, '|') || preg_match('~^https?://[^[:punct:]\s]~u', $body) !== 1) {
                $out .= $match[0];
            } else {
                $label = preg_replace('/([!-\/:-@\[-`{-~])/', '\\\\$1', $body) ?? $body;
                $url = MarkdownDestinationEncoding::destination($body, true);
                $out .= '[' . $label . '](' . $url . ')' . substr($match[0], strlen($body));
            }
            $at += strlen($match[0]);
        }

        return strtr($out, $spans);
    }

    /**
     * A GFM table header: a line holding a pipe whose next line is a delimiter
     * row with as many cells.
     *
     * @param array<int, string> $lines
     * @param int $index
     */
    public function startsTableHeader(array $lines, int $index): bool
    {
        $trimmed = trim($lines[$index]);
        $next = trim($lines[$index + 1] ?? '');
        if (!str_contains($trimmed, '|') || !str_contains($next, '-')) {
            return false;
        }
        // A delimiter row needs a pipe of its own. Without one, `---` under a
        // one-cell header counted as a row of one cell, so a lone pipe line
        // became a table and the setext underline below it lost its heading -
        // cmark-gfm takes the underline (carve-php#2349).
        if (!str_contains($next, '|') || preg_match('/^\|?\s*:?-+:?\s*(?:\|\s*:?-+:?\s*)*\|?$/', $next) !== 1) {
            return false;
        }

        return count($this->splitPipeCells($trimmed)) === count($this->splitPipeCells($next));
    }

    /**
     * Does a GFM table under way keep this line as a body row? GFM ends the
     * table at a blank line or a block construct, not at a line that merely
     * stops looking like a row: a plain line is a one-cell row.
     */
    public function continuesGfmTableBody(string $line): bool
    {
        $trimmed = trim($line);
        if ($trimmed === '' || ($this->indentWidth)($line) >= 4) {
            return false;
        }
        if (preg_match('/^(#{1,6}([ \t]|$)|>|`{3,}|~{3,}|(?:[-*+]|\d+[.)])([ \t]|$))/', $trimmed) === 1) {
            return false;
        }

        return preg_match(self::THEMATIC_BREAK, $trimmed) !== 1 && !($this->htmlBlockInterrupts)($trimmed);
    }

    /**
     * @param array<int, string> $lines
     * @param bool $inRun
     * @param int $contentCol
     * @param string $text
     * @param string $prefix
     * @param int $index
     * @param array<string, \MarkupCarve\Carve\Converter\MarkdownListMarkers> $markers
     * @param array{prefix: string, text: string, itemQuote?: string}|null $prev
     * @param string|null $lazy
     * @param array<int, string|null> $result
     *
     * @return array{lines: list<string>, end: int}|null
     */
    public function collectQuotedItemBlock(
        array $lines,
        int $index,
        string $prefix,
        string $text,
        int $contentCol,
        bool $inRun,
        array &$markers,
        ?array &$prev,
        ?string &$lazy,
        array &$result,
    ): ?array {
        $list = $markers[$prefix] ?? new MarkdownListMarkers();
        $paragraph = $inRun && $prev !== null && $prev['prefix'] === $prefix && ($this->quoteParagraphIsOpen)($prev['text']);
        if (
            $paragraph && (($this->isHeldOrderedMarker)($text, $list)
            || (!$list->hasListAt(($this->indentWidth)($text)) && preg_match('/^[ \t]*(?!0*1[.)])\d+[.)]/', $text) === 1)
            || ($this->indentWidth)($text) - ($list->openItemContentColumn() ?? 0) >= 4)
        ) {
            return null;
        }
        $quotedLead = false;
        $sourceCol = null;
        if (
            preg_match('/^([ \t]*(?:[-*+]|\d{1,9}[.)])[ \t]{1,4})(>.*)$/s', $text, $quotedItem) === 1
            && preg_match('/^(?:> )+(?: {0,3}(?:`{3,}|~{3,})| {4})/', ($this->normalizeBlockquoteMarkers)($quotedItem[2])) === 1
        ) {
            $quotedLead = true;
            $sourceCol = ($this->columnWidth)($quotedItem[1]);
        }
        if ($sourceCol === null && preg_match('/^([ \t]*(?:[-*+]|\d{1,9}[.)])) {5,}(\S.*)$/s', $text, $codeItem) === 1) {
            $sourceCol = ($this->columnWidth)($codeItem[1]) + 1;
        }
        if ($sourceCol !== null) {
            $virtual = [$text];
            for ($at = $index + 1, $count = count($lines); $at < $count; $at++) {
                if (($this->indentWidth)($lines[$at]) < $contentCol) {
                    break;
                }
                $body = ($this->quotedText)(($this->stripColumns)($lines[$at], $contentCol), $prefix);
                if ($body === null || (trim($body) !== '' && ($this->indentWidth)($body) < $sourceCol + ($quotedLead ? 0 : 4))) {
                    break;
                }
                $virtual[] = $body;
            }
            $written = ($this->respellQuotedLine)($lines, $index, $prefix, $text, $inRun, $markers, $prev, $lazy, $result);
            $block = ($this->writeItemContent)($virtual, 0, substr($written, strlen($prefix)), $sourceCol);
            if ($block !== null) {
                $writtenCol = $markers[$prefix]->openItemContentColumn() ?? $sourceCol;
                foreach ($block['lines'] as $at => $line) {
                    if ($at > 0) {
                        $block['lines'][$at] = ($this->moveIndent)($line, $sourceCol, $writtenCol);
                    }
                }
                $prev = null;
                $lazy = null;

                return ['lines' => array_map(static fn (string $line): string => str_repeat(' ', $contentCol) . $prefix . $line, array_values($block['lines'])), 'end' => $index + $block['end']];
            }
        }
        if (preg_match('/^([ \t]*(?:[-*+]|\d{1,9}[.)]) {1,4})(\|.*)$/s', $text, $item) !== 1) {
            return null;
        }
        $sourceCol = ($this->columnWidth)($item[1]);
        $table = $this->collectQuotedTable($lines, $index, $prefix, $item[2], $contentCol, $sourceCol);
        if ($table === null) {
            return null;
        }
        $written = ($this->respellQuotedLine)($lines, $index, $prefix, $text, $inRun, $markers, $prev, $lazy, $result);
        $writtenCol = $markers[$prefix]->openItemContentColumn() ?? $sourceCol;
        if ($table['lines'] === [$prefix . '%% ' . $this->context->omittedTableComment]) {
            $table['lines'] = [$prefix . '%%'];
        }
        foreach ($table['lines'] as $at => $row) {
            $lead = $at === 0 ? substr($written, 0, -strlen($item[2])) : $prefix . str_repeat(' ', $writtenCol);
            $table['lines'][$at] = str_repeat(' ', $contentCol) . $lead . substr($row, strlen($prefix));
        }
        $prev = null;
        $lazy = null;

        return $table;
    }

    /**
     * @param array<int, string> $lines
     * @param string $header
     * @param int $contentCol
     * @param int $quoteContentCol
     * @param string $prefix
     * @param int $index
     *
     * @return array{lines: list<string>, end: int}|null
     */
    public function collectQuotedTable(array $lines, int $index, string $prefix, string $header, int $contentCol = 0, int $quoteContentCol = 0): ?array
    {
        $inside = function (string $line) use ($prefix, $contentCol, $quoteContentCol): ?string {
            if (($this->indentWidth)($line) < $contentCol) {
                return null;
            }
            $line = ($this->stripColumns)($line, $contentCol);
            $line = ($this->normalizeBlockquoteMarkers)(ltrim(($this->expandLeadingTabs)($line), ' '));
            $own = $this->referenceStage->quotePrefixOf(ltrim($line, ' '));

            if ($own !== $prefix) {
                return null;
            }
            $body = substr(ltrim($line, ' '), strlen($own));

            return ($this->indentWidth)($body) >= $quoteContentCol ? ($this->stripColumns)($body, $quoteContentCol) : null;
        };
        $delimiter = $inside($lines[$index + 1] ?? '');
        if (!$this->continuesGfmTableBody($header) || $delimiter === null || ($this->indentWidth)($header) >= 4 || ($this->indentWidth)($delimiter) >= 4) {
            return null;
        }
        $held = [$header, $delimiter];
        if (!$this->startsTableHeader($held, 0)) {
            $this->recordRejectedTableHeader(trim($header), trim($delimiter), $index);

            return null;
        }
        $this->recordTableRowDiagnostics(trim($header), $index);
        $width = count($this->splitPipeCells($header));
        $previousLine = $this->inlineContext->inlineRunSourceLine;
        $this->inlineContext->inlineRunSourceLine = ($this->sourceLine)($index);
        $headerRow = $this->gfmHeaderToCarve(trim($header), trim($delimiter));
        $this->inlineContext->inlineRunSourceLine = $previousLine;
        $written = $this->keepHeaderRow($headerRow, $index) ? [$prefix . $headerRow] : [];
        $end = $index + 1;
        $count = count($lines);
        for ($at = $index + 2; $at < $count; $at++) {
            $body = $inside($lines[$at]);
            if ($body === null || !$this->continuesGfmTableBody($body)) {
                break;
            }
            $cells = $this->splitPipeCells($body);
            $this->recordTableRowDiagnostics(trim($body), $at, $width, $cells);
            $previousLine = $this->inlineContext->inlineRunSourceLine;
            $this->inlineContext->inlineRunSourceLine = ($this->sourceLine)($at);
            $bodyRow = $this->writeTableRow($cells, [], $width);
            $this->inlineContext->inlineRunSourceLine = $previousLine;
            $written[] = $prefix . $bodyRow;
            $end = $at;
        }

        if ($written === []) {
            $written = [$prefix . '%% ' . $this->context->omittedTableComment];
        }

        return ['lines' => $written, 'end' => $end];
    }

    public function writeWithoutOmittedTableComments(string $source): string
    {
        $document = CarveConverter::carve()->parse($source);
        $remove = function (Node $node) use (&$remove): void {
            foreach ($node->getChildren() as $child) {
                if ($child instanceof Comment && trim($child->getContent()) === $this->context->omittedTableComment) {
                    $node->removeChild($child);
                } else {
                    $remove($child);
                }
            }
        };
        $remove($document);

        return (new CarveRenderer())->render($document);
    }

    private function tableDiagnostic(string $code, string $message, int $index, string $fidelity = 'preserved'): void
    {
        $this->context->tableDiagnostics[] = new MigrationDiagnostic($code, $message, 'warning', $fidelity, 'exact', 'line:' . ($this->sourceLine)($index));
    }

    /**
     * @param string $row
     * @param int $index
     * @param int|null $width
     * @param array<int, string>|null $cells
     * @param bool $table
     */
    public function recordTableRowDiagnostics(string $row, int $index, ?int $width = null, ?array $cells = null, bool $table = true): void
    {
        if (isset($this->referenceContext->markdownHtmlSourceLines[$this->referenceContext->markdownSourceLines[$index] ?? $index])) {
            return;
        }

        if (str_contains($row, '`') && str_contains($row, '|')) {
            $hasPipe = false;
            $this->inlineStage->protectCodeSpans($row, static function (string $span) use (&$hasPipe): string {
                if (str_starts_with($span, '`') && preg_match('/(?<!\\\\)\|/', $span) === 1) {
                    $hasPipe = true;
                }

                return $span;
            });
            if ($hasPipe) {
                $this->tableDiagnostic(
                    'markdown-table-code-pipe',
                    'An unescaped pipe inside a code span splits table cells in GFM; escape it as \\| to keep the code span in one cell',
                    $index,
                );
            }
        }
        if ($width !== null) {
            foreach (array_slice($cells ?? $this->splitPipeCells($row), $width) as $extra) {
                if (trim($extra) !== '') {
                    $this->tableDiagnostic('markdown-table-extra-cells', 'GFM ignores body cells beyond the header width; their content was omitted', $index, 'dropped');

                    break;
                }
            }
        }
    }

    public function recordRejectedTableHeader(string $header, string $delimiter, int $index): void
    {
        if (isset($this->referenceContext->markdownHtmlSourceLines[$this->referenceContext->markdownSourceLines[$index] ?? $index])) {
            return;
        }

        if (!str_contains($header, '|') || !str_contains($delimiter, '|') || preg_match('/^\|?\s*:?-+:?\s*(?:\|\s*:?-+:?\s*)*\|?$/', $delimiter) !== 1) {
            return;
        }
        $headers = count($this->splitPipeCells($header));
        $delimiters = count($this->splitPipeCells($delimiter));
        if ($headers === $delimiters) {
            return;
        }
        $this->recordTableRowDiagnostics($header, $index, table: false);
        $this->tableDiagnostic(
            'markdown-table-header-mismatch',
            'The header has ' . $headers . ' cells but the delimiter row has ' . $delimiters . '; GFM reads these lines as paragraph text',
            $index,
        );
    }

    /**
     * Split a `|`-delimited table row into trimmed cell sources (outer pipes
     * removed; escaped `\|` is not a delimiter).
     *
     * @return array<int, string>
     */
    public function splitPipeCells(string $line): array
    {
        $line = trim($line);
        $line = preg_replace('/^\|/', '', $line) ?? $line;
        $line = preg_replace('/(?<!\\\\)\|$/', '', $line) ?? $line;
        $parts = preg_split('/(?<!\\\\)\|/', $line);

        return $parts === false ? [] : $parts;
    }
}
