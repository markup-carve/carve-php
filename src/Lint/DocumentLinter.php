<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

final class DocumentLinter
{
    /**
     * @param string $source
     * @param array{platforms?: list<string>, extensions?: list<\MarkupCarve\Carve\Extension\ExtensionInterface|string>} $options
     *
     * @return list<\MarkupCarve\Carve\Lint\LintWarning>
     */
    public function lint(string $source, array $options = []): array
    {
        $warnings = array_merge(
            (new MarkdownHabitLinter())->lint($source, ['platforms' => $options['platforms'] ?? []]),
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

        return $warnings;
    }
}
