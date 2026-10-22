<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\DefinitionDescription;
use MarkupCarve\Carve\Node\Block\DefinitionList;
use MarkupCarve\Carve\Node\Block\DefinitionTerm;
use MarkupCarve\Carve\Node\Node;

final class DescriptionSeparatorBelowContentColumnLinter
{
    /**
     * @return array<\MarkupCarve\Carve\Lint\LintWarning>
     */
    public function lint(string $source): array
    {
        $converter = new CarveConverter();
        $converter->getParser()->enablePositionTracking();
        $document = $converter->parse($source);
        $rows = preg_split('/\r\n|\r|\n/', $source, flags: PREG_SPLIT_OFFSET_CAPTURE) ?: [];
        $lists = [];
        $attached = [];
        $payload = [];
        $this->collect($document, $rows, $lists, $attached, $payload);
        $warnings = [];

        foreach ($rows as $index => [$line, $offset]) {
            $lineNumber = $index + 1;
            if (isset($attached[$lineNumber]) || isset($payload[$lineNumber])) {
                continue;
            }
            $candidate = $this->candidate($line);
            if ($candidate === null) {
                continue;
            }
            $previous = $index;
            while ($previous > 0 && preg_match('/^[ \t]*(?:>[ \t]*)*$/', $rows[$previous - 1][0])) {
                --$previous;
            }
            if ($previous === 0 || $this->quoteDepth($rows[$previous - 1][0]) !== $candidate['depth']) {
                continue;
            }
            $column = $this->visual(substr($line, 0, $candidate['index']));
            $host = null;
            foreach ($lists as $list) {
                if ($list['first'] > $previous || $list['last'] < $previous || $list['depth'] !== $candidate['depth'] || $list['column'] <= $column) {
                    continue;
                }
                if ($host === null || $list['column'] > $host['column']) {
                    $host = $list;
                }
            }
            if ($host === null) {
                continue;
            }
            $name = $candidate['marker'] === '::' ? 'term marker' : 'description separator';
            $message = "This {$name} starts at column {$column}, below the column {$host['column']} of the definition list above, so it adds no entry to that list. Indent it to column {$host['column']} to continue the list.";
            $start = $offset + $candidate['index'];
            $warnings[] = new LintWarning($lineNumber, mb_strlen(substr($line, 0, $candidate['index']), 'UTF-8') + 1, 'description-separator-below-content-column', $message, $start, $start + strlen($candidate['marker']));
        }

        return $warnings;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param array<int, array{0: string, 1: int}> $rows
     * @param array<int, array{first: int, last: int, column: int, depth: int}> $lists
     * @param array<int, true> $attached
     * @param array<int, true> $payload
     */
    private function collect(Node $node, array $rows, array &$lists, array &$attached, array &$payload): void
    {
        $pos = $node->getPos();
        if ($pos !== null) {
            $type = $node->getType();
            if (in_array($type, ['code_block', 'raw_block', 'comment', 'frontmatter'], true)) {
                for ($line = $pos->startLine; $line <= $pos->endLine; ++$line) {
                    $payload[$line] = true;
                }
            }
            if (in_array($type, ['code', 'math', 'raw_inline', 'literal_inline'], true)) {
                for ($line = $pos->startLine + 1; $line <= $pos->endLine; ++$line) {
                    $payload[$line] = true;
                }
            }
            if ($node instanceof DefinitionTerm || $node instanceof DefinitionDescription) {
                $attached[$pos->startLine] = true;
            }
            if ($node instanceof DefinitionList) {
                $term = $node->getChildren()[0] ?? null;
                $termLine = $term instanceof DefinitionTerm ? $term->getPos()?->startLine : null;
                $authored = $termLine === null ? null : $this->termMarker($rows[$termLine - 1][0] ?? '');
                if ($authored !== null) {
                    $line = $rows[$termLine - 1][0];
                    $lists[] = ['first' => $pos->startLine, 'last' => $pos->endLine, 'column' => $this->visual(substr($line, 0, $authored['index'])), 'depth' => $authored['depth']];
                }
            }
        }
        foreach ($node->getChildren() as $child) {
            $this->collect($child, $rows, $lists, $attached, $payload);
        }
    }

    /**
     * @return array{index: int, marker: string, depth: int}|null
     */
    private function candidate(string $line): ?array
    {
        $index = 0;
        $depth = 0;
        while (preg_match('/^[ \t]*>(?: |$)/', substr($line, $index), $match)) {
            $index += strlen($match[0]);
            ++$depth;
        }
        $index += strspn($line, " \t", $index);
        if (!preg_match('/^::?(?=[ \t]|$)/', substr($line, $index), $match)) {
            return null;
        }

        return ['index' => $index, 'marker' => $match[0], 'depth' => $depth];
    }

    /**
     * The first term's `::`, which may follow list markers on an item's marker line.
     *
     * @return array{index: int, depth: int}|null
     */
    private function termMarker(string $line): ?array
    {
        $index = 0;
        $depth = 0;
        while (preg_match('/^[ \t]*>(?: |$)/', substr($line, $index), $match)) {
            $index += strlen($match[0]);
            ++$depth;
        }
        while (preg_match('/^[ \t]*(?:[-+*](?: +\[[ xX\-_>?]\])?|\([0-9A-Za-z]+\)|[0-9A-Za-z]+[.)]|\.)(?:\{[^{}\r\n]*\})?[ \t]+(?=\S)/', substr($line, $index), $match)) {
            $index += strlen($match[0]);
        }
        $index += strspn($line, " \t", $index);
        if (!preg_match('/^::(?=[ \t]|$)/', substr($line, $index))) {
            return null;
        }

        return ['index' => $index, 'depth' => $depth];
    }

    private function quoteDepth(string $line): int
    {
        $depth = 0;
        $index = 0;
        while (preg_match('/^[ \t]*>(?: |$)/', substr($line, $index), $match)) {
            $index += strlen($match[0]);
            ++$depth;
        }

        return $depth;
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
