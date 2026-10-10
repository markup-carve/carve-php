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

    /**
     * A raw span whose content ends a line in whitespace, which CARVE-P2-025
     * drops from every content line - a verbatim run crossing a line break
     * included (markup-carve/carve#2804).
     *
     * Reported rather than respelled: the shape is inline content inside a
     * paragraph, so writing a raw block to keep the bytes would change the
     * block structure into a different document.
     *
     * @var string
     */
    public const RAW_SPAN_WHITESPACE_TRIMMED = 'A raw span ends a content line in whitespace, which Carve drops; '
        . 'the whitespace did not reach the converted source';

    /**
     * Whitespace a decoded character reference put at the start of a line,
     * which Carve has no spelling for there.
     *
     * Dropped rather than substituted: `\ ` reads back as U+00A0, and a
     * non-breaking space is not the tab or space the author wrote - it changes
     * line breaking and copies out of a browser as a different byte, so the
     * substitution travels further than the document (markup-carve/carve#2595).
     * The drop is deliberate and still a loss, so it gets a row of its own
     * rather than only the blanket `fidelity-unverified` (carve-php#3050).
     *
     * @var string
     */
    public const LEADING_WHITESPACE_UNSPELLABLE = 'Dropped whitespace a decoded reference put at the start of a line; '
        . 'Carve spells no leading whitespace on a paragraph';

    protected function assessedMigrationResult(
        string $source,
        string $value,
        string $format,
        bool $hasKnownLosses = false,
        bool $verifyLiteral = true,
    ): MigrationResult {
        $literal = rtrim(str_replace(["\r\n", "\r"], "\n", $source), "\n");
        if ($verifyLiteral && !$hasKnownLosses && ($literal === '' || preg_match('/\A[\p{L}\p{N}]+(?: [\p{L}\p{N}]+)*\z/u', $literal) === 1) && rtrim($value, "\n") === $literal) {
            return new MigrationResult($value, $format, [
                new MigrationDiagnostic(
                    'literal-text-verified',
                    'Verified the complete input as literal text.',
                    'info',
                    'preserved',
                    'exact',
                ),
            ]);
        }
        $diagnostics = [
            new MigrationDiagnostic(
                'fidelity-unverified',
                $format === 'markdown' ? 'Markdown construct assessment is incomplete.' : 'The ' . $format . ' importer does not yet provide construct-level fidelity evidence',
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
