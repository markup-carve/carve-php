<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2;

use MarkupCarve\Carve2\Block\BlockParser;
use MarkupCarve\Carve2\Block\TreeSink;
use MarkupCarve\Carve2\Diagnostics\Diagnostic;
use MarkupCarve\Carve2\Diagnostics\Findings;
use MarkupCarve\Carve2\Extension\Extensions;
use MarkupCarve\Carve2\Html\HtmlRenderer;
use MarkupCarve\Carve2\Html\Profile;
use MarkupCarve\Carve2\Import\HtmlImporter;
use MarkupCarve\Carve2\Import\ImportResult;
use MarkupCarve\Carve2\Include\Expander;
use MarkupCarve\Carve2\Inline\InlineContext;
use MarkupCarve\Carve2\Inline\InlineRunner;
use MarkupCarve\Carve2\Json\TreeEncoder;
use MarkupCarve\Carve2\Layout\LayoutBuilder;
use MarkupCarve\Carve2\Layout\LayoutReader;
use MarkupCarve\Carve2\Lint\Linter;
use MarkupCarve\Carve2\Resolve\Resolver;
use MarkupCarve\Carve2\Writer\Writer;
use MarkupCarve\Carve2\Writer\WriteResult;

/**
 * The public entry: parse (source to tree), resolve, render (tree to HTML).
 */
final class Carve2
{
    /**
     * Parse and resolve: the tree of a source.
     *
     * @param string $source
     * @param array<string, mixed> $options
     *
     * @return \MarkupCarve\Carve2\Node
     */
    public static function parse(string $source, array $options = []): Node
    {
        return self::parsed($source, Options::from($options, ['parse', 'resolve']))[0];
    }

    /**
     * Parse and resolve, with the entries of the parse channel (C2-A-173).
     *
     * @param string $source
     * @param array<string, mixed> $options
     *
     * @return \MarkupCarve\Carve2\ParseResult
     */
    public static function parseWithDiagnostics(string $source, array $options = []): ParseResult
    {
        [$doc, $findings] = self::parsed($source, Options::from($options, ['parse', 'resolve']));

        return new ParseResult($doc, $findings->finish($doc));
    }

    /**
     * Parse and resolve, with the source-layout record of the input (C2-L-001, C2-L-003: the tree is the same).
     *
     * @param string $source
     * @param array<string, mixed> $options
     *
     * @return \MarkupCarve\Carve2\LayoutResult
     */
    public static function parseWithLayout(string $source, array $options = []): LayoutResult
    {
        [$doc, $findings] = self::parsed($source, Options::from($options, ['parse', 'resolve']));

        return new LayoutResult($doc, LayoutBuilder::build($source, $doc), $findings->finish($doc));
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
     * Render, with the entries of the parse and the render-loss channels (C2-A-173).
     *
     * @param string $source
     * @param array<string, mixed> $options
     *
     * @return \MarkupCarve\Carve2\RenderResult
     */
    public static function renderWithDiagnostics(string $source, array $options = []): RenderResult
    {
        $opts = Options::from($options, ['parse', 'resolve', 'render']);
        self::checkRenderInput($source, $opts);
        [$doc, $findings, $facts] = self::parsed($source, $opts, true);
        [$html, $log] = HtmlRenderer::renderWithLog($doc, $opts, $source, $facts);
        $entries = $findings->finish($doc);
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

        return new RenderResult($opts->strictLosses && $log->entries !== [] ? '' : $html, $entries);
    }

    /**
     * @param string $source
     * @param array{code: string, node: \MarkupCarve\Carve2\Node|null, slots: array<string, string|int>} $entry
     *
     * @return array{int, int}
     */
    private static function renderLossRange(string $source, array $entry): array
    {
        $node = $entry['node'];
        if ($node === null || $node->start < 0) {
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
     * @param \MarkupCarve\Carve2\Options $opts
     * @param bool $keep Keep the facts of the resolve for the renderer.
     *
     * @return array{0: \MarkupCarve\Carve2\Node, 1: \MarkupCarve\Carve2\Diagnostics\Findings, 2: \MarkupCarve\Carve2\Resolve\Facts|null}
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
     * @param \MarkupCarve\Carve2\Node $div
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
     * @return array{0: \MarkupCarve\Carve2\Node, 1: \MarkupCarve\Carve2\Diagnostics\Findings, 2: bool}
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
     * @param string $source
     * @param array<string, mixed> $options
     *
     * @throws \MarkupCarve\Carve2\RenderError
     *
     * @return string
     */
    public static function toHtml(string $source, array $options = []): string
    {
        $opts = Options::from($options, ['parse', 'resolve', 'render']);
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
     * @return array<\MarkupCarve\Carve2\Diagnostics\Diagnostic>
     */
    public static function lint(string $source, array $options = []): array
    {
        return Linter::lint($source, $options);
    }

    /**
     * The HTML importer (import-html.md): the tree, the canonical source and the diagnostics of an HTML string. Options:
     * `rawHtml` (C2-M-066) and `strictLosses` (C2-M-082). Needs `ext-dom`.
     *
     * @param string $html
     * @param array<string, mixed> $options
     *
     * @return \MarkupCarve\Carve2\Import\ImportResult
     */
    public static function importHtml(string $html, array $options = []): ImportResult
    {
        return HtmlImporter::import($html, $options);
    }

    /**
     * @param string $source
     * @param array<string, mixed> $options
     *
     * @return array<array{path: string, from: string|null, file: string|null, read: bool}>
     */
    public static function includeDependencies(string $source, array $options = []): array
    {
        $opts = Options::from($options);
        $doc = self::parse($source, $options);
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
     * @param array<string, mixed> $tree The decoded AST JSON of `toJson()`.
     * @param array<string, mixed>|null $layout Sidecar facts by path (C2-L-046); null writes the defaults (C2-L-015).
     * @param array<string, mixed> $options
     *
     * @return string
     */
    public static function write(array $tree, ?array $layout = null, array $options = []): string
    {
        return Writer::write($tree, $layout, $options);
    }

    /**
     * @param array<string, mixed> $tree
     * @param array<string, mixed>|null $layout
     * @param array<string, mixed> $options
     *
     * @return \MarkupCarve\Carve2\Writer\WriteResult
     */
    public static function writeWithDiagnostics(array $tree, ?array $layout = null, array $options = []): WriteResult
    {
        return Writer::writeWithDiagnostics($tree, $layout, $options);
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
        return Writer::format($source, $options, $layout);
    }

    /**
     * @param string $source
     * @param array<string, mixed> $options
     * @param array<string, mixed>|null $layout
     *
     * @return \MarkupCarve\Carve2\Writer\WriteResult
     */
    public static function formatWithDiagnostics(string $source, array $options = [], ?array $layout = null): WriteResult
    {
        return Writer::formatWithDiagnostics($source, $options, $layout);
    }
}
