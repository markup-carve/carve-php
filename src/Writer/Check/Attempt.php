<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer\Check;

/**
 * The respelling of one node over the rounds: the next step of `RESPELL_STEPS` and the escapes the steps so far asked
 * for (carve-js `Attempt`).
 */
final class Attempt
{
    /**
     * @param int $step
     * @param list<\MarkupCarve\Carve\Writer\Check\Escape> $escapes
     * @param \MarkupCarve\Carve\Writer\Check\Node|null $node The node respelled, which a carve-js Map key carries.
     */
    public function __construct(public int $step = 0, public array $escapes = [], public ?Node $node = null)
    {
    }
}
