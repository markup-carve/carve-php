<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use ReflectionMethod;

/**
 * Protected property compatibility for existing BlockParser subclasses.
 * Internal parsing uses BlockParserState directly.
 *
 * @internal
 */
trait LegacyBlockParserProperties
{
    /**
     * @var array<string, \MarkupCarve\Carve\Parser\ReferenceDefinition>
     */
    protected array $references = [];

    /**
     * @var array<string, \MarkupCarve\Carve\Parser\ReferenceDefinition>
     */
    protected array $headingReferencesByFoldedLabel = [];

    /**
     * @var array<string, \MarkupCarve\Carve\Node\Block\Footnote>
     */
    protected array $footnotes = [];

    /**
     * @var array<string, \MarkupCarve\Carve\Ast\SourceSpan>
     */
    protected array $footnoteDefinitionSpans = [];

    /**
     * @var array<string, bool>
     */
    protected array $footnoteDefinitionPrefixed = [];

    /**
     * @var array<int, true> Nodes reassembled from discontiguous source.
     */
    protected array $unplaceableNodeIds = [];

    /**
     * @var array<int, true>
     */
    protected array $paragraphsAboveContentColumn = [];

    /**
     * @var array<string, string>
     */
    protected array $abbreviations = [];

    /**
     * @var array<int, array<string, string>>
     */
    protected array $abbreviationDefinitions = [];

    protected bool $abbreviationsBeforeBody = false;

    /**
     * @var array<string, array<string, int>>
     */
    protected array $abbreviationSpans = [];

    /**
     * @var array<string, true>
     */
    protected array $unresolvedReferenceLabels = [];

    protected bool $unresolvedReferenceLabelUnknown = false;

    /**
     * @var array<string, string|list<string>>
     */
    protected array $pendingAttributes = [];

    /**
     * @var list<string>
     */
    protected array $pendingAttributeOrder = [];

    /**
     * @var array<\MarkupCarve\Carve\Exception\ParseWarning>
     */
    protected array $warnings = [];

    /**
     * @var array<string, int> Maps reference label to line where used
     */
    protected array $usedReferences = [];

    /**
     * @var array<array{fragment: string, line: int, column: int}>
     */
    protected array $anchorLinks = [];

    /**
     * @var array<string, true>
     */
    protected array $headingIds = [];

    protected int $lineOffset = 0;

    /**
     * @var array<int, true>
     */
    protected array $blockQuoteLazySourceLines = [];

    protected bool $sawUnresolvedCollapsedReference = false;

    /**
     * @var array<int, int>|null
     */
    protected ?array $currentLineMap = null;

    /**
     * @var array<int, int>
     */
    protected array $currentContentColumns = [];

    /**
     * @var array<int, int>|null Fence length => LAST index carrying that fence.
     */
    protected ?array $commentFenceLastIndex = null;

    /**
     * @var array<int, int>|null
     */
    protected ?array $blockQuoteCommentCloserIndex = null;

    /**
     * @var array<int, int>
     */
    protected array $lineStartOffsets = [];

    protected string $originalSource = '';

    /**
     * @var array<int, string>
     */
    protected array $sourceLines = [];

    protected string $normalizedSource = '';

    protected ?PositionIndex $positionIndex = null;

    /**
     * @var bool
     */
    protected bool $trackPositions = false;

    /**
     * @var bool
     */
    protected bool $trackSourceLines = false;

    private function bindLegacySession(): void
    {
        if (static::class === BlockParser::class) {
            return;
        }

        $this->references =&$this->state->session->references;
        $this->headingReferencesByFoldedLabel =&$this->state->session->headingReferencesByFoldedLabel;
        $this->footnotes =&$this->state->session->footnotes;
        $this->footnoteDefinitionSpans =&$this->state->session->footnoteDefinitionSpans;
        $this->footnoteDefinitionPrefixed =&$this->state->session->footnoteDefinitionPrefixed;
        $this->unplaceableNodeIds =&$this->state->session->unplaceableNodeIds;
        $this->paragraphsAboveContentColumn =&$this->state->session->paragraphsAboveContentColumn;
        $this->abbreviations =&$this->state->session->abbreviations;
        $this->abbreviationDefinitions =&$this->state->session->abbreviationDefinitions;
        $this->abbreviationsBeforeBody =&$this->state->session->abbreviationsBeforeBody;
        $this->abbreviationSpans =&$this->state->session->abbreviationSpans;
        $this->unresolvedReferenceLabels =&$this->state->session->unresolvedReferenceLabels;
        $this->unresolvedReferenceLabelUnknown =&$this->state->session->unresolvedReferenceLabelUnknown;
        $this->pendingAttributes =&$this->state->session->pendingAttributes;
        $this->pendingAttributeOrder =&$this->state->session->pendingAttributeOrder;
        $this->warnings =&$this->state->session->warnings;
        $this->usedReferences =&$this->state->session->usedReferences;
        $this->anchorLinks =&$this->state->session->anchorLinks;
        $this->headingIds =&$this->state->session->headingIds;
        $this->lineOffset =&$this->state->session->lineOffset;
        $this->blockQuoteLazySourceLines =&$this->state->session->blockQuoteLazySourceLines;
        $this->sawUnresolvedCollapsedReference =&$this->state->session->sawUnresolvedCollapsedReference;
    }

    private function bindLegacyFrame(): void
    {
        if (static::class === BlockParser::class) {
            return;
        }

        $this->currentLineMap =&$this->state->frame->currentLineMap;
        $this->currentContentColumns =&$this->state->frame->currentContentColumns;
        $this->commentFenceLastIndex =&$this->state->frame->commentFenceLastIndex;
        $this->blockQuoteCommentCloserIndex =&$this->state->frame->blockQuoteCommentCloserIndex;
    }

    private function bindLegacySource(): void
    {
        if (static::class === BlockParser::class) {
            return;
        }

        $this->lineStartOffsets =&$this->state->source->lineStartOffsets;
        $this->originalSource =&$this->state->source->originalSource;
        $this->sourceLines =&$this->state->source->sourceLines;
        $this->normalizedSource =&$this->state->source->normalizedSource;
        $this->positionIndex =&$this->state->source->positionIndex;
        $this->trackPositions =&$this->state->source->trackPositions;
        $this->trackSourceLines =&$this->state->source->trackSourceLines;
    }

    /**
     * @var array<class-string, array<string, bool>>
     */
    private static array $legacyTrailingHooks = [];

    private function usesLegacyTrailingHook(string $method): bool
    {
        if (static::class === BlockParser::class) {
            return false;
        }

        // Keep array conversion confined to overridden legacy hooks.
        return self::$legacyTrailingHooks[static::class][$method] ??= (new ReflectionMethod(static::class, $method))
            ->getDeclaringClass()->getName() !== BlockParser::class;
    }
}
