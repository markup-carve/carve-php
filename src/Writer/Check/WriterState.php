<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer\Check;

use LogicException;

/**
 * What the comparison and the rounds read and change of the writer (the fields of carve-js `Writer` in write-state.ts
 * that write-check.ts uses). Maps by node are keyed by `spl_object_id` of the `Node`.
 */
final class WriterState
{
    /**
     * The columns of a place: a paragraph is written at `line * PLACE_COLUMNS + column` (carve-js `PLACE_COLUMNS`).
     *
     * @var int
     */
    public const PLACE_COLUMNS = 8192;

    public Shared $shared;

    /**
     * The parse of the last comparison.
     */
    public ?Node $parsed = null;

    /**
     * @var array<string, mixed> The parse options (extensions).
     */
    public array $options = [];

    /**
     * @var array<int, list<int>> The escapes placed so far, ascending offsets by text node id.
     */
    public array $escapes = [];

    /**
     * @var array<int, \MarkupCarve\Carve\Writer\Check\Node> The text nodes of `escapes`, by id.
     */
    public array $escaped = [];

    /**
     * @var array<int, int> The form each node is written in, by node id (carve-js `forms`).
     */
    public array $forms = [];

    /**
     * @var array<int, true> The nodes whose form the group writer glued to the text before them, by node id.
     */
    public array $glued = [];

    // The writer of the group's lines (carve-js `writeBlocks`).
    public ?GroupWriter $groupWriter = null;

    // C2-W-125 case five: the group holds link text, and its bare brackets took their backslash.
    public bool $sawLink = false;

    public bool $linkFallback = false;

    /**
     * @var array<int, array<int, true>> The backslashes `linkFallback` placed, by text node id.
     */
    public array $linkPlaced = [];

    // C2-W-005: the group is written below one blank line.
    public bool $blankFirst = false;

    public ?Check $lastCheck = null;

    // The switches of the pass (write-passes.ts): the whole-tree trial test and the closer kinds of C2-W-088.
    public bool $wholeTrials = false;

    public bool $closerKinds = false;

    /**
     * @var list<\MarkupCarve\Carve\Writer\Check\Escape>|null The trials of a `wholeTrials` pass, or null outside one.
     */
    public ?array $trialLog = null;

    public bool $trialsRetested = false;

    /**
     * @var list<array{\MarkupCarve\Carve\Writer\Check\Node, string|null}> Each loss in the order it was recorded.
     */
    public array $lossLog = [];

    /**
     * @var array<int, \MarkupCarve\Carve\Writer\Check\Attempt>
     */
    public array $attempts = [];

    /**
     * @var array<int, true> The nodes the writer reported as lost when it wrote them.
     */
    public array $lost = [];

    /**
     * @var array<int, list<string>> The attribute keys the writer reported and did not write, by node id.
     */
    public array $lostKeys = [];

    /**
     * @var array<int, string> The raw source the writer took for a node from the sidecar, by node id.
     */
    public array $raws = [];

    /**
     * @var array<int, true> The emphasis and strong nodes braced for their content or place.
     */
    public array $bracedAnyway = [];

    /**
     * @var list<string> The lines of the blocks being written.
     */
    public array $lines = [];

    /**
     * @var list<array{\MarkupCarve\Carve\Writer\Check\Node, int}> The paragraphs written and the place of each (carve-js `starts`).
     */
    public array $starts = [];

    public ?string $planWhy = null;

    public bool $kindRounds = false;

    /**
     * @var array<string, true> The keys of the link definitions of the document (carve-js `linkKeys`).
     */
    public array $linkKeys = [];

    /**
     * @var array<string, string> The abbreviation terms of the document, each with its expansion; the first wins.
     */
    public array $abbreviations = [];

    public function __construct(public Spell $spell)
    {
        $this->shared = new Shared();
    }

    /**
     * C2-W-133: whether the repair of the group has parsed more than its limit.
     */
    public function exhausted(): bool
    {
        return $this->shared->chars > $this->shared->limit;
    }

    /**
     * @return list<int>
     */
    public function escapesOf(Node $node): array
    {
        return $this->escapes[spl_object_id($node)] ?? [];
    }

    /**
     * C2-W-011, C2-W-117: records that `node`, or its field `field`, could not be written. One entry per node.
     */
    public function lose(Node $node, ?string $field): void
    {
        $id = spl_object_id($node);
        if (isset($this->lost[$id])) {
            return;
        }
        $this->lost[$id] = true;
        $this->shared->held[] = $node;
        $this->lossLog[] = [$node, $field];
    }

    /**
     * Writes `group` into `lines` with the escapes and forms of this state.
     *
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     *
     * @throws \LogicException Where no group writer is set.
     */
    public function writeGroup(array $group): void
    {
        if ($this->groupWriter === null) {
            throw new LogicException('A round writes its group through a group writer.');
        }
        $this->groupWriter->write($this, $group);
    }

    /**
     * Takes back the losses recorded after the first `count` (carve-js `rollBackLosses`).
     */
    public function rollBackLosses(int $count): void
    {
        foreach (array_slice($this->lossLog, $count) as [$node]) {
            unset($this->lost[spl_object_id($node)]);
        }
        $this->lossLog = array_slice($this->lossLog, 0, $count);
    }

    /**
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     *
     * @return list<\MarkupCarve\Carve\Writer\Check\Escape>
     */
    public function pairEscapes(array $group): array
    {
        return $this->groupWriter?->pairEscapes($this, $group) ?? [];
    }

    public function isLost(Node $node): bool
    {
        return isset($this->lost[spl_object_id($node)]);
    }

    public function form(Node $node): int
    {
        return $this->forms[spl_object_id($node)] ?? 0;
    }

    /**
     * carve-js `recordedRaw`.
     */
    public function recordedRaw(Node $node): ?string
    {
        return $this->raws[spl_object_id($node)] ?? null;
    }

    /**
     * carve-js `isBracedAnyway`.
     */
    public function isBracedAnyway(Node $node): bool
    {
        return isset($this->bracedAnyway[spl_object_id($node)]);
    }
}
