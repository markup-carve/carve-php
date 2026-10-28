<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\ListItem;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Block\TableRow;
use MarkupCarve\Carve\Node\Inline\Code;
use MarkupCarve\Carve\Node\Inline\HeadingRef;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Inline\Link;

final class AuthoringLinter
{
    /**
     * @return list<\MarkupCarve\Carve\Lint\LintWarning>
     */
    public function lint(string $source, bool $captionLookingParagraph = false): array
    {
        $converter = new CarveConverter();
        $converter->getParser()->enablePositionTracking();
        $document = $converter->parse($source);
        $offsetMap = SourceOffsets::map($source);
        $sourceLength = strlen($source);
        $rows = preg_split('/\r\n|\r|\n/', $source, flags: PREG_SPLIT_OFFSET_CAPTURE) ?: [];
        $paragraphs = [];
        $paragraphEnds = [];
        $paragraphStarts = [];
        $headings = [];
        $tables = [];
        $listItems = [];
        $verbatim = [];
        $protectedRanges = [];
        $emptyLinks = [];
        $pending = [$document];
        while ($pending !== []) {
            $node = array_pop($pending);
            $pos = $node->getPos();
            if ($pos !== null) {
                $startByte = SourceOffsets::toByte($pos->startOffset, $offsetMap, $sourceLength);
                $endByte = SourceOffsets::toByte($pos->endOffset, $offsetMap, $sourceLength);
                if ($node instanceof Paragraph) {
                    $paragraphStarts[$pos->startLine] = $startByte;
                    for ($line = $pos->startLine; $line <= $pos->endLine; ++$line) {
                        $paragraphs[$line] = true;
                        $paragraphEnds[$line] = $pos->endLine;
                    }
                } elseif ($node instanceof Heading) {
                    $headings[$pos->startOffset] = $node;
                } elseif ($node instanceof Table && $node->getSourceFormat() === null) {
                    $tables[] = $node;
                } elseif ($node instanceof ListItem) {
                    $listItems[$pos->startLine] = true;
                } elseif ($node instanceof Code || $node instanceof HeadingRef || $node instanceof Image || ($node instanceof Link && $node->isAutolink())) {
                    $protectedRanges[] = [$startByte, $endByte];
                } elseif ($node instanceof Link && $node->getReferenceLabel() === null && !$node->isAutolink()) {
                    $destinationStart = self::inlineDestinationStart($source, $startByte, $endByte);
                    if ($destinationStart !== null) {
                        $protectedRanges[] = [$destinationStart, $endByte];
                        if ($node->getDestination() === '') {
                            $emptyLinks[] = $pos;
                        }
                    }
                } elseif (
                    $node->getType() === 'code'
                    && $pos->startLine < $pos->endLine
                    && preg_match('/^(?:`{3,}|~{3,})/', substr($source, $startByte, $endByte - $startByte)) === 1
                ) {
                    $protectedRanges[] = [$startByte, $endByte];
                }
                if (in_array($node->getType(), ['code_block', 'raw_block', 'comment', 'frontmatter'], true)) {
                    for ($line = $pos->startLine; $line <= $pos->endLine; ++$line) {
                        $verbatim[$line] = true;
                    }
                }
            }
            foreach ($node->getChildren() as $child) {
                $pending[] = $child;
            }
        }

        $warnings = [];
        $length = strlen($source);
        $emit = static function (int $line, int $column, int $size, string $rule, string $message) use (&$warnings, $rows, $length): void {
            if (!isset($rows[$line - 1])) {
                return;
            }
            [$raw, $start] = $rows[$line - 1];
            $byte = strlen(mb_substr($raw, 0, max(0, $column - 1), 'UTF-8'));
            $warnings[] = new LintWarning($line, $column, $rule, $message, $start + $byte, min($start + $byte + $size, $length));
        };
        foreach ($emptyLinks as $pos) {
            $emit($pos->startLine, $pos->startColumn, 1, 'empty-link-destination', 'This inline link has an empty destination; add a URL or use plain text.');
        }
        usort($protectedRanges, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $firstProtected = 0;

        $tableRows = [];
        foreach ($tables as $table) {
            $rowsInTable = array_values(array_filter($table->getChildren(), static fn ($row): bool => $row instanceof TableRow));
            $width = count($rowsInTable[0]->getChildren());
            foreach ($rowsInTable as $index => $row) {
                $at = $row->getPos()?->startLine;
                if ($at === null) {
                    continue;
                }
                $tableRows[$at] = true;
                $cells = count($row->getChildren());
                if ($index > 0 && $cells !== $width) {
                    $column = strpos($rows[$at - 1][0] ?? '', '|');
                    $emit($at, $column === false ? 1 : $column + 1, 1, 'table-row-cell-count', "This table row has {$cells} cells; the header has {$width}. Add or remove a cell to match the table.");
                }
            }
        }

        ksort($headings);
        $previous = 0;
        $headingLines = [];
        foreach ($headings as $heading) {
            $at = $heading->getPos()?->startLine;
            if ($at === null) {
                continue;
            }
            $headingLines[$at] = true;
            $level = $heading->getLevel();
            if ($previous > 0 && $level > $previous + 1) {
                $column = strpos($rows[$at - 1][0] ?? '', '#');
                $emit($at, $column === false ? 1 : $column + 1, $level, 'heading-level-skip', "This heading skips from level {$previous} to {$level}; use the next heading level for a continuous outline.");
            }
            $previous = $level;
        }

        foreach ($rows as $index => [$raw, $rowStart]) {
            $at = $index + 1;
            if (isset($verbatim[$at])) {
                continue;
            }
            $stripped = self::stripPrefixes($raw);
            $prefix = strlen($raw) - strlen($stripped);
            $column = $prefix + strspn($stripped, " \t") + 1;
            $visible = self::visible($stripped);
            $visibleStart = $rowStart + $prefix;
            $visibleEnd = $rowStart + strlen($raw);
            while (isset($protectedRanges[$firstProtected]) && $protectedRanges[$firstProtected][1] <= $visibleStart) {
                $firstProtected++;
            }
            for ($protected = $firstProtected; isset($protectedRanges[$protected]) && $protectedRanges[$protected][0] < $visibleEnd; $protected++) {
                [$start, $end] = $protectedRanges[$protected];
                $from = max(0, $start - $visibleStart);
                $to = min(strlen($visible), $end - $visibleStart);
                if ($to > $from) {
                    $visible = substr_replace($visible, str_repeat(' ', $to - $from), $from, $to - $from);
                }
            }
            $paragraph = isset($paragraphs[$at]);
            $row = isset($tableRows[$at]);
            if ($paragraph) {
                $tableSource = $stripped;
                $tableColumn = $column;
                if (isset($listItems[$at], $paragraphStarts[$at])) {
                    $contentOffset = $paragraphStarts[$at] - $rowStart;
                    if ($contentOffset >= 0 && $contentOffset < strlen($raw)) {
                        $tableSource = substr($raw, $contentOffset);
                        $tableColumn = $contentOffset + 1;
                    }
                }
                if (preg_match('/^\s*\|(?:=|\s|[^\s|])/', $tableSource) && !self::isTableRow(ltrim($tableSource))) {
                    $prior = $rows[$index - 1][0] ?? '';
                    $prior = self::stripPrefixes($prior);
                    if ($index === 0 || !preg_match('/^\s*\|/', $prior) || self::isTableRow(ltrim($prior))) {
                        $emit($at, $tableColumn, 1, 'table-shaped-paragraph', 'This table-shaped line lacks a closing pipe and renders as paragraph text; close the table row.');
                    }
                }
                if ($index > 0 && !isset($listItems[$at]) && preg_match('/^(?:\s*> ?)*\s*(?:[-*]|\d+[.)])\s+\S/', $raw) && !preg_match('/^(?:\s*> ?)*\s*(?:[-*]|\d+[.)])\s+\S/', $rows[$index - 1][0]) && trim($rows[$index - 1][0]) !== '' && isset($paragraphs[$at - 1])) {
                    preg_match('/^(?:\s*> ?)*\s*/', $raw, $marker);
                    $emit($at, strlen($marker[0] ?? '') + 1, 1, 'list-marker-without-blank-line', 'This list marker is part of the preceding paragraph; add a blank line before the list.');
                }
                if (preg_match('/^\s*:\s+\S/', $stripped) && $index > 0 && isset($paragraphs[$at - 1]) && !preg_match('/^[ \t]*:: /', self::stripPrefixes($rows[$index - 1][0]))) {
                    $emit($at, $column, 1, 'markdown-definition-paragraph', 'This markdown definition remains paragraph text; use a :: term line before the definition.');
                }
                if ($captionLookingParagraph && preg_match('/^(?:\*?\s*(?:Caption|Table|Tabelle|Figure|Image)(?:\s+\d+)?\s*:|:\s+\S)/i', ltrim($stripped)) && (isset($tableRows[$at - 2]) || isset($tableRows[$at - 1]) || isset($tableRows[$at + 1]) || isset($tableRows[$at + 2]) || str_contains($rows[$index - 2][0] ?? '', '![') || str_contains($rows[$index - 1][0] ?? '', '!['))) {
                    $emit($at, $column, 1, 'caption-looking-paragraph', 'This caption-looking paragraph sits next to a table or image; use a ^ caption line.');
                }
            }
            if (!$paragraph && !$row && !isset($headingLines[$at])) {
                continue;
            }
            if (preg_match_all('/\[[^\]]+\]\s+\(\s*[^)\s][^)]*\)/', $visible, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as [$match, $offset]) {
                    $emit($at, $prefix + mb_strlen(substr($visible, 0, $offset), 'UTF-8') + 1, strlen($match), 'link-gap-before-destination', 'The gap after this link label keeps the link as text; write ]( with no space.');
                }
            }
            if (preg_match_all('/(^|[^\p{L}\p{N}])_([\p{L}][\p{L}\p{N}_ -]*?)_(?![\p{L}\p{N}])/u', $visible, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $matchIndex => [$match, $offset]) {
                    $start = $offset + strlen($matches[1][$matchIndex][0]);
                    $emit($at, $prefix + mb_strlen(substr($visible, 0, $start), 'UTF-8') + 1, strlen($match) - ($start - $offset), 'markdown-italic-underline', 'This markdown italic spelling renders as underline; use /word/ for italic.');
                }
            }
            if (preg_match('/<\/?[A-Za-z][\w:-]*(?:\s+[^<>]*)?\/?>/', $visible, $match, PREG_OFFSET_CAPTURE)) {
                $emit($at, $prefix + $match[0][1] + 1, strlen($match[0][0]), 'bare-html-tag', 'This bare HTML tag is escaped as text; use a raw HTML block if markup is intended.');
            }
            if (preg_match('/\[!(?:NOTE|TIP|IMPORTANT|WARNING|CAUTION)\]/', $visible, $match, PREG_OFFSET_CAPTURE) && preg_match('/^\s*>/', $raw)) {
                $emit($at, $prefix + $match[0][1] + 1, strlen($match[0][0]), 'github-alert-marker', 'This github alert marker stays literal in a Carve quote; use an admonition fence.');
            }
            if ($paragraph && preg_match_all('/(^|[\s([{])([*\/])(?=\w)/u', $visible, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $i => [$match, $offset]) {
                    $start = $offset + strlen($matches[1][$i][0]);
                    $delimiter = $matches[2][$i][0];
                    $remainder = substr($visible, $start + 1);
                    for ($next = $at + 1; $next <= ($paragraphEnds[$at] ?? $at); ++$next) {
                        $remainder .= "\n" . self::visible(self::stripPrefixes($rows[$next - 1][0] ?? ''));
                    }
                    if (!str_contains($remainder, $delimiter)) {
                        $kind = $delimiter === '*' ? 'strong' : 'emphasis';
                        $emit($at, $prefix + mb_strlen(substr($visible, 0, $start), 'UTF-8') + 1, 1, 'unclosed-inline-emphasis', "This {$kind} opener is unclosed in its paragraph; add a closer.");
                    }
                }
            }
            if ($row || $paragraph) {
                $cells = array_map('trim', array_slice(explode('|', ltrim($stripped)), 1, -1));
                $dashed = preg_match('/^\s*\|[\s:|\-x]+\|?\s*$/', $stripped) && str_contains($stripped, '--');
                $validSeparator = preg_match('/^\s*\|(?:\s*:?-{3,}:?\s*\|)+\s*$/', $stripped);
                if (($dashed && !$validSeparator) || ($row && count(array_filter($cells, static fn (string $cell): bool => (bool)preg_match('/^-{3,}$/', $cell))) > 0)) {
                    $emit($at, $column, 1, 'table-separator-near-miss', 'This table separator spelling renders as table content; correct the dash cells.');
                }
            }
        }

        return $warnings;
    }

    private static function stripPrefixes(string $line): string
    {
        while (preg_match('/^\s*> ?/', $line, $match)) {
            $line = substr($line, strlen($match[0]));
        }

        return $line;
    }

    private static function inlineDestinationStart(string $source, int $start, int $end): ?int
    {
        $depth = 0;
        $escaped = false;
        for ($i = $start; $i + 1 < $end; $i++) {
            $char = $source[$i];
            if ($escaped) {
                $escaped = false;

                continue;
            }
            if ($char === '\\') {
                $escaped = true;

                continue;
            }
            if ($char === '[') {
                $depth++;
            } elseif ($char === ']' && $depth > 0) {
                $depth--;
                if ($depth === 0 && $source[$i + 1] === '(') {
                    return $i + 2;
                }
            }
        }

        return null;
    }

    private static function isTableRow(string $line): bool
    {
        return str_starts_with($line, '|') && preg_match('/\|[ \t]*$/', $line) === 1 && strlen(trim($line)) > 1;
    }

    private static function visible(string $line): string
    {
        $line = preg_replace_callback('/\\\\[\\\\`*_\/<>()\[\]]/', static fn (array $m): string => str_repeat(' ', strlen($m[0])), $line) ?? $line;

        return preg_replace_callback('/(`+)(?:(?!\1).)*\1/', static fn (array $m): string => str_repeat(' ', strlen($m[0])), $line) ?? $line;
    }
}
