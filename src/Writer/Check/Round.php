<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer\Check;

/**
 * One round of the repair of a group (C2-W-088, C2-W-089, C2-W-125), a port of the round part of carve-js write.ts:
 * `settle` with the filters it runs on the check of the round (`dropIdleTrial`, `deferRepairs`, `extendForms`, ...),
 * the trial tests that write the group again (`isAsked`, `retestTrials`) and `applyChanges`. The pass layer of
 * write-passes.ts is not ported yet: a round runs as the first pass, which reads no switch and keeps no trial log.
 *
 * @phpstan-type Texts array<int, \MarkupCarve\Carve\Writer\Check\Node>
 * @phpstan-type Starts list<array{\MarkupCarve\Carve\Writer\Check\Node, int}>
 * @phpstan-type Saved array{escapes: array<int, list<int>>, escaped: Texts, lines: list<string>, starts: Starts}
 */
final class Round
{
    /**
     * @var int The trials a pass tests together at most: the subsets grow as 2^n (write-passes.ts `MAX_RETESTED_TRIALS`).
     */
    public const MAX_RETESTED_TRIALS = 8;

    /**
     * @var string The marks of braced openers, in the order of `BRACED_NODES`.
     */
    private const BRACED_MARKS = '*+,-/=^_~';

    /**
     * @var list<string>
     */
    private const BRACED_NODES = ['strong', 'insert', 'subscript', 'delete', 'emphasis', 'highlight', 'superscript', 'underline', 'strike'];

    /**
     * @var list<string> C2-W-016: the inline leaves whose later lines are lines of the leaf.
     */
    private const LINE_LEAVES = ['code', 'literal_inline', 'math', 'raw_inline', 'comment', 'critic_comment'];

    /**
     * @var int The nesting of inline nodes stays under the depth cap of the parser.
     */
    private const MAX_LEAF_DEPTH = 200;

    /**
     * @var array<int, list<int>> The subsets of each log length, fewest first.
     */
    private static array $subsetOrders = [];

    /**
     * One round of the test of C2-W-089 on `group`, which stands in `w->lines`. False where the group reads back as its
     * blocks. C2-W-088 places all escapes reported by the same read-back parse.
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param \MarkupCarve\Carve\Writer\Check\Node $document
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     * @param bool $initial
     * @param bool $first
     */
    public static function settle(WriterState $w, Node $document, array $group, bool $first, bool $initial): bool
    {
        $check = self::compareWithLinkFallback($w, $document, $group, $first, $initial);
        // C2-W-125 tries to form a missing node separately from the constructs C2-W-088 found in the read-back parse.
        self::dropEarlyTrials($check);
        PassMemo::logTrials($w, $check->trials);
        $dropped = self::dropIdleTrial($w, $check, $group, $first);
        $dropped = self::dropIdleLeads($w, $group, $first, $check) || $dropped;
        self::deferRepairs($check);
        $dropped = self::dropIdleTrials($w, $check, $group, $first) || $dropped;
        if ($dropped && $check->changes() === 0) {
            return true;
        }
        self::keepCodeTrial($check);
        self::dropLineDuplicates($check);
        if ($w->kindRounds) {
            self::escapeReportedKinds($w, $check, $group);
        }
        Compare::extendChains($check);
        self::extendForms($w, $check, $group);
        self::extendClosers($w, $check, $group);
        Compare::escapeCappedRun($check);
        self::dropEscapesAfterConstruct($w, $group, $first, $check);
        self::retestTrials($w, $group, $first, $check);
        PassMemo::logTrials($w, $check->escapes);
        if ($check->changes() === 0 && $check->losses !== [] && $first && !$w->blankFirst) {
            // C2-W-005: what only line 1 reads differently is written below one blank line, and the rounds go on there.
            $w->blankFirst = Compare::compareGroup($w, $document, $group, self::source($w, false), false)->losses === [];
            if ($w->blankFirst) {
                return true;
            }
        }
        $w->lastCheck = $check;

        return self::applyChanges($w, $check);
    }

    /**
     * The written group as the parse reads it: behind a blank line where it is not the first group.
     */
    public static function source(WriterState $w, bool $first): string
    {
        return ($first ? '' : "\n") . implode("\n", $w->lines) . "\n";
    }

    /**
     * The escape of a round that asks for nothing but the escape of one construct's `[` (write-chain.ts).
     */
    public static function openerOnly(Check $check): ?Escape
    {
        if (
            count($check->escapes) !== 1 || count($check->forms) + count($check->retracted) + count($check->trials) > 0
            || $check->losses !== [] || $check->misaligned || count($check->constructs) !== 1
        ) {
            return null;
        }
        $escape = $check->escapes[0];

        return Check::has($check->constructs, $escape) && (($escape->node->value ?? '')[$escape->at] ?? '') === '[' ? $escape : null;
    }

    /**
     * C2-W-088, C2-W-125 case five: the first round writes the brackets of link text bare. Where the round asks
     * nothing and the bare text reads back, or reports a construct outside the links, the brackets stay bare.
     * Otherwise the brackets of link text without a partner take their backslash (`linkFallback`) and the round is
     * compared again.
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param \MarkupCarve\Carve\Writer\Check\Node $document
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     * @param bool $initial
     * @param bool $first
     */
    private static function compareWithLinkFallback(WriterState $w, Node $document, array $group, bool $first, bool $initial): Check
    {
        $attempts = $initial && $w->sawLink && !$w->linkFallback ? self::copyAttempts($w->attempts) : null;
        $check = Compare::compareGroup($w, $document, $group, self::source($w, $first), $first);
        if ($attempts === null) {
            return $check;
        }
        if (self::asksNothing($check) && self::readsBack($w, $group, $first)) {
            return $check;
        }
        if (self::reportsConstruct($check, $group) && !self::linkRepairRequested($group, $check)) {
            // C2-W-125: the case backslash is placed in this round, beside the kind escapes (carve#6656, carve#6681).
            $w->linkFallback = true;

            return $check;
        }
        $w->attempts = $attempts;
        $w->linkFallback = true;
        self::resetLines($w);
        $w->writeGroup($group);

        return Compare::compareGroup($w, $document, $group, self::source($w, $first), $first);
    }

    private static function asksNothing(Check $check): bool
    {
        return $check->constructs === [] && $check->escapes === [] && $check->forms === [] && $check->retracted === []
            && $check->losses === [] && !$check->misaligned;
    }

    /**
     * Whether the parse holds a construct the tree does not hold. In a group with a caret pair, case eight can keep a
     * text `^[` bare, so there only a construct on another text `[` counts.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Check $check
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     */
    private static function reportsConstruct(Check $check, array $group): bool
    {
        if ($check->constructs === []) {
            return false;
        }
        $caretPair = false;
        foreach ($group as $node) {
            $caretPair = $caretPair || Compare::holdsCaretPair($node);
        }
        if (!$caretPair) {
            return true;
        }
        foreach ($check->constructs as $escape) {
            $value = $escape->node->value ?? '';
            if (($value[$escape->at] ?? '') === '[' && ($escape->at === 0 || $value[$escape->at - 1] !== '^')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, \MarkupCarve\Carve\Writer\Check\Attempt> $attempts
     *
     * @return array<int, \MarkupCarve\Carve\Writer\Check\Attempt>
     */
    private static function copyAttempts(array $attempts): array
    {
        $copy = [];
        foreach ($attempts as $id => $attempt) {
            $copy[$id] = new Attempt($attempt->step, $attempt->escapes, $attempt->node);
        }

        return $copy;
    }

    /**
     * Whether the round asks for an escape, a form or a loss in a link or below one.
     *
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     * @param \MarkupCarve\Carve\Writer\Check\Check $check
     */
    private static function linkRepairRequested(array $group, Check $check): bool
    {
        $asked = [];
        foreach ($check->escapes as $escape) {
            $asked[spl_object_id($escape->node)] = true;
        }
        foreach ($check->losses as [$node]) {
            $asked[spl_object_id($node)] = true;
        }
        foreach ($check->forms as $node) {
            $asked[spl_object_id($node)] = true;
        }
        if ($asked === []) {
            return false;
        }
        $pending = array_map(static fn (Node $node): array => [$node, false], $group);
        while (($item = array_pop($pending)) !== null) {
            $inside = $item[1] || $item[0]->type === 'link';
            if ($inside && isset($asked[spl_object_id($item[0])])) {
                return true;
            }
            foreach ($item[0]->children() as $child) {
                $pending[] = [$child, $inside];
            }
        }

        return false;
    }

    /**
     * A reported construct's opener can settle the tree before repairs beside its marks are needed.
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     * @param \MarkupCarve\Carve\Writer\Check\Check $check
     * @param bool $first
     */
    private static function dropEscapesAfterConstruct(WriterState $w, array $group, bool $first, Check $check): void
    {
        if ($check->constructs === [] || count($check->escapes) <= count($check->constructs) || $check->forms !== []) {
            return;
        }
        foreach ($check->constructs as $escape) {
            $pair = substr($escape->node->value ?? '', $escape->at, 2);
            if ($pair !== '{-' && $pair !== '{+') {
                return;
            }
        }
        $kept = array_values(array_filter($check->escapes, static fn (Escape $escape): bool => Check::has($check->constructs, $escape)));
        if ($kept === []) {
            return;
        }
        $saved = self::save($w);
        $candidates = $w->escapes;
        foreach ($kept as $escape) {
            self::insert($candidates, $escape);
        }
        $parsed = self::groupTree($w, $group, $first, $candidates)->children();
        $equal = self::groupEqual($w, $group, $parsed);
        self::restore($w, $saved);
        if ($equal) {
            $check->escapes = $kept;
        }
    }

    /**
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param \MarkupCarve\Carve\Writer\Check\Check $check
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     * @param bool $first
     */
    private static function dropIdleTrial(WriterState $w, Check $check, array $group, bool $first): bool
    {
        $trial = count($check->trials) === 1 ? $check->trials[0] : null;
        if ($trial === null || self::isAsked($w, $group, $first, $trial->node, $trial->at)) {
            return false;
        }
        foreach ($check->escapes as $place => $escape) {
            if ($escape->node === $trial->node && $escape->at === $trial->at) {
                array_splice($check->escapes, $place, 1);

                break;
            }
        }
        unset($check->marks[spl_object_id($trial->node)][$trial->at]);

        return true;
    }

    /**
     * C2-W-088, C2-W-125: the escape of a closer that ended a node early, or that the content took from the text behind
     * it, or that lengthens a node, waits for the parse after the escape of the reported construct.
     */
    private static function deferRepairs(Check $check): void
    {
        if ($check->constructs === []) {
            return;
        }
        $kept = [];
        foreach ($check->escapes as $escape) {
            $deferred = !Check::has($check->constructs, $escape) && (Check::has($check->closers, $escape)
                || Check::has($check->behind, $escape)
                || (Check::has($check->lengthening, $escape) && !Check::has($check->pairedLengthening, $escape)));
            if ($deferred) {
                unset($check->marks[spl_object_id($escape->node)][$escape->at]);

                continue;
            }
            $kept[] = $escape;
        }
        $check->escapes = $kept;
        $check->earlyClosers = array_values(array_filter(
            $check->earlyClosers,
            static fn (array $closer): bool => in_array($closer[1], $check->escapes, true),
        ));
    }

    /**
     * C2-W-125, C2-W-088: a content-start trial among several is tested on its own in the group, like a lone one.
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     * @param \MarkupCarve\Carve\Writer\Check\Check $check
     * @param bool $first
     */
    private static function dropIdleLeads(WriterState $w, array $group, bool $first, Check $check): bool
    {
        $count = count($check->trials);
        if ($count < 2 || $count > 4 || $check->leadTrials === []) {
            return false;
        }
        $dropped = false;
        foreach ($check->trials as $trial) {
            if (!Check::has($check->leadTrials, $trial) || self::isAsked($w, $group, $first, $trial->node, $trial->at)) {
                continue;
            }
            $place = array_search($trial, $check->escapes, true);
            if ($place !== false) {
                array_splice($check->escapes, $place, 1);
            }
            unset($check->marks[spl_object_id($trial->node)][$trial->at]);
            $dropped = true;
        }
        if ($dropped) {
            $check->trials = array_values(array_filter(
                $check->trials,
                static fn (Escape $trial): bool => in_array($trial, $check->escapes, true),
            ));
        }

        return $dropped;
    }

    /**
     * C2-W-125: a trial that changes nothing beside the other escapes of the round is taken out, where the parse reads
     * no node the tree lacks (carve#6687).
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param \MarkupCarve\Carve\Writer\Check\Check $check
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     * @param bool $first
     */
    private static function dropIdleTrials(WriterState $w, Check $check, array $group, bool $first): bool
    {
        $count = count($check->trials);
        if ($count < 2 || $count > self::MAX_RETESTED_TRIALS || !self::readsNoOtherNode($check->w->parsed, $group)) {
            return false;
        }
        $saved = self::save($w);
        $base = [];
        foreach ($w->escapes as $id => $offsets) {
            $base[$id] = array_values(array_filter($offsets, static function (int $at) use ($check, $id): bool {
                foreach ($check->retracted as $escape) {
                    if (spl_object_id($escape->node) === $id && $escape->at === $at) {
                        return false;
                    }
                }

                return true;
            }));
        }
        $withEscapes = static function (array $escapes) use ($base): array {
            $all = $base;
            foreach ($escapes as $escape) {
                self::insert($all, $escape);
            }

            return $all;
        };
        $dropped = false;
        $all = self::groupTree($w, $group, $first, $withEscapes($check->escapes));
        // The later trial goes first, so the earlier one, which the rounds of C2-W-088 reach first, is kept.
        foreach (array_reverse($check->trials) as $trial) {
            $others = array_values(array_filter($check->escapes, static fn (Escape $escape): bool => $escape !== $trial));
            $without = self::groupTree($w, $group, $first, $withEscapes($others));
            if (!Compare::treesEqual($all, $without)) {
                continue;
            }
            $all = $without;
            $check->escapes = $others;
            unset($check->marks[spl_object_id($trial->node)][$trial->at]);
            $check->trials = array_values(array_filter($check->trials, static fn (Escape $other): bool => $other !== $trial));
            $dropped = true;
        }
        self::restore($w, $saved);

        return $dropped;
    }

    /**
     * Whether each leaf block of `parsed` holds, beside text and breaks, only inline nodes that equal nodes of the same
     * leaf of the tree, in their order.
     *
     * @param \MarkupCarve\Carve\Writer\Check\Node|null $parsed
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     */
    private static function readsNoOtherNode(?Node $parsed, array $group): bool
    {
        if ($parsed === null) {
            return false;
        }
        $read = self::leafBlocks($parsed->children());
        $tree = self::leafBlocks($group);
        if (count($read) !== count($tree)) {
            return false;
        }
        foreach ($read as $index => $leaf) {
            $held = $tree[$index]->children();
            $heldCount = count($held);
            $at = 0;
            foreach ($leaf->children() as $node) {
                if ($node->type === 'text' || $node->isBreak()) {
                    continue;
                }
                while ($at < $heldCount && !Compare::treesEqual($held[$at], $node)) {
                    $at++;
                }
                if ($at++ === $heldCount) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $nodes
     *
     * @return list<\MarkupCarve\Carve\Writer\Check\Node>
     */
    private static function leafBlocks(array $nodes): array
    {
        $leaves = [];
        $pending = array_reverse($nodes);
        while (($node = array_pop($pending)) !== null) {
            if (Compare::isInlineParent($node)) {
                $leaves[] = $node;
            } else {
                array_push($pending, ...array_reverse($node->children()));
            }
        }

        return $leaves;
    }

    /**
     * C2-W-125, case eight: the trials of a round that escaped an early note closer read the note it undoes.
     */
    private static function dropEarlyTrials(Check $check): void
    {
        if (!$check->earlyCloser) {
            return;
        }
        foreach ($check->trials as $trial) {
            $place = array_search($trial, $check->escapes, true);
            if ($place !== false) {
                array_splice($check->escapes, $place, 1);
            }
            unset($check->marks[spl_object_id($trial->node)][$trial->at]);
        }
        $check->trials = [];
    }

    /**
     * C2-W-075: a reference whose recorded text did not read back is written from the tree, and so is each reference of
     * the group recorded with the same text.
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param \MarkupCarve\Carve\Writer\Check\Check $check
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     */
    private static function extendForms(WriterState $w, Check $check, array $group): void
    {
        $texts = [];
        foreach ($check->forms as $node) {
            $raw = $w->recordedRaw($node);
            if ($raw !== null) {
                $texts[$raw] = true;
            }
        }
        if ($texts === []) {
            return;
        }
        $asked = [];
        foreach ($check->forms as $node) {
            $asked[spl_object_id($node)] = true;
        }
        $pending = $group;
        while (($node = array_pop($pending)) !== null) {
            $raw = isset($asked[spl_object_id($node)]) || ($w->forms[spl_object_id($node)] ?? 0) > 0 ? null : $w->recordedRaw($node);
            if ($raw !== null && isset($texts[$raw])) {
                $check->forms[] = $node;
                $asked[spl_object_id($node)] = true;
            }
            foreach ($node->slots as $children) {
                array_push($pending, ...$children);
            }
        }
    }

    /**
     * C2-W-093, C2-W-010: a braced emphasis or strong whose content closer took a backslash gives the same backslash
     * to each braced node of the group with the same content.
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param \MarkupCarve\Carve\Writer\Check\Check $check
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     */
    private static function extendClosers(WriterState $w, Check $check, array $group): void
    {
        if ($check->earlyClosers === []) {
            return;
        }
        $asked = [];
        $shapes = [];
        foreach ($check->earlyClosers as [$owner, $escape]) {
            $path = self::pathTo($owner, $escape->node);
            if ($path === null) {
                continue;
            }
            $asked[self::contentKey($w, $owner)] = [$owner, $path, $escape->at];
            $shapes[self::shapeOf($owner)] = true;
        }
        $pending = $group;
        while (($node = array_pop($pending)) !== null) {
            foreach ($node->slots as $children) {
                array_push($pending, ...$children);
            }
            $braced = self::writtenBraced($w, $node) && isset($shapes[self::shapeOf($node)]);
            $found = $braced ? ($asked[self::contentKey($w, $node)] ?? null) : null;
            $text = $found === null || $found[0] === $node ? null : self::follow($node, $found[1]);
            if ($text !== null && Compare::escapeAt($check, $text, $found[2])) {
                Check::add($check->closers, $check->last());
            }
        }
    }

    private static function shapeOf(Node $node): string
    {
        return $node->type . ' ' . count($node->children());
    }

    private static function writtenBraced(WriterState $w, Node $node): bool
    {
        return ($node->type === 'emphasis' || $node->type === 'strong')
            && ($w->isBracedAnyway($node) || ($w->forms[spl_object_id($node)] ?? 0) > 0);
    }

    /**
     * The type and fields of `node` and of everything below it, without positions.
     */
    private static function contentKey(WriterState $w, Node $node): string
    {
        $id = spl_object_id($node);
        $known = $w->shared->contentKeys[$id] ?? null;
        if ($known !== null) {
            return $known;
        }
        $fields = $node->fields;
        unset($fields['pos'], $fields['children']);
        $children = implode(',', array_map(static fn (Node $child): string => self::contentKey($w, $child), $node->children()));
        $key = json_encode($fields) . "[{$children}]";
        $w->shared->held[] = $node;
        $w->shared->contentKeys[$id] = $key;

        return $key;
    }

    /**
     * @return list<int>|null
     */
    private static function pathTo(Node $from, Node $text): ?array
    {
        if ($from === $text) {
            return [];
        }
        foreach ($from->children() as $at => $child) {
            $rest = self::pathTo($child, $text);
            if ($rest !== null) {
                return [$at, ...$rest];
            }
        }

        return null;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\Check\Node $from
     * @param list<int> $path
     */
    private static function follow(Node $from, array $path): ?Node
    {
        $node = $from;
        foreach ($path as $at) {
            $node = $node?->children()[$at] ?? null;
        }

        return $node?->type === 'text' ? $node : null;
    }

    /**
     * C2-W-092, C2-W-152: a line whose opener this round escapes takes no second backslash on that opener.
     */
    private static function dropLineDuplicates(Check $check): void
    {
        if ($check->lineEscapes === []) {
            return;
        }
        $trials = [];
        foreach ($check->trials as $trial) {
            $trials[spl_object_id($trial)] = true;
        }
        for ($place = count($check->escapes) - 1; $place >= 0; $place--) {
            $escape = $check->escapes[$place];
            $line = $check->lineEscapes[spl_object_id($escape->node)] ?? null;
            $opener = $line !== null && in_array($escape->at, Compare::openerOffsets($escape->node->value ?? ''), true);
            if (!$opener || isset($line[$escape->at])) {
                continue;
            }
            if (isset($trials[spl_object_id($escape)]) || isset($check->ownEscapes[spl_object_id($escape->node)][$escape->at])) {
                array_splice($check->escapes, $place, 1);
            }
        }
    }

    /**
     * The group written with the escapes `escapes` and parsed (carve-js `groupTree`).
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     * @param bool $first
     * @param array<int, list<int>> $escapes
     */
    private static function groupTree(WriterState $w, array $group, bool $first, array $escapes): Node
    {
        self::useEscapes($w, $escapes);
        $w->lines = [];
        $w->starts = [];
        $w->writeGroup($group);

        return Compare::parseChunk($w, self::source($w, $first));
    }

    /**
     * C2-W-125: once every other form lost a node, a trial that gives the tree with every placed escape is taken.
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     * @param bool $first
     * @param array<int, list<int>> $placed
     * @param int $at
     * @param \MarkupCarve\Carve\Writer\Check\Node $node
     */
    private static function givesTree(WriterState $w, array $group, bool $first, array $placed, Node $node, int $at): bool
    {
        $all = $placed;
        self::insert($all, new Escape($node, $at));

        return self::groupEqual($w, $group, self::groupTree($w, $group, $first, $all)->children());
    }

    /**
     * Whether a trial escape changes the tree with earlier escapes in place, or gives it in a form of `wholeTrials`.
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     * @param int $at
     * @param \MarkupCarve\Carve\Writer\Check\Node $node
     * @param bool $first
     */
    private static function isAsked(WriterState $w, array $group, bool $first, Node $node, int $at): bool
    {
        $saved = self::save($w);
        $order = self::textOrder($group);
        $rank = $order[spl_object_id($node)] ?? -1;
        $left = [];
        foreach ($w->escapes as $id => $offsets) {
            $own = $order[$id] ?? -1;
            if ($own < $rank) {
                $left[$id] = $offsets;
            } elseif ($id === spl_object_id($node)) {
                $left[$id] = array_values(array_filter($offsets, static fn (int $offset): bool => $offset < $at));
            }
        }
        $escaped = $left;
        $escaped[spl_object_id($node)] = [...($left[spl_object_id($node)] ?? []), $at];
        $w->escaped[spl_object_id($node)] = $node;
        $without = self::groupTree($w, $group, $first, $left);
        $asked = !Compare::treesEqual(self::groupTree($w, $group, $first, $escaped), $without);
        if (!$asked) {
            PassMemo::noteRead($w, PassMemo::READ_WHOLE_TRIALS);
            $asked = $w->wholeTrials && self::givesTree($w, $group, $first, $saved['escapes'], $node, $at);
            if (!$w->wholeTrials) {
                $kept = $saved['escapes'];
                PassMemo::noteWholeTest($w, static fn (): bool => self::givesTree($w, $group, $first, $kept, $node, $at));
            }
        }
        self::restore($w, $saved);

        return $asked;
    }

    /**
     * Text nodes in source order, so a trial sees the escapes already placed to its left.
     *
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $nodes
     * @param array<int, int> $order
     *
     * @return array<int, int>
     */
    private static function textOrder(array $nodes, array $order = []): array
    {
        foreach ($nodes as $node) {
            if ($node->type === 'text') {
                $order[spl_object_id($node)] = count($order);
            }
            foreach ($node->slots as $slot) {
                $order = self::textOrder($slot, $order);
            }
        }

        return $order;
    }

    /**
     * The trial log a retest of the round reads, or null where the round asks for a change, loses nothing, or finds
     * the log empty or closed (none, retested or past its cap). The pass notes which of them it read (`PassMemo`).
     *
     * @return non-empty-list<\MarkupCarve\Carve\Writer\Check\Escape>|null
     */
    private static function retestLog(WriterState $w, Check $check): ?array
    {
        $log = $w->trialLog;
        if ($check->changes() > 0 || $check->losses === []) {
            return null;
        }
        if ($log === null || $w->trialsRetested || count($log) > self::MAX_RETESTED_TRIALS) {
            PassMemo::noteLogClosed($w);

            return null;
        }
        PassMemo::noteRead($w, PassMemo::READ_TRIAL_LOG);

        return $log === [] ? null : $log;
    }

    /**
     * C2-W-125: where the pass loses a node, the trials of its log are tested again together with the other escapes
     * the pass asked for, fewest first, and the first set that gives the tree is taken.
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     * @param \MarkupCarve\Carve\Writer\Check\Check $check
     * @param bool $first
     */
    private static function retestTrials(WriterState $w, array $group, bool $first, Check $check): void
    {
        $log = self::retestLog($w, $check);
        if ($log === null) {
            return;
        }
        $w->trialsRetested = true;
        $saved = self::save($w);
        $base = [];
        foreach ($w->escapes as $id => $offsets) {
            $base[$id] = array_values(array_filter($offsets, static function (int $at) use ($log, $id): bool {
                foreach ($log as $entry) {
                    if (spl_object_id($entry->node) === $id && $entry->at === $at) {
                        return false;
                    }
                }

                return true;
            }));
        }
        $found = -1;
        foreach (self::subsetOrder(count($log)) as $subset) {
            $escapes = $base;
            foreach ($log as $bit => $entry) {
                if (($subset & (1 << $bit)) !== 0) {
                    self::insert($escapes, $entry);
                }
            }
            if (self::groupEqual($w, $group, self::groupTree($w, $group, $first, $escapes)->children())) {
                $found = $subset;

                break;
            }
        }
        self::restore($w, $saved);
        if ($found < 0) {
            return;
        }
        foreach ($log as $bit => $escape) {
            $isPlaced = in_array($escape->at, $saved['escapes'][spl_object_id($escape->node)] ?? [], true);
            if (($found & (1 << $bit)) !== 0 && !$isPlaced) {
                $check->escapes[] = $escape;
            } elseif (($found & (1 << $bit)) === 0 && $isPlaced) {
                $check->retracted[] = $escape;
            }
        }
    }

    /**
     * @return list<int>
     */
    private static function subsetOrder(int $length): array
    {
        if (!isset(self::$subsetOrders[$length])) {
            $order = range(0, (1 << $length) - 1);
            usort($order, static fn (int $a, int $b): int => (self::bitCount($a) <=> self::bitCount($b)) ?: $a <=> $b);
            self::$subsetOrders[$length] = $order;
        }

        return self::$subsetOrders[$length];
    }

    private static function bitCount(int $value): int
    {
        $count = 0;
        for ($rest = $value; $rest !== 0; $rest &= $rest - 1) {
            $count++;
        }

        return $count;
    }

    /**
     * C2-W-088: backtick trials that the test kept stopped a code span of the parse, which reports the backtick kind.
     */
    private static function keepCodeTrial(Check $check): void
    {
        if ($check->trials === []) {
            return;
        }
        foreach ($check->trials as $trial) {
            if (!Check::has($check->codeTrials, $trial) || !in_array($trial, $check->escapes, true)) {
                return;
            }
        }
        $check->trials = [];
    }

    /**
     * C2-W-088 (CC-1568): every opener of each reported kind; a backslash of a C2-W-125 closer repair gives no kind.
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param \MarkupCarve\Carve\Writer\Check\Check $check
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     */
    private static function escapeReportedKinds(WriterState $w, Check $check, array $group): void
    {
        // The comparison can reach a missing tree node before it reaches a construct in that node's text: where the
        // parse opened a construct on the trial's text brace, it is a reported opener, not a C2-W-125 trial.
        $located = $check->source === null || $check->trials === [] ? null : Kinds::locateTexts($check, $group, $check->source);
        if ($located !== null) {
            $opened = self::parsedStarts($check->w->parsed);
            $check->trials = array_values(array_filter(
                $check->trials,
                static fn (Escape $trial): bool => !self::trialOpensBracedConstruct($check, $trial, $located, $opened),
            ));
        }
        // The switch changes the pass only where the asked escapes it keeps from giving a kind would add one.
        if (Kinds::escapeKindsBeside($check, $group, $check->closers + $check->readOn, $w->closerKinds, $located)) {
            PassMemo::noteRead($w, PassMemo::READ_CLOSER_KINDS);
        }
        Kinds::escapePartnerClosers($check, $group);
    }

    /**
     * @param \MarkupCarve\Carve\Writer\Check\Check $check
     * @param \MarkupCarve\Carve\Writer\Check\Escape $trial
     * @param array<int, array{int, list<int>}> $located
     * @param array<string, array<int, true>> $opened
     */
    private static function trialOpensBracedConstruct(Check $check, Escape $trial, array $located, array $opened): bool
    {
        $value = $trial->node->value ?? '';
        $kind = strpos(self::BRACED_MARKS, $value[$trial->at + 1] ?? "\0");
        $type = $kind === false ? null : self::BRACED_NODES[$kind];
        $source = $check->source;
        if (($value[$trial->at] ?? '') !== '{' || $type === null || $source === null || $check->w->parsed === null) {
            return false;
        }
        $place = $located[spl_object_id($trial->node)] ?? null;
        if ($place === null) {
            return false;
        }
        [$start, $escapes] = $place;
        $before = 0;
        while (isset($escapes[$before]) && $escapes[$before] < $trial->at) {
            $before++;
        }
        $sourceAt = $start + $trial->at + $before;

        return ($source[$sourceAt] ?? '') === '{' && isset($opened[$type][$sourceAt]);
    }

    /**
     * The start offsets of the nodes of the parse, by type.
     *
     * @return array<string, array<int, true>>
     */
    private static function parsedStarts(?Node $parsed): array
    {
        $starts = [];
        $pending = $parsed?->children() ?? [];
        while (($node = array_pop($pending)) !== null) {
            if ($node->start !== null) {
                $starts[$node->type][$node->start] = true;
            }
            array_push($pending, ...$node->children());
        }

        return $starts;
    }

    /**
     * Takes over what a comparison asked for. False where it asked for no change.
     */
    public static function applyChanges(WriterState $w, Check $check): bool
    {
        // C2-W-091: where a node first takes its other form, the pass starts again in that spelling.
        foreach ($check->forms as $node) {
            if (!isset($w->forms[spl_object_id($node)])) {
                $w->escapes = [];
                $w->escaped = [];
                $w->attempts = [];
                foreach ($check->forms as $form) {
                    self::setForm($w, $form);
                }

                return true;
            }
        }
        foreach ($check->retracted as $escape) {
            self::removeEscape($w, $escape);
        }
        self::addEscapes($w, $check->escapes);
        foreach ($check->forms as $node) {
            self::setForm($w, $node);
        }
        if ($check->changes() > 0) {
            return true;
        }
        foreach ($check->losses as [$node]) {
            self::refuseLeafLines($w, $node, 0);
        }
        foreach ($check->losses as [$node, $field]) {
            $w->lose($node, $field);
        }

        return false;
    }

    /**
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param list<\MarkupCarve\Carve\Writer\Check\Escape> $added
     */
    public static function addEscapes(WriterState $w, array $added): void
    {
        foreach ($added as $escape) {
            $id = spl_object_id($escape->node);
            $w->escapes[$id][] = $escape->at;
            $w->escaped[$id] = $escape->node;
        }
        foreach ($added as $escape) {
            $id = spl_object_id($escape->node);
            sort($w->escapes[$id]);
        }
    }

    private static function removeEscape(WriterState $w, Escape $escape): void
    {
        $id = spl_object_id($escape->node);
        $place = array_search($escape->at, $w->escapes[$id] ?? [], true);
        if ($place !== false) {
            array_splice($w->escapes[$id], $place, 1);
        }
    }

    /**
     * A form a comparison asked for: the node keeps it whatever stands before it.
     */
    private static function setForm(WriterState $w, Node $node): void
    {
        $id = spl_object_id($node);
        unset($w->glued[$id]);
        $w->forms[$id] = ($w->forms[$id] ?? 0) + 1;
        $w->shared->held[] = $node;
    }

    /**
     * C2-W-016: a lost inline node holds a leaf whose later line would open a block; that leaf is refused.
     */
    private static function refuseLeafLines(WriterState $w, Node $node, int $depth): void
    {
        if ($depth > self::MAX_LEAF_DEPTH || $node->type === 'paragraph' || $node->type === 'list_item') {
            return;
        }
        if (in_array($node->type, self::LINE_LEAVES, true)) {
            if (self::hasOpenerLine($node->value ?? '')) {
                $w->lose($node, 'value');
            }

            return;
        }
        foreach ($node->children() as $child) {
            self::refuseLeafLines($w, $child, $depth + 1);
        }
    }

    /**
     * Whether a line of `value` after the first starts as a block opener can: with a mark, or a number before `.` or `)`.
     */
    private static function hasOpenerLine(string $value): bool
    {
        $length = strlen($value);
        for ($at = strpos($value, "\n"); $at !== false; $at = strpos($value, "\n", $at + 1)) {
            $start = $at + 1 + strspn($value, " \t", $at + 1);
            if ($start >= $length || $value[$start] === "\n") {
                continue;
            }
            if (!ctype_alnum($value[$start])) {
                return true;
            }
            $end = $start + strspn($value, '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', $start);
            if (($value[$end] ?? '') === '.' || ($value[$end] ?? '') === ')') {
                return true;
            }
        }

        return false;
    }

    /**
     * C2-W-002: whether the written group reads back as its blocks.
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     * @param bool $first
     */
    private static function readsBack(WriterState $w, array $group, bool $first): bool
    {
        return self::groupEqual($w, $group, Compare::parseChunk($w, self::source($w, $first))->children());
    }

    /**
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $parsed
     */
    private static function groupEqual(WriterState $w, array $group, array $parsed): bool
    {
        if (count($parsed) !== count($group)) {
            return false;
        }
        foreach ($parsed as $at => $node) {
            if (!Compare::writerEqual($w, $group[$at], $node)) {
                return false;
            }
        }

        return true;
    }

    private static function resetLines(WriterState $w): void
    {
        foreach (array_keys($w->glued) as $id) {
            if (($w->forms[$id] ?? 0) === 1) {
                unset($w->forms[$id]);
            }
        }
        $w->glued = [];
        $w->lines = [];
        $w->starts = [];
    }

    /**
     * The state a trial test writes over, to restore after it.
     *
     * @phpstan-return Saved
     */
    private static function save(WriterState $w): array
    {
        return ['escapes' => $w->escapes, 'escaped' => $w->escaped, 'lines' => $w->lines, 'starts' => $w->starts];
    }

    /**
     * @phpstan-param Saved $saved
     */
    private static function restore(WriterState $w, array $saved): void
    {
        $w->escapes = $saved['escapes'];
        $w->escaped = $saved['escaped'];
        $w->lines = $saved['lines'];
        $w->starts = $saved['starts'];
    }

    /**
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param array<int, list<int>> $escapes
     */
    private static function useEscapes(WriterState $w, array $escapes): void
    {
        $w->escapes = $escapes;
    }

    /**
     * Adds `escape` to the ascending offsets of its node in `escapes`.
     *
     * @param array<int, list<int>> $escapes
     * @param \MarkupCarve\Carve\Writer\Check\Escape $escape
     */
    private static function insert(array &$escapes, Escape $escape): void
    {
        $id = spl_object_id($escape->node);
        $list = $escapes[$id] ?? [];
        $list[] = $escape->at;
        sort($list);
        $escapes[$id] = $list;
    }
}
