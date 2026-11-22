<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use MarkupCarve\Carve\Block\TableBuilder;
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
        'autolink' => true,
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
     * @var array<string, bool>
     */
    private const CONTENT_TYPES = [
        'paragraph' => true,
        'caption' => true,
        'line' => true,
        'heading' => true,
        'definition_term' => true,
        'thematic_break' => true,
        'table_row' => true,
    ];

    /**
     * @var array<string, array{severity: string, default: string|null, status: string|null}>|null
     */
    private static ?array $catalog = null;

    /**
     * @var array<string, array<array{0: array<string>|null, 1: string}>>|null The field names and path suffix of each slot.
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
        $parseDiagnostics = [];
        $own = self::lintSource($source, $options, $parseDiagnostics);
        // A second parse is needed only when an include opener can exist.
        if (!isset($options['includes']) || !str_contains($source, '{{ ')) {
            if (isset($options['includes'])) {
                Includes::validate($options['includes']);
            }

            return self::ordered(array_merge($own, $parseDiagnostics));
        }
        $rest = $options;
        unset($rest['includes']);

        return self::ordered(array_merge($own, Includes::lint($source, $options['includes'], $rest)));
    }

    /**
     * @param string $source
     * @param array<string, mixed> $options Without `includes`.
     * @param array<\MarkupCarve\Carve\Diagnostics\Diagnostic> $parseDiagnostics Set to the diagnostics of the parse.
     *
     * @return array<\MarkupCarve\Carve\Diagnostics\Diagnostic>
     */
    public static function lintSource(string $source, array $options, array &$parseDiagnostics = []): array
    {
        // A lint run builds no cycles, so the collector would only rescan the growing tree.
        $gc = gc_enabled();
        gc_disable();
        try {
            return self::lintRun($source, $options, $parseDiagnostics);
        } finally {
            if ($gc) {
                gc_enable();
            }
        }
    }

    /**
     * @param array<mixed> $entries
     *
     * @return array<string, array<string, mixed>>
     */
    private static function layoutEntries(array $entries): array
    {
        $byPath = [];
        foreach ($entries as $entry) {
            if (
                is_array($entry) && is_string($entry['path'] ?? null)
                && is_int($entry['startByte'] ?? null) && is_int($entry['endByte'] ?? null)
            ) {
                $byPath[$entry['path']] = $entry;
            }
        }

        return $byPath;
    }

    /**
     * @param string $source
     * @param array<string, mixed> $options
     * @param array<\MarkupCarve\Carve\Diagnostics\Diagnostic> $parseDiagnostics
     *
     * @return array<\MarkupCarve\Carve\Diagnostics\Diagnostic>
     */
    private static function lintRun(string $source, array $options, array &$parseDiagnostics): array
    {
        $parsed = TableBuilder::compactEmptyParagraphs(static fn () => Carve::parseWithLayout(
            $source,
            array_intersect_key($options, ['extensions' => true, 'headingIds' => true]),
        ));
        $parseDiagnostics = $parsed->diagnostics;
        $document = $parsed->tree;
        $rawRefs = [];
        foreach ((array)($parsed->layout['nodes'] ?? []) as $entry) {
            if (is_array($entry) && isset($entry['rawRef'])) {
                $rawRefs[] = $entry;
            }
        }
        $entries = DataRules::enabled() ? self::layoutEntries((array)($parsed->layout['nodes'] ?? [])) : [];
        unset($parsed);
        $tree = (new TreeEncoder())->encodeForLint($document, $source);
        unset($document);
        $c = new Context($source, $options, self::catalog());
        $c->layoutEntries = $entries;
        unset($entries);
        $c->layout->rawRefs($rawRefs);
        /** @var array<int, array<array{0: string, 1: array<string>}>> $controls */
        $controls = [];
        $root = Support::node($tree);
        self::measureRoot($root, $c);
        // The measure walk registered every node in tree order, so the visit reads that list instead of walking again.
        $childCounts = [];
        foreach ($c->layout->nodes() as $path => $node) {
            $path = (string)$path;
            $parent = $c->layout->parent($path);
            $index = $path === '' ? 0 : ($childCounts[$parent] = ($childCounts[$parent] ?? -1) + 1);
            self::visit($node, $path, $c, $controls, $parent, $index);
        }
        unset($childCounts, $tree, $root);
        NodeRulesB::finish($c);
        usort($c->leafMask, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        self::runHandwrittenLines($c);

        if ($c->dataRules) {
            DataRules::run($c);
        }

        $findings = self::ordered(self::filter($c, $controls));
        $dataRules = $c->dataRules;
        unset($c);
        if ($dataRules && memory_get_usage(true) - memory_get_usage(false) > 16 * 1024 * 1024) {
            gc_mem_caches();
        }

        return $findings;
    }

    private static function runHandwrittenLines(Context $c): void
    {
        $maskCursor = 0;
        $lineRules = array_values(array_filter(LineRules::all(), static fn (array $rule): bool => $c->active($rule[0])));
        $shapeOnly = $c->dataRules;
        foreach ($lineRules as [$id]) {
            if (!in_array($id, ['deprecated-indented-line-under-heading', 'table-row-without-closing-pipe'], true)) {
                $shapeOnly = false;

                break;
            }
        }
        $recoverOpaque = $c->active('blockquote-marker-without-space') || $c->active('bare-html-tag');
        if ($lineRules !== [] || $recoverOpaque) {
            foreach ($c->lines as $line) {
                if ($line->opaque) {
                    if (isset($c->frontmatterLines[$line->number]) && $c->active('blockquote-marker-without-space')) {
                        LineRules::frontmatterQuote($line, $c);
                    }
                    if ($recoverOpaque) {
                        LineRules::escapedOpaque($line, $c);
                    }

                    continue;
                }
                if ($lineRules !== []) {
                    if (
                        $shapeOnly
                        && (!$line->paragraph || (($line->text[0] ?? '') !== ' '
                            && (ltrim($line->text, " \t")[0] ?? '') !== '|'))
                    ) {
                        continue;
                    }
                    $scan = self::masked($line, $c, $maskCursor);
                    foreach ($lineRules as [, $check]) {
                        $check($scan, $c);
                    }
                }
            }
        }
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
     * @param int $index
     * @param string $parent
     *
     * @return void
     */
    // The parser walk handles facts for all node forms before visiting children.
    // phpcs:disable Generic.Metrics.CyclomaticComplexity.MaxExceeded

    private static function visit(array $node, string $path, Context $c, array &$controls, string $parent, int $index): void
    {
        $type = is_string($node['type'] ?? null) ? $node['type'] : '';
        $c->nodeTypes[$path] = $type;
        if ($type === 'link_reference_definition') {
            $c->referenceDefinitions[] = [$node, $path];
        } elseif ($type === 'footnote') {
            $c->footnoteDefinitions[] = [$node, $path];
        } elseif ($type === 'footnote_ref') {
            $c->usedFootnotes[Support::str($node, 'key') ?? ''] = true;
        }
        $attrs = $node['attrs'] ?? null;
        if (is_array($attrs) && is_string($attrs['id'] ?? null)) {
            $c->ids[$attrs['id']] = true;
            if ($type === 'heading' || DataModel::numberedCaption($node)) {
                $c->xrefIds[$attrs['id']] = true;
            }
        }
        $pos = Support::pos($node);
        if ($c->dataRules) {
            $modelType = $type === 'comment' && ($node['form'] ?? null) === 'block' ? 'comment_block' : $type;
            $c->walked[] = ['node' => $node, 'path' => $path, 'parent' => $parent, 'index' => $index, 'pos' => $pos, 'type' => $modelType];
        }
        if ($attrs !== null && $pos !== null) {
            $c->attachedLines[$pos['startLine']] = true;
        }
        if ($type === 'heading' && is_string($node['generatedId'] ?? null)) {
            $c->ids[$node['generatedId']] = true;
            $c->xrefIds[$node['generatedId']] = true;
        }
        if ($pos !== null) {
            self::placed($node, $type, $path, $pos, $c, $controls);
        }
        $rules = $c->nodeRules[$type] ??= array_values(array_filter(
            self::rulesFor($type),
            static fn (array $rule): bool => $c->active($rule[0]),
        ));
        foreach ($rules as [, $check]) {
            $check($node, $path, $c);
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
        // Only a line's paragraph, caption, term or heading path is ever looked up.
        if ($type === 'paragraph' || $type === 'caption' || $type === 'definition_term' || $type === 'heading') {
            $c->firstLines[$path] = $pos['startLine'];
        }
        if ($type === 'definition_description') {
            $c->descriptionEntryLines[$pos['startLine']] = true;
        }
        if ($type === 'table_row') {
            $c->tableRowPaths[$pos['startLine']] = $path;
        }
        if ($type === 'comment' && ($node['form'] ?? null) === 'block') {
            for ($line = $pos['startLine']; $line <= $pos['endLine']; $line++) {
                $c->blockCommentStartLines[$line] = $pos['startLine'];
            }
        }
        if ($type === 'paragraph' || $type === 'definition_term') {
            $c->paragraphStarts[$path] = $pos['startOffset'];
        }
        // A braced underline in any inline host is an inline node, not an attribute opener.
        if ($type === 'underline') {
            $c->underlineStarts[$pos['startOffset']] = true;
        }
        self::markContent($type, $pos, $c);
        self::markLines($c, $type === 'comment' && ($node['form'] ?? null) === 'block' ? 'comment_block' : $type, $path, $pos);
        if (isset(self::OPAQUE_LEAVES[$type])) {
            $c->leafMask[] = [$pos['startOffset'], $pos['endOffset'], $path, $type];
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
     * Register top-level predecessor kinds while measuring their trees, without adding work to each recursive node.
     *
     * @param array<string, mixed> $root
     * @param \MarkupCarve\Carve\Lint\Context $c
     */
    private static function measureRoot(array $root, Context $c): void
    {
        $c->layout->register('', $root, 0, '');
        $previousType = '';
        foreach (self::children($root, 'document', '') as [$child, $childPath]) {
            $attrs = $child['attrs'] ?? null;
            if (($child['type'] ?? null) === 'frontmatter' && is_array($attrs) && is_string($attrs['id'] ?? null)) {
                $c->frontmatterIds[$attrs['id']] = true;
            }
            $blockPos = Support::pos($child);
            if ($blockPos !== null) {
                $firstLine = $c->lines[$blockPos['startLine'] - 1] ?? null;
                if (
                    ($child['type'] ?? null) === 'paragraph' && $firstLine !== null
                    && str_starts_with($firstLine->text, '    ')
                ) {
                    $c->previousTopLevelBlocks[$blockPos['startLine']] = $previousType;
                }
                $previousType = is_string($child['type'] ?? null) ? $child['type'] : '';
            }
            self::measure($child, $childPath, '', 0, $c);
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
        foreach (self::slotsOf($type) as [$names, $suffix]) {
            if ($names === null) {
                foreach (is_array($node['slots'] ?? null) ? $node['slots'] : [] as $name => $value) {
                    self::collect($value, $path . '/slots/' . $name, $out);
                }

                continue;
            }
            $value = $node;
            foreach ($names as $name) {
                $value = is_array($value) ? ($value[$name] ?? null) : null;
            }
            self::collect($value, $path . $suffix, $out);
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
            if (
                is_int($i) && is_array($child) && is_string($child['type'] ?? null)
                && !isset($child['__lintEmptyCell']) && $child['type'] !== 'table_column'
            ) {
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
        $leaf = $paragraph || $type === 'heading' || $type === 'line';
        if (!$leaf && !$opaque) {
            return;
        }
        for ($i = $pos['startLine'] - 1; $i < $pos['endLine'] && isset($c->lines[$i]); $i++) {
            if ($leaf) {
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

    /**
     * A leaf block starts its first line's text, and each break ends where the next line's text starts.
     *
     * @param string $type
     * @param array{startLine: int, endLine: int, startColumn: int, endColumn: int, startOffset: int, endOffset: int} $pos
     * @param \MarkupCarve\Carve\Lint\Context $c
     *
     * @return void
     */
    private static function markContent(string $type, array $pos, Context $c): void
    {
        $break = $type === 'soft_break' || $type === 'hard_break';
        if (!$break && !isset(self::CONTENT_TYPES[$type])) {
            return;
        }
        $line = $c->lines[($break ? $pos['endLine'] : $pos['startLine']) - 1] ?? null;
        $at = $break ? $pos['endOffset'] : $pos['startOffset'];
        // A caption's paragraph starts behind the caption's own marker, which stays part of the line's text.
        if ($line !== null && ($line->content === null || $at < $line->content)) {
            $line->content = $at;
        }
    }

    private static function masked(Line $line, Context $c, int &$cursor): Line
    {
        $text = $line->text;
        $changed = false;
        $count = count($c->leafMask);
        while ($cursor < $count && $c->leafMask[$cursor][1] <= $line->start) {
            $cursor++;
        }
        $end = $line->start + strlen($text);
        for ($i = $cursor; $i < $count && $c->leafMask[$i][0] < $end; $i++) {
            [$from, $to] = $c->leafMask[$i];
            $a = max($from, $line->start);
            $b = min($to, $end);
            if ($a < $b) {
                $text = substr_replace($text, str_repeat("\0", $b - $a), $a - $line->start, $b - $a);
                $changed = true;
            }
        }

        if (!$changed) {
            return $line;
        }

        return new Line($text, $line->start, $line->end, $line->number, $line->path, $line->opaque, $line->paragraph, $line->content);
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
     * @return array<array{0: array<string>|null, 1: string}>
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
                self::$slots[(string)$name] = array_map(static fn (string $slot): array => $slot === 'slots.*'
                    ? [null, '']
                    : [explode('.', $slot), '/' . str_replace('.', '/', $slot)], $core);
            }
        }

        return self::$slots[$type] ?? [];
    }

    /**
     * @return array<string, array{severity: string, default: string|null, status: string|null}>
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
                        'status' => is_string($rule['status'] ?? null) ? $rule['status'] : null,
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
