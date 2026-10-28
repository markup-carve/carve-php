<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Comment;

final class DocumentLinter
{
    /**
     * @param string $source
     * @param array{platforms?: list<string>, extensions?: list<\MarkupCarve\Carve\Extension\ExtensionInterface|string>, captionLookingParagraph?: bool} $options
     *
     * @return list<\MarkupCarve\Carve\Lint\LintWarning>
     */
    public function lint(string $source, array $options = []): array
    {
        $warnings = array_merge(
            (new MarkdownHabitLinter())->lint($source, ['platforms' => $options['platforms'] ?? []]),
            (new AuthoringLinter())->lint($source, $options['captionLookingParagraph'] ?? false),
            (new SemanticAttributeLinter())->lint($source),
            (new ReferenceLinter())->lint($source, ['extensions' => $options['extensions'] ?? []]),
            (new SourceLinter())->lint($source),
            (new ListColumnLinter())->lint($source),
            (new DescriptionSeparatorBelowContentColumnLinter())->lint($source),
            (new RetiredSpellingLinter())->lint($source),
            (new TableColumnLinter())->lint($source),
            (new DelimitedTableLinter())->lint($source),
            (new ConsumedKeyLinter())->lint($source),
            (new FenceAndContinuationLinter())->lint($source),
            (new TemplateSourceLinter())->lint($source),
            (new FigureGroupLinter())->lint($source),
            (new QuoteFenceLinter())->lint($source),
            (new DefinitionTermFoldLinter())->lint($source),
            (new ReferencesPlacementLinter())->lint($source, ['extensions' => $options['extensions'] ?? []]),
        );
        usort($warnings, static fn (LintWarning $a, LintWarning $b): int => [$a->line, $a->column] <=> [$b->line, $b->column]);

        return $this->applySuppressions($source, $warnings);
    }

    /**
     * @param string $source
     * @param list<\MarkupCarve\Carve\Lint\LintWarning> $warnings
     *
     * @return list<\MarkupCarve\Carve\Lint\LintWarning>
     */
    private function applySuppressions(string $source, array $warnings): array
    {
        $converter = new CarveConverter();
        $converter->getParser()->enablePositionTracking();
        $pending = [$converter->parse($source)];
        $directives = [];
        $map = SourceOffsets::map($source);
        $length = strlen($source);
        while ($pending !== []) {
            $node = array_pop($pending);
            if (
                $node instanceof Comment && $node->getFenceLength() === null && $node->getPos() !== null
                && substr($source, SourceOffsets::toByte($node->getPos()->startOffset, $map, $length), 3) === '%% '
                && preg_match('/^carve-lint-(disable-next-line|disable-line|disable|enable)(?:[ \t]+(.*))?$/', $node->getContent(), $match)
            ) {
                $rules = preg_split('/[ ,]+/', trim($match[2] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $rules = array_map(static fn (string $rule): string => $rule === 'a11y/heading-jump' ? 'heading-level-skip' : $rule, $rules);
                $directives[] = [$node->getPos()->startLine, $match[1], $rules];
            }
            foreach ($node->getChildren() as $child) {
                $pending[] = $child;
            }
        }
        usort($directives, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $lineRules = [];
        $all = false;
        $scoped = [];
        $scopes = [];
        foreach ($directives as [$line, $action, $rules]) {
            if ($action === 'disable-line' || $action === 'disable-next-line') {
                $target = $line + (int)($action === 'disable-next-line');
                foreach ($rules === [] ? ['*'] : $rules as $rule) {
                    $lineRules[$target][$rule] = true;
                }

                continue;
            }
            if ($rules === []) {
                $all = $action === 'disable';
                $scoped = [];
            } else {
                foreach ($rules as $rule) {
                    $scoped[$rule] = $action === 'disable';
                }
            }
            $scopes[$line] = [$all, $scoped];
        }
        $current = [false, []];
        $scopeLines = array_keys($scopes);
        $next = 0;

        return array_values(array_filter($warnings, static function (LintWarning $warning) use (&$current, &$next, $scopeLines, $scopes, $lineRules): bool {
            while (isset($scopeLines[$next]) && $scopeLines[$next] <= $warning->line) {
                $current = $scopes[$scopeLines[$next++]];
            }

            return !isset($lineRules[$warning->line]['*'])
                && !isset($lineRules[$warning->line][$warning->rule])
                && !($current[1][$warning->rule] ?? $current[0]);
        }));
    }
}
