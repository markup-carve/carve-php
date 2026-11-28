<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer\Check;

/**
 * What the writer of a group shares with the writers of its probes: carve-js copies its `Writer` with a spread, so
 * these maps and the work count stay one object across every probe level, while a `WriterState` clone does not.
 *
 * @phpstan-type ProbeTaken list<array{\MarkupCarve\Carve\Writer\Check\Node, int}>
 * @phpstan-type Probe array{found: bool|null, taken: ProbeTaken, leads: ProbeTaken}
 */
final class Shared
{
    /**
     * @var array<string, bool> Whether a line written alone opens a block, by line.
     */
    public array $lineOpens = [];

    /**
     * @var array<string, Probe>
     */
    public array $probes = [];

    /**
     * @var array<int, array<string, \MarkupCarve\Carve\Writer\Check\Node>> The stand-in texts of a probe, by anchor node.
     */
    public array $standIns = [];

    /**
     * @var array<int, int> The id of each node in the keys of the probes.
     */
    public array $nodeIds = [];

    /**
     * @var list<array{\MarkupCarve\Carve\Writer\Check\Node, int}> C2-I-118: the escapes placed behind a capped opener.
     */
    public array $cappedRuns = [];

    /**
     * @var array<int, string> The content key of each node `extendClosers` compared, by node id.
     */
    public array $contentKeys = [];

    /**
     * @var array<string, true> C2-W-088: the braced kinds a round reported, `{` and its mark.
     */
    public array $reportedBraceKinds = [];

    public int $parses = 0;

    /**
     * C2-W-133: the characters the repair of the group has parsed, and its limit.
     */
    public int $chars = 0;

    public int $limit = PHP_INT_MAX;

    /**
     * Keeps the stand-ins and mirrored nodes alive, so no `spl_object_id` key is reused within one write.
     *
     * @var list<\MarkupCarve\Carve\Writer\Check\Node>
     */
    public array $held = [];
}
