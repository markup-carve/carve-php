<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer\Check;

/**
 * What the pass being run read and charged, so a later pass can stand in for it (carve-js `PassTrace`, write-passes.ts).
 */
final class PassTrace
{
    /**
     * The switches its rounds read (`PassMemo::READ_*`).
     */
    public int $reads = 0;

    /**
     * @var list<list<\MarkupCarve\Carve\Writer\Check\Escape>> The trials it handed to `logTrials`, in order.
     */
    public array $logged = [];

    /**
     * @var list<int> Where it found the trial log closed to a retest, as the number of `logged` entries before each check.
     */
    public array $checks = [];

    /**
     * @var list<array{string, \MarkupCarve\Carve\Writer\Check\Node|null, bool}> Its parses: the source, the tree where
     *     `parseChunk` keeps short ones, and whether the source was cacheable.
     */
    public array $charges = [];

    /**
     * @var array{int, int} The parses of rounds a chain step stood in for: their count and their characters.
     */
    public array $skipped = [0, 0];

    /**
     * @var array<int, \MarkupCarve\Carve\Writer\Check\Node> The nodes of `bracedAnyway` it found absent, by id.
     */
    public array $braced = [];

    /**
     * @var list<\MarkupCarve\Carve\Writer\Check\WholeTest>|null The trials it read `wholeTrials` for (C2-W-125), each
     *     with the writer as it stood there; null past their cap.
     */
    public ?array $wholeTests = [];
}
