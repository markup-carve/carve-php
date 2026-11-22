<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

use MarkupCarve\Carve\Block\LineShapes;
use MarkupCarve\Carve\Block\TableBuilder;
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Diagnostics\Diagnostic;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Options;

/**
 * The canonical writer (`writer.md`): a tree, and optionally the layout sidecar returned with it (C2-W-001), to Carve
 * source. Options: `alignTables` (C2-W-068), `normalize` (C2-W-127).
 */
final class Writer
{
    /**
     * @var array<string>
     */
    private const PAIRED_INLINES = [
        'superscript', 'subscript', 'emphasis', 'strong', 'underline', 'strike', 'highlight',
        'insert', 'delete', 'link', 'span', 'inline_footnote', 'inline_extension',
    ];

    /**
     * @var array<string>
     */
    private const BRACKETED_INLINES = ['link', 'image', 'span', 'inline_footnote', 'inline_extension'];

    /**
     * C2-W-134: the nearest source and the report.
     *
     * @param array<string, mixed> $tree A decoded 2.0 tree.
     * @param array<mixed>|null $layout A layout record (`source-layout.md`).
     * @param array<string, mixed> $options
     *
     * @return \MarkupCarve\Carve\Writer\WriteResult
     */
    public static function writeWithDiagnostics(array $tree, ?array $layout = null, array $options = []): WriteResult
    {
        $result = self::writeInternal($tree, $layout, $options, null);
        if ($result->diagnostics !== [] || !self::hasRoundRisk($tree)) {
            return $result;
        }
        $bare = self::bareCandidate($tree, $layout, $options);
        if (ReadbackRound::inspect($bare, $tree, $options)['equal']) {
            return $result;
        }
        $repaired = (new RepairPlanner($tree, $layout, $options))->repair();

        if ($repaired === null) {
            return new WriteResult($result->source, [
                new Diagnostic('writer-refused', 'warning', 'render-loss', 'Writer repair stopped for this block group', ''),
            ]);
        }

        return new WriteResult($repaired, []);
    }

    /**
     * Neighboring paired inlines can read each other's delimiters after their local writes.
     * Only those groups need a whole-group read-back on top of the local writer.
     *
     * @param array<mixed> $tree
     */
    private static function hasRoundRisk(array $tree): bool
    {
        return self::nodeHasRoundRisk($tree, false, false);
    }

    /**
     * @param array<mixed> $node
     * @param bool $pairedBefore
     * @param bool $bracketedBefore
     */
    private static function nodeHasRoundRisk(array $node, bool $pairedBefore, bool $bracketedBefore): bool
    {
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        $paired = 0;
        foreach ($children as $child) {
            if (is_array($child) && in_array($child['type'] ?? '', self::PAIRED_INLINES, true)) {
                $paired++;
                if ($paired >= 2) {
                    break;
                }
            }
        }
        $pairedHere = $pairedBefore || $paired >= 2;
        $bracketed = $bracketedBefore || in_array($node['type'] ?? '', self::BRACKETED_INLINES, true);
        if (
            $pairedHere && !$bracketed && ($node['type'] ?? '') === 'text'
            && is_string($node['value'] ?? null) && strpbrk($node['value'], '[`<{$:') !== false
        ) {
            return true;
        }
        foreach ($children as $child) {
            if (is_array($child) && self::nodeHasRoundRisk($child, $pairedHere, $bracketed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The source of a bare candidate and its text and inline-node source positions for C2-W-088.
     *
     * @param array<string, mixed> $tree
     * @param array<mixed>|null $layout
     * @param array<string, mixed> $options
     * @param array<string, array<int, true>> $escapes
     * @param bool $mark
     *
     * @return array{
     *     source: string,
     *     text: array<int, array{string, int}>,
     *     nodes: array<int, array{string, string, int, int}>,
     *     ranges: array<string, array{int, int}>
     * }
     */
    public static function bareCandidate(
        array $tree,
        ?array $layout = null,
        array $options = [],
        array $escapes = [],
        bool $mark = true,
    ): array {
        if (!$mark) {
            $trace = new RepairTrace($escapes, false);
            $result = self::writeInternal($tree, $layout, $options, $trace);

            return $trace->decode($result->source);
        }
        $plain = new RepairTrace($escapes, false);
        $bare = self::writeInternal($tree, $layout, $options, $plain)->source;
        do {
            $marker = "\x01" . bin2hex(random_bytes(16)) . "\x03";
        } while (str_contains($bare, $marker));
        $trace = new RepairTrace($escapes, true, $marker);
        $result = self::writeInternal($tree, $layout, $options, $trace);

        return RepairAlignment::onto($trace->decode($result->source), $bare);
    }

    /**
     * @param array<string, mixed> $tree
     * @param array<mixed>|null $layout
     * @param array<string, mixed> $options
     * @param \MarkupCarve\Carve\Writer\RepairTrace|null $trace
     */
    private static function writeInternal(array $tree, ?array $layout, array $options, ?RepairTrace $trace): WriteResult
    {
        $w = new State(self::facts($layout), ($options['alignTables'] ?? false) === true, ($options['normalize'] ?? false) === true);
        $w->source = ControlLines::source($layout);
        $w->repairTrace = $trace;
        $w->extensions = is_array($options['extensions'] ?? null) ? $options['extensions'] : [];
        $w->labels = self::linkKeys($options);
        $children = is_array($tree['children'] ?? null) ? $tree['children'] : [];
        self::collect($children, $w);
        self::rootLosses($tree, $children, $w);
        $w->beginRepairDocument($children);
        (new Blocks($w))->blocks($children, '/children', '');
        BlankLines::lose($children, '/children', $w);
        $w->loseLineEndCarriageReturns();
        while ($w->lines !== [] && end($w->lines) === '') {
            array_pop($w->lines);
        }
        if ($w->lines === []) {
            return new WriteResult('', $w->losses);
        }
        $source = implode("\n", $w->lines) . "\n";
        $source = ControlLines::keepAbove($source, ControlLines::source($layout), $w->extensions);
        // C2-W-099: a first block that would read as another tree on line 1 stands below one blank line.
        $first = is_array($children[0] ?? null) ? $children[0] : [];
        $opener = LineShapes::frontmatter($source, 0, (int)strpos($source, "\n")) !== null;
        if ($opener && ($first['type'] ?? '') !== 'frontmatter') {
            $source = "\n" . $source;
        }
        // A text U+FEFF is a byte order mark only at byte zero. Keep it behind one blank line there.
        if (str_starts_with($source, "\u{FEFF}")) {
            $source = "\n" . $source;
        }

        return new WriteResult($source, $w->losses);
    }

    /**
     * C2-X-026: the canonical spelling of a list of blocks as a document body, final line feed included, `''` when
     * empty. The blocks are decoded AST JSON; their losses are in the diagnostic form.
     *
     * @param array<mixed> $blocks
     * @param array<string, mixed> $options
     *
     * @return string
     */
    public static function writeBlocks(array $blocks, array $options = []): string
    {
        return self::writeBlocksWithDiagnostics($blocks, $options)->source;
    }

    /**
     * @param array<mixed> $blocks
     * @param array<string, mixed> $options
     *
     * @return \MarkupCarve\Carve\Writer\WriteResult
     */
    public static function writeBlocksWithDiagnostics(array $blocks, array $options = []): WriteResult
    {
        $document = ['type' => 'document', 'astVersion' => '2.0', 'children' => array_values($blocks)];

        return self::writeWithDiagnostics($document, null, $options);
    }

    /**
     * The sidecar entries by path (C2-L-010).
     *
     * @param array<mixed>|null $layout
     *
     * @return array<string, array<mixed>>
     */
    private static function facts(?array $layout): array
    {
        $facts = [];
        $used = array_fill_keys([
            'bare', 'fenced', 'hardBreakOpener', 'headerRows', 'sourceFormat', 'wraps', 'rawRef', 'startByte',
        ], true);
        foreach (is_array($layout['nodes'] ?? null) ? $layout['nodes'] : [] as $entry) {
            if (
                is_array($entry) && is_string($entry['path'] ?? null)
                && array_intersect_key($entry, $used) !== []
            ) {
                $facts[$entry['path']] = $entry;
            }
        }

        return $facts;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, true>
     */
    private static function linkKeys(array $options): array
    {
        return is_array($options['linkKeys'] ?? null) ? array_fill_keys(array_keys($options['linkKeys']), true) : [];
    }

    /**
     * C2-W-134: the source alone; a loss throws.
     *
     * @param array<string, mixed> $tree
     * @param array<mixed>|null $layout
     * @param array<string, mixed> $options
     *
     * @throws \MarkupCarve\Carve\Writer\CarveWriteError
     *
     * @return string
     */
    public static function write(array $tree, ?array $layout = null, array $options = []): string
    {
        $result = self::writeWithDiagnostics($tree, $layout, $options);
        if ($result->diagnostics !== []) {
            throw new CarveWriteError($result->diagnostics);
        }

        return $result->source;
    }

    /**
     * `carve fmt`: parse with the sidecar, then write (C2-W-001, C2-W-087).
     *
     * @param string $source
     * @param array<string, mixed> $options
     * @param array<mixed>|null $layout A record to use in place of the one this parse gives.
     *
     * @return \MarkupCarve\Carve\Writer\WriteResult
     */
    public static function formatWithDiagnostics(string $source, array $options = [], ?array $layout = null): WriteResult
    {
        Options::from($options, ['format']);
        $parseOptions = isset($options['extensions']) ? ['extensions' => $options['extensions']] : [];
        $parsed = TableBuilder::compactEmptyParagraphs(static fn () => Carve::parseWithLayout($source, $parseOptions));
        $tree = TreeEncoder::decodedForWriter($parsed->tree);
        $writeLayout = $layout ?? $parsed->layout;
        // The read-back parser may need the same memory as the initial parse.
        // Release its object tree before the writer begins that repair work.
        unset($parsed);

        return self::writeWithDiagnostics($tree, $writeLayout, $options);
    }

    /**
     * @param string $source
     * @param array<string, mixed> $options
     * @param array<mixed>|null $layout
     *
     * @throws \MarkupCarve\Carve\Writer\CarveWriteError
     *
     * @return string
     */
    public static function format(string $source, array $options = [], ?array $layout = null): string
    {
        $result = self::formatWithDiagnostics($source, $options, $layout);
        if ($result->diagnostics !== []) {
            throw new CarveWriteError($result->diagnostics);
        }

        return $result->source;
    }

    /**
     * @param array<mixed> $nodes
     * @param \MarkupCarve\Carve\Writer\State $w
     *
     * @return void
     */
    private static function collect(array $nodes, State $w): void
    {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            if (($node['type'] ?? '') === 'abbreviation_def' && is_string($node['key'] ?? null)) {
                $w->abbreviations[$node['key']] = true;
            }
            if (($node['type'] ?? '') === 'link_reference_definition' && is_string($node['key'] ?? null)) {
                $w->labels[$node['key']] = true;
            }
            if (is_array($node['children'] ?? null)) {
                self::collect($node['children'], $w);
            }
        }
    }

    /**
     * C2-W-011, C2-W-117: root `metadata` has no spelling, except `fields` beside a frontmatter node.
     *
     * @param array<string, mixed> $tree
     * @param array<mixed> $children
     * @param \MarkupCarve\Carve\Writer\State $w
     *
     * @return void
     */
    private static function rootLosses(array $tree, array $children, State $w): void
    {
        $metadata = is_array($tree['metadata'] ?? null) ? $tree['metadata'] : [];
        $frontmatter = false;
        foreach ($children as $child) {
            $frontmatter = $frontmatter || (is_array($child) && ($child['type'] ?? '') === 'frontmatter');
        }
        foreach (array_keys($metadata) as $field) {
            if ($field !== 'fields' || !$frontmatter) {
                $w->lose('', 'document', 'metadata/' . $field);
            }
        }
        foreach (['attrs', 'lang', 'dir'] as $field) {
            if (isset($tree[$field])) {
                $w->lose('', 'document', $field);
            }
        }
        foreach (is_array($tree['data'] ?? null) ? array_keys($tree['data']) : [] as $name) {
            if (!in_array($name, ['carve.source', 'carve.style', 'carve.annotation'], true)) {
                $w->lose('', 'document', 'data/' . $name);
            }
        }
    }
}
