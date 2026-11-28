<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Cli;

use JsonException;
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Diagnostics\Diagnostic;
use MarkupCarve\Carve\Export\AnsiExporter;
use MarkupCarve\Carve\Export\MarkdownExporter;
use MarkupCarve\Carve\Export\PlainTextExporter;
use MarkupCarve\Carve\Extension\Bundle;
use MarkupCarve\Carve\Extension\Citations;
use MarkupCarve\Carve\Extension\Standard;
use MarkupCarve\Carve\Include\FileResolver;
use MarkupCarve\Carve\InputError as CoreInputError;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeError;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\OptionError;
use MarkupCarve\Carve\Options;
use MarkupCarve\Carve\Utf8;
use MarkupCarve\Carve\Writer\CarveWriteError;
use stdClass;

/**
 * The `carve` command: `render` (the default), `ast`, `diagnostics`, `lint`, `fmt` and `import` (`ImportCommand`),
 * with the option names, output and exit codes of the carve-js 0.2 command line.
 */
final class Command
{
    /**
     * @var array<string, true>
     */
    private const COMMANDS = [
        'render' => true,
        'ast' => true,
        'diagnostics' => true,
        'lint' => true,
        'fix' => true,
        'flatten' => true,
        'fmt' => true,
        'format' => true,
        'migrate-ast' => true,
    ];

    /**
     * @var array<string, true>
     */
    private const VALUED = [
        'include-root' => true,
        'rules' => true,
        'platforms' => true,
        'to' => true,
        'heading-ids' => true,
        'ignore' => true,
        'output' => true,
        'profile' => true,
        'url-policy' => true,
        'mode' => true,
        'labels' => true,
        'social' => true,
        'symbols' => true,
        'report-losses' => true,
        'report-includes' => true,
        'extensions' => true,
        'extension-options' => true,
    ];

    /**
     * The commands that take `--extensions` and `--extension-options` (C2-T-010); `fix` is `lint --fix`.
     *
     * @var array<string>
     */
    private const EXTENSION_COMMANDS = ['render', 'ast', 'fmt', 'lint', 'fix'];

    /**
     * The output formats of `render --to`.
     *
     * @var array<string>
     */
    private const RENDER_TARGETS = ['html', 'markdown', 'plain', 'ansi'];

    /**
     * PHP options absent from the shared CLI catalog.
     *
     * @var array<string, list<string>>
     */
    private const ENGINE_HELP_FLAGS = [
        'render' => ['to', 'xhtml'],
        'import' => ['directory'],
    ];

    /**
     * Flags that set one option: `softBreak` and the count `headingShift` (C2-T-010), `allowLosses` one code per instance
     * (C2-T-027) and `maxLosses` (C2-T-029).
     *
     * @var array<string, string>
     */
    private const OPTION_FLAGS = [
        'soft-break' => 'softBreak',
        'heading-shift' => 'headingShift',
        'allow-loss' => 'allowLosses',
        'max-render-losses' => 'maxLosses',
    ];

    /**
     * @var array<string, bool>
     */
    private const FLAGS = [
        'json' => true,
        'no-json' => false,
        'tree' => true,
        'includes' => true,
        'no-includes' => false,
        'strict' => true,
        'strict-losses' => true,
        'envelope' => true,
        'fix' => true,
        'from-ast' => true,
        'positions' => true,
        'no-positions' => false,
        'raw-html' => true,
        'roundtrip' => true,
        'sections' => true,
        'smart-typography' => true,
        'source-lines' => true,
        'xhtml' => true,
        'upgrade' => true,
    ];

    /**
     * @var array<string, true>
     */
    private const FMT_FLAGS = [
        'check' => true,
        'diff' => true,
        'normalize' => true,
        'align-tables' => true,
        'write' => true,
        'from-ast' => true,
    ];

    // phpcs:disable Generic.Metrics.CyclomaticComplexity.MaxExceeded -- command dispatch follows the CLI table.

    /**
     * @param array<string> $args
     * @param resource $in
     * @param resource $out
     * @param resource $err
     *
     * @return int 0 done, 1 a lint finding, a file `fmt --check` would change or a write loss, 2 a usage or input error.
     */
    public static function main(array $args, $in, $out, $err): int
    {
        if (in_array('--version', $args, true)) {
            fwrite($out, 'carve-php ' . Carve::SPEC_VERSION['version'] . "\n");

            return 0;
        }
        if (in_array('--help', $args, true)) {
            if (self::commandHelp($args[0] ?? null, $out)) {
                return 0;
            }
            $help = <<<'HELP'
Carve 0.2 command line

Usage: carve [command] [flags] [file ...]
       carve import --from FORMAT [flags] [file]

Commands: render (default), ast, diagnostics, lint, fix, flatten, fmt, import, migrate-ast
Input: a file path or - for standard input; no file reads standard input.

Every command: --help, --version; every switch --NAME also has --no-NAME
Render: --to html|markdown|plain|ansi, --heading-ids MODE, --heading-shift N,
  --labels JSON, --mode interactive|static, --profile PROFILE,
  --raw-html, --roundtrip, --sections, --smart-typography, --xhtml (-x),
  --social JSON, --soft-break newline|space|br, --source-lines,
  --symbols JSON, --url-policy JSON, --strict-losses,
  --allow-loss CODE, --max-render-losses N, --report-losses FILE
Render, AST, lint and fmt: --extensions JSON (standard extension names),
  --extension-options JSON (their renderer options by name)
AST: --from-ast, --positions, --no-positions
Lint and fix: --rules JSON, --platforms JSON, --upgrade, --ignore FILE-ID,
  --strict, --json, --fix, --write
Fmt: --check, --diff, --write, --normalize, --align-tables, --from-ast
Import: --from markdown|gfm|djot|html, --raw-html, --list-table, --adapter NAME, --strict-losses
  --directory SOURCE --output TARGET (Markdown tree import)
Common file options: --output FILE (-o), --include-root DIR,
  --includes, --no-includes, --report-includes FILE

HELP;
            fwrite($out, $help);

            return 0;
        }
        if (($args[0] ?? null) === 'import') {
            return ImportCommand::main(array_slice($args, 1), $in, $out, $err);
        }
        try {
            $parsed = self::parse($args);
            $files = $parsed['files'] === [] ? ['-'] : $parsed['files'];
            self::validateCombination($parsed);
            if (($parsed['values']['write'] ?? false) && in_array('-', $files, true)) {
                throw new OptionError('cannot write standard input in place');
            }
            [$status, $records, $includeRecords] = self::runFiles($parsed, $files, $in, $out, $err);
            if ($parsed['values']['json'] ?? false) {
                $json = (string)json_encode(['files' => $records], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                // The carve-js command line indents by two spaces.
                $halve = static fn (array $m): string => substr($m[0], 0, intdiv(strlen($m[0]), 2));
                fwrite($out, (string)preg_replace_callback('/^ +/m', $halve, $json) . "\n");
            }
            self::writeReports($parsed, $records, $includeRecords, $err);

            return $status;
        } catch (OptionError $e) {
            fwrite($err, 'carve[usage]: ' . $e->getMessage() . "\n");

            return 2;
        } catch (InputError $e) {
            fwrite($err, 'carve[' . $e->category . ']: ' . $e->getMessage() . "\n");

            return 2;
        } catch (CoreInputError $e) {
            fwrite($err, 'carve[input-invalid]: ' . $e->getMessage() . "\n");

            return 2;
        } catch (CarveWriteError $e) {
            // C2-W-134
            fwrite($err, 'carve[writer-refused]: ' . $e->getMessage() . "\n");

            return 1;
        }
    }

    // phpcs:enable Generic.Metrics.CyclomaticComplexity.MaxExceeded

    /**
     * @param string|null $command
     * @param resource $out
     */
    private static function commandHelp(?string $command, $out): bool
    {
        if ($command !== 'import' && !isset(self::COMMANDS[$command ?? ''])) {
            return false;
        }
        $command = $command === 'format' ? 'fmt' : $command;
        $catalog = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/spec/data/cli.json'), true);
        $sources = self::flagSources(is_array($catalog) ? $catalog : [], $command);
        $flags = [];
        foreach (is_array($catalog) && is_array($catalog['flags'] ?? null) ? $catalog['flags'] : [] as $flag) {
            if (
                is_array($flag) && is_string($flag['name'] ?? null) && is_array($flag['commands'] ?? null)
                && self::hasFlagSource($sources, $flag['commands'])
            ) {
                $flags[] = '--' . $flag['name'];
            }
        }
        foreach (self::ENGINE_HELP_FLAGS[$command] ?? [] as $flag) {
            $flags[] = '--' . $flag;
        }
        $flags = array_values(array_unique($flags));
        sort($flags);
        fwrite($out, 'Usage: carve ' . $command . " [flags] [file ...]\nFlags: " . implode(', ', $flags) . "\n");

        return true;
    }

    /**
     * @param array<string, mixed> $catalog
     * @param string $command
     *
     * @return array<string>
     */
    private static function flagSources(array $catalog, string $command): array
    {
        $sources = [$command];
        foreach (is_array($catalog['commands'] ?? null) ? $catalog['commands'] : [] as $entry) {
            if (is_array($entry) && ($entry['name'] ?? null) === $command && is_string($entry['flags_of'] ?? null)) {
                $sources[] = $entry['flags_of'];
            }
        }

        return $sources;
    }

    /**
     * @param array<string> $sources
     * @param array<mixed> $commands
     */
    private static function hasFlagSource(array $sources, array $commands): bool
    {
        foreach ($sources as $source) {
            if (in_array($source, $commands, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{command: string, files: array<string>, values: array<string, mixed>, options: array<string, mixed>} $parsed
     * @param array<array<string, mixed>> $records
     * @param array<array<string, mixed>> $includeRecords
     * @param resource $err
     *
     * @throws \MarkupCarve\Carve\Cli\InputError
     */
    private static function writeReports(array $parsed, array $records, array $includeRecords, $err): void
    {
        foreach (['report-losses', 'report-includes'] as $flag) {
            $path = $parsed['values'][$flag] ?? null;
            if (!is_string($path)) {
                continue;
            }
            $entries = $flag === 'report-includes' ? $includeRecords : $records;
            $report = json_encode(['files' => $entries], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
            if ($path === '-') {
                fwrite($err, $report);
            } elseif (@file_put_contents($path, $report) === false) {
                throw new InputError($path . ': cannot be written', 'output-unwritable');
            }
        }
    }

    /**
     * @param array{command: string, files: array<string>, values: array<string, mixed>, options: array<string, mixed>} $parsed
     * @param array<string> $files
     * @param resource $in
     * @param resource $out
     * @param resource $err
     *
     * @throws \MarkupCarve\Carve\Cli\InputError
     *
     * @return array{int, array<array<string, mixed>>, array<array<string, mixed>>}
     */
    private static function runFiles(array $parsed, array $files, $in, $out, $err): array
    {
        $path = $parsed['values']['output'] ?? null;
        $target = is_string($path) ? @fopen($path, 'wb') : null;
        if ($target === false) {
            throw new InputError($path . ': cannot be written', 'output-unwritable');
        }
        $status = 0;
        $records = [];
        $includeRecords = [];
        try {
            foreach ($files as $file) {
                [$code, $record, $includeRecord] = self::one($parsed, $file, $in, $target ?? $out, $err);
                $status = max($status, $code);
                if ($record !== null) {
                    $records[] = $record;
                }
                if ($includeRecord !== null) {
                    $includeRecords[] = $includeRecord;
                }
            }
        } finally {
            if (is_resource($target)) {
                fclose($target);
            }
        }

        return [$status, $records, $includeRecords];
    }

    /**
     * @param array<string> $args
     *
     * @return array{command: string, files: array<string>, values: array<string, mixed>, options: array<string, mixed>}
     */
    private static function parse(array $args): array
    {
        $command = isset($args[0], self::COMMANDS[$args[0]]) ? ($args[0] === 'format' ? 'fmt' : $args[0]) : 'render';
        $parsed = ['command' => $command, 'files' => [], 'values' => [], 'options' => []];
        $count = count($args);
        for ($i = isset($args[0], self::COMMANDS[$args[0]]) ? 1 : 0; $i < $count; $i++) {
            $arg = $args[$i];
            if ($arg === '--') {
                array_push($parsed['files'], ...array_slice($args, $i + 1));

                break;
            }
            if ($arg === '-' || !str_starts_with($arg, '-')) {
                $parsed['files'][] = $arg;

                continue;
            }
            $i = self::option($args, $i, $parsed);
        }
        if ($parsed['values']['tree'] ?? false) {
            $parsed['command'] = 'ast';
        }
        self::extensionFlags($parsed);

        return $parsed;
    }

    /**
     * @param array<string> $args
     * @param int $i
     * @param array{command: string, files: array<string>, values: array<string, mixed>, options: array<string, mixed>} $parsed
     *
     * @throws \MarkupCarve\Carve\OptionError
     *
     * @return int The index of the last argument read.
     */
    private static function option(array $args, int $i, array &$parsed): int
    {
        $arg = $args[$i];
        if ($arg === '--option' || str_starts_with($arg, '--option=')) {
            self::namedOption($arg === '--option' ? ($args[$i + 1] ?? null) : substr($arg, 9), $parsed);

            return $arg === '--option' ? $i + 1 : $i;
        }
        $equals = strpos($arg, '=');
        $name = substr($arg, 2, $equals === false ? null : $equals - 2);
        // C2-T-008: `--no-name` turns the switch `--name` off.
        $negated = str_starts_with($name, 'no-') && !isset(self::FLAGS[$name]) && !str_starts_with($name, 'no-no-');
        if ($negated) {
            $name = substr($name, 3);
        }
        if (
            in_array($name, [
                'mode', 'labels', 'social', 'symbols', 'raw-html', 'roundtrip', 'sections', 'smart-typography', 'source-lines', 'xhtml',
            ], true) && $parsed['command'] !== 'render'
        ) {
            throw new OptionError('unknown option ' . $arg);
        }
        if (in_array($name, ['extensions', 'extension-options'], true) && !in_array($parsed['command'], self::EXTENSION_COMMANDS, true)) {
            throw new OptionError('unknown option ' . $arg);
        }
        if (isset(self::VALUED[$name]) || (isset(self::OPTION_FLAGS[$name]) && $parsed['command'] === 'render')) {
            if ($negated) {
                throw new OptionError('unknown option ' . $arg);
            }
            self::valued($name, $equals !== false ? substr($arg, $equals + 1) : ($args[$i + 1] ?? null), $parsed);

            return $equals !== false ? $i : $i + 1;
        }

        return self::switchOption($args, $i, $arg, $name, $equals, $parsed, !$negated);
    }

    /**
     * @param string|null $setting
     * @param array{command: string, files: array<string>, values: array<string, mixed>, options: array<string, mixed>} $parsed
     *
     * @throws \MarkupCarve\Carve\OptionError
     */
    private static function namedOption(?string $setting, array &$parsed): void
    {
        if ($setting === null || !str_contains($setting, '=')) {
            throw new OptionError('--option takes NAME=JSON');
        }
        [$key, $value] = explode('=', $setting, 2);
        if ($key === '') {
            throw new OptionError('--option takes NAME=JSON');
        }
        $parsed['options'][$key] = self::json('option', $value);
    }

    /**
     * @param array<string> $args
     * @param int|false $equals
     * @param string $name
     * @param string $arg
     * @param int $i
     * @param array{command: string, files: array<string>, values: array<string, mixed>, options: array<string, mixed>} $parsed
     * @param bool $on False for the `--no-` form.
     *
     * @throws \MarkupCarve\Carve\OptionError
     */
    private static function switchOption(array $args, int $i, string $arg, string $name, int|false $equals, array &$parsed, bool $on): int
    {
        $fmt = $parsed['command'] === 'fmt';
        if ($arg === '-x' && $parsed['command'] === 'render') {
            $parsed['options']['xhtml'] = true;

            return $i;
        }
        if ($arg === '-o') {
            self::valued('output', $args[$i + 1] ?? null, $parsed);

            return $i + 1;
        }
        if (($fmt || $parsed['command'] === 'lint' || $parsed['command'] === 'fix') && $arg === '-w') {
            $parsed['values']['write'] = true;

            return $i;
        }
        $flags = self::FLAGS + ($fmt ? self::FMT_FLAGS : [])
            + ($parsed['command'] === 'lint' || $parsed['command'] === 'fix' ? ['write' => true] : []);
        if (!str_starts_with($arg, '--') || !array_key_exists($name, $flags) || $equals !== false) {
            throw new OptionError('unknown option ' . $arg);
        }
        if ($name === 'strict-losses') {
            $parsed['options']['strictLosses'] = $on;
        } elseif (in_array($name, ['raw-html', 'roundtrip', 'sections', 'smart-typography', 'source-lines', 'xhtml'], true)) {
            $key = str_replace('-', '', ucwords($name, '-'));
            $parsed['options'][lcfirst($key)] = $on;
        } else {
            $key = match ($name) {
                'no-includes' => 'includes', 'no-json' => 'json', 'no-positions' => 'positions', default => $name,
            };
            $parsed['values'][$key] = $on && $flags[$name];
        }

        return $i;
    }

    /**
     * The output format of `render --to`.
     *
     * @param string $command
     * @param string $value
     *
     * @throws \MarkupCarve\Carve\OptionError For another command or an unknown format.
     *
     * @return string
     */
    private static function renderTarget(string $command, string $value): string
    {
        if ($command !== 'render') {
            throw new OptionError('unknown option --to');
        }
        if (!in_array($value, self::RENDER_TARGETS, true)) {
            throw new OptionError('unknown output format ' . $value . ' for --to; known formats: ' . implode(', ', self::RENDER_TARGETS));
        }

        return $value;
    }

    /**
     * @param string $name
     * @param string|null $value
     * @param array{command: string, files: array<string>, values: array<string, mixed>, options: array<string, mixed>} $parsed
     *
     * @throws \MarkupCarve\Carve\OptionError
     *
     * @return void
     */
    private static function valued(string $name, ?string $value, array &$parsed): void
    {
        if (isset(self::OPTION_FLAGS[$name])) {
            self::optionFlag($name, $value, $parsed['options']);

            return;
        }
        if ($value === null) {
            throw new OptionError('missing value for --' . $name);
        }
        if ($name === 'heading-ids') {
            $parsed['options']['headingIds'] = $value;

            return;
        }
        if ($name === 'ignore') {
            $ignore = $parsed['values']['ignore'] ?? [];
            $parsed['values']['ignore'] = is_array($ignore) ? [...$ignore, $value] : [$value];

            return;
        }
        if ($name === 'output') {
            $parsed['values']['output'] = $value;

            return;
        }
        if ($name === 'profile') {
            $parsed['options']['profile'] = $value;

            return;
        }
        if ($name === 'mode') {
            $parsed['options']['mode'] = $value;

            return;
        }
        if (in_array($name, ['labels', 'social', 'symbols'], true)) {
            $parsed['options'][$name] = self::json($name, $value);

            return;
        }
        if ($name === 'url-policy') {
            $parsed['options']['urlPolicy'] = self::json($name, $value);

            return;
        }
        if (in_array($name, ['report-losses', 'report-includes', 'extensions', 'extension-options'], true)) {
            $parsed['values'][$name] = $value;

            return;
        }
        $parsed['values'][$name] = $name === 'to' ? self::renderTarget($parsed['command'], $value)
            : ($name === 'include-root' ? $value : self::json($name, $value));
    }

    /**
     * @internal Shared with `ImportCommand`.
     *
     * @param string $name A key of `OPTION_FLAGS`.
     * @param string|null $value
     * @param array<string, mixed> $options
     *
     * @throws \MarkupCarve\Carve\OptionError
     *
     * @return void
     */
    public static function optionFlag(string $name, ?string $value, array &$options): void
    {
        if ($value === null) {
            throw new OptionError('missing value for --' . $name);
        }
        $option = self::OPTION_FLAGS[$name];
        if ($option === 'allowLosses') {
            $list = $options[$option] ?? [];
            $list = is_array($list) ? $list : [];
            $list[] = $value;
            $options[$option] = $list;
        } elseif ($option === 'maxLosses' || $option === 'headingShift') {
            if (preg_match('/^(0|[1-9][0-9]*)$/', $value) !== 1) {
                throw new OptionError('--' . $name . ' takes a count');
            }
            $options[$option] = (int)$value;
        } else {
            $options[$option] = $value;
        }
    }

    /**
     * C2-T-010: `--extensions` registers standard extensions by name, each with its standard renderer on `render`, and
     * `--extension-options` gives those renderers their options. A name outside the catalog is a usage error, and so is
     * an options entry for an extension the list does not register.
     *
     * @param array{command: string, files: array<string>, values: array<string, mixed>, options: array<string, mixed>} $parsed
     *
     * @return void
     */
    private static function extensionFlags(array &$parsed): void
    {
        $list = $parsed['values']['extensions'] ?? null;
        $settings = $parsed['values']['extension-options'] ?? null;
        unset($parsed['values']['extensions'], $parsed['values']['extension-options']);
        if (!is_string($list) && !is_string($settings)) {
            return;
        }
        $names = array_values(array_unique(self::extensionNames(is_string($list) ? $list : '[]')));
        $byName = is_string($settings) ? self::extensionSettings($settings, $names) : [];
        $parsed['options']['extensions'] = $names;
        if ($parsed['command'] !== 'render') {
            return;
        }
        $renderers = [];
        foreach ($names as $i => $name) {
            if ($name === Citations::CITE) {
                // C2-X-058: the citation renderer needs the bundle's references directive and its transform.
                $parsed['options']['extensions'][$i] = Citations::bundle(self::citeMode($byName[$name] ?? []));
            } elseif (($byName[$name] ?? []) !== []) {
                $renderers[$name] = ['standard' => $byName[$name]];
            }
            $renderers += self::standardRenderers($name);
        }
        if ($renderers !== []) {
            $parsed['options']['renderers'] = $renderers;
        }
    }

    /**
     * C2-X-072: a row that registers others, as `carve.glossary` does `carve.term`, gives each its standard renderer.
     *
     * @return array<string, string>
     */
    private static function standardRenderers(string $name): array
    {
        $renderers = [];
        foreach (Standard::definitions($name) ?? [] as $definition) {
            if (Standard::renderer($definition->name, 'interactive') !== null) {
                $renderers[$definition->name] = 'standard';
            }
        }

        return $renderers;
    }

    /**
     * C2-X-068: the cite renderer's one option, `mode`, is `numbered` (the default) or `author-date`.
     *
     * @param array<string, mixed> $settings
     *
     * @throws \MarkupCarve\Carve\OptionError
     *
     * @return string
     */
    private static function citeMode(array $settings): string
    {
        foreach (array_keys($settings) as $option) {
            if ($option !== 'mode') {
                throw new OptionError('--extension-options: ' . Citations::CITE . ' has no option ' . $option);
            }
        }
        $mode = array_key_exists('mode', $settings) ? $settings['mode'] : Citations::MODES[0];
        if (!is_string($mode) || !in_array($mode, Citations::MODES, true)) {
            throw new OptionError('--extension-options: ' . Citations::CITE . ' mode is ' . implode(' or ', Citations::MODES));
        }

        return $mode;
    }

    /**
     * @param string $value
     *
     * @throws \MarkupCarve\Carve\OptionError
     *
     * @return array<string>
     */
    private static function extensionNames(string $value): array
    {
        $names = self::json('extensions', $value);
        if (!is_array($names) || !array_is_list($names)) {
            throw new OptionError('--extensions takes a JSON list of standard extension names');
        }
        foreach ($names as $name) {
            if (!is_string($name) || Standard::definitions($name) === null) {
                $shown = json_encode($name, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                throw new OptionError('--extensions: ' . $shown . ' is no standard extension');
            }
        }

        return $names;
    }

    /**
     * @param string $value
     * @param array<string> $names The names `--extensions` lists.
     *
     * @throws \MarkupCarve\Carve\OptionError
     *
     * @return array<string, array<string, mixed>>
     */
    private static function extensionSettings(string $value, array $names): array
    {
        $decoded = json_decode($value);
        if (!$decoded instanceof stdClass) {
            throw new OptionError('--extension-options takes a JSON object keyed by extension name');
        }
        $byName = [];
        foreach (get_object_vars($decoded) as $name => $settings) {
            $name = (string)$name;
            if (Standard::definitions($name) === null) {
                throw new OptionError('--extension-options: ' . $name . ' is no standard extension');
            }
            if (!in_array($name, $names, true)) {
                throw new OptionError('--extension-options: ' . $name . ' is not in --extensions');
            }
            if (!$settings instanceof stdClass) {
                throw new OptionError('--extension-options: the options of ' . $name . ' are not an object');
            }
            $byName[$name] = get_object_vars($settings);
        }

        return $byName;
    }

    private static function json(string $name, string $value): mixed
    {
        $decoded = json_decode($value, true);
        if ($decoded === null && $value !== 'null') {
            throw new OptionError('bad JSON for --' . $name);
        }

        return $decoded;
    }

    // phpcs:disable Generic.Metrics.CyclomaticComplexity.MaxExceeded

    /**
     * @param array{command: string, files: array<string>, values: array<string, mixed>, options: array<string, mixed>} $parsed
     * @param string $file
     * @param resource $in
     * @param resource $out
     * @param resource $err
     *
     * @throws \MarkupCarve\Carve\Cli\InputError
     * @throws \MarkupCarve\Carve\OptionError
     *
     * @return array{0: int, 1: array<string, mixed>|null, 2: array<string, mixed>|null}
     */
    private static function one(array $parsed, string $file, $in, $out, $err): array
    {
        $source = $file === '-' ? StdinReader::read($in) : @file_get_contents($file);
        $display = $file === '-' ? '<stdin>' : $file;
        if ($source === false) {
            throw new InputError($display . ': cannot be read');
        }
        if ((($parsed['values']['from-ast'] ?? false) || $parsed['command'] === 'migrate-ast') && !mb_check_encoding($source, 'UTF-8')) {
            throw new InputError($display . ': not UTF-8', 'input-unreadable');
        }
        $options = $parsed['options'] + self::includes($parsed, $file);
        $dependencies = [];
        $includeOptions = $options['includes'] ?? null;
        if (
            isset($parsed['values']['report-includes']) && is_array($includeOptions)
            && is_callable($includeOptions['resolver'] ?? null)
        ) {
            $resolver = $includeOptions['resolver'];
            $topFile = $includeOptions['file'] ?? null;
            $includeOptions['resolver'] = static function (array $request) use ($resolver, $topFile, &$dependencies): array {
                $result = $resolver($request);
                $entry = ['path' => $request['path']];
                if (isset($request['from']) && $request['from'] !== $topFile) {
                    $entry['from'] = $request['from'];
                }
                if (isset($result['text'])) {
                    $entry['file'] = $result['id'] ?? $request['path'];
                }
                $entry['read'] = isset($result['text']);
                $dependencies[] = $entry;

                return $result;
            };
            $options['includes'] = $includeOptions;
        }
        $json = (bool)($parsed['values']['json'] ?? false);
        switch ($parsed['command']) {
            case 'migrate-ast':
                try {
                    $migrated = Carve::migrateTree($source);
                } catch (JsonException|TreeError $error) {
                    throw new InputError($display . ': ' . $error->getMessage(), 'ast-json-invalid');
                }
                // C2-A-217: the bare tree is JCS bytes; carve-js writes the envelope in insertion order.
                $written = isset($migrated['envelope'])
                    ? json_encode($migrated['envelope'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                    : TreeEncoder::canonical($migrated['document']);
                fwrite($out, $written . "\n");
                foreach (is_array($migrated['diagnostics'] ?? null) ? $migrated['diagnostics'] : [] as $diagnostic) {
                    if (is_array($diagnostic) && is_string($diagnostic['code'] ?? null) && is_string($diagnostic['message'] ?? null)) {
                        fwrite($err, 'carve[' . $diagnostic['code'] . ']: ' . $diagnostic['message'] . "\n");
                    }
                }

                return [0, null, null];
            case 'ast':
                $tree = json_decode(Carve::toJson($source, $options), true, 4096, JSON_THROW_ON_ERROR);
                if (!is_array($tree)) {
                    throw new InputError('the AST is not a document', 'ast-json-invalid');
                }
                if (($parsed['values']['positions'] ?? true) === false) {
                    self::removePositions($tree);
                }
                if ($parsed['values']['envelope'] ?? false) {
                    $envelope = ['astVersion' => $tree['astVersion'] ?? '2.0', 'document' => $tree];
                    if (isset($tree['extensions'])) {
                        $envelope['extensions'] = $tree['extensions'];
                    }
                    $tree = $envelope;
                }
                fwrite($out, json_encode($tree, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");

                return [
                    0, null, isset($parsed['values']['report-includes'])
                    ? ['file' => $display, 'dependencies' => $dependencies] : null,
                ];
            case 'diagnostics':
                $entries = array_values(array_filter(
                    Carve::parseWithDiagnostics($source, $options)->diagnostics,
                    static fn (Diagnostic $d): bool => $d->channel === 'parse',
                ));

                return [
                    0, self::report([$display, $source, self::root($parsed, $file)], $entries, $json, $out),
                    isset($parsed['values']['report-includes']) ? ['file' => $display, 'dependencies' => $dependencies] : null,
                ];
            case 'fmt':
                return [self::format($parsed, $file, $display, $source, $out), null, null];
            case 'flatten':
                return [self::flatten($source, $options, $display, $parsed, $out, $err), null, null];
            case 'fix':
            case 'lint':
                return [...self::lintFile($parsed, $file, $display, $source, $options, $out, $err), null];
            default:
                $to = $parsed['values']['to'] ?? 'html';
                $input = ($parsed['values']['from-ast'] ?? false) ? self::decodedTree($source, $options) : $source;
                if ($to !== 'html') {
                    Options::from($options);
                    $parseOptions = array_intersect_key($options, ['includes' => true, 'extensions' => true]);
                    $tree = is_string($input) ? Carve::parse($input, $parseOptions) : $input;
                    $exporter = match ($to) {
                        'markdown' => new MarkdownExporter(),
                        'plain' => new PlainTextExporter(),
                        'ansi' => new AnsiExporter(),
                        default => throw new OptionError('bad value for --to'),
                    };
                    $exportKeys = ['smartTypography', 'urlPolicy', 'headingShift', 'extensions'];
                    $exportOptions = array_intersect_key($options, array_flip($exportKeys));
                    $export = $exporter->export($tree, $exportOptions);
                    $allowed = $options['allowLosses'] ?? [];
                    $losses = array_values(array_filter(
                        $export->diagnostics,
                        static fn (Diagnostic $d): bool => !in_array($d->code, is_array($allowed) ? $allowed : [], true),
                    ));
                    $strictLoss = ($options['strictLosses'] ?? false) && $losses !== [];
                    $limit = $options['maxLosses'] ?? 100;
                    $suppressed = max(0, count($losses) - (is_int($limit) ? $limit : 100));
                    $retained = array_slice($losses, 0, is_int($limit) ? $limit : 100);
                    if ($strictLoss) {
                        fwrite($err, "carve[render-loss]: rendering lost content\n");
                    } else {
                        self::product($export->output, $out);
                    }
                    self::report([$display, $source, self::root($parsed, $file)], $retained, false, $err);
                    $record = self::report([$display, $source, self::root($parsed, $file)], $retained, true, $err)
                        ?? ['file' => $display, 'diagnostics' => [], 'suppressed' => 0];
                    $record['suppressed'] = $suppressed;

                    return [
                        $strictLoss || (($parsed['values']['strict'] ?? false) && $losses !== []) ? 1 : 0, $record,
                        isset($parsed['values']['report-includes']) ? ['file' => $display, 'dependencies' => $dependencies] : null,
                    ];
                }
                $transforms = [];
                $extensions = $options['extensions'] ?? [];
                foreach (is_array($extensions) ? $extensions : [] as $extension) {
                    if ($extension instanceof Bundle) {
                        array_push($transforms, ...$extension->transforms());
                    }
                }
                $result = Carve::renderWithDocumentTransforms($input, $transforms, $options);
                $losses = array_values(array_filter(
                    $result->diagnostics,
                    static fn (Diagnostic $d): bool => $d->channel === 'render-loss',
                ));
                $strictLoss = ($options['strictLosses'] ?? false)
                    && ($losses !== [] || ($result->suppressedDiagnostics > 0 && $result->html === ''));
                if ($strictLoss) {
                    fwrite($err, "carve[render-loss]: rendering lost content\n");
                } else {
                    self::product($result->html . "\n", $out);
                }
                self::report([$display, $source, self::root($parsed, $file)], $losses, false, $err);
                $record = self::report([$display, $source, self::root($parsed, $file)], $losses, true, $err)
                    ?? ['file' => $display, 'diagnostics' => [], 'suppressed' => 0];
                $record['suppressed'] = $result->suppressedDiagnostics;

                return [
                    $strictLoss || (($parsed['values']['strict'] ?? false) && $result->diagnostics !== []) ? 1 : 0, $record,
                    isset($parsed['values']['report-includes']) ? ['file' => $display, 'dependencies' => $dependencies] : null,
                ];
        }
    }

    // phpcs:enable Generic.Metrics.CyclomaticComplexity.MaxExceeded

    /**
     * @param array{command: string, files: array<string>, values: array<string, mixed>, options: array<string, mixed>} $parsed
     * @param string $file
     * @param string $display
     * @param string $source
     * @param array<string, mixed> $options
     * @param resource $out
     * @param resource $err
     *
     * @throws \MarkupCarve\Carve\Cli\InputError
     *
     * @return array{0: int, 1: array<string, mixed>|null}
     */
    private static function lintFile(array $parsed, string $file, string $display, string $source, array $options, $out, $err): array
    {
        $lint = array_filter(
            ['includes' => $options['includes'] ?? null] + self::lintOptions($parsed),
            static fn (mixed $v): bool => $v !== null,
        );
        $fix = $parsed['command'] === 'fix' || ($parsed['values']['fix'] ?? false);
        if ($fix) {
            $counts = [];
            $fixed = Fixer::fix($source, $lint, $counts);
            $fixed = Utf8::repair($fixed);
            if ($parsed['values']['write'] ?? false) {
                if ($fixed !== $source && @file_put_contents($file, $fixed) === false) {
                    throw new InputError($file . ': cannot be written', 'output-unwritable');
                }
            } else {
                self::product($fixed, $out);
            }
            self::writeFixedCounts($counts, $display, $err);
            $source = $fixed;
        }
        $ignore = $parsed['values']['ignore'] ?? [];
        $ignore = is_array($ignore) ? $ignore : [];
        $entries = array_values(array_filter(
            Carve::lint($source, $lint),
            static fn (Diagnostic $d): bool => !in_array($d->file ?? $display, $ignore, true) && !in_array($display, $ignore, true),
        ));
        $failed = array_filter(
            $entries,
            static fn (Diagnostic $d): bool => ($parsed['values']['strict'] ?? false) || $d->severity !== 'hint',
        ) !== [];
        $report = self::report(
            [$display, $source, self::root($parsed, $file)],
            $entries,
            (bool)($parsed['values']['json'] ?? false),
            $fix && !($parsed['values']['write'] ?? false) ? $err : $out,
        );

        return [$failed ? 1 : 0, $report];
    }

    /**
     * @param array<string, int> $counts
     * @param string $display
     * @param resource $err
     */
    private static function writeFixedCounts(array $counts, string $display, $err): void
    {
        foreach ($counts as $code => $count) {
            fwrite($err, $display . ': fixed ' . $code . ' ' . $count . "\n");
        }
    }

    /**
     * C2-W-100: the canonical form to standard output, in place with `--write`, or the first changed line with `--check`
     * and a unified diff with `--diff`, both with status 1 when a file would change.
     *
     * @param array{command: string, files: array<string>, values: array<string, mixed>, options: array<string, mixed>} $parsed
     * @param string $file
     * @param string $display
     * @param string $source
     * @param resource $out
     *
     * @throws \MarkupCarve\Carve\Cli\InputError
     *
     * @return int
     */
    private static function format(array $parsed, string $file, string $display, string $source, $out): int
    {
        $values = $parsed['values'];
        $options = ['normalize' => ($values['normalize'] ?? false) === true, 'alignTables' => ($values['align-tables'] ?? false) === true];
        $output = ($values['from-ast'] ?? false) === true
            ? Carve::write(self::tree($source), null, ['alignTables' => $options['alignTables']])
            : Carve::format($source, $options + $parsed['options']);
        $changed = $output !== $source;
        $check = ($values['check'] ?? false) === true;
        $diff = ($values['diff'] ?? false) === true;
        $write = ($values['write'] ?? false) === true;
        if ($check && $changed && !$diff) {
            fwrite($out, self::firstChange($display, $source, $output));
        }
        if ($diff && $changed) {
            fwrite($out, self::unified($display, $source, $output));
        }
        if ($write && $changed && @file_put_contents($file, $output) === false) {
            throw new InputError($file . ': cannot be written');
        }
        if (!$check && !$diff && !$write) {
            self::product($output, $out);
        }

        return ($check || $diff) && $changed ? 1 : 0;
    }

    /**
     * @param array{command: string, files: array<string>, values: array<string, mixed>, options: array<string, mixed>} $parsed
     *
     * @throws \MarkupCarve\Carve\OptionError
     */
    private static function validateCombination(array $parsed): void
    {
        $v = $parsed['values'];
        $fix = $parsed['command'] === 'fix' || ($v['fix'] ?? false);
        if (($v['write'] ?? false) && $parsed['command'] === 'lint' && !$fix) {
            throw new OptionError('--write requires --fix');
        }
        if ($fix && ($v['json'] ?? false) && !($v['write'] ?? false)) {
            throw new OptionError('--json with --fix requires --write');
        }
        if (($v['fix'] ?? false) && $parsed['command'] !== 'lint') {
            throw new OptionError('--fix is for lint');
        }
        if (($v['strict'] ?? false) && !in_array($parsed['command'], ['render', 'lint', 'fix'], true)) {
            throw new OptionError('--strict is for render or lint');
        }
        if (isset($v['ignore']) && !in_array($parsed['command'], ['lint', 'fix'], true)) {
            throw new OptionError('--ignore is for lint');
        }
        if (($v['upgrade'] ?? false) && !in_array($parsed['command'], ['lint', 'fix'], true)) {
            throw new OptionError('--upgrade is for lint');
        }
        if (isset($v['envelope']) && $parsed['command'] !== 'ast') {
            throw new OptionError('--envelope is for ast');
        }
        self::validateReportFormat($parsed);
        self::validateOutputCombination($parsed);
    }

    /**
     * @param array{command: string, files: array<string>, values: array<string, mixed>, options: array<string, mixed>} $parsed
     *
     * @throws \MarkupCarve\Carve\OptionError
     */
    private static function validateReportFormat(array $parsed): void
    {
        $v = $parsed['values'];
        if (isset($v['report-losses']) && $parsed['command'] !== 'render') {
            throw new OptionError('--report-losses is for render');
        }
        if (isset($v['report-includes']) && !in_array($parsed['command'], ['ast', 'diagnostics', 'render'], true)) {
            throw new OptionError('--report-includes is for ast, diagnostics or render');
        }
        if (
            $parsed['command'] === 'fmt' && (
            (($v['write'] ?? false) && (($v['check'] ?? false) || ($v['diff'] ?? false)))
            || (($v['check'] ?? false) && ($v['diff'] ?? false))
            || (($v['normalize'] ?? false) && ($v['from-ast'] ?? false))
            )
        ) {
            throw new OptionError('conflicting fmt flags');
        }
    }

    /**
     * @param array{command: string, files: array<string>, values: array<string, mixed>, options: array<string, mixed>} $parsed
     *
     * @throws \MarkupCarve\Carve\OptionError
     */
    private static function validateOutputCombination(array $parsed): void
    {
        $v = $parsed['values'];
        $destinations = [];
        foreach (['output', 'report-losses', 'report-includes'] as $flag) {
            $path = $v[$flag] ?? null;
            if (!is_string($path) || $path === '-') {
                continue;
            }
            $parent = realpath(dirname($path));
            $destination = ($parent === false ? dirname($path) : $parent) . '/' . basename($path);
            if (isset($destinations[$destination])) {
                throw new OptionError('output and report paths must differ');
            }
            $destinations[$destination] = true;
        }
        if (
            isset($v['output']) && $parsed['command'] === 'fmt'
            && (($v['write'] ?? false) || ($v['check'] ?? false) || ($v['diff'] ?? false))
        ) {
            throw new OptionError('--output conflicts with fmt write, check or diff');
        }
        if (isset($v['output']) && !in_array($parsed['command'], ['render', 'fmt', 'flatten'], true)) {
            throw new OptionError('--output is unavailable for this command');
        }
        $paths = [];
        foreach (['report-losses', 'report-includes', 'output'] as $flag) {
            $path = $v[$flag] ?? null;
            if (is_string($path) && $path !== '-') {
                $absolute = self::absoluteOutputPath($path);
                if (isset($paths[$absolute])) {
                    throw new OptionError('--' . $flag . ' shares a path with --' . $paths[$absolute]);
                }
                $paths[$absolute] = $flag;
            }
        }
    }

    private static function absoluteOutputPath(string $path): string
    {
        $cwd = getcwd();
        $absolute = str_starts_with($path, '/') ? $path : ($cwd === false ? '' : $cwd) . '/' . $path;
        $parts = [];
        foreach (explode('/', $absolute) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
            } else {
                $parts[] = $part;
            }
        }

        return '/' . implode('/', $parts);
    }

    /**
     * @param string $source
     * @param resource $out
     */
    private static function product(string $source, $out): void
    {
        fwrite($out, $source);
    }

    /**
     * @param array<string, mixed> $options
     * @param string $source
     * @param string $display
     * @param array{command: string, files: array<string>, values: array<string, mixed>, options: array<string, mixed>} $parsed
     * @param resource $out
     * @param resource $err
     */
    private static function flatten(string $source, array $options, string $display, array $parsed, $out, $err): int
    {
        $result = Carve::parseWithDiagnostics($source, $options);
        $unresolved = array_values(array_filter(
            $result->diagnostics,
            static fn (Diagnostic $d): bool => str_starts_with($d->code, 'include-'),
        ));
        if ($unresolved !== []) {
            fwrite($err, "carve[include-unresolved]: {$display}: include did not expand\n");
            self::report([$display, $source, null], $unresolved, false, $err);

            return 1;
        }
        self::expandTree($result->tree, $result->tree->includeSources);
        $flat = Carve::write($result->tree);
        if (Carve::toHtml($flat) !== Carve::toHtml($source, $options)) {
            fwrite($err, "carve[flatten-changed]: {$display}: flattened source renders differently\n");

            return 1;
        }
        self::product($flat, $out);

        return 0;
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param array<string, string> $sources The text of each included file, by id.
     *
     * @return void
     */
    private static function expandTree(Node $node, array $sources): void
    {
        $children = [];
        foreach ($node->children ?? [] as $child) {
            if ($child->type === 'include') {
                self::expandTree($child, $sources);
                foreach ($child->children ?? [] as $k => $expanded) {
                    // C2-T-047: only the leading block of the included file goes; later metadata blocks stay.
                    $bom = str_starts_with($sources[$expanded->file ?? ''] ?? '', "\u{FEFF}") ? 3 : 0;
                    if ($k !== 0 || $expanded->type !== 'frontmatter' || $expanded->start !== $bom) {
                        $children[] = $expanded;
                    }
                }
            } else {
                self::expandTree($child, $sources);
                $children[] = $child;
            }
        }
        if ($node->children !== null) {
            $node->children = $children;
        }
    }

    /**
     * @param string $json
     *
     * @throws \MarkupCarve\Carve\Cli\InputError
     *
     * @return array<string, mixed>
     */
    private static function tree(string $json): array
    {
        $tree = json_decode($json, true, 100000);
        if (!is_array($tree) || ($tree['type'] ?? null) !== 'document') {
            throw new InputError('the input is not AST JSON', 'ast-json-invalid');
        }

        /** @var array<string, mixed> $tree */
        return $tree;
    }

    /**
     * C2-X-003: a required extension that `--extensions` registers is known to the reader.
     *
     * @param string $json
     * @param array<string, mixed> $options
     *
     * @throws \MarkupCarve\Carve\Cli\InputError
     *
     * @return \MarkupCarve\Carve\Node
     */
    private static function decodedTree(string $json, array $options): Node
    {
        try {
            return Carve::decodeTree($json, array_intersect_key($options, ['extensions' => true]));
        } catch (TreeError $error) {
            throw new InputError($error->getMessage(), 'ast-json-invalid');
        }
    }

    /**
     * @param array<string|int, mixed> $node
     */
    private static function removePositions(array &$node): void
    {
        unset($node['pos']);
        foreach ($node as &$value) {
            if (is_array($value)) {
                self::removePositions($value);
            }
        }
    }

    private static function firstChange(string $display, string $before, string $after): string
    {
        $old = explode("\n", $before);
        $new = explode("\n", $after);
        $at = 0;
        $lines = count($old);
        while ($at < $lines && $old[$at] === ($new[$at] ?? null)) {
            $at++;
        }
        $shown = $at >= min(count($old), count($new)) ? max(0, min(count($old), count($new)) - 1) : $at;
        $beforeLine = rtrim($old[$shown] ?? '', "\r");
        $afterLine = rtrim($new[$shown] ?? '', "\r");
        $plus = $at >= count($new) ? '+' : '+ ' . $afterLine;

        return $display . ':' . ($shown + 1) . "\n- " . $beforeLine . "\n" . $plus . "\n";
    }

    private static function unified(string $display, string $before, string $after): string
    {
        $left = explode("\n", $before);
        $right = explode("\n", $after);
        if (str_ends_with($before, "\n")) {
            array_pop($left);
        }
        if (str_ends_with($after, "\n")) {
            array_pop($right);
        }
        $lines = ['--- a/' . $display, '+++ b/' . $display, '@@ -1,' . count($left) . ' +1,' . count($right) . ' @@'];
        foreach ($left as $line) {
            $lines[] = '-' . $line;
        }
        foreach ($right as $line) {
            $lines[] = '+' . $line;
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array{command: string, files: array<string>, values: array<string, mixed>, options: array<string, mixed>} $parsed
     *
     * @return array<string, mixed>
     */
    private static function lintOptions(array $parsed): array
    {
        $out = [];
        foreach (['rules', 'platforms'] as $name) {
            if (array_key_exists($name, $parsed['values'])) {
                $out[$name] = $parsed['values'][$name];
            }
        }
        if ($parsed['values']['upgrade'] ?? false) {
            $out['upgrade'] = true;
        }
        if (isset($parsed['options']['headingIds'])) {
            $out['headingIds'] = $parsed['options']['headingIds'];
        }
        if (isset($parsed['options']['extensions'])) {
            $out['extensions'] = $parsed['options']['extensions'];
        }

        return $out;
    }

    /**
     * C2-N-004: a file entry expands includes under the directory of the file unless they are turned off; standard input
     * expands them only under a named root.
     *
     * @param array{command: string, files: array<string>, values: array<string, mixed>, options: array<string, mixed>} $parsed
     * @param string $file
     *
     * @return array<string, mixed>
     */
    private static function includes(array $parsed, string $file): array
    {
        $canonical = self::root($parsed, $file);
        if ($canonical === null) {
            return [];
        }
        $input = $file === '-' ? null : $file;
        $entry = $input === null ? false : realpath($input);
        $inside = $entry !== false && ($entry === $canonical || str_starts_with($entry, $canonical . '/'));
        $id = $entry === false ? null : ($inside ? ltrim(substr($entry, strlen($canonical)), '/') : $entry);

        return ['includes' => ['resolver' => FileResolver::closure($canonical), 'file' => $id]];
    }

    /**
     * The canonical include root of an entry, or null when includes are not expanded.
     *
     * @param array{command: string, files: array<string>, values: array<string, mixed>, options: array<string, mixed>} $parsed
     * @param string $file
     *
     * @throws \MarkupCarve\Carve\OptionError
     *
     * @return string|null
     */
    private static function root(array $parsed, string $file): ?string
    {
        $root = $parsed['values']['include-root'] ?? null;
        $input = $file === '-' ? null : $file;
        if (($parsed['values']['includes'] ?? true) === false || ($root === null && $input === null)) {
            return null;
        }
        $canonical = realpath(is_string($root) ? $root : dirname((string)$input));
        if ($canonical === false) {
            throw new OptionError('include root is unavailable');
        }

        return $canonical;
    }

    /**
     * `FILE:LINE:COLUMN: SEVERITY CODE MESSAGE` per entry, or the JSON record of the file. A finding in an included file
     * (C2-N-087) is placed in that file and named by its id.
     *
     * @param array{0: string, 1: string, 2: string|null} $entry The display name, the source and the include root.
     * @param array<\MarkupCarve\Carve\Diagnostics\Diagnostic> $entries
     * @param bool $json
     * @param resource $out
     *
     * @return array<string, mixed>|null
     */
    private static function report(array $entry, array $entries, bool $json, $out): ?array
    {
        [$display, $source, $root] = $entry;
        $rows = [];
        $sources = [$display => $source];
        $positions = [];
        foreach ($entries as $finding) {
            $name = $display;
            $included = $finding->file !== null && $root !== null && !str_starts_with($finding->file, '/');
            if ($included) {
                $name = $finding->file;
                if (!isset($sources[$name])) {
                    $sources[$name] = (string)@file_get_contents($root . '/' . $name);
                }
            }
            $positions[$name] ??= ['offset' => 0, 'line' => 1, 'column' => 1];
            [$line, $column] = self::location($sources[$name], $finding->startOffset, $positions[$name]);
            if ($json) {
                // C2-T-020: `file` only names an included file; the command line places each include site in its file.
                $row = $finding->toArray();
                if ($included) {
                    $row['file'] = $name;
                } else {
                    unset($row['file']);
                }
                $row += ['line' => $line, 'column' => $column];
                if ($finding->includedBy !== null) {
                    $row['includedBy'] = [];
                    foreach ($finding->includedBy as $site) {
                        $siteName = $site['file'] ?? $display;
                        $sources[$siteName] ??= (string)@file_get_contents($root . '/' . $siteName);
                        $positions[$siteName] ??= ['offset' => 0, 'line' => 1, 'column' => 1];
                        $at = self::location($sources[$siteName], $site['range']['startOffset'], $positions[$siteName]);
                        $row['includedBy'][] = $site + ['line' => $at[0], 'column' => $at[1]];
                    }
                }
                $rows[] = $row;

                continue;
            }
            $where = $name . ':' . $line . ':' . $column . ': ';
            fwrite($out, $where . $finding->severity . ' ' . $finding->code . ' ' . $finding->message . "\n");
        }

        return $json ? ['file' => $display, 'diagnostics' => $rows, 'suppressed' => 0] : null;
    }

    /**
     * @param string $source
     * @param int $offset
     * @param array{offset: int, line: int, column: int} $position
     *
     * @return array{0: int, 1: int} The line, and the column in characters.
     */
    public static function location(string $source, int $offset, array &$position): array
    {
        $offset = max(0, min($offset, strlen($source)));
        if ($offset < $position['offset']) {
            $position = ['offset' => 0, 'line' => 1, 'column' => 1];
        }
        $chunk = substr($source, $position['offset'], $offset - $position['offset']);
        $breaks = substr_count($chunk, "\n");
        if ($breaks > 0) {
            $position['line'] += $breaks;
            $position['column'] = mb_strlen(mb_scrub(substr($chunk, (int)strrpos($chunk, "\n") + 1), 'UTF-8'), 'UTF-8') + 1;
        } else {
            $position['column'] += mb_strlen(mb_scrub($chunk, 'UTF-8'), 'UTF-8');
            if ($position['offset'] < 3 && $offset >= 3 && str_starts_with($source, "\xEF\xBB\xBF")) {
                $position['column']--;
            }
        }
        $position['offset'] = $offset;

        return [$position['line'], $position['column']];
    }
}
