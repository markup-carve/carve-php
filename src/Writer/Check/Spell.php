<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer\Check;

/**
 * The spelling of tree nodes as the writer writes them with the escapes and forms of the rounds so far: what the
 * comparison of carve-js reads from its inline writer (`writeInline`, `writeInlines`, `spelledEnd` in write-inline.ts).
 */
interface Spell
{
    /**
     * @var int
     */
    public const IN_CELL = 1;

    /**
     * @var int
     */
    public const IN_EMPHASIS = 2;

    /**
     * @var int
     */
    public const IN_STRONG = 4;

    /**
     * @var int
     */
    public const IN_BRACKET = 16;

    /**
     * @var int
     */
    public const IN_FIGURE_GROUP = 32;

    /**
     * @var int
     */
    public const IN_NOTE = 64;

    /**
     * @var int
     */
    public const AT_PAIRED_END = 128;

    /**
     * The written form of `$nodes` as one list, with the escapes the list places for its end.
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $nodes
     * @param int $flags
     */
    public function inlines(WriterState $w, array $nodes, int $flags): string;

    /**
     * The written form of `$node` alone.
     */
    public function inline(WriterState $w, Node $node, int $flags): string;

    /**
     * The offset in the text `$node` at which `$bytes` written bytes from `$from` on end, or -1 where they end inside
     * a character or its backslash (carve-js `spelledEnd`).
     */
    public function spelledEnd(WriterState $w, Node $node, int $from, int $bytes, int $flags): int;

    /**
     * The sorted offsets in the text `$node` that are written with a backslash (carve-js `escapeOffsets`).
     *
     * @return list<int>
     */
    public function escapeOffsets(WriterState $w, Node $node, int $flags): array;

    /**
     * Whether the div `$div` is written with the hard-break opener (carve-js `usesHardBreakOpener`).
     */
    public function usesHardBreakOpener(WriterState $w, Node $div): bool;
}
