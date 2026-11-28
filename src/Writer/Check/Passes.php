<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer\Check;

/**
 * The passes of the repair of a group (C2-W-089, C2-W-005, C2-W-088, C2-W-125), a port of carve-js write.ts
 * `repairGroup` to `finishRounds`: the rounds run by kind first, then with the closer kinds, by the reported openers
 * only, with the whole-tree trials, and last by openers again. The pair rounds of C2-W-146 run around them. Not ported
 * yet: the pass memo of write-passes.ts, the chain of write-chain.ts, `earlyLoneNoteBrackets` before the first
 * round, `trimCappedRuns`, `reportUnread`, and the star, caret and note cases of `prunableOpener`.
 */
final class Passes
{
    /**
     * @var int The rounds of one pass at most (carve-js `history.rounds > 8192`).
     */
    private const MAX_ROUNDS = 8192;

    /**
     * @var int The repair keys a pass remembers to see a repeated round (carve-js `history.recent`).
     */
    private const RECENT_KEYS = 16;

    // C2-W-146: the pair rounds of a group at most (carve-js `MAX_PAIR_ROUNDS`).
    /**
     * @var int
     */
    private const MAX_PAIR_ROUNDS = 64;

    /**
     * C2-W-146, C2-W-089 (carve-js `writeOrdinaryGroup` and `repairPairEscapes`): the pair escapes of the group are
     * placed before the first write, and new ones go through the repair again until no pair is left. A pair round that
     * loses a node is taken back.
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param \MarkupCarve\Carve\Writer\Check\Node $document
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     * @param bool $first
     */
    public static function writeGroup(WriterState $w, Node $document, array $group, bool $first): void
    {
        // carve-js `pairReach`: plain paragraphs take their pair escapes before the write (EARLY_PAIRS), any other
        // group once its other escapes are settled (LATE_PAIRS).
        if (self::onlyPlain($group)) {
            Round::addEscapes($w, $w->pairEscapes($group));
        }
        self::repairGroup($w, $document, $group, $first);
        for ($round = 0; $round < self::MAX_PAIR_ROUNDS; $round++) {
            $asked = $w->pairEscapes($group);
            if ($asked === []) {
                return;
            }
            $before = self::save($w);
            Round::addEscapes($w, $asked);
            self::repairGroup($w, $document, $group, $first);
            if (count($w->lossLog) > $before->losses) {
                self::restore($w, $before);

                return;
            }
        }
    }

    /**
     * Whether every block of `group` is a paragraph of text nodes that hold only letters, digits, ` .,;:!?-` and the
     * pair marks `_~=*` (carve-js `readText` with ONLY_PLAIN).
     *
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     */
    private static function onlyPlain(array $group): bool
    {
        foreach ($group as $node) {
            if ($node->type !== 'paragraph' || $node->field('metadata') !== null) {
                return false;
            }
            foreach ($node->children() as $child) {
                if ($child->type !== 'text' || preg_match('/^[\p{L}\p{N} .,;:!?\-_~=*]*$/u', $child->value ?? '') !== 1) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * C2-W-089: the group is parsed again and written again until it reads back as its blocks. `first` says that the
     * group starts the document, where line 1 can open frontmatter (C2-B-161).
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param \MarkupCarve\Carve\Writer\Check\Node $document
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     * @param bool $first
     */
    public static function repairGroup(WriterState $w, Node $document, array $group, bool $first): void
    {
        $reported = count($w->lossLog);
        self::dropLinkFallback($w);
        // A bracket left open in a bracketed node's content would consume that node's closer. These independent
        // escapes are placed before the readback instead of found one nest per parse.
        $forest = [];
        foreach (Forest::simpleSpanForest($group) as $escape) {
            if (!in_array($escape->at, $w->escapes[spl_object_id($escape->node)] ?? [], true)) {
                $forest[] = $escape;
            }
        }
        Round::addEscapes($w, $forest);
        $caret = [];
        foreach (self::caretLineEscapes($group) as $text) {
            if (!in_array(0, $w->escapes[spl_object_id($text)] ?? [], true)) {
                $caret[] = new Escape($text, 0);
            }
        }
        Round::addEscapes($w, $caret);
        $w->kindRounds = true;
        $saved = self::save($w);
        self::repairOnce($w, $document, $group, $first);
        if (self::passSettled($w, $reported)) {
            return;
        }
        self::restore($w, $saved);
        $before = self::save($w);
        // C2-W-005: where that loses a node, the closer repairs give their kind too, the smallest departure that keeps it.
        $w->closerKinds = true;
        self::repairOnce($w, $document, $group, $first);
        $w->closerKinds = false;
        if (self::passSettled($w, $reported)) {
            return;
        }
        // C2-W-088: where the rounds by kind lose a node, the rounds of the reported openers only give the form.
        self::restore($w, $before);
        $unrepaired = self::save($w);
        $w->kindRounds = false;
        self::repairOnce($w, $document, $group, $first);
        $w->kindRounds = true;
        if (self::passSettled($w, $reported)) {
            return;
        }
        // C2-W-125: where every form loses a node, a trial that gives the tree only with a backslash behind it is kept.
        $last = $unrepaired;
        $w->trialLog = [];
        foreach ([true, false] as $kinds) {
            self::restore($w, $last);
            $last = self::save($w);
            $w->wholeTrials = true;
            $w->trialsRetested = false;
            $w->kindRounds = $kinds;
            self::repairOnce($w, $document, $group, $first);
            $w->wholeTrials = false;
            $w->kindRounds = true;
            if (self::passSettled($w, $reported)) {
                $w->trialLog = null;

                return;
            }
        }
        $w->trialLog = null;
        self::restore($w, $last);
        $w->kindRounds = false;
        self::repairOnce($w, $document, $group, $first);
        $w->kindRounds = true;
    }

    /**
     * C2-W-146, C2-W-092: a caret and a space that start a line of paragraph or line block text stay text that
     * `unattached-caption` reports, so the caret takes a backslash (carve-js write-carets.ts).
     *
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     *
     * @return list<\MarkupCarve\Carve\Writer\Check\Node>
     */
    private static function caretLineEscapes(array $group): array
    {
        $found = [];
        $stack = $group;
        while (($node = array_pop($stack)) !== null) {
            // A term's first line starts behind its marker; its later lines read like paragraph text (C2-B-069).
            if ($node->type === 'paragraph') {
                self::scanCaretLines($node->children(), true, $found);
            } elseif ($node->type === 'definition_term') {
                self::scanCaretLines($node->children(), false, $found);
            } elseif ($node->type === 'line') {
                self::testCaretLine($node->children(), 0, $found);
            }
            foreach ($node->slots as $children) {
                array_push($stack, ...$children);
            }
        }

        return array_values($found);
    }

    /**
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $nodes
     * @param bool $lineStart
     * @param array<int, \MarkupCarve\Carve\Writer\Check\Node> $found
     */
    private static function scanCaretLines(array $nodes, bool $lineStart, array &$found): void
    {
        $start = $lineStart;
        foreach ($nodes as $at => $node) {
            if ($node->type === 'soft_break' || $node->type === 'hard_break') {
                $start = true;

                continue;
            }
            if ($node->type === 'text') {
                if ($start) {
                    self::testCaretLine($nodes, $at, $found);
                }
                if (($node->value ?? '') !== '') {
                    $start = false;
                }

                continue;
            }
            // Any other node writes a mark or a body first; a break inside it starts a line of the same paragraph.
            if (isset($node->slots['children'])) {
                self::scanCaretLines($node->children(), false, $found);
            }
            $start = false;
        }
    }

    /**
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $nodes
     * @param int $at
     * @param array<int, \MarkupCarve\Carve\Writer\Check\Node> $found
     */
    private static function testCaretLine(array $nodes, int $at, array &$found): void
    {
        $node = $nodes[$at] ?? null;
        if ($node === null || $node->type !== 'text') {
            return;
        }
        $value = $node->value ?? '';
        $line = preg_match('/^\^ [ \t]*[^ \t]/', $value) === 1;
        // The rule reads a line without its line comment, so a comment alone behind the caret is no content.
        $next = $nodes[$at + 1] ?? null;
        $behind = $next !== null && !$next->isBreak() && !($next->type === 'comment' && $next->field('form') === 'line');
        if ($line || (preg_match('/^\^ [ \t]*$/', $value) === 1 && $behind)) {
            $found[spl_object_id($node)] = $node;
        }
    }

    /**
     * The rounds of one pass (carve-js `repairRounds` and `nextRound`).
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param \MarkupCarve\Carve\Writer\Check\Node $document
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     * @param bool $first
     */
    private static function repairOnce(WriterState $w, Node $document, array $group, bool $first): void
    {
        $w->shared->cappedRuns = [];
        $w->shared->contentKeys = [];
        $w->linkFallback = false;
        $w->sawLink = false;
        $seen = [];
        $recent = [];
        $nodes = self::groupNodes($group);
        for ($round = 0;; $round++) {
            self::resetLines($w);
            $w->writeGroup($group);
            if ($w->lines === []) {
                return;
            }
            $key = self::repairKey($w, $nodes);
            if ($round >= self::MAX_ROUNDS || isset($seen[$key]) || $w->exhausted()) {
                // carve-js `refuseRepair`: the rounds repeat or run out, so the group's first block has no spelling.
                $w->lose($group[0], null);

                return;
            }
            $seen[$key] = true;
            $recent[] = $key;
            if (count($recent) > self::RECENT_KEYS) {
                unset($seen[array_shift($recent)]);
            }
            $lead = $first && !$w->blankFirst;
            $settled = Round::settle($w, $document, $group, $lead, $round === 0);
            // A limit that the round ran out is caught at the start of the next round, which loses the group.
            if ($settled || $w->shared->chars > $w->shared->limit) {
                continue;
            }
            self::finishRounds($w, $group, $first && !$w->blankFirst);

            return;
        }
    }

    /**
     * The group reads back or asks for no more change: the escapes the rounds placed are cut to those it needs.
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     * @param bool $first
     */
    private static function finishRounds(WriterState $w, array $group, bool $first): void
    {
        self::pruneGroupEscapes($w, $group, $first);
    }

    /**
     * C2-W-003: each prunable backslash of the written group that the group reads back without is taken out of the
     * lines, left to right (carve-js `pruneGroupEscapes`, without the batch rounds of `pruneBatch`).
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     * @param bool $first
     */
    private static function pruneGroupEscapes(WriterState $w, array $group, bool $first): void
    {
        $source = Round::source($w, $first);
        for ($at = strpos($source, '\\'); $at !== false; $at = strpos($source, '\\', $at + 1)) {
            if ($w->exhausted()) {
                break;
            }
            $before = $source[$at - 1] ?? '';
            // C2-W-125, case two: a backslash directly behind a quote mark is the escape of the neighbor that decides its direction.
            $quoted = $before === '"' || $before === "'";
            if ($quoted || self::reportedBraceEscape($w, $source, $at) || !self::prunableOpener($w, $source, $at)) {
                continue;
            }
            $candidate = substr($source, 0, $at) . substr($source, $at + 1);
            $parsed = Compare::parseChunk($w, $candidate);
            if (self::matchesGroup($parsed->children(), $group)) {
                $source = $candidate;
                $w->parsed = $parsed;
                $at--;
            }
        }
        $lines = substr($source, $first ? 0 : 1, -1);
        $w->lines = explode("\n", $lines);
    }

    /**
     * A backslash placed by a reported brace kind stays even when removing it would still read back.
     */
    private static function reportedBraceEscape(WriterState $w, string $source, int $at): bool
    {
        if (($source[$at + 1] ?? '') !== '{') {
            return false;
        }
        $kinds = $w->shared->reportedBraceKinds;

        return isset($kinds['{']) || isset($kinds['{' . ($source[$at + 2] ?? '')]);
    }

    /**
     * The backslashes the prune may take out (carve-js `prunableOpener`). Not ported yet, so kept: a `\*` behind a
     * break, a `\^` in link text, a `\[^]` and a `{#` brace beside an escaped hyphen.
     */
    private static function prunableOpener(WriterState $w, string $source, int $at): bool
    {
        $next = $source[$at + 1] ?? '';
        if ($next === '[') {
            return substr($source, $at + 2, 4) === '----' && ($source[$at - 1] ?? '') !== '^';
        }
        if ($next !== '{') {
            return false;
        }
        $mark = $source[$at + 2] ?? '';
        if ($mark === '/' || $mark === '*') {
            return !self::opensBarePair($w, $at);
        }
        if ($mark !== '#') {
            return false;
        }
        $close = strpos($source, '}', $at + 3);
        $dash = strpos($source, '\\-', $at + 3);

        return $close !== false && ($dash === false || $dash > $close);
    }

    /**
     * Whether the parse opens a bare emphasis or strong at the mark behind the `\{` at `at`. Without the backslash `{`
     * and the mark read as one braced opener, so that pair cannot form and the backslash stays (C2-I-057).
     */
    private static function opensBarePair(WriterState $w, int $at): bool
    {
        $pending = $w->parsed?->children() ?? [];
        while (($node = array_pop($pending)) !== null) {
            if (($node->type === 'emphasis' || $node->type === 'strong') && $node->start === $at + 2) {
                return true;
            }
            array_push($pending, ...$node->children());
        }

        return false;
    }

    /**
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $parsed
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     */
    private static function matchesGroup(array $parsed, array $group): bool
    {
        if (count($parsed) !== count($group)) {
            return false;
        }
        foreach ($parsed as $at => $node) {
            if (!Compare::treesEqual($node, $group[$at])) {
                return false;
            }
        }

        return true;
    }

    /**
     * C2-W-075 (carve#6813): a pass settles the group where it reports no loss.
     */
    private static function passSettled(WriterState $w, int $reported): bool
    {
        return count($w->lossLog) === $reported;
    }

    /**
     * C2-W-133: the repair state of the group, including the attempts that can change its next round. A node without
     * escapes, form, attempt or loss adds nothing (carve-js `repairKey`).
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $nodes The nodes of the group (carve-js `groupNodes`).
     */
    private static function repairKey(WriterState $w, array $nodes): string
    {
        $states = '';
        foreach ($nodes as $node) {
            $id = spl_object_id($node);
            $escapes = $node->type === 'text' ? ($w->escapes[$id] ?? null) : null;
            $form = $w->forms[$id] ?? null;
            $attempt = $w->attempts[$id] ?? null;
            $lost = isset($w->lost[$id]);
            if ($escapes === null && $form === null && $attempt === null && !$lost) {
                continue;
            }
            $tried = '';
            foreach ($attempt->escapes ?? [] as $escape) {
                $tried .= spl_object_id($escape->node) . ".{$escape->at},";
            }
            $states .= $id . ':' . ($escapes === null ? '-' : implode(',', $escapes)) . ':' . ($form ?? '-') . ':'
                . ($attempt->step ?? '-') . ":{$tried}:" . ($lost ? 1 : 0) . ';';
        }

        return json_encode([$w->lines, $w->blankFirst, count($w->lossLog)]) . $states;
    }

    /**
     * The nodes of `group`, each once (carve-js `groupNodes`).
     *
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     *
     * @return list<\MarkupCarve\Carve\Writer\Check\Node>
     */
    private static function groupNodes(array $group): array
    {
        $nodes = [];
        $pending = $group;
        while (($node = array_pop($pending)) !== null) {
            $nodes[] = $node;
            foreach ($node->slots as $children) {
                array_push($pending, ...$children);
            }
        }

        return $nodes;
    }

    private static function dropLinkFallback(WriterState $w): void
    {
        foreach ($w->linkPlaced as $id => $placed) {
            if (isset($w->escapes[$id])) {
                $w->escapes[$id] = array_values(array_filter($w->escapes[$id], static fn (int $at): bool => !isset($placed[$at])));
            }
        }
        $w->linkPlaced = [];
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

    private static function save(WriterState $w): GroupSnapshot
    {
        return new GroupSnapshot(
            $w->lines,
            $w->escapes,
            $w->escaped,
            $w->forms,
            $w->glued,
            $w->bracedAnyway,
            self::copyAttempts($w->attempts),
            $w->blankFirst,
            count($w->lossLog),
            $w->parsed,
        );
    }

    private static function restore(WriterState $w, GroupSnapshot $saved): void
    {
        $w->lines = $saved->lines;
        $w->escapes = $saved->escapes;
        $w->escaped = $saved->escaped;
        $w->forms = $saved->forms;
        $w->glued = $saved->glued;
        $w->bracedAnyway = $saved->bracedAnyway;
        $w->attempts = self::copyAttempts($saved->attempts);
        $w->blankFirst = $saved->blankFirst;
        $w->rollBackLosses($saved->losses);
        $w->parsed = $saved->parsed;
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
}
