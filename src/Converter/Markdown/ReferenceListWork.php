<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\Markdown;

final class ReferenceListWork
{
    /**
     * @var array<int, array<int, int>>
     */
    public array $columns = [];

    /**
     * @var array<int, bool>
     */
    public array $definitionAbove = [];

    /**
     * @var array<int, int>
     */
    public array $emptyColumns = [];

    /**
     * @var array<int, true>
     */
    public array $closedEmptyLists = [];

    public int $quoteDepth = 0;

    public ?int $definitionEnd = null;

    public bool $track = false;

    /**
     * @param array<string> $lines
     */
    public function __construct(array $lines)
    {
        foreach ($lines as $candidate) {
            if (str_contains($candidate, ']:')) {
                $this->track = true;

                break;
            }
        }
    }
}
