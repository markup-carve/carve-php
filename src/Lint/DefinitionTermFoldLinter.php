<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Block\DefinitionTerm;
use MarkupCarve\Carve\Node\Inline\Code;
use MarkupCarve\Carve\Node\Inline\LiteralInline;
use MarkupCarve\Carve\Node\Inline\Math;
use MarkupCarve\Carve\Node\Inline\RawInline;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\Block\TableParser;

/**
 * A block opener indented past a term's marker folds into the term as text,
 * because a term has no content column (markup-carve/carve#2411). Reports the
 * first such line per term.
 */
class DefinitionTermFoldLinter
{
    /**
     * @var string
     */
    public const RULE_DEFINITION_TERM_BLOCK_FOLDED = 'definition-term-block-folded';

    /**
     * Mirrors carve-js `LINT_BLOCK_OPENER`.
     *
     * @var string
     */
    private const BLOCK_OPENER = '/^(?:#{1,6} +\S|>(?: |$)|`{3,}|~{3,}|::(?: |$)|:{3,}(?: |$)|!\[[^\]]*\]\([^)\s]+(?: "[^"]*"| \'[^\']*\')?\)(?:\{[^{}\n]+\})?[ \t]*$|\[\^[^\]]+\]: +\S|\[[^\]]+\]: +\S|(?:-{3,}|\*{3,}|_{3,})[ \t]*$)/u';

    /**
     * @var int
     */
    private const MAX_WALK_DEPTH = 512;

    private TableParser $tableParser;

    public function __construct()
    {
        $this->tableParser = new TableParser();
    }

    /**
     * @param string $source
     * @param array<string, mixed> $options Accepted for signature parity with
     *   the other linters; this pass reads none.
     *
     * @return list<\MarkupCarve\Carve\Lint\LintWarning>
     */
    public function lint(string $source, array $options = []): array
    {
        $converter = new CarveConverter();
        $converter->getParser()->enablePositionTracking();
        $document = $converter->parse($source);

        $lines = preg_split('/\r\n|\r|\n/', $source) ?: [];
        $starts = [];
        $offset = 0;
        foreach ($lines as $i => $line) {
            $starts[$i] = $offset;
            $offset += strlen($line);
            $offset += substr($source, $offset, 2) === "\r\n" ? 2 : 1;
        }

        $warnings = [];
        $this->collect($document, $lines, $starts, $warnings);

        return $warnings;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param array<int, string> $lines
     * @param array<int, int> $starts
     * @param list<\MarkupCarve\Carve\Lint\LintWarning> $warnings
     * @param int $depth
     */
    private function collect(Node $node, array $lines, array $starts, array &$warnings, int $depth = 0): void
    {
        if ($depth >= self::MAX_WALK_DEPTH) {
            return;
        }
        foreach ($node->getChildren() as $child) {
            if ($child instanceof DefinitionTerm) {
                $warning = $this->check($child, $lines, $starts);
                if ($warning !== null) {
                    $warnings[] = $warning;
                }

                continue;
            }
            $this->collect($child, $lines, $starts, $warnings, $depth + 1);
        }
    }

    /**
     * @param \MarkupCarve\Carve\Node\Block\DefinitionTerm $term
     * @param array<int, string> $lines
     * @param array<int, int> $starts
     */
    private function check(DefinitionTerm $term, array $lines, array $starts): ?LintWarning
    {
        $pos = $term->getPos();
        if ($pos === null || $pos->endLine <= $pos->startLine) {
            return null;
        }
        $termLine = $lines[$pos->startLine - 1] ?? '';
        $markerBytes = strlen(mb_substr($termLine, 0, max(0, $pos->startColumn - 1), 'UTF-8'));
        $quotes = substr_count(substr($termLine, 0, $markerBytes), '>');
        $markerColumn = $this->visualColumn($termLine, $markerBytes);
        $verbatim = [];
        $this->collectVerbatimSpans($term, $verbatim);

        for ($ln = $pos->startLine + 1; $ln <= $pos->endLine; $ln++) {
            $line = $lines[$ln - 1] ?? '';
            foreach ($verbatim as $span) {
                if ($span[0] < $ln && $ln <= $span[1]) {
                    continue 2;
                }
                if ($span[2] && $span[0] === $ln && str_starts_with(ltrim($line, " \t>"), '%%')) {
                    continue 2;
                }
            }
            $chars = $this->peel($line, $quotes);
            if ($chars === null || $this->visualColumn($line, $chars) <= $markerColumn) {
                continue;
            }
            $rest = substr($line, $chars);
            if (!preg_match(self::BLOCK_OPENER, $rest) && !$this->tableParser->isTableRow($rest)) {
                continue;
            }
            $start = ($starts[$ln - 1] ?? 0) + $chars;

            return new LintWarning(
                $ln,
                mb_strlen(substr($line, 0, $chars), 'UTF-8') + 1,
                self::RULE_DEFINITION_TERM_BLOCK_FOLDED,
                'This block opener is indented under a definition term, which has no content column, '
                . 'so it folds into the term as text. Dedent it to the container\'s content column '
                . 'to open the block, or put it in the term\'s ": " description.',
                $start,
                ($starts[$ln - 1] ?? 0) + strlen($line),
            );
        }

        return null;
    }

    /**
     * Line ranges of multi-line code, math, raw and literal spans at any inline
     * depth, and of folded comments; a line inside one is not a folded opener.
     *
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param array<int, array{int, int, bool}> $spans
     * @param int $depth
     */
    private function collectVerbatimSpans(Node $node, array &$spans, int $depth = 0): void
    {
        if ($depth >= self::MAX_WALK_DEPTH) {
            return;
        }
        foreach ($node->getChildren() as $child) {
            $pos = $child->getPos();
            if ($pos !== null && $child instanceof Comment) {
                // A folded comment's lines are not term text. Its first line
                // is only when the comment opens it, which the caller checks.
                $spans[] = [$pos->startLine, $pos->endLine, true];
            } elseif (
                $pos !== null
                && ($child instanceof Code || $child instanceof Math || $child instanceof RawInline || $child instanceof LiteralInline)
            ) {
                $spans[] = [$pos->startLine, $pos->endLine, false];
            }
            $this->collectVerbatimSpans($child, $spans, $depth + 1);
        }
    }

    /**
     * Byte length of the quote markers the term's own line carries, plus the indent.
     *
     * @param string $line
     * @param int $quotes
     */
    private function peel(string $line, int $quotes): ?int
    {
        $chars = 0;
        for ($q = 0; $q < $quotes; $q++) {
            if (!preg_match('/^[ \t]*>(?: |$)/', substr($line, $chars), $m)) {
                return null;
            }
            $chars += strlen($m[0]);
        }
        $len = strlen($line);
        while ($chars < $len && ($line[$chars] === ' ' || $line[$chars] === "\t")) {
            $chars++;
        }

        return $chars;
    }

    /**
     * @param string $line
     * @param int $end Byte offset.
     */
    private function visualColumn(string $line, int $end): int
    {
        $column = 0;
        // A leading BOM counts in positions but has no width.
        foreach (mb_str_split(substr($line, 0, $end), 1, 'UTF-8') as $char) {
            if ($char === "\u{FEFF}") {
                continue;
            }
            $column = $char === "\t" ? intdiv($column, 4) * 4 + 4 : $column + 1;
        }

        return $column;
    }
}
