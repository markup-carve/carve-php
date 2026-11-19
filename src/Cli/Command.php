<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Cli;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Diagnostics\Diagnostic;
use MarkupCarve\Carve\Include\FileResolver;
use MarkupCarve\Carve\OptionError;
use MarkupCarve\Carve\Writer\CarveWriteError;

/**
 * The `carve` command: `render` (the default), `ast`, `diagnostics`, `lint`, `fmt` and `import` (`ImportCommand`),
 * with the option names, output and exit codes of the carve-js 0.2 command line.
 */
final class Command
{
    /**
     * @var array<string, true>
     */
    private const COMMANDS = ['render' => true, 'ast' => true, 'diagnostics' => true, 'lint' => true, 'fmt' => true, 'format' => true];

    /**
     * @var array<string, true>
     */
    private const VALUED = ['include-root' => true, 'rules' => true, 'platforms' => true, 'to' => true, 'heading-ids' => true];

    /**
     * The output formats of `render --to`.
     *
     * @var array<string>
     */
    private const RENDER_TARGETS = ['html', 'markdown', 'plain', 'ansi'];

    /**
     * Flags that set one option: `softBreak` (C2-T-010), `allowLosses` one code per instance (C2-T-027) and `maxLosses`
     * (C2-T-029).
     *
     * @var array<string, string>
     */
    private const OPTION_FLAGS = ['soft-break' => 'softBreak', 'allow-loss' => 'allowLosses', 'max-render-losses' => 'maxLosses'];

    /**
     * @var array<string, bool>
     */
    private const FLAGS = ['json' => true, 'tree' => true, 'includes' => true, 'no-includes' => false];

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
        if (($args[0] ?? null) === 'import') {
            return ImportCommand::main(array_slice($args, 1), $in, $out, $err);
        }
        try {
            $parsed = self::parse($args);
            $files = $parsed['files'] === [] ? ['-'] : $parsed['files'];
            if (($parsed['values']['write'] ?? false) && in_array('-', $files, true)) {
                throw new OptionError('cannot write standard input in place');
            }
            $status = 0;
            $records = [];
            foreach ($files as $file) {
                [$code, $record] = self::one($parsed, $file, $in, $out);
                $status = max($status, $code);
                if ($record !== null) {
                    $records[] = $record;
                }
            }
            if ($parsed['values']['json'] ?? false) {
                $json = (string)json_encode(['files' => $records], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                // The carve-js command line indents by two spaces.
                $halve = static fn (array $m): string => substr($m[0], 0, intdiv(strlen($m[0]), 2));
                fwrite($out, (string)preg_replace_callback('/^ +/m', $halve, $json) . "\n");
            }

            return $status;
        } catch (OptionError $e) {
            fwrite($err, 'carve[usage]: ' . $e->getMessage() . "\n");

            return 2;
        } catch (InputError $e) {
            fwrite($err, 'carve[' . $e->category . ']: ' . $e->getMessage() . "\n");

            return 2;
        } catch (CarveWriteError $e) {
            // C2-W-134
            fwrite($err, 'carve[writer-refused]: ' . $e->getMessage() . "\n");

            return 1;
        }
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
        if (str_starts_with($arg, '--option=')) {
            [$key, $value] = explode('=', substr($arg, 9), 2) + [1 => ''];
            $parsed['options'][$key] = json_decode($value, true) ?? $value;

            return $i;
        }
        $equals = strpos($arg, '=');
        $name = substr($arg, 2, $equals === false ? null : $equals - 2);
        if (isset(self::VALUED[$name]) || (isset(self::OPTION_FLAGS[$name]) && $parsed['command'] === 'render')) {
            self::valued($name, $equals !== false ? substr($arg, $equals + 1) : ($args[$i + 1] ?? null), $parsed);

            return $equals !== false ? $i : $i + 1;
        }
        $fmt = $parsed['command'] === 'fmt';
        if ($fmt && $arg === '-w') {
            $parsed['values']['write'] = true;

            return $i;
        }
        $flags = self::FLAGS + ($fmt ? self::FMT_FLAGS : []);
        if (!str_starts_with($arg, '--') || !array_key_exists($name, $flags) || $equals !== false) {
            throw new OptionError('unknown option ' . $arg);
        }
        $parsed['values'][$name === 'no-includes' ? 'includes' : $name] = $flags[$name];

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
        } elseif ($option === 'maxLosses') {
            if (preg_match('/^(0|[1-9][0-9]*)$/', $value) !== 1) {
                throw new OptionError('--' . $name . ' takes a count');
            }
            $options[$option] = (int)$value;
        } else {
            $options[$option] = $value;
        }
    }

    private static function json(string $name, string $value): mixed
    {
        $decoded = json_decode($value, true);
        if ($decoded === null && $value !== 'null') {
            throw new OptionError('bad JSON for --' . $name);
        }

        return $decoded;
    }

    /**
     * @param array{command: string, files: array<string>, values: array<string, mixed>, options: array<string, mixed>} $parsed
     * @param string $file
     * @param resource $in
     * @param resource $out
     *
     * @throws \MarkupCarve\Carve\Cli\InputError
     *
     * @return array{0: int, 1: array<string, mixed>|null}
     */
    private static function one(array $parsed, string $file, $in, $out): array
    {
        $source = $file === '-' ? stream_get_contents($in) : @file_get_contents($file);
        $display = $file === '-' ? '<stdin>' : $file;
        if ($source === false) {
            throw new InputError($display . ': cannot be read');
        }
        $options = $parsed['options'] + self::includes($parsed, $file);
        $json = (bool)($parsed['values']['json'] ?? false);
        switch ($parsed['command']) {
            case 'ast':
                fwrite($out, Carve::toJson($source, $options) . "\n");

                return [0, null];
            case 'diagnostics':
                $entries = array_values(array_filter(
                    Carve::parseWithDiagnostics($source, $options)->diagnostics,
                    static fn (Diagnostic $d): bool => $d->channel === 'parse',
                ));

                return [0, self::report([$display, $source, self::root($parsed, $file)], $entries, $json, $out)];
            case 'fmt':
                return [self::format($parsed, $file, $display, $source, $out), null];
            case 'lint':
                $lint = ['includes' => $options['includes'] ?? null] + self::lintOptions($parsed);
                $entries = Carve::lint($source, array_filter($lint, static fn (mixed $v): bool => $v !== null));

                return [$entries === [] ? 0 : 1, self::report([$display, $source, self::root($parsed, $file)], $entries, $json, $out)];
            default:
                $to = $parsed['values']['to'] ?? 'html';
                if ($to !== 'html') {
                    fwrite($out, Carve::writeTo(is_string($to) ? $to : 'html', Carve::parse($source, $options)));

                    return [0, null];
                }
                fwrite($out, Carve::toHtml($source, $options) . "\n");

                return [0, null];
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
            fwrite($out, $output);
        }

        return ($check || $diff) && $changed ? 1 : 0;
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

    private static function firstChange(string $display, string $before, string $after): string
    {
        $old = explode("\n", $before);
        $new = explode("\n", $after);
        $at = 0;
        $lines = count($old);
        while ($at < $lines && $old[$at] === ($new[$at] ?? null)) {
            $at++;
        }

        return $display . ':' . ($at + 1) . "\n- " . ($old[$at] ?? '') . "\n+ " . ($new[$at] ?? '') . "\n";
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
        if (isset($parsed['options']['headingIds'])) {
            $out['headingIds'] = $parsed['options']['headingIds'];
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
        foreach ($entries as $finding) {
            [$name, $text] = [$display, $source];
            if ($finding->file !== null && $root !== null && !str_starts_with($finding->file, '/')) {
                [$name, $text] = [$finding->file, (string)@file_get_contents($root . '/' . $finding->file)];
            }
            [$line, $column] = self::location($text, $finding->startOffset);
            if ($json) {
                $rows[] = array_merge($finding->toArray(), ['file' => $name, 'line' => $line, 'column' => $column]);

                continue;
            }
            $where = $name . ':' . $line . ':' . $column . ': ';
            fwrite($out, $where . $finding->severity . ' ' . $finding->code . ' ' . $finding->message . "\n");
        }

        return $json ? ['file' => $display, 'diagnostics' => $rows] : null;
    }

    /**
     * @param string $source
     * @param int $offset
     *
     * @return array{0: int, 1: int} The line, and the column in characters.
     */
    private static function location(string $source, int $offset): array
    {
        $prefix = substr($source, 0, max(0, $offset));
        $start = strrpos($prefix, "\n");
        $last = $start === false ? $prefix : substr($prefix, $start + 1);

        return [substr_count($prefix, "\n") + 1, mb_strlen(mb_scrub($last, 'UTF-8'), 'UTF-8') + 1];
    }
}
