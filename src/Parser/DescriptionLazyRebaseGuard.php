<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use MarkupCarve\Carve\Parser\Block\FencedBlockParser;
use MarkupCarve\Carve\Parser\Utility\IndentationHelper;

/**
 * Keeps an opener reached by a nested description out of an outer rebase.
 *
 * @internal
 */
final class DescriptionLazyRebaseGuard
{
    /**
     * @param array<string> $lines
     * @param array<int, int>|null $lineMap
     * @param array<int, true> $lazyLines
     * @param array<int, true>|null $eligible
     *
     * @return array<int, true>|null
     */
    public static function eligible(array $lines, ?array $lineMap, array $lazyLines, ?array $eligible): ?array
    {
        if ($lineMap === null || $lazyLines === []) {
            return $eligible;
        }

        $descriptionColumns = [];
        $afterLazy = false;
        $protected = [];
        $fence = null;
        $fences = new FencedBlockParser();
        foreach ($lines as $index => $entry) {
            $head = explode("\n", $entry, 2)[0];
            if ($fence !== null) {
                $protected[$index] = true;
                if (
                    IndentationHelper::getLeadingColumns($head) === $fence['column']
                    && $fences->isCodeFenceCloser(ltrim($head, " \t"), $fence['char'], $fence['length'])
                ) {
                    $fence = null;
                }

                continue;
            }
            if (isset($lazyLines[$lineMap[$index] ?? -1]) || str_contains($entry, "\n")) {
                if (preg_match(BlockGrammar::DEFINITION_BODY_PATTERN, ltrim($head, " \t"), $match) === 1) {
                    $descriptionColumns[] = IndentationHelper::getLeadingColumns($head)
                        + BlockGrammar::DEFINITION_MARKER_WIDTH + strlen($match[1]);
                }
                $afterLazy = true;

                continue;
            }
            $column = IndentationHelper::getLeadingColumns($head);
            if ($afterLazy && $descriptionColumns !== [] && $column >= $descriptionColumns[0]) {
                $protected[$index] = true;
                $opener = $fences->parseCodeFenceOpener(ltrim($head, " \t"))
                    ?? $fences->parseRawBlockOpener(ltrim($head, " \t"));
                if ($opener !== null) {
                    $fence = [
                        'column' => $column,
                        'char' => $opener['fence'][0],
                        'length' => $opener['length'],
                    ];
                }
            }
            $afterLazy = false;
            while ($descriptionColumns !== [] && $column < end($descriptionColumns)) {
                array_pop($descriptionColumns);
            }
            if (preg_match(BlockGrammar::DEFINITION_BODY_PATTERN, ltrim($head, " \t"), $match) === 1) {
                $descriptionColumns[] = $column + BlockGrammar::DEFINITION_MARKER_WIDTH + strlen($match[1]);
            }
        }

        if ($protected === []) {
            return $eligible;
        }
        if ($eligible === null) {
            $eligible = array_fill_keys(array_keys($lines), true);
        }
        foreach ($protected as $index => $_) {
            unset($eligible[$index]);
        }

        return $eligible;
    }
}
