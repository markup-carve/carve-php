<?php

declare(strict_types=1);

namespace MarkupCarve\Carve;

use MarkupCarve\Carve\Block\BlockParser;
use MarkupCarve\Carve\Block\TreeSink;
use MarkupCarve\Carve\Diagnostics\Cap;
use MarkupCarve\Carve\Diagnostics\Diagnostic;
use MarkupCarve\Carve\Diagnostics\Findings;
use MarkupCarve\Carve\Export\AnsiExporter;
use MarkupCarve\Carve\Export\CodeFence;
use MarkupCarve\Carve\Export\MarkdownExporter;
use MarkupCarve\Carve\Export\PlainTextExporter;
use MarkupCarve\Carve\Extension\DocumentTransform;
use MarkupCarve\Carve\Extension\ExtensionProvider;
use MarkupCarve\Carve\Extension\Extensions;
use MarkupCarve\Carve\Html\HtmlRenderer;
use MarkupCarve\Carve\Html\Profile;
use MarkupCarve\Carve\Import\HtmlImporter;
use MarkupCarve\Carve\Import\ImportResult;
use MarkupCarve\Carve\Include\Expander;
use MarkupCarve\Carve\Include\IncludeResolver;
use MarkupCarve\Carve\Inline\InlineContext;
use MarkupCarve\Carve\Inline\InlineRunner;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeReader;
use MarkupCarve\Carve\Layout\LayoutBuilder;
use MarkupCarve\Carve\Layout\LayoutReader;
use MarkupCarve\Carve\Lint\Linter;
use MarkupCarve\Carve\Output\CarveWriter;
use MarkupCarve\Carve\Output\HtmlWriter;
use MarkupCarve\Carve\Output\WriterRegistry;
use MarkupCarve\Carve\Resolve\PlainText;
use MarkupCarve\Carve\Resolve\Resolver;
use MarkupCarve\Carve\Resolve\TableGrid;
use MarkupCarve\Carve\Resolve\Walk;
use MarkupCarve\Carve\Writer\Writer;
use MarkupCarve\Carve\Writer\WriteResult;
use Throwable;
use UnexpectedValueException;
use WeakMap;

/**
 * The public entry of the 0.2 engine (tooling.md C2-T-005): parse, render, lint, format and write, HTML import, tree
 * JSON, include dependencies and the output writers. Every call validates its options and throws only errors that
 * implement `CarveException`.
 */
final class Carve
{
    /**
     * The source of each tree `parse()` returned, so `render()` of that tree keeps its source lines.
     *
     * @var \WeakMap<\MarkupCarve\Carve\Node, string>|null
     */
    private static ?WeakMap $sources = null;

    /**
     * Parse and resolve: the tree of a source.
     *
     * @param string $source
     * @param array<string, mixed> $options
     *
     * @return \MarkupCarve\Carve\Node
     */
    public static function parse(string $source, array $options = []): Node
    {
        return self::remember(self::parsed($source, Options::from(self::normalize($options), ['parse', 'resolve']))[0], $source);
    }

    /**
     * Parse and resolve, with the entries of the parse channel (C2-A-173).
     *
     * @param string $source
     * @param array<string, mixed> $options
     *
     * @return \MarkupCarve\Carve\ParseResult
     */
    public static function parseWithDiagnostics(string $source, array $options = []): ParseResult
    {
        $opts = Options::from(self::normalize($options), ['parse', 'resolve']);
        [$doc, $findings] = self::parsed($source, $opts);
        [$entries, $suppressed] = Cap::apply($findings->finish($doc), $opts, false);

        return new ParseResult(self::remember($doc, $source), $entries, $suppressed);
    }

    /**
     * Parse and resolve, with the source-layout record of the input (C2-L-001, C2-L-003: the tree is the same).
     *
     * @param string $source
     * @param array<string, mixed> $options
     *
     * @return \MarkupCarve\Carve\LayoutResult
     */
    public static function parseWithLayout(string $source, array $options = []): LayoutResult
    {
        [$doc, $findings] = self::parsed($source, Options::from(self::normalize($options), ['parse', 'resolve']));

        return new LayoutResult(self::remember($doc, $source), LayoutBuilder::build($source, $doc), $findings->finish($doc));
    }

    /**
     * Reads a source-layout record as JSON, refusing what C2-L-008 and C2-L-058 refuse.
     *
     * @param string $json
     * @param array<mixed>|null $tree The decoded tree the record belongs to, for the order of extension slots.
     *
     * @return array<mixed>
     */
    public static function validateLayout(string $json, ?array $tree = null): array
    {
        return LayoutReader::read($json, $tree);
    }

    /**
     * Render, with the entries of the parse and the render-loss channels (C2-A-173). A tree has no parse entries.
     *
     * @param \MarkupCarve\Carve\Node|array<string, mixed>|string $input Carve source, or a tree (see `render()`).
     * @param array<string, mixed> $options
     *
     * @return \MarkupCarve\Carve\RenderResult
     */
    public static function renderWithDiagnostics(Node|array|string $input, array $options = []): RenderResult
    {
        return self::renderDetailed($input, [], $options);
    }

    /**
     * C2-X-053 to C2-X-055: run named host passes on a copied resolved view before HTML rendering.
     *
     * @param \MarkupCarve\Carve\Node|array<string, mixed>|string $input
     * @param array<\MarkupCarve\Carve\Extension\DocumentTransform> $transforms
     * @param array<string, mixed> $options
     */
    public static function renderWithDocumentTransforms(Node|array|string $input, array $transforms, array $options = []): RenderResult
    {
        return self::renderDetailed($input, $transforms, $options);
    }

    /**
     * @param \MarkupCarve\Carve\Node|array<string, mixed>|string $input
     * @param array<\MarkupCarve\Carve\Extension\DocumentTransform> $transforms
     * @param array<string, mixed> $options
     */
    private static function renderDetailed(Node|array|string $input, array $transforms, array $options): RenderResult
    {
        $opts = Options::from(self::normalize($options), ['parse', 'resolve', 'render']);
        if (is_string($input)) {
            $source = $input;
            self::checkRenderInput($source, $opts);
            [$doc, $findings, $facts] = self::parsed($source, $opts, true);
        } else {
            [$doc, $source, $facts] = self::resolvedTree($input, $opts);
            $findings = new Findings();
        }
        $transformEntries = [];
        if ($transforms !== []) {
            [$doc, $facts, $transformEntries] = self::applyDocumentTransforms($doc, $source, $opts, $transforms);
        }
        [$html, $log] = HtmlRenderer::renderWithLog($doc, $opts, $source, $facts);
        $entries = $findings->finish($doc);
        array_push($entries, ...$transformEntries);
        $losses = new Findings();
        foreach ($log->entries as $entry) {
            [$start, $end] = self::renderLossRange($source, $entry);
            $losses->add(
                $entry['code'],
                $entry['node'],
                $entry['file'] ?? $entry['node']?->file,
                $start,
                $end,
                $entry['message'],
            );
        }
        foreach ($losses->finish($doc) as $i => $loss) {
            $entry = $log->entries[$i];
            $severity = $opts->strictLosses ? 'error' : $entry['severity'];
            $entries[] = new Diagnostic(
                $entry['code'],
                $severity,
                $entry['channel'],
                $entry['message'],
                $loss->path,
                $loss->startOffset,
                $loss->endOffset,
                $loss->file,
            );
        }

        [$entries, $suppressed] = Cap::apply($entries, $opts, true);

        return new RenderResult($opts->strictLosses && $log->entries !== [] ? '' : $html, $entries, $suppressed);
    }

    /**
     * @param \MarkupCarve\Carve\Node $doc
     * @param string|null $source
     * @param \MarkupCarve\Carve\Options $opts
     * @param array<mixed> $transforms
     *
     * @return array{\MarkupCarve\Carve\Node, \MarkupCarve\Carve\Resolve\Facts|null, array<\MarkupCarve\Carve\Diagnostics\Diagnostic>}
     */
    private static function applyDocumentTransforms(Node $doc, ?string $source, Options $opts, array $transforms): array
    {
        $view = Extensions::copy($doc);
        $entries = [];
        foreach ($transforms as $transform) {
            if (!$transform instanceof DocumentTransform) {
                throw new OptionError('document transform: invalid registration');
            }
            $candidate = Extensions::copy($view);
            try {
                $changed = ($transform->run)($candidate);
                if ($changed->type !== 'document') {
                    throw new UnexpectedValueException('document transform did not return a document');
                }
                $beforeIds = self::authoredIdCounts($view, $opts);
                $afterIds = self::authoredIdCounts($changed, $opts);
                foreach ($afterIds as $id => $count) {
                    if ($count > 1 && $count > ($beforeIds[$id] ?? 0)) {
                        throw new UnexpectedValueException('document transform duplicated an authored id');
                    }
                }
                TreeReader::read(TreeEncoder::json($changed, null), self::readerExtensions($opts->extensions));
                $view = $changed;
            } catch (Throwable) {
                $entries[] = new Diagnostic(
                    'extension-transform-failed',
                    'warning',
                    'render-loss',
                    'Document transform failed: ' . $transform->name . '.',
                    '',
                );
            }
        }
        $facts = Resolver::resolve($view, $source, $opts, true)->facts;

        return [$view, $facts, $entries];
    }

    /**
     * @return array<string, int>
     */
    private static function authoredIdCounts(Node $doc, Options $opts): array
    {
        Walk::$rendered = $opts->rendered;
        $counts = [];
        $pending = [$doc];
        while ($pending !== []) {
            $node = array_pop($pending);
            $attrs = $node->f['attrs'] ?? null;
            if ($attrs instanceof Attrs && $attrs->id !== null) {
                $counts[$attrs->id] = ($counts[$attrs->id] ?? 0) + 1;
            }
            foreach (array_reverse(Walk::kids($node)) as $child) {
                $pending[] = $child;
            }
        }

        return $counts;
    }

    /**
     * @param string|null $source
     * @param array{code: string, node: \MarkupCarve\Carve\Node|null, slots: array<string, string|int>} $entry
     *
     * @return array{int, int}
     */
    private static function renderLossRange(?string $source, array $entry): array
    {
        $node = $entry['node'];
        if ($source === null || $node === null || $node->start < 0) {
            return [-1, -1];
        }
        $start = $node->start;
        $end = $node->end;
        if ($entry['code'] === 'carrier-attribute-dropped') {
            $key = $entry['slots']['key'] ?? null;
            $at = is_string($key) ? stripos($source, $key, $start) : false;
            if ($at !== false && $at < $end) {
                $start = $at;
                $close = strpos($source, '}', $at);
                $end = $close === false ? $at + strlen($key) : $close;
            }
        } elseif ($entry['code'] === 'destination-denied') {
            $url = $node->f[$node->type === 'image' ? 'src' : 'href'] ?? null;
            $at = is_string($url) ? strpos($source, $url, $start) : false;
            if ($at !== false && $at < $end) {
                $start = $at;
                $end = $at + strlen($url);
            }
        }

        return [$start, $end];
    }

    private static function checkRenderInput(string $source, Options $opts): void
    {
        $max = Profile::maxLength($opts->profile);
        if ($max !== null && strlen($source) > $max) {
            throw new InputError('the source exceeds the profile byte limit');
        }
    }

    /**
     * @param string $source
     * @param \MarkupCarve\Carve\Options $opts
     * @param bool $keep Keep the facts of the resolve for the renderer.
     *
     * @return array{0: \MarkupCarve\Carve\Node, 1: \MarkupCarve\Carve\Diagnostics\Findings, 2: \MarkupCarve\Carve\Resolve\Facts|null}
     */
    private static function parsed(string $source, Options $opts, bool $keep = false): array
    {
        $gc = self::collectorOff();
        try {
            [$doc, $findings, $hasInclude] = self::stages($source);
            self::expand($doc, $findings, $opts, $hasInclude);
            Extensions::apply($doc, $opts->extensions, self::divBody(...));
            $facts = Resolver::resolve($doc, $source, $opts, $keep, $findings)->facts;

            return [$doc, $findings, $facts];
        } finally {
            if ($gc) {
                gc_enable();
            }
        }
    }

    /**
     * C2-X-026: the canonical spelling of the blocks of a div, for the source record of an extension.
     *
     * @param \MarkupCarve\Carve\Node $div
     *
     * @return string
     */
    private static function divBody(Node $div): string
    {
        $blocks = (new TreeEncoder())->encode($div, null)['children'] ?? [];

        return Writer::writeBlocks(is_array($blocks) ? $blocks : []);
    }

    /**
     * The tree is acyclic and freed whole; the cycle collector would rescan it on every allocation
     * burst, which made large documents superlinear. The caller restores the state.
     *
     * @return bool Whether the collector was on.
     */
    private static function collectorOff(): bool
    {
        $gc = gc_enabled();
        gc_disable();

        return $gc;
    }

    /**
     * Parse without resolving.
     *
     * @param string $source
     * @param array<string, string> $inheritedAbbreviations
     * @param bool $fileStart
     *
     * @return array{0: \MarkupCarve\Carve\Node, 1: \MarkupCarve\Carve\Diagnostics\Findings, 2: bool}
     */
    private static function stages(string $source, array $inheritedAbbreviations = [], bool $fileStart = true): array
    {
        $sink = new TreeSink($source);
        (new BlockParser($sink))->parse($source, $fileStart);
        $doc = $sink->root;
        assert($doc !== null);
        $ctx = $sink->hasDefinition ? InlineContext::collect($doc, $source) : new InlineContext();
        foreach ($inheritedAbbreviations as $key => $expansion) {
            $ctx->abbreviations[$key] ??= $expansion;
        }
        InlineRunner::run($doc, $source, $ctx);
        if (str_contains($source, "\0") || !mb_check_encoding($source, 'UTF-8')) {
            NulReplacer::apply($doc);
        }

        return [$doc, $sink->findings, $sink->hasInclude];
    }

    private static function expand(Node $doc, Findings $findings, Options $opts, bool $hasInclude): void
    {
        if ($opts->includeResolver !== null) {
            $doc->f['stages'] = ['includeExpansion'];
        }
        if (!$hasInclude) {
            return;
        }
        (new Expander($opts, $findings, self::stages(...)))->expand($doc);
    }

    /**
     * Carve source to HTML; `render()` takes a tree as well.
     *
     * @param string $source
     * @param array<string, mixed> $options
     *
     * @throws \MarkupCarve\Carve\RenderError
     *
     * @return string
     */
    public static function toHtml(string $source, array $options = []): string
    {
        $opts = Options::from(self::normalize($options), ['parse', 'resolve', 'render']);
        self::checkRenderInput($source, $opts);
        $gc = self::collectorOff();
        try {
            [$doc, , $hasInclude] = self::stages($source);
            self::expand($doc, new Findings(), $opts, $hasInclude);
            Extensions::apply($doc, $opts->extensions, self::divBody(...));
            // The resolve hands its lists to the renderer, which then skips its own walk of the tree.
            $facts = Resolver::resolve($doc, $source, $opts, true)->facts;
        } finally {
            if ($gc) {
                gc_enable();
            }
        }

        [$html, $log] = HtmlRenderer::renderWithLog($doc, $opts, $source, $facts);
        if ($opts->strictLosses && $log->entries !== []) {
            throw new RenderError();
        }

        return $html;
    }

    /**
     * Carve source to the bare tree as JSON (C2-A-012), with positions.
     *
     * @param string $source
     * @param array<string, mixed> $options
     *
     * @return string
     */
    public static function toJson(string $source, array $options = []): string
    {
        return TreeEncoder::json(self::parse($source, $options), $source);
    }

    /**
     * C2-W-103, C2-W-109: the lint findings of a source, with the catalog messages, byte ranges and tree paths. Options:
     * `rules` (rule id to `on` or `off`), `platforms` (`['github']`) and `includes` (as for a parse), which lints the
     * included files too (C2-N-085). Nothing of lint runs unless this is called.
     *
     * @param string $source
     * @param array<string, mixed> $options
     *
     * @return array<\MarkupCarve\Carve\Diagnostics\Diagnostic>
     */
    public static function lint(string $source, array $options = []): array
    {
        return Linter::lint($source, self::normalize($options));
    }

    /**
     * The HTML importer (import-html.md): the tree, the canonical source and the diagnostics of an HTML string. Options:
     * `rawHtml` (C2-M-066) and `strictLosses` (C2-M-082). Needs `ext-dom`.
     *
     * @param string $html
     * @param array<string, mixed> $options
     *
     * @return \MarkupCarve\Carve\Import\ImportResult
     */
    public static function importHtml(string $html, array $options = []): ImportResult
    {
        return HtmlImporter::import($html, self::normalize($options));
    }

    /**
     * C2-N-083: the includes of a tree in tree order. Source is parsed first with `$options`, which then needs `includes`
     * for any include to be read.
     *
     * @param \MarkupCarve\Carve\Node|string $input A tree of `parse()` or `decodeTree()`, or Carve source.
     * @param array<string, mixed> $options
     *
     * @return array<array{path: string, from: string|null, file: string|null, read: bool}>
     */
    public static function includeDependencies(Node|string $input, array $options = []): array
    {
        $options = self::normalize($options);
        $opts = Options::from($options);
        $doc = is_string($input) ? self::parse($input, $options) : $input;
        $out = [];
        $stack = [[$doc, $opts->includeFile]];
        while ($stack !== []) {
            [$node, $from] = array_pop($stack);
            if ($node->type === 'include') {
                $file = is_string($node->f['file'] ?? null) ? $node->f['file'] : null;
                $out[] = [

                    'path' => is_string($node->f['path'] ?? null) ? $node->f['path'] : '',
                    'from' => $from,
                    'file' => $file,
                    'read' => $file !== null,
                ];
                if ($file !== null) {
                    $from = $file;
                }
            }
            foreach (array_reverse($node->children ?? []) as $child) {
                $stack[] = [$child, $from];
            }
        }

        return $out;
    }

    /**
     * C2-W-001: the canonical source of a tree; throws `CarveWriteError` when the tree holds what no source spells.
     *
     * @param \MarkupCarve\Carve\Node|array<string, mixed> $tree A tree, or the decoded AST JSON of `toJson()`.
     * @param array<string, mixed>|null $layout Sidecar facts by path (C2-L-046); null writes the defaults (C2-L-015).
     * @param array<string, mixed> $options
     *
     * @return string
     */
    public static function write(Node|array $tree, ?array $layout = null, array $options = []): string
    {
        return Writer::write(self::treeArray($tree), $layout, self::normalize($options));
    }

    /**
     * `write()` with the writer losses as diagnostics instead of a `CarveWriteError` (C2-W-134).
     *
     * @param \MarkupCarve\Carve\Node|array<string, mixed> $tree
     * @param array<string, mixed>|null $layout
     * @param array<string, mixed> $options
     *
     * @return \MarkupCarve\Carve\Writer\WriteResult
     */
    public static function writeWithDiagnostics(Node|array $tree, ?array $layout = null, array $options = []): WriteResult
    {
        return Writer::writeWithDiagnostics(self::treeArray($tree), $layout, self::normalize($options));
    }

    /**
     * C2-W-100: parse and write again.
     *
     * @param string $source
     * @param array<string, mixed> $options
     * @param array<string, mixed>|null $layout
     *
     * @return string
     */
    public static function format(string $source, array $options = [], ?array $layout = null): string
    {
        return Writer::format($source, self::normalize($options), $layout);
    }

    /**
     * `format()` with the writer losses as diagnostics (C2-W-134).
     *
     * @param string $source
     * @param array<string, mixed> $options
     * @param array<string, mixed>|null $layout
     *
     * @return \MarkupCarve\Carve\Writer\WriteResult
     */
    public static function formatWithDiagnostics(string $source, array $options = [], ?array $layout = null): WriteResult
    {
        return Writer::formatWithDiagnostics($source, self::normalize($options), $layout);
    }

    /**
     * C2-T-005 `render`: the HTML of Carve source or of a tree. A tree is a `Node` of `parse()` or `decodeTree()`, or the
     * decoded AST JSON as an array; it is validated (C2-A-188) and resolved again with the render options on a copy, so
     * the caller's tree is not changed.
     *
     * @param \MarkupCarve\Carve\Node|array<string, mixed>|string $input
     * @param array<string, mixed> $options
     *
     * @throws \MarkupCarve\Carve\RenderError A loss under `strictLosses`.
     *
     * @return string
     */
    public static function render(Node|array|string $input, array $options = []): string
    {
        if (is_string($input)) {
            return self::toHtml($input, $options);
        }
        $opts = Options::from(self::normalize($options), ['parse', 'resolve', 'render']);
        [$doc, $source, $facts] = self::resolvedTree($input, $opts);
        [$html, $log] = HtmlRenderer::renderWithLog($doc, $opts, $source, $facts);
        if ($opts->strictLosses && $log->entries !== []) {
            throw new RenderError();
        }

        return $html;
    }

    /**
     * C2-T-005 `importDocument`: the importer for `from` (only `html`, the default).
     *
     * @param string $input
     * @param array<string, mixed> $options `from`, `rawHtml`, `strictLosses`.
     *
     * @throws \MarkupCarve\Carve\OptionError
     *
     * @return \MarkupCarve\Carve\Import\ImportResult
     */
    public static function importDocument(string $input, array $options = []): ImportResult
    {
        $from = $options['from'] ?? 'html';
        unset($options['from']);
        if ($from !== 'html') {
            throw new OptionError('option from: unknown input format ' . (is_string($from) ? $from : get_debug_type($from)));
        }

        return self::importHtml($input, $options);
    }

    /**
     * C2-T-041: the line and column of C2-T-019 for a range that starts at the UTF-8 byte offset `$offset` of `$source`.
     *
     * @param string $source The source bytes, as `parse` takes them.
     * @param mixed $offset
     *
     * @throws \MarkupCarve\Carve\OptionError An offset outside the source, or inside one code point or one ill-formed
     *   sequence that a U+FFFD replaces (C2-B-218).
     *
     * @return array{line: int, column: int}
     */
    public static function positionOf(string $source, mixed $offset): array
    {
        $length = strlen($source);
        if (!is_int($offset) || $offset < 0 || $offset > $length) {
            throw new OptionError('option offset: ' . json_encode($offset) . ' is no offset into ' . $length . ' bytes');
        }
        $line = 1;
        $column = 1;
        $at = 0;
        while ($at < $offset) {
            if ($source[$at] === "\n") {
                $line++;
                $column = 1;
            } else {
                $column++;
            }
            $at += Utf8::unitLength($source, $at);
        }
        if ($at !== $offset) {
            throw new OptionError('option offset: ' . $offset . ' is inside the bytes of one code point');
        }

        return ['line' => $line, 'column' => $column];
    }

    /**
     * C2-A-012: the bare tree as JSON, without positions where the tree came from JSON without them.
     *
     * @param \MarkupCarve\Carve\Node $tree
     *
     * @return string
     */
    public static function encodeTree(Node $tree): string
    {
        return TreeEncoder::json($tree, self::sourceOf($tree));
    }

    /**
     * C2-A-012, C2-A-013: the tree in its envelope, for a storage or process boundary.
     *
     * @param \MarkupCarve\Carve\Node $tree
     *
     * @return string
     */
    public static function encodeEnvelope(Node $tree): string
    {
        $document = (new TreeEncoder())->encode($tree, self::sourceOf($tree));
        $envelope = ['astVersion' => $document['astVersion'] ?? '2.0', 'document' => $document];
        if (isset($document['extensions'])) {
            $envelope['extensions'] = $document['extensions'];
        }

        return json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }

    /**
     * C2-A-188: reads the JSON of a 2.0 tree or envelope.
     *
     * @param string $json
     * @param array<string, mixed> $options `extensions`, the registered extensions for the reader checks (C2-X-038).
     *
     * @return \MarkupCarve\Carve\Node
     */
    public static function decodeTree(string $json, array $options = []): Node
    {
        return TreeReader::read($json, self::treeOptions($options));
    }

    /**
     * C2-A-188: returns when the tree passes the schema and the reader checks, and throws the refusal otherwise.
     *
     * @param \MarkupCarve\Carve\Node|array<string, mixed> $tree
     * @param array<string, mixed> $options `extensions`.
     *
     * @return void
     */
    public static function validateTree(Node|array $tree, array $options = []): void
    {
        self::readTree($tree, self::treeOptions($options));
    }

    /**
     * C2-H-143: the plain text of a node or a node list of a tree, the text `RenderHost::$plainText` gives a renderer.
     * A block node gives the plain text of its children.
     *
     * @param \MarkupCarve\Carve\Node|array<\MarkupCarve\Carve\Node> $nodes
     *
     * @return string
     */
    public static function plainText(Node|array $nodes): string
    {
        return PlainText::of($nodes instanceof Node ? [$nodes] : PlainText::nodes($nodes));
    }

    /**
     * The cell grid of a `table` node with its spans filled in, for an output that cannot express rowspan and colspan:
     * per row in order (head, body, foot), the row, its group and, per column, the cell that occupies the slot. A cell
     * spanning columns or rows fills every slot it covers and a slot no cell reaches is null; every row has as many
     * columns as the widest.
     *
     * @param \MarkupCarve\Carve\Node $table
     *
     * @throws \MarkupCarve\Carve\OptionError For a node that is not a table.
     *
     * @return array<int, array{
     *     row: \MarkupCarve\Carve\Node,
     *     group: \MarkupCarve\Carve\Node,
     *     cells: array<int, \MarkupCarve\Carve\Node|null>,
     * }>
     */
    public static function tableGrid(Node $table): array
    {
        if ($table->type !== 'table') {
            throw new OptionError('tableGrid: not a table node but ' . $table->type);
        }

        return TableGrid::expand($table);
    }

    /**
     * The shortest code fence of `$marker` (three at least) that is longer than every run of it opening a line of
     * `$content`, so the content cannot close the fence.
     *
     * @param string $content
     * @param string $marker A backtick or a tilde.
     *
     * @return string
     */
    public static function safeCodeFence(string $content, string $marker = '`'): string
    {
        return CodeFence::safe($content, $marker);
    }

    /**
     * A writer registry with the `html` and `carve` targets and the exports `markdown`, `plain` and `ansi`; register
     * further `OutputWriter`s on it.
     *
     * @return \MarkupCarve\Carve\Output\WriterRegistry
     */
    public static function writers(): WriterRegistry
    {
        return new WriterRegistry([
            new HtmlWriter(),
            new CarveWriter(),
            new MarkdownExporter(),
            new PlainTextExporter(),
            new AnsiExporter(),
        ]);
    }

    /**
     * Writes a tree with the writer of `$target`, from `$registry` or from `writers()`.
     *
     * @param string $target
     * @param \MarkupCarve\Carve\Node $tree
     * @param array<string, mixed> $options
     * @param \MarkupCarve\Carve\Output\WriterRegistry|null $registry
     *
     * @return string
     */
    public static function writeTo(string $target, Node $tree, array $options = [], ?WriterRegistry $registry = null): string
    {
        return ($registry ?? self::writers())->write($target, $tree, $options);
    }

    /**
     * Expands `ExtensionProvider` items of `extensions` and wraps an `IncludeResolver` in `includes`.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private static function normalize(array $options): array
    {
        if (isset($options['extensions']) && is_array($options['extensions'])) {
            $list = [];
            foreach ($options['extensions'] as $item) {
                if ($item instanceof ExtensionProvider) {
                    foreach ($item->definitions() as $definition) {
                        $list[] = $definition;
                    }

                    continue;
                }
                $list[] = $item;
            }
            $options['extensions'] = $list;
        }
        $includes = $options['includes'] ?? null;
        if ($includes instanceof IncludeResolver) {
            $options['includes'] = $includes->resolve(...);
        } elseif (is_array($includes) && ($includes['resolver'] ?? null) instanceof IncludeResolver) {
            $includes['resolver'] = $includes['resolver']->resolve(...);
            $options['includes'] = $includes;
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{extensions?: array<\MarkupCarve\Carve\Extension\ExtensionDefinition|string>}
     */
    private static function treeOptions(array $options): array
    {
        return self::readerExtensions(Options::from(self::normalize($options), ['tree'])->extensions);
    }

    /**
     * The tree reader takes the definitions (for the standard tabs) and their versions by name (C2-X-003) in one list.
     *
     * @param array<\MarkupCarve\Carve\Extension\ExtensionDefinition> $definitions
     *
     * @return array{extensions?: array<\MarkupCarve\Carve\Extension\ExtensionDefinition|string>}
     */
    private static function readerExtensions(array $definitions): array
    {
        if ($definitions === []) {
            return [];
        }
        $list = $definitions;
        foreach ($definitions as $definition) {
            $list[$definition->name] = $definition->version;
        }

        return ['extensions' => $list];
    }

    private static function remember(Node $doc, string $source): Node
    {
        self::$sources ??= new WeakMap();
        self::$sources[$doc] = $source;

        return $doc;
    }

    /**
     * @param \MarkupCarve\Carve\Node|array<string, mixed> $tree
     * @param array<string, mixed> $options
     *
     * @return \MarkupCarve\Carve\Node
     */
    private static function readTree(Node|array $tree, array $options): Node
    {
        $json = $tree instanceof Node ? TreeEncoder::json($tree, self::sourceOf($tree))
            : json_encode($tree, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return TreeReader::read($json === false ? '' : $json, $options);
    }

    /**
     * A copy of a tree, resolved for `$opts`: a `Node` of `parse()` is copied with its source; any other tree is read
     * and checked first.
     *
     * @param \MarkupCarve\Carve\Node|array<string, mixed> $tree
     * @param \MarkupCarve\Carve\Options $opts
     *
     * @return array{0: \MarkupCarve\Carve\Node, 1: string|null, 2: \MarkupCarve\Carve\Resolve\Facts|null}
     */
    private static function resolvedTree(Node|array $tree, Options $opts): array
    {
        $source = $tree instanceof Node ? self::sourceOf($tree) : null;
        $doc = $source !== null ? Extensions::copy($tree) : self::readTree($tree, self::readerExtensions($opts->extensions));
        if ($source !== null) {
            self::checkRenderInput($source, $opts);
        }
        $gc = self::collectorOff();
        try {
            $facts = Resolver::resolve($doc, $source, $opts, true)->facts;
        } finally {
            if ($gc) {
                gc_enable();
            }
        }

        return [$doc, $source, $facts];
    }

    private static function sourceOf(Node $tree): ?string
    {
        return self::$sources !== null && isset(self::$sources[$tree]) ? self::$sources[$tree] : null;
    }

    /**
     * @param \MarkupCarve\Carve\Node|array<string, mixed> $tree
     *
     * @return array<string, mixed>
     */
    private static function treeArray(Node|array $tree): array
    {
        return $tree instanceof Node ? (new TreeEncoder())->encode($tree, self::sourceOf($tree)) : $tree;
    }
}
