<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use Closure;
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Diagnostics\Diagnostic;
use MarkupCarve\Carve\Include\Selection;
use MarkupCarve\Carve\OptionError;
use MarkupCarve\Carve\Options;

/**
 * C2-N-085 to C2-N-087: every Carve include the expansion accepted is linted over the source it parsed; each finding names
 * its file, a range in that file and a tree path through the includes that reached it.
 */
final class Includes
{
    /**
     * @var array<string, string> The text the resolver returned for each id.
     */
    private array $texts = [];

    /**
     * @var array<string, true>
     */
    private array $seen = [];

    /**
     * @var array<\MarkupCarve\Carve\Diagnostics\Diagnostic>
     */
    private array $out = [];

    /**
     * @param array<string, mixed> $options The lint options without `includes`.
     */
    private function __construct(private array $options)
    {
    }

    /**
     * @param string $source
     * @param mixed $includes
     * @param array<string, mixed> $options
     *
     * @return array<\MarkupCarve\Carve\Diagnostics\Diagnostic>
     */
    public static function lint(string $source, mixed $includes, array $options): array
    {
        $visit = new self($options);
        $config = $visit->recording($includes);
        $parseOptions = ['includes' => $config] + array_intersect_key($options, ['extensions' => true, 'headingIds' => true]);
        $parsed = Carve::parseWithDiagnostics($source, $parseOptions);
        $tree = json_decode(Carve::encodeTree($parsed->tree), true, 4096, JSON_THROW_ON_ERROR);
        $visit->out = $parsed->diagnostics;
        if (is_array($tree)) {
            $visit->walk(Support::node($tree), '');
        }

        return $visit->out;
    }

    /**
     * Validate the resolver and its limits without parsing a document that has
     * no include opener.
     *
     * @throws \MarkupCarve\Carve\OptionError
     */
    public static function validate(mixed $includes): void
    {
        $config = $includes instanceof Closure ? ['resolver' => $includes] : $includes;
        if (!is_array($config) || !($config['resolver'] ?? null) instanceof Closure) {
            throw new OptionError('option includes: not a resolver or an object with a resolver');
        }
        Options::from(['includes' => $config], ['parse', 'resolve']);
    }

    /**
     * The `includes` option with a resolver that records each text it returns.
     *
     * @param mixed $includes
     *
     * @throws \MarkupCarve\Carve\OptionError
     *
     * @return array<string, mixed>
     */
    private function recording(mixed $includes): array
    {
        $config = $includes instanceof Closure ? ['resolver' => $includes] : $includes;
        if (!is_array($config) || !($config['resolver'] ?? null) instanceof Closure) {
            throw new OptionError('option includes: not a resolver or an object with a resolver');
        }
        $resolver = $config['resolver'];
        $config['resolver'] = function (array $request) use ($resolver): mixed {
            $answer = $resolver($request);
            if (is_array($answer) && is_string($answer['text'] ?? null) && is_string($answer['id'] ?? null)) {
                $this->texts[$answer['id']] = $answer['text'];
            }

            return $answer;
        };

        return $config;
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     *
     * @return void
     */
    private function walk(array $node, string $path): void
    {
        if (($node['type'] ?? null) === 'include' && is_array($node['children'] ?? null)) {
            $this->include($node, $path);
        }
        foreach ($node as $key => $value) {
            if ($key === 'pos' || $key === 'attrs' || !is_array($value)) {
                continue;
            }
            if (is_string($value['type'] ?? null)) {
                $this->walk(Support::node($value), $path . '/' . $key);

                continue;
            }
            foreach ($value as $i => $child) {
                if (is_int($i) && is_array($child) && is_string($child['type'] ?? null)) {
                    $this->walk(Support::node($child), $path . '/' . $key . '/' . $i);
                }
            }
        }
    }

    /**
     * C2-N-086, C2-N-087: lints the source of one expanded Carve include and keeps each distinct finding once.
     *
     * @param array<string, mixed> $node
     * @param string $path
     *
     * @return void
     */
    private function include(array $node, string $path): void
    {
        $file = is_string($node['file'] ?? null) ? $node['file'] : null;
        $text = $file === null ? null : ($this->texts[$file] ?? null);
        if ($text === null) {
            return;
        }
        $options = is_array($node['options'] ?? null) ? $node['options'] : [];
        if (isset($options['code'])) {
            foreach (Support::kids($node) as $i => $child) {
                foreach (Linter::lintPayload($text, $child, '/children/' . $i, $this->options) as $entry) {
                    $this->keep($node, $text, [$text, 0, null, null], $entry, $path);
                }
            }

            return;
        }
        $part = $this->part($node, $options, $text);
        foreach (Linter::lintSource($part[0], $this->options) as $entry) {
            $this->keep($node, $text, $part, $entry, $path);
        }
    }

    /**
     * The part of the file that is linted, the bytes before it, and for a section the lines whose findings are kept
     * and the paths of its blocks.
     *
     * @param array<string, mixed> $node
     * @param array<string, mixed> $options
     * @param string $text
     *
     * @return array{0: string, 1: int, 2: array{0: int, 1: int}|null, 3: array<string, string>|null}
     */
    private function part(array $node, array $options, string $text): array
    {
        if (isset($node['section'])) {
            return [$text, 0, self::sectionLines($node), self::sectionPaths($node, $text)];
        }
        if (!isset($options['lines']) && !isset($options['region'])) {
            return [$text, 0, null, null];
        }
        [$selected, $offset, $error] = Selection::source($text, $options);

        return $error === 'include-region-invalid' ? [$text, 0, null, null] : [$selected, $offset, null, null];
    }

    /**
     * C2-N-086: for a section, the lines from the first selected block through the last one.
     *
     * @param array<string, mixed> $node
     *
     * @return array{0: int, 1: int}|null
     */
    private static function sectionLines(array $node): ?array
    {
        $first = null;
        $last = null;
        foreach (Support::kids($node) as $child) {
            $pos = Support::pos($child);
            $type = $child['type'] ?? '';
            $definition = in_array($type, ['link_reference_definition', 'footnote', 'abbreviation_def', 'citation_definition'], true);
            if ($pos === null || $definition) {
                continue;
            }
            $first ??= $pos['startLine'] - 1;
            $last = $pos['endLine'];
        }

        return $first === null || $last === null ? null : [$first, $last];
    }

    /**
     * C2-N-087: a selected block keeps its place in the file, so its path in the file's own tree is found by that place.
     *
     * @param array<string, mixed> $node
     * @param string $text
     *
     * @return array<string, string> The path under the include of each selected block, by its path in the file.
     */
    private static function sectionPaths(array $node, string $text): array
    {
        $tree = json_decode(Carve::encodeTree(Carve::parse($text)), true, 4096, JSON_THROW_ON_ERROR);
        $places = [];
        if (is_array($tree)) {
            self::places(Support::node($tree), '', $places);
        }
        $paths = [];
        foreach (Support::kids($node) as $i => $child) {
            $key = self::placeKey($child);
            if ($key !== null && isset($places[$key])) {
                $paths[$places[$key]] = '/children/' . $i;
            }
        }

        return $paths;
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param array<string, string> $places
     *
     * @return void
     */
    private static function places(array $node, string $path, array &$places): void
    {
        $key = self::placeKey($node);
        if ($key !== null && !isset($places[$key])) {
            $places[$key] = $path;
        }
        foreach ($node as $slot => $value) {
            if ($slot === 'pos' || $slot === 'attrs' || !is_array($value)) {
                continue;
            }
            if (is_string($value['type'] ?? null)) {
                self::places(Support::node($value), $path . '/' . $slot, $places);

                continue;
            }
            foreach ($value as $i => $child) {
                if (is_int($i) && is_array($child) && is_string($child['type'] ?? null)) {
                    self::places(Support::node($child), $path . '/' . $slot . '/' . $i, $places);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return string|null
     */
    private static function placeKey(array $node): ?string
    {
        $pos = Support::pos($node);
        $type = $node['type'] ?? null;

        return $pos === null || !is_string($type) ? null : $type . "\0" . $pos['startOffset'] . "\0" . $pos['endOffset'];
    }

    /**
     * The path under the include of a finding at `$path` in the file's own tree, by the longest selected block around it.
     *
     * @param array<string, string> $paths
     * @param string $path
     *
     * @return string|null
     */
    private static function pathUnder(array $paths, string $path): ?string
    {
        for ($end = strlen($path); $end > 0; $end = (int)strrpos($path, '/', $end - strlen($path) - 1)) {
            $under = $paths[substr($path, 0, $end)] ?? null;
            if ($under !== null) {
                return $under . substr($path, $end);
            }
        }

        return null;
    }

    /**
     * C2-N-087: one finding of an included file, unless it is outside a section or reported already.
     *
     * @param array<string, mixed> $node
     * @param string $text
     * @param array{0: string, 1: int, 2: array{0: int, 1: int}|null, 3: array<string, string>|null} $part
     * @param \MarkupCarve\Carve\Diagnostics\Diagnostic $entry
     * @param string $path
     *
     * @return void
     */
    private function keep(array $node, string $text, array $part, Diagnostic $entry, string $path): void
    {
        $start = max(0, $entry->startOffset) + $part[1];
        $kept = $part[2];
        if ($kept !== null) {
            $line = substr_count($text, "\n", 0, min($start, strlen($text))) + 1;
            if ($line <= $kept[0] || $line > $kept[1]) {
                return;
            }
        }
        $file = is_string($node['file'] ?? null) ? $node['file'] : '';
        $end = max(0, $entry->endOffset) + $part[1];
        // A file included twice repeats a finding with only another path; per-column findings share a range.
        $key = implode("\0", [$file, $entry->code, $entry->severity, $entry->channel, $entry->message, $start, $end]);
        if (isset($this->seen[$key])) {
            return;
        }
        $this->seen[$key] = true;
        // A finding of a section outside every selected block, such as one on the file as a whole, points at the include.
        $inner = $part[3] === null ? (string)$entry->path : (self::pathUnder($part[3], (string)$entry->path) ?? '');
        $this->out[] = new Diagnostic(
            $entry->code,
            $entry->severity,
            $entry->channel,
            $entry->message,
            $path . $inner,
            $start,
            $end,
            $file,
        );
    }
}
