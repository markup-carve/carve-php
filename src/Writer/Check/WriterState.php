<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer\Check;

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
