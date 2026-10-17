<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Ast\SourceSpan;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\Figure;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\DelimitedTable;

final class DelimitedTableLinter
{
    /** @return list<LintWarning> */
    public function lint(string $source): array
    {
        $converter = new CarveConverter();
        $converter->getParser()->enablePositionTracking();
        $document = $converter->parse($source);
        $map = SourceOffsets::map($source);
        $length = strlen($source);
        $warnings = [];
        $pending = [$document];
        while ($pending !== []) {
            $node = array_pop($pending);
            if ($node instanceof CodeBlock && $node->getAttribute('table') === '') {
                $this->malformed($node, $node, $warnings, $map, $length);
            } elseif ($node instanceof Figure && $node->getAttribute('table') === '') {
                foreach ($node->getTargets() as $target) {
                    if ($target instanceof CodeBlock) {
                        $this->malformed($target, $node, $warnings, $map, $length);
                    }
                }
            } elseif ($node instanceof Table && $node->getSourceFormat() !== null) {
                $this->wide($node, $warnings, $map, $length);
            }
            foreach ($node->getChildren() as $child) {
                $pending[] = $child;
            }
        }
        return $warnings;
    }

    /** @param list<LintWarning> $warnings @param array<int, int>|null $map */
    private function malformed(CodeBlock $code, Node $owner, array &$warnings, ?array $map, int $length): void
    {
        $format = $code->getLanguage();
        if ($format !== 'csv' && $format !== 'tsv') {
            return;
        }
        if (DelimitedTable::rows($code->getContent(), $format === 'csv' ? ',' : "\t") !== null) {
            return;
        }
        $this->warn($code->getPos() ?? $owner->getPos(), 'delimited-table-malformed', 'This marked CSV/TSV fence has malformed quoting, so it remains a code block.', $warnings, $map, $length);
    }

    /** @param list<LintWarning> $warnings @param array<int, int>|null $map */
    private function wide(Table $table, array &$warnings, ?array $map, int $length): void
    {
        $pos = $table->getPos();
        if ($pos === null) {
            return;
        }
        $widths = $table->getSourceRecordWidths();
        if ($widths === null || $widths === []) {
            return;
        }
        $width = $widths[0];
        foreach ($widths as $rowWidth) {
            if ($rowWidth > $width) {
                $this->warn($pos, 'delimited-table-wide-row', 'A CSV/TSV record has more fields than the first record; the table keeps the extra columns.', $warnings, $map, $length);
                return;
            }
        }
    }

    /** @param list<LintWarning> $warnings @param array<int, int>|null $map */
    private function warn(?SourceSpan $pos, string $rule, string $message, array &$warnings, ?array $map, int $length): void
    {
        if ($pos === null) {
            return;
        }
        $warnings[] = new LintWarning($pos->startLine, $pos->startColumn, $rule, $message,
            SourceOffsets::toByte($pos->startOffset, $map, $length),
            SourceOffsets::toByte($pos->endOffset, $map, $length));
    }
}
