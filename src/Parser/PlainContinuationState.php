<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

/**
 * State carried across source lines of one list item.
 *
 * @internal
 */
final class PlainContinuationState
{
    public ?int $rejectedColonFenceLength = null;

    public bool $interruptedParagraphFence = false;

    /**
     * The last line was a comment below the content column: the next below-column line leaves the item.
     */
    public bool $lowComment = false;

    public ?int $authoredCodeColumn = null;

    public bool $closedVisibleBlockAtFloor = false;

    public bool $pendingAttachedAttributes = false;

    /**
     * @var array{fence: string, char: string, length: int, info: string, header: string|null, label: string|null, indent: string}|null
     */
    public ?array $attributeDivFence = null;

    public bool $attributeDivFenceClosed = false;

    public int $metadataAttributeColumn = 0;

    public ?int $metadataCodeColumn = null;

    public int $metadataCodeIndex = -1;

    /**
     * @var array{fence: string, char: string, length: int, info: string, header: string|null, label: string|null, indent: string}|array{fence: string, length: int, format: string}|null
     */
    public ?array $metadataCodeOpener = null;

    public bool $minimumFenceBase = false;

    public bool $floorParagraphWitness = false;

    public bool $floorExistingDiv = false;

    public bool $floorSawFenceState = false;

    public bool $floorLiveParagraph = false;

    public ?int $floorInlineLength = null;

    /**
     * @var array{char: string, length: int, column: int}|null
     */
    public ?array $metadataSameBaseFence = null;

    public int $metadataSameBaseFenceIndex = -1;

    public bool $inlineCodeParagraph = false;

    public ?int $inlineDefinitionBody = null;

    public int $inlineParagraphColumn = 0;

    public int $inlineFenceColumn = 0;

    public ?int $unclosedFenceColumn = null;

    /**
     * @var array<string, int>
     */
    public array $authoredFenceMisses = [];

    /**
     * @var array<string, array{start: int, end: int}>
     */
    public array $authoredFenceHits = [];

    public int $wrappedAttributeLinesRemaining = 0;

    public bool $itemOwnFenceRuns = false;

    public bool $hasDescendantList = false;

    public bool $markerNoteItem = false;

    public bool $releasedNoteMarker = false;

    public ItemBlankRunState $blankRun;

    public ?int $nestedNoteBodyColumn = null;

    public ?TrailingBlockState $nestedNoteTail = null;

    public int $nestedNoteTailColumn = 0;

    public ?int $releasedNoteParagraphColumn = null;

    public bool $sawNestedNote = false;

    public int $noteCursor = 1;

    public bool $noteBroken = false;

    public int $markerRunCursor = 1;

    /**
     * @var array<string, int>
     */
    public array $noteCloserMemo = [];

    public int $noteSourceCursor = 0;

    public int $leaveCursor = 1;

    public int $blockCursor = 1;

    public bool $blockFound = false;

    public bool $leaveBroken = false;

    public bool $noteSourceBroken = false;

    public bool $markerRunBroken = false;

    public bool $leadNoteHadBlank = false;

    public ?int $openCommentLength = null;

    /**
     * A code fence a child description's or note's marker line opened, still open.
     *
     * @var array{char: string, length: int, column: int, base: int}|null
     */
    public ?array $markerLineFence = null;

    public int $markerLineFenceCursor = 0;

    public ?int $markerLineFenceTerm = null;

    public bool $lowSpanCloser = false;

    public ?int $openDefinitionBody = null;

    /**
     * The blank line last asked whether the open description continues, and the answer.
     *
     * @var array{0: int, 1: bool}|null
     */
    public ?array $definitionBlankAnswer = null;

    /**
     * The open-term answer for the blank run ending at the first index.
     *
     * @var array{0: int, 1: bool}|null
     */
    public ?array $termBlankAnswer = null;

    public bool $pendingDefinitionTerm = false;

    public ?int $authoredTermColumn = null;

    public ?int $openTermColumn = null;

    public bool $authoredFenceOwnsLazy = false;

    /**
     * @var array{fence: string, char: string, length: int, info: string, header: string|null, label: string|null, indent: string}|array{fence: string, length: int, format: string}|null
     */
    public ?array $pendingTermFence = null;

    public bool $termCodeTailEligible = true;

    /**
     * @var array{fence: string, char: string, length: int, info: string, header: string|null, label: string|null, indent: string}|array{fence: string, length: int, format: string}|null
     */
    public ?array $termCodeTailFence = null;

    public bool $termCodeTailAfterBlank = false;

    public bool $definitionBodyHasTerm = false;

    public ?int $recentDefinitionBodyColumn = null;

    public bool $recentDefinitionParagraph = false;

    /**
     * @var array{body: int, opener: int, char: string, length: int}|null
     */
    public ?array $descriptionFence = null;

    public bool $spanFromMarkerLine = false;

    public function __construct()
    {
        $this->blankRun = new ItemBlankRunState();
    }
}
