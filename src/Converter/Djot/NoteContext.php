<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\Djot;

final class NoteContext
{
    public ?int $fenceColumn = null;

    /**
     * @var list<string>
     */
    public array $pendingAttributes = [];

    public string $pendingPrefix = '  ';

    public string $pendingParentPrefix = '';

    /**
     * @param int $depth
     * @param int $column
     * @param list<string> $parentOut
     * @param list<\MarkupCarve\Carve\Converter\Djot\ListContext> $parentLists
     * @param list<array{width: int, prefix: string, invalid: bool, depth: int, start: int, itemColumn?: int}> $parentDivs
     * @param \MarkupCarve\Carve\Converter\Djot\ListContext|null $parentEmbedded
     * @param string $parentBoundary
     * @param int $parentDepth
     * @param array{depth: int, column: int, content: int}|null $parentOpaque
     * @param int|null $placement
     * @param \MarkupCarve\Carve\Converter\Djot\EmbeddedListStack $parentEmbeddedOwners
     * @param int $parentDefinition
     */
    public function __construct(
        public readonly int $depth,
        public readonly int $column,
        public readonly array $parentOut,
        public readonly array $parentLists,
        public readonly array $parentDivs,
        public readonly int $parentDepth,
        public readonly string $parentBoundary,
        public readonly ?ListContext $parentEmbedded,
        public readonly ?array $parentOpaque,
        public readonly int $parentDefinition,
        public readonly ?int $placement,
        public readonly EmbeddedListStack $parentEmbeddedOwners,
    ) {
    }
}
