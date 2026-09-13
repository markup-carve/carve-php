<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

trait ReportsMigrationFidelity
{
    protected function unverifiedMigrationResult(string $value, string $format): MigrationResult
    {
        $diagnostics = [
            new MigrationDiagnostic(
                'fidelity-unverified',
                'The ' . $format . ' importer does not yet provide construct-level fidelity evidence',
                'warning',
                'dropped',
                'fallback',
            ),
        ];

        return new MigrationResult($value, $format, $diagnostics);
    }

    protected function htmlMigrationResult(HtmlImportResult $result): MigrationResult
    {
        $diagnostics = array_map(static function (HtmlImportDiagnostic $diagnostic): MigrationDiagnostic {
            $fidelity = match ($diagnostic->code) {
                'element-dropped', 'attribute-dropped', 'structure-unspellable' => 'dropped',
                'element-unwrapped' => 'degraded',
                'style-unmapped', 'table-degraded', 'encoding-assumed', 'diagnostics-truncated' => 'degraded',
                'attribute-preserved', 'raw-preserved' => 'preserved',
                default => 'dropped',
            };

            return new MigrationDiagnostic(
                $diagnostic->code,
                $diagnostic->message,
                $diagnostic->severity,
                $fidelity,
                match ($diagnostic->code) {
                    'encoding-assumed' => 'inferred',
                    'diagnostics-truncated' => 'fallback',
                    'element-dropped', 'attribute-dropped', 'structure-unspellable',
                    'element-unwrapped', 'style-unmapped', 'table-degraded',
                    'attribute-preserved', 'raw-preserved' => 'exact',
                    default => 'fallback',
                },
                $diagnostic->path,
            );
        }, $result->diagnostics);

        return new MigrationResult(
            $result->value,
            'html',
            $diagnostics,
            $result->mode,
            $result->adapter,
        );
    }
}
