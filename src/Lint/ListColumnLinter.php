<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\ListItem;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;

final class ListColumnLinter
{
    /**
     * @var string
     */
    private const MARKER = '/^(?:[-+*](?: +\[[ xX\-_>?\/!]\])?|\([0-9A-Za-z]+\)|[0-9A-Za-z]+[.)]|\.)(?=[ \t])/';

    /**
     * @return list<\MarkupCarve\Carve\Lint\LintWarning>
     */
    public function lint(string $source): array
    {
        $converter = new CarveConverter();
        $converter->getParser()->enablePositionTracking();
        $document = $converter->parse($source);
        $rows = preg_split('/\r\n|\r|\n/', $source, flags: PREG_SPLIT_OFFSET_CAPTURE) ?: [];
        $items = [];
        $warnings = [];
        $this->collect($document, $rows, $items, $warnings);

        foreach ($rows as $index => [$line, $lineOffset]) {
            $lineNumber = $index + 1;
            if (!preg_match('/^(?:(?:[ \t]*> ?)+)?[ \t]*/', $line, $prefix)) {
                continue;
            }
            $at = strlen($prefix[0]);
            $rest = substr($line, $at);
            if (!preg_match(self::MARKER, $rest, $marker)) {
                continue;
            }
            $column = mb_strlen(substr($line, 0, $at), 'UTF-8') + 1;
            $visual = $this->visual(substr($line, 0, $at));
            $detached = $this->detached($items, $rows, $lineNumber, $at, $visual, $column, $lineOffset, $marker[0]);
            if ($detached !== null) {
                $warnings[] = $detached;

                continue;
            }
            foreach (array_reverse($items) as $item) {
                if ($lineNumber <= $item['first'] || $lineNumber > $item['last'] || !isset($item['paragraphs'][$lineNumber]) || !isset($item['textStarts'][$lineNumber . ':' . $column])) {
                    continue;
                }
                if ($item['base'] >= $visual || $visual >= $item['content']) {
                    continue;
                }
                $message = "This list marker starts at column {$visual}, below the list item's content column {$item['content']}, so it folds into the item's paragraph as text. Indent it to column {$item['content']} to nest it under the item, or start it at column {$item['base']} to begin a new item at the item's own level.";
                $warnings[] = new LintWarning($lineNumber, $column, 'list-marker-below-content-column', $message, $lineOffset + $at, $lineOffset + $at + strlen($marker[0]));

                break;
            }
        }

        $split = [];
        foreach ($warnings as $warning) {
            if ($warning->rule === 'ordered-list-split-by-indent') {
                $split[$warning->line] = true;
            }
        }

        return array_values(array_filter(
            $warnings,
            static fn (LintWarning $warning): bool => $warning->rule !== 'list-marker-below-content-column' || !isset($split[$warning->line]),
        ));
    }

    /**
     * A marker after a blank line, below the content column of the item above, starts a separate list.
     *
     * @param list<array{first: int, last: int, base: int, content: int, quote: int, paragraphs: array<int, true>, textStarts: array<string, true>}> $items
     * @param array<int, array{0: string, 1: int}> $rows
     * @param int $lineNumber
     * @param int $at
     * @param int $visual
     * @param int $column
     * @param int $lineOffset
     * @param string $marker
     *
     * @return \MarkupCarve\Carve\Lint\LintWarning|null
     */
    private function detached(array $items, array $rows, int $lineNumber, int $at, int $visual, int $column, int $lineOffset, string $marker): ?LintWarning
    {
        if ($lineNumber < 2 || !$this->blank($rows[$lineNumber - 2][0] ?? '')) {
            return null;
        }
        $opened = null;
        foreach ($items as $item) {
            if ($item['first'] === $lineNumber && ($opened === null || $item['base'] < $opened['base'])) {
                $opened = $item;
            } elseif ($item['first'] < $lineNumber && $lineNumber <= $item['last']) {
                return null;
            }
        }
        if ($opened === null) {
            return null;
        }
        $host = null;
        foreach ($items as $item) {
            if ($item['first'] >= $lineNumber || $item['quote'] !== $opened['quote'] || $item['base'] >= $opened['base'] || $opened['base'] >= $item['content']) {
                continue;
            }
            for ($ln = $item['last'] + 1; $ln < $lineNumber; ++$ln) {
                if (!$this->blank($rows[$ln - 1][0] ?? '')) {
                    continue 2;
                }
            }
            if ($host === null || $item['content'] > $host['content']) {
                $host = $item;
            }
        }
        if ($host === null) {
            return null;
        }
        $message = "This list marker starts at column {$visual} after a blank line, below the content column {$host['content']} of the list item above, so it starts a separate list instead of nesting. Indent it to column {$host['content']} to nest it under the item, or start it at column {$host['base']} to put it at the item's own level.";
        $lineOffsetAt = $lineOffset + $at;

        return new LintWarning($lineNumber, $column, 'list-marker-below-content-column', $message, $lineOffsetAt, $lineOffsetAt + strlen($marker));
    }

    private function blank(string $line): bool
    {
        return trim(preg_replace('/^(?:[ \t]*> ?)+/', '', $line) ?? $line) === '';
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param array<int, array{0: string, 1: int}> $rows
     * @param list<array{first: int, last: int, base: int, content: int, quote: int, paragraphs: array<int, true>, textStarts: array<string, true>}> $items
     * @param list<\MarkupCarve\Carve\Lint\LintWarning> $warnings
     */
    private function collect(Node $node, array $rows, array &$items, array &$warnings): void
    {
        $previous = null;
        foreach ($node->getChildren() as $child) {
            if ($previous instanceof ListBlock && $child instanceof ListBlock) {
                $warning = $this->splitWarning($previous, $child, $rows);
                if ($warning !== null) {
                    $warnings[] = $warning;
                }
            }
            $previous = $child;
            $pos = $child->getPos();
            if ($child instanceof ListItem && $pos !== null) {
                $line = $rows[$pos->startLine - 1][0] ?? '';
                $at = strlen(mb_substr($line, 0, $pos->startColumn - 1, 'UTF-8'));
                if (preg_match('/^([ \t]*)([-*]|(?:[0-9]+|[ivxlcdm]+|[IVXLCDM]+|[a-zA-Z])[.)])(\{[^{}\r\n]*\})?( +)(?:\[[ xX_?>\/!-]\] +)?/', substr($line, $at), $match)) {
                    $base = $this->visual(substr($line, 0, $at)) + $this->visual($match[1]);
                    $paragraphs = [];
                    $textStarts = [];
                    foreach ($child->getChildren() as $block) {
                        $paragraphPos = $block->getPos();
                        if (!$block instanceof Paragraph || $paragraphPos === null) {
                            continue;
                        }
                        for ($ln = $paragraphPos->startLine + 1; $ln <= $paragraphPos->endLine; ++$ln) {
                            $paragraphs[$ln] = true;
                        }
                        $this->textStarts($block, $textStarts);
                    }
                    $items[] = ['first' => $pos->startLine, 'last' => $pos->endLine, 'quote' => substr_count(substr($line, 0, $at), '>'), 'base' => $base, 'content' => $base + mb_strlen($match[2], 'UTF-8') + strlen($match[4]), 'paragraphs' => $paragraphs, 'textStarts' => $textStarts];
                }
            }
            $this->collect($child, $rows, $items, $warnings);
        }
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $node
@param array<string, true> $starts
     */
    private function textStarts(Node $node, array &$starts): void
    {
        foreach ($node->getChildren() as $child) {
            $pos = $child->getPos();
            if ($child instanceof Text && $pos !== null) {
                $starts[$pos->startLine . ':' . $pos->startColumn] = true;
            }
            $this->textStarts($child, $starts);
        }
    }

    /**
     * @param \MarkupCarve\Carve\Node\Block\ListBlock $first
     * @param \MarkupCarve\Carve\Node\Block\ListBlock $second
     * @param array<int, array{0: string, 1: int}> $rows
     */
    private function splitWarning(ListBlock $first, ListBlock $second, array $rows): ?LintWarning
    {
        if ($first->getListType() !== ListBlock::TYPE_ORDERED || $second->getListType() !== ListBlock::TYPE_ORDERED || $first->hasBareMarker() || $second->hasBareMarker() || $first->getStyle() !== $second->getStyle()) {
            return null;
        }
        $firstMarker = $first->getMarker() ?? '';
        $secondMarker = $second->getMarker() ?? '';
        if ($firstMarker === '' || $secondMarker === '' || substr($firstMarker, -1) !== substr($secondMarker, -1) || $second->getStart() !== $first->getStart() + count($first->getChildren())) {
            return null;
        }
        $firstPos = $first->getPos();
        $secondPos = $second->getPos();
        if ($firstPos === null || $secondPos === null) {
            return null;
        }
        for ($ln = $firstPos->endLine + 1; $ln < $secondPos->startLine; ++$ln) {
            $between = $rows[$ln - 1][0] ?? '';
            if (trim(preg_replace('/^(?:[ \t]*> ?)+/', '', $between) ?? $between) !== '') {
                return null;
            }
        }
        $a = $this->firstMarker($first, $rows);
        $b = $this->firstMarker($second, $rows);
        if ($a === null || $b === null || $a['visual'] === $b['visual']) {
            return null;
        }
        $message = "This ordered list continues the numbering of the list above ({$first->getStart()}, then {$second->getStart()}), but its marker starts at column {$b['visual']} and the list above starts at column {$a['visual']}, so they are two lists. Start the markers at the same column";
        $message .= $first->getStyle() !== null ? '.' : ", or write {start={$first->getStart()}} on the line above and use the auto-numbered . marker.";

        return new LintWarning($b['line'], $b['column'], 'ordered-list-split-by-indent', $message, $b['offset'], $b['offset'] + 1);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Block\ListBlock $list
     * @param array<int, array{0: string, 1: int}> $rows
     *
     * @return array{line: int, column: int, visual: int, offset: int}|null
     */
    private function firstMarker(ListBlock $list, array $rows): ?array
    {
        $item = $list->getChildren()[0] ?? null;
        $lineNumber = $item?->getPos()?->startLine;
        if ($lineNumber === null || !isset($rows[$lineNumber - 1])) {
            return null;
        }
        [$line, $offset] = $rows[$lineNumber - 1];
        if (!preg_match('/^(?:[ \t]*> ?)*[ \t]*(\([0-9A-Za-z]+\)|[0-9A-Za-z]+[.)]|\.)(?=[ \t])/', $line, $match)) {
            return null;
        }
        $at = strlen($match[0]) - strlen($match[1]);

        return ['line' => $lineNumber, 'column' => mb_strlen(substr($line, 0, $at), 'UTF-8') + 1, 'visual' => $this->visual(substr($line, 0, $at)), 'offset' => $offset + $at];
    }

    private function visual(string $prefix): int
    {
        $column = 0;
        foreach (preg_split('//u', $prefix, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $column = $char === "\t" ? (intdiv($column, 4) + 1) * 4 : $column + 1;
        }

        return $column;
    }
}
