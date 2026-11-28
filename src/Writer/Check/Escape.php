<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer\Check;

/**
 * A backslash before the character at byte `at` of the text `node`. carve-js keeps an escape as a `[TextNode, number]`
 * tuple and tests sets of them by identity, so each escape is an object here too.
 */
final class Escape
{
    public function __construct(public readonly Node $node, public readonly int $at)
    {
    }
}
