<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

use Closure;
use MarkupCarve\Carve\Block\LineShapes;
use MarkupCarve\Carve\Block\TableBuilder;
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Diagnostics\Diagnostic;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeEquality;
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
     * The source while `formatWithDiagnostics` writes the tree of its parse, which a read-back can match field for
     * field; a supplied tree may lack fields a parse adds, so its read-back is no proof of a misread.
     */
    private static ?string $parsedSource = null;

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
     *
     * @return \MarkupCarve\Carve\Writer\WriteResult
     */
    public static function writeWithDiagnostics(array $tree, ?array $layout = null, array $options = []): WriteResult
    {
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
        self::$captured = null;
        self::$inGroups = false;
        try {
            return Blocks::withLeadMemo(static fn (): WriteResult => self::writeChecked($tree, $layout, $options));
        } finally {
            self::$captured = $captured;
            self::$inGroups = $inGroups;
        }
    }

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
        [$result, $localEscapes, $undecided, $localDecided, $groups] = self::firstWrite($tree, $layout, $options, $risk, $parsed === null);
        $settled = self::settled($tree, $layout, $options, $result, $groups, $parsed, $risk);
        if ($settled !== null) {
            return $settled;
        }
        // C2-W-124: a local write the heuristics call safe is still read back where the tree is a parse's; one that
        // reads as another tree goes to the rounds.
        $unchecked = !$undecided && !$risk;
        // A form equal to the source the tree was parsed from reads back as that tree, and a tree without ASCII
        // punctuation in its strings takes no escape decision that could misread.
        if ($unchecked && ($parsed === null || $parsed === $result->source || !self::hasPunctuation($tree))) {
            return self::uncheckedPruned($result, $parsed, $tree, $layout, $options);
        }
        // C2-W-151: the read-back never holds the spaces and tabs that end a line of text, so the round compares
        // and repairs the tree without them. The local write keeps the supplied tree, and with it the loss paths.
        $removed = [];
        $original = $tree;
        $originalLayout = $layout;
        $tree = LineEndBlanks::trimmed($tree, self::tableFormats($layout, $options), $removed);
        $layout = LineEndBlanks::remapLayout($layout, $removed);
        $treePrint = TreeEquality::fingerprint($tree);
        if (self::takesLocalForm($unchecked, $undecided, $localDecided)) {
            // The record is rebuilt where the rounds need it, so the read-back does not hold it beside a second tree.
            unset($localEscapes);
            if (self::readsBack($result->source, $treePrint, $options)) {
                return self::withPrunedNoteCarets($result, $treePrint, $options, $parsed);
            }
        }
        if ($undecided) {
            // C2-W-088: a bracket the plan left bare stays bare where the form reads back; otherwise the plan takes
            // its backslash there, and the rounds below go on from that form.
            if (self::readsBack($result->source, $treePrint, $options)) {
                return self::withPrunedNoteCarets($result, $treePrint, $options, $parsed);
            }
            // C2-W-125, case eight: the conservative form escapes the bracket of every `^[`, which lets a caret pair the
            // span of a text `^[` keeps ordinary open; the rounds place the backslash on its early closer instead.
            if (!RepairPlanner::hasTextNoteBeforeCaretPair($tree)) {
                [$result, $localEscapes] = self::conservativeWrite($original, $originalLayout, $options, $treePrint);
                if ($result->diagnostics !== [] || !$risk) {
                    return $result;
                }
            }
        }
        $bareSource = self::bareSource($tree, $layout, $options, []);
        $read = ReadbackRound::read($bareSource, $options);
        $seed = [];
        $planner = null;
        [$readPrint, $readParts] = RepairPlanner::readPrints($read);
        if ($readPrint === $treePrint) {
            unset($read);
            if ($result->source === $bareSource || (!$unchecked && self::readsBack($result->source, $treePrint, $options))) {
                return self::withPrunedNoteCarets($result, $treePrint, $options, $parsed);
            }
            // C2-W-091: the escapes of the local write can change how the text reads, as an escaped bracket that no
            // longer counts toward the depth limit of C2-I-118. The rounds go on from the local form.
            $seed = $removed === [] ? ($localEscapes ?? self::localWrite($original, $originalLayout, $options, false)[1]) : [];
        } else {
            // The first round would write and read this same candidate again.
            $bare = self::markedCandidate($tree, $layout, $options, [], $bareSource);
            $planner = new RepairPlanner($tree, $layout, $options, [], $treePrint);
            $planner->startFrom($bare, ReadbackRound::compare($bare, $read, $tree, $treePrint, false), $readParts);
            unset($bare, $read);
        }
        $planner ??= new RepairPlanner($tree, $layout, $options, $seed, $treePrint);
        $repaired = $planner->repair();

        return $repaired === null
            ? self::unrepaired($tree, $result, $options)
            : new WriteResult(self::prunedNoteCarets($repaired, $treePrint, $options, $parsed), []);
    }

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
        $pruned = self::prunedNoteCarets($result->source, $treePrint, $options, $parsed);

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
     * The source without the backslash before the caret of each `[\\^` inside a note, and the offsets of the brackets
     * whose text `bareNoteBrackets()` may write bare.
     *
     * @param string $source
     *
     * @return array{string, array<int>}
     */
    private static function noteCaretStarts(string $source): array
    {
        $pruned = '';
        $from = 0;
        $open = [];
        $notes = 0;
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
                $open = [];
                $notes = 0;
            } elseif ($ch === '[') {
                $note = $before === '^' || ($before === '\\' && $at > 1 && $source[$at - 2] === '^');
                if (!$note && $notes > 0 && substr($source, $at + 1, 2) === '\\^') {
                    $pruned .= substr($source, $from, $at + 1 - $from);
                    $from = $at + 2;
                    $starts[] = $at;
                } elseif (!$note && $notes > 0 && $caret !== false && ($lineEnd === false || $caret < $lineEnd)) {
                    $starts[] = $at;
                }
                $open[] = $note;
                $notes += $note ? 1 : 0;
            } elseif ($before !== '\\' && array_pop($open) === true) {
                $notes--;
            }
        }
        if ($starts === []) {
            return [$source, []];
        }

        return [$pruned . substr($source, $from), $starts];
    }

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
     *     array<int, array{int, int, int, int, bool}>|null
     * }
     */
    private static function firstWrite(array $tree, ?array $layout, array $options, bool $risk, bool $supplied = false): array
    {
        Text::$suppliedTree = $supplied;
        try {
            if (self::$inGroups || !$risk) {
                return [...self::localWrite($tree, $layout, $options, false), null];
            }
            [$local, $records] = self::recording(static fn (): array => self::localWrite($tree, $layout, $options, false), false);
        } finally {
            Text::$suppliedTree = false;
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
     */
    private static function checkedAlone(array $tree, ?array $layout, array $options, string $source, array $groups): ?string
    {
        $children = is_array($tree['children'] ?? null) ? array_values($tree['children']) : [];
        $risky = self::aloneRisky($children, $groups);
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
                : md5(serialize([$sub['children'], self::facts($subLayout), $text]));
            $form = $key !== null && array_key_exists($key, $forms)
                ? $forms[$key]
                : self::aloneForm($sub, $subLayout, $options, $text, $last - $first);
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
     */
    private static function aloneForm(array $sub, ?array $layout, array $options, string $text, int $last): ?string
    {
        [$result, $captures] = self::recording(static fn (): WriteResult => self::writeChecked($sub, $layout, $options), true);
        $final = null;
        foreach ($captures as [$written, $ranges]) {
            if ($written === $result->source) {
                $final = $ranges;
            }
        }
        [$local, $localRanges] = $captures[0] ?? ['', null];
        if (
            $result->diagnostics !== [] || $localRanges === null || $final === null
            || self::spans($localRanges) !== [[0, $last]] || self::spans($final) !== [[0, $last]]
            || substr($local, $localRanges[0][2], $localRanges[0][3] - $localRanges[0][2]) !== $text
        ) {
            return null;
        }

        return substr($result->source, $final[0][2], $final[0][3] - $final[0][2]);
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
     * The fingerprint a read-back is compared with (C2-W-151).
     *
     * @param array<string, mixed> $tree
     * @param array<mixed>|null $layout
     * @param array<string, mixed> $options
     */
    private static function trimmedPrint(array $tree, ?array $layout, array $options): string
    {
        $removed = [];

        return TreeEquality::fingerprint(LineEndBlanks::trimmed($tree, self::tableFormats($layout, $options), $removed));
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
     *     array<string, array<int, true>>
     * } The result, its escapes, whether a bracket was left undecided, and the escapes other than the glued braces of
     *     C2-W-125 case one.
     */
    private static function localWrite(array $tree, ?array $layout, array $options, bool $conservative, bool $bareSigils = false): array
    {
        Text::$recorded = [];
        Text::$recordedDecided = [];
        Text::$undecided = false;
        Text::$conservative = $conservative;
        Text::$bareSigils = $bareSigils;
        try {
            $result = self::writeInternal($tree, $layout, $options, null);

            return [$result, Text::$recorded, Text::$undecided, Text::$recordedDecided];
        } finally {
            Text::$recorded = null;
            Text::$recordedDecided = [];
            Text::$undecided = false;
            Text::$conservative = false;
            Text::$bareSigils = false;
        }
    }

    /**
     * @param string $source
     * @param string $treePrint
     * @param array<string, mixed> $options
     */
    private static function readsBack(string $source, string $treePrint, array $options): bool
    {
        $parseOptions = isset($options['extensions']) ? ['extensions' => $options['extensions']] : [];

        return TreeEquality::fingerprint(TreeEncoder::decodedForWriter(Carve::parse($source, $parseOptions))) === $treePrint;
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
            $trace = new RepairTrace($escapes, false);
            $result = self::writeInternal($tree, $layout, $options, $trace);

            return $trace->decode($result->source);
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
        return self::writeInternal($tree, $layout, $options, new RepairTrace($escapes, false))->source;
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
        do {
            $marker = "\x01" . bin2hex(random_bytes(6)) . "\x03";
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
        $w->repairTrace = $trace;
        $w->recordGroups = self::$captured !== null;
        $w->extensions = is_array($options['extensions'] ?? null) ? $options['extensions'] : [];
        $w->labels = self::linkKeys($options);
        $children = is_array($tree['children'] ?? null) ? $tree['children'] : [];
        self::collect($children, $w);
        self::rootLosses($tree, $children, $w);
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
            'bare', 'fenced', 'hardBreakOpener', 'headerRows', 'sourceFormat', 'wraps', 'rawRef',
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
