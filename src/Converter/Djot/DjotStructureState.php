<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\Djot;

/**
 * Mutable container and note state for one Djot structure pass.
 *
 * @internal
 */
final class DjotStructureState
{
    /**
     * @var list<string>
     */
    public array $out = [];

    /**
     * @var list<\MarkupCarve\Carve\Converter\Djot\ListContext>
     */
    public array $lists = [];

    /**
     * @var list<array{width: int, prefix: string, invalid: bool, depth: int, start: int, itemColumn?: int}>
     */
    public array $divs = [];

    /**
     * @var array<int, array{owner: int, depth: int, row: int, attributes: string}>
     */
    public array $pendingAttributes = [];

    /**
     * @var array<int, string>
     */
    public array $returnedNoteItemAttributes = [];

    public ?int $returnedNoteDestinationDepth = null;

    public bool $paragraph = false;

    public bool $blank = true;

    public int $depth = 0;

    public EmbeddedListStack $embeddedOwners;

    public ?ListContext $embeddedListOwner = null;

    /**
     * @var array{depth: int, column: int, content: int}|null
     */
    public ?array $opaqueQuoteOwner = null;

    public string $quoteBoundary = '';

    public string $headingMarker = '';

    public int $definitionIndent = -1;

    public ?NoteContext $noteOwner = null;

    public NoteStack $notes;

    /**
     * @var list<array{body: list<string>, placement: int|null}>
     */
    public array $completedNotes = [];

    public function __construct()
    {
        $this->embeddedOwners = new EmbeddedListStack();
        $this->notes = new NoteStack();
    }
}
