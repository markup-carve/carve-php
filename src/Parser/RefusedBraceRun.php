<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use Closure;
use MarkupCarve\Carve\Parser\Block\FencedBlockParser;
use MarkupCarve\Carve\Parser\Utility\IndentationHelper;

/**
 * A block brace run refused only because a continuation does not pass its opening `{` (CARVE-P2-035).
 *
 * Its lines are literal paragraph text, and the next line joins that paragraph.
 */
final class RefusedBraceRun
{
    /**
     * The last closer scan: from a line to the first closer or blank line at or after it.
     *
     * @var array{lines: array<string>, from: int, stop: int, closes: bool}|null
     */
    private static ?array $closerScan = null;

    /**
     * The index past the run's last line, or null when the run at $start is not refused.
     *
     * @param array<string> $lines
     * @param int $start
     * @param \MarkupCarve\Carve\Parser\InlineParser $inlineParser
     * @param int|null $limit Index the run may not reach.
     * @param bool $more Whether lines follow $lines; -1 then means the run reaches past them.
     */
    public static function end(array $lines, int $start, InlineParser $inlineParser, ?int $limit = null, bool $more = false): ?int
    {
        $first = self::content($lines[$start] ?? '');
        if ($first === null) {
            return null;
        }
        $openerColumn = IndentationHelper::getLeadingColumns($first);
        $opener = ltrim($first, " \t");
        if (!str_starts_with($opener, '{')) {
            return null;
        }
        $comment = str_starts_with($opener, '{%');
        if ($comment && !$more && !self::closerBeforeBlank($lines, $start)) {
            return null;
        }
        $run = [];
        $shallow = false;
        $depth = 0;
        $blockColumn = $openerColumn;
        $count = min(count($lines), $limit ?? PHP_INT_MAX);
        for ($i = $start; $i < $count; $i++) {
            $line = $i === $start ? $first : self::content($lines[$i]);
            if ($line === null || IndentationHelper::isBlankLine($line)) {
                return null;
            }
            $column = IndentationHelper::getLeadingColumns($line);
            $rest = ltrim($line, " \t");
            if ($i > $start) {
                // A later opener starts a run of its own, so a paragraph of openers is scanned once.
                if (!$comment && str_starts_with($rest, '{')) {
                    return null;
                }
                if ($column <= $blockColumn || str_starts_with($lines[$i], BlockGrammar::LAZY_FRAME)) {
                    $shallow = true;
                }
            }
            $run[] = $i === $start ? $rest : str_repeat(' ', max(1, $blockColumn - $openerColumn + 1, $column - $openerColumn)) . $rest;
            if ($comment) {
                if (str_contains($line, '%}')) {
                    return $shallow ? $i + 1 : null;
                }

                continue;
            }
            if (!self::scanBraces($line, $depth, $blockColumn)) {
                return null;
            }
            if ($depth === 0) {
                return $shallow && WrappedBlockAttributeScanner::scan($run, 0, $inlineParser) !== null ? $i + 1 : null;
            }
        }

        return $more ? -1 : null;
    }

    /**
     * The index past the closer of a block `{%` run at $start whose continuations all pass its `{`, or null.
     *
     * Every line through the closer belongs to the comment, whatever block it spells (CARVE-P2-035, PART 9 §21a).
     *
     * @param array<string> $lines
     * @param int $start
     */
    public static function heldCommentEnd(array $lines, int $start): ?int
    {
        $first = self::content($lines[$start] ?? '');
        if ($first === null || !str_starts_with(ltrim($first, " \t"), '{%') || str_contains($first, '%}')) {
            return null;
        }
        $openerColumn = IndentationHelper::getLeadingColumns($first);
        for ($i = $start + 1, $count = count($lines); $i < $count; $i++) {
            $line = self::content($lines[$i]);
            if (
                $line === null
                || IndentationHelper::isBlankLine($line)
                || str_starts_with($lines[$i], BlockGrammar::LAZY_FRAME)
                || IndentationHelper::getLeadingColumns($line) <= $openerColumn
            ) {
                return null;
            }
            if (str_contains($line, '%}')) {
                return $i + 1;
            }
        }

        return null;
    }

    /**
     * Whether a `%}` stands on line $start or a later one before the next blank line.
     *
     * Memoized per lines array, so every `{%` opener of one unclosed paragraph is answered by a single scan.
     *
     * @param array<string> $lines
     * @param int $start
     */
    public static function closerBeforeBlank(array $lines, int $start): bool
    {
        $memo = self::$closerScan;
        if ($memo === null || $start < $memo['from'] || $start > $memo['stop'] || $memo['lines'] !== $lines) {
            $count = count($lines);
            $j = $start;
            while ($j < $count && !str_contains($lines[$j], '%}') && trim($lines[$j], " \t") !== '') {
                $j++;
            }
            $memo = self::$closerScan = ['lines' => $lines, 'from' => $start, 'stop' => $j, 'closes' => $j < $count && str_contains($lines[$j], '%}')];
        }

        return $memo['closes'];
    }

    /**
     * Drop the memoized scan, so a long-lived process does not keep the last document's lines.
     */
    public static function forgetCloserScan(): void
    {
        self::$closerScan = null;
    }

    /**
     * Whether a run opened by $line ends on that line, so {@see self::end()} refuses nothing there.
     */
    public static function endsOnItsLine(string $line): bool
    {
        $line = self::content($line);
        if ($line === null) {
            return true;
        }
        $rest = ltrim($line, " \t");
        if (str_starts_with($rest, '{%')) {
            return str_contains($line, '%}');
        }
        $depth = 0;
        $blockColumn = 0;

        return !str_starts_with($rest, '{') || !self::scanBraces($line, $depth, $blockColumn) || $depth === 0;
    }

    /**
     * Track the run's brace depth across one line; false when a `}` closes more than was opened.
     */
    private static function scanBraces(string $line, int &$depth, int &$blockColumn): bool
    {
        $length = strlen($line);
        $sourceColumn = 0;
        $quote = null;
        for ($k = 0; $k < $length; $k++) {
            $char = $line[$k];
            if ($quote !== null) {
                if ($char === '\\') {
                    $k++;
                    $sourceColumn++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '{' && $depth === 0) {
                $blockColumn = $sourceColumn;
                $depth = 1;
            } elseif ($char === '}' && --$depth < 0) {
                return false;
            }
            $sourceColumn = $char === "\t" ? intdiv($sourceColumn, 4) * 4 + 4 : $sourceColumn + 1;
        }

        return true;
    }

    /**
     * The last line a refused run's paragraph keeps: the follower, a fence opener there through its closer.
     *
     * Null when the run at $start is not refused, -1 when $more lines would decide it.
     *
     * @param array<string> $lines
     * @param int $start
     * @param \MarkupCarve\Carve\Parser\InlineParser $inlineParser
     * @param \Closure(string): bool $isDefinition
     * @param \MarkupCarve\Carve\Parser\Block\FencedBlockParser $fenced
     * @param int $column The container's content column; a follower short of it misses the container.
     * @param bool $more Whether lines follow $lines.
     * @param bool $bodyBound Whether the container is a description body, which ends at any line short of its column.
     */
    public static function keptThrough(
        array $lines,
        int $start,
        InlineParser $inlineParser,
        Closure $isDefinition,
        FencedBlockParser $fenced,
        int $column = 0,
        bool $more = false,
        bool $bodyBound = false,
    ): ?int {
        $runEnd = self::end($lines, $start, $inlineParser, null, $more);
        if ($runEnd === null || $runEnd === -1) {
            return $runEnd;
        }
        // PART 0 S1 erases a comment or link definition line first, so the follower is the line after it.
        while (isset($lines[$runEnd]) && self::invisibleSibling(ltrim($lines[$runEnd], " \t"), $isDefinition)) {
            $runEnd++;
        }
        if ($more && !isset($lines[$runEnd])) {
            return -1;
        }
        $follower = isset($lines[$runEnd]) ? ltrim($lines[$runEnd], " \t") : '';
        // A note definition or a lone `+` short of the column is still the protected follower (carve#5658 R03).
        if (
            trim($follower, " \t") === ''
            || (IndentationHelper::getLeadingColumns($lines[$runEnd]) < $column
                && preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $follower) !== 1
                && rtrim($follower, " \t") !== '+')
        ) {
            return $runEnd - 1;
        }
        if (preg_match('/^(`{3,}|~{3,})/', $follower, $fence) !== 1 || $fenced->parseCodeFenceOpener($follower) === null) {
            return $runEnd;
        }
        // The paragraph still ends at a blank; a fence with no closer at all is the follower alone.
        $blank = -1;
        $count = count($lines);
        for ($j = $runEnd + 1; $j < $count; $j++) {
            $line = ltrim($lines[$j] ?? '', " \t");
            if ($blank < 0 && trim($line, " \t") === '') {
                $blank = $j;
            }
            // A block opener short of this paragraph's owner ends its span.
            // Its later fence lines belong to the surviving owner, not to the
            // refused run's protected follower.
            // A description body ends at a line short of its column, and the fence-shaped follower is read there as usual.
            if ($bodyBound && $blank < 0 && trim($line, " \t") !== '' && IndentationHelper::getLeadingColumns($lines[$j]) < $column) {
                return $runEnd - 1;
            }
            if (
                $blank < 0 && IndentationHelper::getLeadingColumns($lines[$j]) < $column
                && (preg_match('/^(?:>|#{1,6}(?:[ \t]|$))/', $line) === 1
                    || $fenced->parseCodeFenceOpener($line) !== null
                    || $fenced->parseRawBlockOpener($line) !== null)
            ) {
                return $j - 1;
            }
            if (
                preg_match('/^(`{3,}|~{3,})[ \t]*$/D', $line, $closer) === 1
                && $closer[1][0] === $fence[1][0] && strlen($closer[1]) >= strlen($fence[1])
            ) {
                return $blank < 0 ? $j : $blank - 1;
            }
        }

        return $more && $blank < 0 ? -1 : $runEnd;
    }

    /**
     * @param string $rest
     * @param \Closure(string): bool $isDefinition
     */
    private static function invisibleSibling(string $rest, Closure $isDefinition): bool
    {
        return str_starts_with($rest, '%%') || ($rest !== '' && !str_starts_with($rest, '[^') && $isDefinition($rest));
    }

    /**
     * Escape the ASCII punctuation of one literal line, so the inline parse reads it as text.
     */
    public static function escape(string $line): string
    {
        return (string)preg_replace('/[!-\/:-@\[-`{-~]/', '\\\\$0', $line);
    }

    private static function content(string $line): ?string
    {
        if (str_contains($line, "\n")) {
            return null;
        }

        while (str_starts_with($line, BlockGrammar::LAZY_FRAME)) {
            $line = substr($line, strlen(BlockGrammar::LAZY_FRAME));
        }

        return $line;
    }
}
