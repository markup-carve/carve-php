<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import\Hub;

use League\CommonMark\Node\Block\AbstractBlock;

/**
 * A container directive of `micromark-extension-directive`: `:::name[label]{attributes}` up to a closing `:::`.
 */
final class Directive extends AbstractBlock
{
    /**
     * @param string $name
     * @param array<array{string, string}> $attributes In source order; a repeated key keeps its last value, classes add up.
     */
    public function __construct(public readonly string $name, public readonly array $attributes)
    {
        parent::__construct();
    }
}
