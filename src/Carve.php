<?php

declare(strict_types=1);

namespace MarkupCarve\Carve;

use Composer\InstalledVersions;
use InvalidArgumentException;
use MarkupCarve\Carve\Ast\EditorSession;
use MarkupCarve\Carve\Block\BlockParser;
use MarkupCarve\Carve\Block\TreeSink;
use MarkupCarve\Carve\Diagnostics\Cap;
use MarkupCarve\Carve\Diagnostics\Diagnostic;
use MarkupCarve\Carve\Diagnostics\Findings;
use MarkupCarve\Carve\Export\AnsiExporter;
use MarkupCarve\Carve\Export\CodeFence;
use MarkupCarve\Carve\Export\MarkdownExporter;
use MarkupCarve\Carve\Export\PlainTextExporter;
use MarkupCarve\Carve\Extension\CopyLedger;
use MarkupCarve\Carve\Extension\DocumentTransform;
use MarkupCarve\Carve\Extension\ExtensionDefinition;
use MarkupCarve\Carve\Extension\ExtensionProvider;
use MarkupCarve\Carve\Extension\Extensions;
use MarkupCarve\Carve\Generated\Tables;
use MarkupCarve\Carve\Html\CoreLayout;
use MarkupCarve\Carve\Html\HeadingShift;
use MarkupCarve\Carve\Html\HtmlRenderer;
use MarkupCarve\Carve\Html\Profile;
use MarkupCarve\Carve\Import\HtmlImporter;
use MarkupCarve\Carve\Import\HubImporter;
use MarkupCarve\Carve\Import\ImportResult;
use MarkupCarve\Carve\Include\Expander;
use MarkupCarve\Carve\Include\IncludeResolver;
use MarkupCarve\Carve\Include\Selection;
use MarkupCarve\Carve\Inline\Chars;
use MarkupCarve\Carve\Inline\Finisher;
use MarkupCarve\Carve\Inline\InlineContext;
use MarkupCarve\Carve\Inline\InlineRunner;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeMigration;
use MarkupCarve\Carve\Json\TreePatch;
use MarkupCarve\Carve\Json\TreeReader;
use MarkupCarve\Carve\Json\ViewCheck;
use MarkupCarve\Carve\Layout\LayoutBuilder;
use MarkupCarve\Carve\Layout\LayoutReader;
use MarkupCarve\Carve\Lint\Linter;
use MarkupCarve\Carve\Output\CarveWriter;
use MarkupCarve\Carve\Output\HtmlWriter;
use MarkupCarve\Carve\Output\WriterRegistry;
use MarkupCarve\Carve\Resolve\PlainText;
use MarkupCarve\Carve\Resolve\Resolver;
use MarkupCarve\Carve\Resolve\Slug;
use MarkupCarve\Carve\Resolve\TableGrid;
use MarkupCarve\Carve\Resolve\Walk;
use MarkupCarve\Carve\Snippet\Expander as SnippetExpander;
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
     * The specification this engine implements, and the commit of markup-carve/carve it pins.
     *
     * @var array{version: string, commit: string}
     */
    public const SPEC_VERSION = Tables::SPEC_VERSION;

    /**
     * The node types of the pinned tree schema: those a document holds as blocks, and those a paragraph holds.
     *
     * @var array{block: array<string>, inline: array<string>}
     */
    public const NODE_TYPES = Tables::NODE_TYPES;

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
        $opts = Options::from(self::normalize($options), ['parse', 'resolve']);
        [$doc, $findings] = self::parsed($source, $opts);
        [$entries, $suppressed] = Cap::apply($findings->finish($doc), $opts, false);

        return new LayoutResult(self::remember($doc, $source), LayoutBuilder::build($source, $doc), $entries, $suppressed);
    }

    public static function supportsIncrementalParagraphReuse(): bool
    {
        return true;
    }

    /**
     * Start a source-authoritative editor session with byte-range edits.
     *
     * @param string $source
     * @param array<string, mixed> $options
     */
    public static function createEditorSession(string $source, array $options = []): EditorSession
    {
        return new EditorSession($source, $options);
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
        $transforms = [...self::registeredTransforms($opts->extensions), ...$transforms];
        if (is_string($input)) {
            $source = $input;
            self::checkRenderInput($source, $opts);
            [$doc, $findings, $facts] = self::parsed($source, $opts, true);
        } else {
            [$doc, $source, $facts] = self::resolvedTree($input, $opts);
            $findings = new Findings();
        }
        $shiftAbove = HeadingShift::apply($doc, $opts->headingShift, $opts->rendered);
        $shiftPath = $shiftAbove !== null ? (Findings::paths($doc)[$shiftAbove] ?? null) : null;
        $transformEntries = [];
        $findingsRoot = $doc;
        if ($transforms !== []) {
            SnippetExpander::markDefinitions($doc);
            [$view, $transformEntries] = self::transformWithoutCollector($doc, $opts, $transforms);
            // The parse findings name nodes of the resolved document, which a transformed view does not hold.
            $findingsRoot = $view ?? new Node('document');
            if ($view !== null) {
                SnippetExpander::carry($doc, $view);
                $doc = $view;
                SnippetExpander::refuseTransformed($doc);
                $facts = Resolver::resolve($doc, $source, $opts, true)->facts;
            }
        }
        [$html, $log] = HtmlRenderer::renderWithLog($doc, $opts, $source, $facts, true, $shiftAbove, true);
        $entries = $findings->finish($findingsRoot);
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
            $path = $entry['code'] === HeadingShift::CODE ? $shiftPath : $loss->path;
            if ($source === null && $path !== null && $entry['field'] !== null) {
                $path .= '/' . str_replace(['~', '/'], ['~0', '~1'], $entry['field']);
            }
            $entries[] = new Diagnostic(
                $entry['code'],
                $severity,
                $entry['channel'],
                $entry['message'],
                $path,
                $loss->startOffset,
                $loss->endOffset,
                $loss->file,
            );
        }

        [$entries, $suppressed] = Cap::apply($entries, $opts, true);

        return new RenderResult($opts->strictLosses && $log->entries !== [] ? '' : $html, $entries, $suppressed);
    }

    /**
     * The HTML of a render that runs the registered document transforms; under `strictLosses` a loss, a failed
     * transform included, throws.
     *
     * @param \MarkupCarve\Carve\Node|array<string, mixed>|string $input
     * @param array<string, mixed> $options
     * @param \MarkupCarve\Carve\Options $opts
     *
     * @throws \MarkupCarve\Carve\RenderError
     *
     * @return string
     */
    private static function transformedHtml(Node|array|string $input, array $options, Options $opts): string
    {
        $result = self::renderDetailed($input, [], $options);
        $lost = array_values(array_filter($result->diagnostics, static fn (Diagnostic $d): bool => $d->channel === 'render-loss'));
        if ($lost !== [] && $opts->strictLosses) {
            throw new RenderError($lost);
        }

        return $result->html;
    }

    /**
     * C2-X-053: the document transforms of the registered extensions, in registration order.
     *
     * @param array<mixed> $extensions
     *
     * @return array<\MarkupCarve\Carve\Extension\DocumentTransform>
     */
    private static function registeredTransforms(array $extensions): array
    {
        $transforms = [];
        foreach ($extensions as $extension) {
            if ($extension instanceof ExtensionDefinition && $extension->documentTransform !== null) {
                $transforms[] = new DocumentTransform($extension->name, $extension->documentTransform);
            }
        }

        return $transforms;
    }

    /**
     * `applyDocumentTransforms()` with the cycle collector off: the view copies are acyclic, and a collector run
     * on the copies of a large document costs more than the transforms.
     *
     * @param \MarkupCarve\Carve\Node $doc
     * @param \MarkupCarve\Carve\Options $opts
     * @param array<mixed> $transforms
     *
     * @return array{\MarkupCarve\Carve\Node|null, array<\MarkupCarve\Carve\Diagnostics\Diagnostic>}
     */
    private static function transformWithoutCollector(Node $doc, Options $opts, array $transforms): array
    {
        $gc = self::collectorOff();
        try {
            return self::applyDocumentTransforms($doc, $opts, $transforms);
        } finally {
            if ($gc) {
                gc_enable();
            }
        }
    }

    /**
     * C2-X-055: a view a transform gives back unchanged is not checked again, since every node it kept passed the same
     * checks before the transform ran.
     *
     * @param \MarkupCarve\Carve\Node $doc
     * @param \MarkupCarve\Carve\Options $opts
     * @param array<mixed> $transforms
     *
     * @return array{\MarkupCarve\Carve\Node|null, array<\MarkupCarve\Carve\Diagnostics\Diagnostic>} The final view,
     *   or null when no transform changed the resolved document, and the failures.
     */
    private static function applyDocumentTransforms(Node $doc, Options $opts, array $transforms): array
    {
        $view = $doc;
        $changedView = false;
        $entries = [];
        $beforeIds = null;
        foreach ($transforms as $transform) {
            if (!$transform instanceof DocumentTransform) {
                throw new OptionError('document transform: invalid registration');
            }
            $candidate = Extensions::copy($view);
            try {
                [$changed, $copies] = CopyLedger::run($transform->run, $candidate);
                if ($changed->type !== 'document') {
                    throw new UnexpectedValueException('document transform did not return a document');
                }
                if (Extensions::same($changed, $view)) {
                    $view = $changed;

                    continue;
                }
                $beforeIds ??= self::authoredIdCounts($view, $opts);
                $afterIds = self::authoredIdCounts($changed, $opts);
                foreach ($afterIds as $id => $count) {
                    if ($count > 1 && $count > ($beforeIds[$id] ?? 0)) {
                        throw new UnexpectedValueException('document transform duplicated an authored id');
                    }
                }
                $reader = self::readerExtensions($opts->extensions);
                if (!ViewCheck::passes($changed, $reader, $copies)) {
                    TreeReader::checkTreeOf($changed, $reader);
                }
                $view = $changed;
                $changedView = true;
                $beforeIds = $afterIds;
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

        return [$changedView ? $view : null, $entries];
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
            // A count needs no document order.
            array_push($pending, ...Walk::kids($node));
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
        $abbreviations = Finisher::$abbreviations;
        try {
            [$doc, $findings, $hasInclude] = self::stages($source);
            self::expand($doc, $findings, $opts, $hasInclude);
            if ($opts->extensions !== []) {
                $linkKeys = self::linkKeysByFile($doc);
                Extensions::apply(
                    $doc,
                    $opts->extensions,
                    static fn (Node $div): string => self::divBody($div, $linkKeys[$div->file ?? ''] ?? []),
                );
            }
            if ($keep) {
                SnippetExpander::expand($doc, $opts->roundtrip, $opts->extensions === [] ? $source : null);
            }
            $facts = Resolver::resolve($doc, $source, $opts, $keep, $findings)->facts;
            // Only an abbreviation of the parse or a node of an extension can carry an expansion.
            if (!$keep && ($opts->extensions !== [] || Finisher::$abbreviations !== $abbreviations)) {
                TreeEncoder::limitParsedExpansions($doc, strlen($source));
            }

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
     * @param array<string, true> $linkKeys
     *
     * @return string
     */
    private static function divBody(Node $div, array $linkKeys): string
    {
        $blocks = (new TreeEncoder())->encode($div, null)['children'] ?? [];

        return Writer::writeBlocks(is_array($blocks) ? $blocks : [], ['linkKeys' => $linkKeys]);
    }

    /**
     * The inline pass of each included file sees its own definitions.
     *
     * @return array<string, array<string, true>>
     */
    private static function linkKeysByFile(Node $doc): array
    {
        $byFile = [];
        $stack = [$doc];
        while ($stack !== []) {
            $node = array_pop($stack);
            if ($node->type === 'link_reference_definition' && is_string($node->f['key'] ?? null)) {
                $byFile[$node->file ?? ''][Chars::fold($node->f['key'])] = true;
            }
            foreach ($node->children ?? [] as $child) {
                $stack[] = $child;
            }
        }

        return $byFile;
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
        if (self::registeredTransforms($opts->extensions) !== []) {
            return self::transformedHtml($source, $options, $opts);
        }
        self::checkRenderInput($source, $opts);
        if ($options === [] && $opts->extensions === []) {
            $html = CoreLayout::render($source);
            if ($html !== null) {
                return $html;
            }
        }
        $gc = self::collectorOff();
        try {
            [$doc, , $hasInclude] = self::stages($source);
            self::expand($doc, new Findings(), $opts, $hasInclude);
            if ($opts->extensions !== []) {
                $linkKeys = self::linkKeysByFile($doc);
                Extensions::apply(
                    $doc,
                    $opts->extensions,
                    static fn (Node $div): string => self::divBody($div, $linkKeys[$div->file ?? ''] ?? []),
                );
            }
            SnippetExpander::expand($doc, $opts->roundtrip, $opts->extensions === [] ? $source : null);
            // The resolve hands its lists to the renderer, which then skips its own walk of the tree.
            $facts = Resolver::resolve($doc, $source, $opts, true)->facts;
            HeadingShift::apply($doc, $opts->headingShift, $opts->rendered);
        } finally {
            if ($gc) {
                gc_enable();
            }
        }

        [$html, $log] = HtmlRenderer::renderWithLog($doc, $opts, $source, $facts, true);
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
     * `rules` (rule id to `on` or `off`), `platforms` (`['github']`), `upgrade` (`true` turns on the 0.1 migration notices,
     * which also run for a document whose line-1 frontmatter declares `carve: 0.1`; C2-W-156), `headingIds` (C2-T-042) and
     * `includes`, which lints the included files too (C2-N-085). Nothing of lint runs unless this is called.
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
     * `adapter` (C2-M-102), `rawHtml` (C2-M-066), `labels` (C2-M-101), `listTable` (C2-M-112) and `strictLosses`
     * (C2-M-082). Needs `ext-dom`.
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
     * C2-N-037 to C2-N-041: where the region `$name` of an included file's source lies, as an include directive in
     * `$mode` (`carve` or `code`) selects it. `start` and `end` are the one-based first and last selected line; an empty
     * region has `end` one less than `start`. Where the directive would report `include-region-invalid`, `ok` is false.
     *
     * @throws \InvalidArgumentException For a mode other than `carve` or `code`.
     *
     * @return array{ok: true, start: int, end: int}|array{ok: false, rule: 'include-region-invalid'}
     */
    public static function selectIncludeRegion(string $source, string $name, string $mode): array
    {
        if ($mode !== 'carve' && $mode !== 'code') {
            throw new InvalidArgumentException('Include region mode must be carve or code');
        }
        $range = Selection::regionLines($source, $name, $mode === 'code');

        return $range === null
            ? ['ok' => false, 'rule' => 'include-region-invalid']
            : ['ok' => true, 'start' => $range[0], 'end' => $range[1]];
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
        if (self::registeredTransforms($opts->extensions) !== []) {
            return self::transformedHtml($input, $options, $opts);
        }
        [$doc, $source, $facts] = self::resolvedTree($input, $opts);
        HeadingShift::apply($doc, $opts->headingShift, $opts->rendered);
        [$html, $log] = HtmlRenderer::renderWithLog($doc, $opts, $source, $facts, true);
        if ($opts->strictLosses && $log->entries !== []) {
            throw new RenderError();
        }

        return $html;
    }

    /**
     * Render the complete document before delivering it to the sink. A failed render never calls the sink.
     *
     * @param string $source
     * @param callable(string): void $sink
     * @param array<string, mixed> $options
     *
     * @return 'complete'
     */
    public static function tryRenderHtmlStreaming(string $source, callable $sink, array $options = []): string
    {
        $html = self::render($source, $options);
        $sink($html);

        return 'complete';
    }

    /**
     * C2-T-005 `importDocument`: the importer for `from`: `html` (the default), or `markdown`, `gfm` and `djot` through the
     * HTML hub. `rawHtml` applies to HTML only; a hub import always keeps the raw content of its input.
     *
     * @param string $input
     * @param array<string, mixed> $options `from`, `rawHtml`, `strictLosses`.
     *
     * @throws \MarkupCarve\Carve\OptionError
     * @throws \MarkupCarve\Carve\Import\ImportInputError ! An input the library or the HTML importer refuses, a missing
     *   library, or a Markdown inline block past 16,384 inline candidate positions.
     *
     * @return \MarkupCarve\Carve\Import\ImportResult
     */
    public static function importDocument(string $input, array $options = []): ImportResult
    {
        $from = $options['from'] ?? 'html';
        unset($options['from']);
        if ($from === 'html') {
            return self::importHtml($input, $options);
        }
        if (!is_string($from) || !in_array($from, HubImporter::FORMATS, true)) {
            throw new OptionError('option from: unknown input format ' . (is_string($from) ? $from : get_debug_type($from)));
        }
        $options = self::normalize($options);
        Options::from($options);

        return HubImporter::import($input, $from, $options);
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
     * The version of this package as Composer installed it, or null outside a Composer install.
     *
     * @return string|null
     */
    public static function version(): ?string
    {
        if (!class_exists(InstalledVersions::class) || !InstalledVersions::isInstalled('markup-carve/carve-php')) {
            return null;
        }

        return InstalledVersions::getPrettyVersion('markup-carve/carve-php');
    }

    /**
     * The id the `headingIds` option generates for a heading with the plain text `$text` (C2-H-022, C2-H-143 to
     * C2-H-148), or null under `off`. Each call stands alone: no `-2` suffix of a repeated id (C2-H-024).
     *
     * @param string $text
     * @param array<string, mixed> $options `headingIds`, `keep-case` unless given.
     *
     * @throws \MarkupCarve\Carve\OptionError
     *
     * @return string|null
     */
    public static function headingId(string $text, array $options = []): ?string
    {
        foreach (array_keys($options) as $name) {
            if ($name !== 'headingIds') {
                throw new OptionError('option ' . $name . ': not an option of headingId');
            }
        }
        $mode = Options::from($options)->headingIds;

        return $mode === 'off' ? null : Slug::of($text, $mode);
    }

    /**
     * The fingerprint of a source: `sha256:` and the SHA-256 of its bytes.
     *
     * @param string $source
     *
     * @return string
     */
    public static function sourceFingerprint(string $source): string
    {
        return TreePatch::fingerprint($source);
    }

    /**
     * The fingerprint of a tree: `sha256:` and the SHA-256 of `encodeTree($tree)`.
     *
     * @param \MarkupCarve\Carve\Node $tree
     *
     * @return string
     */
    public static function treeFingerprint(Node $tree): string
    {
        return TreePatch::fingerprint(self::encodeTree($tree));
    }

    /**
     * The JSON Patch (RFC 6902) that turns the encoded tree `$a` into the encoded tree `$b`, with RFC 6901 paths.
     * Object values are `stdClass`, as `json_decode()` gives them.
     *
     * @param \MarkupCarve\Carve\Node $a
     * @param \MarkupCarve\Carve\Node $b
     *
     * @return array<array<string, mixed>>
     */
    public static function diffTree(Node $a, Node $b): array
    {
        return TreePatch::diff(self::encodeTree($a), self::encodeTree($b));
    }

    /**
     * Applies a JSON Patch to the encoded tree and decodes the result; `$tree` is not changed.
     *
     * @param \MarkupCarve\Carve\Node $tree
     * @param array<mixed> $patch The operations, as arrays or `stdClass` objects.
     * @param array<string, mixed> $options `fingerprint`, the `treeFingerprint` of the tree the patch was made against,
     *   and `extensions` as for `decodeTree`.
     *
     * @throws \MarkupCarve\Carve\OptionError
     *
     * @return \MarkupCarve\Carve\Node
     */
    public static function applyPatch(Node $tree, array $patch, array $options = []): Node
    {
        $fingerprint = $options['fingerprint'] ?? null;
        if ($fingerprint !== null && !is_string($fingerprint)) {
            throw new OptionError('option fingerprint: not a string');
        }
        unset($options['fingerprint']);
        $reader = self::treeOptions($options);

        return TreePatch::apply(
            self::encodeTree($tree),
            $patch,
            $fingerprint,
            static fn (string $json): Node => TreeReader::read($json, $reader),
        );
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
     * @param array<string, mixed> $options `extensions` and optional `annotations`, the annotation-range sidecar (C2-T-005).
     *
     * @return \MarkupCarve\Carve\Node
     */
    public static function decodeTree(string $json, array $options = []): Node
    {
        return TreeReader::read($json, self::treeOptions($options));
    }

    /**
     * C2-A-176 to C2-A-180: migrate a stored 1.x or retired draft tree before strict 2.0 decoding.
     *
     * @param array<string, mixed>|string $tree A bare document or envelope, or its JSON spelling.
     * @param array<string, mixed> $options Optional original source for the source-layout record.
     *
     * @return array<string, mixed> The document, optional envelope, layout and migration diagnostics.
     */
    public static function migrateTree(array|string $tree, array $options = []): array
    {
        return TreeMigration::migrate($tree, $options);
    }

    /**
     * C2-A-188: returns when the tree passes the schema and the reader checks, and throws the refusal otherwise.
     *
     * @param \MarkupCarve\Carve\Node|array<string, mixed> $tree
     * @param array<string, mixed> $options `extensions` and optional `annotations`, the annotation-range sidecar (C2-T-005).
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
        $hasAnnotations = array_key_exists('annotations', $options);
        $annotations = $options['annotations'] ?? null;
        unset($options['annotations']);
        $reader = self::readerExtensions(Options::from(self::normalize($options), ['tree'])->extensions);
        if ($hasAnnotations) {
            $reader['annotations'] = $annotations;
        }

        return $reader;
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
        if ($tree instanceof Node && self::sourceOf($tree) === null) {
            return TreeReader::readNode($tree, $options);
        }
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
     * @param bool $expand Run the snippet expansion (C2-N-091); the editor bridge keeps the authored uses.
     *
     * @return array{0: \MarkupCarve\Carve\Node, 1: string|null, 2: \MarkupCarve\Carve\Resolve\Facts|null}
     */
    private static function resolvedTree(Node|array $tree, Options $opts, bool $expand = true): array
    {
        $source = $tree instanceof Node ? self::sourceOf($tree) : null;
        $doc = $source !== null ? Extensions::copy($tree) : self::readTree($tree, self::readerExtensions($opts->extensions));
        if ($source !== null) {
            self::checkRenderInput($source, $opts);
        }
        $gc = self::collectorOff();
        try {
            // A tree may have changed since its parse, so its source tells nothing about its uses.
            if ($expand) {
                SnippetExpander::expand($doc, $opts->roundtrip);
            }
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
