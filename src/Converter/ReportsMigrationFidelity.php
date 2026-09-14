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
        // The DIAGNOSTIC answers both questions now, so this carries them rather
        // than deriving a second copy. The two used to be computed here alone,
        // which is why the plain import report - what the shared fixtures
        // compare - reported neither, and why the copies could disagree about a
        // code with nothing to catch it.
        $diagnostics = array_map(static fn (HtmlImportDiagnostic $diagnostic): MigrationDiagnostic => new MigrationDiagnostic(
            $diagnostic->code,
            $diagnostic->message,
            $diagnostic->severity,
            $diagnostic->fidelity(),
            $diagnostic->confidence(),
            $diagnostic->path,
        ), $result->diagnostics);

        return new MigrationResult(
            $result->value,
            'html',
            $diagnostics,
            $result->mode,
            $result->adapter,
        );
    }
}
