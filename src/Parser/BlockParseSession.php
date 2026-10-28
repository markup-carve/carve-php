<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use MarkupCarve\Carve\Ast\SourceSpan;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\ListItem;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Parser\Block\ListParser;
use WeakMap;

/**
 * State owned by one document parse.
 *
 * @internal
 */
final class BlockParseSession
{
    /**
     * Physical opener column of a folded fence inside a parsed paragraph.
     *
     * @var \WeakMap<\MarkupCarve\Carve\Node\Block\Paragraph, int>|null
     */
    private ?WeakMap $foldedFenceColumns = null;

    /**
     * Authored content column of each parsed list item.
     *
     * @var \WeakMap<\MarkupCarve\Carve\Node\Block\ListItem, int>|null
     */
    private ?WeakMap $listItemContentColumns = null;

    public function recordListItemContentColumn(ListItem $item, int $column): void
    {
        $this->listItemContentColumns ??= new WeakMap();
        $this->listItemContentColumns[$item] = $column;
    }

    public function listItemContentColumn(ListItem $item): ?int
    {
        return $this->listItemContentColumns[$item] ?? null;
    }

    public function recordFoldedFenceColumn(Paragraph $paragraph, int $column): void
    {
        $this->foldedFenceColumns ??= new WeakMap();
        $this->foldedFenceColumns[$paragraph] = $column;
    }

    public function foldedFenceColumn(Paragraph $paragraph): ?int
    {
        return $this->foldedFenceColumns[$paragraph] ?? null;
    }

    /**
     * @var \WeakMap<\MarkupCarve\Carve\Node\Block\Paragraph, bool>|null
     */
    private ?WeakMap $foldedCloserBelowBase = null;

    public function recordFoldedCloserBelowBase(Paragraph $paragraph): void
    {
        $this->foldedCloserBelowBase ??= new WeakMap();
        $this->foldedCloserBelowBase[$paragraph] = true;
    }

    public function foldedCloserBelowBase(Paragraph $paragraph): bool
    {
        return $this->foldedCloserBelowBase[$paragraph] ?? false;
    }

    /**
     * @var \WeakMap<\MarkupCarve\Carve\Node\Block\Div, int>|null
     */
    private ?WeakMap $divOpenerColumns = null;

    public function recordDivOpenerColumn(Div $div, int $column): void
    {
        $this->divOpenerColumns ??= new WeakMap();
        $this->divOpenerColumns[$div] = $column;
    }

    public function divOpenerColumn(Div $div): ?int
    {
        return $this->divOpenerColumns[$div] ?? null;
    }

    /**
     * @var \WeakMap<\MarkupCarve\Carve\Node\Block\Paragraph, int>|null
     */
    private ?WeakMap $admittedBlockBelowFoldedFenceColumns = null;

    public function recordAdmittedBlockBelowFoldedFenceColumn(Paragraph $paragraph, int $column): void
    {
        $this->admittedBlockBelowFoldedFenceColumns ??= new WeakMap();
        $this->admittedBlockBelowFoldedFenceColumns[$paragraph] = $column;
    }

    public function admittedBlockBelowFoldedFenceColumn(Paragraph $paragraph): ?int
    {
        return $this->admittedBlockBelowFoldedFenceColumns[$paragraph] ?? null;
    }

    /**
     * Content column of a parsed nested list, keyed by its parent item.
     *
     * @var \WeakMap<\MarkupCarve\Carve\Node\Block\ListItem, int>|null
     */
    private ?WeakMap $parsedNestedListContentColumns = null;

    public function recordParsedNestedListContentColumn(ListItem $item, int $column): void
    {
        $this->parsedNestedListContentColumns ??= new WeakMap();
        $this->parsedNestedListContentColumns[$item] = $column;
    }

    public function parsedNestedListContentColumn(ListItem $item): ?int
    {
        return $this->parsedNestedListContentColumns[$item] ?? null;
    }

    private ?ContinuationMarkerClassifier $continuationMarkers = null;

    /**
     * @var \WeakMap<\MarkupCarve\Carve\Node\Block\ListItem, bool>|null
     */
    private ?WeakMap $itemsWithParsedBlankBeforeParagraph = null;

    public function recordParsedBlankBeforeParagraph(ListItem $item): void
    {
        $this->itemsWithParsedBlankBeforeParagraph ??= new WeakMap();
        $this->itemsWithParsedBlankBeforeParagraph[$item] = true;
    }

    public function itemHasParsedBlankBeforeParagraph(ListItem $item): bool
    {
        return $this->itemsWithParsedBlankBeforeParagraph[$item] ?? false;
    }

    /**
     * @return array{owner: array{kind: string, column: int, depth: int, content: int, first: bool}|null, reason: string|null, column: int, depth: int, stale: bool, open: bool}
     */
    public function continuationMarkerDecision(BlockSourceState $source, ListParser $lists, int $line): array
    {
        $this->continuationMarkers ??= new ContinuationMarkerClassifier($source->sourceLines, $lists);

        return $this->continuationMarkers->decision($line);
    }

    /**
     * @return array{kind: string, column: int, depth: int, content: int, first: bool}|null
     */
    public function firstBlockMarkerOwner(BlockSourceState $source, ListParser $lists, int $line): ?array
    {
        $this->continuationMarkers ??= new ContinuationMarkerClassifier($source->sourceLines, $lists);

        return $this->continuationMarkers->firstBlockOwner($line);
    }

    public function plusAtHostContentColumn(BlockSourceState $source, ListParser $lists, int $line): bool
    {
        $this->continuationMarkers ??= new ContinuationMarkerClassifier($source->sourceLines, $lists);

        return $this->continuationMarkers->atHostContentColumn($line);
    }

    public function innermostListColumnBefore(BlockSourceState $source, ListParser $lists, int $line): ?int
    {
        $this->continuationMarkers ??= new ContinuationMarkerClassifier($source->sourceLines, $lists);

        return $this->continuationMarkers->innermostListColumnBefore($line);
    }

    public function footnoteColumnBefore(BlockSourceState $source, ListParser $lists, int $line): ?int
    {
        $this->continuationMarkers ??= new ContinuationMarkerClassifier($source->sourceLines, $lists);

        return $this->continuationMarkers->footnoteColumnBefore($line);
    }

    /**
     * @var list<array{line: int, column: int, marker: string, endLine: int, reason: string}>
     */
    public array $unclosedCodeFences = [];

    /**
     * @var list<array{line: int, column: int, host: string, reason: string}>
     */
    public array $refusedContinuationMarkers = [];

    /**
     * @var array<int, array{line: int, column: int, host: string, reason: string}>
     */
    public array $unclaimedDocumentMarkers = [];

    /**
     * The opener line and `>` offsets of quotes a stacked `+` nests, handed from collection to span assignment.
     *
     * @var array{0: int, 1: array<int>}|null
     */
    public ?array $nestedQuoteOpenerOffsets = null;

    public function recordRefusedContinuationMarker(BlockSourceState $source, int $line, string $host): void
    {
        $next = $source->sourceLines[$line + 1] ?? null;
        if ($next === '' && $line + 1 === array_key_last($source->sourceLines)) {
            $next = null;
        }
        if ($next !== null && trim($next) !== '') {
            return;
        }
        $physical = $source->sourceLines[$line] ?? '';
        $plus = strpos($physical, '+');
        if ($plus === false) {
            return;
        }
        $this->refusedContinuationMarkers[] = [
            'line' => $line + 1,
            'column' => mb_strlen(substr($physical, 0, $plus), 'UTF-8') + 1,
            'host' => $host,
            'reason' => $next === null ? 'document' : 'blank',
        ];
    }

    /**
     * @var array<string, \MarkupCarve\Carve\Parser\ReferenceDefinition>
     */
    public array $references = [];

    /**
     * Heading-derived references keyed by folded heading text. Used only for
     * unresolved collapsed references (`[text][]`), after exact definitions lose.
     *
     * @var array<string, \MarkupCarve\Carve\Parser\ReferenceDefinition>
     */
    public array $headingReferencesByFoldedLabel = [];

    /**
     * @var array<string, \MarkupCarve\Carve\Node\Block\Footnote>
     */
    public array $footnotes = [];

    /**
     * Where each footnote definition LINE was written, keyed by label.
     *
     * Kept beside the definitions because a definition's extent is otherwise
     * derived from its body, and `[^f]: {empty}` has no body to derive from -
     * so that node reached the wire with no `pos` at all, the one node in the
     * spec corpus PART 12 §4 requires to carry one and this engine did not
     * publish (markup-carve/carve#1023). Recorded for every definition and
     * READ only for a childless one, so a definition with content keeps the
     * extent its body already gives it.
     *
     * Empty unless position tracking is on: §4 makes positions opt-in.
     *
     * @var array<string, \MarkupCarve\Carve\Ast\SourceSpan>
     */
    public array $footnoteDefinitionSpans = [];

    /**
     * Which recorded definitions were written behind a CONTAINER PREFIX.
     *
     * A definition at column 0 owns the blank line below it - nothing else
     * does, and its body may resume under that blank. A definition inside a
     * quote, a list item or a `dd` does not: the blank line that follows the
     * container is outside the container, so reaching into it puts the
     * definition's span past the end of the block that holds it. See
     * `extendFootnoteDefinitionToLineStart()`.
     *
     * @var array<string, bool>
     */
    public array $footnoteDefinitionPrefixed = [];

    /**
     * @var array<int, true> Nodes reassembled from discontiguous source.
     */
    public array $unplaceableNodeIds = [];

    /**
     * Abbreviation definitions: maps abbreviation text to its definition
     *
     * @var array<string, string>
     */
    public array $abbreviations = [];

    /**
     * Every authored abbreviation definition line in source order, shadowed
     * ones kept.
     *
     * @var array<int, array<string, string>>
     */
    public array $abbreviationDefinitions = [];

    public bool $abbreviationsBeforeBody = false;

    /**
     * @var array<string, array<string, int>>
     */
    public array $abbreviationSpans = [];

    /**
     * @var array<string, array{lines: array<string>, lineMap: array<int, int>}>
     */
    public array $discoveredFootnoteBodies = [];

    /**
     * @var array<int, true>
     */
    public array $discoveredAbbreviationLines = [];

    /**
     * The authoritative structural walk is currently collecting mixed
     * definitions. Inline nodes are built normally; forward-only resolution is
     * completed after the walk exposes every definition.
     */
    public bool $discoveringDefinitions = false;

    /**
     * Folded labels of the references that found no definition, in the key
     * space the heading index uses.
     *
     * A heading can only rescue a reference that NAMES it, so this is what the
     * second pass is filtered by (carve-php#2245).
     *
     * @var array<string, true>
     */
    public array $unresolvedReferenceLabels = [];

    /**
     * An unresolved reference arrived without its label, so which headings
     * could rescue it is unknown and every one of them has to be tried.
     */
    public bool $unresolvedReferenceLabelUnknown = false;

    /**
     * Pending block attributes to apply to next block
     *
     * @var array<string, string|list<string>>
     */
    public array $pendingAttributes = [];

    /**
     * Pending block attribute source slots.
     *
     * @var list<string>
     */
    public array $pendingAttributeOrder = [];

    /**
     * Collected warnings during parsing
     *
     * @var array<\MarkupCarve\Carve\Exception\ParseWarning>
     */
    public array $warnings = [];

    /**
     * References that have been used (for validation)
     * Only populated when collectWarnings is true
     *
     * @var array<string, int> Maps reference label to line where used
     */
    public array $usedReferences = [];

    /**
     * Anchor links found during parsing (for validation)
     * Only populated when collectWarnings is true
     *
     * @var array<array{fragment: string, line: int, column: int}>
     */
    public array $anchorLinks = [];

    /**
     * Heading IDs generated during heading reference extraction
     * Used for anchor link validation
     *
     * @var array<string, true>
     */
    public array $headingIds = [];

    /**
     * Current line offset for nested parsing (0-indexed internally, 1-indexed for errors)
     */
    public int $lineOffset = 0;

    /**
     * Source lines admitted into a block quote only by lazy continuation.
     *
     * They carry no quote marker, so a column inside the quoted content cannot
     * claim them. A nested list may still fold one into its deepest open
     * paragraph, but must not re-read a definition-shaped line as a block at
     * the list's content column (markup-carve/carve#1384).
     *
     * @var array<int, true>
     */
    public array $blockQuoteLazySourceLines = [];

    /**
     * @var array<int, true> Code-shaped lines owned by a quoted inline body.
     */
    public array $blockQuoteInlineFenceSourceLines = [];

    /**
     * True when a reference failed to resolve during the parse, in either the
     * collapsed `[text][]` or the explicit `[text][Label]` form. Both can
     * still land on a heading, and the heading index is built from the parsed
     * tree, so it does not exist yet. This is the trigger for the second pass;
     * a document whose references all resolved never pays for it.
     */
    public bool $sawUnresolvedCollapsedReference = false;

    /**
     * @var list<\MarkupCarve\Carve\Ast\SourceSpan>
     */
    public array $unattachedBlockAttributes = [];

    public ?SourceSpan $pendingAttributeSpan = null;
}
