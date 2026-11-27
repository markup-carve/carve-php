<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

use Closure;
use MarkupCarve\Carve\Block\LineShapes;
use MarkupCarve\Carve\Block\TableBuilder;
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Diagnostics\Diagnostic;
use MarkupCarve\Carve\Json\TreeDepth;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeEquality;
use MarkupCarve\Carve\Json\TreeError;
use MarkupCarve\Carve\Lint\DataModel;
use MarkupCarve\Carve\Options;

/**
 * The canonical writer (`writer.md`): a tree, and optionally the layout sidecar returned with it (C2-W-001), to Carve
 * source. Options: `alignTables` (C2-W-068), `normalize` (C2-W-127).
 */
final class Writer
{
    /**
     * The marks of the paired spans whose braced opener in their text the local write leaves to the read-back.
     *
     * @var array<string, string>
     */
    private const BRACED_CLOSERS = [
        'superscript' => '^',
        'subscript' => ',',
        'underline' => '_',
        'strike' => '~',
        'highlight' => '=',
        'insert' => '+',
        'delete' => '-',
    ];

    /**
     * Emphasis and strong may be written braced, where a braced opener of their mark in their text takes the closer.
     *
     * @var array<string, string>
     */
    private const PAIRED_CLOSERS = ['emphasis' => '/', 'strong' => '*'];

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
     * The fields the writer does not spell as text.
     *
     * @var array<string, true>
     */
    private const NO_SPELLING = ['type' => true, 'astVersion' => true];

    /**
     * @var string
     */
    private const PUNCTUATION = '!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~';

    /**
     * @var string
     */
    private const PLAIN_CHARACTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789 ';

    /**
     * The source while `formatWithDiagnostics` writes the tree of its parse, which a read-back can match field for
     * field; a supplied tree may lack fields a parse adds, so its read-back is no proof of a misread.
     */
    private static ?string $parsedSource = null;

    /**
     * The fingerprint each read-back of the outer write gave, by source: the steps after the rounds read some of their
     * forms again. Each entry also holds whether the read came through `ReadbackRound` and whether it has a table cell,
     * the one node the two decodings spell apart. Null outside `writeWithDiagnostics()`.
     *
     * @var array<string, array{string, bool, bool}>|null
     */
    private static ?array $readPrints = null;

    /**
     * @var int The source bytes `$readPrints` keeps at most.
     */
    private const READ_PRINT_BYTES = 1_048_576;

    /**
     * `trimmedPrint()` of the last trees of the outer write, newest first: the steps after the rounds compare with the
     * print the rounds compared with. Null outside `writeWithDiagnostics()`.
     *
     * @var array<int, array{array<string, mixed>, array<string, mixed>, string}>|null
     */
    private static ?array $trimmedPrints = null;

    /**
     * @var int The trees `$trimmedPrints` keeps at most.
     */
    private const TRIMMED_PRINTS = 4;

    /**
     * The layout with its verbatim reference texts while the rounds write every reference from the tree (C2-W-075).
     *
     * @var array<mixed>|null
     */
    private static ?array $verbatimLayout = null;

    /**
     * Whether the write already reports a loss before its local write, so its read-back is skipped as carve-js skips
     * the check of a group with a loss.
     */
    private static bool $lossReported = false;

    /**
     * C2-W-134: `image-alt-unknown` is not among them.
     *
     * @var array<string, true>
     */
    public const REFUSING_CODES = ['field-unspellable' => true, 'structure-unspellable' => true, 'writer-refused' => true];

    /**
     * C2-M-074, C2-W-117: the fields no source spells on a node of the type, beside `dir`, which none spells.
     *
     * @var array<string, array<string>>
     */
    private const UNSPELLED_FIELDS = [
        'caption' => ['attrs', 'lang'],
        'definition_term' => ['attrs', 'lang'],
        'definition_description' => ['attrs', 'lang'],
    ];

    /**
     * The node fields `fieldLosses()` does not walk: they hold no node.
     *
     * @var array<string, true>
     */
    private const UNWALKED_FIELDS = TreeDepth::OPAQUE_FIELDS + ['attrs' => true, 'pos' => true, 'metadata' => true];

    /**
     * Whether a supplied tree the heuristics call safe is read back. The importer writes trees of its own build, whose
     * one-paragraph read-back does not fit the memory its gates allow (carve-php#5664, #5722).
     */
    private static bool $readsSupplied = true;

    /**
     * `Text::$recordedOwn` of the last local write.
     *
     * @var array<string, array<int, true>>
     */
    private static array $localOwn = [];

    /**
     * `Text::$caseFive` of the last local write.
     */
    private static bool $localCaseFive = false;

    /**
     * `Text::$gluedElsewhere` of the last local write.
     */
    private static bool $localGluedElsewhere = false;

    /**
     * `Text::$closesFence` of the last local write.
     */
    private static bool $localClosesFence = false;

    /**
     * Whether the last `bareSource()` placed a backslash of C2-W-125 itself.
     */
    private static bool $bareRepaired = false;

    /**
     * The writes of the current check, each as its source and its top-level group ranges.
     *
     * @var array<int, array{string, array<int, array{int, int, int, int, bool}>|null}>|null
     */
    private static ?array $captured = null;

    /**
     * Whether the current check writes the groups of `checkedGroups()` alone.
     */
    private static bool $inGroups = false;

    /**
     * The node types whose blocks a group check may leave out: none feeds the resolve stage (C2-A-133) or the parse
     * of another block. A key only reads a definition, which stays; an id, a caption or a long description would
     * feed it.
     *
     * @var array<string, true>
     */
    private const PLAIN = [
        'paragraph' => true,
        'block_quote' => true,
        'list' => true,
        'list_item' => true,
        'code_block' => true,
        'thematic_break' => true,
        'text' => true,
        'soft_break' => true,
        'hard_break' => true,
        'emphasis' => true,
        'strong' => true,
        'code' => true,
        'link' => true,
        'autolink' => true,
        'smart_punctuation' => true,
        'strike' => true,
        'underline' => true,
        'superscript' => true,
        'subscript' => true,
        'highlight' => true,
        'insert' => true,
        'delete' => true,
        'table' => true,
        'table_head' => true,
        'table_body' => true,
        'table_row' => true,
        'table_cell' => true,
        'table_column' => true,
        'cell_paragraph' => true,
        'definition_list' => true,
        'definition_term' => true,
        'definition_description' => true,
        'div' => true,
        'admonition' => true,
        'line_block' => true,
        'line' => true,
        'literal_inline' => true,
        'span' => true,
        'image' => true,
        'abbreviation' => true,
        'xref' => true,
    ];

    /**
     * C2-W-134: the nearest source and the report.
     *
     * @param array<string, mixed> $tree A decoded 2.0 tree.
     * @param array<mixed>|null $layout A layout record (`source-layout.md`).
     * @param array<string, mixed> $options
     * @param bool $readsSupplied False for the importer's own trees (`$readsSupplied`).
     *
     * @return \MarkupCarve\Carve\Writer\WriteResult
     */
    public static function writeWithDiagnostics(
        array $tree,
        ?array $layout = null,
        array $options = [],
        bool $readsSupplied = true,
    ): WriteResult {
        // A minimal empty document has no nodes or root fields to audit, and needs only C2-W-022's final line feed.
        if (
            $layout === null && $options === [] && count($tree) === 3 && ($tree['type'] ?? null) === 'document'
            && ($tree['astVersion'] ?? null) === '2.0' && ($tree['children'] ?? null) === []
        ) {
            return new WriteResult("\n", []);
        }

        // A write nested in another, as of an extension body, keeps its own group check.
        $captured = self::$captured;
        $inGroups = self::$inGroups;
        $lossReported = self::$lossReported;
        $outerReadsSupplied = self::$readsSupplied;
        $readPrints = self::$readPrints;
        $trimmedPrints = self::$trimmedPrints;
        self::$readPrints ??= [];
        self::$trimmedPrints ??= [];
        self::$readsSupplied = $readsSupplied && $outerReadsSupplied;
        self::$captured = null;
        self::$inGroups = false;
        try {
            $parsedSource = self::$parsedSource;
            [$losses, $fallbackNodes] = self::normalizedAttributeValueLosses($tree);
            self::$lossReported = $losses !== [];
            // C2-W-088, C2-W-075 (carve-js#5201): the rounds write every reference from the tree, so the escapes
            // outside a reference never depend on its authored spelling; the verbatim texts are taken after them.
            $verbatim = self::verbatimTexts($tree, $layout, $options);
            $writeLayout = $verbatim === [] ? $layout : self::withoutRawRefs($layout);
            $outerVerbatim = self::$verbatimLayout;
            self::$verbatimLayout = $verbatim === [] ? null : $layout;
            try {
                $written = ReadbackRound::memoized(static fn (): WriteResult => Blocks::withLeadMemo(
                    static fn (): WriteResult => WriteMemo::scoped(
                        static fn (): WriteResult => self::writeChecked($tree, $writeLayout, $options),
                    ),
                ));
            } finally {
                self::$verbatimLayout = $outerVerbatim;
            }
            // The loss is judged after the verbatim texts are restored, so a refused rounds form may still read back.
            if ($verbatim !== [] && ($written->diagnostics === [] || self::onlyRefused($written))) {
                $written = self::withVerbatimReferences($written, $verbatim, $tree, $layout, $options);
            }
            $written = self::withAuthoredBlock($written, $tree, $layout, $options);

            if ($written->diagnostics === [] && self::notePrunable($written->source)) {
                $print = self::trimmedPrint($tree, $layout, $options);
                $written = self::withPrunedNoteCarets($written, $print, $options, $parsedSource);
            }

            $written = self::withAttributeValueLosses($written, $losses, $fallbackNodes);

            return count($written->diagnostics) < 2 ? $written
                : new WriteResult($written->source, EntryOrder::sort($tree, $written->diagnostics));
        } finally {
            self::$captured = $captured;
            self::$inGroups = $inGroups;
            self::$lossReported = $lossReported;
            self::$readsSupplied = $outerReadsSupplied;
            self::$readPrints = $readPrints;
            self::$trimmedPrints = $trimmedPrints;
        }
    }

    /**
     * @var int Past this many trials of a pass, the authored-block fallback takes the rest of the pass at once (C2-W-133).
     */
    private const MAX_AUTHORED_TRIALS = 32;

    /**
     * C2-W-088 (carve#6813, carve#6846, carve#6848): where the repair stops, the leaf blocks whose subtree does not
     * read back take their authored source from the sidecar, cumulatively in document order, until the whole output
     * reads back; failing that, the remaining blocks whose spelling differs from their authored slice follow. A tree
     * without a sidecar `source` keeps the loss (C2-W-117). The fallback runs under `normalize` too (C2-W-127).
     *
     * @param \MarkupCarve\Carve\Writer\WriteResult $written
     * @param array<string, mixed> $tree
     * @param array<mixed>|null $layout
     * @param array<string, mixed> $options
     */
    private static function withAuthoredBlock(WriteResult $written, array $tree, ?array $layout, array $options): WriteResult
    {
        $authored = $layout['source'] ?? null;
        if (is_string($authored) && ($layout['encoding'] ?? null) === 'base64') {
            $authored = base64_decode($authored, true);
        }
        // Only the repair's own stop: a loss another clause reports (C2-W-016) stays.
        if (!is_string($authored) || !self::onlyRefused($written)) {
            return $written;
        }
        $parseOptions = isset($options['extensions']) ? ['extensions' => $options['extensions']] : [];
        $parsed = Carve::parseWithLayout($written->source, $parseOptions);
        $read = TreeEncoder::decodedForWriter($parsed->tree);
        $from = self::blockSpans($layout);
        $to = self::blockSpans($parsed->layout);
        $print = self::trimmedPrint($tree, $layout, $options);
        $failing = [];
        $rest = [];
        foreach (self::leafPaths($tree, '') as $path => $leaf) {
            if (!isset($from[$path], $to[$path])) {
                continue;
            }
            $block = self::authoredSlice($authored, $from[$path][0], $from[$path][1]);
            if (!TreeEquality::equal($leaf, self::nodeAt($read, $path) ?? [])) {
                $failing[$path] = $block;
            } elseif ($block !== substr($written->source, $to[$path][0], $to[$path][1] - $to[$path][0])) {
                $rest[$path] = $block;
            }
        }
        // Each replacement is kept for the trials after it, also across the passes.
        $taken = [];
        foreach ([$failing, $rest] as $pass) {
            $trials = 0;
            foreach ($pass as $path => $block) {
                $taken[$path] = $block;
                if (++$trials > self::MAX_AUTHORED_TRIALS && $trials < count($pass)) {
                    continue;
                }
                $trial = self::withBlocks($written->source, $to, $taken);
                if (self::readsBack($trial, $print, $options)) {
                    return new WriteResult($trial, []);
                }
            }
        }

        return $written;
    }

    private static function onlyRefused(WriteResult $written): bool
    {
        $codes = array_map(static fn (Diagnostic $d): string => $d->code, $written->diagnostics);

        return array_unique($codes) === ['writer-refused'];
    }

    /**
     * The authored source of a block as the writer writes it: a CR before an LF dropped (C2-B-010, C2-W-016), and no
     * space or tab at a line end (C2-W-022), which leaves a hard break its backslash alone (C2-W-079).
     */
    private static function authoredSlice(string $authored, int $from, int $to): string
    {
        $slice = str_replace("\r\n", "\n", substr($authored, $from, $to - $from));
        $next = $authored[$to] ?? "\n";
        if (str_ends_with($slice, "\r") && $next === "\n") {
            $slice = substr($slice, 0, -1);
        }
        $slice = (string)preg_replace('/[ \t]+(?=\n)/', '', $slice);

        return $next === "\n" || $next === "\r" ? rtrim($slice, " \t") : $slice;
    }

    /**
     * `$source` with the block at each path of `$taken` replaced, the spans of `$to` read in `$source`.
     *
     * @param string $source
     * @param array<string, array{int, int}> $to
     * @param array<string, string> $taken
     */
    private static function withBlocks(string $source, array $to, array $taken): string
    {
        $spans = [];
        foreach ($taken as $path => $block) {
            $spans[] = [$to[$path][0], $to[$path][1], $block];
        }
        usort($spans, static fn (array $a, array $b): int => [$a[0], $b[1]] <=> [$b[0], $a[1]]);
        $out = '';
        $at = 0;
        foreach ($spans as [$start, $end, $block]) {
            $out .= substr($source, $at, $start - $at) . $block;
            $at = $end;
        }

        return $out . substr($source, $at);
    }

    /**
     * @param array<mixed>|null $layout
     *
     * @return array<string, array{int, int}>
     */
    private static function blockSpans(?array $layout): array
    {
        $spans = [];
        foreach (is_array($layout['nodes'] ?? null) ? $layout['nodes'] : [] as $entry) {
            if (!is_array($entry) || !is_string($entry['path'] ?? null)) {
                continue;
            }
            if (is_int($entry['startByte'] ?? null) && is_int($entry['endByte'] ?? null)) {
                $spans[$entry['path']] ??= [$entry['startByte'], $entry['endByte']];
            }
        }

        return $spans;
    }

    /**
     * The leaf blocks of `$node` by path, in document order.
     *
     * @param array<mixed> $node
     * @param string $path
     *
     * @return array<string, array<mixed>>
     */
    private static function leafPaths(array $node, string $path): array
    {
        $leaves = [];
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $k => $child) {
            if (!is_array($child)) {
                continue;
            }
            $at = $path . '/children/' . $k;
            if (is_string($child['type'] ?? null) && isset(DataModel::INLINE_PARENTS[$child['type']])) {
                $leaves[$at] = $child;
            } else {
                $leaves += self::leafPaths($child, $at);
            }
        }

        return $leaves;
    }

    /**
     * @param array<mixed> $root
     * @param string $path
     *
     * @return array<mixed>|null
     */
    private static function nodeAt(array $root, string $path): ?array
    {
        $node = $root;
        foreach (array_slice(explode('/', $path), 1) as $step) {
            $next = $node[$step] ?? null;
            if (!is_array($next)) {
                return null;
            }
            $node = $next;
        }

        return $node;
    }

    /**
     * @var int Past this many references a block whose verbatim texts do not read back together keeps the rest from the
     *   tree.
     */
    private const MAX_VERBATIM_TRIALS = 32;

    /**
     * C2-W-075: the verbatim text of each reference whose sidecar records one, by path, as the writer spells it there:
     * the second field says whether the reference is an image, whose text starts behind its `!`.
     *
     * The paths are those of the read-back, which holds no line-end blank (C2-W-151).
     *
     * @param array<string, mixed> $tree
     * @param array<mixed>|null $layout
     * @param array<string, mixed> $options
     *
     * @return array<string, array{string, bool}>
     */
    private static function verbatimTexts(array $tree, ?array $layout, array $options): array
    {
        $facts = array_filter(self::facts($layout), static fn (array $entry): bool => isset($entry['rawRef']));
        // C2-W-127: `normalize` writes as if there were no sidecar.
        if ($facts === [] || ($options['normalize'] ?? false) === true) {
            return [];
        }
        $removed = [];
        $tree = LineEndBlanks::trimmed($tree, self::tableFormats($layout, $options), $removed);
        if ($removed !== []) {
            $facts = self::facts(LineEndBlanks::remapLayout($layout, $removed));
        }
        $texts = [];
        foreach ($facts as $path => $entry) {
            $raw = $entry['rawRef'] ?? null;
            if (!is_string($raw)) {
                continue;
            }
            $node = $tree;
            $inCell = false;
            foreach (explode('/', trim($path, '/')) as $part) {
                $node = is_array($node[$part] ?? null) ? $node[$part] : null;
                if ($node === null) {
                    break;
                }
                $inCell = $inCell || ($node['type'] ?? null) === 'table_cell';
            }
            $type = $node['type'] ?? null;
            if (($type === 'link' || $type === 'image') && isset($node['key'])) {
                $texts[$path] = [Inline::verbatimReference($raw, $inCell), $type === 'image'];
            }
        }

        return $texts;
    }

    /**
     * @param array<mixed>|null $layout
     *
     * @return array<mixed>|null
     */
    private static function withoutRawRefs(?array $layout): ?array
    {
        if (!is_array($layout['nodes'] ?? null)) {
            return $layout;
        }
        foreach ($layout['nodes'] as &$entry) {
            if (is_array($entry)) {
                unset($entry['rawRef']);
            }
        }
        unset($entry);

        return $layout;
    }

    /**
     * C2-W-075, C2-W-088 (carve-js `takeVerbatimReferences`): the references take their verbatim text together where the
     * source reads back with them; otherwise each top-level block decides its own, together where it reads back and else
     * one by one in document order. The others keep their spelling from the tree.
     *
     * Inline content does not reach across blocks, so one parse per round judges every block by its own subtree. Where
     * the result does not read back as a whole, the trials run on the whole document instead.
     *
     * @param \MarkupCarve\Carve\Writer\WriteResult $written
     * @param array<string, array{string, bool}> $verbatim
     * @param array<string, mixed> $tree
     * @param array<mixed>|null $layout
     * @param array<string, mixed> $options
     */
    private static function withVerbatimReferences(
        WriteResult $written,
        array $verbatim,
        array $tree,
        ?array $layout,
        array $options,
    ): WriteResult {
        $source = $written->source;
        $spans = self::verbatimSpans($source, $verbatim, $tree, $layout, $options);
        if ($spans === []) {
            return $written;
        }
        $treePrint = self::trimmedPrint($tree, $layout, $options);
        $all = self::spliced($source, $spans);
        $read = self::readTree($all, $options);
        if (TreeEquality::fingerprint($read) === $treePrint) {
            return new WriteResult($all, []);
        }
        if (in_array(-1, array_column($spans, 3), true)) {
            return self::verbatimOneByOne($written, $spans, $treePrint, $options);
        }
        $removed = [];
        $trimmed = LineEndBlanks::trimmed($tree, self::tableFormats($layout, $options), $removed);
        $final = self::verbatimByBlock($source, $spans, $read, $trimmed, $options);
        if ($final === []) {
            return $written;
        }
        $out = self::spliced($source, $final);
        if (TreeEquality::fingerprint(self::readTree($out, $options)) === $treePrint) {
            return new WriteResult($out, []);
        }

        return self::verbatimOneByOne($written, $spans, $treePrint, $options);
    }

    /**
     * The verbatim texts each top-level block takes, by rounds that each parse the whole source once: all of a block's
     * texts where the block read back with them in `$read`, else one by one in document order.
     *
     * @param string $source
     * @param array<int, array{int, int, string, int}> $spans
     * @param array<mixed> $read The read-back with every verbatim text.
     * @param array<string, mixed> $trimmed
     * @param array<string, mixed> $options
     *
     * @return array<int, array{int, int, string, int}>
     */
    private static function verbatimByBlock(string $source, array $spans, array $read, array $trimmed, array $options): array
    {
        $children = is_array($trimmed['children'] ?? null) ? $trimmed['children'] : [];
        $wanted = array_map(static fn (mixed $child): string => TreeEquality::fingerprint($child), $children);
        $reads = static function (array $read, int $block) use ($wanted): bool {
            $children = is_array($read['children'] ?? null) ? $read['children'] : [];

            return count($children) === count($wanted) && isset($children[$block], $wanted[$block])
                && TreeEquality::fingerprint($children[$block]) === $wanted[$block];
        };
        $byBlock = [];
        foreach ($spans as $span) {
            $byBlock[$span[3]][] = $span;
        }
        $taken = [];
        $open = [];
        foreach ($byBlock as $block => $list) {
            $together = $reads($read, $block);
            $taken[$block] = $together ? $list : [];
            if (!$together && count($list) > 1) {
                $open[$block] = 0;
            }
        }
        while ($open !== []) {
            $trial = $taken;
            foreach ($open as $block => $at) {
                $trial[$block][] = $byBlock[$block][$at];
            }
            $read = self::readTree(self::spliced($source, array_merge(...array_values($trial))), $options);
            foreach ($open as $block => $at) {
                if ($reads($read, $block)) {
                    $taken[$block] = $trial[$block];
                }
                $open[$block] = $at + 1;
                if ($at + 1 >= min(count($byBlock[$block]), self::MAX_VERBATIM_TRIALS)) {
                    unset($open[$block]);
                }
            }
        }

        return array_merge(...array_values($taken));
    }

    /**
     * The trials of `withVerbatimReferences()` on the whole document, for an output whose blocks do not decide alone.
     *
     * @param \MarkupCarve\Carve\Writer\WriteResult $written
     * @param array<int, array{int, int, string, int}> $spans
     * @param string $treePrint
     * @param array<string, mixed> $options
     */
    private static function verbatimOneByOne(WriteResult $written, array $spans, string $treePrint, array $options): WriteResult
    {
        $taken = [];
        foreach (array_slice($spans, 0, self::MAX_VERBATIM_TRIALS) as $span) {
            $trial = [...$taken, $span];
            if (self::readsBack(self::spliced($written->source, $trial), $treePrint, $options)) {
                $taken = $trial;
            }
        }

        return $taken === [] ? $written : new WriteResult(self::spliced($written->source, $taken), []);
    }

    /**
     * The tree `readsBack()` compares.
     *
     * @param string $source
     * @param array<string, mixed> $options
     *
     * @return array<mixed>
     */
    private static function readTree(string $source, array $options): array
    {
        if (ReadbackRound::memoActive() && !isset($options['extensions'])) {
            return ReadbackRound::read($source, $options);
        }
        $parseOptions = isset($options['extensions']) ? ['extensions' => $options['extensions']] : [];

        return TreeEncoder::decodedForWriter(Carve::parse($source, $parseOptions));
    }

    /**
     * The byte span of each reference in the written source whose verbatim text differs from its spelling there, with
     * that text and the index of the top-level block that holds it, in document order. Inside a container a written line
     * carries its prefixes, so each later line of the verbatim text takes the prefix the spelling's line took.
     *
     * @param string $source
     * @param array<string, array{string, bool}> $verbatim
     * @param array<string, mixed> $tree
     * @param array<mixed>|null $layout
     * @param array<string, mixed> $options
     *
     * @return array<int, array{int, int, string, int}>
     */
    private static function verbatimSpans(string $source, array $verbatim, array $tree, ?array $layout, array $options): array
    {
        $found = self::writtenReferences($source, $verbatim, $options);
        // A label break has no line in a one-line spelling, so it takes the continuation of the leaf (C2-W-024).
        $wrapped = array_keys(array_filter(
            $found,
            static fn (array $at, string $path): bool => str_contains($verbatim[$path][0], "\n")
                && !str_contains(substr($source, $at[0], $at[1] - $at[0]), "\n"),
            ARRAY_FILTER_USE_BOTH,
        ));
        $continuations = $wrapped === [] ? [] : self::continuations($tree, $layout, $options, $wrapped);
        $spans = [];
        foreach ($found as $path => [$start, $end, $spelled]) {
            $written = substr($source, $start, $end - $start);
            $out = self::prefixedLike($verbatim[$path][0], $written, $spelled, $continuations[$path] ?? '');
            if ($out !== null && $out !== $written) {
                $spans[] = [$start, $end, $out, preg_match('#^/children/(\d+)#', $path, $block) === 1 ? (int)$block[1] : -1];
            }
        }
        usort($spans, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $spans;
    }

    /**
     * The byte span and the spelling without container prefixes of each reference with a verbatim text, by path.
     *
     * @param string $source
     * @param array<string, array{string, bool}> $verbatim
     * @param array<string, mixed> $options
     *
     * @return array<string, array{int, int, string}>
     */
    private static function writtenReferences(string $source, array $verbatim, array $options): array
    {
        $parseOptions = isset($options['extensions']) ? ['extensions' => $options['extensions']] : [];
        $found = [];
        $entries = Carve::parseWithLayout($source, $parseOptions)->layout['nodes'] ?? null;
        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $path = $entry['path'] ?? null;
            $spelled = $entry['rawRef'] ?? null;
            $start = $entry['startByte'] ?? null;
            $end = $entry['endByte'] ?? null;
            if (!is_string($path) || !isset($verbatim[$path]) || !is_string($spelled) || !is_int($start) || !is_int($end)) {
                continue;
            }
            $found[$path] = [$start + ($verbatim[$path][1] ? 1 : 0), $end, $spelled];
        }

        return $found;
    }

    /**
     * The prefix the writer gives a later line of the leaf that holds each reference at `$paths`: a probe write of the
     * tree with a line break in each of those references' text, read off at the line behind that break.
     *
     * @param array<string, mixed> $tree
     * @param array<mixed>|null $layout
     * @param array<string, mixed> $options
     * @param array<string> $paths
     *
     * @return array<string, string>
     */
    private static function continuations(array $tree, ?array $layout, array $options, array $paths): array
    {
        $removed = [];
        $probe = LineEndBlanks::trimmed($tree, self::tableFormats($layout, $options), $removed);
        $probeLayout = self::withoutRawRefs($removed === [] ? $layout : LineEndBlanks::remapLayout($layout, $removed));
        $moved = self::probeBlocks($probe, $probeLayout, $paths);
        // A mark that no text of the probe holds, so only the inserted one is found.
        $held = (string)json_encode($probe, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $base = 'qzx';
        while (str_contains($held, $base)) {
            $base .= 'q';
        }
        $marks = [];
        foreach ($paths as $at => $path) {
            $mark = $base . $at . 'x';
            $target = $moved($path);
            if ($target !== null && self::probeAt($probe, explode('/', trim($target, '/')), $mark)) {
                $marks[$path] = $mark;
            }
        }
        if ($marks === []) {
            return [];
        }
        // The prefix does not depend on escapes, so one plain write without the read-back rounds serves.
        $out = self::writeInternal($probe, $probeLayout, $options, null)->source;
        $prefixes = [];
        foreach ($marks as $path => $mark) {
            $at = strpos($out, $mark);
            if ($at === false) {
                continue;
            }
            $lineStart = strrpos(substr($out, 0, $at), "\n");
            $lineStart = $lineStart === false ? 0 : $lineStart + 1;
            $prefixes[$path] = substr($out, $lineStart, $at - $lineStart);
        }

        return $prefixes;
    }

    /**
     * Keeps only the top-level blocks that hold a path of `$paths`, so the probe stays small, and renumbers the layout to
     * match. The closure maps a path of the full tree to the probe, null for a dropped block.
     *
     * @param array<string, mixed> $probe
     * @param array<mixed>|null $layout
     * @param array<string> $paths
     *
     * @return \Closure(string): ?string
     */
    private static function probeBlocks(array &$probe, ?array &$layout, array $paths): Closure
    {
        $blocks = [];
        foreach ($paths as $path) {
            if (preg_match('#^/children/(\d+)/#', $path, $m) === 1) {
                $blocks[(int)$m[1]] = true;
            }
        }
        ksort($blocks);
        $renumber = array_flip(array_keys($blocks));
        $children = is_array($probe['children'] ?? null) ? $probe['children'] : [];
        $probe['children'] = array_values(array_intersect_key($children, $renumber));
        $moved = static function (string $path) use ($renumber): ?string {
            if (preg_match('#^/children/(\d+)(/.*)?$#', $path, $m) !== 1) {
                return $path;
            }

            return isset($renumber[(int)$m[1]]) ? '/children/' . $renumber[(int)$m[1]] . ($m[2] ?? '') : null;
        };
        if (is_array($layout['nodes'] ?? null)) {
            $nodes = [];
            foreach ($layout['nodes'] as $entry) {
                $path = is_array($entry) && is_string($entry['path'] ?? null) ? $moved($entry['path']) : null;
                if ($path !== null) {
                    $nodes[] = ['path' => $path] + $entry;
                }
            }
            $layout['nodes'] = $nodes;
        }

        return $moved;
    }

    /**
     * Gives the reference at `$parts` the text `x`, a soft break and `$mark`; an image becomes a link with that text.
     *
     * @param array<mixed> $node
     * @param array<string> $parts
     * @param string $mark
     *
     * @return bool Whether a reference stood there.
     */
    private static function probeAt(array &$node, array $parts, string $mark): bool
    {
        if ($parts === []) {
            $type = $node['type'] ?? null;
            if (($type !== 'link' && $type !== 'image') || !isset($node['key'])) {
                return false;
            }
            if ($type === 'image') {
                $node = ['type' => 'link', 'key' => $node['key'], 'refForm' => 'full', 'href' => $node['src'] ?? ''];
            }
            $node['children'] = [
                ['type' => 'text', 'value' => 'x'],
                ['type' => 'soft_break'],
                ['type' => 'text', 'value' => $mark],
            ];

            return true;
        }
        $part = array_shift($parts);
        if (!is_array($node[$part] ?? null)) {
            return false;
        }

        return self::probeAt($node[$part], $parts, $mark);
    }

    /**
     * `$text` with each later line behind the prefix the same line of the written reference carries before its line of
     * `$spelled`, the reference read without the container prefixes; the last prefix serves the lines past them, and
     * `$continuation` the lines past a one-line spelling. Null where the written lines are not those of `$spelled` behind
     * prefixes. The first line starts at the opening bracket, so it carries none.
     */
    private static function prefixedLike(string $text, string $written, string $spelled, string $continuation): ?string
    {
        $lines = explode("\n", $written);
        $spelledLines = explode("\n", $spelled);
        if (count($lines) !== count($spelledLines)) {
            return null;
        }
        $prefixes = [];
        foreach ($lines as $k => $line) {
            $cut = strlen($line) - strlen($spelledLines[$k]);
            if ($cut < 0 || substr($line, $cut) !== $spelledLines[$k] || ($k === 0 && $cut !== 0)) {
                return null;
            }
            $prefixes[] = substr($line, 0, $cut);
        }
        if (count($prefixes) === 1) {
            $prefixes[] = $continuation;
        }
        $out = '';
        foreach (explode("\n", $text) as $k => $line) {
            $out .= $k === 0 ? $line : "\n" . $prefixes[min($k, count($prefixes) - 1)] . $line;
        }

        return $out;
    }

    /**
     * @param string $source
     * @param array<int, array{int, int, string, int}> $spans In document order.
     */
    private static function spliced(string $source, array $spans): string
    {
        $out = '';
        $at = 0;
        foreach ($spans as [$start, $end, $text]) {
            // A reference in the text of another is already in that one's verbatim text.
            if ($start < $at) {
                continue;
            }
            $out .= substr($source, $at, $start - $at) . $text;
            $at = $end;
        }

        return $out . substr($source, $at);
    }

    /**
     * @param array<string, mixed> $tree
     *
     * @return array{array<\MarkupCarve\Carve\Diagnostics\Diagnostic>, array<string, true>}
     */
    private static function normalizedAttributeValueLosses(array &$tree): array
    {
        $losses = [];
        $fallbackNodes = [];
        $decorative = false;
        if (is_array($tree['children'] ?? null)) {
            foreach ($tree['children'] as $at => $child) {
                if (is_array($child)) {
                    self::findAttributeLineFeeds($child, '/children/' . $at, $losses, $fallbackNodes, $decorative);
                }
            }
        }
        if ($decorative) {
            foreach ($tree['children'] as &$child) {
                if (is_array($child)) {
                    self::spellDecorative($child);
                }
            }
            unset($child);
        }
        if ($losses !== []) {
            foreach ($tree['children'] as &$child) {
                if (is_array($child)) {
                    self::spellAttributeValues($child);
                }
            }
            unset($child);
        }

        return [$losses, $fallbackNodes];
    }

    /**
     * A lost attribute field or node already has its report; do not add a value report under it.
     *
     * @param \MarkupCarve\Carve\Writer\WriteResult $written
     * @param array<\MarkupCarve\Carve\Diagnostics\Diagnostic> $losses
     * @param array<string, true> $fallbackNodes
     *
     * @return \MarkupCarve\Carve\Writer\WriteResult
     */
    private static function withAttributeValueLosses(WriteResult $written, array $losses, array $fallbackNodes): WriteResult
    {
        if ($losses === []) {
            return $written;
        }
        $lostNodes = [];
        $lostFields = [];
        foreach ($written->diagnostics as $entry) {
            if ($entry->code === 'writer-refused') {
                return $written;
            }
            if (is_string($entry->path) && $entry->path !== '') {
                if ($entry->code === 'structure-unspellable') {
                    $lostNodes[$entry->path] = true;
                } elseif ($entry->code === 'field-unspellable') {
                    $lostFields[$entry->path] = true;
                }
            }
        }
        $uncovered = [];
        foreach ($losses as $loss) {
            $path = $loss->path ?? '';
            $attrsAt = strpos($path, '/attrs/keyValues/');
            if ($attrsAt === false) {
                $uncovered[] = $loss;

                continue;
            }
            $node = substr($path, 0, $attrsAt);
            if (isset($lostNodes[$node])) {
                continue;
            }
            if (self::omittedByFallback($node, $path, $lostNodes, $fallbackNodes)) {
                continue;
            }
            if (!self::attributeFieldLost($node, $path, $lostFields)) {
                $uncovered[] = $loss;
            }
        }

        return $uncovered === [] ? $written : new WriteResult($written->source, [...$written->diagnostics, ...$uncovered]);
    }

    /**
     * @param string $node
     * @param string $path
     * @param array<string, true> $lostFields
     */
    private static function attributeFieldLost(string $node, string $path, array $lostFields): bool
    {
        $attrsPath = $node . '/attrs';
        while ($path !== $attrsPath && !isset($lostFields[$path])) {
            $slash = strrpos($path, '/');
            $path = $slash === false || $slash === 0 ? '' : substr($path, 0, $slash);
        }

        return isset($lostFields[$path]);
    }

    /**
     * @param string $node
     * @param string $path
     * @param array<string, true> $lostNodes
     * @param array<string, true> $fallbackNodes
     */
    private static function omittedByFallback(string $node, string $path, array $lostNodes, array $fallbackNodes): bool
    {
        $ancestor = $node;
        while ($ancestor !== '') {
            if (
                isset($lostNodes[$ancestor], $fallbackNodes[$ancestor])
                && !str_starts_with($path, $ancestor . '/fallback/')
            ) {
                return true;
            }
            $slash = strrpos($ancestor, '/');
            $ancestor = $slash === false || $slash === 0 ? '' : substr($ancestor, 0, $slash);
        }

        return false;
    }

    /**
     * C2-W-050: a value in a one-line attribute block takes one space per line feed.
     *
     * @param array<mixed> $node
     * @param string $path
     * @param array<\MarkupCarve\Carve\Diagnostics\Diagnostic> $losses
     * @param array<string, true> $fallbackNodes
     * @param bool $decorative
     */
    private static function findAttributeLineFeeds(
        array $node,
        string $path,
        array &$losses,
        array &$fallbackNodes,
        bool &$decorative,
    ): void {
        if (is_array($node['fallback'] ?? null)) {
            $fallbackNodes[$path] = true;
        }
        $decorative = $decorative || ($node['decorative'] ?? null) === true;
        $attrs = $node['attrs'] ?? null;
        if (is_array($attrs) && is_array($attrs['keyValues'] ?? null)) {
            foreach ($attrs['keyValues'] as $at => $pair) {
                if (
                    is_array($pair) && is_string($pair[0] ?? null) && BraceBlock::isKey($pair[0])
                    && is_string($pair[1] ?? null) && str_contains($pair[1], "\n")
                ) {
                    $losses[] = new Diagnostic(
                        'field-unspellable',
                        'warning',
                        'render-loss',
                        'Carve source cannot spell an attribute value with a line feed',
                        $path . '/attrs/keyValues/' . $at . '/1',
                    );
                }
            }
        }
        foreach ($node as $slot => $value) {
            if (
                in_array($slot, ['attrs', 'metadata', 'data', 'pos', 'segments'], true)
                || (($node['type'] ?? null) === 'include' && $slot === 'children') || !is_array($value)
            ) {
                continue;
            }
            $step = str_replace(['~', '/'], ['~0', '~1'], (string)$slot);
            self::findAttributeSlotLineFeeds($value, $path . '/' . $step, $losses, $fallbackNodes, $decorative);
        }
    }

    /**
     * @param array<mixed> $value
     * @param string $path
     * @param array<\MarkupCarve\Carve\Diagnostics\Diagnostic> $losses
     * @param array<string, true> $fallbackNodes
     * @param bool $decorative
     */
    private static function findAttributeSlotLineFeeds(
        array $value,
        string $path,
        array &$losses,
        array &$fallbackNodes,
        bool &$decorative,
    ): void {
        if (is_string($value['type'] ?? null)) {
            self::findAttributeLineFeeds($value, $path, $losses, $fallbackNodes, $decorative);

            return;
        }
        foreach ($value as $at => $part) {
            if (!is_array($part)) {
                continue;
            }
            // A node of scalar fields alone has no fallback, attributes or nodes in it.
            $leaf = is_string($part['type'] ?? null);
            foreach ($leaf ? $part : [] as $field) {
                if (is_array($field)) {
                    $leaf = false;

                    break;
                }
            }
            if (!$leaf) {
                $step = is_int($at) ? (string)$at : str_replace(['~', '/'], ['~0', '~1'], $at);
                self::findAttributeSlotLineFeeds($part, $path . '/' . $step, $losses, $fallbackNodes, $decorative);
            } elseif (($part['decorative'] ?? null) === true) {
                $decorative = true;
            }
        }
    }

    /**
     * C2-W-011: a decorative image is written as `![](x)`, so the read-back to compare with holds its empty alt.
     *
     * @param array<mixed> $node
     */
    private static function spellDecorative(array &$node): void
    {
        if (($node['type'] ?? null) === 'image' && ($node['decorative'] ?? null) === true && !array_key_exists('alt', $node)) {
            unset($node['decorative']);
            $node['alt'] = '';
        }
        foreach ($node as $slot => &$value) {
            if (is_array($value) && !in_array($slot, ['attrs', 'metadata', 'data', 'pos', 'segments'], true)) {
                self::spellDecorative($value);
            }
        }
        unset($value);
    }

    /**
     * Change only a tree with a reported value. The writer's other attribute paths then share the same spelling.
     *
     * @param array<mixed> $node
     */
    private static function spellAttributeValues(array &$node): void
    {
        $attrs = $node['attrs'] ?? null;
        if (is_array($attrs) && is_array($attrs['keyValues'] ?? null)) {
            foreach ($attrs['keyValues'] as &$pair) {
                if (
                    is_array($pair) && is_string($pair[0] ?? null) && BraceBlock::isKey($pair[0])
                    && is_string($pair[1] ?? null) && str_contains($pair[1], "\n")
                ) {
                    $pair[1] = str_replace("\n", ' ', $pair[1]);
                }
            }
            unset($pair);
            $node['attrs'] = $attrs;
        }
        foreach ($node as $slot => &$value) {
            if (
                in_array($slot, ['attrs', 'metadata', 'data', 'pos', 'segments'], true)
                || (($node['type'] ?? null) === 'include' && $slot === 'children') || !is_array($value)
            ) {
                continue;
            }
            self::spellAttributeSlotValues($value);
        }
        unset($value);
    }

    /**
     * @param array<mixed> $value
     */
    private static function spellAttributeSlotValues(array &$value): void
    {
        if (is_string($value['type'] ?? null)) {
            self::spellAttributeValues($value);

            return;
        }
        foreach ($value as &$part) {
            if (is_array($part)) {
                self::spellAttributeSlotValues($part);
            }
        }
        unset($part);
    }

    // The first bare read-back adds one necessary branch to the writer's round procedure.
    // phpcs:disable Generic.Metrics.CyclomaticComplexity.MaxExceeded

    /**
     * @param array<string, mixed> $tree
     * @param array<mixed>|null $layout
     * @param array<string, mixed> $options
     */
    private static function writeChecked(array $tree, ?array $layout, array $options): WriteResult
    {
        $parsed = self::$parsedSource;
        self::$parsedSource = null;
        $risk = self::hasRoundRisk($tree);
        [$result, $localEscapes, $undecided, $localDecided, $pairEscapes, $groups]
            = self::firstWrite($tree, $layout, $options, $risk || self::plainGroups($tree, $options), $parsed === null);
        $localOwn = self::$localOwn;
        $cellTicks = self::$cellTicks;
        // C2-I-060: a brace glued before the opener of a node takes the node's mark, so the bare form never reads back,
        // and its backslash gives no kind (C2-W-088). A local write whose only escapes are such braces needs no kind
        // check (carve-js `leavesKind`).
        $gluedNodesOnly = $localEscapes !== [] && !$undecided && $localDecided === [] && $localOwn === []
            && !self::$localGluedElsewhere;
        // A text line the plan escaped closes its fenced block bare, so the form without escapes does not hold. Where the
        // plan also settles its kinds, the first kind check is decided as the second one is, without a read.
        $bareFails = self::$localClosesFence && $localOwn === [] && !$undecided && $localEscapes !== []
            && RepairPlanner::settlesKinds($tree, $localEscapes);
        // C2-W-088: the backslashes a row's pairing across a pipe gives were placed as the first round places them;
        // where that form reads back, the procedure ends there.
        if (self::$cellKinds && $result->diagnostics === []) {
            $read = self::readBackResult($result, $tree, $layout, $options, $parsed);
            if ($read !== null) {
                return $read;
            }
        }
        // C2-W-125 case five: the plan placed the escapes the rounds would add one link per round (carve-js#5592).
        if (self::$localCaseFive && !$undecided && $result->diagnostics === []) {
            $read = self::readBackResult($result, $tree, $layout, $options, $parsed);
            if ($read !== null) {
                return $read;
            }
        }
        // A local bracket decision is no reported kind when the write without it already reads back as the tree.
        // Check only leaves whose local spelling actually contains an escaped bracket.
        // The bare form without escapes, its group ranges and `$bareRepaired`: the checks below write it once.
        $plainBare = null;
        $wanted = null;
        if (
            $result->diagnostics === [] && !self::$inGroups && !isset($options['linkKeys'])
            && str_contains($result->source, '\\[') && !self::hasExtensionNode($tree)
        ) {
            if ($localOwn === []) {
                $plainRanges = null;
                $bare = self::recordedBare($tree, $layout, $options, [], $plainRanges);
                $plainBare = [$bare, $plainRanges, self::$bareRepaired];
            } else {
                $bare = self::bareSource($tree, $layout, $options, $localOwn);
            }
            if ($bare !== $result->source && !self::$bareRepaired && Text::treePairOpeners($tree, $localOwn) === []) {
                $wanted = self::trimmedPrint($tree, $layout, $options);
                if (self::readsBack($bare, $wanted, $options)) {
                    return new WriteResult(self::prunedNoteCarets($bare, $wanted, $options, $parsed), []);
                }
            }
        }
        if (
            $parsed !== null && $result->diagnostics === []
            && (str_contains($parsed, '^[') || str_contains($parsed, '[^'))
            && !str_contains($parsed, '\\[') && !str_contains($parsed, '\\]')
            && self::onlyAddedEscapes($parsed, $result->source, '[](!^')
            // C2-W-146: an added caret line escape is no note escape, and the parsed form would drop it.
            && substr_count($result->source, '\\^ ') === substr_count($parsed, '\\^ ')
        ) {
            return new WriteResult(str_ends_with($parsed, "\n") ? $parsed : $parsed . "\n", []);
        }
        $unsettledByKind = false;
        $firstBare = null;
        // The marked candidate of the bare form, which the first round of the repair below would write again.
        $markedBare = null;
        // C2-W-146 places its pair escapes after the procedure of C2-W-088, for pairs of bare marks that stay text, so a
        // write whose escapes are all of these and that reads back reads so bare too: no kind round, no bare read-back.
        $pairsOnly = $localEscapes !== [] && $result->diagnostics === []
            && self::onlyPairEscapes($tree, $localEscapes, $pairEscapes, $result->source)
            && ($parsed === null || !self::mayTakeBare($tree, $localEscapes, $parsed))
            // C2-W-146 reads the pairs of a whole run of text and soft breaks, as lint does on the written source.
            && Text::treePairOpeners($tree, []) == $localEscapes;
        $pairPrint = null;
        if ($pairsOnly) {
            $pairPrint = $wanted ??= self::trimmedPrint($tree, $layout, $options);
            $pairsOnly = self::readsBack($result->source, $pairPrint, $options);
        }
        if (
            $parsed !== null && strlen($parsed) < 65536 && $result->diagnostics === [] && $localEscapes !== []
            && !$pairsOnly && !$gluedNodesOnly && !$bareFails && !RepairPlanner::hasTextNoteBeforeCaretPair($tree)
        ) {
            // C2-W-088: with escapes every form carries, the form without escapes is taken only through `mayTakeBare()`.
            // Elsewhere the procedure form is tried first, and the form without escapes is written only where it fails.
            $ownFirst = $localOwn !== [] && !self::mayTakeBare($tree, $localEscapes, $parsed);
            if ($ownFirst) {
                $wanted ??= self::trimmedPrint($tree, $layout, $options);
                $procedure = self::procedureForm($tree, $layout, $options, $localOwn, $wanted, null);
                if ($procedure !== null) {
                    return $procedure;
                }
            }
            $firstRanges = null;
            if ($plainBare !== null) {
                [$bare, $firstRanges, $bareRepaired] = $plainBare;
            } else {
                $bare = self::recordedBare($tree, $layout, $options, [], $firstRanges);
                $bareRepaired = self::$bareRepaired;
            }
            // The source the tree was parsed from reads back as that tree, so a bare form equal to it holds unread.
            if ($bare === $parsed) {
                // With no escape every form carries and no pair escape, the procedure form is this bare form too.
                $noPairs = Text::treePairOpeners($tree, []) === [];
                if (
                    $noPairs && (self::mayTakeBare($tree, $localEscapes, $parsed)
                    || (!$bareRepaired && $localOwn === [] && !self::hasExtensionNode($tree)))
                ) {
                    return new WriteResult($bare, []);
                }
                $wanted ??= self::trimmedPrint($tree, $layout, $options);
                $procedure = $bareRepaired || $ownFirst ? null
                    : self::procedureForm($tree, $layout, $options, $localOwn, $wanted, [$bare, [], true]);
                if ($procedure !== null) {
                    return $procedure;
                }
            }
            $readBare = ReadbackRound::read($bare, $options);
            $wanted ??= self::trimmedPrint($tree, $layout, $options);
            $firstBare = [$bare, $readBare, ...RepairPlanner::readPrints($readBare), $wanted, $firstRanges];
            $bareHolds = $firstBare[2] === $wanted;
            if ($bareHolds && self::mayTakeBare($tree, $localEscapes, $parsed) && Text::treePairOpeners($tree, []) === []) {
                return new WriteResult($bare, []);
            }
            $procedure = $bareRepaired || $ownFirst || $bare === $parsed ? null
                : self::procedureForm($tree, $layout, $options, $localOwn, $wanted, [$bare, $readBare, $bareHolds]);
            if ($procedure !== null) {
                return $procedure;
            }
            if (!$bareHolds) {
                $marked = self::markedCandidate($tree, $layout, $options, [], $bare);
                $markedBare = [$bare, $marked];
                $reported = ReadbackRound::compare($marked, $readBare, $tree, $wanted, false)['openers'];
                $expanded = RepairPlanner::expandedKindEscapes($tree, $reported, $localEscapes);
                $unsettledByKind = $expanded !== $localEscapes;
                // C2-W-092: a round that reads blocks the local form did not escape also reports the plain lines of
                // the containers it opened, as carve-js does for a misaligned round.
                if ($unsettledByKind) {
                    $reported = ReadbackRound::compare($marked, $readBare, $tree, $wanted, false, true)['openers'];
                    $expanded = RepairPlanner::expandedKindEscapes($tree, $reported, $localEscapes);
                }
                // Cell backticks take the backslashes of their whole kind in the rounds, not the reported opener alone.
                if ($unsettledByKind && !$cellTicks) {
                    $byKind = self::bareSource($tree, $layout, $options, $expanded);
                    if (Text::treePairOpeners($tree, $expanded) === [] && self::readsBack($byKind, $wanted, $options)) {
                        return new WriteResult($byKind, []);
                    }
                }
            }
        }
        $settled = $unsettledByKind
            ? self::settledAlone($tree, $layout, $options, $result, $groups, $parsed)
            : self::settled($tree, $layout, $options, $result, $groups, $parsed, $risk);
        if ($settled !== null) {
            return $settled;
        }
        $children = $tree['children'] ?? null;
        if (
            $parsed !== null && self::simpleBracedSource($parsed) && $result->diagnostics === []
            && is_array($children) && count($children) === 1
            && is_array($children[0]) && ($children[0]['type'] ?? null) === 'paragraph'
            && self::onlyAddedBraceEscapes($parsed, $result->source)
        ) {
            // The local planner added only backslashes to text braces before braced-only marks. Bare ^ and ,
            // are text, so escaping those literal braces cannot change the parsed tree.
            return $result;
        }
        // C2-W-124: a local write the heuristics call safe is still read back where the tree is a parse's; one that
        // reads as another tree goes to the rounds.
        $unchecked = !$undecided && !$risk;
        // A form equal to the source the tree was parsed from reads back as that tree, and a tree without ASCII
        // punctuation in its strings takes no escape decision that could misread.
        // Without the source, a braced opener in the text of its span goes to the rounds as a parse's tree does, and any
        // other tree is read back (C2-W-002).
        if ($unchecked && self::readsBackUnread($tree, $parsed, $result, $options)) {
            $procedure = $parsed === null
                ? self::suppliedProcedure($tree, $layout, $options, $result, $localEscapes, $localOwn, $pairEscapes) : null;

            return $procedure ?? self::uncheckedPruned($result, $parsed, $tree, $layout, $options);
        }
        // C2-W-151: the read-back never holds the spaces and tabs that end a line of text, so the round compares
        // and repairs the tree without them. The local write keeps the supplied tree, and with it the loss paths.
        $removed = [];
        $fallback = null;
        $original = $tree;
        $originalLayout = $layout;
        $tree = LineEndBlanks::trimmed($tree, self::tableFormats($layout, $options), $removed);
        $layout = LineEndBlanks::remapLayout($layout, $removed);
        // `trimmedPrint()` of the supplied tree, which the first bare read-back compared with.
        $treeParts = null;
        $treePrint = $firstBare[4] ?? $pairPrint;
        if ($treePrint === null) {
            [$treePrint, $treeParts] = RepairPlanner::readPrints($tree);
        }
        // A nested link does not split the bracket pairs of the text around it. At the inline depth cap, the local
        // plan can still propose escapes for those pairs; C2-W-088 ends after round one if its bare form reads back.
        $knownBare = null;
        if ($removed === [] && $plainBare !== null) {
            $knownBare = [$plainBare[0], $firstBare !== null && $firstBare[0] === $plainBare[0] ? $firstBare[2] === $treePrint : null];
        }
        $bareNestedLink = self::bareNestedLink($tree, $layout, $options, $treePrint, $result->source, $knownBare);
        if ($bareNestedLink !== null) {
            return $bareNestedLink;
        }
        $bareSource = null;
        $bareRanges = null;
        $read = null;
        $readPrint = null;
        $readParts = null;
        $needsKindRound = false;
        $conservativeLater = false;
        if (
            $localEscapes !== [] && !$pairsOnly && !$gluedNodesOnly
            && (self::takesLocalForm($unchecked, $undecided, $localDecided) || $undecided)
            && ($undecided || !($bareFails || RepairPlanner::settlesKinds($tree, $localEscapes)))
        ) {
            // Without trimmed line-end blanks the tree is the one the first bare read-back was taken of.
            if ($removed === [] && $firstBare !== null) {
                [$bareSource, $read, $readPrint, $readParts, , $bareRanges] = $firstBare;
            } else {
                $bareSource = self::recordedBare($tree, $layout, $options, [], $bareRanges);
                $read = ReadbackRound::read($bareSource, $options);
                [$readPrint, $readParts] = RepairPlanner::readPrints($read);
            }
        }
        if ($read !== null && $readPrint !== $treePrint) {
            // The kind check above marked this same bare form of the same tree.
            $bare = $removed === [] && $markedBare !== null && $markedBare[0] === $bareSource
                ? $markedBare[1]
                : self::markedCandidate($tree, $layout, $options, [], $bareSource);
            $markedBare = [$bareSource, $bare];
            $bareCompared = ReadbackRound::compare($bare, $read, $tree, $treePrint, false);
            $reported = $bareCompared['openers'];
            $needsKindRound = RepairPlanner::expandedKindEscapes($tree, $reported, $localEscapes) !== $localEscapes;
            // C2-W-088, C2-W-125: a span the parse reads on a node's own opener with another extent is that node, which
            // reports nothing; the rounds repair it, not the local heuristics.
            $needsKindRound = $needsKindRound || ($reported === [] && $bareCompared['moved'] !== []);
            unset($bareCompared, $bare);
        }
        if (!$needsKindRound && self::takesLocalForm($unchecked, $undecided, $localDecided)) {
            $procedure = $parsed === null && $removed === []
                ? self::suppliedProcedure($tree, $layout, $options, $result, $localEscapes, $localOwn, $pairEscapes, $treePrint)
                : null;
            if ($procedure !== null) {
                return $procedure;
            }
            // The record is rebuilt where the rounds need it, so the read-back does not hold it beside a second tree.
            // So are the bare read-back and its marked candidate.
            unset($localEscapes);
            $read = null;
            $markedBare = null;
            if ($pairsOnly || self::readsBack($result->source, $treePrint, $options)) {
                return self::withPrunedNoteCarets($result, $treePrint, $options, $parsed);
            }
        }
        if ($undecided && !$needsKindRound) {
            // C2-W-088: a bracket the plan left bare stays bare where the form reads back; otherwise the plan takes
            // its backslash there, and the rounds below go on from that form.
            // The rounds read the bare form, which is this local form where the plan placed no escape.
            if ($pairsOnly || self::readsBack($result->source, $treePrint, $options, ($localEscapes ?? []) === [])) {
                $procedure = $parsed === null && $removed === []
                    ? self::suppliedProcedure($tree, $layout, $options, $result, $localEscapes ?? [], $localOwn, $pairEscapes, $treePrint)
                    : null;

                return $procedure ?? self::withPrunedNoteCarets($result, $treePrint, $options, $parsed);
            }
            // C2-W-088: the rounds decide the brackets the plan left open, as in carve-js; the conservative form, which
            // escapes every undecided bracket, stands only where they stop.
            $conservativeLater = !$cellTicks;
        }
        $glued = RepairPlanner::gluedTicks($tree);
        if ($read === null && $glued === [] && $removed === [] && $firstBare !== null) {
            // The rounds start from the bare form the kind check above wrote and read.
            [$bareSource, $read, $readPrint, $readParts, , $bareRanges] = $firstBare;
        } elseif ($read === null || $glued !== []) {
            $bareSource = self::recordedBare($tree, $layout, $options, $glued, $bareRanges);
            $read = ReadbackRound::read($bareSource, $options);
            [$readPrint, $readParts] = RepairPlanner::readPrints($read);
        }
        unset($firstBare);
        $seed = $glued;
        $planner = null;
        $scoped = null;
        if ($readPrint === $treePrint) {
            unset($read);
            if ($result->source === $bareSource || (!$unchecked && self::readsBack($result->source, $treePrint, $options))) {
                // `$localEscapes` is gone where the branch above already tried the procedure form.
                $procedure = $parsed === null && $removed === [] && $result->source !== $bareSource
                    ? self::suppliedProcedure($tree, $layout, $options, $result, $localEscapes ?? [], $localOwn, $pairEscapes, $treePrint)
                    : null;

                return $procedure ?? self::withPrunedNoteCarets($result, $treePrint, $options, $parsed);
            }
            // C2-W-091: the escapes of the local write can change how the text reads, as an escaped bracket that no
            // longer counts toward the depth limit of C2-I-118. The rounds go on from the local form.
            $local = $removed === [] ? ($localEscapes ?? self::localWrite($original, $originalLayout, $options, false)[1]) : [];
            foreach ($local as $path => $offsets) {
                $seed[$path] = ($seed[$path] ?? []) + $offsets;
            }
        } else {
            $prints = [$treePrint, $treeParts];
            $scoped = self::scopedRepair($tree, $layout, $options, $glued, $prints, $bareSource, $bareRanges, $read, $readParts);
            if ($scoped === null) {
                // The first round would write and read this same candidate again. Without glued ticks it is the
                // candidate the kind check above marked.
                $bare = $glued === [] && $removed === [] && $markedBare !== null && $markedBare[0] === $bareSource
                    ? $markedBare[1]
                    : self::markedCandidate($tree, $layout, $options, $glued, $bareSource);
                $planner = new RepairPlanner($tree, $layout, $options, $glued, $treePrint);
                $planner->startFrom($bare, ReadbackRound::compare($bare, $read, $tree, $treePrint, false), $readParts, $glued);
                unset($bare);
            }
            unset($read);
        }
        unset($markedBare);
        $spans = null;
        if ($scoped !== null) {
            [$repaired, $spans] = $scoped;
        } else {
            $planner ??= new RepairPlanner($tree, $layout, $options, $seed, $treePrint);
            $repaired = $planner->repair() ?? self::passSettled($planner, $tree, $options, $treePrint);
        }
        $passes = $planner === null ? [] : [$planner];
        if ($repaired === null) {
            // C2-W-088: where the kind rounds end with a node that did not read back, rounds that escape only the
            // reported openers write the group again.
            $openers = (new RepairPlanner($tree, $layout, $options, $seed, $treePrint))->reportedOpenersAfter($planner);
            $passes[] = $openers;
            $repaired = $openers->repair() ?? self::passSettled($openers, $tree, $options, $treePrint);
            $spans = null;
            if ($repaired === null) {
                // C2-W-125: where every form loses a node, the passes by kind and by reported opener run again and
                // retest the escapes they placed together.
                $byKind = (new RepairPlanner($tree, $layout, $options, $seed, $treePrint))
                    ->chargedAfter($openers)->retestingAfter(null)->twinOf($planner);
                $passes[] = $byKind;
                $repaired = $byKind->repair() ?? self::passSettled($byKind, $tree, $options, $treePrint);
                if ($repaired === null) {
                    $last = (new RepairPlanner($tree, $layout, $options, $seed, $treePrint))
                        ->reportedOpenersAfter($byKind)->retestingAfter($byKind)->twinOf($openers);
                    $passes[] = $last;
                    $repaired = $last->repair() ?? self::passSettled($last, $tree, $options, $treePrint);
                }
            }
        }
        // The form of the groups where no pass settles every group; the authored-block fallback starts from it.
        $unsettled = null;
        if (count($passes) > 1 && ($repaired === null || end($passes)->resultEscapes() !== null)) {
            $local = null;
            if ($removed === [] && $result->diagnostics === []) {
                $groups ??= self::recording(
                    static fn (): string => self::localWrite($tree, $layout, $options, false)[0]->source,
                    false,
                )[1][0][1] ?? null;
                $local = $groups === null ? null : [$result->source, $groups];
            }
            [$joined, $holds] = self::groupPasses($passes, $local, $tree, $layout, $options, $treePrint) ?? [null, false];
            if ($holds) {
                $repaired = $joined;
            } elseif ($repaired === null) {
                $unsettled = $joined;
            }
        }
        unset($passes);

        if ($repaired === null) {
            // C2-W-125, case eight: the conservative form escapes the bracket of every `^[`, which lets a caret pair the
            // span of a text `^[` keeps ordinary open.
            if (($cellTicks || $conservativeLater) && !RepairPlanner::hasTextNoteBeforeCaretPair($tree)) {
                // The local write left the cell backticks to the rounds, so the conservative form stands where they stop.
                $result = self::conservativeWrite($original, $originalLayout, $options, $treePrint)[0];
                $fallback = self::conservativeFallback($result, $risk, $original, $treePrint, $options);
                if ($conservativeLater && ($result->diagnostics !== [] || $fallback !== null)) {
                    return $result;
                }
            }

            return self::unrepairedOr($fallback, $tree, $unsettled === null ? $result : new WriteResult($unsettled, []), $options);
        }

        $pruned = self::prunedNoteCarets($repaired, $treePrint, $options, $parsed);
        $repaired = self::prunedEmptyLabelBrackets($pruned, $treePrint, $options, $pruned === $repaired ? $spans : null);

        return new WriteResult($repaired, []);
    }

    /**
     * C2-W-088: the passes run group by group (carve-js `repairGroup`), so a top-level group keeps the form of the first
     * pass in which all of its blocks read back, and a group no pass settles takes the form of the last one. A group the
     * local write already gives (carve-js `planGroup`) keeps that form. The join of those forms and whether it reads
     * back as the tree; null for a single group or where the passes split the groups differently.
     *
     * @param array<int, \MarkupCarve\Carve\Writer\RepairPlanner> $passes
     * @param array{string, array<int, array{int, int, int, int, bool}>}|null $local The local write and its group ranges.
     * @param array<string, mixed> $tree
     * @param array<mixed>|null $layout
     * @param array<string, mixed> $options
     * @param string $treePrint
     *
     * @return array{string, bool}|null
     */
    private static function groupPasses(
        array $passes,
        ?array $local,
        array $tree,
        ?array $layout,
        array $options,
        string $treePrint,
    ): ?array {
        $treeParts = RepairPlanner::readPrints($tree)[1];
        $taken = [];
        $forms = [];
        foreach ($local === null ? $passes : [$local, ...$passes] as $pass) {
            if (is_array($pass)) {
                [$source, $ranges] = $pass;
            } else {
                $ranges = null;
                $source = self::recordedBare($tree, $layout, $options, $pass->resultEscapes() ?? $pass->lastEscapes(), $ranges);
            }
            if ($ranges === null || count($ranges) < 2 || ($forms !== [] && count($forms[0][1]) !== count($ranges))) {
                return null;
            }
            $forms[] = [$source, $ranges];
            $parts = RepairPlanner::readPrints(ReadbackRound::read($source, $options))[1];
            foreach ($ranges as $g => [$first, $last]) {
                $holds = !isset($taken[$g]);
                for ($i = $first; $i <= $last && $holds; $i++) {
                    $holds = ($treeParts['/children/' . $i] ?? null) === ($parts['/children/' . $i] ?? '');
                }
                if ($holds) {
                    $taken[$g] = count($forms) - 1;
                }
            }
        }
        [$lastSource, $lastRanges] = $forms[count($forms) - 1];
        $joined = '';
        $at = 0;
        foreach ($lastRanges as $g => [, , $start, $end]) {
            [$source, $ranges] = $forms[$taken[$g] ?? count($forms) - 1];
            $joined .= substr($lastSource, $at, $start - $at) . substr($source, $ranges[$g][2], $ranges[$g][3] - $ranges[$g][2]);
            $at = $end;
        }
        $joined .= substr($lastSource, $at);

        return [$joined, self::readsBack($joined, $treePrint, $options)];
    }

    /**
     * C2-W-075 (carve#6813): a pass that stops on a loss settles where its loss is only one of the temporary reference
     * forms: written with the verbatim reference texts, its escapes read back as the tree (carve-js `passSettled`).
     *
     * @param \MarkupCarve\Carve\Writer\RepairPlanner $pass
     * @param array<string, mixed> $tree
     * @param array<string, mixed> $options
     * @param string $treePrint
     */
    private static function passSettled(RepairPlanner $pass, array $tree, array $options, string $treePrint): ?string
    {
        if (self::$verbatimLayout === null) {
            return null;
        }
        $verbatim = self::bareSource($tree, self::$verbatimLayout, $options, $pass->lastEscapes());

        return self::readsBack($verbatim, $treePrint, $options) ? $verbatim : null;
    }

    /**
     * `bareSource()` with the byte range of each top-level block group, null where the write records none.
     *
     * @param array<string, mixed> $tree
     * @param array<mixed>|null $layout
     * @param array<string, mixed> $options
     * @param array<string, array<int, true>> $escapes
     * @param array<int, array{int, int, int, int, bool}>|null $ranges
     */
    private static function recordedBare(array $tree, ?array $layout, array $options, array $escapes, ?array &$ranges): string
    {
        [$source, $records] = self::recording(
            static fn (): string => self::bareSource($tree, $layout, $options, $escapes),
            self::$inGroups,
        );
        $ranges = null;
        foreach ($records as [$written, $groups]) {
            if ($written === $source) {
                $ranges = $groups;
            }
        }

        return $source;
    }

    /**
     * C2-W-089, C2-W-133: the rounds of the top-level groups that need them, as carve-js repairs group by group. A
     * group is kept where a block of it does not read back bare, is not `plain()` (it can feed the parse or resolve
     * stage of another block) or takes a pair escape (C2-W-146). The kept groups are repaired as a document of their
     * own from their bare form, and each takes its range in the bare form of the whole. Null where that does not hold:
     * a group writes otherwise alone, the kept groups read back bare alone, the rounds stop, or the join does not read
     * back; the rounds of the whole document decide there. With the join come the kept groups' ranges in it, each with
     * the source around it in its own document and the fingerprint of its blocks.
     *
     * @param array<string, mixed> $tree
     * @param array<mixed>|null $layout
     * @param array<string, mixed> $options
     * @param array<string, array<int, true>> $seed
     * @param array{string, array<string, string>|null} $prints The fingerprint of the tree, and its parts where known.
     * @param string $bare The bare form of the whole, written with `$seed`.
     * @param array<int, array{int, int, int, int, bool}>|null $ranges Its group ranges.
     * @param array<string, mixed> $read Its read-back.
     * @param array<string, string>|null $readParts `RepairPlanner::readPrints()` of that read-back.
     *
     * @return array{string, array<int, array{int, int, string, string, string}>}|null
     */
    private static function scopedRepair(
        array $tree,
        ?array $layout,
        array $options,
        array $seed,
        array $prints,
        string $bare,
        ?array $ranges,
        array $read,
        ?array $readParts,
    ): ?array {
        $children = is_array($tree['children'] ?? null) ? $tree['children'] : [];
        $readChildren = is_array($read['children'] ?? null) ? $read['children'] : [];
        if (
            $ranges === null || $readParts === null || count($ranges) < 2 || !array_is_list($children)
            || count($readChildren) !== count($children)
        ) {
            return null;
        }
        [$treePrint, $treeParts] = $prints;
        $treeParts ??= RepairPlanner::readPrints($tree)[1];
        $keep = [];
        foreach ($children as $i => $child) {
            $path = '/children/' . $i;
            if (!is_array($child) || !self::plain($child) || ($treeParts[$path] ?? null) !== ($readParts[$path] ?? '')) {
                $keep[$i] = true;
            }
        }
        foreach (array_keys(Text::treePairOpeners($tree, $seed)) as $path) {
            if (preg_match('~^/children/(\d+)(?:/|$)~', $path, $m) !== 1) {
                return null;
            }
            $keep[(int)$m[1]] = true;
        }
        $kept = [];
        $grouped = [];
        foreach ($ranges as $g => [$first, $last]) {
            for ($i = $first; $i <= $last; $i++) {
                $grouped[$i] = true;
                if (isset($keep[$i])) {
                    $kept[$g] = true;
                }
            }
        }
        if ($kept === [] || count($kept) === count($ranges) || array_diff_key($keep, $grouped) !== []) {
            return null;
        }
        $seeds = [];
        foreach ($seed as $path => $offsets) {
            if (preg_match('~^/children/(\\d+)(/.*)?$~', $path, $m) !== 1) {
                return null;
            }
            $seeds[(int)$m[1]][$m[2] ?? ''] = $offsets;
        }
        $index = self::rootIndex($layout);
        $forms = [];
        $spans = [];
        $joined = '';
        $at = 0;
        foreach ($ranges as $g => [$first, $last, $start, $end]) {
            if (!isset($kept[$g])) {
                continue;
            }
            $text = substr($bare, $start, $end - $start);
            $sub = $tree;
            $sub['children'] = array_slice($children, $first, $last - $first + 1);
            $subLayout = self::remapRange($layout, $index, $first, $last);
            $subSeed = [];
            for ($i = $first; $i <= $last; $i++) {
                foreach ($seeds[$i] ?? [] as $rest => $offsets) {
                    $subSeed['/children/' . ($i - $first) . $rest] = $offsets;
                }
            }
            // A control comment reads the lines of the whole source around it (`ControlLines::keepAbove()`).
            $key = str_contains($text, 'carve-lint-')
                ? null
                : md5(serialize([$sub['children'], self::facts($subLayout), $subSeed, $text]));
            $form = $key !== null && array_key_exists($key, $forms)
                ? $forms[$key]
                : self::groupRepair($sub, $subLayout, $options, $subSeed, $text);
            if ($form === null) {
                return null;
            }
            if ($key !== null) {
                $forms[$key] = $form;
            }
            $joined .= substr($bare, $at, $start - $at);
            $spans[] = [strlen($joined), strlen($joined) + strlen($form[0]), $form[1], $form[2], $form[3]];
            $joined .= $form[0];
            $at = $end;
        }
        $joined .= substr($bare, $at);

        return self::readsBack($joined, $treePrint, $options, true) ? [$joined, $spans] : null;
    }

    /**
     * The rounds of one kept group written as a document of its own, from its bare form: its text, the source before
     * and after it there, and the fingerprint of the group; null where it writes otherwise alone or the rounds stop.
     *
     * @param array<string, mixed> $sub
     * @param array<mixed>|null $layout
     * @param array<string, mixed> $options
     * @param array<string, array<int, true>> $seed
     * @param string $text The group in the bare form of the whole.
     *
     * @return array{string, string, string, string}|null
     */
    private static function groupRepair(array $sub, ?array $layout, array $options, array $seed, string $text): ?array
    {
        $bare = self::recordedBare($sub, $layout, $options, $seed, $ranges);
        if ($ranges === null || count($ranges) !== 1 || substr($bare, $ranges[0][2], $ranges[0][3] - $ranges[0][2]) !== $text) {
            return null;
        }
        $print = self::trimmedPrint($sub, $layout, $options);
        $read = ReadbackRound::read($bare, $options);
        [$readPrint, $parts] = RepairPlanner::readPrints($read);
        if ($readPrint === $print) {
            // The group misread in the whole only through another group, whose rounds the join reads back with it.
            return Text::treePairOpeners($sub, $seed) === []
                ? [$text, substr($bare, 0, $ranges[0][2]), substr($bare, $ranges[0][3]), $print]
                : null;
        }
        $marked = self::markedCandidate($sub, $layout, $options, $seed, $bare);
        $planner = new RepairPlanner($sub, $layout, $options, $seed, $print);
        $planner->startFrom($marked, ReadbackRound::compare($marked, $read, $sub, $print, false), $parts);
        unset($marked, $read);
        $repaired = $planner->repair();
        $escapes = $planner->resultEscapes();
        unset($planner);
        if ($repaired === null || $escapes === null) {
            return null;
        }
        $again = self::recordedBare($sub, $layout, $options, $escapes, $final);
        if ($again !== $repaired || $final === null || count($final) !== 1) {
            return null;
        }

        [, , $start, $end] = $final[0];

        return [substr($repaired, $start, $end - $start), substr($repaired, 0, $start), substr($repaired, $end), $print];
    }

    /**
     * C2-W-088: the form of the procedure where its first parse settles it. The bare form holds the escapes every form
     * carries (`Text::$recordedOwn`); where it reads back, no round reports a kind, and the planner adds only the pair
     * escapes of C2-W-146. A backslash of the input or of the local heuristics has no part in that form (C2-W-091).
     * Null where the bare form does not read back, which the rounds below take on.
     *
     * @param array<string, mixed> $tree
     * @param array<mixed>|null $layout
     * @param array<string, mixed> $options
     * @param array<string, array<int, true>> $own
     * @param string $wanted
     * @param array{string, array<string, mixed>, bool}|null $plain The source, read-back and fit of the form without
     *   escapes; null where `$own` is not empty.
     */
    private static function procedureForm(
        array $tree,
        ?array $layout,
        array $options,
        array $own,
        string $wanted,
        ?array $plain,
    ): ?WriteResult {
        if (self::hasExtensionNode($tree)) {
            return null;
        }
        [$bare, $read, $holds] = $plain ?? ['', [], false];
        if ($own !== []) {
            $bare = self::bareSource($tree, $layout, $options, $own);
            $read = ReadbackRound::read($bare, $options);
            $holds = TreeEquality::fingerprint($read) === $wanted;
        }
        // A backslash of C2-W-125 in the bare write can stand for a kind the bare parse reports; the rounds below
        // decide that form.
        if (!$holds || self::$bareRepaired) {
            return null;
        }
        unset($read);

        return self::pairRounds($tree, $layout, $options, $own, $wanted, $bare);
    }

    /**
     * The planner from a bare form that reads back, which adds only the pair escapes of C2-W-146.
     *
     * @param array<string, mixed> $tree
     * @param array<mixed>|null $layout
     * @param array<string, mixed> $options
     * @param array<string, array<int, true>> $own
     * @param string $wanted
     * @param string $bare
     */
    private static function pairRounds(
        array $tree,
        ?array $layout,
        array $options,
        array $own,
        string $wanted,
        string $bare,
    ): ?WriteResult {
        $planner = new RepairPlanner($tree, $layout, $options, $own, $wanted);
        // The bare form was read already; the planner goes on from it to the pair rounds.
        $planner->startFrom(
            ['source' => $bare, 'text' => [], 'paths' => [], 'nodes' => [], 'ranges' => []],
            ['equal' => true, 'openers' => [], 'moved' => [], 'read' => []],
        );
        $procedure = $planner->repair();

        return $procedure === null ? null : new WriteResult($procedure, []);
    }

    /**
     * C2-W-088: the procedure form of a supplied tree where the local form holds plan escapes. The bare form with the
     * escapes every form carries is taken where it reads back, with the pair escapes of C2-W-146. Null where it does
     * not read back, so the local form stands.
     *
     * @param array<string, mixed> $tree
     * @param array<mixed>|null $layout
     * @param array<string, mixed> $options
     * @param \MarkupCarve\Carve\Writer\WriteResult $result
     * @param array<string, array<int, true>> $localEscapes
     * @param array<string, array<int, true>> $localOwn
     * @param array<string, array<int, true>> $pairEscapes
     * @param string|null $wanted
     */
    private static function suppliedProcedure(
        array $tree,
        ?array $layout,
        array $options,
        WriteResult $result,
        array $localEscapes,
        array $localOwn,
        array $pairEscapes,
        ?string $wanted = null,
    ): ?WriteResult {
        // A nested write, as of an extension body, reads back without the link keys of its document.
        if (
            $localEscapes === [] || $result->diagnostics !== [] || self::$lossReported
            || isset($options['linkKeys'])
        ) {
            return null;
        }
        $wanted ??= self::trimmedPrint($tree, $layout, $options);
        $asked = Text::treePairOpeners($tree, $localOwn);
        // Where the pair rules ask the bare form for the pair escapes the local write placed, one write holds both.
        $seed = $localOwn;
        if ($asked !== [] && $asked == $pairEscapes) {
            foreach ($pairEscapes as $path => $offsets) {
                $seed[$path] = ($seed[$path] ?? []) + $offsets;
            }
        }
        $bare = self::bareSource($tree, $layout, $options, $seed);
        if ($bare === $result->source || self::$bareRepaired || !self::readsBack($bare, $wanted, $options)) {
            return null;
        }
        if ($asked === [] || ($seed !== $localOwn && Text::treePairOpeners($tree, $seed) === [])) {
            return new WriteResult($bare, []);
        }
        if ($seed !== $localOwn) {
            $bare = self::bareSource($tree, $layout, $options, $localOwn);
            if (!self::readsBack($bare, $wanted, $options)) {
                return null;
            }
        }

        return self::pairRounds($tree, $layout, $options, $localOwn, $wanted, $bare);
    }

    /**
     * Whether a local write the heuristics call safe is taken without the rounds: it equals the source the tree was
     * parsed from, below that source's leading empty lines too, the tree has no ASCII punctuation in its strings, or the
     * tree is supplied, holds no brace or pair mark that may pair with the bare spelling of a span, and reads back as
     * itself where C2-A-161 can compare it.
     *
     * @param array<string, mixed> $tree
     * @param string|null $parsed
     * @param \MarkupCarve\Carve\Writer\WriteResult $result
     * @param array<string, mixed> $options
     */
    private static function readsBackUnread(array $tree, ?string $parsed, WriteResult $result, array $options): bool
    {
        if ($parsed === null && !self::hasBracedCloserRisk($tree) && !self::hasPairMarkRisk($tree)) {
            // No punctuation in a supplied tree's strings proves nothing: a shortcut link whose text is not its key misreads.
            return $result->diagnostics !== [] || self::$lossReported || !self::comparable($tree)
                || self::readsBackAsTree($tree, $result->source, $options) || !self::readerAccepts($tree, $options);
        }

        return $parsed === $result->source
            || ($parsed !== null && self::sameBelowLeadingBlankLines($parsed, $result->source))
            || !self::hasPunctuation($tree);
    }

    /**
     * Empty lines before the first line of a document read as nothing; with no container open they only move the line
     * a frontmatter opener has to stand on (C2-B-161, C2-B-232), so a source that starts so keeps its read-back.
     */
    private static function sameBelowLeadingBlankLines(string $parsed, string $source): bool
    {
        $blank = strspn($parsed, "\n");
        // A byte-order mark is skipped only at the start of the source (C2-B-165).
        if (
            $blank === 0 || strlen($parsed) - $blank !== strlen($source) || ($source[0] ?? '-') === '-'
            || str_starts_with($source, "\u{FEFF}")
        ) {
            return false;
        }

        return substr_compare($parsed, $source, $blank) === 0;
    }

    /**
     * Braced sup/sub marks and plain text have no pair or sigil escape decisions.
     * Other punctuation keeps the local planner's canonical escape choices.
     */
    private static function simpleBracedSource(string $source): bool
    {
        if (strspn($source, "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789 {},^\\\n") !== strlen($source)) {
            return false;
        }
        $at = 0;
        while (($at = strpos($source, '\\', $at)) !== false) {
            $run = strspn($source, '\\', $at);
            if (($run & 1) !== 0) {
                return false;
            }
            $at += $run;
        }

        return true;
    }

    /**
     * The local form may add a backslash before a literal brace followed by a braced-only mark.
     * Every other byte, including the spelling of all nodes, must be unchanged.
     */
    private static function onlyAddedBraceEscapes(string $source, string $written): bool
    {
        $i = 0;
        $j = 0;
        $sourceLength = strlen($source);
        $writtenLength = strlen($written);
        while ($i < $sourceLength && $j < $writtenLength) {
            if ($source[$i] === $written[$j]) {
                $i++;
                $j++;

                continue;
            }
            if ($written[$j] !== '\\' || ($source[$i] ?? '') !== '{' || !str_contains('^,', $source[$i + 1] ?? '')) {
                return false;
            }
            $j++;
        }

        return $i === $sourceLength && $j === $writtenLength;
    }

    /**
     * Whether the escapes of the local write are of the kinds whose bare form the writer takes where it reads back.
     *
     * @param array<string, mixed> $tree
     * @param array<string, array<int, true>> $localEscapes
     * @param string $parsed
     */
    private static function mayTakeBare(array $tree, array $localEscapes, string $parsed): bool
    {
        return RepairPlanner::escapesOnly($tree, $localEscapes, '{')
            || (!str_contains($parsed, '\\') && RepairPlanner::escapesOnly($tree, $localEscapes, '{:!+#</*|['));
    }

    /**
     * Whether every backslash of the local write is a C2-W-146 pair escape on a `_`, `~` or `=` that no brace opens,
     * the marks whose bare pairs are text (C2-I-043).
     *
     * @param array<string, mixed> $tree
     * @param array<string, array<int, true>> $localEscapes
     * @param array<string, array<int, true>> $pairEscapes
     * @param string $written
     */
    private static function onlyPairEscapes(array $tree, array $localEscapes, array $pairEscapes, string $written): bool
    {
        // Some backslashes are written outside the text escapes, as before the tail of a link.
        $count = array_sum(array_map('count', $localEscapes));
        if (
            substr_count($written, '\\') !== $count
            || substr_count($written, '\\_') + substr_count($written, '\\~') + substr_count($written, '\\=') !== $count
        ) {
            return false;
        }
        foreach ($localEscapes as $path => $offsets) {
            $pairs = $pairEscapes[$path] ?? [];
            if (count($pairs) !== count($offsets)) {
                return false;
            }
            $value = RepairPlanner::leafValue($tree, $path);
            foreach ($offsets as $at => $_) {
                if (
                    !isset($pairs[$at]) || $value === null || !str_contains('_~=', $value[$at] ?? '')
                    || ($at > 0 && $value[$at - 1] === '{')
                ) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Whether the local write differs from the parsed source only by added
     * backslashes before the given punctuation.
     */
    private static function onlyAddedEscapes(string $source, string $written, string $characters): bool
    {
        if (!str_ends_with($source, "\n")) {
            $source .= "\n";
        }
        $i = 0;
        $j = 0;
        $added = false;
        $sourceLength = strlen($source);
        $writtenLength = strlen($written);
        while ($i < $sourceLength && $j < $writtenLength) {
            if ($source[$i] === $written[$j]) {
                $i++;
                $j++;

                continue;
            }
            if ($written[$j] !== '\\' || !str_contains($characters, $source[$i])) {
                return false;
            }
            $added = true;
            $j++;
        }

        return $added && $i === $sourceLength && $j === $writtenLength;
    }

    /**
     * The conservative form the rounds could not improve on, else the report of `unrepaired()`.
     *
     * @param \MarkupCarve\Carve\Writer\WriteResult|null $fallback
     * @param array<string, mixed> $tree
     * @param \MarkupCarve\Carve\Writer\WriteResult $result
     * @param array<string, mixed> $options
     */
    private static function unrepairedOr(?WriteResult $fallback, array $tree, WriteResult $result, array $options): WriteResult
    {
        return $fallback ?? self::unrepaired($tree, $result, $options);
    }

    /**
     * C2-W-091: the conservative form kept where the rounds find none, null where it reports a loss, the tree carries
     * round risk, or it reads as another tree.
     *
     * @param \MarkupCarve\Carve\Writer\WriteResult $result
     * @param bool $risk
     * @param array<string, mixed> $tree
     * @param string $treePrint
     * @param array<string, mixed> $options
     */
    private static function conservativeFallback(
        WriteResult $result,
        bool $risk,
        array $tree,
        string $treePrint,
        array $options,
    ): ?WriteResult {
        if ($risk || $result->diagnostics !== []) {
            return null;
        }

        return !self::comparable($tree) || self::readsBack($result->source, $treePrint, $options)
            || !self::readerAccepts($tree, $options)
            ? $result
            : null;
    }

    // phpcs:enable Generic.Metrics.CyclomaticComplexity.MaxExceeded

    /**
     * Whether the local write is taken where it reads back, without the rounds: the heuristics call it safe, or it
     * decided all its brackets and its only escapes are the glued braces of C2-W-125 case one, which give no kind
     * (C2-W-010, C2-W-088; carve-js `leavesKind`).
     *
     * @param bool $unchecked
     * @param bool $undecided
     * @param array<string, array<int, true>> $decided The other escapes of the local write, by text path.
     */
    private static function takesLocalForm(bool $unchecked, bool $undecided, array $decided): bool
    {
        return $unchecked || (!$undecided && $decided === []);
    }

    /**
     * The local write with every undecided bracket escaped: the brackets alone first, since a sigil glued to a node
     * mostly forms nothing with it, and every opener where that does not read back.
     *
     * @param array<string, mixed> $tree
     * @param array<mixed>|null $layout
     * @param array<string, mixed> $options
     * @param string $treePrint
     *
     * @return array{
     *     \MarkupCarve\Carve\Writer\WriteResult,
     *     array<string, array<int, true>>,
     *     bool,
     *     array<string, array<int, true>>
     * }
     */
    private static function conservativeWrite(array $tree, ?array $layout, array $options, string $treePrint): array
    {
        $local = self::localWrite($tree, $layout, $options, true, true);
        if ($local[0]->diagnostics !== [] || !self::readsBack($local[0]->source, $treePrint, $options)) {
            $local = self::localWrite($tree, $layout, $options, true);
        }

        return $local;
    }

    /**
     * `withPrunedNoteCarets()` on a local write the heuristics call safe, read back only where it holds a caret pair.
     *
     * @param \MarkupCarve\Carve\Writer\WriteResult $result
     * @param string|null $parsed
     * @param array<string, mixed> $tree
     * @param array<mixed>|null $layout
     * @param array<string, mixed> $options
     */
    private static function uncheckedPruned(WriteResult $result, ?string $parsed, array $tree, ?array $layout, array $options): WriteResult
    {
        if ($parsed === $result->source || !self::notePrunable($result->source)) {
            return $result;
        }

        return self::withPrunedNoteCarets($result, self::trimmedPrint($tree, $layout, $options), $options, $parsed);
    }

    /**
     * `prunedNoteCarets()` on a local write that reads back: its case-five escapes do not see the text `^[` before.
     *
     * @param \MarkupCarve\Carve\Writer\WriteResult $result
     * @param string $treePrint
     * @param array<string, mixed> $options
     * @param string|null $parsed The source the tree was parsed from.
     */
    private static function withPrunedNoteCarets(WriteResult $result, string $treePrint, array $options, ?string $parsed): WriteResult
    {
        $pruned = self::prunedEmptyLabelBrackets(
            self::prunedNoteCarets($result->source, $treePrint, $options, $parsed),
            $treePrint,
            $options,
        );

        return $pruned === $result->source ? $result : new WriteResult($pruned, $result->diagnostics);
    }

    /**
     * C2-I-089, C2-W-125 case five: behind a text `^[` of the line that is still open, and inside an inline footnote,
     * the `[^` and `^[` of link or span text are ordinary brackets, so the backslash the rounds wrote before the caret,
     * and those of the brackets of that text, are taken out where the form still reads back (carve-js
     * `prunableOpener`). In the span a backslash before the `[` of `^[` still counts it, and an escaped `]` closes
     * nothing (carve-js `noteSpans`).
     *
     * @param string $source
     * @param string $treePrint
     * @param array<string, mixed> $options
     * @param string|null $parsed The source the tree was parsed from.
     */
    private static function prunedNoteCarets(string $source, string $treePrint, array $options, ?string $parsed = null): string
    {
        // A form equal to the source the tree was parsed from, up to its final line feed, reads back as that tree.
        $readsBack = static fn (string $form): bool => $form === $parsed || $form === $parsed . "\n"
            || self::readsBack($form, $treePrint, $options);
        if (!self::notePrunable($source)) {
            return $source;
        }
        [$pruned, $starts] = self::noteCaretStarts($source);
        if ($starts === []) {
            return $source;
        }
        // C2-W-088: the brackets of that text took their backslashes only as the partners of the caret pair.
        $bare = self::bareNoteBrackets($source, $starts, ['[', ']', '^']);
        if ($bare !== $pruned && $readsBack($bare)) {
            return $bare;
        }
        if ($pruned !== $source && $readsBack($pruned)) {
            return $pruned;
        }
        // C2-W-125 case five keeps the caret's backslash where the link would not form without it.
        $brackets = self::bareNoteBrackets($source, $starts, ['[', ']']);

        return $brackets !== $source && $brackets !== $bare && $readsBack($brackets) ? $brackets : $source;
    }

    /**
     * C2-W-125: a backslash the rounds left before the `[` of a text `[^]` is taken out where the form still reads back
     * and the bare parse reports no `[` kind in its leaf block (carve-js `prunableBracket`, `reportsBracketKind`).
     *
     * @param string $source
     * @param string $treePrint
     * @param array<string, mixed> $options
     * @param array<int, array{int, int, string, string, string}>|null $spans The groups `scopedRepair()` repaired alone
     *   in `$source`: a trial that drops bytes only there is read group by group, as those groups were.
     */
    private static function prunedEmptyLabelBrackets(string $source, string $treePrint, array $options, ?array $spans = null): string
    {
        $at = strpos($source, '\\[^]');
        if ($at === false) {
            return $source;
        }
        [$leaves, $prints] = self::bracketKindLeaves($source, $options);
        $sorted = self::sortedLeaves($leaves);
        $pending = self::prunableBrackets($source, $at, $leaves, $sorted);
        // C2-W-010: the candidates are taken out together, so one parse decides many (carve-js `pruneInRounds`). A
        // candidate alone in a leaf that then differs keeps its backslash; of several there, the first is tried next.
        $removed = [];
        $waiting = [];
        $alone = [];
        for ($round = 0; $pending !== []; $round++) {
            if ($round === 16) {
                return $source;
            }
            $drop = [...$removed, ...$pending];
            sort($drop);
            $grouped = $spans !== null && self::spansReadBack($source, $drop, $spans, $options, $alone);
            $read = $grouped ? null : ReadbackRound::read(self::withoutBytes($source, $drop), $options);
            if ($read === null || TreeEquality::fingerprint($read) === $treePrint) {
                array_push($removed, ...$pending);
                $pending = $waiting;
                $waiting = [];

                continue;
            }
            $read = self::leafBlocks($read);
            if (count($read) !== count($prints)) {
                return $source;
            }
            $decided = [];
            $byLeaf = self::pendingByLeaf($leaves, $sorted, $pending);
            foreach ($read as $index => $leaf) {
                if (TreeEquality::fingerprint($leaf) === $prints[$index]) {
                    continue;
                }
                $inside = $byLeaf[$index] ?? [];
                if ($inside === []) {
                    return $source;
                }
                foreach (count($inside) === 1 ? $inside : array_slice($inside, 1) as $at) {
                    $decided[$at] = true;
                }
                array_push($waiting, ...array_slice($inside, 1));
            }
            $pending = array_values(array_filter($pending, static fn (int $at): bool => !isset($decided[$at])));
            if ($pending === []) {
                sort($waiting);
                $pending = $waiting;
                $waiting = [];
            }
        }
        sort($removed);

        return self::withoutBytes($source, $removed);
    }

    /**
     * The offsets of the unescaped backslashes before a text `[^]`, from `$at` on, whose leaf reports no `[` kind.
     *
     * @param string $source
     * @param int $at
     * @param array<array{int, int, bool}> $leaves
     * @param array<int>|null $sorted
     *
     * @return array<int>
     */
    private static function prunableBrackets(string $source, int $at, array $leaves, ?array $sorted): array
    {
        $pending = [];
        for (; $at !== false; $at = strpos($source, '\\[^]', $at + 1)) {
            $run = 0;
            while ($at - $run > 0 && $source[$at - $run - 1] === '\\') {
                $run++;
            }
            if ($run % 2 === 0 && !self::reportsBracketKind($leaves, $sorted, $at)) {
                $pending[] = $at;
            }
        }

        return $pending;
    }

    /**
     * `$source` without the bytes at the ascending offsets `$drop`.
     *
     * @param string $source
     * @param array<int> $drop
     */
    private static function withoutBytes(string $source, array $drop): string
    {
        $out = '';
        $from = 0;
        foreach ($drop as $at) {
            $out .= substr($source, $from, $at - $from);
            $from = $at + 1;
        }

        return $out . substr($source, $from);
    }

    /**
     * Whether every byte of `$drop` lies in a group of `$spans`, and each such group without them reads back alone.
     *
     * @param string $source
     * @param array<int> $drop Sorted offsets.
     * @param array<int, array{int, int, string, string, string}> $spans
     * @param array<string, mixed> $options
     * @param array<string, bool> $alone The groups read so far, by source.
     */
    private static function spansReadBack(string $source, array $drop, array $spans, array $options, array &$alone): bool
    {
        $s = 0;
        $count = count($spans);
        $inside = [];
        foreach ($drop as $at) {
            while ($s < $count && $spans[$s][1] <= $at) {
                $s++;
            }
            if ($s === $count || $at < $spans[$s][0]) {
                return false;
            }
            $inside[$s][] = $at - $spans[$s][0];
        }
        foreach ($inside as $s => $offsets) {
            [$start, $end, $before, $after, $print] = $spans[$s];
            $text = $before . self::withoutBytes(substr($source, $start, $end - $start), $offsets) . $after;
            $alone[$text] ??= self::readsBack($text, $print, $options);
            if (!$alone[$text]) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<array{int, int, bool}> $leaves
     * @param array<int>|null $sorted
     * @param int $at
     */
    private static function reportsBracketKind(array $leaves, ?array $sorted, int $at): bool
    {
        $index = self::leafAt($leaves, $sorted, $at);

        return $index !== null && $leaves[$index][2];
    }

    /**
     * The indexes of the leaves with a range, by start, where those ranges do not overlap; null where they do.
     *
     * @param array<array{int, int, bool}> $leaves
     *
     * @return array<int>|null
     */
    private static function sortedLeaves(array $leaves): ?array
    {
        $sorted = [];
        $last = -1;
        foreach ($leaves as $index => [$start, $end]) {
            if ($start < 0 || $end <= $start) {
                continue;
            }
            if ($start < $last) {
                return null;
            }
            $sorted[] = $index;
            $last = $end;
        }

        return $sorted;
    }

    /**
     * The first leaf whose range holds `$at`: a binary search over `sortedLeaves()`, a scan where that is null.
     *
     * @param array<array{int, int, bool}> $leaves
     * @param array<int>|null $sorted
     * @param int $at
     */
    private static function leafAt(array $leaves, ?array $sorted, int $at): ?int
    {
        if ($sorted === null) {
            foreach ($leaves as $index => [$start, $end]) {
                if ($start <= $at && $at < $end) {
                    return $index;
                }
            }

            return null;
        }
        $low = 0;
        $high = count($sorted) - 1;
        while ($low <= $high) {
            $middle = ($low + $high) >> 1;
            [$start, $end] = $leaves[$sorted[$middle]];
            if ($at < $start) {
                $high = $middle - 1;
            } elseif ($at >= $end) {
                $low = $middle + 1;
            } else {
                return $sorted[$middle];
            }
        }

        return null;
    }

    /**
     * The pending offsets inside each leaf, in their pending order.
     *
     * @param array<array{int, int, bool}> $leaves
     * @param array<int>|null $sorted
     * @param array<int> $pending
     *
     * @return array<int, array<int>>
     */
    private static function pendingByLeaf(array $leaves, ?array $sorted, array $pending): array
    {
        $byLeaf = [];
        if ($sorted === null) {
            foreach ($leaves as $index => [$start, $end]) {
                $inside = array_values(array_filter($pending, static fn (int $at): bool => $start <= $at && $at < $end));
                if ($inside !== []) {
                    $byLeaf[$index] = $inside;
                }
            }

            return $byLeaf;
        }
        foreach ($pending as $at) {
            $index = self::leafAt($leaves, $sorted, $at);
            if ($index !== null) {
                $byLeaf[$index][] = $at;
            }
        }

        return $byLeaf;
    }

    /**
     * The leaf blocks of the parse of `$source` by their range, each with whether the parse of its bare text, every
     * backslash before ASCII punctuation taken out, holds more nodes that open on a bracket (C2-W-088).
     *
     * @param string $source
     * @param array<string, mixed> $options
     *
     * @return array{array<array{int, int, bool}>, array<string>} The leaves, and the fingerprint of each.
     */
    private static function bracketKindLeaves(string $source, array $options): array
    {
        $bare = (string)preg_replace_callback(
            '/\\\\(.)/s',
            static fn (array $m): string => Text::isPunct($m[1]) ? $m[1] : $m[0],
            $source,
        );
        $bareLeaves = self::leafBlocks(ReadbackRound::read($bare, $options));
        $leaves = self::leafBlocks(ReadbackRound::read($source, $options));
        $found = [];
        $prints = [];
        foreach ($leaves as $index => $leaf) {
            $range = TreeEncoder::offsets($leaf['pos'] ?? null) ?? [-1, -1];
            $other = $bareLeaves[$index] ?? null;
            $reported = count($bareLeaves) !== count($leaves) || $other === null || self::moreBracketNodes($other, $leaf, $bare);
            $found[] = [$range[0], $range[1], $reported];
            $prints[] = TreeEquality::fingerprint($leaf);
        }

        return [$found, $prints];
    }

    /**
     * @param array<mixed> $node
     *
     * @return array<array<mixed>>
     */
    private static function leafBlocks(array $node): array
    {
        $leaves = [];
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            if (!is_array($child)) {
                continue;
            }
            if (is_string($child['type'] ?? null) && isset(DataModel::INLINE_PARENTS[$child['type']])) {
                $leaves[] = $child;
            } else {
                array_push($leaves, ...self::leafBlocks($child));
            }
        }

        return $leaves;
    }

    /**
     * Whether `$bare` holds more nodes of a type that opens on a bracket of `$text` (`[`, `^[`, `![`) than `$tree`.
     *
     * @param array<mixed> $bare
     * @param array<mixed> $tree
     * @param string $text
     */
    private static function moreBracketNodes(array $bare, array $tree, string $text): bool
    {
        $counts = [];
        foreach (self::descendants($bare) as $node) {
            $start = TreeEncoder::offsets($node['pos'] ?? null)[0] ?? -1;
            $opens = $start >= 0 && (($text[$start] ?? '') === '['
                || (($text[$start + 1] ?? '') === '[' && in_array($text[$start] ?? '', ['!', '^'], true)));
            if ($opens) {
                $type = is_string($node['type'] ?? null) ? $node['type'] : '';
                $counts[$type] = ($counts[$type] ?? 0) + 1;
            }
        }
        foreach (self::descendants($tree) as $node) {
            $type = is_string($node['type'] ?? null) ? $node['type'] : '';
            if (isset($counts[$type])) {
                $counts[$type]--;
            }
        }

        return array_filter($counts, static fn (int $count): bool => $count > 0) !== [];
    }

    /**
     * The nodes below `$root` that are not text.
     *
     * @param array<mixed> $root
     *
     * @return array<array<mixed>>
     */
    private static function descendants(array $root): array
    {
        $found = [];
        $pending = is_array($root['children'] ?? null) ? $root['children'] : [];
        while ($pending !== []) {
            $node = array_pop($pending);
            if (!is_array($node)) {
                continue;
            }
            if (($node['type'] ?? '') !== 'text') {
                $found[] = $node;
            }
            foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
                $pending[] = $child;
            }
        }

        return $found;
    }

    /**
     * The source without the backslash before the caret of each `[\\^` inside a note, and the offsets of the brackets
     * whose text `bareNoteBrackets()` may write bare.
     *
     * @param string $source
     *
     * @return array{string, array<int>}
     */
    // phpcs:disable Generic.Metrics.CyclomaticComplexity.MaxExceeded

    private static function noteCaretStarts(string $source): array
    {
        $pruned = '';
        $from = 0;
        $open = [];
        $notes = 0;
        $crossedLine = false;
        $starts = [];
        $n = strlen($source);
        // The next caret pair with an escaped bracket and the next line end, found once per position so the scan stays
        // linear.
        $noteCaret = -1;
        $refCaret = -1;
        $lineEnd = -1;
        for ($at = strcspn($source, "[]\n"); $at < $n; $at += 1 + strcspn($source, "[]\n", $at + 1)) {
            if ($noteCaret !== false && $noteCaret < $at) {
                $noteCaret = strpos($source, '^\\[', $at);
            }
            if ($refCaret !== false && $refCaret < $at) {
                $refCaret = strpos($source, '\\[^', $at);
            }
            $caret = $noteCaret === false ? $refCaret : ($refCaret === false ? $noteCaret : min($noteCaret, $refCaret));
            if ($lineEnd !== false && $lineEnd < $at) {
                $lineEnd = strpos($source, "\n", $at);
            }
            $ch = $source[$at];
            $before = $at > 0 ? $source[$at - 1] : '';
            if ($ch === "\n") {
                // An escaped note closer can leave the would-be note open across a soft break.
                $crossedLine = true;
            } elseif ($ch === '[') {
                $note = $before === '^' || ($before === '\\' && $at > 1 && $source[$at - 2] === '^');
                $pruneAcrossLine = !$crossedLine || substr($source, $at + 3, 2) === '](';
                // C2-W-125 case eight across a line is decided on the tree (`Inline::markCaseEightLinks()`), and a
                // reference written as authored keeps its spelling (C2-W-075).
                if (
                    !$note && $notes > 0 && !$crossedLine && substr($source, $at + 1, 2) === '\\^'
                    && substr($source, $at + 3, 2) !== ']['
                ) {
                    $pruned .= substr($source, $from, $at + 1 - $from);
                    $from = $at + 2;
                    $starts[] = $at;
                } elseif (
                    !$note && $before !== '\\' && $notes > 0 && $pruneAcrossLine && $caret !== false
                    && ($lineEnd === false || $caret < $lineEnd)
                ) {
                    // An escaped bracket opens no text, so nothing up to the line end is its content.
                    $starts[] = $at;
                }
                $open[] = $note;
                $notes += $note ? 1 : 0;
                if ($note) {
                    $crossedLine = false;
                }
            } elseif ($before !== '\\' && array_pop($open) === true) {
                $notes--;
            }
        }
        if ($starts === []) {
            return [$source, []];
        }

        return [$pruned . substr($source, $from), $starts];
    }

    // phpcs:enable Generic.Metrics.CyclomaticComplexity.MaxExceeded

    /**
     * Whether the source holds a caret pair with an escape that `prunedNoteCarets()` may take out.
     */
    private static function notePrunable(string $source): bool
    {
        return str_contains($source, '[\\^') || str_contains($source, '^\\[') || str_contains($source, '\\[^');
    }

    /**
     * The source with the backslash taken out before each of `$chars` in the text that starts at each offset of
     * `$starts`, up to the bare `]` that closes it.
     *
     * @param string $source
     * @param array<int> $starts The offsets of the `[` before an escaped caret.
     * @param array<string> $chars The escaped characters whose backslash is taken out.
     */
    private static function bareNoteBrackets(string $source, array $starts, array $chars): string
    {
        $bare = '';
        $from = 0;
        $n = strlen($source);
        $done = 0;
        foreach ($starts as $start) {
            if ($start < $done) {
                continue;
            }
            $depth = 0;
            for ($at = $start; $at < $n && $source[$at] !== "\n"; $at++) {
                $ch = $source[$at];
                if ($ch === '\\' && in_array($source[$at + 1] ?? '', $chars, true)) {
                    $bare .= substr($source, $from, $at - $from);
                    $from = $at + 1;
                    $at++;
                } elseif ($ch === '\\') {
                    $at++;
                } elseif ($ch === '[') {
                    $depth++;
                } elseif ($ch === ']' && --$depth === 0) {
                    break;
                }
            }
            $done = $at;
        }

        return $bare . substr($source, $from);
    }

    /**
     * The local write, and its group ranges where the check is the outer one and a group carries risk.
     *
     * @param array<string, mixed> $tree
     * @param array<mixed>|null $layout
     * @param array<string, mixed> $options
     * @param bool $risk
     * @param bool $supplied Whether the tree is no parse of a source, so no round reads the local write back.
     *
     * @return array{
     *     \MarkupCarve\Carve\Writer\WriteResult,
     *     array<string, array<int, true>>,
     *     bool,
     *     array<string, array<int, true>>,
     *     array<string, array<int, true>>,
     *     array<int, array{int, int, int, int, bool}>|null
     * }
     */
    private static function firstWrite(array $tree, ?array $layout, array $options, bool $risk, bool $supplied = false): array
    {
        // A nested write (an extension's read-back) must not reset the flag of the outer write.
        $outer = Text::$suppliedTree;
        Text::$suppliedTree = $supplied;
        try {
            if (self::$inGroups || !$risk) {
                return [...self::localWrite($tree, $layout, $options, false), null];
            }
            [$local, $records] = self::recording(static fn (): array => self::localWrite($tree, $layout, $options, false), false);
        } finally {
            Text::$suppliedTree = $outer;
        }

        return [...$local, $records[0][1] ?? null];
    }

    /**
     * The local write where it reports a loss, else the result of the group check.
     *
     * @param array<string, mixed> $tree
     * @param array<mixed>|null $layout
     * @param array<string, mixed> $options
     * @param \MarkupCarve\Carve\Writer\WriteResult $result
     * @param array<int, array{int, int, int, int, bool}>|null $groups
     * @param string|null $parsed
     * @param bool $risk
     *
     * @return \MarkupCarve\Carve\Writer\WriteResult|null
     */
    private static function settled(
        array $tree,
        ?array $layout,
        array $options,
        WriteResult $result,
        ?array $groups,
        ?string $parsed,
        bool $risk,
    ): ?WriteResult {
        if ($result->diagnostics !== []) {
            return $result;
        }
        $alone = $risk && $groups !== null ? self::checkedAlone($tree, $layout, $options, $result->source, $groups) : null;
        if ($alone !== null && ($alone === $parsed || self::readsBack($alone, self::trimmedPrint($tree, $layout, $options), $options))) {
            return new WriteResult($alone, []);
        }

        return self::checkedGroups($tree, $layout, $options, $result->source, $groups, $parsed, $risk);
    }

    /**
     * C2-W-133 where the kinds of the bare form do not settle and every block is plain: each group is written as a
     * document of its own, as carve-js repairs group by group, and the join is read back once.
     *
     * @param array<string, mixed> $tree
     * @param array<mixed>|null $layout
     * @param array<string, mixed> $options
     * @param \MarkupCarve\Carve\Writer\WriteResult $result
     * @param array<int, array{int, int, int, int, bool}>|null $groups The group ranges of the local write.
     * @param string|null $parsed
     *
     * @return \MarkupCarve\Carve\Writer\WriteResult|null
     */
    private static function settledAlone(
        array $tree,
        ?array $layout,
        array $options,
        WriteResult $result,
        ?array $groups,
        ?string $parsed,
    ): ?WriteResult {
        if ($result->diagnostics !== [] || self::$inGroups || !self::plainGroups($tree, $options)) {
            return null;
        }
        if ($groups === null || count($groups) < 2) {
            return null;
        }
        $all = [];
        foreach (array_keys($groups) as $g) {
            $all[$g] = true;
        }
        $alone = self::checkedAlone($tree, $layout, $options, $result->source, $groups, $all);
        if ($alone !== null && ($alone === $parsed || self::readsBack($alone, self::trimmedPrint($tree, $layout, $options), $options))) {
            return new WriteResult($alone, []);
        }

        return null;
    }

    /**
     * Whether the document has two or more top-level blocks and each is plain, so no block feeds the parse or resolve
     * stage of another.
     *
     * @param array<string, mixed> $tree
     * @param array<string, mixed> $options
     */
    private static function plainGroups(array $tree, array $options): bool
    {
        $children = is_array($tree['children'] ?? null) ? $tree['children'] : [];
        if (count($children) < 2 || isset($options['extensions'])) {
            return false;
        }
        foreach ($children as $child) {
            if (!is_array($child) || !self::plain($child)) {
                return false;
            }
        }

        return true;
    }

    /**
     * C2-W-133: the read-back rounds of the groups that need them, with only those groups and the blocks the parse
     * and resolve stage read beside them. The other groups keep their local write, and the joined form is read back
     * once; null where a risky group writes otherwise alone or the join does not read back.
     *
     * @param array<string, mixed> $tree
     * @param array<mixed>|null $layout
     * @param array<string, mixed> $options
     * @param string $source The local write.
     * @param array<int, array{int, int, int, int, bool}>|null $groups Its group ranges.
     * @param string|null $parsed
     * @param bool $risk Whether a group carries round risk.
     *
     * @return \MarkupCarve\Carve\Writer\WriteResult|null
     */
    private static function checkedGroups(
        array $tree,
        ?array $layout,
        array $options,
        string $source,
        ?array $groups,
        ?string $parsed,
        bool $risk,
    ): ?WriteResult {
        $children = is_array($tree['children'] ?? null) ? array_values($tree['children']) : [];
        $kept = $risk && $groups !== null ? self::keptGroups($children, $groups, strlen($source)) : null;
        if ($kept === null) {
            return null;
        }
        [$keep, $risky, $map, $expected] = $kept;
        $sub = $tree;
        $sub['children'] = array_values(array_intersect_key($children, $map));
        $subLayout = self::remapRoot($layout, $map);
        [$subResult, $captures] = self::recording(static fn (): WriteResult => self::writeChecked($sub, $subLayout, $options), true);
        $finalRanges = null;
        foreach ($captures as [$written, $ranges]) {
            if ($written === $subResult->source) {
                $finalRanges = $ranges;
            }
        }
        [$subLocal, $localRanges] = $captures[0] ?? ['', null];
        if (
            $subResult->diagnostics !== [] || $localRanges === null || $finalRanges === null
            || self::spans($localRanges) !== $expected || self::spans($finalRanges) !== $expected
        ) {
            return null;
        }
        $joined = '';
        $at = 0;
        $j = 0;
        foreach ($groups as $g => [, , $start, $end]) {
            $joined .= substr($source, $at, $start - $at);
            $at = $end;
            $text = substr($source, $start, $end - $start);
            $j += isset($keep[$g]) ? 1 : 0;
            if (!isset($risky[$g])) {
                $joined .= $text;

                continue;
            }
            [, , $localStart, $localEnd] = $localRanges[$j - 1];
            [, , $finalStart, $finalEnd] = $finalRanges[$j - 1];
            // A risky group that writes otherwise alone is decided with its neighbors.
            if (substr($subLocal, $localStart, $localEnd - $localStart) !== $text) {
                return null;
            }
            $joined .= substr($subResult->source, $finalStart, $finalEnd - $finalStart);
        }
        $joined .= substr($source, $at);

        return $joined === $parsed || self::readsBack($joined, self::trimmedPrint($tree, $layout, $options), $options)
            ? new WriteResult($joined, [])
            : null;
    }

    /**
     * C2-W-133 where no block outside the risky groups feeds their parse or resolve stage: each risky group is checked
     * as a document of its own, as carve-js repairs group by group, so a round reads only the group it changes. Groups
     * with the same blocks, sidecar facts and local write take the same form, which is written once. Returns the
     * joined source, unread, or null where a block may feed another group or a group writes otherwise alone.
     *
     * @param array<string, mixed> $tree
     * @param array<mixed>|null $layout
     * @param array<string, mixed> $options
     * @param string $source The local write.
     * @param array<int, array{int, int, int, int, bool}> $groups
     * @param array<int, true>|null $risky The groups to write alone, each kept only where its form is its local write
     *   or, for a single block the rounds stop on, its authored source; null for the risky groups of `aloneRisky()`.
     */
    private static function checkedAlone(
        array $tree,
        ?array $layout,
        array $options,
        string $source,
        array $groups,
        ?array $risky = null,
    ): ?string {
        $children = is_array($tree['children'] ?? null) ? array_values($tree['children']) : [];
        $kept = $risky !== null;
        $risky ??= self::aloneRisky($children, $groups);
        if ($risky === null) {
            return null;
        }
        $forms = [];
        $joined = '';
        $at = 0;
        $index = self::rootIndex($layout);
        foreach ($groups as $g => [$first, $last, $start, $end]) {
            $joined .= substr($source, $at, $start - $at);
            $at = $end;
            $text = substr($source, $start, $end - $start);
            if (!isset($risky[$g])) {
                $joined .= $text;

                continue;
            }
            $sub = $tree;
            $sub['children'] = array_slice($children, $first, $last - $first + 1);
            $subLayout = self::remapRange($layout, $index, $first, $last);
            // A control comment reads the lines of the whole source around it (`ControlLines::keepAbove()`).
            $key = str_contains($text, 'carve-lint-')
                ? null
                : md5(serialize([$sub['children'], self::facts($subLayout), $text, self::authoredGroup($subLayout)]));
            $form = $key !== null && array_key_exists($key, $forms)
                ? $forms[$key]
                : self::aloneForm($sub, $subLayout, $options, $text, $last - $first, $kept);
            if ($form === null) {
                return null;
            }
            if ($key !== null) {
                $forms[$key] = $form;
            }
            $joined .= $form;
        }

        return $joined . substr($source, $at);
    }

    /**
     * The sidecar bytes of the top-level blocks of a group's layout, which its authored-block fallback reads.
     *
     * @param array<mixed>|null $layout
     */
    private static function authoredGroup(?array $layout): ?string
    {
        $source = ControlLines::source($layout);
        $from = null;
        $to = null;
        foreach (is_array($layout['nodes'] ?? null) ? $layout['nodes'] : [] as $entry) {
            if (
                is_array($entry) && is_string($entry['path'] ?? null) && preg_match('~^/children/\d+$~', $entry['path']) === 1
                && is_int($entry['startByte'] ?? null) && is_int($entry['endByte'] ?? null)
            ) {
                $from = min($from ?? $entry['startByte'], $entry['startByte']);
                $to = max($to ?? $entry['endByte'], $entry['endByte']);
            }
        }

        return $source === null || $from === null ? null : substr($source, $from, (int)$to - $from);
    }

    /**
     * The risky groups where there are at least two and every other block is plain, else null.
     *
     * @param array<int, mixed> $children
     * @param array<int, array{int, int, int, int, bool}> $groups
     *
     * @return array<int, true>|null
     */
    private static function aloneRisky(array $children, array $groups): ?array
    {
        $grouped = [];
        $risky = [];
        foreach ($groups as $g => [$first, $last, , , $undecided]) {
            $risk = $undecided;
            $plain = true;
            for ($i = $first; $i <= $last; $i++) {
                $grouped[$i] = true;
                $child = $children[$i] ?? null;
                $risk = $risk || (is_array($child) && self::nodeHasRoundRisk($child, false, false));
                $plain = $plain && is_array($child) && self::plain($child);
            }
            if (!$risk && !$plain) {
                return null;
            }
            $risky += $risk ? [$g => true] : [];
        }
        foreach ($children as $i => $child) {
            if (!isset($grouped[$i]) && (!is_array($child) || !self::plain($child))) {
                return null;
            }
        }

        return count($risky) < 2 ? null : $risky;
    }

    /**
     * The checked form of one risky group written as its own document, null where it reports a loss or writes the
     * group otherwise than the local write of the whole document did.
     *
     * @param array<string, mixed> $sub
     * @param array<mixed>|null $layout
     * @param array<string, mixed> $options
     * @param string $text The group in the local write of the whole document.
     * @param int $last The index of its last block.
     * @param bool $kept Whether the group must keep its local write, or take its authored source where it is one block
     *   the rounds stop on (`settledAlone()`).
     */
    private static function aloneForm(
        array $sub,
        ?array $layout,
        array $options,
        string $text,
        int $last,
        bool $kept = false,
    ): ?string {
        [$result, $captures] = self::recording(static fn (): WriteResult => self::writeChecked($sub, $layout, $options), true);
        $final = null;
        foreach ($captures as [$written, $ranges]) {
            if ($written === $result->source) {
                $final = $ranges;
            }
        }
        [$local, $localRanges] = $captures[0] ?? ['', null];
        if (
            $localRanges === null || $final === null
            || self::spans($localRanges) !== [[0, $last]] || self::spans($final) !== [[0, $last]]
            || substr($local, $localRanges[0][2], $localRanges[0][3] - $localRanges[0][2]) !== $text
        ) {
            return null;
        }
        [, , $start, $end] = $final[0];
        if ($result->diagnostics === []) {
            $form = substr($result->source, $start, $end - $start);

            return !$kept || $form === $text ? $form : null;
        }
        if (!$kept || $last !== 0 || !self::onlyRefused($result)) {
            return null;
        }
        // C2-W-088: a block the rounds stop on takes the authored-block fallback alone (carve-js `settleLosses`).
        $authored = self::withAuthoredBlock($result, $sub, $layout, $options);
        $after = strlen($result->source) - $end;
        if (
            $authored->diagnostics !== [] || strlen($authored->source) < $start + $after
            || substr($authored->source, 0, $start) !== substr($result->source, 0, $start)
            || substr($authored->source, strlen($authored->source) - $after) !== substr($result->source, $end)
        ) {
            return null;
        }

        return substr($authored->source, $start, strlen($authored->source) - $after - $start);
    }

    /**
     * The groups a group check writes: the risky ones, and those the parse or resolve stage of the risky ones may
     * read; null where none is risky or they hold more than two thirds of the source. With them the old to new child
     * index of the kept children, and the child span of each kept group.
     *
     * @param array<int, mixed> $children
     * @param array<int, array{int, int, int, int, bool}> $groups
     * @param int $length The bytes of the local write.
     *
     * @return array{array<int, true>, array<int, true>, array<int, int>, array<int, array{int, int}>}|null
     */
    private static function keptGroups(array $children, array $groups, int $length): ?array
    {
        $groupOf = [];
        $keep = [];
        $risky = [];
        foreach ($groups as $g => [$first, $last, , , $undecided]) {
            $risk = $undecided;
            $plain = true;
            for ($i = $first; $i <= $last; $i++) {
                $groupOf[$i] = $g;
                $child = $children[$i] ?? null;
                $risk = $risk || (is_array($child) && self::nodeHasRoundRisk($child, false, false));
                $plain = $plain && is_array($child) && self::plain($child);
            }
            $risky += $risk ? [$g => true] : [];
            $keep += $risk || !$plain ? [$g => true] : [];
        }
        $keptBytes = 0;
        foreach (array_keys($keep) as $g) {
            $keptBytes += $groups[$g][3] - $groups[$g][2];
        }
        // The check of the kept groups and one read-back of the join cost less than the whole check (two writes and
        // a parse) only while the kept groups are at most two thirds of the source.
        if ($risky === [] || count($keep) === count($groups) || 3 * $keptBytes > 2 * $length) {
            return null;
        }
        $map = [];
        foreach ($children as $i => $child) {
            // A child that writes nothing is in no group; it stays where the parse may read it.
            if (isset($groupOf[$i]) ? isset($keep[$groupOf[$i]]) : !is_array($child) || !self::plain($child)) {
                $map[$i] = count($map);
            }
        }
        $expected = [];
        foreach (array_keys($keep) as $g) {
            $expected[] = [$map[$groups[$g][0]], $map[$groups[$g][1]]];
        }

        return [$keep, $risky, $map, $expected];
    }

    /**
     * @param array<int, array{int, int, int, int, bool}> $ranges
     *
     * @return array<int, array{int, int}>
     */
    private static function spans(array $ranges): array
    {
        return array_map(static fn (array $r): array => [$r[0], $r[1]], $ranges);
    }

    /**
     * `withPrunedNoteCarets()` of a write that reads back as the trimmed tree, null where it does not.
     *
     * @param \MarkupCarve\Carve\Writer\WriteResult $result
     * @param array<string, mixed> $tree
     * @param array<mixed>|null $layout
     * @param array<string, mixed> $options
     * @param string|null $parsed
     */
    private static function readBackResult(WriteResult $result, array $tree, ?array $layout, array $options, ?string $parsed): ?WriteResult
    {
        if (self::notePrunable($result->source) || str_contains($result->source, '\\[^]')) {
            $print = self::trimmedPrint($tree, $layout, $options);

            return self::readsBack($result->source, $print, $options)
                ? self::withPrunedNoteCarets($result, $print, $options, $parsed)
                : null;
        }
        // Nothing to prune needs no fingerprint: the read-back and the trimmed tree are compared in one walk (carve-php#5965).
        $removed = [];
        $trimmed = LineEndBlanks::trimmed($tree, self::tableFormats($layout, $options), $removed);
        if (ReadbackRound::memoActive() && !isset($options['extensions'])) {
            $read = ReadbackRound::read($result->source, $options);
        } else {
            $parseOptions = isset($options['extensions']) ? ['extensions' => $options['extensions']] : [];
            $read = TreeEncoder::decodedForWriter(Carve::parse($result->source, $parseOptions));
        }

        return TreeEquality::same($read, $trimmed) ? $result : null;
    }

    /**
     * The fingerprint a read-back is compared with (C2-W-151).
     *
     * @param array<string, mixed> $tree
     * @param array<mixed>|null $layout
     * @param array<string, mixed> $options
     */
    private static function trimmedPrint(array $tree, ?array $layout, array $options): string
    {
        $formats = self::tableFormats($layout, $options);
        foreach (self::$trimmedPrints ?? [] as [$known, $knownFormats, $print]) {
            if ($known === $tree && $knownFormats === $formats) {
                return $print;
            }
        }
        $removed = [];
        $print = TreeEquality::fingerprint(LineEndBlanks::trimmed($tree, $formats, $removed));
        if (self::$trimmedPrints !== null) {
            self::$trimmedPrints = [[$tree, $formats, $print], ...array_slice(self::$trimmedPrints, 0, self::TRIMMED_PRINTS - 1)];
        }

        return $print;
    }

    /**
     * Runs `$write` with each of its writes recorded, and returns its result with the records.
     *
     * @template T
     *
     * @param \Closure(): T $write
     * @param bool $inGroups Whether the writes are those of `checkedGroups()`.
     *
     * @return array{T, array<int, array{string, array<int, array{int, int, int, int, bool}>|null}>}
     */
    private static function recording(Closure $write, bool $inGroups): array
    {
        $captured = self::$captured;
        $outer = self::$inGroups;
        self::$captured = [];
        self::$inGroups = $inGroups;
        try {
            $result = $write();

            return [$result, self::records()];
        } finally {
            self::$captured = $captured;
            self::$inGroups = $outer;
        }
    }

    /**
     * @return array<int, array{string, array<int, array{int, int, int, int, bool}>|null}>
     */
    private static function records(): array
    {
        return self::$captured ?? [];
    }

    /**
     * Whether a block, with everything in it, is of the `PLAIN` types and carries no id, caption or note key.
     *
     * @param array<mixed> $node
     */
    private static function plain(array $node): bool
    {
        $type = is_string($node['type'] ?? null) ? $node['type'] : '';
        $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
        if (
            !isset(self::PLAIN[$type]) || isset($attrs['id']) || isset($node['caption']) || isset($node['longDescription'])
            || (isset($node['key']) && ($node['namespace'] ?? null) === 'footnote')
        ) {
            return false;
        }
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            if (!is_array($child) || !self::plain($child)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The layout of a document of the top-level children of `$map`, renumbered to their new index.
     *
     * @param array<mixed>|null $layout
     * @param array<int, int> $map
     *
     * @return array<mixed>|null
     */
    private static function remapRoot(?array $layout, array $map): ?array
    {
        if ($layout === null || !is_array($layout['nodes'] ?? null)) {
            return $layout;
        }
        $nodes = [];
        foreach ($layout['nodes'] as $entry) {
            if (!is_array($entry) || self::remapPaths($entry, $map)) {
                $nodes[] = $entry;
            }
        }
        $layout['nodes'] = $nodes;

        return $layout;
    }

    /**
     * `rootIndex()` for `isolatedBlock()`, built once per tree.
     *
     * @param array<mixed>|null $layout
     *
     * @return array{array<int, mixed>, array<int, array<array{int, int}>>, array<int>}
     */
    public static function layoutIndex(?array $layout): array
    {
        return self::rootIndex($layout);
    }

    /**
     * Top-level child `$index` as a document of its own, at index 0, followed by the link definitions of the tree when
     * it holds a reference: its tree and layout. The C2-W-125 retest reads it instead of the whole document.
     *
     * @param array<string, mixed> $tree
     * @param array<mixed>|null $layout
     * @param array{array<int, mixed>, array<int, array<array{int, int}>>, array<int>} $index `layoutIndex()`
     * @param int $child
     *
     * @return array{array<string, mixed>, array<mixed>|null}
     */
    public static function isolatedBlock(array $tree, ?array $layout, array $index, int $child): array
    {
        $children = is_array($tree['children'] ?? null) ? $tree['children'] : [];
        $map = [$child => 0];
        if (str_contains(json_encode($children[$child] ?? null) ?: '', '"key"')) {
            foreach ($children as $i => $node) {
                if (is_array($node) && ($node['type'] ?? null) === 'link_reference_definition' && $i !== $child) {
                    $map[$i] = count($map);
                }
            }
        }
        $sub = $tree;
        $sub['children'] = [];
        foreach ($map as $i => $_) {
            $sub['children'][] = $children[$i];
        }

        return [$sub, self::isolatedLayout($layout, $index, $map)];
    }

    /**
     * The layout entries of the top-level children in `$map`, their paths renumbered by it.
     *
     * @param array<mixed>|null $layout
     * @param array{array<int, mixed>, array<int, array<array{int, int}>>, array<int>} $index
     * @param array<int, int> $map
     *
     * @return array<mixed>|null
     */
    private static function isolatedLayout(?array $layout, array $index, array $map): ?array
    {
        if ($layout === null || !is_array($layout['nodes'] ?? null)) {
            return $layout;
        }
        [$entries, $byFirst, $picked] = $index;
        foreach ($map as $i => $_) {
            foreach ($byFirst[$i] ?? [] as [$e, $high]) {
                if ($high === $i || isset($map[$high])) {
                    $picked[] = $e;
                }
            }
        }
        sort($picked);
        $nodes = [];
        foreach ($picked as $e) {
            $entry = $entries[$e];
            if (!is_array($entry) || self::remapPaths($entry, $map)) {
                $nodes[] = $entry;
            }
        }
        $layout['nodes'] = $nodes;

        return $layout;
    }

    /**
     * The layout entries in order, and for `remapRange()` the entries by the first top-level child their paths name,
     * each with the last; entries that name none stay in every range. Built once, so the groups do not each walk the
     * whole layout.
     *
     * @param array<mixed>|null $layout
     *
     * @return array{array<int, mixed>, array<int, array<array{int, int}>>, array<int>}
     */
    private static function rootIndex(?array $layout): array
    {
        $entries = is_array($layout['nodes'] ?? null) ? array_values($layout['nodes']) : [];
        $byFirst = [];
        $always = [];
        foreach ($entries as $e => $entry) {
            $low = PHP_INT_MAX;
            $high = -1;
            if (is_array($entry)) {
                self::rootSpan($entry, $low, $high);
            }
            if ($high < 0) {
                $always[] = $e;
            } else {
                $byFirst[$low][] = [$e, $high];
            }
        }

        return [$entries, $byFirst, $always];
    }

    /**
     * The lowest and highest top-level child index the paths of a layout entry name.
     *
     * @param array<mixed> $entry
     * @param int $low
     * @param int $high
     */
    private static function rootSpan(array $entry, int &$low, int &$high): void
    {
        foreach ($entry as $key => $value) {
            if (is_array($value)) {
                self::rootSpan($value, $low, $high);
            } elseif ($key === 'path' && is_string($value) && preg_match('~^/children/(\d+)(?=/|$)~', $value, $m) === 1) {
                $low = min($low, (int)$m[1]);
                $high = max($high, (int)$m[1]);
            }
        }
    }

    /**
     * `remapRoot()` for the top-level children `$first` to `$last`, read from the index of `rootIndex()`.
     *
     * @param array<mixed>|null $layout
     * @param array{array<int, mixed>, array<int, array<array{int, int}>>, array<int>} $index
     * @param int $first
     * @param int $last
     *
     * @return array<mixed>|null
     */
    private static function remapRange(?array $layout, array $index, int $first, int $last): ?array
    {
        if ($layout === null || !is_array($layout['nodes'] ?? null)) {
            return $layout;
        }
        [$entries, $byFirst, $picked] = $index;
        for ($i = $first; $i <= $last; $i++) {
            foreach ($byFirst[$i] ?? [] as [$e, $high]) {
                if ($high <= $last) {
                    $picked[] = $e;
                }
            }
        }
        sort($picked);
        $map = array_flip(range($first, $last));
        $nodes = [];
        foreach ($picked as $e) {
            $entry = $entries[$e];
            if (is_array($entry)) {
                self::remapPaths($entry, $map);
            }
            $nodes[] = $entry;
        }
        $layout['nodes'] = $nodes;

        return $layout;
    }

    /**
     * Renumbers every `path` in a layout entry, its wraps included; false where one names a child left out.
     *
     * @param array<mixed> $entry
     * @param array<int, int> $map
     */
    private static function remapPaths(array &$entry, array $map): bool
    {
        foreach ($entry as $key => &$value) {
            if (is_array($value)) {
                if (!self::remapPaths($value, $map)) {
                    return false;
                }
            } elseif ($key === 'path' && is_string($value) && preg_match('~^/children/(\d+)(?=/|$)~', $value, $m) === 1) {
                if (!isset($map[(int)$m[1]])) {
                    return false;
                }
                $value = '/children/' . $map[(int)$m[1]] . substr($value, strlen($m[0]));
            }
        }
        unset($value);

        return true;
    }

    /**
     * The local form where the rounds stopped: kept where it reads as the tree, reported otherwise.
     *
     * @param array<string, mixed> $tree
     * @param \MarkupCarve\Carve\Writer\WriteResult $result
     * @param array<string, mixed> $options
     *
     * @return \MarkupCarve\Carve\Writer\WriteResult
     */
    private static function unrepaired(array $tree, WriteResult $result, array $options): WriteResult
    {
        $parseOptions = isset($options['extensions']) ? ['extensions' => $options['extensions']] : [];
        $read = TreeEncoder::decodedForWriter(Carve::parse($result->source, $parseOptions));
        if (TreeEquality::equal($tree, $read)) {
            return $result;
        }

        return new WriteResult($result->source, [
            new Diagnostic('writer-refused', 'warning', 'render-loss', 'Writer repair stopped for this block group', ''),
        ]);
    }

    /**
     * Whether a supplied tree compares with its read-back by C2-A-161: a 2.0 document without the rewrites the writer
     * may spell (carve-js `holdsRewrites`) or a span count it spells with marks (C2-W-145).
     *
     * @param array<string, mixed> $tree
     */
    private static function comparable(array $tree): bool
    {
        if (!self::$readsSupplied || ($tree['type'] ?? null) !== 'document' || ($tree['astVersion'] ?? null) !== '2.0') {
            return false;
        }
        $pending = [$tree];
        while (($node = array_pop($pending)) !== null) {
            $type = $node['type'] ?? null;
            if (
                $type === 'inline_extension' || $type === 'block_extension' || is_array($node['data'] ?? null)
                || isset($node['colspan']) || isset($node['rowspan'])
            ) {
                return false;
            }
            foreach ($node as $value) {
                if (is_array($value)) {
                    $pending[] = $value;
                }
            }
        }

        return true;
    }

    /**
     * Whether the tree passes the reader's checks. A parse fills the fields a tree that fails them may lack, as the
     * `alt` of an image, so its read-back is no proof of a misread. Asked only after a read-back differs: the check
     * costs about as much as the parse. The write's extensions implement the ones the tree requires (C2-A-018).
     *
     * @param array<string, mixed> $tree
     * @param array<string, mixed> $options
     */
    private static function readerAccepts(array $tree, array $options): bool
    {
        try {
            Carve::validateTree($tree, isset($options['extensions']) ? ['extensions' => $options['extensions']] : []);
        } catch (TreeError) {
            return false;
        }

        return true;
    }

    /**
     * C2-W-002: whether the source reads back as the blocks of the tree, as carve-js `readsBack` compares a group's
     * blocks; root fields have their own reports (C2-W-011).
     *
     * @param array<string, mixed> $tree
     * @param string $source
     * @param array<string, mixed> $options
     */
    private static function readsBackAsTree(array $tree, string $source, array $options): bool
    {
        $children = is_array($tree['children'] ?? null) ? $tree['children'] : [];
        if (!isset($options['extensions']) && self::plainParagraph($children, $source)) {
            return true;
        }
        $parseOptions = isset($options['extensions']) ? ['extensions' => $options['extensions']] : [];
        HeapPages::release();
        $read = TreeEncoder::decodedForWriter(Carve::parse($source, $parseOptions))['children'] ?? [];
        HeapPages::release();

        return $read === $children || TreeEquality::same($read, $children);
    }

    /**
     * carve-js `plainLeaf`: whether the blocks are one paragraph of texts of plain words between soft breaks, and the
     * source is their values joined by line feeds. Such lines read back as that paragraph with no parse.
     *
     * @param array<mixed> $blocks
     * @param string $source
     */
    private static function plainParagraph(array $blocks, string $source): bool
    {
        $paragraph = $blocks[0] ?? null;
        if (
            count($blocks) !== 1 || !is_array($paragraph) || count($paragraph) !== 2
            || ($paragraph['type'] ?? null) !== 'paragraph' || !is_array($paragraph['children'] ?? null)
            || !array_is_list($paragraph['children']) || count($paragraph['children']) % 2 !== 1
        ) {
            return false;
        }
        $at = 0;
        foreach ($paragraph['children'] as $index => $node) {
            if ($index % 2 === 1) {
                if ($node !== ['type' => 'soft_break'] || ($source[$at] ?? '') !== "\n") {
                    return false;
                }
                $at++;

                continue;
            }
            $value = is_array($node) ? $node['value'] ?? null : null;
            if (
                !is_string($value) || count($node) !== 2 || ($node['type'] ?? null) !== 'text' || !self::plainWords($value)
                || $at + strlen($value) >= strlen($source) || substr_compare($source, $value, $at, strlen($value)) !== 0
            ) {
                return false;
            }
            $at += strlen($value);
        }

        return strlen($source) === $at + 1 && $source[$at] === "\n";
    }

    /**
     * ASCII letters and digits with single spaces between them (carve-js `PLAIN_WORDS`).
     */
    private static function plainWords(string $value): bool
    {
        return $value !== '' && strspn($value, self::PLAIN_CHARACTERS) === strlen($value) && $value[0] !== ' '
            && $value[-1] !== ' ' && !str_contains($value, '  ');
    }

    /**
     * Whether the last local write left a cell backtick to the rounds (`Text::$cellTicks`).
     */
    private static bool $cellTicks = false;

    /**
     * Whether the last local write gave a cell the backslashes of the backtick kind (`Text::$cellKinds`).
     */
    private static bool $cellKinds = false;

    /**
     * The local write and the escapes it placed, by text path; with `$conservative` every bracket the plan cannot
     * decide takes its backslash.
     *
     * @param array<string, mixed> $tree
     * @param array<mixed>|null $layout
     * @param array<string, mixed> $options
     * @param bool $conservative
     * @param bool $bareSigils With `$conservative`, a sigil glued to a node stays bare.
     *
     * @return array{
     *     \MarkupCarve\Carve\Writer\WriteResult,
     *     array<string, array<int, true>>,
     *     bool,
     *     array<string, array<int, true>>,
     *     array<string, array<int, true>>
     * } The result, its escapes, whether a bracket was left undecided, the escapes other than the glued braces of
     *     C2-W-125 case one, and the pair escapes of C2-W-146.
     */
    private static function localWrite(array $tree, ?array $layout, array $options, bool $conservative, bool $bareSigils = false): array
    {
        Text::$recorded = [];
        Text::$recordedDecided = [];
        Text::$recordedPairs = [];
        Text::$recordedOwn = [];
        Text::$undecided = false;
        Text::$caseFive = false;
        Text::$gluedElsewhere = false;
        Text::$closesFence = false;
        Text::$cellTicks = false;
        Text::$cellKinds = false;
        Text::$conservative = $conservative;
        Text::$bareSigils = $bareSigils;
        try {
            $result = self::writeInternal($tree, $layout, $options, null);
            self::$cellTicks = Text::$cellTicks;
            self::$cellKinds = Text::$cellKinds;
            self::$localOwn = Text::$recordedOwn;
            self::$localCaseFive = Text::$caseFive;
            self::$localGluedElsewhere = Text::$gluedElsewhere;
            self::$localClosesFence = Text::$closesFence;

            return [$result, Text::$recorded ?? [], Text::$undecided, Text::$recordedDecided, Text::$recordedPairs];
        } finally {
            Text::$recorded = null;
            Text::$recordedDecided = [];
            Text::$recordedPairs = [];
            Text::$recordedOwn = [];
            Text::$undecided = false;
            Text::$caseFive = false;
            Text::$gluedElsewhere = false;
            Text::$closesFence = false;
            Text::$conservative = false;
            Text::$bareSigils = false;
        }
    }

    /**
     * @param string $source
     * @param string $treePrint
     * @param array<string, mixed> $options
     * @param bool $keep Keep a long source's read-back, which the next step reads again.
     */
    private static function readsBack(string $source, string $treePrint, array $options, bool $keep = false): bool
    {
        if (isset($options['extensions'])) {
            $parseOptions = ['extensions' => $options['extensions']];

            return TreeEquality::fingerprint(TreeEncoder::decodedForWriter(Carve::parse($source, $parseOptions))) === $treePrint;
        }
        $round = ReadbackRound::memoActive();
        $known = self::$readPrints[$source] ?? null;
        if ($known !== null && ($known[1] === $round || !$known[2])) {
            return $known[0] === $treePrint;
        }
        $read = $round ? ReadbackRound::read($source, $options, $keep) : TreeEncoder::decodedForWriter(Carve::parse($source));
        $print = TreeEquality::fingerprint($read);
        if (self::$readPrints !== null) {
            self::keepReadPrint($source, [$print, $round, self::hasTableCell($read)]);
        }

        return $print === $treePrint;
    }

    /**
     * @param string $source
     * @param array{string, bool, bool} $entry
     */
    private static function keepReadPrint(string $source, array $entry): void
    {
        if (strlen($source) > self::READ_PRINT_BYTES) {
            return;
        }
        $prints = self::$readPrints ?? [];
        unset($prints[$source]);
        $prints[$source] = $entry;
        $bytes = 0;
        foreach (array_reverse(array_keys($prints)) as $kept) {
            $bytes += strlen((string)$kept);
            if ($bytes > self::READ_PRINT_BYTES) {
                unset($prints[$kept]);
            }
        }
        self::$readPrints = $prints;
    }

    /**
     * @param array<mixed> $node
     */
    private static function hasTableCell(array $node): bool
    {
        if (($node['type'] ?? null) === 'table_cell') {
            return true;
        }
        foreach ($node as $value) {
            if (is_array($value) && self::hasTableCell($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The recorded `sourceFormat` of each table by path; none under `normalize` (C2-W-127).
     *
     * @param array<mixed>|null $layout
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private static function tableFormats(?array $layout, array $options): array
    {
        if (($options['normalize'] ?? false) === true) {
            return [];
        }
        $formats = [];
        foreach (self::facts($layout) as $path => $entry) {
            if (isset($entry['sourceFormat'])) {
                $formats[$path] = $entry['sourceFormat'];
            }
        }

        return $formats;
    }

    /**
     * Whether a string field of the tree that the writer spells holds ASCII punctuation.
     *
     * @param array<mixed> $tree
     */
    private static function hasPunctuation(array $tree): bool
    {
        $pending = [$tree];
        while (($node = array_pop($pending)) !== null) {
            foreach ($node as $key => $value) {
                if (is_array($value)) {
                    $pending[] = $value;
                } elseif (!isset(self::NO_SPELLING[$key]) && is_string($value) && strpbrk($value, self::PUNCTUATION) !== false) {
                    return true;
                }
            }
        }

        return false;
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
     * An extension may spell its source from its layout, which a bare core write does not preserve.
     *
     * @param array<mixed> $node
     */
    private static function hasExtensionNode(array $node): bool
    {
        if (in_array($node['type'] ?? null, ['block_extension', 'inline_extension'], true)) {
            return true;
        }
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            if (is_array($child) && self::hasExtensionNode($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The first round's bare result when a nested link's surrounding brackets need no repair.
     *
     * @param array<mixed> $tree
     * @param array<mixed>|null $layout
     * @param array<string, mixed> $options
     * @param string $treePrint
     * @param string $localSource
     * @param array{string, bool|null}|null $known The bare form already written, and whether it reads back where known.
     */
    private static function bareNestedLink(
        array $tree,
        ?array $layout,
        array $options,
        string $treePrint,
        string $localSource,
        ?array $known = null,
    ): ?WriteResult {
        if (!str_contains($localSource, '\\[') || !self::hasNestedLink($tree)) {
            return null;
        }
        $bareSource = $known[0] ?? self::bareSource($tree, $layout, $options, []);
        $holds = $known[1] ?? self::readsBack($bareSource, $treePrint, $options);

        return $holds ? new WriteResult($bareSource, []) : null;
    }

    /**
     * Whether a bracketed leaf contains a link inside another link. Its surrounding text can pair across that link.
     *
     * @param array<mixed> $node
     * @param bool $inLink
     */
    private static function hasNestedLink(array $node, bool $inLink = false): bool
    {
        $link = ($node['type'] ?? null) === 'link';
        if ($link && $inLink) {
            return true;
        }
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            if (is_array($child) && self::hasNestedLink($child, $inLink || $link)) {
                return true;
            }
        }

        return false;
    }

    /**
     * C2-I-051, C2-I-057: whether a text beside emphasis or strong holds a pair mark or a brace, which may pair with
     * the bare spelling of that span.
     *
     * @param array<mixed> $node
     */
    private static function hasPairMarkRisk(array $node): bool
    {
        [$paired, $marked] = self::pairMarkFacts($node);

        return $paired && $marked;
    }

    /**
     * @param array<mixed> $node
     *
     * @return array{bool, bool} Whether the node holds emphasis or strong, and a text with `/`, `*` or `{`.
     */
    private static function pairMarkFacts(array $node): array
    {
        $type = is_string($node['type'] ?? null) ? $node['type'] : '';
        if ($type === 'text') {
            return [false, is_string($node['value'] ?? null) && strpbrk($node['value'], '/*{') !== false];
        }
        $paired = isset(self::PAIRED_CLOSERS[$type]);
        $marked = false;
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            if (!is_array($child)) {
                continue;
            }
            [$p, $m] = self::pairMarkFacts($child);
            $paired = $paired || $p;
            $marked = $marked || $m;
            if ($paired && $marked) {
                break;
            }
        }

        return [$paired, $marked];
    }

    /**
     * C2-I-043: a braced opener of a span's own mark in its text takes the closer of the span, even one directly behind
     * it, which the local write does not decide. Inside emphasis or strong, a braced opener of either pair mark may
     * also take a closer the bare form of the pair spells (C2-I-057).
     *
     * @param array<mixed> $node
     * @param string $closers The marks of the spans around the node.
     */
    private static function hasBracedCloserRisk(array $node, string $closers = ''): bool
    {
        $type = is_string($node['type'] ?? null) ? $node['type'] : '';
        if ($closers !== '' && $type === 'text') {
            $value = is_string($node['value'] ?? null) ? $node['value'] : '';
            for ($at = strpos($value, '{'); $at !== false; $at = strpos($value, '{', $at + 1)) {
                if ($at + 1 < strlen($value) && str_contains($closers, $value[$at + 1])) {
                    return true;
                }
            }

            return false;
        }
        if (isset(self::BRACED_CLOSERS[$type])) {
            $closers .= self::BRACED_CLOSERS[$type];
        } elseif (isset(self::PAIRED_CLOSERS[$type])) {
            $closers .= '/*';
        }
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            if (is_array($child) && self::hasBracedCloserRisk($child, $closers)) {
                return true;
            }
        }

        return false;
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
     *     text: array<int, int>,
     *     paths: array<int, string>,
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
            $write = static function () use ($tree, $layout, $options, $escapes): array {
                $trace = new RepairTrace($escapes, false);

                return [$trace->decode(self::writeInternal($tree, $layout, $options, $trace)->source), self::$bareRepaired];
            };

            return self::remembered('decoded', [$tree, $layout, $options, $escapes], $write);
        }

        return self::markedCandidate($tree, $layout, $options, $escapes, self::bareSource($tree, $layout, $options, $escapes));
    }

    /**
     * The source of `bareCandidate()`, without the marked write that maps its positions.
     *
     * @param array<string, mixed> $tree
     * @param array<mixed>|null $layout
     * @param array<string, mixed> $options
     * @param array<string, array<int, true>> $escapes
     */
    public static function bareSource(array $tree, ?array $layout, array $options, array $escapes): string
    {
        $write = static function () use ($tree, $layout, $options, $escapes): array {
            $trace = new RepairTrace($escapes, false);

            return [self::writeInternal($tree, $layout, $options, $trace)->source, $trace->repaired];
        };

        return self::remembered('bare', [$tree, $layout, $options, $escapes], $write);
    }

    /**
     * The `$bareRepaired` the writes so far left, null while the writes of a check are recorded.
     */
    public static function repairFlag(): ?bool
    {
        return self::$captured === null ? self::$bareRepaired : null;
    }

    public static function restoreRepairFlag(bool $flag): void
    {
        self::$bareRepaired = $flag;
    }

    /**
     * `WriteMemo::remember()` for a traced write, except while the writes of a check are recorded. The flag of the
     * write is the `$bareRepaired` it leaves.
     *
     * @template T
     *
     * @param string $kind
     * @param array<mixed> $inputs The tree, layout, options and escapes the write reads.
     * @param \Closure(): array{T, bool} $write
     *
     * @return T
     */
    private static function remembered(string $kind, array $inputs, Closure $write): mixed
    {
        $inputs[] = self::$bareRepaired;
        [$result, self::$bareRepaired] = self::$captured !== null ? $write() : WriteMemo::remember($kind, $inputs, $write);

        return $result;
    }

    /**
     * `bareCandidate()` of a bare source already written with these escapes.
     *
     * @param array<string, mixed> $tree
     * @param array<mixed>|null $layout
     * @param array<string, mixed> $options
     * @param array<string, array<int, true>> $escapes
     * @param string $bare
     *
     * @return array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>,
     *     ranges: array<string, array{int, int}>
     * }
     */
    public static function markedCandidate(array $tree, ?array $layout, array $options, array $escapes, string $bare): array
    {
        $write = static function () use ($tree, $layout, $options, $escapes, $bare): array {
            do {
                $marker = "\x01" . bin2hex(random_bytes(6)) . "\x03";
            } while (str_contains($bare, $marker));
            $trace = new RepairTrace($escapes, true, $marker);
            $result = self::writeInternal($tree, $layout, $options, $trace);

            return [RepairAlignment::onto($trace->decode($result->source), $bare), self::$bareRepaired];
        };

        return self::remembered('marked', [$tree, $layout, $options, $escapes, $bare], $write);
    }

    /**
     * `ControlLines::keepAbove()`, loaded only for a written control comment.
     *
     * @param string $source
     * @param array<mixed>|null $layout
     * @param \MarkupCarve\Carve\Writer\State $w
     */
    private static function keptAbove(string $source, ?array $layout, State $w): string
    {
        if (!str_contains($source, 'carve-lint-disable-next-line')) {
            return $source;
        }

        return ControlLines::keepAbove($source, ControlLines::source($layout), $w->extensions);
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
        $w->repairTrace = $trace;
        $w->recordGroups = self::$captured !== null;
        $w->extensions = is_array($options['extensions'] ?? null) ? $options['extensions'] : [];
        $w->labels = self::linkKeys($options);
        $children = is_array($tree['children'] ?? null) ? $tree['children'] : [];
        self::collect($children, $w);
        self::rootLosses($tree, $w);
        self::fieldLosses($children, '/children', $w);
        $w->beginRepairDocument($children);
        (new Blocks($w))->blocks($children, '/children', '');
        self::lineLosses($children, $w);
        while ($w->lines !== [] && end($w->lines) === '') {
            array_pop($w->lines);
        }
        if ($w->lines === []) {
            return new WriteResult($children === [] ? "\n" : '', $w->losses);
        }
        HeapPages::release();
        $source = implode("\n", $w->lines) . "\n";
        $joined = $source;
        $source = self::keptAbove($source, $layout, $w);
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
        self::record($w, $joined, $source);

        return new WriteResult($source, $w->losses);
    }

    /**
     * C2-W-016, C2-W-117: the losses of blank lines and line-end carriage returns. A traced write reports none: only
     * its source is read.
     *
     * @param array<mixed> $children
     * @param \MarkupCarve\Carve\Writer\State $w
     */
    private static function lineLosses(array $children, State $w): void
    {
        if ($w->repairTrace !== null) {
            return;
        }
        BlankLines::lose($children, '/children', $w);
        $w->loseLineEndCarriageReturns();
    }

    /**
     * @param \MarkupCarve\Carve\Writer\State $w
     * @param string $joined
     * @param string $source
     *
     * @return void
     */
    private static function record(State $w, string $joined, string $source): void
    {
        if (self::$captured !== null) {
            self::$captured[] = [$source, self::groupRanges($w, $joined, $source)];
        }
    }

    /**
     * The byte range of each top-level block group in `$source`, null where the source is no prefix of blank lines
     * in front of the joined lines.
     *
     * @param \MarkupCarve\Carve\Writer\State $w
     * @param string $joined
     * @param string $source
     *
     * @return array<int, array{int, int, int, int, bool}>|null
     */
    private static function groupRanges(State $w, string $joined, string $source): ?array
    {
        $shift = strlen($source) - strlen($joined);
        if ($shift < 0 || !str_ends_with($source, $joined) || trim(substr($source, 0, $shift), "\n") !== '') {
            return null;
        }
        $offsets = [];
        $at = $shift;
        foreach ($w->lines as $i => $line) {
            $offsets[$i] = $at;
            $at += strlen($line) + 1;
        }
        $offsets[count($w->lines)] = $at;
        $ranges = [];
        foreach ($w->rootGroups as [$first, $last, $start, $end, $undecided]) {
            $end = min($end, count($w->lines));
            if ($start >= $end) {
                return null;
            }
            $ranges[] = [$first, $last, $offsets[$start], $offsets[$end] - 1, $undecided];
        }

        return $ranges;
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

        $result = self::writeWithDiagnostics($document, null, $options);

        return $blocks === [] ? new WriteResult('', $result->diagnostics) : $result;
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
            'bare', 'continuationLines', 'fenced', 'hardBreakOpener', 'headerRows', 'sourceFormat', 'wraps', 'rawRef',
            'optionSpelling',
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
     * C2-W-134: the entries a call that returns only source throws on.
     *
     * @param array<\MarkupCarve\Carve\Diagnostics\Diagnostic> $diagnostics
     *
     * @return array<\MarkupCarve\Carve\Diagnostics\Diagnostic>
     */
    public static function refusing(array $diagnostics): array
    {
        return array_values(array_filter(
            $diagnostics,
            static fn (Diagnostic $d): bool => isset(self::REFUSING_CODES[$d->code]),
        ));
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
        $refused = self::refusing($result->diagnostics);
        if ($refused !== []) {
            throw new CarveWriteError($refused);
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
        HeapPages::release();
        $tree = TreeEncoder::decodedForWriter($parsed->tree);
        $writeLayout = $layout ?? $parsed->layout;
        // The read-back parser may need the same memory as the initial parse.
        // Release its object tree before the writer begins that repair work.
        unset($parsed);
        self::$parsedSource = $source;
        try {
            return self::writeWithDiagnostics($tree, $writeLayout, $options);
        } finally {
            self::$parsedSource = null;
        }
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
        $refused = self::refusing($result->diagnostics);
        if ($refused !== []) {
            throw new CarveWriteError($refused);
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
     * C2-M-074, C2-W-117: reports each field of `UNSPELLED_FIELDS` and each `dir` a node carries, at any depth. An
     * array without a `type` is a container (a list, `slots`), walked as `TreeDepth` walks it.
     *
     * @param array<mixed> $nodes
     * @param string $path
     * @param \MarkupCarve\Carve\Writer\State $w
     *
     * @return void
     */
    private static function fieldLosses(array $nodes, string $path, State $w): void
    {
        foreach ($nodes as $at => $node) {
            if (!is_array($node)) {
                continue;
            }
            $here = $path . '/' . $at;
            if (!is_string($node['type'] ?? null)) {
                self::fieldLosses($node, $here, $w);

                continue;
            }
            $type = $node['type'];
            foreach (self::UNSPELLED_FIELDS[$type] ?? [] as $field) {
                if (isset($node[$field])) {
                    $w->lose($here, $type, $field);
                }
            }
            if (isset($node['dir'])) {
                $w->lose($here, $type, 'dir');
            }
            foreach ($node as $field => $value) {
                if (is_array($value) && !isset(self::UNWALKED_FIELDS[$field])) {
                    self::fieldLosses([$field => $value], $here, $w);
                }
            }
        }
    }

    /**
     * C2-W-011, C2-W-117: root `metadata` has no spelling; a frontmatter node is raw text and never reads back as
     * `fields`.
     *
     * @param array<string, mixed> $tree
     * @param \MarkupCarve\Carve\Writer\State $w
     *
     * @return void
     */
    private static function rootLosses(array $tree, State $w): void
    {
        $metadata = is_array($tree['metadata'] ?? null) ? $tree['metadata'] : [];
        foreach (array_keys($metadata) as $field) {
            $w->lose('', 'document', 'metadata/' . $field);
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
