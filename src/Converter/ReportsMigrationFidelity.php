<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

trait ReportsMigrationFidelity
{
    protected function normalizedMigrationResult(string $source, string $value, string $format): MigrationResult
    {
        $diagnostics = $source === $value ? [] : [
            new MigrationDiagnostic(
                'syntax-normalized',
                'Converted ' . $format . ' syntax to canonical Carve source',
                'info',
                'normalized',
            ),
        ];

        return new MigrationResult($value, $format, $diagnostics);
    }

    protected function htmlMigrationResult(HtmlImportResult $result): MigrationResult
    {
        $diagnostics = array_map(static function (HtmlImportDiagnostic $diagnostic): MigrationDiagnostic {
            $fidelity = match ($diagnostic->code) {
                'element-dropped', 'attribute-dropped', 'structure-unspellable' => 'dropped',
                'element-unwrapped' => 'normalized',
                'style-unmapped', 'table-degraded', 'encoding-assumed', 'diagnostics-truncated' => 'degraded',
                default => 'preserved',
            };

            return new MigrationDiagnostic(
                $diagnostic->code,
                $diagnostic->message,
                $diagnostic->severity,
                $fidelity,
                $diagnostic->code === 'encoding-assumed' ? 'inferred' : 'exact',
                $diagnostic->path,
            );
        }, $result->diagnostics);

        return new MigrationResult($result->value, 'html', $diagnostics);
    }
}
