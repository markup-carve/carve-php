<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer\Check;

/**
 * One comparison of written blocks with their parse: what it asks the next write to change (carve-js `Check`,
 * write-check.ts). Sets of escapes are keyed by `spl_object_id` of the `Escape`, sets of nodes by that of the `Node`.
 */
final class Check
{
    /**
     * @var list<\MarkupCarve\Carve\Writer\Check\Escape>
     */
    public array $escapes = [];

    /**
     * @var list<\MarkupCarve\Carve\Writer\Check\Escape> Escapes of an earlier round that did not help and are taken back (C2-W-088).
     */
    public array $retracted = [];

    /**
     * @var list<\MarkupCarve\Carve\Writer\Check\Escape> The escapes a node that did not form asked for (C2-W-125 trials).
     */
    public array $trials = [];

    /**
     * @var array<int, array<int, bool>> The escapes asked for and taken back, by text node id and offset.
     */
    public array $marks = [];

    /**
     * @var list<\MarkupCarve\Carve\Writer\Check\Node>
     */
    public array $forms = [];

    /**
     * @var array<int, \MarkupCarve\Carve\Writer\Check\Node>
     */
    public array $respelled = [];

    public bool $misaligned = false;

    /**
     * @var list<array{\MarkupCarve\Carve\Writer\Check\Node, string|null}> What no change repairs.
     */
    public array $losses = [];

    /**
     * The flags the content under comparison was written with (`Spell::IN_*`).
     */
    public int $flags = 0;

    /**
     * C2-A-093: the counts of a cell are a resolution result in a table that has span marks.
     */
    public bool $spanMarks = false;

    public ?Node $owner = null;

    /**
     * Escapes that open a run of escapes (`extendChains`), with the construct each stops.
     *
     * @var list<array{\MarkupCarve\Carve\Writer\Check\Node, int, \MarkupCarve\Carve\Writer\Check\Node}>
     */
    public array $chains = [];

    /**
     * The written group the parse under comparison was made of, or null in a probe of part of it.
     */
    public ?string $source = null;

    /**
     * @var array<int, array<int, true>> C2-W-088: the escapes among `escapes` that make a line opener text, by text node id.
     */
    public array $lineEscapes = [];

    /**
     * @var array<int, array<int, true>> C2-W-088: block openers inside a line, by text node id.
     */
    public array $ownEscapes = [];

    public bool $cut = false;

    /**
     * @var array<int, \MarkupCarve\Carve\Writer\Check\Escape>
     */
    public array $leadTrials = [];

    /**
     * @var array<int, \MarkupCarve\Carve\Writer\Check\Escape>
     */
    public array $codeTrials = [];

    /**
     * @var array<int, \MarkupCarve\Carve\Writer\Check\Escape>
     */
    public array $closers = [];

    /**
     * @var array<int, \MarkupCarve\Carve\Writer\Check\Escape>
     */
    public array $readOn = [];

    /**
     * @var list<array{\MarkupCarve\Carve\Writer\Check\Node, \MarkupCarve\Carve\Writer\Check\Escape}>
     */
    public array $earlyClosers = [];

    /**
     * @var list<array{list<\MarkupCarve\Carve\Writer\Check\Node>, int, int}> The inline nodes around the content, innermost last.
     */
    public array $around = [];

    /**
     * @var array<int, \MarkupCarve\Carve\Writer\Check\Escape>
     */
    public array $reported = [];

    /**
     * @var array<int, \MarkupCarve\Carve\Writer\Check\Escape> The escapes of openers of constructs the parse holds and the tree does not.
     */
    public array $constructs = [];

    /**
     * @var array<int, \MarkupCarve\Carve\Writer\Check\Escape>
     */
    public array $behind = [];

    /**
     * @var array<int, \MarkupCarve\Carve\Writer\Check\Escape>
     */
    public array $lengthening = [];

    /**
     * @var array<int, \MarkupCarve\Carve\Writer\Check\Escape>
     */
    public array $pairedLengthening = [];

    /**
     * C2-I-118: the capped openers escaped, with the end of their construct.
     *
     * @var list<array{\MarkupCarve\Carve\Writer\Check\Node, int, int}>
     */
    public array $capped = [];

    public bool $earlyCloser = false;

    /**
     * @var array<int, array<int, true>>
     */
    public array $notesDone = [];

    public ?Node $note = null;

    /**
     * @var list<\MarkupCarve\Carve\Writer\Check\Node>
     */
    public array $parsed = [];

    public int $parsedAt = 0;

    public bool $formsAround = false;

    public function __construct(public WriterState $w)
    {
    }

    /**
     * carve-js `changes`.
     */
    public function changes(): int
    {
        return count($this->escapes) + count($this->forms) + count($this->retracted);
    }

    /**
     * The last escape asked for.
     */
    public function last(): Escape
    {
        return $this->escapes[count($this->escapes) - 1];
    }

    /**
     * @param array<int, \MarkupCarve\Carve\Writer\Check\Escape> $set
     * @param \MarkupCarve\Carve\Writer\Check\Escape $escape
     */
    public static function add(array &$set, Escape $escape): void
    {
        $set[spl_object_id($escape)] = $escape;
    }

    /**
     * @param array<int, \MarkupCarve\Carve\Writer\Check\Escape> $set
     * @param \MarkupCarve\Carve\Writer\Check\Escape $escape
     */
    public static function has(array $set, Escape $escape): bool
    {
        return isset($set[spl_object_id($escape)]);
    }
}
