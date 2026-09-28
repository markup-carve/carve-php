<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

/**
 * State owned by one document parse.
 *
 * @internal
 */
final class BlockParseSession
{
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
     * Paragraphs whose FIRST LINE sat above their container's content column.
     *
     * A block image is a top-level block construct, so PART 9 section 15's
     * strict column-0 rule reaches it: an INDENTED lone image is a paragraph
     * holding an inline image, never a block image (markup-carve/carve#1660).
     * {@see \MarkupCarve\Carve\Parser\BlockParser::promoteBlockImages()} is the only reader.
     *
     * PARSER-LOCAL, keyed by object id like `$unplaceableNodeIds` above, rather
     * than a property on `Paragraph`: {@see \MarkupCarve\Carve\Ast\AstCodec}
     * publishes every non-static property a node declares, so a flag on the node
     * would put a parse internal on the wire that no other engine emits.
     *
     * @var array<int, true>
     */
    public array $paragraphsAboveContentColumn = [];

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
}
