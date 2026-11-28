<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer\Check;

/**
 * The block writer the rounds write a group with (carve-js `writeBlocks` into `w.lines`): the blocks of the group in
 * their current spelling, with the escapes and forms of the writer state.
 */
interface GroupWriter
{
    /**
     * Writes `$group` into `$w->lines` and records the place of each paragraph it writes in `$w->starts`.
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     */
    public function write(WriterState $w, array $group): void;

    /**
     * C2-W-146: the escapes the pair rules of lint ask for in `$group` as it is written now, beside those placed.
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param list<\MarkupCarve\Carve\Writer\Check\Node> $group
     *
     * @return list<\MarkupCarve\Carve\Writer\Check\Escape>
     */
    public function pairEscapes(WriterState $w, array $group): array;
}
