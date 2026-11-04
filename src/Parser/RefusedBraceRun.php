<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use MarkupCarve\Carve\Parser\Utility\IndentationHelper;

/**
 * A block brace run refused only because a continuation does not pass its opening `{` (CARVE-P2-035).
 *
 * Its lines are literal paragraph text, and the next line joins that paragraph.
 */
final class RefusedBraceRun
{
    /**
     * The index past the run's last line, or null when the run at $start is not refused.
     *
     * @param array<string> $lines
     * @param int $start
     * @param \MarkupCarve\Carve\Parser\InlineParser $inlineParser
     * @param int|null $limit Index the run may not reach.
     */
    public static function end(array $lines, int $start, InlineParser $inlineParser, ?int $limit = null): ?int
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
                    return null;
                }
                $sourceColumn = $char === "\t" ? intdiv($sourceColumn, 4) * 4 + 4 : $sourceColumn + 1;
            }
            if ($depth === 0) {
                return $shallow && WrappedBlockAttributeScanner::scan($run, 0, $inlineParser) !== null ? $i + 1 : null;
            }
        }

        return null;
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

        return str_starts_with($line, BlockGrammar::LAZY_FRAME) ? substr($line, strlen(BlockGrammar::LAZY_FRAME)) : $line;
    }
}
