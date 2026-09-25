<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

trait ReportsMigrationFidelity
{
    /**
     * A checkbox read on an ordered list item, kept as the item's bracket text.
     *
     * One copy for every entry point that reaches the loss: it is one loss with
     * one cause, so a consumer filtering on the message should not have to know
     * which importer ran. The grammar clause behind it - `task_marker` hangs off
     * `unordered_item` alone - stays in the import contract's prose rather than
     * in the row (carve-js#2062).
     *
     * @var string
     */
    public const ORDERED_TASK_ITEM_UNSPELLABLE = 'An ordered task item is not spellable as a Carve task item; '
        . 'the checkbox marker was kept as text';

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
