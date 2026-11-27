<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Block\LineShapes;
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Generated\Tables;
use MarkupCarve\Carve\Inline\Chars;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeEquality;

/**
 * The read-back rounds of C2-W-088, including the ordered C2-W-125 trials.
 */
final class RepairPlanner
{
    /**
     * @var array<string, array<int, true>>
     */
    private array $escapes = [];

    /**
     * @var array<string, int>
     */
    private array $attempts = [];

    /**
     * @var array<string, int>
     */
    private array $textOrder = [];

    /**
     * @var array<string, array<int, true>>
     */
    private array $trialEscapes = [];

    /**
     * C2-W-125 case five: the backslashes of lone link brackets the link fallback placed. The writer places them, not
     * a round, so the trial log does not take them (carve-js `escapeLoneLinkBrackets`).
     *
     * @var array<string, array<int, true>>
     */
    private array $linkPlaced = [];

    /**
     * The escapes `addEscape()` placed since the last `logTrials()`, in the order it placed them.
     *
     * @var array<array{string, int}>
     */
    private array $placedOrder = [];

    private int $read = 0;

    /**
     * @var array<string, array<array{string, int}>>|null The escapes a C2-W-125 retesting pass placed, by leaf block
     *   and in order; null in other passes.
     */
    private ?array $trialLog = null;

    private bool $trialsRetested = false;

    /**
     * The logged escapes a pass retests together at most: the subsets grow as 2^n (carve-js `MAX_RETESTED_TRIALS`).
     *
     * @var int
     */
    private const MAX_RETESTED_TRIALS = 8;

    /**
     * C2-W-088: whether a round escapes every potential opener of a reported kind, or the reported openers only.
     */
    private bool $kindRounds = true;

    /**
     * @var array<string, array<string, true>> The kinds already escaped, by leaf path.
     */
    private array $kindsDone = [];

    /**
     * @var array<string>
     */
    private const INLINE_CONTAINERS = [
        'superscript', 'subscript', 'emphasis', 'strong', 'underline', 'strike', 'highlight', 'insert', 'delete',
        'link', 'image', 'span', 'inline_footnote', 'inline_extension', 'citation', 'abbreviation', 'quote',
    ];

    /**
     * @var array<string>
     */
    private const INLINE_LEAF_TYPES = [
        'paragraph', 'heading', 'definition_term', 'cell_paragraph', 'line', 'citation_definition',
    ];

    /**
     * @var array<string> The nodes whose content a `]` closes.
     */
    private const BRACKETED = ['link', 'span', 'inline_footnote', 'inline_extension'];

    private int $limit;

    /**
     * The fingerprint of the tree every round compares its read-back with.
     */
    private string $treePrint;

    /**
     * The planner a content probe runs for: its character budget is the one charged.
     */
    private ?self $parent = null;

    /**
     * In a content probe, the type of the probed node.
     */
    private ?string $probed = null;

    // The rounds of one content probe, as MAX_PROBE_ROUNDS in carve-js.
    /**
     * @var int
     */
    private const MAX_PROBE_ROUNDS = 32;

    /**
     * @param array<mixed> $tree
     * @param array<mixed>|null $layout
     * @param array<string, mixed> $options
     * @param array<string, array<int, true>> $seed The escapes of the local write, which the rounds go on from.
     * @param string|null $treePrint `TreeEquality::fingerprint()` of `$tree`, when the caller has it.
     */
    public function __construct(
        private array $tree,
        private ?array $layout,
        private array $options = [],
        array $seed = [],
        ?string $treePrint = null,
    ) {
        foreach ($seed as $path => $offsets) {
            $value = self::leafValue($tree, $path);
            foreach ($offsets as $at => $_) {
                // A backslash the local write doubled (C2-W-097) is doubled by the bare write itself.
                if ($value !== null && isset($value[$at]) && $value[$at] !== '\\') {
                    $this->escapes[$path][$at] = true;
                }
            }
        }
        $encoded = json_encode($tree, JSON_INVALID_UTF8_SUBSTITUTE);
        $allowance = $encoded === false ? 0 : strlen($encoded) * 4;
        $this->limit = max(24_000_000, 1000 * $allowance);
        self::textOrder($tree, '', $this->textOrder);
        $this->treePrint = $treePrint ?? TreeEquality::fingerprint($tree);
    }

    /**
     * @param array<string, array<int, true>> $escapes
     * @param array<string, array<int, true>> $more
     *
     * @return array<string, array<int, true>>
     */
    private static function mergedEscapes(array $escapes, array $more): array
    {
        foreach ($more as $path => $offsets) {
            $escapes[$path] = ($escapes[$path] ?? []) + $offsets;
        }

        return $escapes;
    }

    /**
     * C2-I-015, C2-W-125 case six: a text that starts with a backtick right behind a code, literal or math span would
     * lengthen that span's closing run, so the procedure takes that backslash in every case, before its first round
     * (carve-js `escapeGluedBacktick`).
     *
     * @param array<mixed> $node
     * @param string $path
     * @param array<string, array<int, true>> $out
     *
     * @return array<string, array<int, true>>
     */
    public static function gluedTicks(array $node, string $path = '', array $out = []): array
    {
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        $before = null;
        foreach ($children as $at => $child) {
            if (!is_array($child)) {
                continue;
            }
            $value = $child['value'] ?? null;
            if (($child['type'] ?? '') === 'text' && is_string($value) && Inline::gluesTick($value, $before)) {
                $out[$path . '/children/' . $at][0] = true;
            }
            if (($child['type'] ?? '') === 'cell_paragraph') {
                $out = self::cellTickKind($child, $path . '/children/' . $at, $out);
            }
            $out = self::gluedTicks($child, $path . '/children/' . $at, $out);
            $before = $child;
        }

        return $out;
    }

    /**
     * C2-W-088, C2-W-125 case six: a glued backtick lengthens the closing run of its code span. Where the bare text of
     * the cell then pairs its backtick runs otherwise than the tree, the parse reports the backtick kind, so every
     * backtick that starts a run in the text of the cell takes a backslash (carve-js `escapeBacktickKind`).
     *
     * @param array<mixed> $block
     * @param string $path
     * @param array<string, array<int, true>> $out
     *
     * @return array<string, array<int, true>>
     */
    private static function cellTickKind(array $block, string $path, array $out): array
    {
        $bare = ['text' => '', 'fromText' => [], 'leaves' => [], 'spans' => [], 'glued' => false];
        self::flattenTicks($block, $path, $bare);
        if (!$bare['glued'] || array_diff(self::bareSpans($bare), $bare['spans']) === []) {
            return $out;
        }
        foreach ($bare['leaves'] as [$leaf, $value]) {
            for ($at = strpos($value, '`'); $at !== false; $at = strpos($value, '`', $at + 1)) {
                if ($at === 0 || $value[$at - 1] !== '`') {
                    $out[$leaf][$at] = true;
                }
            }
        }

        return $out;
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     * @param array{
     *     text: string,
     *     fromText: array<int, bool>,
     *     leaves: array<int, array{string, string}>,
     *     spans: array<int, string>,
     *     glued: bool
     * } $bare
     */
    private static function flattenTicks(array $node, string $path, array &$bare): void
    {
        $before = null;
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $at => $child) {
            if (!is_array($child)) {
                continue;
            }
            $type = $child['type'] ?? '';
            $value = $child['value'] ?? null;
            if ($type === 'text' && is_string($value)) {
                $bare['glued'] = $bare['glued'] || Inline::gluesTick($value, $before);
                $bare['leaves'][] = [$path . '/children/' . $at, $value];
                $bare['fromText'] += array_fill(strlen($bare['text']), strlen($value), true);
                $bare['text'] .= $value;
            } elseif (in_array($type, ['code', 'literal_inline', 'math', 'raw_inline'], true) && is_string($value)) {
                $spelled = Inline::codeSpan($value);
                $bare['spans'][] = strlen($bare['text']) . ':' . (strlen($bare['text']) + strlen($spelled));
                self::addBare($bare, $type === 'raw_inline' ? $spelled . "\x01" : $spelled);
            } elseif ($type === 'soft_break' || $type === 'hard_break') {
                self::addBare($bare, "\n");
            } else {
                self::addBare($bare, "\x01");
                self::flattenTicks($child, $path . '/children/' . $at, $bare);
                self::addBare($bare, "\x01");
            }
            $before = $child;
        }
    }

    /**
     * @param array{
     *     text: string,
     *     fromText: array<int, bool>,
     *     leaves: array<int, array{string, string}>,
     *     spans: array<int, string>,
     *     glued: bool
     * } $bare
     * @param string $spelled
     */
    private static function addBare(array &$bare, string $spelled): void
    {
        if ($spelled !== '') {
            $bare['fromText'] += array_fill(strlen($bare['text']), strlen($spelled), false);
        }
        $bare['text'] .= $spelled;
    }

    /**
     * The code spans that the bare text forms by its backtick runs (C2-I-015), as `start:end`.
     *
     * @param array{
     *     text: string,
     *     fromText: array<int, bool>,
     *     leaves: array<int, array{string, string}>,
     *     spans: array<int, string>,
     *     glued: bool
     * } $bare
     *
     * @return array<int, string>
     */
    private static function bareSpans(array $bare): array
    {
        $text = $bare['text'];
        $runs = [];
        preg_match_all('/`+/', $text, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$run, $start]) {
            $escaped = $start > 0 && ($bare['fromText'][$start] ?? false) && ($bare['fromText'][$start - 1] ?? false)
                && $text[$start - 1] === '\\';
            $runs[] = [$escaped ? $start + 1 : $start, $start + strlen($run)];
        }
        $next = [];
        $nearest = [];
        for ($at = count($runs) - 1; $at >= 0; $at--) {
            $length = $runs[$at][1] - $runs[$at][0];
            $next[$at] = $nearest[$length] ?? -1;
            $nearest[$length] = $at;
        }
        $spans = [];
        $count = count($runs);
        for ($open = 0; $open < $count; $open++) {
            [$start, $end] = $runs[$open];
            $close = $next[$open];
            if ($start === $end || $close < 0) {
                continue;
            }
            $spans[] = $start . ':' . $runs[$close][1];
            $open = $close;
        }

        return $spans;
    }

    /**
     * The first round's candidate and read-back.
     *
     * @var array{
     *     array{
     *         source: string,
     *         text: array<int, int>,
     *         paths: array<int, string>,
     *         nodes: array<int, array{string, string, int, int}>,
     *         ranges: array<string, array{int, int}>
     *     },
     *     array{
     *         equal: bool,
     *         openers: array<string, array<int, true>>,
     *         moved: array<int, array{string, string, int, int, int}>,
     *         read: array<string, mixed>
     *     }
     * }|null
     */
    private ?array $first = null;

    /**
     * The escapes of this round's candidate and its source, while the round is applied.
     *
     * @var array{array<string, array<int, true>>, string}|null
     */
    private ?array $roundSource = null;

    /**
     * `roundSource` and the round's read-back, while the trials of the round are read.
     *
     * @var array{array<string, array<int, true>>, string, array<string, mixed>}|null
     */
    private ?array $roundRead = null;

    /**
     * `roundSource` and the fingerprint of its read-back, while a lone trial of the round is read one source at a time.
     *
     * @var array{array<string, array<int, true>>, string, string}|null
     */
    private ?array $roundPrint = null;

    /**
     * `readPrints()` of the read-back the next round starts from, when its comparison has them.
     *
     * @var array<string, string>|null
     */
    private ?array $readParts = null;

    /**
     * `readParts` of the round's read-back, while `roundRead` holds it.
     *
     * @var array<string, string>|null
     */
    private ?array $roundParts = null;

    /**
     * A source a trial read and its read-back, which the next round takes when it writes the same source.
     *
     * @var array{string, array<string, mixed>}|null
     */
    private ?array $nextRead = null;

    /**
     * Whether the last read-back of `compared()` is one paragraph, which a content probe's first round needs.
     */
    private bool $singleParagraph = false;

    /**
     * The backslashes the comparison of the last round asked for, before its kind escapes.
     *
     * @var array<string, array<int, true>>
     */
    private array $compareAsks = [];

    /**
     * @var array<string, true>|null `linkTexts()`.
     */
    private ?array $linkTexts = null;

    /**
     * The escapes of the source `repair()` settled on.
     *
     * @var array<string, array<int, true>>|null
     */
    private ?array $settledEscapes = null;

    /**
     * C2-W-088: rounds that escape only the reported openers, which write a group again where its kind rounds end with
     * a node of the tree that did not read back. They go on from the characters those rounds read (C2-W-133).
     */
    public function reportedOpenersAfter(?self $kindRounds): self
    {
        $this->kindRounds = false;
        $this->read = $kindRounds->read ?? 0;

        return $this;
    }

    /**
     * A pass without a trial log that this one repeats until its rounds stop (`repair()`).
     */
    private ?self $twin = null;

    /**
     * `startState()` after the first comparison, with the characters read until then.
     *
     * @var array{array<mixed>, int}|null
     */
    private ?array $startAt = null;

    /**
     * Where the rounds of a pass without a trial log stopped (`keepStop()`).
     *
     * @var array{
     *     planner: self,
     *     locals: array{array<string, true>, string|null, int, array{string, int, int}|null, array<string, array<int, true>>, int},
     *     consumed: int,
     *     state: array<mixed>,
     *     read: int,
     *     retests: bool
     * }|null
     */
    private ?array $stop = null;

    /**
     * Whether the stop this pass took over is one where a pass with a log retests.
     */
    private bool $stopRetests = false;

    /**
     * The escapes `logTrials()` would have logged, in order, while there is no log.
     *
     * @var array<array{string, int}>
     */
    private array $logItems = [];

    /**
     * C2-W-125: the rounds of a retesting pass are those of the same pass without a log up to where it stopped, so a
     * pass that starts in the same state goes on from there, with the log those rounds would have written.
     */
    public function twinOf(?self $twin): self
    {
        $this->twin = $twin;

        return $this;
    }

    /**
     * Everything the rounds from the first comparison on read: the planner without its budget, its log, the caches
     * and what a comparison derives from its read-back, and the round's source and the escapes its candidate was
     * written with, which give the comparison. Null where those escapes are not known.
     *
     * @param string $source
     * @param array<string, array<int, true>>|null $escapes
     *
     * @return array<mixed>|null
     */
    private function startState(string $source, ?array $escapes): ?array
    {
        if ($escapes === null) {
            return null;
        }
        $state = get_object_vars($this);
        unset(
            $state['read'],
            $state['trialLog'],
            $state['trialsRetested'],
            $state['twin'],
            $state['startAt'],
            $state['stop'],
            $state['logItems'],
            $state['first'],
            $state['firstEscapes'],
            $state['nextRead'],
            $state['stopRetests'],
            $state['placedOrder'],
            $state['trialled'],
            $state['singleParagraph'],
            $state['readParts'],
        );

        return [$state, $source, $escapes];
    }

    /**
     * In a pass without a trial log, keeps where its rounds stopped: for want of a repair, where a pass with a log
     * retests, or on a repeated state, where it returns.
     *
     * @param array{array<string, true>, string|null, int, array{string, int, int}|null, array<string, array<int, true>>, int} $locals
     * @param bool $retests
     */
    private function keepStop(array $locals, bool $retests): void
    {
        if ($this->trialLog !== null || $this->startAt === null) {
            return;
        }
        [$state, $read] = $this->startAt;
        $planner = clone $this;
        $planner->startAt = null;
        $this->stop = [
            'planner' => $planner,
            'locals' => $locals,
            'consumed' => $this->read - $read,
            'state' => $state,
            'read' => $read,
            'retests' => $retests,
        ];
    }

    /**
     * Before the first comparison of a pass with a trial log: where its twin stopped from the same state, inside this
     * pass's budget, this pass takes over that stop and returns the loop state there. Otherwise it keeps its own state
     * for a later twin.
     *
     * @param string $source
     * @param array<string, array<int, true>>|null $escapes
     *
     * @return array{array<string, true>, string|null, int, array{string, int, int}|null, array<string, array<int, true>>, int}|null
     */
    private function resumedStop(string $source, ?array $escapes): ?array
    {
        if ($this->parent !== null) {
            return null;
        }
        $state = $this->startState($source, $escapes);
        $stop = $this->twin?->stop;
        $this->twin = null;
        $this->startAt = $state === null ? null : [$state, $this->read];
        if (
            $stop === null || $state === null || $this->trialLog === null || $this->read < $stop['read']
            || $this->read + $stop['consumed'] > $this->limit || $stop['state'] !== $state
        ) {
            return null;
        }
        $this->startAt = null;
        $this->adopt($stop['planner'], $stop['consumed']);
        $this->stopRetests = $stop['retests'];

        return $stop['locals'];
    }

    /**
     * Whether a pass that took over its twin's stop retests there, as the twin's rounds would have with a log.
     *
     * @param array<string, array<int, true>> $logged
     */
    private function retestsAtStop(array $logged): bool
    {
        if (!$this->stopRetests) {
            return false;
        }
        $this->logTrials($logged);

        return $this->retestedTrials();
    }

    /**
     * Takes over the state of the twin where it stopped, and replays the log its rounds would have written onto this
     * pass's log.
     */
    private function adopt(self $planner, int $consumed): void
    {
        $own = [
            'read', 'trialLog', 'trialsRetested', 'twin', 'startAt', 'stop', 'logItems', 'limit', 'parent', 'probed',
            'firstEscapes', 'stopRetests',
        ];
        foreach (get_object_vars($planner) as $name => $value) {
            if (!in_array($name, $own, true)) {
                $this->$name = $value;
            }
        }
        $this->read += $consumed;
        foreach ($planner->logItems as [$path, $at]) {
            $this->logTrial($path, $at);
        }
    }

    /**
     * C2-W-125: the pass after every other form lost a node logs the escapes it places and, where it loses the node
     * too, retests them (carve-js `wholeTrials`). The log of an earlier such pass carries over, as do its characters.
     */
    public function retestingAfter(?self $previous): self
    {
        $this->trialLog = $previous->trialLog ?? [];
        $this->read = $previous->read ?? $this->read;

        return $this;
    }

    /**
     * The characters `$other` read are charged to this planner too, so the passes after it share one budget.
     */
    public function chargedAfter(self $other): self
    {
        $this->read = max($this->read, $other->read);

        return $this;
    }

    /**
     * Adds the escapes the round placed beyond `$before` to the trial log of their leaf block, each once, up to its cap.
     *
     * @param array<string, array<int, true>> $before
     */
    private function logTrials(array $before): void
    {
        $placed = $this->placedOrder;
        $this->placedOrder = [];
        foreach ($placed as [$path, $at]) {
            if (!isset($this->escapes[$path][$at])) {
                continue;
            }
            $this->logOne($before, $path, $at);
            $before[$path][$at] = true;
        }
        foreach ($this->escapes as $path => $offsets) {
            foreach ($offsets as $at => $_) {
                $this->logOne($before, $path, $at);
            }
        }
    }

    /**
     * @param array<string, array<int, true>> $before
     * @param string $path
     * @param int $at
     */
    private function logOne(array $before, string $path, int $at): void
    {
        if (isset($before[$path][$at]) || isset($this->linkPlaced[$path][$at])) {
            return;
        }
        if ($this->trialLog === null) {
            $this->logItems[] = [$path, $at];

            return;
        }
        $this->logTrial($path, $at);
    }

    /**
     * @param string $path
     * @param int $at
     */
    private function logTrial(string $path, int $at): void
    {
        $leaf = $this->leafOf($path);
        $log = $this->trialLog[$leaf] ?? [];
        // Past the cap the leaf is not retested, so its log stops growing.
        if (count($log) <= self::MAX_RETESTED_TRIALS && !in_array([$path, $at], $log, true)) {
            $this->trialLog[$leaf][] = [$path, $at];
        }
    }

    /**
     * C2-W-125: each escape was tested with only the escapes placed before it, so one a later escape makes needless
     * or harmful stays. Where the pass loses a node, the logged escapes of each leaf block that does not read back are
     * tested again together, fewest first, and the first set that gives that block is taken (carve-js `retestTrials`,
     * which runs per block group as its rounds do). Each trial reads the top-level block of the leaf alone, so the
     * retest stays linear in the document; the next round reads the whole again.
     */
    private function retestedTrials(): bool
    {
        if ($this->trialLog === null || $this->trialLog === [] || $this->trialsRetested) {
            return false;
        }
        $this->trialsRetested = true;
        $index = Writer::layoutIndex($this->layout);
        $byChild = [];
        foreach ($this->trialLog as $leaf => $log) {
            if (preg_match('~^/children/(\d+)~', $leaf, $m) === 1 && count($log) <= self::MAX_RETESTED_TRIALS) {
                $byChild[(int)$m[1]][$leaf] = $log;
            }
        }
        $changed = false;
        $ofChild = $this->escapesByChild();
        foreach ($byChild as $child => $leaves) {
            [$tree, $layout] = Writer::isolatedBlock($this->tree, $this->layout, $index, $child);
            $prefix = '/children/' . $child;
            $local = static fn (string $path): string => '/children/0' . substr($path, strlen($prefix));
            $escapes = [];
            foreach ($ofChild[$child] ?? [] as $path => $offsets) {
                $escapes[$local($path)] = $offsets;
            }
            $wanted = self::readPrints($tree)[1];
            foreach ($leaves as $leaf => $log) {
                $want = $wanted[$local($leaf)] ?? null;
                if ($want === null) {
                    continue;
                }
                $log = array_map(static fn (array $entry): array => [$local($entry[0]), $entry[1]], $log);
                $found = $this->rememberedRetest($tree, $layout, $escapes, $log, $local($leaf), $want);
                if ($found === null) {
                    break 2;
                }
                if ($found !== $escapes) {
                    $escapes = $found;
                    $changed = true;
                }
            }
            foreach (array_keys($ofChild[$child] ?? []) as $path) {
                unset($this->escapes[$path]);
            }
            foreach ($escapes as $path => $offsets) {
                $this->escapes[$prefix . substr($path, strlen('/children/0'))] = $offsets;
            }
        }

        return $changed;
    }

    /**
     * `retestedLeaf()`, run once per isolated block, escapes, log and write state: blocks of the same content in one
     * write take the same retest.
     *
     * @param array<string, mixed> $tree
     * @param array<mixed>|null $layout
     * @param array<string, array<int, true>> $escapes
     * @param array<array{string, int}> $log
     * @param string $leaf
     * @param string $want
     *
     * @return array<string, array<int, true>>|null
     */
    private function rememberedRetest(array $tree, ?array $layout, array $escapes, array $log, string $leaf, string $want): ?array
    {
        $flag = Writer::repairFlag();
        if ($flag === null) {
            return $this->retestedLeaf($tree, $layout, $escapes, $log, $leaf, $want);
        }
        $root = $this->root();
        $read = $root->read;
        $inputs = ['retest', $tree, self::retestLayoutKey($tree, $layout), $escapes, $log, $leaf, $want, $this->options, $flag];
        $retest = function () use ($tree, $layout, $escapes, $log, $leaf, $want, $root, $read): array {
            $found = $this->retestedLeaf($tree, $layout, $escapes, $log, $leaf, $want);

            return [[$found, Writer::repairFlag() ?? false], $root->read - $read];
        };
        [[$found, $after], $charged] = WriteMemo::probe($inputs, $root->limit - $read, $retest);
        $root->read = $read + $charged;
        Writer::restoreRepairFlag($after);

        return $found;
    }

    /**
     * The layout of an isolated block as its writes read it: they read the sidecar source only for a control comment
     * (`ControlLines::keepAbove()`) and the byte offsets only in the authored-block fallback, so the key leaves out the
     * source and holds the offsets relative to the block.
     *
     * @param array<string, mixed> $tree
     * @param array<mixed>|null $layout
     *
     * @return array<mixed>|null
     */
    private static function retestLayoutKey(array $tree, ?array $layout): ?array
    {
        $nodes = $layout['nodes'] ?? null;
        if (!is_array($nodes) || str_contains(serialize($tree), 'carve-lint-disable-next-line')) {
            return $layout;
        }
        $base = null;
        foreach ($nodes as $entry) {
            if (is_array($entry) && is_int($entry['startByte'] ?? null)) {
                $base = min($base ?? $entry['startByte'], $entry['startByte']);
            }
        }
        foreach ($nodes as $i => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            foreach (['startByte', 'endByte'] as $field) {
                if (is_int($entry[$field] ?? null)) {
                    $entry[$field] -= (int)$base;
                }
            }
            $nodes[$i] = $entry;
        }
        unset($layout['source']);
        $layout['nodes'] = $nodes;

        return $layout;
    }

    /**
     * The escapes by the top-level child they stand in, in their order.
     *
     * @return array<int, array<string, array<int, true>>>
     */
    private function escapesByChild(): array
    {
        $ofChild = [];
        foreach ($this->escapes as $path => $offsets) {
            if (preg_match('~^/children/(\d+)(?:/|$)~', (string)$path, $m) === 1) {
                $ofChild[(int)$m[1]][$path] = $offsets;
            }
        }

        return $ofChild;
    }

    /**
     * The escapes of an isolated block with the first subset of a leaf's logged escapes, fewest first, with which the
     * leaf reads back in place of the logged ones; unchanged where none does, null where the budget ran out.
     *
     * @param array<string, mixed> $tree
     * @param array<mixed>|null $layout
     * @param array<string, array<int, true>> $escapes
     * @param array<array{string, int}> $log
     * @param string $leaf
     * @param string $want The fingerprint of the leaf in the tree.
     *
     * @return array<string, array<int, true>>|null
     */
    private function retestedLeaf(array $tree, ?array $layout, array $escapes, array $log, string $leaf, string $want): ?array
    {
        $current = Writer::bareSource($tree, $layout, $this->options, $escapes);
        if (!$this->charge($current)) {
            return null;
        }
        if ((self::readPrints(ReadbackRound::read($current, $this->options))[1][$leaf] ?? null) === $want) {
            return $escapes;
        }
        $base = $escapes;
        foreach ($log as [$path, $at]) {
            unset($base[$path][$at]);
            if (($base[$path] ?? null) === []) {
                unset($base[$path]);
            }
        }
        $subsets = range(0, (1 << count($log)) - 1);
        usort($subsets, static fn (int $x, int $y): int => [self::bitCount($x), $x] <=> [self::bitCount($y), $y]);
        foreach ($subsets as $subset) {
            $trial = $base;
            foreach ($log as $bit => [$path, $at]) {
                if (($subset & (1 << $bit)) !== 0) {
                    $trial[$path][$at] = true;
                }
            }
            $source = Writer::bareSource($tree, $layout, $this->options, $trial);
            if (!$this->charge($source)) {
                return null;
            }
            if ((self::readPrints(ReadbackRound::read($source, $this->options))[1][$leaf] ?? null) === $want) {
                return $trial;
            }
        }

        return $escapes;
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
     * The candidate of the starting escapes and its `ReadbackRound::inspect()`, when the caller already has them.
     *
     * @param array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>,
     *     ranges: array<string, array{int, int}>
     * } $candidate
     * @param array{
     *     equal: bool,
     *     openers: array<string, array<int, true>>,
     *     moved: array<int, array{string, string, int, int, int}>,
     *     read: array<string, mixed>
     * } $comparison
     * @param array<string, string>|null $parts `readPrints()` of the read-back.
     * @param array<string, array<int, true>>|null $escapes The escapes the candidate was written with.
     */
    public function startFrom(array $candidate, array $comparison, ?array $parts = null, ?array $escapes = null): void
    {
        $this->first = [$candidate, $comparison];
        $this->readParts = $parts;
        $this->firstEscapes = $escapes;
    }

    /**
     * The escapes the candidate of `startFrom()` was written with, where the caller names them.
     *
     * @var array<string, array<int, true>>|null
     */
    private ?array $firstEscapes = null;

    /**
     * The local escapes with every potential opener of a reported kind in its leaf.
     *
     * @param array<mixed> $tree
     * @param array<string, array<int, true>> $reported
     * @param array<string, array<int, true>> $localEscapes
     *
     * @return array<string, array<int, true>>
     */
    // phpcs:disable Generic.Metrics.CyclomaticComplexity.MaxExceeded

    public static function expandedKindEscapes(array $tree, array $reported, array $localEscapes): array
    {
        if ($reported === []) {
            return $localEscapes;
        }
        $expanded = $localEscapes;
        $order = [];
        $leafPaths = [];
        self::textLeaves($tree, '', '', $order, $leafPaths);
        $leaves = [];
        foreach ($leafPaths as $text => $leaf) {
            $leaves[$leaf][] = $text;
        }
        $done = [];
        $escapedHash = [];
        foreach ($reported as $path => $positions) {
            $value = self::nodeAt($tree, $path)['value'] ?? null;
            if (!is_string($value)) {
                continue;
            }
            $leaf = $leafPaths[$path] ?? self::leafOfTree($tree, $path);
            foreach ($positions as $at => $_) {
                $kind = self::kindAt($value, $at);
                if ($kind === null) {
                    continue;
                }
                if ($kind === '--') {
                    self::addSingleDash($expanded, $path, $at, $value);

                    continue;
                }
                $expanded[$path][$at] = true;
                // A line kind has its own column rule. The inline `#` kind is the one whose further runs can stand
                // inside another mark, and a text `[` outside a bracketed node reports the kind `[` (C2-W-088, C2-W-094).
                if ($kind !== '#' && $kind !== '{#') {
                    if ($kind === '[' && BracketKind::expands($tree, $path, $value, $at)) {
                        self::expandKindInLeaf($tree, $leaves[$leaf] ?? [], $leaf, $kind, $expanded, $done);
                    }

                    continue;
                }
                // C2-W-088: a reported `#` before a space or the line end opens no tag, so it is the line kind of a
                // heading, whose potential openers are the starts of the leaf's lines.
                if ($kind === '#' && preg_match('/\G#+(?:[ \t]|$)/m', $value, $m, 0, $at) === 1) {
                    self::expandHeadingLines($tree, $leaves[$leaf] ?? [], $expanded);

                    continue;
                }
                $hashRound = $kind === '{#'
                    && ($escapedHash[$leaf] ??= self::hasEscapedHash($tree, $leaves[$leaf] ?? [], $localEscapes));
                foreach ($hashRound ? ['{#', '#'] : [$kind] as $roundKind) {
                    self::expandKindInLeaf($tree, $leaves[$leaf] ?? [], $leaf, $roundKind, $expanded, $done);
                }
            }
        }

        return $expanded;
    }

    // phpcs:enable Generic.Metrics.CyclomaticComplexity.MaxExceeded

    /**
     * @param array<mixed> $tree
     * @param array<string> $texts
     * @param string $leaf
     * @param string $kind
     * @param array<string, array<int, true>> $expanded
     * @param array<string, array<string, true>> $done
     */
    private static function expandKindInLeaf(array $tree, array $texts, string $leaf, string $kind, array &$expanded, array &$done): void
    {
        if (isset($done[$leaf][$kind])) {
            return;
        }
        $done[$leaf][$kind] = true;
        $edges = $kind === '/' || $kind === '*' ? self::textEdges($tree, $leaf) : [];
        foreach ($texts as $path) {
            $value = self::nodeAt($tree, $path)['value'] ?? null;
            if (!is_string($value)) {
                continue;
            }
            foreach (self::kindOffsets($kind, $value, $edges[$path] ?? null) as $at) {
                $expanded[$path][$at] = true;
            }
        }
    }

    /**
     * C2-W-088: the line kind `#`: each line of the leaf that starts with `#`, in a text that starts the leaf or follows
     * a break, or behind a line feed of a text.
     *
     * @param array<mixed> $tree
     * @param array<string> $texts
     * @param array<string, array<int, true>> $expanded
     */
    private static function expandHeadingLines(array $tree, array $texts, array &$expanded): void
    {
        foreach ($texts as $path) {
            $value = self::nodeAt($tree, $path)['value'] ?? null;
            if (!is_string($value)) {
                continue;
            }
            $cut = (int)strrpos($path, '/');
            $index = (int)substr($path, $cut + 1);
            $before = $index === 0 ? null : self::nodeAt($tree, substr($path, 0, $cut + 1) . ($index - 1));
            if (($value[0] ?? '') === '#' && ($before === null || in_array($before['type'] ?? '', ['soft_break', 'hard_break'], true))) {
                $expanded[$path][0] = true;
            }
            for ($at = strpos($value, "\n#"); $at !== false; $at = strpos($value, "\n#", $at + 1)) {
                $expanded[$path][$at + 1] = true;
            }
        }
    }

    /**
     * @param array<mixed> $tree
     * @param array<string> $texts
     * @param array<string, array<int, true>> $escapes
     */
    private static function hasEscapedHash(array $tree, array $texts, array $escapes): bool
    {
        foreach ($texts as $path) {
            $value = self::nodeAt($tree, $path)['value'] ?? null;
            if (!is_string($value)) {
                continue;
            }
            foreach ($escapes[$path] ?? [] as $at => $_) {
                if (($value[$at] ?? '') === '#') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, array<int, true>> $expanded
     * @param string $path
     * @param int $at
     * @param string $value
     */
    private static function addSingleDash(array &$expanded, string $path, int $at, string $value): void
    {
        if ($at === 0 && ($value[1] ?? '') !== '-') {
            $expanded[$path][$at] = true;
        }
    }

    /**
     * Whether every local text escape is on one of the characters being checked.
     *
     * @param array<mixed> $tree
     * @param array<string, array<int, true>> $escapes
     * @param string $characters
     */
    public static function escapesOnly(array $tree, array $escapes, string $characters): bool
    {
        if ($escapes === []) {
            return false;
        }
        foreach ($escapes as $path => $positions) {
            $value = self::nodeAt($tree, $path)['value'] ?? null;
            if (!is_string($value)) {
                return false;
            }
            foreach ($positions as $at => $_) {
                if (!str_contains($characters, $value[$at] ?? '')) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * `TreeEquality::fingerprint()` of a read-back, with the fingerprint of each block in it by path, so the trials of
     * a round compare their leaves without hashing the read-back again.
     *
     * @param array<mixed> $read
     *
     * @return array{string, array<string, string>}
     */
    public static function readPrints(array $read): array
    {
        static $stop = null;
        $stop ??= array_fill_keys(self::INLINE_CONTAINERS, true);

        return TreeEquality::fingerprintParts($read, $stop);
    }

    /**
     * The escapes `repair()` wrote its result with, null where it returned none.
     *
     * @return array<string, array<int, true>>|null
     */
    public function resultEscapes(): ?array
    {
        return $this->settledEscapes;
    }

    /**
     * The escapes of the last round `repair()` wrote, also where it returned none.
     *
     * @return array<string, array<int, true>>
     */
    public function lastEscapes(): array
    {
        return $this->escapes;
    }

    /**
     * C2-W-133: null means the repair could not settle inside the existing character budget.
     */
    // phpcs:disable Generic.Metrics.CyclomaticComplexity.MaxExceeded

    public function repair(): ?string
    {
        $seen = [];
        $settled = null;
        $this->settledEscapes = null;
        $pairRounds = 0;
        $run = null;
        $logged = $this->escapes;
        for ($round = 0; $round < 8192; $round++) {
            $this->logTrials($logged);
            $logged = $this->escapes;
            $escapes = $this->escapes;
            $first = $this->first;
            $this->first = null;
            $source = $first === null ? Writer::bareSource($this->tree, $this->layout, $this->options, $escapes) : $first[0]['source'];
            if (!$this->charge($source)) {
                return $settled;
            }
            $key = hash('xxh3', $source . "\0" . serialize($this->attempts));
            if (isset($seen[$key])) {
                $this->keepStop([$seen, $settled, $pairRounds, $run, $logged, $round], false);

                return $settled;
            }
            $seen[$key] = true;
            // A pass that takes over its twin's stop does not need the comparison of its first round.
            $resumed = $round === 0 ? $this->resumedStop($source, $first === null ? $escapes : $this->firstEscapes) : null;
            if ($resumed !== null) {
                unset($first);
                [$seen, $settled, $pairRounds, $run, $logged, $round] = $resumed;
                if ($this->retestsAtStop($logged)) {
                    continue;
                }

                return $settled;
            }
            $compared = $first ?? $this->compared($source, $escapes, $this->layout);
            unset($first);
            if ($round === 0 && $compared !== null && !$compared[1]['equal'] && $this->probed === null) {
                $fallback = $this->linkFallback($escapes, $compared);
                if ($fallback === true) {
                    continue;
                }
                if ($fallback !== null) {
                    [$source, $escapes] = $fallback;
                    if (!$this->charge($source)) {
                        return $settled;
                    }
                    $seen[hash('xxh3', $source . "\0" . serialize($this->attempts))] = true;
                    $compared = $this->compared($source, $escapes, $this->layout);
                }
            }
            if ($compared === null || $compared[1]['equal']) {
                // C2-W-146: the settled spelling is tested with the pair rules too, and their escapes go through the
                // rounds again; where those do not settle, the spelling before them stands.
                $asked = $pairRounds < 64 ? Text::treePairOpeners($this->tree, $this->escapes) : [];
                if (!$this->addAsked($asked)) {
                    $this->settledEscapes = $escapes;

                    return $source;
                }
                $settled = $source;
                $this->settledEscapes = $escapes;
                $pairRounds++;

                continue;
            }
            [$candidate, $comparison] = $compared;
            unset($compared);
            if (!$this->appliedRound($escapes, $candidate, $comparison)) {
                $this->keepStop([$seen, $settled, $pairRounds, $run, $logged, $round], true);
                $this->logTrials($logged);
                if ($this->retestedTrials()) {
                    unset($candidate, $comparison);

                    continue;
                }

                return $settled;
            }
            $run = $this->followedRun($escapes, $run);
            // The next round's write and parse do not need this round's candidate and read-back.
            unset($candidate, $comparison);
        }

        return $settled;
    }

    // phpcs:enable Generic.Metrics.CyclomaticComplexity.MaxExceeded

    /**
     * C2-I-118: past the depth cap each round frees one entry, so two rounds that each escape the same character at
     * the same offset of successive sibling texts would go on one sibling per round. The run is placed at once and cut
     * to the shortest prefix that reads back, the escapes the rounds would have placed (carve-js `trimCappedRuns`).
     *
     * @param array<string, array<int, true>> $before The escapes of the round.
     * @param array{string, int, int}|null $run The single escape of the round before: parent, index and offset.
     *
     * @return array{string, int, int}|null
     */
    private function followedRun(array $before, ?array $run): ?array
    {
        $added = null;
        foreach ($this->escapes as $path => $offsets) {
            foreach ($offsets as $offset => $_) {
                if (!isset($before[$path][$offset])) {
                    if ($added !== null) {
                        return null;
                    }
                    $added = [$path, $offset];
                }
            }
        }
        if ($added === null) {
            return null;
        }
        $cut = strrpos($added[0], '/');
        $index = substr($added[0], $cut + 1);
        if ($cut === false || !ctype_digit($index)) {
            return null;
        }
        $here = [substr($added[0], 0, $cut), (int)$index, $added[1]];
        if ($run === null || $run[0] !== $here[0] || $run[2] !== $here[2] || $run[1] >= $here[1]) {
            return $here;
        }
        $siblings = self::nodeAt($this->tree, $here[0]);
        $value = self::leafValue($this->tree, $added[0]);
        if ($siblings === null || $value === null || !isset($value[$here[2]])) {
            return $here;
        }
        $char = $value[$here[2]];
        $placed = [];
        foreach ($siblings as $at => $sibling) {
            $path = $here[0] . '/' . $at;
            if (
                is_int($at) && $at > $here[1] && is_array($sibling) && ($sibling['type'] ?? null) === 'text'
                && is_string($sibling['value'] ?? null) && ($sibling['value'][$here[2]] ?? '') === $char
                && !isset($this->escapes[$path][$here[2]])
            ) {
                $placed[] = $path;
            }
        }
        $this->escapeRun($placed, $here[2]);

        return null;
    }

    /**
     * @param array<string> $placed
     * @param int $offset
     */
    private function escapeRun(array $placed, int $offset): void
    {
        $readsBack = function (int $keep) use ($placed, $offset): ?bool {
            $escapes = $this->escapes;
            foreach (array_slice($placed, 0, $keep) as $path) {
                $escapes[$path][$offset] = true;
            }
            $source = Writer::bareSource($this->tree, $this->layout, $this->options, $escapes);

            return $this->charge($source) ? self::readPrints(ReadbackRound::read($source, $this->options))[0] === $this->treePrint : null;
        };
        $low = 0;
        $high = count($placed);
        if ($high === 0 || $readsBack($high) !== true) {
            return;
        }
        // The rounds stop a few escapes short of the end of the run at most, so the search starts there and widens.
        for ($step = 1; $low < $high; $step *= 2) {
            $keep = max($low, $high - $step);
            $read = $readsBack($keep);
            if ($read === null) {
                return;
            }
            if (!$read) {
                $low = $keep + 1;

                break;
            }
            $high = $keep;
        }
        while ($low < $high) {
            $middle = ($low + $high) >> 1;
            $read = $readsBack($middle);
            if ($read === null) {
                return;
            }
            if ($read) {
                $high = $middle;
            } else {
                $low = $middle + 1;
            }
        }
        foreach (array_slice($placed, 0, $high) as $path) {
            $this->addEscape($path, $offset);
        }
    }

    /**
     * C2-W-088, C2-W-125 case five: the first round reads the brackets of link text bare, so a bare closer reports the
     * kinds of what it lets form (carve-js `compareWithLinkFallback`). Where the round reports no construct, it is
     * written again with every lone link bracket escaped: the source and escapes to compare instead. Where it reports
     * one and asks nothing inside a link, the round stands and those brackets take their backslash from the next round
     * on (true); where it asks a repair inside a link, the round is written with the fallback too. Null where the tree
     * has no lone link bracket left bare.
     *
     * @param array<string, array<int, true>> $escapes
     * @param array{
     *     array{
     *         source: string,
     *         text: array<int, int>,
     *         paths: array<int, string>,
     *         nodes: array<int, array{string, string, int, int}>,
     *         ranges: array<string, array{int, int}>
     *     },
     *     array{
     *         equal: bool,
     *         openers: array<string, array<int, true>>,
     *         moved: array<int, array{string, string, int, int, int}>,
     *         read: array<string, mixed>
     *     }
     * } $compared
     *
     * @return array{string, array<string, array<int, true>>}|true|null
     */
    private function linkFallback(array $escapes, array $compared): array|bool|null
    {
        [$positions, $links] = LinkFallback::positions($this->tree);
        $placed = [];
        foreach ($positions as $path => $offsets) {
            $offsets = array_diff_key($offsets, $this->escapes[$path] ?? []);
            if ($offsets !== []) {
                $placed[$path] = $offsets;
            }
        }
        if ($placed === []) {
            return null;
        }
        [$candidate, $comparison] = $compared;
        unset($compared);
        $reports = $comparison['openers'];
        $oneGroup = $this->oneLinkGroup($links);
        if ($reports !== []) {
            if (($oneGroup && $this->gluedCloser($placed)) || $this->breaksLink($candidate, $comparison, $links)) {
                return null;
            }
            $state = get_object_vars($this);
            $this->appliedRound($escapes, $candidate, $comparison);
            if (!$this->linkRepairRequested() && !($oneGroup && $this->bareTextOpener($placed))) {
                $this->addAsked($placed);
                $this->notePlaced($placed);

                return true;
            }
            foreach ($state as $name => $value) {
                $this->{$name} = $value;
            }

            if ($oneGroup) {
                return null;
            }
        }
        $this->addAsked($placed);
        $this->notePlaced($placed);

        return [Writer::bareSource($this->tree, $this->layout, $this->options, $this->escapes), $this->escapes];
    }

    /**
     * @param array<mixed> $links
     */
    private function oneLinkGroup(array $links): bool
    {
        $children = self::childrenOf($this->tree);
        $first = $children[0] ?? null;

        return count($children) === 1 && ((is_array($first) && ($first['type'] ?? null) === 'paragraph') || count($links) === 1);
    }

    /**
     * @param array<string, array<int, true>> $placed
     */
    private function notePlaced(array $placed): void
    {
        foreach ($placed as $path => $offsets) {
            $this->linkPlaced[$path] = ($this->linkPlaced[$path] ?? []) + $offsets;
        }
    }

    /**
     * Whether a leaf block that holds a lone link bracket still holds a bare text `[` outside every link after the
     * round: the link backslashes change which `]` it can pair with, so the rounds decide it one round at a time.
     *
     * @param array<string, array<int, true>> $placed
     */
    private function bareTextOpener(array $placed): bool
    {
        $inLink = $this->linkTexts();
        $leafOf = [];
        foreach ($this->leafTexts() as $leaf => $texts) {
            foreach ($texts as $text) {
                $leafOf[$text] = $leaf;
            }
        }
        $leaves = [];
        foreach ($placed as $path => $_) {
            $leaves[$leafOf[$path] ?? $this->leafOf($path)] = true;
        }
        $texts = $this->leafTexts();
        foreach ($leaves as $leaf => $_) {
            foreach ($texts[$leaf] ?? [] as $text) {
                if (isset($inLink[$text])) {
                    continue;
                }
                $value = self::leafValue($this->tree, $text) ?? '';
                for ($at = strpos($value, '['); $at !== false; $at = strpos($value, '[', $at + 1)) {
                    if (!isset($this->escapes[$text][$at])) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Whether a lone `]` of link text stands right before `[`, `{` or `(`, where read bare it opens another construct.
     *
     * @param array<string, array<int, true>> $placed
     */
    private function gluedCloser(array $placed): bool
    {
        foreach ($placed as $path => $offsets) {
            $value = self::leafValue($this->tree, $path) ?? '';
            foreach ($offsets as $at => $_) {
                if ($value[$at] === ']' && str_contains('[{(', $value[$at + 1] ?? 'x')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether the bare round broke a link with a lone bracket where carve-js, which compares in source order, reports
     * a repair inside it: another node opens at the link's own opener (`[g]{h}](x)` reads a span), or a link before
     * the first reported opener ends at no link of the read-back. A link whose closer the reported construct took
     * still ends there.
     *
     * @param array{nodes: array<int, array{string, string, int, int}>} $candidate
     * @param array{openers: array<string, array<int, true>>, read: array<string, mixed>} $comparison
     * @param array<string, array<string, true>> $links The text paths with a lone bracket, by link path.
     */
    private function breaksLink(array $candidate, array $comparison, array $links): bool
    {
        $first = PHP_INT_MAX;
        foreach ($comparison['openers'] as $path => $_) {
            $first = min($first, $this->textOrder[$path] ?? PHP_INT_MAX);
        }
        [$starts, $ends] = self::bracketedSpans($comparison['read']);
        foreach ($candidate['nodes'] as [$packed, $type, $start, $end]) {
            if ($type !== 'link') {
                continue;
            }
            $texts = $links[RepairTrace::unpackPath($packed)] ?? null;
            if ($texts === null) {
                continue;
            }
            if (isset($starts[$start]) && !isset($starts[$start]['link ' . $end])) {
                return true;
            }
            if (isset($ends[$end])) {
                continue;
            }
            foreach ($texts as $text => $_) {
                if (($this->textOrder[$text] ?? PHP_INT_MAX) < $first) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The bracketed nodes of a read-back by start offset, keyed `type end`, and the end offsets of its links.
     *
     * @param array<string, mixed> $read
     *
     * @return array{array<int, array<string, true>>, array<int, true>}
     */
    private static function bracketedSpans(array $read): array
    {
        $starts = [];
        $ends = [];
        $pending = [$read];
        while ($pending !== []) {
            $node = array_pop($pending);
            $type = $node['type'] ?? null;
            if (is_string($type) && in_array($type, self::BRACKETED, true)) {
                [$start, $end] = TreeEncoder::offsets($node['pos'] ?? null) ?? [-1, -1];
                $starts[$start][$type . ' ' . $end] = true;
                if ($type === 'link') {
                    $ends[$end] = true;
                }
            }
            foreach (self::childrenOf($node) as $child) {
                $pending[] = $child;
            }
        }

        return [$starts, $ends];
    }

    /**
     * Whether the round's comparison asks for a backslash in a link or below one (carve-js `linkRepairRequested`):
     * the kind and partner escapes the round adds after it do not count.
     */
    private function linkRepairRequested(): bool
    {
        $inLink = $this->linkTexts();
        foreach ($this->compareAsks as $path => $_) {
            if (isset($inLink[$path])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The text paths in a link or below one.
     *
     * @return array<string, true>
     */
    private function linkTexts(): array
    {
        if ($this->linkTexts === null) {
            $this->linkTexts = [];
            $pending = [['', $this->tree, false]];
            while ($pending !== []) {
                [$path, $node, $inside] = array_pop($pending);
                $inside = $inside || ($node['type'] ?? null) === 'link';
                if ($inside && ($node['type'] ?? null) === 'text') {
                    $this->linkTexts[$path] = true;
                }
                foreach (self::childrenOf($node) as $index => $child) {
                    $pending[] = [$path . '/children/' . $index, $child, $inside];
                }
            }
        }

        return $this->linkTexts;
    }

    /**
     * The candidate of a bare source and its comparison, or null where the source reads back as the tree: only a round
     * that goes on needs the positions of the marked write.
     *
     * @param string $source
     * @param array<string, array<int, true>> $escapes The escapes the source was written with.
     * @param array<mixed>|null $layout
     *
     * @return array{
     *     array{
     *         source: string,
     *         text: array<int, int>,
     *         paths: array<int, string>,
     *         nodes: array<int, array{string, string, int, int}>,
     *         ranges: array<string, array{int, int}>
     *     },
     *     array{
     *         equal: bool,
     *         openers: array<string, array<int, true>>,
     *         moved: array<int, array{string, string, int, int, int}>,
     *         read: array<string, mixed>
     *     }
     * }|null
     */
    private function compared(string $source, array $escapes, ?array $layout): ?array
    {
        $read = $this->readOf($source);
        $blocks = is_array($read['children'] ?? null) ? $read['children'] : [];
        $this->singleParagraph = count($blocks) === 1 && is_array($blocks[0]) && ($blocks[0]['type'] ?? '') === 'paragraph';
        [$print, $parts] = self::readPrints($read);
        if ($print === $this->treePrint) {
            return null;
        }
        $this->readParts = $parts;
        $candidate = Writer::markedCandidate($this->tree, $layout, $this->options, $escapes, $source);

        return [$candidate, ReadbackRound::compare($candidate, $read, $this->tree, $this->treePrint, false)];
    }

    /**
     * The read-back of a source: the one a trial of the last round read, where it wrote this same source.
     *
     * @return array<string, mixed>
     */
    private function readOf(string $source): array
    {
        $next = $this->nextRead;
        $this->nextRead = null;
        if ($next !== null && $next[0] === $source) {
            return $next[1];
        }
        unset($next);

        return ReadbackRound::read($source, $this->options);
    }

    /**
     * `applyRound()` with the round's read-back at hand for its trials.
     *
     * @param-out array{
     *     equal: bool,
     *     openers: array<string, array<int, true>>,
     *     moved: array<int, array{string, string, int, int, int}>
     * } $comparison
     *
     * @param array<string, array<int, true>> $escapes The escapes the candidate was written with.
     * @param array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>
     * } $candidate
     * @param array{
     *     equal: bool,
     *     openers: array<string, array<int, true>>,
     *     moved: array<int, array{string, string, int, int, int}>,
     *     read: array<string, mixed>
     * } $comparison
     */
    private function appliedRound(array $escapes, array &$candidate, array &$comparison): bool
    {
        $this->roundSource = [$escapes, $candidate['source']];
        try {
            return $this->applyRound($candidate, $comparison);
        } finally {
            $this->roundSource = null;
        }
    }

    /**
     * A comparison that stopped inside an inline list has not reached later siblings of that list. A reported-opener
     * pass takes only the repairs reached by the comparison, as carve-js `compareList` does.
     *
     * @param string $path
     * @param array<array{string, int}> $stops
     */
    private static function behindStop(string $path, array $stops): bool
    {
        foreach ($stops as [$prefix, $stop]) {
            if (!str_starts_with($path, $prefix)) {
                continue;
            }
            $rest = substr($path, strlen($prefix));
            $cut = strpos($rest, '/');
            $index = $cut === false ? $rest : substr($rest, 0, $cut);
            if (ctype_digit($index) && (int)$index > $stop) {
                return true;
            }
        }

        return false;
    }

    /**
     * A stop on a missing node is the comparison's loss boundary. A stop elsewhere can still be crossed by a later
     * respelling of that node, so it must not suppress the reports beyond it.
     *
     * @param array<array{string, string}> $missing
     * @param array<array{string, int}> $stops
     *
     * @return array<array{string, int}>
     */
    private static function stopsOnMissing(array $missing, array $stops): array
    {
        $matched = [];
        foreach ($stops as [$prefix, $index]) {
            foreach ($missing as [$path]) {
                if ($path === $prefix . $index) {
                    $matched[] = [$prefix, $index];

                    break;
                }
            }
        }

        return $matched;
    }

    /**
     * @param array<string, array<int, true>> $openers
     * @param array<array{string, string}> $missing
     * @param array<array{string, int}> $stops
     *
     * @return array<string, array<int, true>>
     */
    private function reachedOpeners(array $openers, array $missing, array $stops): array
    {
        $missingStops = self::stopsOnMissing($missing, $stops);
        if ($this->kindRounds || $this->parent !== null || $missingStops === []) {
            return $openers;
        }

        return array_filter(
            $openers,
            static fn (string $path): bool => !self::behindStop($path, $missingStops),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * The characters the rounds have read so far, the measure of C2-W-133.
     */
    public function charactersRead(): int
    {
        return $this->read;
    }

    /**
     * @param-out array{
     *     equal: bool,
     *     openers: array<string, array<int, true>>,
     *     moved: array<int, array{string, string, int, int, int}>
     * } $comparison
     *
     * @param array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>
     * } $candidate
     * @param array{
     *     equal: bool,
     *     openers: array<string, array<int, true>>,
     *     moved: array<int, array{string, string, int, int, int}>,
     *     read: array<string, mixed>
     * } $comparison
     */
    private function applyRound(array &$candidate, array &$comparison): bool
    {
        $firsts = [];
        $differs = [];
        $keys = [];
        $stops = [];
        $read = $comparison['read'];
        $asked = CompareRound::inspect($this->tree, $read, $this->escapes, $candidate, $this->probed, $firsts, $differs, $keys, $stops);
        $missing = self::missingSiblings($candidate, $read, $this->layout !== null);
        $trials = $this->keyTrials($this->nextTrials($candidate, $read, $missing), $keys);
        // Only a stop at a node that did not form bounds the openers this comparison reached.
        $comparison['openers'] = $this->reachedOpeners($comparison['openers'], $missing, $stops);
        $this->compareAsks = self::mergedEscapes($comparison['openers'], $asked);
        unset($read);
        if ($asked === [] && $trials === [] && $comparison['openers'] === []) {
            $asked = $keys;
        }

        $differences = [];
        // C2-I-028: attribute lines the bare parse attaches or reads apart shift the blocks of the read, so they go
        // before the text differences, which compare by path.
        $attributeLines = $comparison['openers'] === [] && $missing === [] && $asked === []
            && $this->escapeAttributeLinesAbove($this->tree, '');
        if ($comparison['openers'] === [] && $missing === [] && $asked === [] && !$attributeLines) {
            self::textDifferences($this->tree, $comparison['read'], '', $differences);
        }
        // The trials below read the whole document again; only a trial read of the round's own source keeps the
        // read-back.
        $this->roundRead = $this->roundSource === null ? null : [...$this->roundSource, $comparison['read']];
        $this->roundParts = $this->roundRead === null ? null : $this->readParts;
        $this->readParts = null;
        unset($comparison['read']);
        // The probes behind the trials need only their own spans of the candidate, not all of it beside the trial reads.
        $groups = $this->movedByLeaf($comparison['moved']);
        $across = self::acrossSpans($candidate, self::probedMoves($groups));
        $candidate = [
            'source' => $candidate['source'],
            'text' => $across === [] ? [] : $candidate['text'],
            'paths' => $candidate['paths'],
            'nodes' => [],
            'ranges' => [],
        ];
        $early = $this->escapeEarlyNoteClosers($comparison['openers']);
        // C2-W-125, case eight: the trials and asked escapes of this round read the note that closed early, which the
        // closer escape undoes.
        $kept = $early ? [] : $this->keptTrials($trials);
        $asked = $early ? [] : $asked;
        $this->roundRead = null;
        $this->roundParts = null;
        foreach ($kept as $trial) {
            foreach ($trial as [$path, $offset]) {
                $this->addEscape($path, $offset);
                $this->trialEscapes[$path][$offset] = true;
            }
        }
        $this->addAsked($comparison['openers']);
        if ($this->kindRounds && $comparison['openers'] !== []) {
            // The kind escapes go first; a C2-W-125 trial behind a node gives no kind of its own (ruling B), so the
            // asked escapes below are added after them. A differing character the round reports joins the kinds.
            $reported = self::mergedEscapes($comparison['openers'], $early ? [] : $differs);
            $this->addAsked($reported);
            $this->escapeKinds($reported);
            $this->escapePartnerClosers();
            $this->addAsked($this->lengtheningAsks($asked));
        } else {
            // C2-W-088: a braced opener that starts a node that did not form is an opener of its kind, so the round
            // escapes every potential opener of that kind in the leaf block, not one node per round.
            if ($this->kindRounds && !$early) {
                $this->escapeKinds($this->bracedFirsts($firsts));
            }
            $this->addAsked($asked);
        }
        $moved = false;
        foreach ($groups as $group) {
            $moved = $this->probeMoved($candidate, $across, $group) || $moved;
            $moved = $this->ownerMoved($group) || $moved;
        }
        if ($early || $comparison['openers'] !== [] || $missing !== [] || $asked !== []) {
            return $early || $comparison['openers'] !== [] || $trials !== [] || $asked !== [] || $moved;
        }

        $this->addAsked($differences);

        return $attributeLines || $differences !== [] || $moved;
    }

    /**
     * The bare parse can attach a first line that is a whole attribute block and report nothing, which the local write
     * escapes as a line opener (C2-W-092). A round that finds no other repair places that escape here.
     *
     * @param array<mixed> $node
     * @param string $path
     */
    private function escapeAttributeLinesAbove(array $node, string $path): bool
    {
        $children = self::childrenOf($node);
        $first = $children[0] ?? null;
        $value = ($first['type'] ?? '') === 'text' && is_string($first['value'] ?? null) ? rtrim($first['value'], " \t") : '';
        if ($value !== '' && $value[0] === '{' && isset($children[1]) && !isset($this->escapes[$path . '/children/0'][0])) {
            // C2-I-028, C2-W-125 case one: lines that are all attribute blocks above line comments only move the
            // comment, so the first brace alone takes the backslash.
            if (Text::attributeLinesAboveComment($children)) {
                return $this->addEscape($path . '/children/0', 0);
            }
            $break = in_array($children[1]['type'] ?? '', ['soft_break', 'hard_break'], true);
            if ($break && LineShapes::attributeLine($value, 0, strlen($value), new Attrs())) {
                return $this->escapeLineBraces($children, $path);
            }
        }
        $added = false;
        foreach ($children as $i => $child) {
            $added = $this->escapeAttributeLinesAbove($child, $path . '/children/' . $i) || $added;
        }

        return $added;
    }

    /**
     * C2-W-088: the line kind `{` escapes the brace that starts each line of the leaf.
     *
     * @param array<int, array<mixed>> $children
     * @param string $path
     */
    private function escapeLineBraces(array $children, string $path): bool
    {
        $added = false;
        $start = true;
        foreach ($children as $i => $child) {
            $type = $child['type'] ?? '';
            if ($start && $type === 'text' && str_starts_with(is_string($child['value'] ?? null) ? $child['value'] : '', '{')) {
                $added = $this->addEscape($path . '/children/' . $i, 0) || $added;
            }
            $start = $type === 'soft_break' || $type === 'hard_break';
        }

        return $added;
    }

    /**
     * C2-W-125, case eight: a reported inline footnote at a text `^[` whose span keeps a caret pair of the tree ordinary
     * (C2-I-089) stays bare, and the bare `]` that closed it early takes the backslash, which gives no kind. Each later
     * text `^[` before a caret pair takes its early closer in the same round (C2-W-088: the round repairs every note
     * its parse reports, also those behind a node that did not form). The openers it handles leave `$reported`.
     *
     * @param array<string, array<int, true>> $reported
     */
    private function escapeEarlyNoteClosers(array &$reported): bool
    {
        $fired = false;
        $this->notesDone = [];
        // The reported texts of one leaf share their siblings; each list is read and walked once per round.
        $lists = [];
        foreach ($reported as $path => $offsets) {
            $cut = strrpos($path, '/children/');
            if ($cut === false) {
                continue;
            }
            $parent = substr($path, 0, $cut + 10);
            if (!isset($lists[$parent])) {
                $children = self::childrenOf(self::nodeAt($this->tree, substr($path, 0, $cut)) ?? []);
                $lists[$parent] = [$children, self::lastCaretPair($children)];
            }
            [$siblings, $last] = $lists[$parent];
            $i = (int)substr($path, $cut + 10);
            $value = $siblings[$i]['value'] ?? null;
            if (!is_string($value) || $last <= $i) {
                continue;
            }
            foreach ($offsets as $at => $_) {
                if (!$this->isTextNote($siblings, $parent, $i, $value, $at)) {
                    continue;
                }
                if (!isset($this->notesDone[$path][$at])) {
                    $closer = $this->earlyNoteCloser($siblings, $parent, $i, $at + 1);
                    if ($closer === null || !$this->addEscape(...$closer)) {
                        continue;
                    }
                    $this->laterNoteClosers($siblings, $parent, $i, $at + 1, $last);
                    $this->bracketedClosers($siblings, $parent, $i);
                    $fired = true;
                }
                unset($reported[$path][$at]);
            }
            if ($reported[$path] === []) {
                unset($reported[$path]);
            }
        }

        return $fired;
    }

    /**
     * @var array<string, array<int, true>> C2-W-125, case eight: the `[` of each text `^[` whose early closer this round
     *   escaped.
     */
    private array $notesDone = [];

    /**
     * Whether the reported `[` at `$at` follows a bare caret of the text, which may end the text node before.
     *
     * @param array<int, array<mixed>> $siblings
     * @param string $parent
     * @param int $i
     * @param string $value
     * @param int $at
     */
    private function isTextNote(array $siblings, string $parent, int $i, string $value, int $at): bool
    {
        if (($value[$at] ?? '') !== '[' || isset($this->escapes[$parent . $i][$at])) {
            return false;
        }
        if ($at > 0) {
            return $value[$at - 1] === '^' && !isset($this->escapes[$parent . $i][$at - 1]);
        }
        $before = $siblings[$i - 1] ?? null;
        $prior = is_array($before) && ($before['type'] ?? '') === 'text' && is_string($before['value'] ?? null) ? $before['value'] : '';

        return str_ends_with($prior, '^') && !isset($this->escapes[$parent . ($i - 1)][strlen($prior) - 1]);
    }

    /**
     * Each later text `^[` of the siblings that stands before a caret pair takes its early closer.
     *
     * @param array<int, array<mixed>> $siblings
     * @param string $parent
     * @param int $i
     * @param int $from
     * @param int $last The `lastCaretPair()` of the siblings.
     */
    private function laterNoteClosers(array $siblings, string $parent, int $i, int $from, int $last): void
    {
        foreach ($siblings as $k => $text) {
            $value = $text['value'] ?? null;
            if ($k < $i || $k >= $last || ($text['type'] ?? '') !== 'text' || !is_string($value)) {
                continue;
            }
            for ($at = strpos($value, '^[', $k === $i ? $from : 0); $at !== false; $at = strpos($value, '^[', $at + 2)) {
                if (isset($this->escapes[$parent . $k][$at]) || isset($this->escapes[$parent . $k][$at + 1])) {
                    continue;
                }
                $closer = $this->earlyNoteCloser($siblings, $parent, $k, $at + 2);
                if ($closer !== null && $this->addEscape(...$closer)) {
                    $this->notesDone[$parent . $k][$at + 1] = true;
                }
            }
        }
    }

    /**
     * C2-W-125, case five, in the round of case eight: the note that closed early hides the links and spans behind it
     * from the comparison, so a lone `]` of their content that would end one early takes its backslash in this round.
     *
     * @param array<int, array<mixed>> $siblings
     * @param string $parent
     * @param int $i
     */
    private function bracketedClosers(array $siblings, string $parent, int $i): void
    {
        foreach ($siblings as $k => $node) {
            // A caret pair inside an inline footnote opens nothing (C2-I-089), so a note's content stays as it is.
            if ($k <= $i || !in_array($node['type'] ?? '', ['link', 'span', 'inline_extension'], true)) {
                continue;
            }
            $depth = 0;
            foreach (self::childrenOf($node) as $j => $child) {
                $value = ($child['type'] ?? '') === 'text' && is_string($child['value'] ?? null) ? $child['value'] : '';
                $path = $parent . $k . '/children/' . $j;
                $n = strlen($value);
                for ($at = strcspn($value, '[]'); $at < $n; $at += 1 + strcspn($value, '[]', $at + 1)) {
                    if (isset($this->escapes[$path][$at])) {
                        continue;
                    }
                    if ($value[$at] === '[') {
                        $depth++;
                    } elseif ($depth > 0) {
                        $depth--;
                    } else {
                        $this->addEscape($path, $at);
                    }
                }
            }
        }
    }

    /**
     * The bare `]` from sibling `$i` at `$from` on that no bare `[` there pairs with, as a path and an offset. A node of
     * the tree between, as a strong, a code span or a link, holds no bracket of the note.
     *
     * @param array<int, array<mixed>> $siblings
     * @param string $parent
     * @param int $i
     * @param int $from
     *
     * @return array{string, int}|null
     */
    private function earlyNoteCloser(array $siblings, string $parent, int $i, int $from): ?array
    {
        $depth = 0;
        foreach ($siblings as $k => $text) {
            $value = $text['value'] ?? null;
            if ($k < $i || ($text['type'] ?? '') !== 'text' || !is_string($value)) {
                continue;
            }
            $escaped = $this->escapes[$parent . $k] ?? [];
            $n = strlen($value);
            $start = $k === $i ? min($from, $n) : 0;
            for ($at = $start + strcspn($value, '[]', $start); $at < $n; $at += 1 + strcspn($value, '[]', $at + 1)) {
                if (isset($escaped[$at])) {
                    continue;
                }
                if ($value[$at] === '[') {
                    $depth++;
                } elseif ($depth-- === 0) {
                    return [$parent . $k, $at];
                }
            }
        }

        return null;
    }

    /**
     * The last sibling that is, or holds below it, link or span text starting with a caret, the bracket of which forms
     * `[^`; -1 for none. One inside an inline footnote opens nothing there (C2-I-089), so it does not count.
     *
     * @param array<int, array<mixed>> $siblings
     */
    private static function lastCaretPair(array $siblings): int
    {
        for ($k = array_key_last($siblings) ?? -1; $k >= 0; $k--) {
            if (isset($siblings[$k]) && self::holdsCaretPair($siblings[$k])) {
                return $k;
            }
        }

        return -1;
    }

    /**
     * C2-W-125, case eight: whether a text `^[` of the tree stands before a sibling that holds a caret pair, which the
     * span of that `^[` keeps ordinary (C2-I-089). Escaping the bracket of that `^[` would let the caret pair open.
     *
     * @param array<mixed> $node
     */
    public static function hasTextNoteBeforeCaretPair(array $node): bool
    {
        if (self::textNoteBeforeCaretPair($node)) {
            return true;
        }
        foreach (self::childrenOf($node) as $child) {
            if (self::hasTextNoteBeforeCaretPair($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `hasTextNoteBeforeCaretPair()` for the children of `$node` only.
     *
     * @param array<mixed> $node
     */
    private static function textNoteBeforeCaretPair(array $node): bool
    {
        $children = self::childrenOf($node);
        $prior = '';
        foreach ($children as $k => $child) {
            $value = ($child['type'] ?? '') === 'text' && is_string($child['value'] ?? null) ? $child['value'] : null;
            if ($value !== null && (str_contains($value, '^[') || (str_ends_with($prior, '^') && str_starts_with($value, '[')))) {
                // The first such text has the most siblings behind it.
                return $k < self::lastCaretPair($children);
            }
            $prior = $value ?? '';
        }

        return false;
    }

    /**
     * @param array<mixed> $node
     */
    private static function holdsCaretPair(array $node): bool
    {
        $type = $node['type'] ?? '';
        $first = self::childrenOf($node)[0] ?? null;
        if (
            ($type === 'link' || $type === 'span') && is_array($first) && ($first['type'] ?? '') === 'text'
            && str_starts_with(is_string($first['value'] ?? null) ? $first['value'] : '', '^')
        ) {
            return true;
        }
        if ($type === 'inline_footnote') {
            return false;
        }
        foreach (self::childrenOf($node) as $child) {
            if (self::holdsCaretPair($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * C2-W-088: each reported opener's kind escapes every potential opener of that kind in its leaf block.
     *
     * @param array<string, array<int, true>> $reported
     */
    // phpcs:disable Generic.Metrics.CyclomaticComplexity.MaxExceeded

    private function escapeKinds(array $reported): void
    {
        $escapedHash = [];
        foreach ($reported as $path => $offsets) {
            $value = self::nodeAt($this->tree, $path)['value'] ?? null;
            if (!is_string($value)) {
                continue;
            }
            $leaf = $this->leafOf($path);
            foreach ($offsets as $offset => $_) {
                $kind = self::kindAt($value, $offset);
                if ($kind === null) {
                    continue;
                }
                $hashRound = $kind === '{#'
                    && ($escapedHash[$leaf] ??= self::hasEscapedHash($this->tree, $this->leafTexts()[$leaf] ?? [], $this->escapes));
                foreach ($hashRound ? ['{#', '#'] : [$kind] as $roundKind) {
                    if (isset($this->kindsDone[$leaf][$roundKind])) {
                        continue;
                    }
                    $this->kindsDone[$leaf][$roundKind] = true;
                    $edges = $roundKind === '/' || $roundKind === '*' ? self::textEdges($this->tree, $leaf) : [];
                    foreach ($this->leafTexts()[$leaf] ?? [] as $text) {
                        $other = self::nodeAt($this->tree, $text)['value'] ?? null;
                        if (is_string($other)) {
                            foreach (self::kindOffsets($roundKind, $other, $edges[$text] ?? null) as $at) {
                                $this->addEscape($text, $at);
                                if ($other[$at] === '#') {
                                    $escapedHash[$leaf] = true;
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    // phpcs:enable Generic.Metrics.CyclomaticComplexity.MaxExceeded

    /**
     * C2-W-125 case six: only an ask for the first closer mark of text behind its node stays in a kind round.
     *
     * @param array<string, array<int, true>> $asked
     *
     * @return array<string, array<int, true>>
     */
    private function lengtheningAsks(array $asked): array
    {
        $behind = [];
        foreach ($asked as $path => $offsets) {
            if (!isset($offsets[0])) {
                continue;
            }
            $cut = strrpos($path, '/children/');
            if ($cut === false) {
                continue;
            }
            $index = (int)substr($path, $cut + 10);
            if ($index === 0) {
                continue;
            }
            $prior = self::nodeAt($this->tree, substr($path, 0, $cut) . '/children/' . ($index - 1));
            $text = self::nodeAt($this->tree, $path);
            $closer = is_array($prior) ? self::closer($prior) : '';
            $value = is_array($text) && is_string($text['value'] ?? null) ? $text['value'] : '';
            if (
                $closer !== '' && is_array($text) && ($text['type'] ?? '') === 'text'
                && str_starts_with($value, $closer)
            ) {
                $behind[$path][0] = true;
            }
        }

        return $behind;
    }

    /**
     * The asks of `$firsts` at a brace that starts a braced opener.
     *
     * @param array<string, array<int, true>> $firsts
     *
     * @return array<string, array<int, true>>
     */
    private function bracedFirsts(array $firsts): array
    {
        $braced = [];
        foreach ($firsts as $path => $offsets) {
            $value = self::nodeAt($this->tree, $path)['value'] ?? null;
            foreach ($offsets as $offset => $_) {
                if (is_string($value) && strlen((string)self::kindAt($value, $offset)) === 2 && $value[$offset] === '{') {
                    $braced[$path][$offset] = true;
                }
            }
        }

        return $braced;
    }

    /**
     * C2-W-125, case five: a `[` escaped in the content of a bracketed node leaves the `]` that closes it in the text
     * bare, which would end that node early, so that `]` takes its backslash in the same round.
     */
    private function escapePartnerClosers(): void
    {
        $pending = [['', $this->tree]];
        while ($pending !== []) {
            [$path, $node] = array_pop($pending);
            if (!in_array($node['type'] ?? '', self::BRACKETED, true)) {
                foreach (self::childrenOf($node) as $index => $child) {
                    $pending[] = [$path . '/children/' . $index, $child];
                }

                continue;
            }
            $open = [];
            $content = [];
            foreach (array_reverse(self::childrenOf($node), true) as $index => $child) {
                $content[] = [$path . '/children/' . $index, $child];
            }
            while ($content !== []) {
                [$at, $child] = array_pop($content);
                $type = $child['type'] ?? '';
                if (in_array($type, self::BRACKETED, true)) {
                    $pending[] = [$at, $child];
                } elseif ($type === 'text') {
                    $this->pairBrackets($at, is_string($child['value'] ?? null) ? $child['value'] : '', $open);
                } else {
                    foreach (array_reverse(self::childrenOf($child), true) as $index => $inner) {
                        $content[] = [$at . '/children/' . $index, $inner];
                    }
                }
            }
        }
    }

    /**
     * @param string $path
     * @param string $value
     * @param array<array{string, int}> $open The `[` of the content not yet closed.
     */
    private function pairBrackets(string $path, string $value, array &$open): void
    {
        $n = strlen($value);
        for ($i = strcspn($value, '[]'); $i < $n; $i += 1 + strcspn($value, '[]', $i + 1)) {
            if ($value[$i] === '[') {
                $open[] = [$path, $i];

                continue;
            }
            $partner = array_pop($open);
            if ($partner !== null && isset($this->escapes[$partner[0]][$partner[1]])) {
                $this->addEscape($path, $i);
            }
        }
    }

    /**
     * @param array<mixed> $node
     *
     * @return array<int, array<mixed>>
     */
    private static function childrenOf(array $node): array
    {
        return array_filter(is_array($node['children'] ?? null) ? $node['children'] : [], 'is_array');
    }

    /**
     * @var array<string, array<string>>|null The text paths of each leaf block, in source order.
     */
    private ?array $leafTexts = null;

    /**
     * @return array<string, array<string>>
     */
    private function leafTexts(): array
    {
        if ($this->leafTexts === null) {
            $this->leafTexts = [];
            foreach ($this->textOrder as $text => $_order) {
                $this->leafTexts[$this->leafOf($text)][] = $text;
            }
        }

        return $this->leafTexts;
    }

    /**
     * @var array<string, string>|null The leaf block of each text in `$textOrder`, by text path.
     */
    private ?array $textLeaf = null;

    private function leafOf(string $path): string
    {
        if ($this->textLeaf === null) {
            $order = [];
            $this->textLeaf = [];
            self::textLeaves($this->tree, '', '', $order, $this->textLeaf);
        }

        return $this->textLeaf[$path] ?? self::leafOfTree($this->tree, $path);
    }

    /**
     * Find the leaf block of a text path without constructing a repair planner.
     *
     * @param array<mixed> $tree
     * @param string $path
     */
    private static function leafOfTree(array $tree, string $path): string
    {
        // The ancestors are found in one walk down the path: looking each up from the root costs a deep path its
        // depth squared.
        $types = self::typesAlong($tree, $path);
        while (($cut = strrpos($path, '/children/')) !== false) {
            $path = substr($path, 0, $cut);
            $type = array_key_exists($cut, $types) ? $types[$cut] : (self::nodeAt($tree, $path)['type'] ?? null);
            if (!in_array($type, self::INLINE_CONTAINERS, true)) {
                return $path;
            }
        }

        return $path;
    }

    /**
     * The `type` of the node at each prefix of `path` that ends before a `/`, by the prefix length, as `nodeAt()` reads
     * it; empty for a path that does not start with a single `/`.
     *
     * @param array<mixed> $tree
     * @param string $path
     *
     * @return array<int, mixed>
     */
    private static function typesAlong(array $tree, string $path): array
    {
        if (!str_starts_with($path, '/') || str_contains($path, '//')) {
            return [];
        }
        $types = [];
        $node = $tree;
        $length = 0;
        foreach (explode('/', substr($path, 1)) as $part) {
            $length += 1 + strlen($part);
            if ($node === null || !is_array($node[$part] ?? null)) {
                $node = null;
            } else {
                $node = $node[$part];
            }
            $types[$length] = $node === null ? null : ($node['type'] ?? null);
        }

        return $types;
    }

    private static function kindAt(string $value, int $offset): ?string
    {
        $ch = $value[$offset] ?? '';
        if ($ch === '' || !ctype_punct($ch)) {
            return null;
        }
        $next = $value[$offset + 1] ?? '';
        if ($ch === '{' && ($next === '#' || isset(Tables::BRACED_MARKS[$next]))) {
            return '{' . $next;
        }

        return $ch === '-' ? '--' : $ch;
    }

    /**
     * C2-W-088 (carve-js `leavesKindBare`): whether the escapes stand in leaf blocks of text and breaks alone, where no
     * node has an extent the read could move, and leave no potential opener of their kinds bare there. Such a plan
     * reports no kind round.
     *
     * @param array<string, mixed> $tree
     * @param array<string, array<int, true>> $escapes
     */
    public static function settlesKinds(array $tree, array $escapes): bool
    {
        $values = [];
        $leaves = [];
        $mixed = [];
        self::kindTexts($tree, '', '', $values, $leaves, $mixed);
        $kinds = [];
        foreach ($escapes as $path => $offsets) {
            if (!isset($values[$path]) || isset($mixed[$leaves[$path]])) {
                return false;
            }
            foreach ($offsets as $at => $_) {
                $kind = self::kindAt($values[$path], $at);
                if ($kind !== null) {
                    $kinds[$leaves[$path]][$kind] = true;
                }
            }
        }
        $edges = [];
        foreach ($leaves as $path => $leaf) {
            foreach ($kinds[$leaf] ?? [] as $kind => $_) {
                $kind = (string)$kind;
                if ($kind === '/' || $kind === '*') {
                    $edges[$leaf] ??= self::textEdges($tree, $leaf);
                }
                foreach (self::kindOffsets($kind, $values[$path], $edges[$leaf][$path] ?? null) as $at) {
                    if (!isset($escapes[$path][$at])) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * The value and leaf block of each text, by path, and the leaf blocks that hold another node than text and breaks.
     *
     * @param array<mixed> $node
     * @param string $path
     * @param string $leaf
     * @param array<string, string> $values
     * @param array<string, string> $leaves
     * @param array<string, true> $mixed
     */
    private static function kindTexts(array $node, string $path, string $leaf, array &$values, array &$leaves, array &$mixed): void
    {
        $type = $node['type'] ?? null;
        if ($type === 'text') {
            $values[$path] = is_string($node['value'] ?? null) ? $node['value'] : '';
            $leaves[$path] = $leaf;
        } elseif ($leaf !== '' && $type !== 'soft_break' && $type !== 'hard_break') {
            $mixed[$leaf] = true;
        }
        $inner = in_array($type, self::INLINE_CONTAINERS, true) ? $leaf : $path;
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $index => $child) {
            if (is_array($child)) {
                self::kindTexts($child, $path . '/children/' . $index, $inner, $values, $leaves, $mixed);
            }
        }
    }

    /**
     * C2-W-088: the potential openers of a kind in a text: the first character of each run of its characters, the
     * brace of a braced opener, the second hyphen of a hyphen run, and a `/` or `*` its neighbors let open.
     *
     * @param string $kind
     * @param string $value
     * @param array{?string, ?string}|null $edges The characters beside the text in its leaf block (`textEdges()`).
     *
     * @return array<int>
     */
    private static function kindOffsets(string $kind, string $value, ?array $edges = null): array
    {
        $offsets = [];
        $n = strlen($value);
        $ch = $kind[0];
        $first = $ch === '{' ? '{' : ($kind === '--' ? '-' : $ch);
        // A `{#` without any later `#}` cannot open a comment. Find the last possible closer once, so a leaf
        // with many sibling tags still takes one linear scan.
        $commentClose = $kind === '{#' ? strrpos($value, '#}') : false;
        for ($i = strpos($value, $first); $i !== false && $i < $n; $i = strpos($value, $value[$i], $i + 1)) {
            // A negative string offset reads from the end, so the first character has no character before it.
            $prev = $i > 0 ? $value[$i - 1] : '';
            $open = match (true) {
                // Every `{` before the mark is a potential opener, a `{` before it included (carve-js#5550).
                strlen($kind) === 2 && $ch === '{' => ($value[$i + 1] ?? '') === $kind[1]
                    && ($kind !== '{#' || ($prev !== '{' && $commentClose !== false && $commentClose > $i + 2)),
                $kind === '--' => $prev === '-' && ($i < 2 || $value[$i - 2] !== '-') && ($value[$i + 1] ?? '') !== '',
                $ch === '/' || $ch === '*' => self::delimiterOpens($value, $i, $edges),
                default => $prev !== $ch,
            };
            if ($open) {
                $offsets[] = $i;
            }
        }

        return $offsets;
    }

    /**
     * C2-I-047, C2-I-049: whether the `/` or `*` at `$at` can open by the characters written around it; at the edge of
     * the text, by the leaf beside it (carve-js `canOpen`).
     *
     * @param string $value
     * @param int $at
     * @param array{?string, ?string}|null $edges
     */
    private static function delimiterOpens(string $value, int $at, ?array $edges): bool
    {
        $ch = $value[$at];
        $before = $at > 0 ? $value[$at - 1] : ($edges === null ? '' : $edges[0] ?? '');
        $after = $at + 1 < strlen($value) ? $value[$at + 1] : ($edges === null ? ' ' : $edges[1]);
        if ($before === $ch || $after === $ch || $after === null) {
            return false;
        }
        if ($at === 0 && $edges !== null ? Chars::isWord(Chars::at($before, 0)) : ctype_alnum($before)) {
            return false;
        }

        return $at + 1 < strlen($value) || $edges === null
            ? !in_array($after, [' ', "\t", "\n"], true)
            : !Chars::isSpace(Chars::at($after, 0));
    }

    /**
     * The character beside each text of a leaf block, before and after it, as carve-js `edge()` reads the flattened
     * leaves: null at a line edge, `!` beside other markup, `\` before a written hard break (C2-I-047).
     *
     * @param array<mixed> $tree
     * @param string $leaf
     *
     * @return array<string, array{?string, ?string}>
     */
    private static function textEdges(array $tree, string $leaf): array
    {
        $node = self::nodeAt($tree, $leaf) ?? [];
        // C2-W-080: in a line block a hard break is the bare line ending.
        $bare = ($node['type'] ?? null) === 'line_block' || in_array('line_block', self::typesAlong($tree, $leaf), true);
        $items = [];
        self::flattenLeaf(self::childrenOf($node), $leaf, $bare, $items);
        $edges = [];
        foreach ($items as $k => [$kind, $path]) {
            if ($kind === 'text') {
                $edges[$path] = [self::edgeOf($items[$k - 1] ?? null, false), self::edgeOf($items[$k + 1] ?? null, true)];
            }
        }

        return $edges;
    }

    /**
     * The character a flattened leaf shows toward a text beside it (carve-js `edge()`).
     *
     * @param array{string, string, string, bool}|null $item
     * @param bool $after
     */
    private static function edgeOf(?array $item, bool $after): ?string
    {
        [$kind, , $value, $backslash] = $item ?? ['', '', '', false];
        if ($kind === 'markup' || ($kind === 'text' && $value === '')) {
            return '!';
        }
        if ($kind !== 'text') {
            return $kind === 'break' && $after && $backslash ? '\\' : null;
        }

        // One code point, as carve-js `codePointAt` reads it, not the grapheme around it.
        if (preg_match($after ? '/^./su' : '/.$/su', $value, $match) === 1) {
            return $match[0];
        }

        return $after ? $value[0] : substr($value, -1);
    }

    /**
     * The leaves of inline content in source order: each text by path and value, each break, and the markup before
     * and after the content of every other node.
     *
     * @param array<mixed> $nodes
     * @param bool $bare
     * @param string $path
     * @param array<int, array{string, string, string, bool}> $items
     */
    private static function flattenLeaf(array $nodes, string $path, bool $bare, array &$items): void
    {
        foreach ($nodes as $index => $node) {
            if (!is_array($node)) {
                continue;
            }
            $type = $node['type'] ?? null;
            $at = $path . '/children/' . $index;
            if ($type === 'text') {
                $items[] = ['text', $at, is_string($node['value'] ?? null) ? $node['value'] : '', false];
            } elseif ($type === 'soft_break' || $type === 'hard_break') {
                $items[] = ['break', $at, '', $type === 'hard_break' && !$bare];
            } else {
                $items[] = ['markup', $at, '', false];
                self::flattenLeaf(self::childrenOf($node), $at, $bare, $items);
                $items[] = ['markup', $at, '', false];
            }
        }
    }

    private function addEscape(string $path, int $offset): bool
    {
        if (isset($this->escapes[$path][$offset])) {
            return false;
        }
        $this->escapes[$path][$offset] = true;
        $this->placedOrder[] = [$path, $offset];

        return true;
    }

    /**
     * @param array<string, array<int, true>> $asked
     */
    private function addAsked(array $asked): bool
    {
        $added = false;
        foreach ($asked as $path => $offsets) {
            foreach ($offsets as $offset => $_) {
                $added = $this->addEscape($path, $offset) || $added;
            }
        }

        return $added;
    }

    /**
     * @param array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>
     * } $candidate
     * @param array<string, array{array{int, int}, int}> $across `acrossSpans()` of the candidate.
     * @param array<int, array{string, string, int, int, int}> $moved
     */
    private function probeMoved(array $candidate, array $across, array $moved): bool
    {
        $added = false;
        foreach (self::probedMoves([$moved]) as [$path, , , $end]) {
            foreach ($this->probeAcrossCloser($candidate, $across[$path], $path, $end) as [$owner, $offset]) {
                $added = $this->addEscape($owner, $offset) || $added;
            }
        }

        return $added;
    }

    /**
     * The moved nodes `probeMoved()` probes: past the first of each leaf, those the read-back ends later.
     *
     * @param array<array<int, array{string, string, int, int, int}>> $groups
     *
     * @return array<int, array{string, string, int, int, int}>
     */
    private static function probedMoves(array $groups): array
    {
        $probed = [];
        foreach ($groups as $group) {
            foreach ($group as $position => $entry) {
                if ($position > 0 && $entry[4] > $entry[3]) {
                    $probed[] = $entry;
                }
            }
        }

        return $probed;
    }

    /**
     * The moved nodes grouped by their leaf block, in order, so each leaf repairs its first moved node as its owner.
     *
     * @param array<int, array{string, string, int, int, int}> $moved
     *
     * @return array<array<int, array{string, string, int, int, int}>>
     */
    private function movedByLeaf(array $moved): array
    {
        $groups = [];
        foreach ($moved as $entry) {
            $groups[$this->leafOf($entry[0])][] = $entry;
        }

        return array_values($groups);
    }

    /**
     * @param array<int, array{string, string, int, int, int}> $moved
     */
    private function ownerMoved(array $moved): bool
    {
        [$path, , , $end, $readEnd] = $moved[0] ?? [null, '', 0, 0, 0];
        if ($path === null || $readEnd <= $end || !self::hasLaterOverrun($moved, $path)) {
            return false;
        }
        $parts = explode('/', $path);
        $index = (int)array_pop($parts);
        $nextPath = implode('/', $parts) . '/' . ($index + 1);
        $next = self::nodeAt($this->tree, $nextPath);
        $owner = self::nodeAt($this->tree, $path);
        if ($next === null || $owner === null) {
            return false;
        }
        $mark = self::firstMark($next, $nextPath, self::closer($owner), $this->escapes);

        return $mark !== null && $this->addEscape($mark[0], $mark[1]);
    }

    /**
     * @param array<int, array{string, string, int, int, int}> $moved
     * @param string $path
     */
    private static function hasLaterOverrun(array $moved, string $path): bool
    {
        $parent = substr($path, 0, (int)strrpos($path, '/'));
        foreach (array_slice($moved, 1) as [$otherPath, , , $otherEnd, $otherReadEnd]) {
            if ($otherReadEnd > $otherEnd && substr($otherPath, 0, (int)strrpos($otherPath, '/')) === $parent) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>
     * } $candidate
     * @param array<mixed> $actual
     * @param array<array{string, string}> $missing
     *
     * @return array<array<array{string, int}>>
     */
    private function nextTrials(array $candidate, array $actual, array $missing): array
    {
        // C2-W-088, C2-W-125: the procedure runs per text in its leaf block, so each leaf takes the trial of its own
        // first missing node in the same round, and a leaf's escapes do not depend on the leaves around it.
        $trials = [];
        $spent = [];
        $across = null;
        foreach ($missing as [$path, $prior]) {
            $leaf = $this->leafOf($path);
            if (isset($trials[$leaf]) || isset($spent[$leaf])) {
                continue;
            }
            $step = $this->attempts[$path] ?? 0;
            $trial = $this->trial($candidate, $actual, $path, $prior, $step, $across);
            $this->attempts[$path] = $step;
            if ($trial !== null) {
                $trials[$leaf] = $trial;
            } elseif (!$this->kindRounds && $this->parent === null) {
                $spent[$leaf] = true;
            }
        }

        return array_values($trials);
    }

    /**
     * The trials that change how their leaf reads. Several trials of one round are tested together: one source without
     * them and one with all of them, each leaf compared on its own, so a round costs two reads and not two per leaf.
     *
     * @param array<array<array{string, int}>> $trials
     *
     * @return array<array<array{string, int}>>
     */
    private function keptTrials(array $trials): array
    {
        $small = array_filter($trials, static fn (array $trial): bool => count($trial) <= 4);
        // When the first parse holds a construct the tree does not, its reported kind runs before C2-W-125 decides
        // whether several trials together are idle. Keep those trials for the next comparison, as carve-js
        // `dropIdleTrials` does; the local leaf comparison cannot prove them needless yet.
        if ($this->needsKindBeforeTrials($trials)) {
            $this->roundRead = null;

            return $trials;
        }
        if (count($small) < 2) {
            // Only the fingerprint of the round's read-back is kept, so it is not held beside the trial reads.
            if ($small !== [] && $this->roundRead !== null) {
                [$escapes, $source, $read] = $this->roundRead;
                $this->roundPrint = [$escapes, $source, TreeEquality::fingerprint($read)];
                unset($read);
            }
            $this->roundRead = null;
            try {
                return array_values(array_filter($trials, fn (array $trial): bool => $this->keepTrial($trial)));
            } finally {
                $this->roundPrint = null;
            }
        }
        $first = [];
        foreach ($small as $k => $trial) {
            $first[$this->leafOf($trial[0][0])] = $k;
        }
        $left = [];
        foreach ($this->escapes as $owner => $offsets) {
            $k = $first[$this->leafOf($owner)] ?? null;
            foreach ($offsets as $at => $_) {
                if ($k === null || $this->before($owner, $at, $small[$k][0])) {
                    $left[$owner][$at] = true;
                }
            }
        }
        $with = $left;
        foreach ($small as $trial) {
            foreach ($trial as [$path, $offset]) {
                $with[$path][$offset] = true;
            }
        }
        $this->clearStaleRoundRead($left);
        [$count, $prints] = $this->leafPrints($left, $small);
        $read = $count < 0 ? null : $this->readTree($with, true);
        $read = self::matchingRootBlockRead($read, $count);
        if ($read === null) {
            return array_values(array_filter($trials, fn (array $trial): bool => $this->keepTrial($trial)));
        }
        $kept = [];
        foreach ($trials as $k => $trial) {
            $leaf = $this->leafOf($trial[0][0]);
            if (!isset($small[$k]) || $prints[$leaf] !== TreeEquality::fingerprint(self::nodeAt($read, $leaf))) {
                $kept[] = $trial;
            }
        }

        return $kept;
    }

    /**
     * @param array<string, array<int, true>> $escapes
     */
    private function clearStaleRoundRead(array $escapes): void
    {
        if ($this->roundRead !== null && $this->roundRead[0] !== $escapes) {
            $this->roundRead = null;
        }
    }

    /**
     * @param array<mixed>|null $read
     * @param int $count
     *
     * @return array<mixed>|null
     */
    private static function matchingRootBlockRead(?array $read, int $count): ?array
    {
        return $read !== null && $count === count(self::childrenOf($read)) ? $read : null;
    }

    /**
     * @param array<array<array{string, int}>> $trials
     */
    private function needsKindBeforeTrials(array $trials): bool
    {
        $count = count($trials);
        if ($count < 2 || $count > self::MAX_RETESTED_TRIALS || $this->roundRead === null) {
            return false;
        }

        return !self::readsNoOtherNode($this->tree, $this->roundRead[2]);
    }

    /**
     * Whether every read-back inline construct belongs to the same leaf block, including a caption, and occurs in
     * the same order as a construct of the tree. A different construct gives its kind before the trials of that round
     * are tested.
     *
     * @param array<mixed> $expected
     * @param array<mixed> $actual
     */
    private static function readsNoOtherNode(array $expected, array $actual): bool
    {
        $wanted = self::inlineLeaves($expected);
        $read = self::inlineLeaves($actual);
        if (count($wanted) !== count($read)) {
            return false;
        }
        foreach ($read as $at => $leaf) {
            $held = self::childrenOf($wanted[$at]);
            $index = 0;
            foreach (self::childrenOf($leaf) as $node) {
                if (in_array($node['type'] ?? '', ['text', 'soft_break', 'hard_break'], true)) {
                    continue;
                }
                while (isset($held[$index]) && !TreeEquality::same($held[$index], $node)) {
                    $index++;
                }
                if (!isset($held[$index])) {
                    return false;
                }
                $index++;
            }
        }

        return true;
    }

    /**
     * @param array<mixed> $root
     *
     * @return array<array<mixed>>
     */
    private static function inlineLeaves(array $root): array
    {
        $leaves = [];
        $pending = array_reverse(self::childrenOf($root));
        while ($pending !== []) {
            $node = array_pop($pending);
            if (is_array($node['caption'] ?? null)) {
                $pending[] = $node['caption'];
            }
            if (in_array($node['type'] ?? '', self::INLINE_LEAF_TYPES, true)) {
                $leaves[] = $node;
            } else {
                foreach (array_reverse(self::childrenOf($node)) as $child) {
                    $pending[] = $child;
                }
            }
        }

        return $leaves;
    }

    /**
     * The block count of the source these escapes write and the fingerprint of each trial's leaf in it; -1 past the
     * budget. Only the fingerprints are kept, so the read is not held beside the next one.
     *
     * @param array<string, array<int, true>> $escapes
     * @param array<array<array{string, int}>> $trials
     *
     * @return array{int, array<string, string>}
     */
    private function leafPrints(array $escapes, array $trials): array
    {
        $parts = $this->roundRead !== null && $this->roundRead[0] === $escapes ? $this->roundParts ?? [] : [];
        $read = $this->readTree($escapes);
        if ($read === null) {
            return [-1, []];
        }
        $prints = [];
        foreach ($trials as $trial) {
            $leaf = $this->leafOf($trial[0][0]);
            $prints[$leaf] = ($leaf !== '' ? $parts[$leaf] ?? null : null) ?? TreeEquality::fingerprint(self::nodeAt($read, $leaf));
        }

        return [count(self::childrenOf($read)), $prints];
    }

    /**
     * Whether the escape at `$at` of `$owner` stands before the escape `$trial` in the order of the texts.
     *
     * @param string $owner
     * @param int $at
     * @param array{string, int} $trial
     *
     * @return bool
     */
    private function before(string $owner, int $at, array $trial): bool
    {
        $order = $this->textOrder[$owner] ?? -1;
        $rank = $this->textOrder[$trial[0]] ?? -1;

        return $order < $rank || ($owner === $trial[0] && $at < $trial[1]);
    }

    /**
     * The tree the source written with these escapes reads back as, or null past the budget.
     *
     * @param array<string, array<int, true>> $escapes
     * @param bool $keep Keep the read for the next round, which takes it where it writes the same source.
     *
     * @return array<mixed>|null
     */
    private function readTree(array $escapes, bool $keep = false): ?array
    {
        // The source of the round's own escapes is the round's candidate, read already; it is charged all the same.
        if ($this->roundRead !== null && $this->roundRead[0] === $escapes) {
            [, $source, $read] = $this->roundRead;
            $this->roundRead = null;

            return $this->charge($source) ? $read : null;
        }
        $source = Writer::bareSource($this->tree, $this->layout, $this->options, $escapes);
        if (!$this->charge($source)) {
            return null;
        }
        $read = ReadbackRound::read($source, $this->options);
        if ($keep) {
            $this->nextRead = [$source, $read];
        }

        return $read;
    }

    /**
     * @param array<array{string, int}> $trial
     */
    private function keepTrial(array $trial): bool
    {
        if (count($trial) > 4) {
            return true;
        }
        if (count($trial) > 1) {
            $rank = $this->textOrder[$trial[0][0]] ?? -1;
            $left = [];
            foreach ($this->escapes as $owner => $offsets) {
                $order = $this->textOrder[$owner] ?? -1;
                foreach ($offsets as $at => $_) {
                    if ($order < $rank || ($owner === $trial[0][0] && $at < $trial[0][1])) {
                        $left[$owner][$at] = true;
                    }
                }
            }
            $without = $this->sourceTree($left);
            foreach ($trial as [$path, $offset]) {
                $left[$path][$offset] = true;
            }
            $with = $this->sourceTree($left);

            return $without !== null && $with !== null && $without !== $with;
        }
        foreach ($trial as [$path, $offset]) {
            if ($this->trialChangesTree($path, $offset)) {
                return true;
            }
        }

        return false;
    }

    private function charge(string $source): bool
    {
        if ($this->parent !== null) {
            return $this->parent->charge($source);
        }
        $this->read += strlen($source);

        return $this->read <= $this->limit;
    }

    /**
     * @param array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>,
     *     ranges?: array<string, array{int, int}>
     * } $candidate
     * @param array<mixed> $actual
     * @param bool $sourceBacked
     *
     * @return array<array{string, string}>
     */
    private static function missingSiblings(array $candidate, array $actual, bool $sourceBacked): array
    {
        // Two flat maps, not an array per node: these are held beside the candidate and the read-back.
        $ends = [];
        $parents = [];
        self::actualSpans($actual, null, $ends, $parents);
        $byPath = [];
        foreach ($candidate['nodes'] as $id => [$packed]) {
            $byPath[$packed] = $id;
        }
        $nodes = $candidate['nodes'];
        $missing = [];
        foreach ($byPath as $packed => $id) {
            [, $type, $start] = $nodes[$id];
            [$index, $parent, $prior] = self::packedNeighbors((string)$packed);
            $priorId = $index > 0 ? $byPath[$prior] ?? null : null;
            if ($priorId !== null) {
                [, $priorType, $priorStart, $priorEnd] = $nodes[$priorId];
                if (($ends[$priorType . ':' . $priorStart] ?? $priorEnd) > $start) {
                    $missing[] = [RepairTrace::unpackPath((string)$packed), RepairTrace::unpackPath($prior)];

                    continue;
                }
            }
            if (
                self::heldAsText($candidate['ranges'] ?? [], $ends, [$type, $start, $prior], $priorId !== null)
                // A parsed source proves its first child can form. A supplied tree may name an unspellable first node;
                // its following text must not create a speculative trial for that node.
                || ($sourceBacked && $index === 0 && !isset($ends[$type . ':' . $start]) && ($ends['text:' . $start] ?? $start) > $start)
            ) {
                $missing[] = [RepairTrace::unpackPath((string)$packed), RepairTrace::unpackPath($prior)];

                continue;
            }
            $key = $type . ':' . $start;
            $parentId = isset($ends[$key]) ? $byPath[$parent] ?? null : null;
            if ($parentId === null) {
                continue;
            }
            [, $parentType, $parentStart] = $nodes[$parentId];
            if ($parents[$key] !== $parentType . ':' . $parentStart) {
                $missing[] = [RepairTrace::unpackPath((string)$packed), RepairTrace::unpackPath($prior)];
            }
        }

        return $missing;
    }

    /**
     * C2-W-091: the node did not form, and the parse holds its markup as text: the text before it reads on into it, or
     * behind a node that formed a text starts where the node would.
     *
     * @param array<string, array{int, int}> $ranges The text ranges of the candidate, by path.
     * @param array<string, int> $ends
     * @param array{string, int, string} $node Its type, its start and the packed path of the sibling before it.
     * @param bool $afterNode Whether the sibling before it is a node of the candidate.
     */
    private static function heldAsText(array $ranges, array $ends, array $node, bool $afterNode): bool
    {
        [$type, $start, $prior] = $node;
        if (isset($ends[$type . ':' . $start])) {
            return false;
        }
        if ($afterNode) {
            return isset($ends['text:' . $start]);
        }
        $priorText = $ranges[RepairTrace::unpackPath($prior)] ?? null;

        return $priorText !== null && ($ends['text:' . $priorText[0]] ?? $priorText[1]) > $start;
    }

    /**
     * The last index of a packed path, and the packed paths before that index (its parent) and of the sibling before.
     *
     * @return array{int, string, string}
     */
    private static function packedNeighbors(string $packed): array
    {
        // `<form>\x01<digits>` is `<path>/children/<digits>`; the parent and prior keep `<form>` as it is.
        $cut = $packed === '' || $packed[0] === "\x02" ? false : strrpos($packed, "\x01");
        if ($cut !== false) {
            $digits = substr($packed, $cut + 1);
            if ($digits !== '' && strspn($digits, '0123456789') === strlen($digits)) {
                $index = (int)$digits;

                return [$index, substr($packed, 0, $cut) . '/children', substr($packed, 0, $cut + 1) . ($index - 1)];
            }
        }
        $path = RepairTrace::unpackPath($packed);
        $cut = strrpos($path, '/');
        $index = (int)($cut === false ? $path : substr($path, $cut + 1));
        $parent = $cut === false ? '' : substr($path, 0, $cut);

        return [$index, RepairTrace::packPath($parent), RepairTrace::packPath($parent . '/' . ($index - 1))];
    }

    /**
     * @param array<mixed> $node
     * @param string|null $parent The `type:start` key of the nearest positioned ancestor.
     * @param array<string, int> $ends The end of each positioned node, by its `type:start` key.
     * @param array<string, string|null> $parents
     */
    private static function actualSpans(array $node, ?string $parent, array &$ends, array &$parents): void
    {
        $pos = TreeEncoder::offsets($node['pos'] ?? null);
        $type = is_string($node['type'] ?? null) ? $node['type'] : '';
        if ($pos !== null) {
            $key = $type . ':' . $pos[0];
            $ends[$key] = $pos[1];
            $parents[$key] = $parent;
            $parent = $key;
        }
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            if (is_array($child)) {
                self::actualSpans($child, $parent, $ends, $parents);
            }
        }
    }

    /**
     * @param array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>
     * } $candidate
     * @param array<mixed> $actual
     * @param string $prior
     * @param string $path
     * @param int $step
     * @param array{array<string, array{int, int}>, array<int, int>}|null $across The round's `acrossIndex()`, built on first use.
     *
     * @return array<array{string, int}>|null
     */
    private function trial(array $candidate, array $actual, string $path, string $prior, int &$step, ?array &$across = null): ?array
    {
        $node = self::nodeAt($this->tree, $path);
        if ($node === null) {
            return null;
        }
        $parts = explode('/', $path);
        $index = (int)array_pop($parts);
        $parent = implode('/', $parts);
        while ($step < 14) {
            $case = $step++;
            if ($case === 1 && ($node['type'] ?? '') === 'inline_footnote') {
                $openers = self::priorNoteOpeners(self::lastText(self::nodeAt($this->tree, $prior), $prior), $this->escapes);
                if ($openers !== []) {
                    return [...$openers, ...self::loneNoteBrackets($node, $path, $this->escapes)];
                }
            }
            if ($case === 3 || $case === 4) {
                $probed = $case === 3
                    ? $this->contentConstruct($path, $node)
                    : self::acrossCloser($candidate, $actual, $across, $path, $this->escapes);
                if ($probed !== []) {
                    return $probed;
                }

                continue;
            }
            $chosen = match ($case) {
                0, 11 => self::endEscape(self::lastText(self::nodeAt($this->tree, $prior), $prior), $this->escapes),
                5, 12 => self::endEscape(self::lastText($node, $path), $this->escapes),
                7 => self::after($this->tree, $parent . '/' . ($index + 1), $this->escapes),
                13 => self::firstMark($node, $path, self::closer($node), $this->escapes),
                default => null,
            };
            if ($chosen !== null) {
                return [$chosen];
            }
        }

        return null;
    }

    /**
     * A missing inline footnote can be swallowed by text `^[` openers before it. Escaping those brackets leaves the
     * footnote's own opener in place; the trial is retained only when its read-back changes the tree.
     *
     * @param array{string, string}|null $last
     * @param array<string, array<int, true>> $escapes
     *
     * @return array<array{string, int}>
     */
    private static function priorNoteOpeners(?array $last, array $escapes): array
    {
        if ($last === null) {
            return [];
        }
        [$path, $value] = $last;
        $openers = [];
        for ($at = strpos($value, '^['); $at !== false; $at = strpos($value, '^[', $at + 2)) {
            if (!isset($escapes[$path][$at + 1])) {
                $openers[] = [$path, $at + 1];
            }
        }

        return $openers;
    }

    /**
     * Brackets left open by the text of a note need the case-five escape before a trial can show whether an earlier
     * text note opener was swallowing this note. Bracketed child nodes have their own independent pair stack.
     *
     * @param array<mixed> $node
     * @param string $path
     * @param array<string, array<int, true>> $escapes
     *
     * @return array<array{string, int}>
     */
    private static function loneNoteBrackets(array $node, string $path, array $escapes): array
    {
        $open = [];
        self::noteBracketWalk(self::childrenOf($node), $path . '/children', $open);

        return array_values(array_filter($open, static fn (array $entry): bool => !isset($escapes[$entry[0]][$entry[1]])));
    }

    /**
     * @param array<mixed> $children
     * @param string $path
     * @param array<array{string, int}> $open
     */
    private static function noteBracketWalk(array $children, string $path, array &$open): void
    {
        foreach ($children as $index => $child) {
            if (!is_array($child)) {
                continue;
            }
            $atPath = $path . '/' . $index;
            if (($child['type'] ?? '') === 'text' && is_string($child['value'] ?? null)) {
                for ($at = 0, $length = strlen($child['value']); $at < $length; $at++) {
                    if ($child['value'][$at] === '[') {
                        $open[] = [$atPath, $at];
                    } elseif ($child['value'][$at] === ']' && $open !== []) {
                        array_pop($open);
                    }
                }
            } elseif (
                ($child['type'] ?? '') !== 'image'
                && !in_array($child['type'] ?? '', self::BRACKETED, true)
                && is_array($child['children'] ?? null)
            ) {
                self::noteBracketWalk($child['children'], $atPath . '/children', $open);
            }
        }
    }

    /**
     * C2-W-088: the content of the node at `path`, written and parsed on its own, holds a construct that the tree does
     * not, as a closer of an inner node that reads early. The escapes its own rounds ask for are this trial. A content
     * that opens a block is read again behind a word, then with its line breaks as spaces (carve-js
     * `escapeContentConstruct`).
     *
     * @param string $path
     * @param array<mixed> $node
     *
     * @return array<array{string, int}>
     */
    private function contentConstruct(string $path, array $node): array
    {
        $children = is_array($node['children'] ?? null) ? array_values($node['children']) : [];
        if ($children === [] || !in_array($node['type'] ?? '', self::INLINE_CONTAINERS, true)) {
            return [];
        }
        $word = ['type' => 'text', 'value' => 'x'];
        $flat = [];
        $kept = [];
        foreach ($children as $index => $child) {
            $break = is_array($child) && ($child['type'] ?? '') === 'soft_break';
            $flat[] = $break ? ['type' => 'text', 'value' => ' '] : $child;
            if (!$break) {
                $kept[$index] = true;
            }
        }
        $all = array_fill_keys(array_keys($children), true);
        $first = is_array($children[0]) ? ($children[0]['type'] ?? '') : '';
        $variants = [[[$word, ...$children], 1, $all], [[$word, ...$flat], 1, $kept]];
        if ($first !== 'soft_break' && $first !== 'hard_break') {
            array_unshift($variants, [$children, 0, $all]);
        }
        foreach ($variants as [$inlines, $shift, $mapped]) {
            $found = $this->probeContent($path, $inlines, $shift, $mapped);
            if ($found !== null) {
                return $found;
            }
        }

        return [];
    }

    /**
     * One variant of a content probe: null where its first parse is no single paragraph, which tells nothing.
     *
     * @param string $path
     * @param array<mixed> $inlines
     * @param int $shift
     * @param array<int, true> $mapped The children of `path` that stand in the probe as themselves.
     *
     * @return array<array{string, int}>|null
     */
    private function probeContent(string $path, array $inlines, int $shift, array $mapped): ?array
    {
        $prefix = $path . '/children/';
        $inside = '/children/0/children/';
        $probe = new self(['type' => 'document', 'children' => [['type' => 'paragraph', 'children' => $inlines]]], null, $this->options);
        $probe->parent = $this;
        $probe->kindRounds = false;
        $type = self::nodeAt($this->tree, $path)['type'] ?? null;
        $probe->probed = is_string($type) ? $type : null;
        $probe->escapes = self::moveEscapes($this->escapes, $prefix, $inside, $shift, $mapped);
        if (!$probe->rememberedRounds($inlines)) {
            return null;
        }
        $found = [];
        foreach (self::moveEscapes($probe->escapes, $inside, $prefix, -$shift, $mapped, true) as $original => $offsets) {
            foreach ($offsets as $at => $_) {
                if (!isset($this->escapes[$original][$at])) {
                    $found[] = [$original, $at];
                }
            }
        }

        return $found;
    }

    /**
     * `probeRounds()`, run once per probe of the same content, escapes and write state: a leaf takes the same probe in
     * several rounds.
     *
     * @param array<mixed> $inlines
     */
    private function rememberedRounds(array $inlines): bool
    {
        $flag = Writer::repairFlag();
        if ($flag === null) {
            return $this->probeRounds();
        }
        $root = $this->root();
        $read = $root->read;
        $inputs = [$inlines, $this->escapes, $this->probed, $this->options, $flag];
        [[$single, $escapes, $after], $charged] = WriteMemo::probe($inputs, $root->limit - $read, function () use ($root, $read): array {
            $single = $this->probeRounds();

            return [[$single, $this->escapes, Writer::repairFlag() ?? false], $root->read - $read];
        });
        $root->read = $read + $charged;
        $this->escapes = $escapes;
        Writer::restoreRepairFlag($after);

        return $single;
    }

    /**
     * The rounds of a content probe; false where its first parse is no single paragraph.
     */
    private function probeRounds(): bool
    {
        $root = $this->root();
        $seen = [];
        $states = [];
        $reads = [];
        for ($round = 0; $round < self::MAX_PROBE_ROUNDS; $round++) {
            if ($seen !== null) {
                $state = $this->probeState();
                $key = hash('xxh3', serialize($state));
                $from = $seen[$key] ?? null;
                if ($from !== null && $states[$from] !== $state) {
                    $from = null;
                }
                if ($from === null) {
                    $seen[$key] = $round;
                    $states[$round] = $state;
                    $reads[$round] = $root->read;
                } else {
                    // A round starts from the state of an earlier one, so the rounds left repeat that cycle: the state
                    // and the characters they would read are known without reading them (C2-W-133).
                    $period = $round - $from;
                    $left = self::MAX_PROBE_ROUNDS - $round;
                    $rest = $left % $period;
                    $charged = intdiv($left, $period) * ($root->read - $reads[$from]) + $reads[$from + $rest] - $reads[$from];
                    if ($root->read + $charged <= $root->limit) {
                        $root->read += $charged;
                        $this->restoreProbeState($states[$from + $rest]);

                        return true;
                    }
                    // Past the budget the rounds run, so the charge that fails stops them where it would.
                    $seen = null;
                    $states = [];
                }
            }
            $escapes = $this->escapes;
            $source = Writer::bareSource($this->tree, null, $this->options, $escapes);
            if (!$this->charge($source)) {
                return true;
            }
            $compared = $this->compared($source, $escapes, null);
            if ($round === 0 && !$this->singleParagraph) {
                return false;
            }
            if ($compared === null) {
                return true;
            }
            [$candidate, $comparison] = $compared;
            unset($compared);
            if (!$this->appliedRound($escapes, $candidate, $comparison)) {
                return true;
            }
        }

        return true;
    }

    private function root(): self
    {
        return $this->parent === null ? $this : $this->parent->root();
    }

    /**
     * Everything a probe round reads that an earlier round may have changed.
     *
     * @return array{
     *     escapes: array<string, array<int, true>>,
     *     attempts: array<string, int>,
     *     trialEscapes: array<string, array<int, true>>,
     *     kindsDone: array<string, array<string, true>>,
     *     compareAsks: array<string, array<int, true>>,
     *     notesDone: array<string, array<int, true>>,
     *     nextRead: array{string, array<string, mixed>}|null,
     *     roundPrint: array{array<string, array<int, true>>, string, string}|null
     * }
     */
    private function probeState(): array
    {
        return [
            'escapes' => $this->escapes,
            'attempts' => $this->attempts,
            'trialEscapes' => $this->trialEscapes,
            'kindsDone' => $this->kindsDone,
            'compareAsks' => $this->compareAsks,
            'notesDone' => $this->notesDone,
            'nextRead' => $this->nextRead,
            'roundPrint' => $this->roundPrint,
        ];
    }

    /**
     * @param array{
     *     escapes: array<string, array<int, true>>,
     *     attempts: array<string, int>,
     *     trialEscapes: array<string, array<int, true>>,
     *     kindsDone: array<string, array<string, true>>,
     *     compareAsks: array<string, array<int, true>>,
     *     notesDone: array<string, array<int, true>>,
     *     nextRead: array{string, array<string, mixed>}|null,
     *     roundPrint: array{array<string, array<int, true>>, string, string}|null
     * } $state
     */
    private function restoreProbeState(array $state): void
    {
        $this->escapes = $state['escapes'];
        $this->attempts = $state['attempts'];
        $this->trialEscapes = $state['trialEscapes'];
        $this->kindsDone = $state['kindsDone'];
        $this->compareAsks = $state['compareAsks'];
        $this->notesDone = $state['notesDone'];
        $this->nextRead = $state['nextRead'];
        $this->roundPrint = $state['roundPrint'];
    }

    /**
     * The escapes below `from` moved below `to`, each child index shifted by `shift`. Only the children in `mapped`
     * (by their index below the content of the probed node) move.
     *
     * @param array<string, array<int, true>> $escapes
     * @param string $from
     * @param string $to
     * @param int $shift
     * @param array<int, true> $mapped
     * @param bool $back
     *
     * @return array<string, array<int, true>>
     */
    private static function moveEscapes(
        array $escapes,
        string $from,
        string $to,
        int $shift,
        array $mapped,
        bool $back = false,
    ): array {
        $moved = [];
        foreach ($escapes as $owner => $offsets) {
            if (!str_starts_with($owner, $from)) {
                continue;
            }
            $rest = substr($owner, strlen($from));
            $cut = strpos($rest, '/');
            $index = (int)($cut === false ? $rest : substr($rest, 0, $cut));
            if (isset($mapped[$back ? $index + $shift : $index])) {
                $moved[$to . ($index + $shift) . ($cut === false ? '' : substr($rest, $cut))] = $offsets;
            }
        }

        return $moved;
    }

    /**
     * @param array<mixed> $tree
     * @param string $path
     */
    public static function leafValue(array $tree, string $path): ?string
    {
        $node = self::nodeAt($tree, $path);

        return is_string($node['value'] ?? null) ? $node['value'] : null;
    }

    /**
     * @param array<mixed> $tree
     * @param string $path
     *
     * @return array<mixed>|null
     */
    private static function nodeAt(array $tree, string $path): ?array
    {
        $node = $tree;
        foreach (explode('/', trim($path, '/')) as $part) {
            if (!is_array($node[$part] ?? null)) {
                return null;
            }
            $node = $node[$part];
        }

        return $node;
    }

    /**
     * @param array<mixed>|null $node
     * @param string $path
     *
     * @return array{string, string}|null
     */
    private static function lastText(?array $node, string $path): ?array
    {
        if ($node === null) {
            return null;
        }
        if (($node['type'] ?? '') === 'text') {
            return [$path, is_string($node['value'] ?? null) ? $node['value'] : ''];
        }
        foreach (array_reverse(is_array($node['children'] ?? null) ? $node['children'] : [], true) as $index => $child) {
            if (is_array($child)) {
                $last = self::lastText($child, $path . '/children/' . $index);
                if ($last !== null) {
                    return $last;
                }
            }
        }

        return null;
    }

    /**
     * @param array{string, string}|null $last
     * @param array<string, array<int, true>> $escapes
     *
     * @return array{string, int}|null
     */
    private static function endEscape(?array $last, array $escapes): ?array
    {
        if ($last === null) {
            return null;
        }
        [$path, $value] = $last;
        $length = strlen($value);
        if ($length > 0 && str_contains('\\!^$`', $value[$length - 1]) && !isset($escapes[$path][$length - 1])) {
            return [$path, $length - 1];
        }
        $brace = strrpos($value, '{');

        return $brace !== false && !isset($escapes[$path][$brace]) ? [$path, $brace] : null;
    }

    /**
     * @param array<mixed> $tree
     * @param string $path
     * @param array<string, array<int, true>> $escapes
     *
     * @return array{string, int}|null
     */
    private static function after(array $tree, string $path, array $escapes): ?array
    {
        $node = self::nodeAt($tree, $path);
        $value = $node['value'] ?? null;

        return ($node['type'] ?? '') === 'text' && is_string($value) && $value !== ''
            && Text::isPunct($value[0]) && !isset($escapes[$path][0]) ? [$path, 0] : null;
    }

    /**
     * C2-W-125, case nine: where the braced spelling reads as an attribute block, its key takes the backslash rather
     * than a trial in the same text, unless that trial escapes the node's closing mark in its content (carve-js
     * `escapeInContent`, an earlier step). A round with another repair asks the key only after it.
     *
     * @param array<array<array{string, int}>> $trials
     * @param array<string, array<int, true>> $keys
     *
     * @return array<array<array{string, int}>>
     */
    private function keyTrials(array $trials, array $keys): array
    {
        if ($keys === []) {
            return $trials;
        }

        return array_values(array_filter($trials, fn (array $trial): bool => !$this->yieldsToKey($trial, $keys)));
    }

    /**
     * Whether a trial gives way to the case-nine key of its text: it escapes a character of that text other than the
     * closing mark of the node the text opens.
     *
     * @param array<int, array{string, int}> $trial
     * @param array<string, array<int, true>> $keys
     */
    private function yieldsToKey(array $trial, array $keys): bool
    {
        foreach ($trial as [$path, $offset]) {
            if (!isset($keys[$path])) {
                continue;
            }
            $owner = self::nodeAt($this->tree, substr($path, 0, (int)strrpos($path, '/children/')));
            $value = self::leafValue($this->tree, $path) ?? '';
            if ($owner !== null && ($value[$offset] ?? '') !== self::closer($owner)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $node
     */
    private static function closer(array $node): string
    {
        return match ($node['type'] ?? '') {
            'superscript' => '^', 'subscript' => ',', 'emphasis' => '/', 'strong' => '*',
            'underline' => '_', 'strike' => '~', 'highlight' => '=', 'insert' => '+', 'delete' => '-',
            'link', 'span', 'inline_footnote', 'inline_extension' => ']',
            default => '',
        };
    }

    /**
     * @param array<mixed> $node
     * @param string $mark
     * @param string $path
     * @param array<string, array<int, true>> $escapes
     *
     * @return array{string, int}|null
     */
    private static function firstMark(array $node, string $path, string $mark, array $escapes): ?array
    {
        if ($mark === '') {
            return null;
        }
        if (($node['type'] ?? '') === 'text') {
            $value = is_string($node['value'] ?? null) ? $node['value'] : '';
            for ($at = 0, $length = strlen($value); $at < $length; $at++) {
                if ($value[$at] === $mark && !isset($escapes[$path][$at])) {
                    return [$path, $at];
                }
            }

            return null;
        }
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $index => $child) {
            if (is_array($child)) {
                $found = self::firstMark($child, $path . '/children/' . $index, $mark, $escapes);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * @param array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>
     * } $candidate
     * @param array<mixed> $actual
     *
     * @return array{array<string, array{int, int}>, array<int, int>}
     */
    private static function acrossIndex(array $candidate, array $actual): array
    {
        // Built once per round: every leaf's trial reads the same candidate and read-back.
        $spans = [];
        foreach ($candidate['nodes'] as [$expectedPath, , $start, $end]) {
            $spans[$expectedPath] ??= [$start, $end];
        }
        $ends = [];
        $parents = [];
        self::actualSpans($actual, null, $ends, $parents);
        unset($parents);
        $endsByStart = [];
        foreach ($ends as $key => $readEnd) {
            if (str_starts_with($key, 'text:')) {
                continue;
            }
            $separator = strrpos($key, ':');
            if ($separator !== false) {
                $start = (int)substr($key, $separator + 1);
                $endsByStart[$start] = max($endsByStart[$start] ?? 0, $readEnd);
            }
        }

        return [$spans, $endsByStart];
    }

    /**
     * @param-out array{array<string, array{int, int}>, array<int, int>} $index
     *
     * @param array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>
     * } $candidate
     * @param array<mixed> $actual
     * @param array{array<string, array{int, int}>, array<int, int>}|null $index `acrossIndex()`, built on first use.
     * @param string $path
     * @param array<string, array<int, true>> $escapes
     *
     * @return array<array{string, int}>
     */
    private static function acrossCloser(array $candidate, array $actual, ?array &$index, string $path, array $escapes): array
    {
        [$spans, $endsByStart] = $index ??= self::acrossIndex($candidate, $actual);
        $span = $spans[RepairTrace::packPath($path)] ?? null;
        if ($span === null) {
            return [];
        }
        $found = [];
        for ($position = max(0, $span[0]); $position < $span[1]; $position++) {
            $code = $candidate['text'][$position] ?? null;
            if ($code === null) {
                continue;
            }
            [$owner, $offset] = [$candidate['paths'][$code >> 32], $code & 0xFFFFFFFF];
            if (!str_starts_with($owner, $path . '/') || isset($escapes[$owner][$offset])) {
                continue;
            }
            $mark = $candidate['source'][$position] ?? '';
            if (!Text::isPunct($mark)) {
                continue;
            }
            if (($endsByStart[$position] ?? 0) > $span[1]) {
                $found[] = [$owner, $offset];
            }
        }

        return $found;
    }

    /**
     * A construct inside a paired node may read across its closer only when the content and
     * following inlines are parsed together. Probe that suffix independently so an outer parse
     * does not hide its nested openers. As in C2-W-125, a probe takes at most 32 rounds.
     *
     * @param array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>
     * } $candidate
     * @param array{array{int, int}, int} $across The node's span and the end of its probe, `acrossSpans()`.
     * @param int $end
     * @param string $path
     *
     * @return array<array{string, int}>
     */
    private function probeAcrossCloser(array $candidate, array $across, string $path, int $end): array
    {
        $node = self::nodeAt($this->tree, $path);
        if ($node === null || self::closer($node) === '') {
            return [];
        }
        [$probe, $end] = $this->probeCandidate($candidate, $path, $end);
        if ($probe !== $candidate) {
            $candidate = $probe;
            $across = self::acrossSpans($candidate, [[$path]])[$path];
        }
        [$span, $acrossEnd] = $across;
        $first = null;
        for ($position = $span[0], $stop = $span[1]; $position < $stop; $position++) {
            if (str_starts_with(RepairTrace::owner($candidate, $position)[0] ?? '', $path . '/')) {
                $first = $position;

                break;
            }
        }
        if ($first === null) {
            return [];
        }
        $source = 'x ' . substr($candidate['source'], $first, $acrossEnd - $first);
        if (!$this->charge($source)) {
            return [];
        }
        $read = TreeEncoder::decodedWithOffsets(Carve::parse($source, $this->parseOptions()), $source);

        return $this->probeOpeners($candidate, $read, $path, $end, $first, self::closer($node));
    }

    /**
     * @param array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>
     * } $candidate
     * @param array<mixed> $read
     * @param string $path
     * @param int $end
     * @param int $first
     * @param string $closer
     *
     * @return array<array{string, int}>
     */
    private function probeOpeners(array $candidate, array $read, string $path, int $end, int $first, string $closer): array
    {
        $found = [];
        $pending = [$read];
        while ($pending !== []) {
            $item = array_pop($pending);
            if (!is_array($item)) {
                continue;
            }
            foreach (is_array($item['children'] ?? null) ? $item['children'] : [] as $child) {
                $pending[] = $child;
            }
            $opener = $this->probeOpener($candidate, $item, $path, $end, $first, $closer);
            if ($opener !== null) {
                [$start, $owned] = $opener;
                $found[$start] = $owned;
            }
        }
        ksort($found);

        return array_slice(array_values($found), 0, 32);
    }

    // The inlines behind a node that a probe across its closer reads, at most (as MAX_ACROSS in carve-js).
    /**
     * @var int
     */
    private const MAX_ACROSS = 16;

    /**
     * Each probed node's span in the candidate and the end of a probe across its closer: the node, its closer and at
     * most MAX_ACROSS inlines behind it. By path.
     *
     * @param array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>,
     *     ranges?: array<string, array{int, int}>
     * } $candidate
     * @param array<int, array{0: string}> $moved
     *
     * @return array<string, array{array{int, int}, int}>
     */
    private static function acrossSpans(array $candidate, array $moved): array
    {
        $packed = [];
        $siblings = [];
        foreach ($moved as [$path]) {
            $cut = (int)strrpos($path, '/');
            $parent = substr($path, 0, $cut) . '/';
            // Digits cannot be part of a `/children/`, so a packed path takes them as they are.
            $form = RepairTrace::packPath($parent);
            $packed[$path] = RepairTrace::packPath($path);
            $siblings[$path] = [];
            for ($index = (int)substr($path, $cut + 1), $k = $index + self::MAX_ACROSS; $k >= $index; $k--) {
                $packed[$parent . $k] = $siblings[$path][] = $form . $k;
            }
        }
        $spans = [];
        foreach ($candidate['ranges'] ?? [] as $rangePath => $range) {
            if (isset($packed[$rangePath])) {
                $spans[$packed[$rangePath]] = $range;
            }
        }
        $wanted = array_flip($packed);
        foreach ($candidate['nodes'] as [$nodePath, , $start, $end]) {
            if (isset($wanted[$nodePath])) {
                $spans[$nodePath] = [$start, $end];
            }
        }
        $across = [];
        foreach ($siblings as $path => $forms) {
            $end = strlen($candidate['source']);
            foreach ($forms as $form) {
                if (isset($spans[$form])) {
                    $end = $spans[$form][1];

                    break;
                }
            }
            $across[$path] = [$spans[$packed[$path]] ?? [0, -1], $end];
        }

        return $across;
    }

    /**
     * @param array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>
     * } $candidate
     * @param int $end
     * @param string $path
     *
     * @return array{
     *     array{
     *         source: string,
     *         text: array<int, int>,
     *         paths: array<int, string>,
     *         nodes: array<int, array{string, string, int, int}>
     *     },
     *     int
     * }
     */
    private function probeCandidate(array $candidate, string $path, int $end): array
    {
        $probeEscapes = $this->escapes;
        $changed = false;
        foreach ($this->trialEscapes as $owner => $offsets) {
            if (!str_starts_with($owner, $path . '/')) {
                continue;
            }
            foreach ($offsets as $at => $_) {
                unset($probeEscapes[$owner][$at]);
                $changed = true;
            }
        }
        if ($changed) {
            $candidate = Writer::bareCandidate($this->tree, $this->layout, $this->options, $probeEscapes);
            $packed = RepairTrace::packPath($path);
            foreach ($candidate['nodes'] as [$nodePath, , , $nodeEnd]) {
                if ($nodePath === $packed) {
                    $end = $nodeEnd;

                    break;
                }
            }
        }

        return [$candidate, $end];
    }

    /**
     * @param array{
     *     source: string,
     *     text: array<int, int>,
     *     paths: array<int, string>,
     *     nodes: array<int, array{string, string, int, int}>
     * } $candidate
     * @param array<mixed> $item
     * @param string $closer
     * @param int $first
     * @param int $end
     * @param string $path
     *
     * @return array{int, array{string, int}}|null
     */
    private function probeOpener(array $candidate, array $item, string $path, int $end, int $first, string $closer): ?array
    {
        $pos = TreeEncoder::offsets($item['pos'] ?? null);
        if ($pos === null) {
            return null;
        }
        [$startOffset, $endOffset] = $pos;
        $start = $startOffset + $first - 2;
        $finish = $endOffset + $first - 2;
        $owned = RepairTrace::owner($candidate, $start);
        if (
            $owned === null || !str_starts_with($owned[0], $path . '/') || $start >= $end || $finish <= $end
            || !str_contains('[`<{$:', $candidate['source'][$start])
            || $candidate['source'][$start] === $closer || isset($this->escapes[$owned[0]][$owned[1]])
        ) {
            return null;
        }

        return [$start, $owned];
    }

    private function trialChangesTree(string $path, int $offset): bool
    {
        $rank = $this->textOrder[$path] ?? -1;
        $left = [];
        foreach ($this->escapes as $owner => $offsets) {
            $order = $this->textOrder[$owner] ?? -1;
            foreach ($offsets as $at => $_) {
                if ($order < $rank || ($owner === $path && $at < $offset)) {
                    $left[$owner][$at] = true;
                }
            }
        }
        $without = $this->sourceTree($left);
        if ($without === null) {
            return false;
        }
        $left[$path][$offset] = true;
        $with = $this->sourceTree($left);

        return $with !== null && $without !== $with;
    }

    /**
     * The fingerprint of the tree the source written with these escapes reads back as.
     *
     * @param array<string, array<int, true>> $escapes
     *
     * @return string|null
     */
    private function sourceTree(array $escapes): ?string
    {
        // The source of the round's own escapes is the round's candidate, read already; it is charged all the same.
        if ($this->roundPrint !== null && $this->roundPrint[0] === $escapes) {
            return $this->charge($this->roundPrint[1]) ? $this->roundPrint[2] : null;
        }
        $source = Writer::bareCandidate($this->tree, $this->layout, $this->options, $escapes, false)['source'];
        if (!$this->charge($source)) {
            return null;
        }
        $options = $this->parseOptions();

        return WriteMemo::read(
            'print',
            [$source, $options],
            static fn (): string => TreeEquality::fingerprint(TreeEncoder::decodedForWriter(Carve::parse($source, $options))),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function parseOptions(): array
    {
        return isset($this->options['extensions']) ? ['extensions' => $this->options['extensions']] : [];
    }

    /**
     * `textOrder()` with `leafOfTree()` of each text, found in the same walk: a lookup per text costs a deep tree its
     * depth squared.
     *
     * @param array<mixed> $node
     * @param string $path
     * @param string $leaf The leaf block `$node` stands in, or its own path where it is no inline container.
     * @param array<string, int> $order
     * @param array<string, string> $leaves
     */
    private static function textLeaves(array $node, string $path, string $leaf, array &$order, array &$leaves): void
    {
        $type = $node['type'] ?? null;
        if ($type === 'text') {
            $order[$path] = count($order);
            $leaves[$path] = $leaf;
        }
        $inner = in_array($type, self::INLINE_CONTAINERS, true) ? $leaf : $path;
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $index => $child) {
            if (is_array($child)) {
                self::textLeaves($child, $path . '/children/' . $index, $inner, $order, $leaves);
            }
        }
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     * @param array<string, int> $order
     */
    private static function textOrder(array $node, string $path, array &$order): void
    {
        if (($node['type'] ?? '') === 'text') {
            $order[$path] = count($order);
        }
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $index => $child) {
            if (is_array($child)) {
                self::textOrder($child, $path . '/children/' . $index, $order);
            }
        }
    }

    /**
     * @param array<mixed> $expected
     * @param array<mixed>|null $actual
     * @param string $path
     * @param array<string, array<int, true>> $differences
     */
    private static function textDifferences(array $expected, ?array $actual, string $path, array &$differences): void
    {
        if (($expected['type'] ?? '') === 'text') {
            $at = self::firstTextDifference($expected, $actual);
            if ($at !== null) {
                $differences[$path][$at] = true;
            }

            return;
        }
        if ($actual === null || ($expected['type'] ?? '') !== ($actual['type'] ?? '')) {
            return;
        }
        $actualChildren = is_array($actual['children'] ?? null) ? $actual['children'] : [];
        foreach (is_array($expected['children'] ?? null) ? $expected['children'] : [] as $index => $child) {
            if (is_array($child)) {
                $other = $actualChildren[$index] ?? null;
                self::textDifferences($child, is_array($other) ? $other : null, $path . '/children/' . $index, $differences);
            }
        }
    }

    /**
     * @param array<mixed> $expected
     * @param array<mixed>|null $actual
     */
    private static function firstTextDifference(array $expected, ?array $actual): ?int
    {
        $value = is_string($expected['value'] ?? null) ? $expected['value'] : '';
        $other = ($actual['type'] ?? '') === 'text' && is_string($actual['value'] ?? null) ? $actual['value'] : '';
        $at = 0;
        $end = min(strlen($value), strlen($other));
        while ($at < $end && $value[$at] === $other[$at]) {
            $at++;
        }

        return $at < strlen($value) && Text::isPunct($value[$at]) ? $at : null;
    }
}
