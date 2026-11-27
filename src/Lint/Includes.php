<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use Closure;
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Diagnostics\Diagnostic;
use MarkupCarve\Carve\Diagnostics\Findings;
use MarkupCarve\Carve\Include\Selection;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\OptionError;
use MarkupCarve\Carve\Options;
use MarkupCarve\Carve\Snippet\Expander;

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
     * @var list<array{0: string, 1: string, 2: array{0: string, 1: int, 2: array{0: int, 1: int}|null, 3: array<string, string>|null}}>
     *   The children path, the file text and the linted part of each expanded Carve include.
     */
    private array $parts = [];

    private References $references;

    /**
     * @param array<string, mixed> $options The lint options without `includes`.
     */
    private function __construct(private array $options)
    {
        $this->references = new References();
    }

    /**
     * @param string $source
     * @param mixed $includes
     * @param array<string, mixed> $options
     * @param array<\MarkupCarve\Carve\Diagnostics\Diagnostic> $own The findings of the source itself, reconciled with the expansion.
     *
     * @return array<\MarkupCarve\Carve\Diagnostics\Diagnostic>
     */
    public static function lint(string $source, mixed $includes, array $options, array $own = []): array
    {
        $visit = new self($options);
        $config = $visit->recording($includes);
        $parseOptions = ['includes' => $config] + array_intersect_key($options, ['extensions' => true, 'headingIds' => true]);
        $parsed = Carve::parseWithDiagnostics($source, $parseOptions);
        $losing = self::losingSnippets($parsed->tree, $parsed->diagnostics);
        $used = Snippets::usedNames($parsed->tree);
        $tree = json_decode(Carve::encodeTree($parsed->tree), true, 4096, JSON_THROW_ON_ERROR);
        if (is_array($tree)) {
            $root = Support::node($tree);
            $visit->walkReferences($root, '', '', '', [self::scope(Support::kids($root), '')]);
            $visit->walk($root, '');
        }
        // C2-N-086: an entry of the expanded parse counts only inside the part of its file that was linted.
        $visit->out = [...array_filter($parsed->diagnostics, $visit->inLintedPart(...)), ...$visit->out];
        $own = array_filter($own, $visit->references->kept(...));
        // C2-N-096: a definition one file of the expansion uses is used, and one that loses to an earlier one takes no hint.
        $kept = static function (Diagnostic $entry) use ($losing, $used): bool {
            if ($entry->code !== 'unused-snippet') {
                return true;
            }
            preg_match('/"\$([^"]+)"/', $entry->message, $name);

            return !isset($losing[($entry->file ?? '') . "\0" . $entry->startOffset]) && !isset($used[$name[1] ?? '']);
        };

        return [...array_filter($own, $kept), ...array_filter($visit->out, $kept)];
    }

    /**
     * The link and footnote definitions among the direct children of a document or an include; the first one of a key wins.
     *
     * @param array<int, array<string, mixed>> $children
     * @param string $file
     *
     * @return array{links: array<string, string>, notes: array<string, string>}
     */
    private static function scope(array $children, string $file): array
    {
        $scope = ['links' => [], 'notes' => []];
        foreach ($children as $child) {
            $kind = match ($child['type'] ?? null) {
                'link_reference_definition' => 'links',
                'footnote' => 'notes',
                default => null,
            };
            $pos = is_array($child['pos'] ?? null) ? $child['pos'] : [];
            $start = $pos['startOffset'] ?? null;
            if ($kind !== null && is_string($child['key'] ?? null) && is_int($start)) {
                $scope[$kind][$child['key']] ??= $file . "\0" . $start;
            }
        }

        return $scope;
    }

    /**
     * C2-N-054, C2-N-055: the expanded tree decides which file's definition a reference uses, so the reference findings of
     * the per-file lint are checked against it.
     *
     * @param array<string, mixed> $node
     * @param string $path
     * @param string $file
     * @param string $inclusion The path of the include whose file holds the node; empty for the top-level document.
     * @param array<array{links: array<string, string>, notes: array<string, string>}> $scopes Innermost last.
     *
     * @return void
     */
    private function walkReferences(array $node, string $path, string $file, string $inclusion, array $scopes): void
    {
        $this->references->record($node, $file, $inclusion, $scopes);
        $inner = [$file, $inclusion, $scopes];
        if (($node['type'] ?? null) === 'include' && is_array($node['children'] ?? null)) {
            $innerFile = is_string($node['file'] ?? null) ? $node['file'] : $file;
            $inner = [$innerFile, $path, [...$scopes, self::scope(Support::kids($node), $innerFile)]];
        }
        foreach ($node as $key => $value) {
            if ($key === 'pos' || $key === 'attrs' || !is_array($value)) {
                continue;
            }
            [$underFile, $underInclusion, $underScopes] = $key === 'children' ? $inner : [$file, $inclusion, $scopes];
            if (is_string($value['type'] ?? null)) {
                $this->walkReferences(Support::node($value), $path . '/' . $key, $underFile, $underInclusion, $underScopes);

                continue;
            }
            foreach ($value as $i => $child) {
                if (is_int($i) && is_array($child) && is_string($child['type'] ?? null)) {
                    $this->walkReferences(
                        Support::node($child),
                        $path . '/' . $key . '/' . $i,
                        $underFile,
                        $underInclusion,
                        $underScopes,
                    );
                }
            }
        }
    }

    /**
     * C2-N-090: the file and offset of the `$` of each definition of the expanded tree that loses to an earlier one of
     * its name. A file included twice repeats its definitions at one offset; the winning one stays out.
     *
     * @param \MarkupCarve\Carve\Node $tree
     * @param array<\MarkupCarve\Carve\Diagnostics\Diagnostic> $diagnostics
     *
     * @return array<string, true>
     */
    private static function losingSnippets(Node $tree, array $diagnostics): array
    {
        $losing = [];
        foreach ($diagnostics as $entry) {
            if ($entry->code === 'duplicate-snippet') {
                $losing[($entry->file ?? '') . "\0" . $entry->startOffset] = true;
            }
        }
        foreach (Expander::registry($tree) as [$definition]) {
            unset($losing[($definition->file ?? '') . "\0" . ($definition->start + 1)]);
        }

        return $losing;
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
     * @param array<array{file?: string, range: array{startOffset: int, endOffset: int}}> $chain The include sites that
     *   reached the node (C2-T-020).
     * @param string|null $in The file the node is in; null for the top-level document.
     *
     * @return void
     */
    private function walk(array $node, string $path, array $chain = [], ?string $in = null): void
    {
        $inner = $chain;
        $innerFile = $in;
        if (($node['type'] ?? null) === 'include' && is_array($node['children'] ?? null)) {
            $inner[] = self::site($node, $in);
            $innerFile = is_string($node['file'] ?? null) ? $node['file'] : $in;
            $this->include($node, $path, $inner);
        }
        foreach ($node as $key => $value) {
            if ($key === 'pos' || $key === 'attrs' || !is_array($value)) {
                continue;
            }
            [$under, $underFile] = $key === 'children' ? [$inner, $innerFile] : [$chain, $in];
            if (is_string($value['type'] ?? null)) {
                $this->walk(Support::node($value), $path . '/' . $key, $under, $underFile);

                continue;
            }
            foreach ($value as $i => $child) {
                if (is_int($i) && is_array($child) && is_string($child['type'] ?? null)) {
                    $this->walk(Support::node($child), $path . '/' . $key . '/' . $i, $under, $underFile);
                }
            }
        }
    }

    /**
     * The `includedBy` site of an include node of the encoded tree.
     *
     * @param array<string, mixed> $node
     * @param string|null $in
     *
     * @return array{file?: string, range: array{startOffset: int, endOffset: int}}
     */
    private static function site(array $node, ?string $in): array
    {
        $pos = is_array($node['pos'] ?? null) ? $node['pos'] : [];

        return Findings::site(
            $in,
            is_int($pos['startOffset'] ?? null) ? $pos['startOffset'] : -1,
            is_int($pos['endOffset'] ?? null) ? $pos['endOffset'] : -1,
        );
    }

    /**
     * C2-N-086, C2-N-087: lints the source of one expanded Carve include and keeps each distinct finding once.
     *
     * @param array<string, mixed> $node
     * @param string $path
     * @param array<array{file?: string, range: array{startOffset: int, endOffset: int}}> $chain
     *
     * @return void
     */
    private function include(array $node, string $path, array $chain): void
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
                    $this->keep($node, $text, [$text, 0, null, null], $entry, $path, $chain);
                }
            }

            return;
        }
        $part = $this->part($node, $options, $text);
        $this->parts[] = [$path . '/children/', $text, $part];
        foreach (Linter::lintSource($part[0], $this->options) as $entry) {
            $this->keep($node, $text, $part, $entry, $path, $chain);
        }
    }

    /**
     * Whether an entry of the expanded parse stands in the linted part of the deepest include whose children hold it; a
     * line or region selection appends definitions of its file that it did not select (C2-N-020).
     */
    private function inLintedPart(Diagnostic $entry): bool
    {
        if ($entry->file === null || $entry->startOffset < 0) {
            return true;
        }
        $deepest = null;
        foreach ($this->parts as $at) {
            if (str_starts_with((string)$entry->path, $at[0]) && ($deepest === null || strlen($at[0]) > strlen($deepest[0]))) {
                $deepest = $at;
            }
        }
        if ($deepest === null) {
            return true;
        }
        [, $text, $part] = $deepest;
        $start = $entry->startOffset;
        if ($part[2] !== null) {
            $line = substr_count($text, "\n", 0, min($start, strlen($text))) + 1;

            return $line > $part[2][0] && $line <= $part[2][1];
        }

        return $part[0] === $text || ($start >= $part[1] && $start < $part[1] + strlen($part[0]));
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
            $definition = in_array(
                $type,
                ['link_reference_definition', 'footnote', 'abbreviation_def', 'citation_definition', 'snippet_definition'],
                true,
            );
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
     * @param array<array{file?: string, range: array{startOffset: int, endOffset: int}}> $chain
     *
     * @return void
     */
    private function keep(array $node, string $text, array $part, Diagnostic $entry, string $path, array $chain): void
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
        if (!$this->references->keeps($entry->code, $file, $start, $path)) {
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
            includedBy: $chain,
        );
    }
}
