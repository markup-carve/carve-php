<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Parser\DelimitedTable;
use MarkupCarve\Carve\Transform\IncludeExpander;
use MarkupCarve\Carve\Transform\IncludeResolverInterface;

final class IncludeLinter
{
    /**
     * @param string $source
     * @param array{platforms?: list<string>, extensions?: list<\MarkupCarve\Carve\Extension\ExtensionInterface|string>} $options
     * @param string|null $sourcePath
     * @param \MarkupCarve\Carve\Transform\IncludeResolverInterface|null $resolver
     *
     * @return list<\MarkupCarve\Carve\Lint\LintWarning>
     */
    public function lint(string $source, array $options = [], ?IncludeResolverInterface $resolver = null, ?string $sourcePath = null): array
    {
        $linter = new DocumentLinter();
        $findings = $linter->lint($source, $options);
        if ($resolver === null) {
            return $findings;
        }
        $converter = new CarveConverter();
        $converter->getParser()->enablePositionTracking();
        $document = $converter->parse($source);
        $expander = new IncludeExpander($resolver, $sourcePath, source: $source);
        $expander->collectIncluded();
        $expander->transform($document);
        $seen = [];
        foreach ($expander->getIncluded() as $entry) {
            $file = $entry['file'];
            $chain = $entry['includedBy'];
            if ($entry['payload'] === null) {
                foreach ($linter->lint($entry['source'], $options) as $warning) {
                    $line = $warning->line + $entry['lineBase'];
                    $section = $entry['section'];
                    if ($section !== null && ($line < $section['start'] || $line > $section['end'])) {
                        continue;
                    }
                    $this->add($findings, $seen, new LintWarning($line, $warning->column, $warning->rule, $warning->message, $warning->start, $warning->end, $file, $chain));
                }

                continue;
            }
            $block = $entry['block'];
            if (!$block instanceof CodeBlock && !$block instanceof Table) {
                continue;
            }
            $format = $block instanceof Table ? $block->getSourceFormat() : ($block->getAttribute('table') === '' ? $block->getLanguage() : null);
            if ($format !== 'csv' && $format !== 'tsv') {
                continue;
            }
            $starts = [];
            $malformed = null;
            $rows = DelimitedTable::rows($entry['payload'], $format === 'csv' ? ',' : "\t", null, static function (int $start) use (&$starts): void {
                $starts[] = $start;
            }, static function (int $start) use (&$malformed): void {
                $malformed = $start;
            });
            $offset = null;
            $rule = null;
            $message = null;
            if ($malformed !== null) {
                $offset = $malformed;
                $rule = 'delimited-table-malformed';
                $message = 'This marked CSV/TSV record has malformed quoting, so the include remains a code block.';
            } elseif ($rows !== null && $rows !== []) {
                foreach ($rows as $index => $row) {
                    if (count($row) > count($rows[0])) {
                        $offset = $starts[$index];
                        $rule = 'delimited-table-wide-row';
                        $message = 'A CSV/TSV record has more fields than the first record; the table keeps the extra columns.';

                        break;
                    }
                }
            }
            if ($offset !== null) {
                $line = ($block->getPos() === null ? ($entry['lineBase'] + 1) : $block->getPos()->startLine) + substr_count(substr($entry['payload'], 0, $offset), "\n");
                $this->add($findings, $seen, new LintWarning($line, 1, $rule ?? '', $message ?? '', 0, 0, $file, $chain));
            }
        }
        foreach ($expander->getWarnings() as $warning) {
            if (in_array($warning->getRule(), ['include-id-rename', 'include-footnote-rename', 'include-heading-clamp'], true)) {
                continue;
            }
            $chain = $warning->getIncludedBy();
            $this->add($findings, $seen, new LintWarning($warning->getLine(), $warning->getColumn(), $warning->getRule() ?? 'include', $warning->getMessage(), 0, 0, $chain === null ? null : $warning->getFile(), $chain));
        }

        return $findings;
    }

    /**
     * @param list<\MarkupCarve\Carve\Lint\LintWarning> $findings
     * @param array<string, true> $seen
     * @param \MarkupCarve\Carve\Lint\LintWarning $warning
     */
    private function add(array &$findings, array &$seen, LintWarning $warning): void
    {
        if ($warning->includedBy !== null) {
            $key = implode("\0", [$warning->file, $warning->line, $warning->column, $warning->rule]);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
        }
        $findings[] = $warning;
    }
}
