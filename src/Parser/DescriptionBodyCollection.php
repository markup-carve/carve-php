<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

/**
 * State carried across the source lines of one definition description body.
 *
 * @internal
 */
final class DescriptionBodyCollection
{
    /**
     * @var array{done: int, open: bool, fence: int|null, alt: bool}|null
     */
    public ?array $termScan = null;

    public CollectedCommentSpan $commentSpan;

    public IndexedFenceView $bodyFenceView;

    public ?int $lastOpenerKey = null;

    public ?bool $lastOpenerIsParagraph = null;

    /**
     * Whether a FORM A line has been pushed since the last blank.
     *
     * Past-the-column laziness is about a line following the body's own
     * paragraph; once an indented block is open, its own indentation governs
     * the lines under it.
     */
    public bool $formABlockOpen = false;

    public TrailingBlockState $bodyState;

    public NestedLeadFenceState $nestedLeadFence;

    public int $bodyStateCursor = 0;

    /**
     * Authored base of the block the body tracker has open.
     */
    public ?int $bodyOpenerBase = null;

    /**
     * @var array<int, true> Body indexes collected BELOW the content column.
     */
    public array $bodyLazy = [];

    /**
     * @var array<int, true> Entries that close the body's paragraph.
     */
    public array $bodyDefinition = [];

    /**
     * @var array<int, array{index: int, opener: array{fence: string, length: int, char?: string}, columns: int, nested: bool, eligible: bool, paragraph: bool}> Fence-shaped body entries, by body index.
     */
    public array $bodyFenceSource = [];

    public ?int $nestedDescriptionBodyColumn = null;

    /**
     * Whether the open fence interrupted a paragraph on a closer the body cannot see.
     */
    public bool $bodyInterruptedParagraphFence = false;

    public TrailingBlockState $bodyNestedState;

    public int $bodyNestedCursor = 0;

    public int $bodyAttributeThrough = -1;

    public bool $bodyEndsWithAttribute = false;

    public bool $nestedAttributeClosed = false;

    public bool $nestedLazyParagraph = false;

    public bool $retainedBelowComment = false;

    /**
     * The body ended at the line after a comment below its column.
     */
    public bool $closedByComment = false;

    public ?int $restoredDivLength = null;

    /**
     * @var array<int, string>|null
     */
    public ?array $detachedFootnote = null;

    /**
     * @var array<int, int>
     */
    public array $detachedFootnoteMap = [];

    public bool $bodyAtColumnBlock = false;

    public bool $attachedDefinitionListOpen = false;

    public DefinitionBodyFrameState $bodyFrames;

    /**
     * @var array<int, string>
     */
    public array $preReleaseListEntries = [];

    /**
     * @var array<int, int>
     */
    public array $syntheticTermColumns = [];

    public ?int $reachedTermColumn = null;

    public ?int $noteBodyColumn = null;

    /**
     * @param array<string> $lines
     * @param int $i
     * @param int $count
     * @param array<int, string> $body
     * @param array<int, int> $bodyMap
     * @param int $continuationColumn
     * @param \MarkupCarve\Carve\Parser\IndentedContainerColumn $bodyColumn
     * @param int $definitionStart
     * @param int|null $definitionColumn
     * @param string $authoredDefinition
     * @param bool $descriptionAtDocumentOrQuote
     * @param int|null $termColumn
     * @param bool $termHasOwnColumn
     */
    public function __construct(
        public array $lines,
        public int $i,
        public readonly int $count,
        public array $body,
        public array $bodyMap,
        public readonly int $continuationColumn,
        public readonly IndentedContainerColumn $bodyColumn,
        public readonly int $definitionStart,
        public readonly ?int $definitionColumn,
        public readonly string $authoredDefinition,
        public readonly bool $descriptionAtDocumentOrQuote,
        public readonly ?int $termColumn,
        public readonly bool $termHasOwnColumn,
    ) {
        $this->commentSpan = new CollectedCommentSpan();
        $this->bodyFenceView = new IndexedFenceView();
        $this->bodyState = new TrailingBlockState();
        $this->nestedLeadFence = new NestedLeadFenceState();
        $this->bodyNestedState = new TrailingBlockState();
        $this->bodyFrames = new DefinitionBodyFrameState();
    }

    /**
     * The body's first entry, or `''` while it has none.
     */
    public function lead(): string
    {
        return $this->body[0] ?? '';
    }
}
