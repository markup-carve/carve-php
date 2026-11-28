<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer\Check;

use MarkupCarve\Carve\Inline\Chars;
use WeakMap;

/**
 * C2-W-088: a round escapes every potential opener of each kind its parse reports in a leaf block, a port of carve-js
 * write-kinds.ts. Offsets are bytes; the byte map carve-js keeps for its UTF-16 source is the identity here.
 *
 * @phpstan-import-type Leaf from \MarkupCarve\Carve\Writer\Check\KindBlock
 * @phpstan-type Starts array<int, array{int, list<int>}>
 * @phpstan-type BlockCache array{list<\MarkupCarve\Carve\Writer\Check\Node>, array<int, \MarkupCarve\Carve\Writer\Check\KindBlock>}
 * @phpstan-type BraceContext array{ranges: list<array{int, int}>, starts: Starts, comments: array<int, true>}
 */
final class Kinds
{
    /**
     * @var string
     */
    private const BRACED_MARKS = '*+,-/=^_~';

    /**
     * The first characters of the block line shapes of data/line-shapes.json; digits and letters start ordered markers.
     *
     * @var string
     */
    private const LINE_FIRST = '!#$%*+-.:=>[^`{|';

    /**
     * @var \WeakMap<\MarkupCarve\Carve\Writer\Check\WriterState, BlockCache>|null
     */
    private static ?WeakMap $blockCache = null;

    /**
     * C2-W-088: the text nodes of each block of `group`, in order, with the block that holds them, by node id.
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     *
     * @return array<int, \MarkupCarve\Carve\Writer\Check\KindBlock>
     */
    private static function kindBlocks(WriterState $w, array $group): array
    {
        $owners = [];
        $pending = array_map(static fn (Node $node): array => [$node, false], $group);
        while (($item = array_pop($pending)) !== null) {
            [$node, $bareBreaks] = $item;
            if (Compare::isInlineParent($node)) {
                $block = new KindBlock();
                self::flatten($node->children(), $block->leaves, $bareBreaks);
                foreach ($block->leaves as $index => $leaf) {
                    if ($leaf['text'] !== null) {
                        $owners[spl_object_id($leaf['text'])] = $block;
                        $block->places[spl_object_id($leaf['text'])] = $index;
                    }
                }

                continue;
            }
            // C2-W-080: below a hard-break opener and in a line block a hard break is the bare line ending.
            $bare = $bareBreaks || $node->type === 'line_block' || ($node->type === 'div' && $w->spell->usesHardBreakOpener($w, $node));
            foreach ($node->children() as $child) {
                $pending[] = [$child, $bare];
            }
        }

        return $owners;
    }

    /**
     * `kindBlocks` of a group, kept while the rounds of the same writer repair the same blocks.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $check
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     *
     * @return array<int, \MarkupCarve\Carve\Writer\Check\KindBlock>
     */
    private static function groupKindBlocks(Check $check, array $group): array
    {
        self::$blockCache ??= new WeakMap();
        $known = self::$blockCache[$check->w] ?? null;
        if ($known !== null && count($known[0]) === count($group)) {
            $same = true;
            foreach ($known[0] as $at => $node) {
                $same = $same && $node === $group[$at];
            }
            if ($same) {
                return $known[1];
            }
        }
        $owners = self::kindBlocks($check->w, $group);
        self::$blockCache[$check->w] = [$group, $owners];

        return $owners;
    }

    /**
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $nodes
     * @param list<Leaf> $out
     * @param bool $bareBreaks
     */
    private static function flatten(array $nodes, array &$out, bool $bareBreaks): void
    {
        foreach ($nodes as $node) {
            if ($node->type === 'text') {
                $before = $out[count($out) - 1] ?? null;
                $out[] = self::leaf($node, $before === null || ($before['text'] === null && $before['kind'] === 'break'));
            } elseif ($node->isBreak()) {
                // C2-I-047: the backslash of a written hard break is no whitespace, so a delimiter before it can open.
                $out[] = self::markup('break', false, null, $node->type === 'hard_break' && !$bareBreaks);
            } else {
                // The source of a smart dash is hyphens that the written text joins (C2-W-099).
                $dash = $node->type === 'smart_punctuation' && preg_match('/^-+$/', $node->string('source') ?? '') === 1;
                $out[] = self::markup('markup', $dash, null, false);
                self::flatten($node->children(), $out, $bareBreaks);
                // The closer of a paired node starts with its mark in either form.
                $mark = isset(Compare::BRACKETED[$node->type]) ? null : (Compare::CLOSING_MARKS[$node->type] ?? null);
                $out[] = self::markup('markup', $dash, $mark, false);
            }
        }
    }

    /**
     * @return Leaf
     */
    private static function leaf(Node $text, bool $lineStart): array
    {
        return ['text' => $text, 'lineStart' => $lineStart, 'kind' => '', 'dash' => false, 'mark' => null, 'backslash' => false];
    }

    /**
     * @return Leaf
     */
    private static function markup(string $kind, bool $dash, ?string $mark, bool $backslash): array
    {
        return ['text' => null, 'lineStart' => false, 'kind' => $kind, 'dash' => $dash, 'mark' => $mark, 'backslash' => $backslash];
    }

    /**
     * The kind of the opener escaped at `at`, as a key: `L` and the shape for a line start, else the escaped character,
     * with the mark of a braced opener and `-` twice for the second hyphen of a run (C2-W-090, C2-W-099).
     */
    private static function kindKey(KindBlock $block, int $index, int $at, bool $line): string
    {
        $value = self::textOf($block, $index);
        if ($line) {
            return 'L' . self::lineShape($value);
        }
        $ch = $value[$at] ?? '';
        if ($ch === '{' && $at + 1 < strlen($value) && str_contains(self::BRACED_MARKS, $value[$at + 1])) {
            return '{' . $value[$at + 1];
        }
        // C2-W-088: a brace that ends its text reads as a braced opener with the mark of the markup behind it.
        if ($ch === '{') {
            return $at + 1 < strlen($value) ? '{' : '{' . (self::markBehind($block, $index) ?? '');
        }
        if (
            $ch === '-' && (self::hyphenRun($block, $index, $at)[0] > 0 || ($at === 0 && self::isDash($block->leaves[$index - 1] ?? null))
            || self::besideDash($block, $index, $at))
        ) {
            return '--';
        }

        return $ch;
    }

    /**
     * A lone hyphen of a text whose run goes on into a written dash beside it (C2-I-111).
     */
    private static function besideDash(KindBlock $block, int $index, int $at): bool
    {
        $value = self::textOf($block, $index);
        if (($at > 0 && $value[$at - 1] === '-') || ($value[$at + 1] ?? '') === '-') {
            return false;
        }

        return ($at === 0 && self::isDash($block->leaves[$index - 1] ?? null))
            || ($at === strlen($value) - 1 && self::isDash($block->leaves[$index + 1] ?? null));
    }

    /**
     * @phpstan-param Leaf|null $leaf
     */
    private static function isDash(?array $leaf): bool
    {
        return $leaf !== null && $leaf['text'] === null && $leaf['dash'];
    }

    /**
     * The mark of the paired markup directly behind the text leaf `index`, or null.
     */
    private static function markBehind(KindBlock $block, int $index): ?string
    {
        $next = $block->leaves[$index + 1] ?? null;

        return $next !== null && $next['text'] === null ? $next['mark'] : null;
    }

    private static function textOf(KindBlock $block, int $index): string
    {
        return $block->leaves[$index]['text']->value ?? '';
    }

    /**
     * The text leaf before or after `index` with nothing between them, or -1.
     */
    private static function adjacentText(KindBlock $block, int $index, int $step): int
    {
        $leaf = $block->leaves[$index + $step] ?? null;

        return $leaf !== null && $leaf['text'] !== null ? $index + $step : -1;
    }

    /**
     * The place of the hyphen at `at` in its run and the length of the run, over adjacent text nodes (C2-W-099).
     *
     * @return array{int, int}
     */
    private static function hyphenRun(KindBlock $block, int $index, int $at): array
    {
        $value = self::textOf($block, $index);
        $start = $at;
        while ($start > 0 && $value[$start - 1] === '-') {
            $start--;
        }
        $end = $at + 1;
        $valueLength = strlen($value);
        while ($end < $valueLength && $value[$end] === '-') {
            $end++;
        }
        $before = $start === 0 ? self::runOver($block, $index, -1) : 0;
        $after = $end === strlen($value) ? self::runOver($block, $index, 1) : 0;

        return [$before + $at - $start, $before + $end - $start + $after];
    }

    /**
     * The hyphens that the adjacent text nodes in direction `step` add to a run that reaches the edge of `index`.
     */
    private static function runOver(KindBlock $block, int $index, int $step): int
    {
        $total = 0;
        for ($leaf = self::adjacentText($block, $index, $step); $leaf >= 0; $leaf = self::adjacentText($block, $leaf, $step)) {
            $text = self::textOf($block, $leaf);
            $run = 0;
            $textLength = strlen($text);
            while ($run < $textLength && $text[$step < 0 ? $textLength - 1 - $run : $run] === '-') {
                $run++;
            }
            $total += $run;
            if ($run < strlen($text)) {
                break;
            }
        }

        return $total;
    }

    /**
     * The shape of a line opener: its first character, or `0` and the delimiter for an ordered marker.
     */
    private static function lineShape(string $value): string
    {
        $end = self::markerEnd($value);
        if ($end > 0) {
            return '0' . ($value[$end] ?? '');
        }

        return $value[0] ?? '';
    }

    private static function markerEnd(string $value): int
    {
        $end = 0;
        $valueLength = strlen($value);
        while ($end < $valueLength && ctype_alnum($value[$end])) {
            $end++;
        }

        return $end;
    }

    /**
     * The offset that makes the line opener of kind `key` text, where `leaf` starts a line of that shape.
     *
     * @phpstan-param Leaf $leaf
     *
     * @param string $key
     * @param array $leaf
     *
     * @return list<int>
     */
    private static function lineOffsets(string $key, array $leaf): array
    {
        $value = $leaf['text']->value ?? '';
        if (!$leaf['lineStart'] || $value === '' || 'L' . self::lineShape($value) !== $key) {
            return [];
        }
        if (str_starts_with($key, 'L0')) {
            return [self::markerEnd($value)];
        }

        return [str_starts_with($value, '--') ? 1 : 0];
    }

    /**
     * C2-W-099: the second hyphen of each run, or the hyphen beside a written dash. C2-W-088: a single hyphen is no
     * potential opener of this kind.
     *
     * @return list<int>
     */
    private static function hyphenOffsets(KindBlock $block, int $index): array
    {
        $value = self::textOf($block, $index);
        $offsets = [];
        for ($at = strpos($value, '-'); $at !== false; $at = strpos($value, '-', $at + 1)) {
            if (self::hyphenRun($block, $index, $at)[0] === 1 || self::besideDash($block, $index, $at)) {
                $offsets[] = $at;
            }
        }

        return $offsets;
    }

    /**
     * The first character of each run of the kind, and of `/` and `*` each one that can open.
     *
     * @return list<int>
     */
    private static function charOffsets(string $key, KindBlock $block, int $index, string $value): array
    {
        $offsets = [];
        $ch = $key[0] ?? '';
        if ($ch === '') {
            return [];
        }
        $delimiter = $ch === '/' || $ch === '*';
        for ($at = strpos($value, $ch); $at !== false; $at = strpos($value, $ch, $at + 1)) {
            if (strlen($key) === 2 && ($at + 1 < strlen($value) ? $value[$at + 1] : self::markBehind($block, $index)) !== $key[1]) {
                continue;
            }
            $opens = $delimiter
                ? self::canOpen($block, $index, $at)
                : (strlen($key) > 1 && $ch === '{' ? true : ($at === 0 || $value[$at - 1] !== $ch));
            if ($opens) {
                $offsets[] = $at;
            }
        }

        return $offsets;
    }

    /**
     * The offsets of the openers of kind `key` in the text of the leaf `index` of `block`.
     *
     * @return list<int>
     */
    private static function kindOffsets(string $key, KindBlock $block, int $index): array
    {
        $leaf = $block->leaves[$index];
        if (str_starts_with($key, 'L')) {
            return self::lineOffsets($key, $leaf);
        }
        if ($key === '--') {
            return self::hyphenOffsets($block, $index);
        }

        return self::charOffsets($key, $block, $index, $leaf['text']->value ?? '');
    }

    /**
     * C2-I-047, C2-I-049: a delimiter whose neighbors let it open, by the characters written around it.
     */
    private static function canOpen(KindBlock $block, int $index, int $at): bool
    {
        $value = self::textOf($block, $index);
        $ch = ord($value[$at]);
        $before = $at > 0 ? Chars::before($value, $at) : self::edge($block, $index, -1);
        $after = $at + 1 < strlen($value) ? Chars::at($value, $at + 1) : self::edge($block, $index, 1);
        if ($before === $ch || $after === $ch) {
            return false;
        }

        return !Chars::isWord($before) && $after !== -1 && !Chars::isSpace($after);
    }

    /**
     * The character next to a leaf, where a text leaf stands there; -1 at a line edge, `!` beside other markup.
     */
    private static function edge(KindBlock $block, int $index, int $step): int
    {
        $next = $block->leaves[$index + $step] ?? null;
        if ($next !== null && $next['text'] === null && $next['backslash'] && $step > 0) {
            return 0x5C;
        }
        if ($next === null || $next['text'] === null) {
            return $next !== null && $next['kind'] === 'markup' ? 0x21 : -1;
        }
        $value = $next['text']->value ?? '';
        if ($value === '') {
            return 0x21;
        }

        return $step < 0 ? Chars::before($value, strlen($value)) : Chars::at($value, 0);
    }

    /**
     * Adds to the escapes of `check` every opener of each kind they report in their block. A C2-W-125 trial, an escape
     * among `noKind` or `lengthening` and a block opener inside a line give no kind.
     *
     * @phpstan-param Starts|null $starts
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $check
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     * @param array<int, \MarkupCarve\Carve\Writer\Check\Escape> $noKind
     * @param array|null $starts
     */
    public static function escapeKinds(Check $check, array $group, array $noKind = [], ?array $starts = null): void
    {
        self::addKinds($check, self::reportedKinds($check, $group, $noKind, $starts));
    }

    /**
     * `escapeKinds` with the escapes `noKind` giving no kind unless `all`, and whether they change the kinds the round
     * adds: where they do not, the two forms of the pass are the same.
     *
     * @phpstan-param Starts|null $starts
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $check
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     * @param array<int, \MarkupCarve\Carve\Writer\Check\Escape> $noKind
     * @param bool $all
     * @param array|null $starts
     */
    public static function escapeKindsBeside(Check $check, array $group, array $noKind, bool $all, ?array $starts = null): bool
    {
        $kept = self::reportedKinds($check, $group, $noKind, $starts);
        $any = false;
        foreach ($check->escapes as $escape) {
            $any = $any || Check::has($noKind, $escape);
        }
        $every = $any ? self::reportedKinds($check, $group, [], $starts) : $kept;
        self::addKinds($check, $all ? $every : $kept);
        if (count($every) !== count($kept)) {
            return true;
        }
        foreach ($every as $at => [$block, $key]) {
            if ($kept[$at][0] !== $block || $kept[$at][1] !== $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * The kinds the escapes of `check` report, each once per block, in the order of the first escape that reports it.
     *
     * @phpstan-param Starts|null $starts
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $check
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     * @param array<int, \MarkupCarve\Carve\Writer\Check\Escape> $noKind
     * @param array|null $starts
     *
     * @return list<array{\MarkupCarve\Carve\Writer\Check\KindBlock, string}>
     */
    private static function reportedKinds(Check $check, array $group, array $noKind, ?array $starts): array
    {
        $trials = [];
        foreach ($check->trials as $trial) {
            $trials[spl_object_id($trial)] = true;
        }
        $candidates = [];
        $anyBrace = false;
        foreach ($check->escapes as $escape) {
            if (
                isset($check->ownEscapes[spl_object_id($escape->node)][$escape->at]) || isset($trials[spl_object_id($escape)])
                || Check::has($noKind, $escape) || Check::has($check->lengthening, $escape)
            ) {
                continue;
            }
            $candidates[] = $escape;
            $anyBrace = $anyBrace || (($escape->node->value ?? '')[$escape->at] ?? '') === '{';
        }
        $braces = $anyBrace ? self::braceContext($check, $group, $starts) : null;
        $reported = array_values(array_filter(
            $candidates,
            static fn (Escape $escape): bool => self::reportedBrace($braces, $escape->node, $escape->at),
        ));
        $kinds = [];
        if ($reported === []) {
            return $kinds;
        }
        $owners = self::groupKindBlocks($check, $group);
        $done = [];
        foreach ($reported as $escape) {
            $block = $owners[spl_object_id($escape->node)] ?? null;
            if ($block === null) {
                continue;
            }
            $key = self::reportedKey($check, $block, $escape->node, $escape->at, $braces);
            $id = spl_object_id($block);
            if (isset($done[$id][$key])) {
                continue;
            }
            $done[$id][$key] = true;
            $kinds[] = [$block, $key];
        }

        return $kinds;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\Check\Check $check
     * @param list<array{\MarkupCarve\Carve\Writer\Check\KindBlock, string}> $kinds
     */
    private static function addKinds(Check $check, array $kinds): void
    {
        foreach ($kinds as [$block, $key]) {
            if (str_starts_with($key, '{')) {
                $check->w->shared->reportedBraceKinds[$key] = true;
            }
            foreach ($block->leaves as $index => $leaf) {
                if ($leaf['text'] === null) {
                    continue;
                }
                foreach (self::kindOffsets($key, $block, $index) as $offset) {
                    Compare::escapeAt($check, $leaf['text'], $offset);
                }
            }
        }
    }

    /**
     * @phpstan-param BraceContext|null $braces
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $check
     * @param \MarkupCarve\Carve\Writer\Check\KindBlock $block
     * @param \MarkupCarve\Carve\Writer\Check\Node $node
     * @param int $at
     * @param array|null $braces
     */
    private static function reportedKey(Check $check, KindBlock $block, Node $node, int $at, ?array $braces): string
    {
        $index = $block->places[spl_object_id($node)] ?? -1;
        $inline = self::commentKind($braces, $node, $at) ?? self::kindKey($block, $index, $at, false);
        $line = isset($check->lineEscapes[spl_object_id($node)][$at])
            || ($at === 0 && ($block->leaves[$index]['lineStart'] ?? false) && $inline === '{');

        return $line ? self::kindKey($block, $index, $at, true) : $inline;
    }

    /**
     * Locate each written text leaf and each parsed text range once for the round.
     *
     * @phpstan-param Starts|null $located
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $check
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     * @param array|null $located
     *
     * @return BraceContext|null
     */
    private static function braceContext(Check $check, array $group, ?array $located): ?array
    {
        $source = $check->source;
        $parsed = $check->w->parsed;
        if ($source === null || $parsed === null) {
            return null;
        }
        $comments = [];
        $starts = $located ?? self::locateTexts($check, $group, $source);

        return ['ranges' => self::parsedTextRanges($parsed->children(), $comments), 'starts' => $starts, 'comments' => $comments];
    }

    /**
     * Where each text leaf of a group stands in its written source, as a byte offset, with the escapes it was written with.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $check
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     * @param string $source
     *
     * @return Starts
     */
    public static function locateTexts(Check $check, array $group, string $source): array
    {
        $starts = [];
        $inert = self::inertRanges($check->w->parsed?->children() ?? []);
        $pending = array_map(static fn (Node $node): array => [$node, false], array_reverse($group));
        $cursor = 0;
        while (($entry = array_pop($pending)) !== null) {
            [$node, $inCell] = $entry;
            if ($node->type !== 'text') {
                $children = $node->children();
                for ($at = count($children) - 1; $at >= 0; $at--) {
                    $pending[] = [$children[$at], $inCell || $node->type === 'table_cell'];
                }

                continue;
            }
            $value = $node->value ?? '';
            $escapes = $check->w->spell->escapeOffsets($check->w, $node, $inCell ? Spell::IN_CELL : 0);
            $written = '';
            $from = 0;
            $last = -1;
            foreach ($escapes as $at) {
                if ($at <= $last || $at >= strlen($value)) {
                    break;
                }
                $written .= substr($value, $from, $at - $from) . '\\';
                $from = $last = $at;
            }
            $written .= substr($value, $from);
            $start = self::nextTextPlace($source, $written, $cursor, $inert);
            $length = strlen($written);
            if ($start < 0) {
                $start = self::nextTextPlace($source, $value, $cursor, $inert);
                $length = strlen($value);
            }
            if ($start < 0) {
                continue;
            }
            $starts[spl_object_id($node)] = [$start, $escapes];
            $cursor = $start + $length;
        }

        return $starts;
    }

    /**
     * Text in a code, literal, or math span cannot be the source of a text leaf.
     *
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $nodes
     *
     * @return list<array{int, int}>
     */
    private static function inertRanges(array $nodes): array
    {
        $ranges = [];
        $pending = $nodes;
        while (($node = array_pop($pending)) !== null) {
            if (($node->type === 'code' || $node->type === 'literal_inline' || $node->type === 'math') && $node->start !== null) {
                $ranges[] = [$node->start, $node->end ?? $node->start];
            } else {
                array_push($pending, ...$node->children());
            }
        }
        usort($ranges, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $ranges;
    }

    /**
     * @param string $source
     * @param string $value
     * @param int $cursor
     * @param list<array{int, int}> $inert
     */
    private static function nextTextPlace(string $source, string $value, int $cursor, array $inert): int
    {
        if ($value === '') {
            return $cursor;
        }
        for ($start = strpos($source, $value, $cursor); $start !== false; $start = strpos($source, $value, $start + 1)) {
            if (!self::inRange($inert, $start)) {
                return $start;
            }
        }

        return -1;
    }

    /**
     * Whether `byte` falls in the last range that starts at or before it, of the sorted `ranges`.
     *
     * @param list<array{int, int}> $ranges
     * @param int $byte
     */
    private static function inRange(array $ranges, int $byte): bool
    {
        $low = 0;
        $high = count($ranges);
        while ($low < $high) {
            $middle = ($low + $high) >> 1;
            if ($ranges[$middle][0] <= $byte) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low !== 0 && $byte < $ranges[$low - 1][1];
    }

    /**
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $nodes
     * @param array<int, true> $comments
     *
     * @return list<array{int, int}>
     */
    private static function parsedTextRanges(array $nodes, array &$comments): array
    {
        $ranges = [];
        $pending = $nodes;
        while (($node = array_pop($pending)) !== null) {
            if ($node->type === 'text' && $node->start !== null) {
                $ranges[] = [$node->start, $node->end ?? $node->start];
            }
            $comment = $node->type === 'critic_comment' || ($node->type === 'comment' && $node->field('form') === 'braced');
            if ($comment && $node->start !== null) {
                $comments[$node->start] = true;
            }
            array_push($pending, ...$node->children());
        }
        usort($ranges, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $ranges;
    }

    /**
     * A brace still held by parsed text is a case repair, not a reported construct.
     *
     * @phpstan-param BraceContext|null $context
     *
     * @param int $at
     * @param array|null $context
     * @param \MarkupCarve\Carve\Writer\Check\Node $text
     */
    private static function reportedBrace(?array $context, Node $text, int $at): bool
    {
        $byte = (($text->value ?? '')[$at] ?? '') === '{' ? self::braceByte($context, $text, $at) : null;
        if ($byte === null || $context === null) {
            return true;
        }

        return !self::inRange($context['ranges'], $byte);
    }

    /**
     * The byte offset in the parsed source of the text brace at `at`, where the round located its text.
     *
     * @phpstan-param BraceContext|null $context
     *
     * @param int $at
     * @param array|null $context
     * @param \MarkupCarve\Carve\Writer\Check\Node $text
     */
    private static function braceByte(?array $context, Node $text, int $at): ?int
    {
        $located = $context['starts'][spl_object_id($text)] ?? null;
        if ($located === null) {
            return null;
        }
        [$start, $escapes] = $located;
        $before = 0;
        while (isset($escapes[$before]) && $escapes[$before] < $at) {
            $before++;
        }

        return $start + $at + $before;
    }

    /**
     * C2-W-088: a braced comment that the parse holds reports its opener with the mark behind the brace, `{%` or `{#`.
     *
     * @phpstan-param BraceContext|null $context
     *
     * @param int $at
     * @param array|null $context
     * @param \MarkupCarve\Carve\Writer\Check\Node $text
     */
    private static function commentKind(?array $context, Node $text, int $at): ?string
    {
        $value = $text->value ?? '';
        $mark = $value[$at + 1] ?? '';
        if (($value[$at] ?? '') !== '{' || ($mark !== '%' && $mark !== '#')) {
            return null;
        }
        $byte = self::braceByte($context, $text, $at);

        return $byte !== null && isset($context['comments'][$byte]) ? '{' . $mark : null;
    }

    /**
     * C2-W-125, case five: a `[` that is escaped in the content of a bracketed node leaves the `]` that closes it in
     * the text bare, which would end that node early; that `]` takes its backslash in the same round.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $check
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     */
    public static function escapePartnerClosers(Check $check, array $group): void
    {
        $pending = $group;
        while (($node = array_pop($pending)) !== null) {
            if (!isset(Compare::BRACKETED[$node->type])) {
                array_push($pending, ...$node->children());

                continue;
            }
            $open = [];
            $inner = [];
            foreach (Compare::contentClosers($node->children(), static fn (): bool => true, $inner, $open) as [$text, $at, $partner]) {
                if ($partner !== null && Compare::isEscaped($check, $partner[0], $partner[1])) {
                    Compare::escapeAt($check, $text, $at);
                }
            }
            // The bracketed nodes inside the content are read in turn, as carve-js pushes them onto `pending`.
            array_push($pending, ...($inner ?? []));
        }
    }

    /**
     * @phpstan-param Leaf $leaf
     *
     * @param array $leaf
     * @param int $at
     */
    private static function isLineOpener(array $leaf, int $at): bool
    {
        if (!$leaf['lineStart']) {
            return false;
        }
        $value = $leaf['text']->value ?? '';
        $first = $value[0] ?? '';
        if (!($first !== '' && ctype_alnum($first)) && ($first === '' || !str_contains(self::LINE_FIRST, $first))) {
            return false;
        }
        $block = new KindBlock();
        $block->leaves = [$leaf];

        return in_array($at, self::kindOffsets('L' . self::lineShape($value), $block, 0), true);
    }

    /**
     * The kinds of the escapes `escapes` in `block`; without the parse a line-start escape may be of either kind.
     *
     * @param \MarkupCarve\Carve\Writer\Check\KindBlock $block
     * @param array<int, list<int>> $escapes
     *
     * @return array<string, true>
     */
    private static function planKinds(KindBlock $block, array $escapes): array
    {
        $keys = [];
        foreach ($block->leaves as $index => $leaf) {
            if ($leaf['text'] === null) {
                continue;
            }
            foreach ($escapes[spl_object_id($leaf['text'])] ?? [] as $at) {
                $line = self::isLineOpener($leaf, $at);
                $inline = self::kindKey($block, $index, $at, false);
                if (!$line || $inline !== '{') {
                    $keys[$inline] = true;
                }
                if ($line) {
                    $keys[self::kindKey($block, $index, $at, true)] = true;
                }
            }
        }

        return $keys;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\Check\KindBlock $block
     * @param string $key
     * @param array<int, list<int>> $placed
     */
    private static function bareOpener(KindBlock $block, string $key, array $placed): bool
    {
        foreach ($block->leaves as $index => $leaf) {
            if ($leaf['text'] === null) {
                continue;
            }
            $there = array_flip($placed[spl_object_id($leaf['text'])] ?? []);
            foreach (self::kindOffsets($key, $block, $index) as $offset) {
                if (!isset($there[$offset])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether the escapes `escapes` of a plan leave an opener of one of their kinds outside `placed` (C2-W-010).
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     * @param array<int, list<int>> $escapes
     * @param array<int, list<int>> $placed
     */
    public static function leavesKindBare(WriterState $w, array $group, array $escapes, array $placed): bool
    {
        $blocks = [];
        foreach (self::kindBlocks($w, $group) as $block) {
            $blocks[spl_object_id($block)] = $block;
        }
        foreach ($blocks as $block) {
            foreach (array_keys(self::planKinds($block, $escapes)) as $key) {
                if (self::bareOpener($block, (string)$key, $placed)) {
                    return true;
                }
            }
        }

        return false;
    }
}
