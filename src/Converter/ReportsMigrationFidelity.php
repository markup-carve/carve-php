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
            return new MigrationDiagnostic(
                $diagnostic->code,
                $diagnostic->message,
                $diagnostic->severity,
                $diagnostic->fidelity,
                $diagnostic->confidence,
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
