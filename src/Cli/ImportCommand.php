<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Cli;

use MarkupCarve\Carve\Diagnostics\Diagnostic;
use MarkupCarve\Carve\Import\HtmlImporter;
use MarkupCarve\Carve\Import\HubImporter;
use MarkupCarve\Carve\Import\ImportInputError;
use MarkupCarve\Carve\Import\ImportResult;
use MarkupCarve\Carve\Json\TreeError;
use MarkupCarve\Carve\OptionError;
use MarkupCarve\Carve\Writer\Writer;
use RuntimeException;

/**
 * `carve import --from=html [--raw-html] [FILE]`, as the `import` command of the carve-js 0.2 command line: the canonical
 * source on standard output, each diagnostic as `carve[CODE]: MESSAGE` on standard error. Exit 0, 1 when the writer
 * reports a loss, 2 on usage or input errors (C2-M-006, C2-W-101). Unlike the library, the command line does not read
 * the imported tree back (carve-php#5965).
 * Markdown, GFM and Djot go through the HTML hub; `--raw-html` applies to HTML only.
 */
final class ImportCommand
{
    /**
     * The switches of `import` and the option each turns on.
     *
     * @var array<string, string>
     */
    private const SWITCHES = ['--raw-html' => 'rawHtml', '--strict-losses' => 'strictLosses', '--list-table' => 'listTable'];

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
        try {
            [$from, $options, $files, $output, $directory, $report] = self::parse($args);
            self::validatePaths($output, $report);
        } catch (OptionError $e) {
            return self::fail($err, 'usage', $e->getMessage(), 2);
        }
        if ($from === null || count($files) > 1) {
            return self::fail($err, 'usage', 'import requires --from=markdown|gfm|djot|html and at most one file', 2);
        }
        if ($from !== 'html' && !in_array($from, HubImporter::FORMATS, true)) {
            return self::fail($err, 'usage', 'import requires --from=markdown|gfm|djot|html and at most one file', 2);
        }
        if ($directory !== null) {
            if (
                $from !== 'gfm' || $output === null || $files !== []
                || $options !== ['rawHtml' => false]
            ) {
                return self::fail($err, 'usage', 'directory import requires --from gfm --directory SOURCE --output TARGET', 2);
            }
            try {
                $counts = DirectoryImport::run($directory, $output);
            } catch (RuntimeException $e) {
                return self::fail($err, 'input-invalid', $e->getMessage(), 2);
            }
            fwrite(
                $out,
                $counts['files'] . ' file(s) converted; ' . $counts['assets'] . ' asset(s) copied; '
                    . $counts['review'] . ' file(s) need review; ' . $counts['skipped'] . ' entry(s) skipped. Report: '
                    . $counts['report'] . "\n",
            );

            return 0;
        }
        $file = $files[0] ?? '-';
        $html = $file === '-' ? stream_get_contents($in) : @file_get_contents($file);
        $display = $file === '-' ? '<stdin>' : $file;
        if ($html === false) {
            return self::fail($err, 'input-unreadable', $display . ': cannot be read', 2);
        }
        try {
            // carve-php#5965: the command line writes only the source, so it skips the tree check the library keeps.
            $result = $from === 'html'
                ? HtmlImporter::import($html, $options, checksTree: false)
                : HubImporter::import($html, $from, $options, checksTree: false);
        } catch (OptionError $e) {
            return self::fail($err, 'usage', $e->getMessage(), 2);
        } catch (ImportInputError $e) {
            return self::fail($err, 'import-input-invalid', $display . ': ' . $e->getMessage(), 2);
        } catch (TreeError $e) {
            // An engine defect: the importer built a tree the reader refuses (C2-M-001).
            return self::fail($err, 'import-tree-invalid', $display . ': ' . $e->getMessage(), 1);
        }

        return self::finish($result, $options, $display, [$from, $html], $output, $report, $out, $err);
    }

    private static function validatePaths(?string $output, ?string $report): void
    {
        if ($output === null || $report === null || $output === '-' || $report === '-') {
            return;
        }
        $outputParent = realpath(dirname($output));
        $reportParent = realpath(dirname($report));
        if (
            $outputParent !== false && $reportParent !== false
            && $outputParent . '/' . basename($output) === $reportParent . '/' . basename($report)
        ) {
            throw new OptionError('--output shares a path with --report-losses');
        }
    }

    /**
     * @param \MarkupCarve\Carve\Import\ImportResult $result
     * @param array<string, mixed> $options
     * @param string $display
     * @param array{string, string} $input The format and the input, for the line and column of an entry with a range.
     * @param string|null $output
     * @param string|null $report
     * @param resource $out
     * @param resource $err
     *
     * @return int
     */
    private static function finish(
        ImportResult $result,
        array $options,
        string $display,
        array $input,
        ?string $output,
        ?string $report,
        $out,
        $err,
    ): int {
        $losses = array_filter($result->diagnostics, static fn (Diagnostic $d): bool => isset(Writer::REFUSING_CODES[$d->code]));
        if (($options['strictLosses'] ?? false) && $result->hasUnallowedLoss) {
            self::fail($err, 'render-loss', $display . ': import reported a loss', 1);
            self::writeDiagnostics($result, $display, $input, $err);

            return max(1, self::writeReport($result, $display, $input, $report, $err));
        }
        if ($losses !== [] || $result->hasRefusingLoss) {
            $named = array_map(static fn (Diagnostic $d): string => $d->code . ' at ' . ($d->path ?: '/'), $losses);
            $reason = $named === [] ? 'a writer loss was suppressed' : implode(', ', $named);
            self::fail($err, 'writer-refused', $display . ': ' . $reason, 1);

            return max(1, self::writeReport($result, $display, $input, $report, $err));
        }
        // C2-T-019: a diagnostic line, so `carve[...]:` stays the failure line of C2-T-039.
        self::writeDiagnostics($result, $display, $input, $err);
        $status = self::writeProduct($result->source, $output, $out, $err);

        return max($status, self::writeReport($result, $display, $input, $report, $err));
    }

    /**
     * @param \MarkupCarve\Carve\Import\ImportResult $result
     * @param string $display
     * @param array{string, string} $input
     * @param string|null $report
     * @param resource $err
     */
    private static function writeReport(ImportResult $result, string $display, array $input, ?string $report, $err): int
    {
        if ($report === null) {
            return 0;
        }
        $entries = [];
        $position = ['offset' => 0, 'line' => 1, 'column' => 1];
        foreach ($result->diagnostics as $entry) {
            $data = $entry->toArray();
            if ($input[0] === 'html' && $entry->startOffset >= 0) {
                [$data['line'], $data['column']] = Command::location($input[1], $entry->startOffset, $position);
            }
            $entries[] = $data;
        }
        $record = ['files' => [['file' => $display, 'diagnostics' => $entries, 'suppressed' => $result->suppressedDiagnostics]]];
        $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        if ($report === '-') {
            fwrite($err, $json);
        } elseif (@file_put_contents($report, $json) === false) {
            return self::fail($err, 'output-unwritable', $report . ': cannot be written', 2);
        }

        return 0;
    }

    /**
     * @param \MarkupCarve\Carve\Import\ImportResult $result
     * @param string $display
     * @param array{string, string} $input
     * @param resource $err
     */
    private static function writeDiagnostics(ImportResult $result, string $display, array $input, $err): void
    {
        $position = ['offset' => 0, 'line' => 1, 'column' => 1];
        // The hub's offsets point into the HTML it built, not into the input.
        $html = $input[0] === 'html' ? $input[1] : null;
        foreach ($result->diagnostics as $entry) {
            $where = $display;
            if ($html !== null && $entry->startOffset >= 0) {
                [$line, $column] = Command::location($html, $entry->startOffset, $position);
                $where .= ':' . $line . ':' . $column;
            }
            fwrite($err, $where . ': ' . $entry->severity . ' ' . $entry->code . ' ' . $entry->message . "\n");
        }
    }

    /**
     * @param string $source
     * @param string|null $path
     * @param resource $out
     * @param resource $err
     */
    private static function writeProduct(string $source, ?string $path, $out, $err): int
    {
        if ($path === null) {
            fwrite($out, $source);

            return 0;
        }
        if (@file_put_contents($path, $source) === false) {
            return self::fail($err, 'output-unwritable', $path . ': cannot be written', 2);
        }

        return 0;
    }

    // phpcs:disable Generic.Metrics.CyclomaticComplexity.MaxExceeded -- each import flag has one parsing branch.

    /**
     * @param array<string> $args
     *
     * @throws \MarkupCarve\Carve\OptionError
     *
     * @return array{0: string|null, 1: array<string, mixed>, 2: array<string>, 3: string|null, 4: string|null, 5: string|null}
     */
    private static function parse(array $args): array
    {
        $from = null;
        $options = ['rawHtml' => false];
        $files = [];
        $output = null;
        $directory = null;
        $report = null;
        $count = count($args);
        for ($i = 0; $i < $count; $i++) {
            $arg = $args[$i];
            $match = [];
            if (preg_match('/^--(from|adapter|allow-loss|max-render-losses)(?:=(.*))?$/s', $arg, $m) === 1) {
                $from = self::valueFlag($m[1], $m[2] ?? ($args[++$i] ?? null), $from, $options);
            } elseif (isset(self::SWITCHES[$arg])) {
                $options[self::SWITCHES[$arg]] = true;
            } elseif ($arg === '--no-list-table') {
                unset($options['listTable']);
            } elseif ($arg === '--no-raw-html' || $arg === '--no-strict-losses') {
                $options[$arg === '--no-raw-html' ? 'rawHtml' : 'strictLosses'] = false;
            } elseif (preg_match('/^--report-losses(?:=(.*))?$/s', $arg, $match) === 1) {
                $report = $match[1] ?? ($args[++$i] ?? null);
                if ($report === null) {
                    throw new OptionError('missing value for --report-losses');
                }
            } elseif ($arg === '-o' || preg_match('/^--(output|directory)(?:=(.*))?$/s', $arg, $match) === 1) {
                $name = $arg === '-o' ? 'output' : ($match[1] ?? 'output');
                $value = $match[2] ?? ($args[++$i] ?? null);
                if ($value === null) {
                    throw new OptionError('missing value for --' . $name);
                }
                if ($name === 'directory') {
                    $directory = $value;
                } else {
                    $output = $value;
                }
            } elseif ($arg === '-' || !str_starts_with($arg, '-')) {
                $files[] = $arg;
            } else {
                return [null, [], [], null, null, null];
            }
        }

        return [$from, $options, $files, $output, $directory, $report];
    }

    // phpcs:enable Generic.Metrics.CyclomaticComplexity.MaxExceeded

    /**
     * A flag with a value: `--from`, `--adapter` (C2-M-102), and `--allow-loss` and `--max-render-losses`, which the
     * importer reads as allowLosses and maxLosses (C2-T-027, C2-T-029, C2-H-204, C2-H-205).
     *
     * @param string $name
     * @param string|null $value
     * @param string|null $from
     * @param array<string, mixed> $options
     *
     * @throws \MarkupCarve\Carve\OptionError ! From `Command::optionFlag()`.
     *
     * @return string|null The input format.
     */
    private static function valueFlag(string $name, ?string $value, ?string $from, array &$options): ?string
    {
        if ($name === 'from') {
            return $value ?? '';
        }
        if ($name === 'adapter') {
            $options['adapter'] = $value ?? '';
        } else {
            Command::optionFlag($name, $value, $options);
        }

        return $from;
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
