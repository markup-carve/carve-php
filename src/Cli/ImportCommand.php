<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Cli;

use MarkupCarve\Carve\Diagnostics\Diagnostic;
use MarkupCarve\Carve\Import\HtmlImporter;
use MarkupCarve\Carve\Import\ImportInputError;
use MarkupCarve\Carve\Json\TreeError;

/**
 * `carve import --from=html [--raw-html] [FILE]`, as the `import` command of the carve-js 0.2 command line: the canonical
 * source on standard output, each diagnostic as `carve[CODE]: MESSAGE` on standard error. Exit 0, 1 when the importer
 * built a tree the reader refuses or the writer reports a loss, 2 on usage or input errors (C2-M-006, C2-W-101). This
 * engine imports HTML only. Until a writer is wired the tree is written as JSON in place of the source.
 */
final class ImportCommand
{
    /**
     * C2-W-117: the writer codes that refuse the output.
     *
     * @var array<string, true>
     */
    private const WRITER_LOSSES = ['writer-refused' => true, 'structure-unspellable' => true, 'field-unspellable' => true];

    /**
     * @param array<string> $args The arguments after `import`.
     * @param resource $in
     * @param resource $out
     * @param resource $err
     *
     * @return int
     */
    public static function main(array $args, $in, $out, $err): int
    {
        [$from, $raw, $files] = self::parse($args);
        if ($from === null || count($files) > 1) {
            return self::fail($err, 'usage', 'import requires --from=markdown|gfm|djot|html and at most one file', 2);
        }
        if ($from !== 'html') {
            return self::fail($err, 'usage', 'this engine imports --from=html only', 2);
        }
        $file = $files[0] ?? '-';
        $html = $file === '-' ? stream_get_contents($in) : @file_get_contents($file);
        $display = $file === '-' ? '<stdin>' : $file;
        if ($html === false) {
            return self::fail($err, 'input-unreadable', $display . ': cannot be read', 2);
        }
        try {
            $result = HtmlImporter::import($html, ['rawHtml' => $raw]);
        } catch (ImportInputError $e) {
            return self::fail($err, 'import-input-invalid', $display . ': ' . $e->getMessage(), 2);
        } catch (TreeError $e) {
            // An engine defect: the importer built a tree the reader refuses (C2-M-001).
            return self::fail($err, 'import-tree-invalid', $display . ': ' . $e->getMessage(), 1);
        }
        $losses = array_filter($result->diagnostics, static fn (Diagnostic $d): bool => isset(self::WRITER_LOSSES[$d->code]));
        if ($losses !== []) {
            $named = array_map(static fn (Diagnostic $d): string => $d->code . ' at ' . ($d->path ?: '/'), $losses);

            return self::fail($err, 'writer-refused', $display . ': ' . implode(', ', $named), 1);
        }
        foreach ($result->diagnostics as $entry) {
            fwrite($err, 'carve[' . $entry->code . ']: ' . $entry->message . "\n");
        }
        fwrite($out, $result->source);

        return 0;
    }

    /**
     * @param array<string> $args
     *
     * @return array{0: string|null, 1: bool, 2: array<string>}
     */
    private static function parse(array $args): array
    {
        $from = null;
        $raw = false;
        $files = [];
        $count = count($args);
        for ($i = 0; $i < $count; $i++) {
            $arg = $args[$i];
            if (str_starts_with($arg, '--from=')) {
                $from = substr($arg, 7);
            } elseif ($arg === '--from') {
                $from = $args[++$i] ?? '';
            } elseif ($arg === '--raw-html') {
                $raw = true;
            } elseif ($arg === '-' || !str_starts_with($arg, '-')) {
                $files[] = $arg;
            } else {
                return [null, false, []];
            }
        }

        return [$from, $raw, $files];
    }

    /**
     * @param resource $err
     * @param string $category
     * @param string $message
     * @param int $status
     *
     * @return int
     */
    private static function fail($err, string $category, string $message, int $status): int
    {
        fwrite($err, 'carve[' . $category . ']: ' . $message . "\n");

        return $status;
    }
}
