<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\OptionError;

/**
 * Lint of a 0.2 source (C2-W-103 to C2-W-113): the rules of `data/lint-rules.json` over the tree and the source lines, with
 * the control comments of C2-B-215 and C2-B-216.
 */
final class Linter
{
    /**
     * C2-W-107: leaves whose text no line rule reads.
     *
     * @var array<string, true>
     */
    private const OPAQUE_LEAVES = [
        'code' => true,
        'raw_inline' => true,
        'literal_inline' => true,
        'math' => true,
        'comment' => true,
        'code_block' => true,
        'raw_block' => true,
        'frontmatter' => true,
        'include' => true,
        'link_reference_definition' => true,
        'abbreviation_def' => true,
    ];

    /**
     * C2-B-215: the control keywords, the longer first.
     *
     * @var array<string>
     */
    private const CONTROLS = ['disable-next-line', 'disable-line', 'disable', 'enable'];

    /**
     * @var array<string, array{severity: string, default: string|null}>|null
     */
    private static ?array $catalog = null;

    /**
     * @var array<string, array<string>>|null
     */
    private static ?array $slots = null;

    /**
     * @var array<string, array<array{0: string, 1: \Closure}>>|null
     */
    private static ?array $byType = null;

    /**
     * @param string $source
     * @param array<string, mixed> $options
     *
     * @return array<\MarkupCarve\Carve\Diagnostics\Diagnostic>
     */
    public static function lint(string $source, array $options = []): array
    {
        self::validate($options);
        $own = self::lintSource($source, $options);
        if (!isset($options['includes'])) {
            $parsed = Carve::parseWithDiagnostics($source, array_intersect_key($options, ['extensions' => true, 'headingIds' => true]));

            return self::ordered(array_merge($own, $parsed->diagnostics));
        }
        $rest = $options;
        unset($rest['includes']);

        return self::ordered(array_merge($own, Includes::lint($source, $options['includes'], $rest)));
    }

    /**
     * @param string $source
     * @param array<string, mixed> $options Without `includes`.
     *
     * @return array<\MarkupCarve\Carve\Diagnostics\Diagnostic>
     */
    public static function lintSource(string $source, array $options): array
    {
        $parsed = Carve::parseWithLayout($source, array_intersect_key($options, ['extensions' => true, 'headingIds' => true]));
        $tree = (new TreeEncoder())->encode($parsed->tree, $source);
        $c = new Context($source, $options, self::catalog());
        $c->layout->rawRefs((array)$parsed->layout['nodes']);
        /** @var array<int, array<array{0: string, 1: array<string>}>> $controls */
        $controls = [];
        $root = Support::node($tree);
        self::measure($root, '', '', 0, $c);
        self::visit($root, '', $c, $controls);
        NodeRulesB::finish($c);
        foreach ($c->lines as $line) {
            if ($line->opaque) {
                if (isset($c->frontmatterLines[$line->number]) && $c->active('blockquote-marker-without-space')) {
                    LineRules::frontmatterQuote($line, $c);
                }

                continue;
            }
            $scan = self::masked($line, $c);
            foreach (LineRules::all() as [$id, $check]) {
                if ($c->active($id)) {
                    $check($scan, $c);
                }
            }
        }

        return self::ordered(self::filter($c, $controls));
    }

    /**
     * C2-N-086: the rules that read the payload of a block, on a block whose positions count in `$text`, the file it came from.
     *
     * @param string $text
     * @param array<string, mixed> $node
     * @param string $path
     * @param array<string, mixed> $options
     *
     * @return array<\MarkupCarve\Carve\Diagnostics\Diagnostic>
     */
    public static function lintPayload(string $text, array $node, string $path, array $options): array
    {
        $c = new Context($text, $options + ['payload' => true], self::catalog());
        foreach (self::rulesFor(is_string($node['type'] ?? null) ? $node['type'] : '') as [$id, $check]) {
            if ($id === 'bidi-control-in-source' && $c->active($id)) {
                $check($node, $path, $c);
            }
        }

        return $c->diagnostics;
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     * @param \MarkupCarve\Carve\Lint\Context $c
     * @param array<int, array<array{0: string, 1: array<string>}>> $controls
     *
     * @return void
     */
    private static function visit(array $node, string $path, Context $c, array &$controls): void
    {
        $type = is_string($node['type'] ?? null) ? $node['type'] : '';
        $c->nodeTypes[$path] = $type;
        $attrs = $node['attrs'] ?? null;
        if (is_array($attrs) && is_string($attrs['id'] ?? null)) {
            $c->ids[$attrs['id']] = true;
        }
        $pos = Support::pos($node);
        if ($attrs !== null && $pos !== null) {
            $c->attachedLines[$pos['startLine']] = true;
        }
        if ($type === 'heading' && is_string($node['generatedId'] ?? null)) {
            $c->ids[$node['generatedId']] = true;
        }
        if ($pos !== null) {
            self::placed($node, $type, $path, $pos, $c, $controls);
        }
        foreach (self::rulesFor($type) as [$id, $check]) {
            if ($c->active($id)) {
                $check($node, $path, $c);
            }
        }
        foreach (self::children($node, $type, $path) as [$child, $childPath]) {
            self::visit($child, $childPath, $c, $controls);
        }
    }

    /**
     * The line facts, the mask and the control comment of a node with a position.
     *
     * @param array<string, mixed> $node
     * @param string $type
     * @param string $path
     * @param array{startLine: int, endLine: int, startColumn: int, endColumn: int, startOffset: int, endOffset: int} $pos
     * @param \MarkupCarve\Carve\Lint\Context $c
     * @param array<int, array<array{0: string, 1: array<string>}>> $controls
     *
     * @return void
     */
    private static function placed(array $node, string $type, string $path, array $pos, Context $c, array &$controls): void
    {
        $c->firstLines[$path] = $pos['startLine'];
        if ($type === 'paragraph') {
            $c->paragraphStarts[$path] = $pos['startOffset'];
        }
        self::markLines($c, $type === 'comment' && ($node['form'] ?? null) === 'block' ? 'comment_block' : $type, $path, $pos);
        if (isset(self::OPAQUE_LEAVES[$type])) {
            $c->leafMask[] = [$pos['startOffset'], $pos['endOffset']];
        }
        $control = self::control($node, $c);
        if ($control !== null) {
            $controls[$pos['startLine']][] = $control;
        }
    }

    /**
     * The layout facts are measured before any rule runs, since a rule of a list reads the facts of its items.
     *
     * @param array<string, mixed> $node
     * @param string $path
     * @param string $parent
     * @param int $quotes
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    private static function measure(array $node, string $path, string $parent, int $quotes, Context $c): void
    {
        $type = is_string($node['type'] ?? null) ? $node['type'] : '';
        $c->layout->register($path, $node, $quotes, $parent);
        $pos = Support::pos($node);
        $inner = $quotes + ($type === 'block_quote' && $pos !== null && self::marked($c->source, $pos['startOffset']) ? 1 : 0);
        foreach (self::children($node, $type, $path) as [$child, $childPath]) {
            self::measure($child, $childPath, $path, $inner, $c);
        }
    }

    /**
     * C2-A-025: the child nodes in source order, each with its path.
     *
     * @param array<string, mixed> $node
     * @param string $type
     * @param string $path
     *
     * @return array<array{0: array<string, mixed>, 1: string}>
     */
    private static function children(array $node, string $type, string $path): array
    {
        $out = [];
        foreach (self::slotsOf($type) as $slot) {
            if ($slot === 'slots.*') {
                foreach (is_array($node['slots'] ?? null) ? $node['slots'] : [] as $name => $value) {
                    self::collect($value, $path . '/slots/' . $name, $out);
                }

                continue;
            }
            $value = $node;
            foreach (explode('.', $slot) as $name) {
                $value = is_array($value) ? ($value[$name] ?? null) : null;
            }
            self::collect($value, $path . '/' . str_replace('.', '/', $slot), $out);
        }

        return $out;
    }

    /**
     * @param mixed $value
     * @param string $path
     * @param array<array{0: array<string, mixed>, 1: string}> $out
     *
     * @return void
     */
    private static function collect(mixed $value, string $path, array &$out): void
    {
        if (!is_array($value)) {
            return;
        }
        if (is_string($value['type'] ?? null)) {
            $out[] = [Support::node($value), $path];

            return;
        }
        foreach ($value as $i => $child) {
            if (is_int($i) && is_array($child) && is_string($child['type'] ?? null)) {
                $out[] = [Support::node($child), $path . '/' . $i];
            }
        }
    }

    /**
     * C2-B-003: a quote with marks, not a quote fence.
     *
     * @param string $s
     * @param int $start
     *
     * @return bool
     */
    private static function marked(string $s, int $start): bool
    {
        $p = $start + strspn($s, " \t", $start);

        return ($s[$p] ?? '') === '>';
    }

    /**
     * @param \MarkupCarve\Carve\Lint\Context $c
     * @param string $type
     * @param string $path
     * @param array{startLine: int, endLine: int} $pos
     *
     * @return void
     */
    private static function markLines(Context $c, string $type, string $path, array $pos): void
    {
        $paragraph = $type === 'paragraph' || $type === 'caption' || $type === 'definition_term';
        $opaque = in_array($type, ['code_block', 'raw_block', 'frontmatter', 'comment_block'], true);
        if (!$paragraph && !$opaque && $type !== 'heading') {
            return;
        }
        for ($i = $pos['startLine'] - 1; $i < $pos['endLine'] && isset($c->lines[$i]); $i++) {
            if ($paragraph || $type === 'heading') {
                $c->lines[$i]->path = $path;
            }
            if ($paragraph) {
                $c->lines[$i]->paragraph = true;
            }
            if ($opaque) {
                $c->lines[$i]->opaque = true;
                if ($type === 'frontmatter') {
                    $c->frontmatterLines[$i + 1] = true;
                }
            }
        }
    }

    /**
     * C2-B-215: a lint control comment, as its kind and the rule ids it names.
     *
     * @param array<string, mixed> $node
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return array{0: string, 1: array<string>}|null
     */
    private static function control(array $node, Context $c): ?array
    {
        if (($node['type'] ?? null) !== 'comment' || ($node['form'] ?? null) !== 'line') {
            return null;
        }
        $raw = Support::raw($node, $c);
        $marker = strpos($raw, '%%');
        if ($marker === false || ($raw[$marker + 2] ?? '') !== ' ' || ($raw[$marker + 3] ?? '') === ' ') {
            return null;
        }
        $value = Support::str($node, 'value') ?? '';
        if (!str_starts_with($value, 'carve-lint-')) {
            return null;
        }
        $suffix = substr($value, 11);
        foreach (self::CONTROLS as $kind) {
            if (!str_starts_with($suffix, $kind)) {
                continue;
            }
            $tail = substr($suffix, strlen($kind));
            if ($tail !== '' && $tail[0] !== ' ' && $tail[0] !== ',') {
                return null;
            }

            return [$kind, array_values(array_filter(preg_split('/[ ,]/', $tail) ?: [], static fn (string $id): bool => $id !== ''))];
        }

        return null;
    }

    private static function masked(Line $line, Context $c): Line
    {
        $text = $line->text;
        $changed = false;
        foreach ($c->leafMask as [$from, $to]) {
            $a = max($from, $line->start);
            $b = min($to, $line->start + strlen($text));
            if ($a < $b) {
                $text = substr_replace($text, str_repeat("\0", $b - $a), $a - $line->start, $b - $a);
                $changed = true;
            }
        }

        return $changed ? new Line($text, $line->start, $line->end, $line->number, $line->path, $line->opaque, $line->paragraph) : $line;
    }

    /**
     * C2-B-216: the findings no control comment suppresses.
     *
     * @param \MarkupCarve\Carve\Lint\Context $c
     * @param array<int, array<array{0: string, 1: array<string>}>> $controls
     *
     * @return array<\MarkupCarve\Carve\Diagnostics\Diagnostic>
     */
    private static function filter(Context $c, array $controls): array
    {
        $byLine = [];
        foreach ($c->diagnostics as $i => $diagnostic) {
            $byLine[$c->findingLines[$i]][] = $diagnostic;
        }
        $state = [];
        $out = [];
        $count = count($c->lines);
        for ($line = 1; $line <= $count; $line++) {
            foreach ($controls[$line] ?? [] as [$kind, $ids]) {
                if ($kind === 'disable' || $kind === 'enable') {
                    foreach ($ids === [] ? array_keys(self::catalog()) : $ids as $id) {
                        $state[$id] = $kind === 'disable';
                    }
                }
            }
            foreach ($byLine[$line] ?? [] as $diagnostic) {
                if (!self::suppressed($diagnostic->code, $line, $controls, $state)) {
                    $out[] = $diagnostic;
                }
            }
        }

        return $out;
    }

    /**
     * @param string $id
     * @param int $line
     * @param array<int, array<array{0: string, 1: array<string>}>> $controls
     * @param array<string, bool> $state
     *
     * @return bool
     */
    private static function suppressed(string $id, int $line, array $controls, array $state): bool
    {
        foreach ($controls[$line] ?? [] as [$kind, $ids]) {
            if ($kind === 'disable-line' && ($ids === [] || in_array($id, $ids, true))) {
                return true;
            }
        }
        foreach ($controls[$line - 1] ?? [] as [$kind, $ids]) {
            if ($kind === 'disable-next-line' && ($ids === [] || in_array($id, $ids, true))) {
                return true;
            }
        }

        return ($state[$id] ?? false) === true;
    }

    /**
     * In position order, findings at one offset in the order they were made.
     *
     * @param array<\MarkupCarve\Carve\Diagnostics\Diagnostic> $diagnostics
     *
     * @return array<\MarkupCarve\Carve\Diagnostics\Diagnostic>
     */
    private static function ordered(array $diagnostics): array
    {
        $keys = array_keys($diagnostics);
        usort($keys, static fn (int $a, int $b): int => [$diagnostics[$a]->startOffset, $a] <=> [$diagnostics[$b]->startOffset, $b]);

        return array_map(static fn (int $k) => $diagnostics[$k], $keys);
    }

    /**
     * @param string $type
     *
     * @return array<array{0: string, 1: \Closure}>
     */
    private static function rulesFor(string $type): array
    {
        if (self::$byType === null) {
            self::$byType = [];
            foreach (NodeRules::all() as [$id, $types, $check]) {
                foreach ($types as $t) {
                    self::$byType[$t][] = [$id, $check];
                }
            }
        }

        return self::$byType[$type] ?? [];
    }

    /**
     * C2-A-025: the slots of a node type, `title` first, without the metadata slots a parsed tree does not hold.
     *
     * @param string $type
     *
     * @return array<string>
     */
    private static function slotsOf(string $type): array
    {
        if (self::$slots === null) {
            $data = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/spec/data/node-slots.json'), true);
            self::$slots = [];
            foreach (is_array($data) && is_array($data['nodes'] ?? null) ? $data['nodes'] : [] as $name => $paths) {
                $core = [];
                foreach (is_array($paths) ? $paths : [] as $slot) {
                    if (is_string($slot) && !str_starts_with($slot, 'metadata.')) {
                        $core[] = $slot;
                    }
                }
                if (in_array('title', $core, true)) {
                    $core = array_merge(['title'], array_values(array_diff($core, ['title'])));
                }
                self::$slots[(string)$name] = $core;
            }
        }

        return self::$slots[$type] ?? [];
    }

    /**
     * @return array<string, array{severity: string, default: string|null}>
     */
    public static function catalog(): array
    {
        if (self::$catalog === null) {
            $data = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/spec/data/lint-rules.json'), true);
            self::$catalog = [];
            foreach (is_array($data) && is_array($data['rules'] ?? null) ? $data['rules'] : [] as $rule) {
                if (is_array($rule) && ($rule['channel'] ?? null) === 'lint' && is_string($rule['id'] ?? null)) {
                    self::$catalog[$rule['id']] = [
                        'severity' => is_string($rule['severity'] ?? null) ? $rule['severity'] : 'warning',
                        'default' => is_string($rule['default'] ?? null) ? $rule['default'] : null,
                    ];
                }
            }
        }

        return self::$catalog;
    }

    /**
     * C2-H-137, C2-W-109: the options are checked before any value is read.
     *
     * @param array<string, mixed> $options
     *
     * @throws \MarkupCarve\Carve\OptionError
     */
    private static function validate(array $options): void
    {
        foreach (array_keys($options) as $name) {
            if (!in_array($name, ['rules', 'platforms', 'includes', 'extensions', 'headingIds'], true)) {
                throw new OptionError('option ' . $name . ': unknown option');
            }
        }
        $platforms = $options['platforms'] ?? null;
        if ($platforms !== null && (!is_array($platforms) || !array_is_list($platforms) || array_diff($platforms, ['github']) !== [])) {
            throw new OptionError('option platforms: not a list of supported platform names');
        }
        $rules = $options['rules'] ?? null;
        if ($rules === null) {
            return;
        }
        if (!is_array($rules) || ($rules !== [] && array_is_list($rules))) {
            throw new OptionError('option rules: not a map');
        }
        foreach ($rules as $name => $value) {
            if (!isset(self::catalog()[$name]) || ($value !== 'on' && $value !== 'off')) {
                throw new OptionError('option rules: invalid rule ' . $name);
            }
        }
    }
}
