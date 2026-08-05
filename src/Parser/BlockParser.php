<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use Closure;
use MarkupCarve\Carve\Ast\SourceSpan;
use MarkupCarve\Carve\Exception\ParseException;
use MarkupCarve\Carve\Exception\ParseWarning;
use MarkupCarve\Carve\Node\Block\AbbreviationDefinition;
use MarkupCarve\Carve\Node\Block\BlockQuote;
use MarkupCarve\Carve\Node\Block\Caption;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Block\DefinitionDescription;
use MarkupCarve\Carve\Node\Block\DefinitionList;
use MarkupCarve\Carve\Node\Block\DefinitionTerm;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\Figure;
use MarkupCarve\Carve\Node\Block\Footnote;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\LineBlock;
use MarkupCarve\Carve\Node\Block\LinkReferenceDefinition;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\ListItem;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Block\RawBlock;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Block\TableRow;
use MarkupCarve\Carve\Node\Block\ThematicBreak;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\HardBreak;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Inline\Math;
use MarkupCarve\Carve\Node\Inline\SoftBreak;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Inline\UnresolvedReference;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\Block\FencedBlockParser;
use MarkupCarve\Carve\Parser\Block\ListParser;
use MarkupCarve\Carve\Parser\Block\TableParser;
use MarkupCarve\Carve\Parser\Utility\AttributeParser;
use MarkupCarve\Carve\Parser\Utility\IndentationHelper;
use MarkupCarve\Carve\Renderer\HeadingIdTracker;
use MarkupCarve\Carve\Util\StringUtil;

/**
 * Block-level parser for Djot
 */
class BlockParser
{
    /**
     * Neutral starting point for incremental brace scanning.
     *
     * @var array{depth: int, inQuote: bool, quoteChar: string, pendingEscape: bool}
     */
    private const INITIAL_BRACE_STATE = ['depth' => 0, 'inQuote' => false, 'quoteChar' => '', 'pendingEscape' => false];

    /**
     * Initial trailing-block tracker state for list-item lazy continuation.
     *
     * `openParagraph` starts true: an empty item (no block yet) can absorb a
     * lazy line. See advanceTrailingBlockState().
     *
     * @var array{openParagraph: bool, inFence: bool, fenceChar: string, fenceLength: int, inDiv: bool, divFenceLength: int}
     */
    private const INITIAL_TRAILING_BLOCK_STATE = ['openParagraph' => true, 'inFence' => false, 'fenceChar' => '', 'fenceLength' => 0, 'inDiv' => false, 'divFenceLength' => 0];

    /**
     * Abbreviation definitions use a space-free alphanumeric term and require
     * one space after the colon.
     *
     * @var string
     */
    /**
     * PART 5: `abbreviation_expansion = {character - newline}+` - ONE or more,
     * hence `(.+)` and not `(.*)`. An empty expansion is not a definition, and
     * consuming the line DELETED it from the document (carve-php#674).
     *
     * `.` matches a space, so `*[A]:` followed by TWO spaces has a
     * one-character expansion and IS a definition. That is the production as
     * written and what carve-js does.
     *
     * @var string
     */
    /**
     * A bullet, task or ordered marker line: it opens a list item whose lazy
     * continuation a following flush-left line folds into.
     *
     * @var string
     */
    private const LIST_ITEM_CONTEXT_PATTERN = '/^[ \t]*(?:[-*+]|(?:[0-9]+|[ivxlcdm]+|[IVXLCDM]+|[a-zA-Z])[.)]) /';

    /**
     * @var string
     */
    private const ABBREVIATION_DEFINITION_PATTERN = '/^\*\[([A-Za-z0-9]+)\]: (.+)$/';

    /**
     * Maximum block-container nesting depth. Every level of blockquote / div /
     * list / footnote recurses through parseBlocks(), so unbounded nesting (e.g.
     * `> ` repeated thousands of times) exhausts the stack or memory. Past this
     * depth, container content is emitted as a literal paragraph instead of
     * recursing. Far above any real document; only adversarial input reaches it.
     *
     * Public because `AstCodec` derives its ingest bound from this number
     * rather than repeating it: the decoder has to accept anything parsing can
     * produce, so raising this must raise that with it.
     *
     * @var int
     */
    public const MAX_NESTING_DEPTH = 200;

    /**
     * Depth bound for the heading-index walk.
     *
     * Matches `CrossReferenceResolver`'s own bound, because this walk has to
     * reach every heading THAT one reaches: a heading it stops short of still
     * gets an id at render time, so a lower bound here would render `<h1
     * id="H">` while leaving `[H][]` literal. Nesting is capped at
     * MAX_NESTING_DEPTH levels and a nested list spends two nodes per level, so
     * the bound has to be comfortably above twice that.
     *
     * @var int
     */
    protected const MAX_HEADING_WALK_DEPTH = 512;

    /**
     * A definition term, with its content.
     *
     * The separator after `::` is the SPACE character:
     * `definition_term = "::", space, inline_content, newline` and
     * `space = ' '`, so a tab does not open a term and the line stays
     * paragraph text - the rule every other space-separated marker already
     * followed (carve#532).
     *
     * The three shapes below are one decision spelled for three callers, and
     * they are constants because they had eleven copies between them: a fix
     * applied to the one a bug report named would have left the rest deciding
     * the old way.
     *
     * @var string
     */
    protected const DEFINITION_TERM_PATTERN = '/^::(?!:) +(?=\S)(.+)$/';

    /**
     * A definition term, tested rather than captured.
     *
     * @var string
     */
    protected const DEFINITION_TERM_LINE_PATTERN = '/^::(?!:) +\S/';

    /**
     * A definition-term MARKER, where the caller checks only that the line
     * opens one.
     *
     * @var string
     */
    protected const DEFINITION_TERM_LINE_PREFIX = '/^::(?!:) +/';

    private int $nestingDepth = 0;

    protected InlineParser $inlineParser;

    protected ListParser $listParser;

    protected TableParser $tableParser;

    protected FencedBlockParser $fencedBlockParser;

    protected ReferenceDefinitionExtractor $referenceDefinitionExtractor;

    /**
     * @var array<string, \MarkupCarve\Carve\Parser\ReferenceDefinition>
     */
    protected array $references = [];

    /**
     * Heading-derived references keyed by folded heading text. Used only for
     * unresolved collapsed references (`[text][]`), after exact definitions lose.
     *
     * @var array<string, \MarkupCarve\Carve\Parser\ReferenceDefinition>
     */
    protected array $headingReferencesByFoldedLabel = [];

    /**
     * True when a reference failed to resolve during the parse, in either the
     * collapsed `[text][]` or the explicit `[text][Label]` form. Both can
     * still land on a heading, and the heading index is built from the parsed
     * tree, so it does not exist yet. This is the trigger for the second pass;
     * a document whose references all resolved never pays for it.
     */
    protected bool $sawUnresolvedCollapsedReference = false;

    /**
     * @var array<string, \MarkupCarve\Carve\Node\Block\Footnote>
     */
    protected array $footnotes = [];

    /**
     * Abbreviation definitions: maps abbreviation text to its definition
     *
     * @var array<string, string>
     */
    protected array $abbreviations = [];

    /**
     * Every authored abbreviation definition line in source order, shadowed
     * ones kept.
     *
     * @var array<int, array<string, string>>
     */
    protected array $abbreviationDefinitions = [];

    protected bool $abbreviationsBeforeBody = false;

    /**
     * @var array<string, array<string, int>>
     */
    protected array $abbreviationSpans = [];

    /**
     * Pending block attributes to apply to next block
     *
     * @var array<string, string>
     */
    protected array $pendingAttributes = [];

    /**
     * Pending block attribute source slots.
     *
     * @var list<string>
     */
    protected array $pendingAttributeOrder = [];

    /**
     * Whether to collect warnings during parsing
     */
    protected bool $collectWarnings = false;

    /**
     * Whether to throw on parse errors
     */
    protected bool $strictMode = false;

    /**
     * Collected warnings during parsing
     *
     * @var array<\MarkupCarve\Carve\Exception\ParseWarning>
     */
    protected array $warnings = [];

    /**
     * References that have been used (for validation)
     * Only populated when collectWarnings is true
     *
     * @var array<string, int> Maps reference label to line where used
     */
    protected array $usedReferences = [];

    /**
     * Anchor links found during parsing (for validation)
     * Only populated when collectWarnings is true
     *
     * @var array<array{fragment: string, line: int, column: int}>
     */
    protected array $anchorLinks = [];

    /**
     * Heading IDs generated during heading reference extraction
     * Used for anchor link validation
     *
     * @var array<string, true>
     */
    protected array $headingIds = [];

    /**
     * Current line offset for nested parsing (0-indexed internally, 1-indexed for errors)
     */
    protected int $lineOffset = 0;

    /**
     * Byte offset of each line's first character in the normalized source.
     *
     * Keyed by index into the top-level line array, so it is only meaningful
     * alongside a line map that resolves back to that array.
     *
     * @var array<int, int>
     */
    protected array $lineStartOffsets = [];

    /**
     * The normalized top-level source lines, kept so a block span can measure
     * where its last line ends.
     *
     * @var array<int, string>
     */
    protected array $sourceLines = [];

    /**
     * The normalized source, kept so a computed span can be verified against
     * what it actually selects before a node is allowed to carry it.
     */
    protected string $normalizedSource = '';

    /**
     * Converts the parser's byte positions to the codepoints PART 12 §4 counts.
     */
    protected ?PositionIndex $positionIndex = null;

    /**
     * Custom block patterns: array of [pattern => callback]
     * Callback receives (array $lines, int $startIndex, Node $parent, BlockParser $parser)
     * and should return number of lines consumed, or null if not matched
     *
     * @var array<string, callable(array<string>, int, \MarkupCarve\Carve\Node\Node, self): ?int>
     */
    protected array $customBlockPatterns = [];

    /**
     * @var array<array{matcher: \Closure, priority: int, seq: int, pattern: string|null}>
     */
    protected array $blockMatchers = [];

    protected int $blockMatcherSeq = 0;

    /**
     * @var array<\Closure>|null
     */
    protected ?array $sortedBlockMatchers = null;

    protected ?Node $currentMatcherParent = null;

    /**
     * Comment-fence index for the current line set, built once per line set.
     *
     * A closer must match the opener width EXACTLY, so any later line carrying a
     * fence of that width IS a valid closer: "is there a closer after $i" is
     * exactly "last index for this width > $i". That replaces a per-opener scan
     * to the end of the line set, which is superlinear on input full of openers
     * with DISTINCT widths - the case where a per-width negative cache can never
     * help, because each width is only seen once.
     *
     * @var array<int, int>|null Fence length => LAST index carrying that fence.
     */
    protected ?array $commentFenceLastIndex = null;

    /**
     * Optional slug transform mirrored onto the parse-time heading-id
     * tracker so implicit `[Heading][]` references agree with the
     * render-time ids (set by AsciiHeadingIdsExtension).
     */
    protected ?Closure $headingIdTransformer = null;

    /**
     * Mirrors the render-time tracker's opt-in lowercase flag so implicit
     * `[Heading][]` references agree with the (lowercased) emitted ids.
     */
    protected bool $headingIdLowercase = false;

    public function setHeadingIdTransformer(?Closure $headingIdTransformer): void
    {
        $this->headingIdTransformer = $headingIdTransformer;
    }

    public function setHeadingIdLowercase(bool $lowercase): void
    {
        $this->headingIdLowercase = $lowercase;
    }

    /**
     * When true, block nodes are stamped with a `data-source-line`
     * attribute holding the 1-based source line where the block started.
     * Opt-in (default off): used by editor live-preview to sync scroll to the
     * source textarea. Off by default so normal rendering output is unchanged.
     *
     * @var bool
     */
    protected bool $trackSourceLines = false;

    /**
     * When true, nodes carry a full PART 12 §4 source span.
     *
     * Separate from trackSourceLines, which stamps a `data-source-line`
     * ATTRIBUTE for editor scroll-sync and is line-granular. A span is AST
     * state, carries all six fields, and never reaches rendered output.
     *
     * @var bool
     */
    protected bool $trackPositions = false;

    /**
     * Per-line map for the line array currently being parsed.
     *
     * @var array<int, int>|null
     */
    protected ?array $currentLineMap = null;

    public function __construct(
        bool $collectWarnings = false,
        bool $strictMode = false,
        bool $trackSourceLines = false,
        bool $trackPositions = false,
    ) {
        $this->collectWarnings = $collectWarnings;
        $this->strictMode = $strictMode;
        $this->trackSourceLines = $trackSourceLines;
        $this->trackPositions = $trackPositions;
        $this->inlineParser = new InlineParser($this);
        $this->listParser = new ListParser();
        $this->tableParser = new TableParser();
        $this->fencedBlockParser = new FencedBlockParser();
        $this->referenceDefinitionExtractor = new ReferenceDefinitionExtractor($this->inlineParser);
    }

    /**
     * Register a custom block pattern
     *
     * The pattern should match the first line of the block.
     * The callback receives the full lines array, start index, parent node, and parser,
     * and should return the number of lines consumed (or null if not a match).
     *
     * Example - :::spoiler blocks:
     * ```php
     * $parser->addBlockPattern('/^:::spoiler\s*$/', function($lines, $start, $parent, $parser) {
     *     $endPattern = '/^:::\s*$/';
     *     $content = [];
     *     $i = $start + 1;
     *     while ($i < count($lines) && !preg_match($endPattern, $lines[$i])) {
     *         $content[] = $lines[$i];
     *         $i++;
     *     }
     *     $div = new Div();
     *     $div->setAttribute('class', 'spoiler');
     *     // Parse content inside
     *     $parser->parseBlockContent($div, $content);
     *     $parent->appendChild($div);
     *     return $i - $start + 1; // +1 for closing :::
     * });
     * ```
     *
     * Example - custom admonitions:
     * ```php
     * $parser->addBlockPattern('/^!!!\s*(note|warning|danger)\s*$/', function($lines, $start, $parent, $parser) {
     *     $type = trim(substr($lines[$start], 3));
     *     $content = [];
     *     $i = $start + 1;
     *     while ($i < count($lines) && preg_match('/^\s+/', $lines[$i])) {
     *         $content[] = ltrim($lines[$i]);
     *         $i++;
     *     }
     *     $div = new Div();
     *     $div->setAttribute('class', 'admonition ' . $type);
     *     $parser->parseBlockContent($div, $content);
     *     $parent->appendChild($div);
     *     return $i - $start;
     * });
     * ```
     *
     * @param string $pattern Regex pattern to match the first line
     * @param callable(array<string>, int, \MarkupCarve\Carve\Node\Node, self): ?int $callback
     */
    public function addBlockPattern(string $pattern, callable $callback): void
    {
        $this->removeBlockPattern($pattern);
        $this->customBlockPatterns[$pattern] = $callback;
        $self = $this;

        $this->registerBlockMatcher(
            function (array $lines, int $start, MatcherContext $ctx) use ($pattern, $callback, $self): ?int {
                if (!preg_match($pattern, $lines[$start])) {
                    return null;
                }

                // Legacy callbacks append their node(s) to the parent themselves
                // and return the number of lines consumed. Preserve that contract
                // verbatim — a pattern emitting several sibling blocks keeps them
                // flat, with no synthetic wrapper. The dispatcher reads the int
                // return as "already appended".
                $parent = $self->currentMatcherParent;
                if ($parent === null) {
                    return null;
                }

                $consumed = $callback($lines, $start, $parent, $self);

                return is_int($consumed) ? $consumed : null;
            },
            pattern: $pattern,
        );
    }

    /**
     * Remove a custom block pattern
     */
    public function removeBlockPattern(string $pattern): void
    {
        unset($this->customBlockPatterns[$pattern]);
        $this->blockMatchers = array_values(array_filter(
            $this->blockMatchers,
            static fn (array $entry): bool => $entry['pattern'] !== $pattern,
        ));
        $this->sortedBlockMatchers = null;
    }

    /**
     * Get all registered custom block patterns
     *
     * @return array<string, callable>
     */
    public function getBlockPatterns(): array
    {
        return $this->customBlockPatterns;
    }

    /**
     * @param \Closure(array<string>, int, \MarkupCarve\Carve\Parser\MatcherContext): (array{node: \MarkupCarve\Carve\Node\Node, linesConsumed: int}|null) $matcher
     * @param int $priority
     */
    public function addBlockMatcher(Closure $matcher, int $priority = 0): void
    {
        $this->registerBlockMatcher($matcher, $priority);
    }

    /**
     * @param \Closure(array<string>, int, \MarkupCarve\Carve\Parser\MatcherContext): (int|array{node: \MarkupCarve\Carve\Node\Node, linesConsumed: int}|null) $matcher
     * @param int $priority
     * @param string|null $pattern
     */
    protected function registerBlockMatcher(Closure $matcher, int $priority = 0, ?string $pattern = null): void
    {
        $this->blockMatchers[] = [
            'matcher' => $matcher,
            'priority' => $priority,
            'seq' => $this->blockMatcherSeq++,
            'pattern' => $pattern,
        ];
        $this->sortedBlockMatchers = null;
    }

    /**
     * @return array<\Closure>
     */
    protected function sortedBlockMatchers(): array
    {
        if ($this->sortedBlockMatchers !== null) {
            return $this->sortedBlockMatchers;
        }

        $entries = $this->blockMatchers;
        usort($entries, static function (array $a, array $b): int {
            return $b['priority'] <=> $a['priority'] ?: $a['seq'] <=> $b['seq'];
        });

        return $this->sortedBlockMatchers = array_map(
            static fn (array $entry): Closure => $entry['matcher'],
            $entries,
        );
    }

    /**
     * Parse block content (for use in custom block callbacks)
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     */
    public function parseBlockContent(Node $parent, array $lines): void
    {
        $this->parseBlocks($parent, $lines, 0, array_fill(0, count($lines), -1));
    }

    /**
     * Enable or disable warning collection
     */
    public function setCollectWarnings(bool $collect): self
    {
        $this->collectWarnings = $collect;

        return $this;
    }

    /**
     * Enable or disable strict mode
     */
    public function setStrictMode(bool $strict): self
    {
        $this->strictMode = $strict;

        return $this;
    }

    /**
     * Get collected warnings
     *
     * @return array<\MarkupCarve\Carve\Exception\ParseWarning>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Clear collected warnings
     */
    public function clearWarnings(): self
    {
        $this->warnings = [];

        return $this;
    }

    /**
     * Add a warning or throw exception in strict mode
     *
     * @throws \MarkupCarve\Carve\Exception\ParseException In strict mode for errors
     */
    protected function addWarning(
        string $message,
        int $line,
        int $column = 1,
        bool $isError = false,
        ?string $category = null,
        ?string $suggestion = null,
    ): void {
        // Convert from 0-indexed to 1-indexed for user-facing messages
        $line = $line + $this->lineOffset + 1;

        if ($isError && $this->strictMode) {
            throw new ParseException($message, $line, $column);
        }

        if ($this->collectWarnings) {
            $this->warnings[] = new ParseWarning($message, $line, $column, $category, $suggestion);
        }
    }

    public function parse(string $input): Document
    {
        // Capture the original source byte length before any normalization so
        // renderers can size the abbreviation-expansion budget (DoS guard).
        $sourceLength = strlen($input);
        $this->resetParseState();
        $document = new Document();
        // Strip a single leading UTF-8 BOM (U+FEFF) at the document start so
        // `﻿# T` is a heading, not literal text. Root only: this is the
        // top-level entry; nested content is parsed from line arrays.
        if (str_starts_with($input, "\u{FEFF}")) {
            $input = substr($input, 3);
        }
        // Replace any NUL (U+0000) with the U+FFFD replacement character so a
        // control byte never reaches output (decided cross-impl behavior;
        // WHATWG-style). For carve-php this also prevents an input NUL from
        // colliding with the internal SOFT_BREAK_GUARD sentinel (also \x00).
        if (str_contains($input, "\0")) {
            $input = str_replace("\0", "\u{FFFD}", $input);
        }
        $lines = $this->splitLines($input);

        // First pass: extract reference definitions, footnotes, abbreviations, and heading references
        $this->extractReferences($lines);
        $this->extractFootnotes($lines);
        $this->extractAbbreviations($lines);
        // The implicit `[Heading][]` index. Two ways to build it, and which one
        // runs depends only on whether the document could USE it:
        //
        // The line scan below is cheap and sees a heading marker at column 0,
        // which is every top-level heading and every one inside a div. It
        // cannot see one inside a list item, a definition or a nested list -
        // those are indented, and an indented `#` at top level is a paragraph,
        // so the scan has no way to tell the two apart without re-deriving
        // block structure. It also cannot see that a `#` line is inside a code
        // fence, so it indexed headings that do not exist.
        //
        // PART 11 R1 puts headings in divs, admonitions, LIST ITEMS and
        // definitions in the index, excluding only a blockquote ancestor. To
        // get that right the structure has to be known, so when the source can
        // actually contain a reference link the blocks are parsed once into a
        // scratch tree and the index is taken from it - the same document-order
        // walk the renderer already uses to resolve heading ids, so the ids
        // agree by construction rather than by two scanners mirroring each
        // other (carve-php#572).
        if ($this->needsStructuredHeadingIndex($input)) {
            $this->indexHeadingsFromStructure($lines);
        } else {
            $this->extractHeadingReferences($lines);
        }

        // Second pass: parse blocks
        $this->parseBlocks($document, $lines, 0, topLevel: true);

        // Third pass, and ONLY when the document needs it: an implicit
        // `[Heading][]` reference that found no definition.
        //
        // R1's index is a property of the parsed TREE - it asks whether a
        // heading has a blockquote ancestor - and references resolve during
        // inline parsing, which happens inside the pass above. So the index
        // cannot exist before the parse that consumes it, and the honest way
        // to have both is to parse again with it seeded. A document with no
        // unresolved collapsed reference never reaches this and parses once.
        //
        // The alternative was keeping the old line pre-scan and teaching it
        // about list indentation, which leaves the index keyed on source
        // column: the blockquote rule would stay an accident of the `>`
        // prefix and the next container would inherit whatever spacing it
        // happens to use (#572).
        if ($this->sawUnresolvedCollapsedReference) {
            $headingReferences = (new HeadingReferenceCollector($this->headingIdTrackerForReferences()))
                ->collect($document);
            // Only re-parse for headings the first pass could not already
            // reach. Without this a single typo'd reference in a document full
            // of top-level headings would pay for a second parse that changes
            // nothing, since those headings resolved in pass 1 anyway.
            $headingReferences = array_filter(
                $headingReferences,
                fn (string $folded): bool => !isset($this->headingReferencesByFoldedLabel[$folded]),
                ARRAY_FILTER_USE_KEY,
            );
            if ($headingReferences !== []) {
                $document = $this->reparseWithHeadingReferences($lines, $headingReferences, $sourceLength);
            }
        }

        // Append footnotes section if any
        foreach ($this->footnotes as $footnote) {
            $document->appendChild($footnote);
        }

        // PART 12 §10: an authored link reference definition is a NODE, hoisted
        // to the document like the other two definition kinds. Without one a
        // writer cannot reproduce the definition, so a resolved reference was
        // written back as an inline link and `parse(fmt(x)) == parse(x)` failed
        // for every one of them (PART 11 §1, markup-carve/carve#642).
        //
        // Appended here rather than at parse time because the line may sit
        // inside a block quote or a list item, and the node belongs to the
        // DOCUMENT - the same reason footnotes are appended above. Built from
        // the collected table, so the node and the table resolution uses cannot
        // disagree about what the author wrote. A heading-derived reference is
        // skipped: it has no definition line to reproduce.
        $this->appendLinkReferenceDefinitions($document);

        // A sole-image paragraph carrying a leading block-attribute line's attrs
        // renders as a bare block <img> with those attrs on the image (§15). Run
        // this AFTER caption wrapping (so a captioned image is already a <figure>
        // and keeps its id there) and BEFORE rendering (so render-time extension
        // attributes are untouched -- they still land on the <p> wrapper).
        $this->promoteBlockImageAttributes($document);

        if ($this->trackPositions) {
            // After every pass that can move or wrap nodes, so a container sees
            // its final children.
            $this->deriveContainerSpans($document);
        }

        // Validate references and anchor links if warnings are enabled
        if ($this->collectWarnings) {
            $this->validateReferences();
            $this->validateAnchorLinks($document);
        }

        // Store abbreviations on document for round-trip support
        if ($this->abbreviations !== []) {
            $document->setAbbreviations($this->abbreviations);
            $document->setAbbreviationDefinitions($this->abbreviationDefinitions);
            $document->setAbbreviationsBeforeBody($this->abbreviationsBeforeBody);
            $document->setAbbreviationSpans($this->abbreviationSpans);
        }

        // Record the source byte length so renderers can size the
        // abbreviation-expansion budget (output-amplification DoS guard).
        $document->setSourceLength($sourceLength);

        return $document;
    }

    /**
     * Move a sole-image paragraph's SOURCE attributes (from a leading
     * block-attribute line) onto the image, so it renders as a bare block
     * `<img>` carrying those attrs (§15) rather than a `<p>` wrapper -- matching
     * a direct block image and carve-js / carve-rs. Walks the whole block tree.
     */
    protected function promoteBlockImageAttributes(Node $node): void
    {
        $children = $node->getChildren();
        $replaced = false;
        foreach ($children as $index => $child) {
            if ($child instanceof Paragraph) {
                $kids = $child->getChildren();
                // An UNRESOLVED reference image is not an image in block
                // position: it renders as its literal source (PART 12 §3a), so
                // `![a][]` with nothing defining `[a]` stays a paragraph, as it
                // does in carve-js. Promoting it dropped the `<p>` wrapper.
                if (
                    count($kids) === 1
                    && $kids[0] instanceof Image
                    && ($kids[0]->getRawReferenceLabel() === null || $kids[0]->getSource() !== '')
                ) {
                    if ($child->getAttributes() !== []) {
                        $kids[0]->mergeLeadingAttributes($child->getAttributes(), $child->getAttributeOrder());
                        foreach (array_keys($child->getAttributes()) as $key) {
                            $child->removeAttribute((string)$key);
                        }
                    }
                    // The AST vocabulary states it in the `image` node's own
                    // description: "Also valid in BLOCK position: a lone image
                    // paragraph is a block-level image." carve-js and carve-rs
                    // publish the image; this engine published the paragraph
                    // and unwrapped it again in the HTML renderer, so the
                    // output matched while the tree did not - which no HTML
                    // gate can see (#633).
                    $kids[0]->setPos($kids[0]->getPos() ?? $child->getPos());
                    $children[$index] = $kids[0];
                    $replaced = true;

                    continue;
                }
            }
            $this->promoteBlockImageAttributes($child);
        }
        if ($replaced) {
            $node->setChildren($children);
        }
    }

    /**
     * Append a node per AUTHORED link reference definition (PART 12 §10).
     *
     * Document order, matching the line each definition was written on, so a
     * writer reproduces them where the author had them. A definition DERIVED
     * from a heading (PART 11 R1) has no authored line and is skipped.
     */
    protected function appendLinkReferenceDefinitions(Document $document): void
    {
        $authored = [];
        foreach ($this->references as $label => $definition) {
            if ($definition->fromHeading) {
                continue;
            }
            $authored[] = [$label, $definition];
        }
        usort($authored, static fn (array $a, array $b): int => $a[1]->line <=> $b[1]->line);

        foreach ($authored as [$label, $definition]) {
            $node = new LinkReferenceDefinition($label, $definition->url, $definition->title);
            if ($definition->attributes !== []) {
                // SLOT spellings, not raw keys. The writer's `#id` slot is what
                // emits an id, and its raw-`id` branch returns early on purpose
                // so a key cannot be written twice - so an order list holding
                // `id` dropped the id from the definition line entirely
                // (carve-php#831). Same mapping the block-attribute path uses.
                $node->setAttributesWithOrder(
                    $definition->attributes,
                    array_map(
                        static fn (string $name): string => match ($name) {
                            'id' => '#id',
                            'class' => '.class',
                            default => $name,
                        },
                        array_map('strval', array_keys($definition->attributes)),
                    ),
                );
            }
            // PART 12 §4 requires `pos` on every node but the root, and §10 says
            // a hoisted definition's span still points at the line the author
            // wrote it on - which is the whole point of hoisting a NODE rather
            // than a root map. The definition is single-line by production.
            $node->setPos($this->wholeLineSpan($definition->line));
            $document->appendChild($node);
        }
    }

    /**
     * Extract reference link definitions from the document
     *
     * @param array<string> $lines
     */
    protected function extractReferences(array $lines): void
    {
        $this->references = $this->referenceDefinitionExtractor->extract($lines);
    }

    /**
     * Extract footnote definitions from the document
     *
     * @param array<string> $lines
     */
    protected function extractFootnotes(array $lines): void
    {
        $i = 0;
        $count = count($lines);
        // Track an open fenced code block so a `[^a]: ...` (or `> [^a]: ...`)
        // shown inside a ``` / ~~~ sample is treated as literal code, never
        // registered -- mirroring the fence-opacity guard in extractReferences.
        // A single leading blockquote marker is stripped first so a code fence
        // INSIDE a blockquote (`> ``` ` / `> [^a]: note` / `> ``` `) is tracked
        // too: without this the container stripping below would wrongly read the
        // quoted code content as a real definition.
        $fence = new PrepassFenceTracker();
        // Track an open LINE BLOCK the same way. A line block's body is inline
        // content (`line_block_line = {whitespace}, inline_content, newline`),
        // so the block-level definition form cannot occur there: a `[^a]: note`
        // inside `::: |` is literal text and must register nothing. Without
        // this the scan registered it, and the line then rendered as a live
        // footnote REFERENCE with `: note` beside it plus an endnote nobody
        // referenced (carve-php#685). Only the `|` type token opens one --
        // an ordinary `::: note` div holds blocks, so a definition there still
        // registers.
        $lineBlockLen = 0;
        // The blockquote depth the open line block was opened at, so its closer
        // is read at that depth instead of after every marker is stripped.
        $lineBlockDepth = 0;
        // A `%%%` COMMENT FENCE is opaque, so a literal `::: |` inside one is
        // not a line-block opener. Entering that state there left it open past
        // the comment's own closer -- which is not a colon fence -- and every
        // later definition in the document was skipped (#698).
        //
        // A comment's body is skipped outright, so a definition inside one no
        // longer registers either. That is an intended behaviour change: a
        // comment renders nothing, and carve-js has never registered from
        // inside one.
        $commentFenceLen = 0;
        // The LAST line index at which a comment fence of each length closes,
        // computed ONCE. Scanning forward per opener is what
        // testDistinctWidthFenceOpenersDoNotRescanPerOpener forbids, and
        // `%%% x`, `%%%% x`, ... is all openers and no closers -- every one of
        // them would scan to the end of the document.
        //
        // Keyed by EXACT length: a `%%%%` line does not close a `%%%` fence.
        // Any leading `%` run counts, not only a bare line, because `%%% end`
        // closes a `%%%` fence.
        $commentCloseAt = [];
        for ($j = 0; $j < $count; $j++) {
            if (preg_match('/^(%{3,})/', $lines[$j], $cj) === 1) {
                $commentCloseAt[strlen($cj[1])] = $j;
            }
        }
        // Footnote bodies are parsed AFTER the scan registers every label, so a
        // forward reference inside a body resolves (`[^1]: a[^2]` before
        // `[^2]: b`). label -> raw content lines.
        $deferredBodies = [];
        // The item content column a definition on a CONTINUATION line has to
        // reach, tracked exactly as the link-reference prepass tracks it.
        $contentColumns = new ListContentColumns();

        while ($i < $count) {
            $line = $lines[$i];
            // Inside a code fence a `- x` line is sample text, not a marker.
            // Content columns are measured INSIDE a block quote (carve#658);
            // see the same strip in ReferenceDefinitionExtractor. Only a
            // COLUMN-0 marker is stripped: an indented one sits at an item's
            // content column, and eating that indentation loses it.
            $unquotedForColumns = preg_replace('/^(?:>(?: |$))+/', '', $line) ?? $line;
            $contentCol = $contentColumns->observe($unquotedForColumns, $fence->isOpen());
            // One line can open SEVERAL items (`- - b` opens two, columns 2 and
            // 4), and a definition written under it belongs to whichever open
            // item's column it lands on - not necessarily the innermost
            // (carve-php#764).
            $reachedCol = $contentColumns->reachedBy(
                strlen($unquotedForColumns) - strlen(ltrim($unquotedForColumns, " \t")),
            );
            // Strip any leading blockquote markers before the fence test so a
            // code fence nested at any blockquote depth (`> ``` `, `> > ``` `)
            // is tracked and its quoted footnote-looking lines stay literal.
            // Each entry is the line with THAT many leading markers removed, so
            // a consumer can ask for the content at its own quote depth rather
            // than the fully stripped tail (the line block below needs that).
            $quoteStages = [$line];
            $fenceLine = $line;
            while (($fenceLine[0] ?? '') === '>') {
                $quoteContent = $this->blockQuoteLineContent($fenceLine);
                if ($quoteContent === null) {
                    break;
                }
                $fenceLine = $quoteContent;
                $quoteStages[] = $fenceLine;
            }

            if ($fence->isOpen()) {
                // LEFT means the line dropped out of the blockquote the fence
                // was opened in, so the region ended without a closer and this
                // line is read normally.
                if ($fence->advance($line) !== PrepassFenceTracker::LEFT) {
                    $i++;

                    continue;
                }
            }
            // A comment fence's closer is a leading `%` run of the SAME length --
            // trailing text is allowed, so `%%% end` closes a `%%%` fence. Matching
            // only a bare fence missed real closers and left the state open.
            if ($commentFenceLen > 0) {
                if (preg_match('/^(%{3,})/', $line, $cm) === 1 && strlen($cm[1]) === $commentFenceLen) {
                    $commentFenceLen = 0;
                }
                // The body is opaque: a code fence opener in there is comment
                // TEXT, and letting it reach the fence scanner below opened a
                // code block that swallowed the real comment closer.
                $i++;

                continue;
            }
            if (preg_match('/^(%{3,})/', $line, $cm) === 1) {
                // Only a fence that CLOSES. An unterminated `%%%` is not a fenced
                // comment -- the block parser degrades it to a single-line comment
                // -- and treating it as open here stayed open for the rest of the
                // document, suppressing every later line block.
                $openLen = strlen($cm[1]);
                if (($commentCloseAt[$openLen] ?? -1) > $i) {
                    $commentFenceLen = $openLen;
                    $i++;

                    continue;
                }
            }
            if ($lineBlockLen > 0) {
                // The closer has to be read at the DEPTH the line block opened
                // at: inside `> ::: |` a nested `> > :::` is a quoted `> :::`,
                // which the real parser keeps as line-block content. Reading
                // the fully stripped tail would close the region there and let
                // the lines after it register again. A line that no longer
                // reaches that depth has left the blockquote, so the line block
                // ended with it.
                $closerLine = $quoteStages[$lineBlockDepth] ?? null;
                if ($closerLine === null) {
                    $lineBlockLen = 0;
                } elseif ($this->fencedBlockParser->isDivFenceCloser($closerLine, $lineBlockLen)) {
                    $lineBlockLen = 0;
                    $i++;

                    continue;
                } else {
                    $i++;

                    continue;
                }
            }
            if ($fence->opensOn($line, $contentCol)) {
                $i++;

                continue;
            }
            $fc0 = $fenceLine[0] ?? '';
            if ($fc0 === ':') {
                $lineBlockOpener = $this->parseLineBlockOpener($fenceLine);
                if ($lineBlockOpener !== null) {
                    $lineBlockLen = $lineBlockOpener['length'];
                    $lineBlockDepth = count($quoteStages) - 1;
                    $i++;

                    continue;
                }
            }

            // A footnote definition may sit at column 0 or directly inside a
            // single container (blockquote / list item): the container consumes
            // the def line without rendering it, but the definition must still
            // populate the global footnote map so a `[^a]` reference resolves
            // (carve spec #115). Mirrors how extractReferences handles
            // container-nested reference definitions.
            //
            // Container-nested defs are collected ONLY from a single def line
            // with a non-empty inline body (`> [^a]: body` / `- [^a]: body`):
            // the oracle (carve-js) treats a following indented line inside a
            // container as ordinary container content, not note body, and never
            // collects an empty-bodied container def. Only a TOP-LEVEL def
            // gathers indented continuation lines (the original behavior).
            // A footnote definition (top-level or container-nested) always
            // contains the literal `[^` token: the top-level form starts with
            // it, and the container form has it after the stripped markers (a
            // suffix of this line). When the line has no `[^` at all, neither
            // path can fire, so skip the marker-stripping prefix scan entirely.
            if (!str_contains($line, '[^')) {
                $i++;

                continue;
            }

            $container = $this->footnoteContainerPrefix($line, $reachedCol);
            $prefix = $container['prefix'];
            $bare = $prefix === '' ? $line : substr($line, strlen($prefix));

            // A definition on an item's CONTINUATION line carries no marker of
            // its own, so the prefix scan above leaves the item's indentation in
            // front of the `[` and the line stops looking like a definition. It
            // was then collected by nobody while the block parser still removed
            // it from the output: the author's line rendered as nothing and a
            // `[^f]` reference to it stayed literal (carve-php#761) - the same
            // disappearance markup-carve/carve#624 describes.
            //
            // Exactly the content column is removed, never more: one column
            // short and the `[` is not at position 0, so a definition BELOW the
            // column still registers nothing and folds as the paragraph text it
            // looks like (§24 C3). Indented PAST the column it keeps residual
            // spaces and fails the same test, matching carve-js.
            //
            // Measured on the quote-stripped view: inside `> - a` the content
            // column counts from after the `> `, so applying it to the raw line
            // cut into the quote marker and the definition was missed
            // (carve#658).
            if (
                $container['kind'] === 'none'
                && $reachedCol > 0
                && strlen($unquotedForColumns) - strlen(ltrim($unquotedForColumns, " \t")) >= $reachedCol
            ) {
                $columnBare = substr($unquotedForColumns, $reachedCol);
                if (preg_match('/^\[\^[^\]]+\]:/', $columnBare) === 1) {
                    $quotePrefixLength = strlen($line) - strlen($unquotedForColumns);
                    $container = [
                        'kind' => 'columnContainer',
                        'prefix' => substr($line, 0, $quotePrefixLength + $reachedCol),
                    ];
                    $bare = $columnBare;
                }
            }

            // Match footnote definition: [^label]: content. The marker line
            // must carry inline content (grammar PART 9 §16 production:
            // `"]:", space, inline_content`); a bare `[^label]:` is an
            // ordinary paragraph line, and a following indented line folds
            // into it as paragraph text.
            if (($bare[0] ?? '') === '[' && preg_match('/^\[\^([^\]]+)\]: +(.*)$/', $bare, $matches)) {
                $label = $matches[1];
                $content = $matches[2];
                if (trim($content) === '') {
                    $i++;

                    continue;
                }

                if ($container['kind'] !== 'none') {
                    // Container-nested: single-line, non-empty body only. The
                    // FIRST definition of a label wins, so a container def never
                    // overwrites an earlier (top-level or container) def.
                    //
                    // The def must OPEN a block: collect only when the previous
                    // line is blank, the document start, or itself a container
                    // line (so a structurally-nested `> ...` / `- ...` chain is
                    // still seen). An indented `- [^a]:` that merely lazily
                    // continues a preceding paragraph is NOT a definition -- the
                    // real parser leaves it in that paragraph (matches carve-js).
                    // A line that REACHED the item's content column opens a
                    // block there by geometry (§24 C3), so it needs no opener
                    // test: carve-js collects it under an item paragraph, under
                    // a blank, and as the item's first body line alike.
                    // A BLOCKQUOTE marker in the prefix opens a block by
                    // itself, wherever it sits: `- a` / `  > [^f]: x` starts a
                    // quote inside the item, so the definition is that quote's
                    // first block and does not depend on what precedes it. The
                    // opener test below is about a line CONTINUING a paragraph,
                    // which a quote marker never does (carve-php#788).
                    $opensBlock = $container['kind'] === 'columnContainer'
                        || str_contains($container['prefix'], '>')
                        || $i === 0
                        || IndentationHelper::isBlankLine($lines[$i - 1])
                        || $this->footnoteContainerPrefix($lines[$i - 1])['kind'] !== 'none'
                        || $this->blockQuoteLineContent(ltrim($lines[$i - 1], " \t")) !== null;
                    if ($opensBlock && trim($content) !== '' && !isset($this->footnotes[$label])) {
                        $footnote = new Footnote($label);
                        if ($this->trackSourceLines) {
                            $footnote->setAttribute('data-source-line', (string)($i + 1));
                        }
                        $this->footnotes[$label] = $footnote;
                        $bodyLines = [$content];
                        $bodyLineMap = [$i];
                        // A definition at an item's CONTENT COLUMN keeps its
                        // continuation lines, measured from the definition
                        // rather than from column 0 (PART 9 §16: ">= 2",
                        // relative). Treating the column form as single-line
                        // dropped `    more` under `  [^f]: x` - not into the
                        // item, not into the note, gone from the document
                        // (carve-php#794). carve-js and carve-rs both keep it;
                        // a line at the definition's OWN column is not
                        // continuation and all three leave it alone.
                        //
                        // Only for a COLUMN container: under a blockquote
                        // prefix a continuation carries the `>` itself, which
                        // this line-based pass does not strip, so those stay
                        // single-line and are left to normal block parsing.
                        if ($container['kind'] === 'columnContainer') {
                            $bodyIndent = $reachedCol + 2;
                            $k = $i + 1;
                            while ($k < $count) {
                                $continuation = $lines[$k];
                                if (IndentationHelper::isBlankLine($continuation)) {
                                    break;
                                }
                                $indent = strlen($continuation) - strlen(ltrim($continuation, " \t"));
                                if ($indent < $bodyIndent) {
                                    break;
                                }
                                $bodyLines[] = substr($continuation, $bodyIndent);
                                $bodyLineMap[] = $k;
                                $k++;
                            }
                            $i = $k - 1;
                        }
                        $deferredBodies[$label] = [
                            'lines' => $bodyLines,
                            'lineMap' => $bodyLineMap,
                        ];
                    }

                    $i++;

                    continue;
                }

                // Collect continuation lines (indented or blank). A footnote
                // body extends only to lines indented by the base indentation
                // (2 spaces or a tab); see the continuation regex below.
                $contentLines = [];
                $contentLineMap = [];
                if (trim($content) !== '') {
                    $contentLines[] = $content;
                    $contentLineMap[] = $i;
                }
                $j = $i + 1;
                while ($j < $count) {
                    $nextLine = $lines[$j];
                    if (IndentationHelper::isBlankLine($nextLine)) {
                        // Add blank line to preserve structure
                        $contentLines[] = '';
                        $contentLineMap[] = $j;
                        $j++;

                        continue;
                    }
                    // Form B: a lone `+` attaches the FOLLOWING flush-left block
                    // to the note with no indentation (the same continuation
                    // marker lists, block quotes and definition bodies use). The
                    // attached block ends at a blank line, another `+`, or the
                    // next footnote definition.
                    if (preg_match('/^\+[ \t]*$/', $nextLine)) {
                        $j++;
                        $attached = [];
                        $attachedLineMap = [];
                        while ($j < $count) {
                            $a = $lines[$j];
                            if (
                                IndentationHelper::isBlankLine($a)
                                || preg_match('/^\+[ \t]*$/', $a)
                                || preg_match('/^\[\^[^\]]+\]:/', $a)
                            ) {
                                break;
                            }
                            $attached[] = $a;
                            $attachedLineMap[] = $j;
                            $j++;
                        }
                        if ($attached) {
                            $contentLines[] = '';
                            $contentLineMap[] = -1;
                            foreach ($attached as $attachedIndex => $a) {
                                $contentLines[] = $a;
                                $contentLineMap[] = $attachedLineMap[$attachedIndex];
                            }
                        }

                        continue;
                    }
                    // A footnote body extends only to lines indented by at least
                    // base indentation (2 spaces or a tab), per grammar PART 9
                    // §16. A line with less indentation (e.g. a single space) is
                    // a top-level block, not part of the footnote -- matches
                    // carve-js / carve-rs.
                    if (preg_match('/^(?:[ ]{2}|\t)(.*)$/', $nextLine, $contMatch)) {
                        $contentLines[] = $contMatch[1];
                        $contentLineMap[] = $j;
                        $j++;
                    } else {
                        break;
                    }
                }

                // Remove trailing blank lines
                $lineCount = count($contentLines);
                while ($lineCount > 0 && $contentLines[$lineCount - 1] === '') {
                    array_pop($contentLines);
                    array_pop($contentLineMap);
                    $lineCount--;
                }

                // The first definition of a label wins (grammar / carve-js): a
                // later top-level def never overwrites an earlier one, whether
                // that earlier one was top-level or container-nested.
                if (!isset($this->footnotes[$label])) {
                    $footnote = new Footnote($label);
                    if ($this->trackSourceLines) {
                        $footnote->setAttribute('data-source-line', (string)($i + 1));
                    }
                    $this->footnotes[$label] = $footnote;
                    if ($contentLines) {
                        $deferredBodies[$label] = [
                            'lines' => $contentLines,
                            'lineMap' => $contentLineMap,
                        ];
                    }
                }
            }

            $i++;
        }

        // Every footnote label is now registered; parse the bodies so a forward
        // reference to a later-defined footnote inside a body resolves.
        foreach ($deferredBodies as $label => $body) {
            // A note body's PENDING ATTRIBUTES do not survive it. `parseBlocks()`
            // leaves the state set when a body ends with an attribute line that
            // has nothing to attach to, and the next block in the DOCUMENT then
            // collected it - so a class written inside a note landed on body
            // text outside the note (carve-php#816). Section 15 A4 drops a
            // pending attribute with no following block element; the note body
            // ending is that condition for anything written inside it.
            $outerPendingAttributes = $this->pendingAttributes;
            $outerPendingAttributeOrder = $this->pendingAttributeOrder;
            $this->pendingAttributes = [];
            $this->pendingAttributeOrder = [];
            try {
                $this->parseBlocks($this->footnotes[$label], $body['lines'], 0, $body['lineMap']);
            } finally {
                $this->pendingAttributes = $outerPendingAttributes;
                $this->pendingAttributeOrder = $outerPendingAttributeOrder;
            }
        }
    }

    /**
     * Classify the leading container context of a footnote definition line, so
     * the pre-pass collects a footnote defined inside one or more nested
     * containers (carve spec #115). Strips every leading container marker --
     * blockquote `>` and any ordered/bullet (non-task) list marker, repeatedly
     * and at any depth -- mirroring the reference-definition pre-pass loop, so
     * `> [^a]:`, `- [^a]:`, `> > [^a]:` and `> - [^a]:` are all recognized.
     *
     * Returns:
     *  - kind 'none' : the line is a top-level `[^label]:` (or no def);
     *  - kind 'container' : at least one container marker precedes the def;
     *    `prefix` is the stripped marker run.
     *
     * A TASK item (`- [ ] …`) terminates stripping: there the `[^a]:` is
     * ordinary checked-item content, not a footnote definition (matches the
     * oracle carve-js, which leaves it literal).
     *
     * @return array{kind: string, prefix: string}
     */
    protected function footnoteContainerPrefix(string $line, int $contentCol = 0): array
    {
        $rest = $line;
        $stripped = false;
        do {
            $previous = $rest;

            // Blockquote marker `>` alone or `>` then a literal space. The
            // marker may be INDENTED: inside a list item the quote sits at the
            // item's content column (`- a` / `  > [^f]: x`), and testing
            // position 0 only left that line unstripped - so the definition was
            // never collected while the block parser still emptied the quote,
            // and the author's line rendered nothing AND defined nothing
            // (carve-php#788). The list-marker arm below already ltrims.
            $quoteContent = $this->blockQuoteLineContent($rest);
            if (
                $quoteContent === null
                && $contentCol > 0
                && strlen($rest) - strlen(ltrim($rest, " \t")) >= $contentCol
            ) {
                // EXACTLY the item's content column, never arbitrary
                // indentation: a top-level `    > [r]: /u` is indented text,
                // not a quote (tests/BlockquoteRefDefTest).
                $quoteContent = $this->blockQuoteLineContent(substr($rest, $contentCol));
            }
            if ($quoteContent !== null) {
                $rest = $quoteContent;
                $stripped = true;

                continue;
            }

            // Ordered/bullet list marker (non-task). Use the canonical list
            // parser so every accepted marker (bullet `-`/`*`/`+`, decimal /
            // alpha / roman ordered) is recognized identically to the real
            // parser; a task marker stops the loop (its content is not a def).
            $trimmed = ltrim($rest);
            $info = $this->listParser->parseListItemMarker($trimmed);
            if ($info !== null && $info['type'] !== 'task') {
                $markerWidth = strlen($rest) - strlen((string)$info['content']);
                $rest = substr($rest, $markerWidth);
                $stripped = true;
            }
        } while ($rest !== $previous);

        if ($stripped && preg_match('/^\[\^[^\]]+\]:/', $rest)) {
            return ['kind' => 'container', 'prefix' => substr($line, 0, strlen($line) - strlen($rest))];
        }

        return ['kind' => 'none', 'prefix' => ''];
    }

    /**
     * Extract abbreviation definitions from the document
     *
     * Syntax: *[ABBR]: Full Definition Text
     *
     * This is an extension feature inspired by PHP Markdown Extra.
     *
     * @param array<string> $lines
     */
    protected function extractAbbreviations(array $lines): void
    {
        $i = 0;
        $count = count($lines);
        $firstAbbreviationLine = null;
        // OPAQUE content defines nothing. A `*[A]: x` inside a fenced code
        // SAMPLE registered an abbreviation for the whole document, so
        // documenting the syntax changed the prose around it; inside a LINE
        // BLOCK it did the same and was expanded in place, showing an <abbr>
        // in verse the author never wrote (carve#573, carve#574).
        //
        // The footnote scan beside this one already tracks code fences for the
        // same reason. Both fences close on their own width, so a wider opener
        // is not closed by a narrower run.
        $fenceChar = null;
        $fenceLen = 0;
        $verseFence = 0;
        // PART 12 §7 recognizes an abbreviation definition only at document
        // level. The pattern is anchored, so a block quote or list marker
        // prefix already disqualifies a line. Two containers add no prefix of
        // their own and so need tracking here: a `:::` div, and an open list
        // item whose lazy continuation a flush-left line folds into.
        $divs = [];
        $inListItem = false;

        while ($i < $count) {
            $line = $lines[$i];

            if (IndentationHelper::isBlankLine($line)) {
                $inListItem = false;
            } elseif (preg_match(self::LIST_ITEM_CONTEXT_PATTERN, $line) === 1) {
                $inListItem = true;
            }

            if ($fenceChar !== null) {
                if (
                    preg_match('/^([`~]{3,})\s*$/', $line, $fm)
                    && $fm[1][0] === $fenceChar
                    && strlen($fm[1]) >= $fenceLen
                ) {
                    $fenceChar = null;
                    $fenceLen = 0;
                }
                $i++;

                continue;
            }
            if ($verseFence > 0) {
                if (preg_match('/^(:{3,})\s*$/', $line, $vm) && strlen($vm[1]) >= $verseFence) {
                    $verseFence = 0;
                }
                $i++;

                continue;
            }
            if (preg_match('/^([`~]{3,})/', $line, $fo) === 1) {
                $fenceChar = $fo[1][0];
                $fenceLen = strlen($fo[1]);
                $i++;

                continue;
            }
            if (preg_match('/^(:{3,})[ \t]*\|(?:[ \t]*\{.*\})?[ \t]*$/', $line, $vo) === 1) {
                $verseFence = strlen($vo[1]);
                $i++;

                continue;
            }
            // Colon fences close on an exact length match, so the stack records
            // the opener width rather than just a depth count.
            if (preg_match('/^(:{3,})[ \t]*(.*)$/', $line, $cm) === 1) {
                $width = strlen($cm[1]);
                if ($cm[2] === '' && $divs !== [] && end($divs) === $width) {
                    array_pop($divs);
                } else {
                    $divs[] = $width;
                }
            }

            // Match abbreviation definition: *[abbr]: definition. The pattern
            // is anchored to a leading `*`, so skip it on any other line.
            if (
                ($line[0] ?? '') === '*'
                && $divs === []
                && !$inListItem
                && preg_match(self::ABBREVIATION_DEFINITION_PATTERN, $line, $matches)
            ) {
                $firstAbbreviationLine ??= $i;
                $abbr = $matches[1];
                $definition = trim($matches[2]);

                // Collect continuation lines (indented)
                $j = $i + 1;
                while ($j < $count) {
                    $nextLine = $lines[$j];
                    if (IndentationHelper::isBlankLine($nextLine)) {
                        break;
                    }
                    // Check if next line starts a new abbreviation definition
                    if ($this->isAbbreviationDefinitionLine($nextLine)) {
                        break;
                    }
                    if ($this->startsNewBlock($nextLine)) {
                        break;
                    }
                    // A list marker (bullet or ordered, at any indent) starts a
                    // list, not a definition continuation. startsNewBlock() no
                    // longer reports list markers (symmetric interruption), so
                    // check explicitly to avoid swallowing the list.
                    if ($this->listParser->parseListItemMarker(ltrim($nextLine)) !== null) {
                        break;
                    }
                    // Continuation line (indented)
                    if (preg_match('/^\s+(.+)$/', $nextLine, $contMatch)) {
                        $definition .= ' ' . $contMatch[1];
                        $j++;
                    } else {
                        break;
                    }
                }

                // Store the abbreviation (case-sensitive). The map answers
                // WHICH definition wins - the last one (PART 9R) - and the
                // list keeps every line the author wrote, shadowed ones
                // included, because the tree is pre-resolve (PART 12 section
                // 3a).
                $this->abbreviations[$abbr] = $definition;
                $this->abbreviationDefinitions[] = ['abbr' => $abbr, 'expansion' => $definition];
                // The definition's own lines, so the node built for it at
                // serialization has somewhere to point. A continuation line is
                // part of the definition, so the span covers `$i` through the
                // last line consumed rather than the opener alone.
                if ($this->trackPositions) {
                    $lineMap = [];
                    for ($k = $i; $k < $j; $k++) {
                        $lineMap[] = $this->sourceLineFor($k);
                    }
                    $span = $this->spanForLineMap($lineMap);
                    if ($span !== null) {
                        $this->abbreviationSpans[$abbr] = $span->toArray();
                    }
                }
                $i = $j;

                continue;
            }

            $i++;
        }

        if ($firstAbbreviationLine !== null) {
            $firstBodyLine = null;
            foreach ($lines as $lineNumber => $line) {
                if (IndentationHelper::isBlankLine($line) || $this->isAbbreviationDefinitionLine($line)) {
                    continue;
                }
                $firstBodyLine = $lineNumber;

                break;
            }
            $this->abbreviationsBeforeBody = $firstBodyLine === null || $firstAbbreviationLine < $firstBodyLine;
        }
    }

    /**
     * Is this document one where the difference between the two ways of
     * building the heading index can be observed?
     *
     * Two consumers read it. A reference link - `[text][ref]` or the collapsed
     * `[text][]`, both containing `][` - resolves through it. And anchor
     * validation asks whether an id exists, which is why `](#` counts too: a
     * heading in a list item that the line scan missed was reported as a broken
     * anchor even in a document with no reference link at all. That half only
     * matters when warnings are being collected, so it is gated on that as
     * well.
     *
     * Everything else is left on the cheap line scan, where the index it builds
     * is never read.
     */
    protected function needsStructuredHeadingIndex(string $input): bool
    {
        if (str_contains($input, '][')) {
            return true;
        }

        return $this->collectWarnings && str_contains($input, '](#');
    }

    /**
     * Build the implicit-reference index from parsed block structure.
     *
     * The blocks are parsed into a SCRATCH document and thrown away. That costs
     * a second block parse, and buys the one thing a line scan cannot have:
     * knowing whether an indented `#` line is a heading inside a list item or a
     * paragraph at top level. Every mutable parse state the scratch run touched
     * is reset afterwards and the extraction passes re-run, so the real parse
     * starts from the same place it would have.
     *
     * @param array<string> $lines
     */
    protected function indexHeadingsFromStructure(array $lines): void
    {
        $scratch = new Document();
        $this->parseBlocks($scratch, $lines, 0, topLevel: true);

        $tracker = new HeadingIdTracker();
        $tracker->setIdTransformer($this->headingIdTransformer);
        $tracker->setLowercase($this->headingIdLowercase);
        $index = [];
        $ids = [];
        // Document order, over every heading including nested ones - the same
        // walk CrossReferenceResolver does at render time, so the ids this
        // registers are the ids the output will carry.
        $this->collectHeadingReferences($scratch, $tracker, false, $index, $ids);

        $this->resetParseState();
        $this->extractReferences($lines);
        $this->extractFootnotes($lines);
        $this->extractAbbreviations($lines);

        foreach ($index as $label => $id) {
            $this->registerHeadingReference((string)$label, new ReferenceDefinition('#' . $id, [], 0, null, true));
        }
        // Anchor validation asks a different question - does an element with
        // this id exist - so it takes EVERY heading, blockquote ancestors
        // included. Leaving these out warned "broken anchor link" for a link
        // to a heading that is right there.
        foreach ($ids as $id => $_present) {
            $this->headingIds[(string)$id] = true;
        }
    }

    /**
     * Walk block structure, registering every heading the index may hold.
     *
     * A BLOCKQUOTE ancestor is the one exclusion (PART 11 R1): it carries
     * another document's headings, so its wording is not the author's to
     * reference. A list item, definition, div or admonition is the author's own
     * grouping inside their own document, so those are included. The id is
     * still resolved for an excluded heading, because it stays a valid `</#id>`
     * crossref target and skipping it would shift the dedup counter.
     *
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param \MarkupCarve\Carve\Renderer\HeadingIdTracker $tracker
     * @param bool $inBlockquote
     * @param array<string, string> $index
     * @param array<string, bool> $ids
     * @param int $depth
     */
    protected function collectHeadingReferences(
        Node $node,
        HeadingIdTracker $tracker,
        bool $inBlockquote,
        array &$index,
        array &$ids,
        int $depth = 0,
    ): void {
        if ($depth >= self::MAX_HEADING_WALK_DEPTH) {
            return;
        }

        foreach ($node->getChildren() as $child) {
            if ($child instanceof Heading) {
                $id = $tracker->getIdForHeading($child);
                $ids[$id] = true;
                $label = preg_replace('/\s+/', ' ', trim($tracker->getPlainText($child))) ?? '';
                if (!$inBlockquote && $label !== '' && !isset($index[$label])) {
                    $index[$label] = $id;
                }

                continue;
            }

            $this->collectHeadingReferences(
                $child,
                $tracker,
                $inBlockquote || $child instanceof BlockQuote,
                $index,
                $ids,
                $depth + 1,
            );
        }
    }

    /**
     * Every mutable parse state, in one place.
     *
     * Called at the start of a parse and again after the scratch structure
     * pass, so the two entry points cannot drift.
     */
    protected function resetParseState(): void
    {
        $this->references = [];
        $this->headingReferencesByFoldedLabel = [];
        $this->footnotes = [];
        $this->abbreviations = [];
        $this->abbreviationDefinitions = [];
        $this->abbreviationsBeforeBody = false;
        $this->pendingAttributes = [];
        $this->pendingAttributeOrder = [];
        $this->warnings = [];
        $this->usedReferences = [];
        $this->anchorLinks = [];
        $this->headingIds = [];
        $this->lineOffset = 0;
    }

    /**
     * Extract heading IDs as implicit reference definitions
     * This allows [Heading][] style links to headings
     *
     * @param array<string> $lines
     */
    protected function extractHeadingReferences(array $lines): void
    {
        $headingIdTracker = new HeadingIdTracker();
        $headingIdTracker->setIdTransformer($this->headingIdTransformer);
        $headingIdTracker->setLowercase($this->headingIdLowercase);
        $pendingId = null;
        $count = count($lines);
        $listContentColumns = [];
        $fenceChar = null;
        $fenceLength = 0;

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            $scan = $this->headingReferenceScanLine($line, $listContentColumns);
            $contentLine = $scan['content'];

            if ($fenceChar !== null) {
                if ($this->fencedBlockParser->isCodeFenceCloser($contentLine, $fenceChar, $fenceLength)) {
                    $fenceChar = null;
                    $fenceLength = 0;
                }

                continue;
            }

            $rawFenceInfo = $this->fencedBlockParser->parseRawBlockOpener($contentLine);
            if ($rawFenceInfo !== null) {
                $fenceChar = $rawFenceInfo['fence'][0];
                $fenceLength = $rawFenceInfo['length'];

                continue;
            }

            $codeFenceInfo = $this->fencedBlockParser->parseCodeFenceOpener($contentLine);
            if ($codeFenceInfo !== null) {
                $fenceChar = $codeFenceInfo['char'];
                $fenceLength = $codeFenceInfo['length'];

                continue;
            }

            if ($scan['quoted']) {
                $pendingId = null;

                continue;
            }

            // Check for an explicit id on a block-attribute line before the
            // heading -- bare ({#custom-id}) or part of a fuller list
            // ({#id .class key=val}), single- or multi-line. Attribute lines
            // accumulate (§15, last id wins); an attribute line without an id
            // keeps a pending one. Mirrors the tryParseBlockAttributes gates
            // (first content char [.#a-zA-Z], not a comment/braced inline
            // marker) so the pre-scan accepts exactly what the parser does.
            $attrStr = $this->scanBlockAttributeLines($lines, $i, $consumed);
            if ($attrStr !== null) {
                // Same source-order merge the parser uses (later token wins,
                // e.g. `{id=bar #foo}` -> foo), so the pre-scan id always
                // matches the rendered one.
                $attrs = $attrStr === '' ? [] : AttributeParser::parseAndMerge([], $attrStr);
                if (isset($attrs['id'])) {
                    $pendingId = $attrs['id'];
                }
                $i += $consumed - 1;

                continue;
            }

            // Match heading: 1-6 # characters at column 0, followed by space(s) and content
            // Space after # is syntax delimiter, not indentation - must be space(s) per spec, not tab.
            // The marker MUST start at column 0 (no leading indent): an indented `#`-line is a
            // paragraph, matching carve-js / carve-rs and the spec grammar (heading_first_line =
            // heading_marker, space, ...).
            if (($contentLine[0] ?? '') === '#' && preg_match('/^(#{1,6}) +(.*\S.*)$/', $contentLine, $matches)) {
                // Content required (same rule as tryParseHeading): a bare
                // `#` / `# ` is not a heading and must not consume a slug here.
                $headingText = trim($matches[2]);
                $headingParts = [[$i, $headingText]];
                $level = strlen($matches[1]);

                // SINGLE-LINE HEADINGS: a heading ends at the newline, so the
                // label is this line's text alone. This mirrors tryParseHeading
                // so the implicit-reference label agrees with the rendered id.

                // Fast path: a heading whose collected text is purely letters,
                // numbers and spaces has no inline markup, so its plain text
                // equals the raw text and its id resolves without building and
                // inline-parsing a Heading node. (Smart typography only rewrites
                // punctuation, which slugging collapses, so the id is identical.)
                // Skips one of the two inline parses per heading.
                if ($pendingId === null && $headingText !== '' && preg_match('/^[\p{L}\p{N} ]+$/u', $headingText) === 1) {
                    $label = preg_replace('/\s+/', ' ', $headingText) ?? $headingText;
                    $id = $headingIdTracker->getIdForText($label);
                    $this->headingIds[$id] = true;
                    $reference = new ReferenceDefinition('#' . $id, [], $i, null, true);
                    $this->registerHeadingReference($label, $reference);

                    continue;
                }

                $heading = new Heading(strlen($matches[1]));
                if ($pendingId !== null) {
                    $heading->setAttribute('id', $pendingId);
                    $pendingId = null;
                }
                // One segment for the heading's single line: a run of its own
                // source line.
                $headingContentLines = [];
                foreach ($headingParts as [$partIndex, $partText]) {
                    $partSourceLine = $this->sourceLineFor($partIndex);
                    $partColumn = $partSourceLine < 0
                        ? false
                        : strpos($this->sourceLines[$partSourceLine] ?? '', $partText);
                    $headingContentLines[] = [
                        $partSourceLine,
                        $partColumn === false ? 0 : $partColumn,
                        strlen($partText),
                        $partText,
                    ];
                }

                $this->inlineParser->parseHeading(
                    $heading,
                    $headingText,
                    $i,
                    $this->foldedLinesMap($headingContentLines),
                );

                $plainText = $headingIdTracker->getPlainText($heading);
                $id = $headingIdTracker->getIdForHeading($heading);
                $this->headingIds[$id] = true;

                // Register as reference if not already defined
                // Use normalized plain text as the label (for [Heading][] style links)
                $label = preg_replace('/\s+/', ' ', trim($plainText)) ?? $plainText;
                $reference = new ReferenceDefinition('#' . $id, [], $i, null, true);
                $this->registerHeadingReference($label, $reference);
            } else {
                // Non-heading, non-attribute line - clear pending ID
                if (!IndentationHelper::isBlankLine($contentLine)) {
                    $pendingId = null;
                }
            }
        }
    }

    /**
     * Present a raw top-level line as implicit heading-reference extraction
     * should see it after list containers expose their content. Blockquote
     * ancestry is returned separately so quoted headings are deliberately
     * skipped in either container order.
     *
     * @param string $line
     * @param list<int> $listContentColumns
     *
     * @return array{content: string, quoted: bool, openedList: bool}
     */
    protected function headingReferenceScanLine(string $line, array &$listContentColumns): array
    {
        if (!IndentationHelper::isBlankLine($line)) {
            $leadingColumns = IndentationHelper::getLeadingColumns($line);
            while ($listContentColumns !== [] && $leadingColumns < $listContentColumns[array_key_last($listContentColumns)]) {
                array_pop($listContentColumns);
            }
        }

        $baseColumn = $listContentColumns === []
            ? 0
            : $listContentColumns[array_key_last($listContentColumns)];
        $content = $baseColumn === 0 ? $line : IndentationHelper::stripLeadingColumns($line, $baseColumn);
        $quoted = false;
        $openedList = false;

        while ($content !== '') {
            $stripped = ltrim($content, " \t");
            $leadingColumns = IndentationHelper::getLeadingColumns($content);

            $quoteContent = $this->blockQuoteLineContent($stripped);
            if ($quoteContent !== null) {
                $quoted = true;
                $content = $quoteContent;

                continue;
            }

            $itemInfo = $this->listParser->parseListItemMarker($stripped);
            if ($itemInfo === null) {
                break;
            }

            $openedList = true;
            /** @var string $itemContent */
            $itemContent = $itemInfo['content'];
            // The measured width, which is now what the list parser itself
            // uses (carve-php#580). While a bullet was pinned at 2 here, this
            // scan deliberately hardcoded 2 as well so it could not index a
            // heading the renderer never emitted; both sides measure now, so
            // the pre-scan and the parse agree by construction.
            $markerWidth = $this->listMarkerWidth($stripped, $itemInfo);
            $baseColumn += $leadingColumns + $markerWidth;
            $listContentColumns[] = $baseColumn;
            $content = $itemContent;
        }

        return ['content' => $content, 'quoted' => $quoted, 'openedList' => $openedList];
    }

    /**
     * Recognize a block-attribute line (single- or multi-line) starting at
     * $start, WITHOUT applying it. Returns the joined attribute string and
     * sets $consumed to the number of lines the block spans, or returns null
     * when the line is not a block-attribute line. Mirrors the recognition
     * rules of tryParseBlockAttributes() exactly: `{...}` with the first
     * content character in [.#a-zA-Z] (excludes braced inline markers like
     * `{=x=}`); a multi-line block needs indented
     * continuation lines and a closing `}`.
     *
     * @param array<string> $lines
     * @param int $start
     * @param int|null $consumed
     */
    protected function scanBlockAttributeLines(array $lines, int $start, ?int &$consumed): ?string
    {
        $consumed = 0;
        $line = $lines[$start];

        if (!str_starts_with($line, '{')) {
            return null;
        }

        // A bare `{}` line is NOT a block-attribute block: block_attributes
        // requires at least one attribute (grammar §15), and there is no
        // block-level blessed-empty exception (only the inline `[text]{}` form
        // is blessed). So it stays a literal paragraph, matching carve-js /
        // carve-rs.

        // Single-line block: {.class #id key=value}, including adjacent
        // blocks that merge in order: {.class}{#id}.
        $singleLineAttrStr = $this->parseSingleLineBlockAttributePayload($line);
        if ($singleLineAttrStr !== null) {
            $attrStr = $singleLineAttrStr;
            if (!preg_match('/^[.#a-zA-Z]/', $attrStr) || str_starts_with($attrStr, '%')) {
                return null;
            }
            $consumed = 1;

            return $attrStr;
        }

        // Multi-line block: { on the first line, } on a later line, with
        // indented continuation lines in between.
        $count = count($lines);
        $attrContent = substr($line, 1);
        $i = $start + 1;
        while ($i < $count) {
            $nextLine = $lines[$i];
            if (preg_match('/^(.*)\}\s*$/', $nextLine, $closeMatch)) {
                $attrStr = trim($attrContent . ' ' . $closeMatch[1]);
                if (!preg_match('/^[.#a-zA-Z]/', $attrStr) || str_starts_with($attrStr, '%')) {
                    return null;
                }
                $consumed = $i - $start + 1;

                return $attrStr;
            }
            if (preg_match('/^\s+(.*)$/', $nextLine, $contMatch)) {
                $attrContent .= ' ' . $contMatch[1];
                $i++;
            } else {
                return null;
            }
        }

        return null;
    }

    /**
     * Recurse into block content, but cap nesting depth so pathologically
     * nested input degrades to literal text instead of overflowing the stack
     * (or exhausting memory). See MAX_NESTING_DEPTH.
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $indent
     * @param array<int, int>|null $lineMap
     * @param bool $topLevel
     */
    protected function parseBlocks(Node $parent, array $lines, int $indent, ?array $lineMap = null, bool $topLevel = false): void
    {
        if ($this->nestingDepth >= self::MAX_NESTING_DEPTH) {
            // PART 9 §25: past the cap an opener degrades to ORDINARY PARAGRAPH
            // TEXT, and therefore groups by the ordinary paragraph rule -
            // consecutive over-cap openers and the text after them form ONE
            // paragraph, ending at the first blank line like any other, with no
            // trailing newline before `</p>`. Handing the whole remainder to
            // one paragraph kept the document's trailing newline inside it and
            // swallowed blank lines that end a paragraph everywhere else
            // (carve-php#702).
            $group = [];
            foreach ($lines as $line) {
                if (IndentationHelper::isBlankLine($line)) {
                    $this->appendDegradedParagraph($parent, $group);
                    $group = [];

                    continue;
                }
                $group[] = $line;
            }
            $this->appendDegradedParagraph($parent, $group);

            return;
        }

        $this->nestingDepth++;
        $previousLineMap = $this->currentLineMap;
        $previousCommentFenceLastIndex = $this->commentFenceLastIndex;
        $this->currentLineMap = $lineMap;
        $this->commentFenceLastIndex = null;
        try {
            $this->parseBlocksImpl($parent, $lines, $indent, $topLevel);
        } finally {
            $this->currentLineMap = $previousLineMap;
            $this->commentFenceLastIndex = $previousCommentFenceLastIndex;
            $this->nestingDepth--;
        }
    }

    /**
     * One paragraph of over-cap content (PART 9 §25), or nothing when the
     * group holds no visible text.
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $group
     */
    private function appendDegradedParagraph(Node $parent, array $group): void
    {
        $text = rtrim(implode("\n", $group), "\n");
        if (trim($text) === '') {
            return;
        }

        $paragraph = new Paragraph();
        $this->inlineParser->parse($paragraph, $text);
        $parent->appendChild($paragraph);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $indent
     * @param bool $topLevel
     */
    private function parseBlocksImpl(Node $parent, array $lines, int $indent, bool $topLevel = false): void
    {
        $i = 0;
        $count = count($lines);

        while ($i < $count) {
            $line = $lines[$i];

            // Skip blank lines
            if (IndentationHelper::isBlankLine($line)) {
                $i++;

                continue;
            }

            // Try to parse block attributes first
            $attrConsumed = $this->tryParseBlockAttributes($lines, $i);
            if ($attrConsumed !== null) {
                $i += $attrConsumed;

                continue;
            }

            // Source-line tracking (opt-in): remember where this block starts and
            // how many children the parent had, so newly appended blocks can be
            // stamped with `data-source-line` after the dispatch below.
            $sourceLine = $this->sourceLineFor($i);
            $tracking = $this->trackSourceLines || $this->trackPositions;
            $childrenBefore = ($tracking && $sourceLine >= 0) ? count($parent->getChildren()) : -1;

            // A bare `---` at the very start of the document is ambiguous between
            // a thematic break and the opening of bare frontmatter (`---\n…\n---`).
            // Give registered block matchers first refusal at this one position so
            // the frontmatter extension can capture the block raw, before core reads
            // the `---` as a thematic break (which would route the body through
            // inline parsing and corrupt quotes/dashes/ellipses). Scoped to the
            // exact `---` opener so core-first still holds for every other line and
            // every other thematic-break shape (***, ___, ----); a lone `---` with
            // no closing fence is declined, leaving it a thematic break.
            if ($topLevel && !$parent->hasChildren() && preg_match('/^---\s*$/', $line) === 1) {
                $matchConsumed = $this->tryBlockMatchers($parent, $lines, $i);
                if ($matchConsumed !== null) {
                    $this->stampSourceLine($parent, $childrenBefore, $sourceLine);
                    $i += $matchConsumed;

                    continue;
                }
            }

            // Fast path: a line whose first non-blank char is a LETTER can only
            // be a paragraph, a custom block matcher, or an alphabetic/roman
            // ordered list (`a.`, `iv.`) - every other core block opener begins
            // with punctuation or a digit. So skip the ~16-probe tryParse
            // cascade and try only the list parser before falling through. This
            // is the common case (prose) and the dominant per-line cost in PHP,
            // where each preg_match carries real call overhead. (A first-char
            // switch over the punctuation openers was tried too but added no
            // measurable gain - for those lines the actual parsing, not the
            // dispatch probes, dominates - so only the prose fast path is kept.)
            $ws = strspn($line, " \t");
            $fc = $line[$ws] ?? '';
            if ($fc !== '' && ($fc >= 'a' && $fc <= 'z' || $fc >= 'A' && $fc <= 'Z')) {
                $consumed = $this->tryParseList($parent, $lines, $i)
                    ?? $this->tryBlockMatchers($parent, $lines, $i)
                    ?? $this->tryParseParagraph($parent, $lines, $i, $topLevel);
                $this->stampSourceLine($parent, $childrenBefore, $sourceLine);
                $i += $consumed;

                continue;
            }

            // Try to match block elements in order of precedence
            // Fenced comment must come before thematic break (%%% vs ---)
            // Comment and raw block must come before code block since ``` =format is a special case
            // Caption must come before paragraph to catch `^ caption text`
            $consumed = $this->tryParseFencedComment($parent, $lines, $i)
                ?? $this->tryParseComment($parent, $lines, $i)
                ?? $this->tryParseRawBlock($parent, $lines, $i)
                ?? $this->tryParseCodeBlock($parent, $lines, $i)
                ?? $this->tryParseLineBlock($parent, $lines, $i)
                ?? $this->tryParseHardBreaksBlock($parent, $lines, $i)
                ?? $this->tryParseDiv($parent, $lines, $i)
                ?? $this->tryParseDefinitionList($parent, $lines, $i)
                ?? $this->tryParseHeading($parent, $lines, $i)
                ?? $this->tryParseThematicBreak($parent, $line, $i)
                ?? $this->tryParseBlockQuote($parent, $lines, $i)
                ?? $this->tryParseList($parent, $lines, $i)
                ?? $this->tryParseTable($parent, $lines, $i)
                ?? $this->tryParseFootnoteDefinition($lines, $i)
                ?? $this->tryParseReferenceDefinition($lines, $i)
                ?? $this->tryParseAbbreviationDefinition($parent, $lines, $i, $topLevel)
                ?? $this->tryParseCaption($parent, $lines, $i);

            if ($consumed === null) {
                $matchConsumed = $this->tryBlockMatchers($parent, $lines, $i);
                if ($matchConsumed !== null) {
                    $this->stampSourceLine($parent, $childrenBefore, $sourceLine);
                    $i += $matchConsumed;

                    continue;
                }
            }

            $consumed ??= $this->tryParseParagraph($parent, $lines, $i, $topLevel);

            // The block ran from $i to $i + $consumed - 1 in THIS line array;
            // resolve the last one back to the top-level array the offsets are
            // keyed by, the same way the first one was.
            $this->stampSourceLine(
                $parent,
                $childrenBefore,
                $sourceLine,
                $consumed > 0 ? $this->sourceLineFor($i + $consumed - 1) : $sourceLine,
            );
            $i += $consumed;
        }
    }

    private function sourceLineFor(int $index): int
    {
        return $this->currentLineMap[$index] ?? ($this->currentLineMap === null ? $index : -1);
    }

    /**
     * Stamp `data-source-line` on any children appended to $parent since
     * $childrenBefore, using the 1-based source line the block started on.
     * No-op unless source-line tracking is enabled (childrenBefore === -1).
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param int $childrenBefore Child count before the block was parsed, or -1 when disabled.
     * @param int $sourceLine 0-indexed original source line; emitted as 1-based (+1).
     * @param int $endLine
     *
     * @return void
     */
    private function stampSourceLine(Node $parent, int $childrenBefore, int $sourceLine, int $endLine = -1): void
    {
        if ($childrenBefore < 0 || $sourceLine < 0) {
            return;
        }

        $children = $parent->getChildren();
        $total = count($children);
        for ($k = $childrenBefore; $k < $total; $k++) {
            if ($this->trackPositions) {
                $this->stampBlockSpan($children[$k], $sourceLine, $endLine < 0 ? $sourceLine : $endLine);
            }
            if (!$this->trackSourceLines || !$this->canStampSourceLine($children[$k])) {
                continue;
            }
            if ($children[$k]->getAttribute('data-source-line') === null) {
                $children[$k]->setAttribute('data-source-line', (string)($sourceLine + 1));
            }
        }
    }

    /**
     * Give a block the span covering the source lines it was parsed from.
     *
     * Only set when both ends resolve to a recorded line, and never overwritten:
     * a parser that already placed a node more precisely than "these whole
     * lines" knows better than this does.
     */
    private function stampBlockSpan(Node $node, int $startLine, int $endLine): void
    {
        if ($node->getPos() !== null) {
            return;
        }

        $start = $this->lineStartOffsets[$startLine] ?? null;
        $end = $this->lineStartOffsets[$endLine] ?? null;
        if ($start === null || $end === null) {
            // Synthesized content (a footnote section, a resolved reference)
            // has no line of its own. §4 forbids inventing one.
            return;
        }

        $endOffset = $end + strlen($this->sourceLines[$endLine] ?? '');
        $node->setPos($this->positionIndex?->span($start, $endOffset, $startLine + 1, $endLine + 1, $start, $end));
    }

    private function stampNodeSourceLine(Node $node, int $sourceLine): void
    {
        if (!$this->trackSourceLines || $sourceLine < 0 || !$this->canStampSourceLine($node)) {
            return;
        }
        if ($node->getAttribute('data-source-line') === null) {
            $node->setAttribute('data-source-line', (string)($sourceLine + 1));
        }
    }

    private function canStampSourceLine(Node $node): bool
    {
        return !(
            $node instanceof RawBlock
            || $node instanceof Comment
            || $node instanceof TableRow
            || $node instanceof TableCell
        );
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryBlockMatchers(Node $parent, array $lines, int $start): ?int
    {
        if ($this->blockMatchers === []) {
            return null;
        }

        $previousParent = $this->currentMatcherParent;
        $this->currentMatcherParent = $parent;
        $ctx = new MatcherContext($this, $this->getInlineParser());
        try {
            foreach ($this->sortedBlockMatchers() as $matcher) {
                $result = $matcher($lines, $start, $ctx);
                if ($result === null) {
                    continue;
                }
                // Legacy addBlockPattern callbacks append to $parent themselves
                // and report only the line count.
                if (is_int($result)) {
                    return $result;
                }
                // Normative matchers return the node for the dispatcher to append.
                $parent->appendChild($result['node']);

                return $result['linesConsumed'];
            }
        } finally {
            $this->currentMatcherParent = $previousParent;
        }

        return null;
    }

    /**
     * Try to parse block attributes {.class #id key=value}
     *
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseBlockAttributes(array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Must start with {
        if (!str_starts_with($line, '{')) {
            return null;
        }

        // A bare `{}` line is NOT a block-attribute block (block_attributes
        // needs >= 1 attribute, no block-level blessed-empty); it stays a
        // literal paragraph, matching carve-js / carve-rs.

        // Check for single-line attribute: {.class}, {#id}, {key=value}, or
        // adjacent blocks like {.class}{#id}.
        $singleLineAttrStr = $this->parseSingleLineBlockAttributePayload($line);
        if ($singleLineAttrStr !== null) {
            $attrStr = $singleLineAttrStr;
            // Exclude _ * = + - ~ ^ which are braced inline markers (not block attributes)
            if (!preg_match('/^[.#a-zA-Z]/', $attrStr) || str_starts_with($attrStr, '%')) {
                return null;
            }

            // The whole payload must be a valid attribute block, else it is
            // not a block-attribute line (§14) and stays literal paragraph
            // text. One invalid name (`.123`, `#1`, `2=v`, `.a!b`) -- even
            // mixed with valid ones (`.ok .1`) -- invalidates the whole line,
            // matching the inline path and carve-js.
            if (!$this->inlineParser->isValidAttrPayload($attrStr)) {
                return null;
            }

            // A definition that follows renders nothing, and §15 A2a says
            // pending floats PAST anything that renders nothing to the next
            // VISIBLE block. This used to drop the attributes here and hand
            // them to the reference definition instead, so `{#i}` above
            // `[f]: u` was lost to the document and reappeared on every link
            // that used the label - the one place §15's "next block element"
            // was read as a construct that emits nothing (carve-php#702).
            $this->parseAttributeString($attrStr);

            return 1;
        }

        // Try multi-line attributes: { on first line, } on a later line
        // Collect lines until we find the closing }
        $count = count($lines);
        $attrContent = substr($line, 1); // Remove opening {
        $i = $start + 1;

        while ($i < $count) {
            $nextLine = $lines[$i];

            // Check if this line ends the attribute block
            if (preg_match('/^(.*)\}\s*$/', $nextLine, $closeMatch)) {
                $attrContent .= ' ' . $closeMatch[1];
                $attrStr = trim($attrContent);

                // Exclude _ * = + - ~ ^ which are braced inline markers (not block attributes)
                if (!preg_match('/^[.#a-zA-Z]/', $attrStr) || str_starts_with($attrStr, '%')) {
                    return null;
                }
                // The whole payload must be valid, else it is not a block-
                // attribute line (§14) and stays literal. One invalid name --
                // even mixed with valid ones -- invalidates the whole line.
                if (!$this->inlineParser->isValidAttrPayload($attrStr)) {
                    return null;
                }
                $this->parseAttributeString($attrStr);

                return $i - $start + 1;
            }

            // Continuation line (must be indented)
            if (preg_match('/^\s+(.*)$/', $nextLine, $contMatch)) {
                $attrContent .= ' ' . $contMatch[1];
                $i++;
            } else {
                // Not a valid continuation
                return null;
            }
        }

        return null;
    }

    /**
     * Parse attribute string and add to pending attributes
     */
    protected function parseAttributeString(string $attrStr): void
    {
        $parsed = AttributeParser::parseOrderedWithSlots($attrStr);
        if (isset($parsed['attributes']['class'], $this->pendingAttributes['class'])) {
            $parsed['attributes']['class'] = trim($this->pendingAttributes['class'] . ' ' . $parsed['attributes']['class']);
        }
        $this->pendingAttributes = array_merge($this->pendingAttributes, $parsed['attributes']);
        $this->pendingAttributeOrder = array_merge($this->pendingAttributeOrder, $parsed['order']);
    }

    /**
     * Apply pending attributes to a node and clear them
     */
    protected function applyPendingAttributes(Node $node): void
    {
        if ($this->pendingAttributes !== []) {
            $node->setAttributesWithOrder($this->pendingAttributes, $this->pendingAttributeOrder);
            $this->pendingAttributes = [];
            $this->pendingAttributeOrder = [];
        }
    }

    /**
     * Consume and return pending block attributes
     *
     * This allows custom block pattern callbacks to retrieve any block attributes
     * that were defined on the line(s) before the block started. The attributes
     * are cleared after retrieval.
     *
     * Example usage in a custom block callback:
     * ```php
     * $parser->addBlockPattern('/^---(\w+)/', function($lines, $start, $parent, $parser) {
     *     $myNode = new MyCustomNode();
     *     $attrs = $parser->consumePendingAttributes();
     *     if (!empty($attrs)) {
     *         $myNode->setAttributes($attrs);
     *     }
     *     $parent->appendChild($myNode);
     *     return 1;
     * });
     * ```
     *
     * @return array<string, string> The pending attributes (empty array if none)
     */
    public function consumePendingAttributes(): array
    {
        $attrs = $this->pendingAttributes;
        $this->pendingAttributes = [];
        $this->pendingAttributeOrder = [];

        return $attrs;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseCodeBlock(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Use FencedBlockParser to detect code fence opener
        $fenceInfo = $this->fencedBlockParser->parseCodeFenceOpener($line);
        if ($fenceInfo === null) {
            return null;
        }

        $fenceChar = $fenceInfo['char'];
        $fenceLength = $fenceInfo['length'];
        $info = $fenceInfo['info'];
        $header = $fenceInfo['header'];
        $label = $fenceInfo['label'];
        $indentLen = strlen($fenceInfo['indent']);

        $content = '';
        $i = $start + 1;
        $count = count($lines);
        $closed = false;

        while ($i < $count) {
            $currentLine = $lines[$i];

            // Check for closing fence
            if ($this->fencedBlockParser->isCodeFenceCloser($currentLine, $fenceChar, $fenceLength)) {
                $i++;
                $closed = true;

                break;
            }

            // Splitting a source that ends with a newline yields a phantom
            // empty final element. That newline terminates the preceding line,
            // it does not add a blank content line, so an unterminated fence
            // running to EOF must not absorb it (matching carve-js).
            if ($i === $count - 1 && $currentLine === '') {
                $i++;

                break;
            }

            // Remove indent from content lines (up to the same amount as opening fence)
            $currentLine = $this->fencedBlockParser->removeIndent($currentLine, $indentLen);

            $content .= $currentLine . "\n";
            $i++;
        }

        // A fence opener reaching this point is at block start (no open
        // paragraph): a mid-paragraph unterminated fence never gets here -- the
        // §10 closer-lookahead keeps it inside the paragraph as an inline
        // verbatim run. So an unclosed opener is always a block code fence that
        // runs to the end of the block, even when empty (matching carve-js /
        // canonical djot), rather than degrading to an inline code span.
        if (!$closed) {
            $this->addWarning('Unclosed code fence', $start, 1, true);
        }

        $language = $info !== '' ? $info : null;

        // Drop only the line separator before the closing fence. Code content
        // itself, including blank lines, is preserved verbatim.
        if (str_ends_with($content, "\n")) {
            $content = substr($content, 0, -1);
        }

        $codeBlock = new CodeBlock($content, $language, $label, $header);
        $this->applyPendingAttributes($codeBlock);
        // The opener "header" becomes the <pre> title attribute (rendering A),
        // unless a preceding {title=...} block-attribute line already set one
        // (the explicit attribute channel wins).
        if ($header !== null && !$codeBlock->hasAttribute('title')) {
            $codeBlock->setAttribute('title', $header);
        }
        $parent->appendChild($codeBlock);

        return $i - $start;
    }

    /**
     * Try to parse a `%%` line comment.
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseComment(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Carve line comment: a `%%` line (the `%%%` fenced form is handled
        // earlier by tryParseFencedComment) runs to end of line, not rendered.
        if (str_starts_with(ltrim($line), '%%')) {
            $parent->appendChild(new Comment(trim(substr(ltrim($line), 2))));

            return 1;
        }

        return null;
    }

    /**
     * Try to parse a fenced comment block %%% ... %%%
     *
     * This allows multi-line comments with blank lines.
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseFencedComment(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        $fenceInfo = $this->fencedBlockParser->parseFencedCommentOpenerAnyColumn($line);
        if ($fenceInfo === null) {
            return null;
        }

        $fenceLength = $fenceInfo['length'];
        if (!$this->hasClosingCommentFenceAhead($line, $lines, $start)) {
            $this->addWarning('Unclosed fenced comment', $start, 1, true);

            return null;
        }

        /** @var list<string> $contentLines */
        $contentLines = [];
        if ($fenceInfo['tail'] !== '') {
            $contentLines[] = $fenceInfo['tail'];
        }
        $i = $start + 1;
        $count = count($lines);

        while ($i < $count) {
            $currentLine = $lines[$i];

            if ($this->fencedBlockParser->isFencedCommentCloserAnyColumn($currentLine, $fenceLength)) {
                $i++;

                break;
            }

            $contentLines[] = $currentLine;
            $i++;
        }

        // Trim leading and trailing empty lines but preserve internal blank
        // lines - and preserve the INDENTATION of the lines that remain. A
        // comment body is verbatim text: `trim()` on the joined content ate
        // the first line's leading whitespace, so
        //
        //     %%%
        //       x
        //     %%%
        //
        // parsed to a comment holding `x` here and `  x` in carve-js and
        // carve-rs - a cross-engine AST difference, and a round trip through
        // `carve fmt` that silently moved the author's line (carve#653).
        while ($contentLines && trim(end($contentLines)) === '') {
            array_pop($contentLines);
        }
        while ($contentLines && trim($contentLines[0]) === '') {
            array_shift($contentLines);
        }

        $content = implode("\n", $contentLines);

        // Comments are stored but not rendered
        $comment = new Comment($content, $fenceLength);
        $parent->appendChild($comment);

        return $i - $start;
    }

    /**
     * Try to parse a raw block ``` =format
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseRawBlock(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Use FencedBlockParser to detect raw block opener
        $rawInfo = $this->fencedBlockParser->parseRawBlockOpener($line);
        if ($rawInfo === null) {
            return null;
        }

        $fenceLength = $rawInfo['length'];
        $format = $rawInfo['format'];
        // The closer must use the SAME fence character as the opener (` or ~);
        // a ~~~=html block closes with ~~~, not ```.
        $fenceChar = $rawInfo['fence'][0];

        $content = '';
        $i = $start + 1;
        $count = count($lines);
        $closed = false;

        while ($i < $count) {
            $currentLine = $lines[$i];

            // Check for closing fence (equal or longer)
            if ($this->fencedBlockParser->isCodeFenceCloser($currentLine, $fenceChar, $fenceLength)) {
                $i++;
                $closed = true;

                break;
            }

            $content .= $currentLine . "\n";
            $i++;
        }

        if (!$closed) {
            $this->addWarning('Unclosed raw block', $start, 1, true);
        }

        $rawBlock = new RawBlock(trim($content, "\n"), $format);
        $this->applyPendingAttributes($rawBlock);
        $parent->appendChild($rawBlock);

        return $i - $start;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */

    /**
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseDiv(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Use FencedBlockParser to detect div opener
        $divInfo = $this->fencedBlockParser->parseDivFenceOpener($line);
        if ($divInfo === null) {
            return null;
        }

        $fenceLength = $divInfo['length'];
        $className = $divInfo['className'];
        $label = $divInfo['label'];

        // STRICT (djot): the opener carries no inline attributes, so
        // `parseDivFenceOpener` has already guaranteed `$className` is empty
        // (bare `:::`), a type word, or `type "title"`. Split off the
        // optional quoted title; the type word is the div's primary class.
        // Attributes attach via a preceding block-attribute line only.
        $title = null;
        if ($className !== '' && preg_match('/^([a-zA-Z_][\w-]*)(?:\s+"([^"]*)")?$/', $className, $tm) === 1) {
            $className = $tm[1];
            $title = $tm[2] ?? null;
        }

        $div = new Div();

        // The opener `[label]` is inert structured metadata (NOT rendered); a
        // group extension (tabs) reads it as the tab name. Mirrors a code
        // fence's `[label]` on CodeBlock.
        if ($label !== null) {
            $div->setLabel($label);
        }

        // Leading block-attribute lines (`{.x}` before the opener) are the
        // only attribute source; they apply to the div in source order.
        if ($className !== '') {
            $div->addClass($className);
            $div->setTyped(true);
        }
        if ($title !== null) {
            $div->setHeader($title);
            $headerContainer = new Paragraph();
            $this->inlineParser->parse(
                $headerContainer,
                $title,
                $this->lineOffset + $start,
                sourceMap: $this->openerTitleMap($start, $title),
            );
            $div->setHeaderNodes($headerContainer->getChildren());
        }
        // Author source order, in the Node's canonical slot form (`#id` / `.class`
        // / key), taken from the pending attribute insertion order.
        $authorOrder = [];
        foreach (array_keys($this->pendingAttributes) as $name) {
            $authorOrder[] = $name === 'id' ? '#id' : ($name === 'class' ? '.class' : (string)$name);
        }
        foreach ($this->pendingAttributes as $name => $value) {
            if ($name === 'class') {
                foreach (preg_split('/\s+/', trim((string)$value)) ?: [] as $class) {
                    if ($class !== '') {
                        $div->addClass($class);
                    }
                }
            } else {
                $div->setAttribute($name, $value);
            }
        }
        // Storage stays class-first (the type class leads; the core renderer
        // emits it that way). But the type class polluted the recorded order,
        // so restore the author's SOURCE order for extensions and fmt (#304).
        // A type class with no authored class slot is not added to the order;
        // the extension serializer appends it after the ordered attributes.
        $div->setAttributeOrder($authorOrder);
        $this->pendingAttributes = [];
        $this->pendingAttributeOrder = [];

        $body = $this->collectColonFenceBody($lines, $start, $fenceLength, true);
        $innerLines = $body['lines'];
        $innerLineMap = $body['lineMap'];
        $i = $start + $body['consumed'];

        // Parse inner content as blocks (track line offset for nested content)
        $previousOffset = $this->lineOffset;
        $this->lineOffset = $previousOffset + $start + 1;
        $this->parseBlocks($div, $innerLines, 0, $innerLineMap);
        $this->lineOffset = $previousOffset;

        // (Pending block attributes were already applied before the
        // opener's own attributes above, per PART 9 §15 precedence.)
        $parent->appendChild($div);

        return $i - $start;
    }

    /**
     * Try to parse a local hard-break container (`::: \`).
     *
     * Unlike `::: |` line blocks, this parses ordinary block content and only
     * upgrades soft breaks in direct paragraph children. Nested blocks keep their
     * normal soft-break behavior.
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseHardBreaksBlock(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];
        if (preg_match('/^(?<fence>:{3,})[ \t]+\\\\[ \t]*$/', $line, $matches) !== 1) {
            return null;
        }

        $fenceLength = strlen($matches['fence']);
        $div = new Div();
        $div->addClass('hardbreaks');
        foreach ($this->pendingAttributes as $name => $value) {
            if ($name === 'class') {
                foreach (preg_split('/\s+/', trim((string)$value)) ?: [] as $class) {
                    if ($class !== '') {
                        $div->addClass($class);
                    }
                }
            } else {
                $div->setAttribute($name, $value);
            }
        }
        $this->pendingAttributes = [];

        $body = $this->collectColonFenceBody($lines, $start, $fenceLength, true);
        $innerLines = $body['lines'];
        $innerLineMap = $body['lineMap'];
        $i = $start + $body['consumed'];

        $previousOffset = $this->lineOffset;
        $this->lineOffset = $previousOffset + $start + 1;
        $this->parseBlocks($div, $innerLines, 0, $innerLineMap);
        $this->lineOffset = $previousOffset;

        $this->convertDirectParagraphSoftBreaksToHardBreaks($div);
        $parent->appendChild($div);

        return $i - $start;
    }

    protected function convertDirectParagraphSoftBreaksToHardBreaks(Div $div): void
    {
        foreach ($div->getChildren() as $child) {
            if (!$child instanceof Paragraph) {
                continue;
            }

            foreach ($child->getChildren() as $index => $inline) {
                if ($inline instanceof SoftBreak) {
                    $hardBreak = new HardBreak();
                    $hardBreak->setPos($inline->getPos());
                    $child->replaceChild($index, $hardBreak);
                }
            }
        }
    }

    /**
     * Collect a colon-fence body. Reparsed container bodies track nested colon
     * fences as a stack and skip opaque verbatim/comment spans; line blocks use
     * their own literal collector instead.
     *
     * @param array<string> $lines
     * @param int $start
     * @param int $fenceLength
     * @param bool $nestingAware
     *
     * @return array{lines: list<string>, lineMap: list<int>, consumed: int, closed: bool}
     */
    protected function collectColonFenceBody(array $lines, int $start, int $fenceLength, bool $nestingAware): array
    {
        $innerLines = [];
        $innerLineMap = [];
        $stack = [$fenceLength];
        $i = $start + 1;
        $count = count($lines);
        $closed = false;

        while ($i < $count) {
            if ($nestingAware) {
                $skippedTo = $this->appendOpaqueColonFenceSpan($lines, $i, $innerLines, $innerLineMap);
                if ($skippedTo !== null) {
                    $i = $skippedTo;

                    continue;
                }
            }

            $currentLine = $lines[$i];
            if ($this->isBareColonFence($currentLine, $colonLength) && $colonLength === end($stack)) {
                array_pop($stack);
                if ($stack === []) {
                    $i++;
                    $closed = true;

                    break;
                }

                $innerLines[] = $currentLine;
                $innerLineMap[] = $this->sourceLineFor($i);
                $i++;

                continue;
            }

            if ($nestingAware) {
                $opener = $this->fencedBlockParser->parseDivFenceOpener($currentLine);
                if ($opener !== null) {
                    $stack[] = $opener['length'];
                }
            }

            $innerLines[] = $currentLine;
            $innerLineMap[] = $this->sourceLineFor($i);
            $i++;
        }

        return [
            'lines' => $innerLines,
            'lineMap' => $innerLineMap,
            'consumed' => $i - $start,
            'closed' => $closed,
        ];
    }

    /**
     * @param array<string> $lines
     * @param int $start
     * @param list<string> $innerLines
     * @param list<int> $innerLineMap
     */
    protected function appendOpaqueColonFenceSpan(array $lines, int $start, array &$innerLines, array &$innerLineMap): ?int
    {
        $line = $lines[$start];
        $count = count($lines);
        $fenceChar = null;
        $fenceLength = 0;

        $rawFenceInfo = $this->fencedBlockParser->parseRawBlockOpener($line);
        if (
            $rawFenceInfo !== null
            && $this->hasCodeFenceCloserAhead($lines, $start, $rawFenceInfo['fence'][0], $rawFenceInfo['length'])
        ) {
            $fenceChar = $rawFenceInfo['fence'][0];
            $fenceLength = $rawFenceInfo['length'];
        } else {
            $codeFenceInfo = $this->fencedBlockParser->parseCodeFenceOpener($line);
            if (
                $codeFenceInfo !== null
                && $this->hasCodeFenceCloserAhead($lines, $start, $codeFenceInfo['char'], $codeFenceInfo['length'])
            ) {
                $fenceChar = $codeFenceInfo['char'];
                $fenceLength = $codeFenceInfo['length'];
            }
        }

        if ($fenceChar !== null) {
            for ($i = $start; $i < $count; $i++) {
                $innerLines[] = $lines[$i];
                $innerLineMap[] = $this->sourceLineFor($i);
                if ($i > $start && $this->fencedBlockParser->isCodeFenceCloser($lines[$i], $fenceChar, $fenceLength)) {
                    return $i + 1;
                }
            }

            return $count;
        }

        $commentInfo = $this->fencedBlockParser->parseFencedCommentOpener($line);
        if ($commentInfo === null || !$this->hasClosingCommentFenceAhead($line, $lines, $start)) {
            return null;
        }

        for ($i = $start; $i < $count; $i++) {
            $innerLines[] = $lines[$i];
            $innerLineMap[] = $this->sourceLineFor($i);
            if ($i > $start && $this->fencedBlockParser->isFencedCommentCloser($lines[$i], $commentInfo['length'])) {
                return $i + 1;
            }
        }

        return $count;
    }

    protected function isBareColonFence(string $line, ?int &$length = null): bool
    {
        if (preg_match('/^(:+)\s*$/', $line, $m) !== 1 || strlen($m[1]) < 3) {
            return false;
        }

        $length = strlen($m[1]);

        return true;
    }

    /**
     * Whether a code fence opened at `$openIndex` (with the given fence char and
     * length) has a matching closer on a later line. Used to decide whether a
     * raw ``` =format opener really forms a closed verbatim region -- an
     * UNCLOSED raw fence is paragraph text (inline code), not a block that
     * should hide following ::: div closers from the closer-lookahead scans.
     *
     * @param array<string> $lines
     * @param int $openIndex
     * @param string $fenceChar
     * @param int $fenceLength
     */
    protected function hasCodeFenceCloserAhead(array $lines, int $openIndex, string $fenceChar, int $fenceLength): bool
    {
        $count = count($lines);
        for ($j = $openIndex + 1; $j < $count; $j++) {
            if ($this->fencedBlockParser->isCodeFenceCloser($lines[$j], $fenceChar, $fenceLength)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseHeading(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Fast early exit: headings start with # (possibly after up to 3 spaces)
        $trimmed = ltrim($line, ' ');
        if (!isset($trimmed[0]) || $trimmed[0] !== '#') {
            return null;
        }

        // A heading is 1-6 `#` at COLUMN 0 (no leading indent), a literal space,
        // then NON-EMPTY content (grammar `heading_first_line = heading_marker,
        // space, inline_content`). The marker must start at column 0: an indented
        // `#`-line is a paragraph, matching carve-js / carve-rs and the spec.
        // Requiring content in the pattern itself means a bare `#`, `##`, or `# `
        // is ordinary paragraph text. `# \tx` (content after a tab) is still a
        // heading: `.*\S.*` only requires a non-whitespace char after the space.
        if (!preg_match('/^(#{1,6}) +(.*\S.*)$/', $line, $matches)) {
            return null;
        }

        $level = strlen($matches[1]);
        // Keep the content verbatim here: the regex `#… +` already folded the
        // leading spaces into the delimiter, and a leading TAB is content (kept,
        // matching a caption and carve-js / carve-rs).
        $content = $matches[2];
        $foldedLines = [[$start, $content]];

        // SINGLE-LINE HEADINGS (NORMATIVE, diverges from Djot): a heading ENDS AT
        // THE NEWLINE. Nothing folds into it -- not a plain line, not a same-count
        // `#` line -- so the following line begins whatever block it begins,
        // exactly as after any other closed block. Lazy continuation therefore
        // means one thing across the language: it continues an open PARAGRAPH,
        // and a heading is not one. Matches carve-js / carve-rs.
        $i = $start + 1;

        $heading = new Heading($level);

        // djot-strict (spec PART 2 headings; matches carve-js #153): a heading
        // line carries NO trailing `{...}` attribute block -- a trailing brace
        // block is ordinary inline content, and the heading id derives from
        // the full literal text. Attributes attach via a PRECEDING
        // block-attribute line (applyPendingAttributes below, PART 9 §15).
        //
        // §756 (NORMATIVE): strip the line's trailing whitespace (rtrim, ASCII
        // whitespace -- a trailing NBSP is content and survives). A leading tab
        // is preserved (see the extraction note above).
        $content = rtrim($content);

        // One source segment for the heading's single line.
        $headingLines = [];
        foreach ($foldedLines as [$foldIndex, $foldText]) {
            $foldSourceLine = $this->sourceLineFor($foldIndex);
            $foldColumn = $foldSourceLine < 0
                ? false
                : strpos($this->sourceLines[$foldSourceLine] ?? '', $foldText);
            $headingLines[] = [
                $foldSourceLine,
                $foldColumn === false ? 0 : $foldColumn,
                strlen($foldText),
                $foldText,
            ];
        }

        $this->inlineParser->parseHeading(
            $heading,
            $content,
            $start,
            $this->foldedLinesMap($headingLines),
        );
        $this->applyPendingAttributes($heading);
        $parent->appendChild($heading);

        return $i - $start;
    }

    protected function tryParseThematicBreak(Node $parent, string $line, int $start): ?int
    {
        // Grammar §262 thematic_break: a column-0 run of at least three IDENTICAL
        // `-`, `*`, or `_` characters, contiguous (no leading or internal
        // whitespace), followed only by optional trailing whitespace. Markdown's
        // loose spaced/indented forms (`* * *`, ` ***`, `-*-*-`) are NOT thematic
        // breaks and fall through to list/paragraph parsing.
        if (!preg_match('/^([-*_])\1{2,}[ \t]*$/', $line, $matches)) {
            return null;
        }

        $char = $matches[1];
        $thematicBreak = new ThematicBreak($char);
        $this->applyPendingAttributes($thematicBreak);
        $parent->appendChild($thematicBreak);

        return 1;
    }

    /**
     * Block-quote line content: the text after a `> ` prefix, '' for a lone
     * `>`, or null when the line does not open/continue a block quote.
     * Byte-equivalent to the `/^> (.*)$/` and `/^>$/` regexes -- a space is
     * required after `>`; `>text` and `>\t` do not start a quote.
     */
    private function blockQuoteLineContent(string $line): ?string
    {
        if (($line[0] ?? '') !== '>') {
            return null;
        }
        if ($line === '>') {
            return '';
        }
        if (($line[1] ?? '') === ' ') {
            return substr($line, 2);
        }

        return null;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseBlockQuote(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Match block quote opener via byte checks (equivalent to the regexes
        // `/^> (.*)$/` and `/^>$/`, without per-line preg_match overhead): `> `
        // with content, or a lone `>`. `>text` / `>\t` are not a quote.
        $content = $this->blockQuoteLineContent($line);
        if ($content === null) {
            return null;
        }

        $blockQuote = new BlockQuote();

        // Save and clear pending attributes - they apply to the blockquote, not inner content
        $quoteAttributes = $this->pendingAttributes;
        $this->pendingAttributes = [];
        $quoteAttributeOrder = $this->pendingAttributeOrder;
        $this->pendingAttributeOrder = [];

        $innerLines = [];
        $innerLineMap = [];
        $lazyState = [
            'inFence' => false,
            'fenceChar' => '',
            'fenceLength' => 0,
            'inComment' => false,
            'commentLength' => 0,
            'paragraphOpen' => false,
            'paragraphTextOpen' => false,
        ];

        $innerLines[] = $content;
        $innerLineMap[] = $this->sourceLineFor($start);
        $this->trackBlockQuoteLazyState($content, $lazyState, $lines, $start);

        $i = $start + 1;
        $count = count($lines);

        while ($i < $count) {
            $currentLine = $lines[$i];

            if (IndentationHelper::isBlankLine($currentLine)) {
                break;
            }

            // Continuation marker (Carve, PART 9 §17): a lone `+` at column 0
            // after a quoted line attaches the FOLLOWING flush-left block to the
            // quote -- the un-prefixed analogue of the list-item form, so a real
            // block (list, fenced code, table, ...) can join the quote without
            // repeating `>`. Collect the block's lines (up to a blank line, a
            // `>` line, or a further `+`) and splice them into the quote body
            // behind a blank-line separator, so they parse as their own block
            // instead of folding into the preceding quoted paragraph.
            if (rtrim($currentLine) === '+') {
                $i++; // consume the `+` marker
                /** @var array<string> $attached */
                $attached = [];
                $attachedLineMap = [];
                while ($i < $count) {
                    $line = $lines[$i];
                    if (IndentationHelper::isBlankLine($line)) {
                        break;
                    }
                    if ($this->blockQuoteLineContent($line) !== null) {
                        break; // a `>` line resumes the quote normally
                    }
                    if (rtrim($line) === '+') {
                        break; // a further `+` starts the next attached block
                    }
                    $attached[] = $line;
                    $attachedLineMap[] = $this->sourceLineFor($i);
                    $i++;
                }
                if ($attached !== []) {
                    // $innerLines always holds the quote's first content line, so
                    // a leading blank separates the attached block from it.
                    $innerLines[] = '';
                    $innerLineMap[] = -1;
                    foreach ($attached as $attachedIndex => $attachedLine) {
                        $innerLines[] = $attachedLine;
                        $innerLineMap[] = $attachedLineMap[$attachedIndex];
                    }
                    $innerLines[] = '';
                    $innerLineMap[] = -1;
                    // The attached block closed any open paragraph: a following
                    // unmarked line no longer lazily continues the quote.
                    $lazyState['paragraphOpen'] = false;
                }

                continue;
            }

            // Continue with "> " prefix (space required per spec)
            $content = $this->blockQuoteLineContent($currentLine);
            if ($content !== null) {
                $innerLines[] = $content;
                $innerLineMap[] = $this->sourceLineFor($i);
                $this->trackBlockQuoteLazyState($content, $lazyState, $lines, $i);
                $i++;
            } elseif (
                $lazyState['paragraphOpen']
                && !$this->endsBlockQuote($currentLine, $lazyState['paragraphTextOpen'], $lines, $i)
            ) {
                // Lazy continuation only extends an OPEN paragraph (djot rule).
                // A non-">" line inside an open code fence/comment, or after a
                // block that left no open paragraph (a just-opened div, a closed
                // fence), terminates the quote instead of being swallowed. A LIST
                // marker (bullet OR ordered) FOLDS into an open quoted PARAGRAPH
                // as literal text -- mirroring the top-level rule where a list
                // marker does not interrupt an open paragraph. But it only folds
                // when an open plain paragraph precedes it: after a heading,
                // table, or other closed block there is no paragraph to fold
                // into, so the list marker ENDS the quote and starts a sibling
                // list (endsBlockQuote() handles this via paragraphTextOpen).
                $innerLines[] = $currentLine;
                $innerLineMap[] = $this->sourceLineFor($i);
                $this->trackBlockQuoteLazyState($currentLine, $lazyState, $lines, $i);
                $i++;
            } else {
                break;
            }
        }

        $this->parseBlocks($blockQuote, $innerLines, 0, $innerLineMap);

        // Apply the saved attributes to the blockquote
        if ($quoteAttributes !== []) {
            $blockQuote->setAttributesWithOrder($quoteAttributes, $quoteAttributeOrder);
        }
        $parent->appendChild($blockQuote);

        return $i - $start;
    }

    /**
     * Track verbatim/paragraph state across a blockquote's collected inner lines.
     *
     * A non-">" line lazily continues a blockquote only when an open paragraph is
     * available to extend (the djot/CommonMark lazy-continuation rule). Inside an
     * open code fence or fenced comment, or after a structural line that leaves no
     * open paragraph (a just-opened div, a closed fence), such a line must instead
     * terminate the quote - otherwise it is wrongly swallowed into the fence/div.
     *
     * The separate `paragraphTextOpen` flag is narrower than `paragraphOpen`:
     * it is true only after a plain PARAGRAPH-text line, and false after a
     * heading, table, thematic break, fence, comment, div, or blank line. It
     * decides whether a lazy list marker folds (open paragraph above) or ends
     * the quote (no open paragraph), mirroring the top-level rule that a list
     * marker folds into a paragraph but never into a heading.
     *
     * @param string $content Inner content line (after the "> " marker is stripped).
     * @param array{inFence:bool,fenceChar:string,fenceLength:int,inComment:bool,commentLength:int,paragraphOpen:bool,paragraphTextOpen:bool} $state
     *     Running state, mutated in place.
     * @param array<string> $sourceLines
     * @param int $sourceIndex
     */
    private function trackBlockQuoteLazyState(string $content, array &$state, array $sourceLines, int $sourceIndex): void
    {
        if ($state['inComment']) {
            if ($this->fencedBlockParser->isFencedCommentCloser($content, $state['commentLength'])) {
                $state['inComment'] = false;
            }
            $state['paragraphOpen'] = false;
            $state['paragraphTextOpen'] = false;

            return;
        }

        if ($state['inFence']) {
            if ($this->fencedBlockParser->isCodeFenceCloser($content, $state['fenceChar'], $state['fenceLength'])) {
                $state['inFence'] = false;
            }
            $state['paragraphOpen'] = false;
            $state['paragraphTextOpen'] = false;

            return;
        }

        if (IndentationHelper::isBlankLine($content)) {
            $state['paragraphOpen'] = false;
            $state['paragraphTextOpen'] = false;

            return;
        }

        // This only tracks LAZY-CONTINUATION state (which non-">" lines extend the
        // quote), not how the collected content is block-parsed -- §10 paragraph
        // interruption (with its fence/div closer lookahead) is applied later by
        // parseBlocks. A fence/comment/div opener begins a fence/comment/div state
        // only when no paragraph is open (the opener is the first content, or
        // follows a blank line); a marker mid-paragraph leaves the paragraph open
        // so a following unquoted line still lazily continues it.
        if (!$state['paragraphOpen']) {
            $fenceInfo = $this->fencedBlockParser->parseCodeFenceOpener($content);
            if ($fenceInfo !== null) {
                $state['inFence'] = true;
                $state['fenceChar'] = $fenceInfo['char'];
                $state['fenceLength'] = $fenceInfo['length'];
                $state['paragraphOpen'] = false;
                $state['paragraphTextOpen'] = false;

                return;
            }

            $commentInfo = $this->fencedBlockParser->parseFencedCommentOpener($content);
            if ($commentInfo !== null && $this->hasClosingCommentFenceAheadInBlockQuote($sourceLines, $sourceIndex, $commentInfo['length'])) {
                $state['inComment'] = true;
                $state['commentLength'] = $commentInfo['length'];
                $state['paragraphOpen'] = false;
                $state['paragraphTextOpen'] = false;

                return;
            }

            if ($this->fencedBlockParser->parseDivFenceOpener($content) !== null) {
                // Div opener/closer line is structural; it opens no paragraph itself.
                $state['paragraphOpen'] = false;
                $state['paragraphTextOpen'] = false;

                return;
            }
        }

        // Any other non-blank line is paragraph-ish content (plain text, an open
        // paragraph's continuation, or a block that opens with text on the same line:
        // list item, nested quote) - all leave an open paragraph a lazy line
        // may continue.
        //
        // EXCEPT the closed blocks below. PART 1 S4 makes lazy continuation
        // conditional on an OPEN PARAGRAPH ("if ANY container in the open stack
        // holds an OPEN PARAGRAPH ... Otherwise close the unmatched containers"),
        // and a bounded single-line block leaves none. PART 9 §10 I6 says it
        // again for the heading: "a bounded title holds no block and ENDS AT THE
        // NEWLINE, so nothing folds into it at all".
        //
        // These used to set paragraphOpen anyway and clear only
        // paragraphTextOpen, so `> # h` / `b` kept the quote open and put `b`
        // inside it. carve-rs closes it; the distinction between the two flags
        // was the bug (carve-php#652).
        $trimmed = ltrim($content);
        $isHeading = preg_match('/^#{1,6} .*\S/', $trimmed) === 1;
        $isThematicBreak = preg_match('/^([-*_])\1{2,}[ \t]*$/', $trimmed) === 1;
        $isTableRow = $this->tableParser->isTableRow($trimmed);
        // A definition TERM is bounded like a heading: it holds inline content,
        // not a paragraph. `:::` is a div fence and is handled above.
        $isDefinitionTerm = preg_match(self::DEFINITION_TERM_LINE_PATTERN, $trimmed) === 1;
        // An invisible definition leaves no paragraph at all - there is nothing
        // on the page for a lazy line to continue.
        // PART 12 §7 recognizes an abbreviation definition only at document
        // level, so whether this line leaves an open paragraph depends on
        // WHERE it was written. Written inside the quote (`> *[A]: b`) it is
        // paragraph text and a lazy line continues it; written flush-left after
        // the quote it is a real definition, which is invisible and so ends the
        // quote. A reference definition is a definition at either level.
        $rawLine = $sourceLines[$sourceIndex] ?? '';
        $isFlushLeftCandidate = !str_starts_with(ltrim($rawLine, " \t"), '>');
        $isDefinitionLine = $this->isReferenceDefinitionLine($trimmed)
            || ($isFlushLeftCandidate && $this->isAbbreviationDefinitionLine($trimmed));

        $leavesNoParagraph = $isHeading
            || $isThematicBreak
            || $isTableRow
            || $isDefinitionTerm
            || $isDefinitionLine;

        $state['paragraphOpen'] = !$leavesNoParagraph;
        // A list marker folds only into an open PLAIN paragraph, so the same
        // set clears this too. Mirrors the top-level rule: `text\n- item`
        // folds, `# h\n- item` is a heading plus a sibling list.
        $state['paragraphTextOpen'] = !$leavesNoParagraph;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseList(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Try to match list item marker. The marker is matched on the trimmed
        // line so an indented bullet/ordered marker still opens a list (Rule B:
        // a list opens at any indentation, not only at column 0); the leading
        // indentation becomes the list's base column (getLeadingColumns below).
        $listInfo = $this->listParser->parseListItemMarker(ltrim($line));
        if ($listInfo === null) {
            return null;
        }

        // Disambiguate roman vs alphabetical for single-letter markers
        // by looking at subsequent items
        if (!empty($listInfo['ambiguous'])) {
            $listInfo = $this->listParser->disambiguateListStyle($listInfo, $lines, $start);
        }

        // Get the base indentation of this list
        $baseIndent = IndentationHelper::getLeadingColumns($line);

        /** @var string $listType */
        $listType = $listInfo['type'];
        /** @var int $listStart */
        $listStart = $listInfo['start'] ?? 1;
        $listMarker = $listInfo['marker'];
        /** @var string|null $listStyle */
        $listStyle = $listInfo['style'] ?? null;

        $list = new ListBlock(
            $listType,
            $listStart,
            true, // Start as tight
            $listMarker,
            $listStyle,
            $listInfo['bareMarker'] ?? false,
        );

        // Save and clear pending attributes - they apply to the list, not inner content
        $listAttributes = $this->pendingAttributes;
        $this->pendingAttributes = [];
        $listAttributeOrder = $this->pendingAttributeOrder;
        $this->pendingAttributeOrder = [];

        $i = $start;
        $count = count($lines);
        $lastItemHadBlankAfter = false;
        $firstItem = true; // Track first item to use listInfo directly
        // Content column of the most recently opened item (marker width + base).
        // A post-blank continuation belongs to that item only if it reaches this
        // column (content-column model, carve#295); below it the item body ends.
        // Seeded with the bullet width; every item overwrites it once its own
        // marker width is known.
        $lastItemContentIndent = $baseIndent + 2;

        while ($i < $count) {
            $currentLine = $lines[$i];

            // Skip blank lines, track them for tight/loose determination
            if (IndentationHelper::isBlankLine($currentLine)) {
                $lastItemHadBlankAfter = true;
                $i++;

                continue;
            }

            // Get indentation of current line
            $currentIndent = IndentationHelper::getLeadingColumns($currentLine);

            // If line is less indented than base, we're done with this list
            if ($currentIndent < $baseIndent) {
                break;
            }

            // List-continuation marker (Carve): a lone `+` at the marker column
            // attaches the FOLLOWING flush-left block to the current item, with
            // no blank line, keeping the list tight. A bare `+` is never a bullet
            // (a bullet needs `+ ` + content), so this does not collide with
            // `+`-bulleted lists; lets you attach a code block, table or quote to
            // an item without indenting its body.
            if ($currentIndent === $baseIndent && trim($currentLine) === '+') {
                $lastItem = $this->listParser->getLastListItem($list);
                if ($lastItem !== null) {
                    [$i, $attached, $attachedLineMap] = $this->collectListContinuationBlock($lines, $i + 1, $count, $baseIndent);
                    if ($attached !== []) {
                        $this->parseItemBlocks($lastItem, $attached, $attachedLineMap);
                    }
                    // The continuation attaches content but does not loosen the list.
                    $lastItemHadBlankAfter = false;

                    continue;
                }
            }

            // Indented content belonging to the previous item. Carve
            // enters this for an indented list marker even with no
            // preceding blank line (tight nesting); other indented
            // content still requires the blank line (loose nesting).
            $indentedListMarker = $currentIndent > $baseIndent
                && $this->listParser->parseListItemMarker(ltrim($currentLine)) !== null;
            // Content-column model (carve#295): a continuation - after a blank, or
            // a no-blank nested marker - belongs to the previous item only when it
            // REACHES that item's content column. Below it the item body has
            // ended: a post-blank block detaches to document level, and a
            // below-column marker folds as lazy item text (handled by the item
            // collector). The old rule attached at any indent past the base
            // column, which nested a block one space under the marker.
            if (
                ($lastItemHadBlankAfter || $indentedListMarker)
                && $currentIndent >= $lastItemContentIndent
            ) {
                // Content after blank line with indentation belongs to previous item
                $lastItem = $this->listParser->getLastListItem($list);
                if ($lastItem !== null) {
                    // Compact list blocks (Carve): a blank line before indented
                    // content does not loosen the list when that content OPENS A
                    // BLOCK (sub-list, block quote, fenced code, fenced div,
                    // heading, table). Only a genuine second prose paragraph makes
                    // the list loose. Block recognition and the uniformity
                    // principle are unchanged -- only tight/loose RENDERING moves.
                    // Recognize the block opener at the item body's COLUMN 0 (the
                    // content column), not after a full trim: content indented
                    // PAST the content column carries residual spaces, so - like
                    // ` # h` at the top level - it is not a block opener but lazy
                    // paragraph text, which loosens the item (content-column
                    // model, carve#295). A list marker still nests at any indent
                    // (Rule B re-recognizes it after the residual), so it keeps
                    // the item tight.
                    $strippedCurrent = IndentationHelper::stripLeadingColumns($currentLine, $lastItemContentIndent);
                    // The shared looseness predicate, not a second spelling of
                    // it: a list marker at any indent, a block opener, and a
                    // line that renders NOTHING all leave the item tight. A
                    // comment or a definition here used to loosen it, wrapping
                    // the item in `<p>` because of a line the reader never sees
                    // (carve-php#744).
                    $firstContentOpensBlock = $this->lineOpensBlockForLooseness($strippedCurrent);
                    // §17 L1b: an invisible line is not the second paragraph,
                    // AND it is not a separator either - it cannot stand
                    // between the blank line and the paragraph that follows.
                    // Testing only the FIRST line after the blank stopped at
                    // the comment and left the item tight, so deleting the
                    // comment changed how both paragraphs render - a line that
                    // outputs nothing making a visible difference (carve#630,
                    // carve-php#771).
                    if ($firstContentOpensBlock && $this->isInvisibleOrAttributeLine($strippedCurrent)) {
                        $behind = $this->firstVisibleLineAfterInvisible($lines, $i, $lastItemContentIndent);
                        if ($behind !== null && !$this->lineOpensBlockForLooseness($behind)) {
                            $firstContentOpensBlock = false;
                        }
                    }
                    if (!$firstContentOpensBlock) {
                        // Indented plain text (or above-column lazy text) after a
                        // blank line = a second paragraph in the item => loose.
                        $list->setTight(false);
                    }

                    // Collect all indented content at this new level. The strip
                    // column is the item's content column (body column 0), so
                    // residual indent above it is preserved and a block opener
                    // there stays lazy text rather than being re-promoted.
                    $subLines = [];
                    $subLineMap = [];
                    $subIndent = $lastItemContentIndent;
                    // Track the maximum content indent we've seen (for detecting drop-back to marker level)
                    $maxContentIndent = $currentIndent;
                    $sawBlankLine = false;
                    $brokeForParentContent = false;
                    // Trailing-block state over the collected nested lines, so a
                    // base-level lazy line folds only when the nested content
                    // ends in an OPEN paragraph (family-D rule). After a CLOSED
                    // block (fenced code, table, div) the dedented line ends the
                    // item instead of being absorbed.
                    $subTrailingState = self::INITIAL_TRAILING_BLOCK_STATE;
                    // Whether the collected stream already holds list content;
                    // sibling markers inside it are the nested list's own
                    // business and must not get a loosening blank injected.
                    $subSawListMarker = false;
                    while ($i < $count) {
                        $subLine = $lines[$i];
                        if (IndentationHelper::isBlankLine($subLine)) {
                            $subLines[] = '';
                            $subLineMap[] = $this->sourceLineFor($i);
                            $sawBlankLine = true;
                            $i++;

                            continue;
                        }
                        $lineIndent = IndentationHelper::getLeadingColumns($subLine);

                        // If we've seen content at a higher indent level (actual nested content),
                        // and now we're back at the marker level (subIndent) after a blank line,
                        // this content belongs to the parent level - break to let parent handle it
                        if ($lineIndent === $subIndent && $maxContentIndent > $subIndent && $sawBlankLine) {
                            // Set flags so parent loop handles this as continuation content
                            $lastItemHadBlankAfter = true;
                            $brokeForParentContent = true;

                            break;
                        }

                        // Check if line has at least the subIndent level
                        if ($lineIndent >= $subIndent) {
                            // Track the highest content indent seen
                            if ($lineIndent > $maxContentIndent) {
                                $maxContentIndent = $lineIndent;
                            }
                            // Remove subIndent worth of indentation (handling tabs)
                            $stripped = IndentationHelper::stripLeadingColumns($subLine, $subIndent);
                            // A list marker reaching the content column starts a
                            // sublist even when an open continuation paragraph
                            // precedes it (PART 0 S3, PART 9 §24 C3; corpus 131).
                            // Inject a blank separator so the nested parse opens
                            // the sublist instead of lazily folding the marker
                            // into the open PLAIN paragraph. Once the stream
                            // holds list content, sibling markers belong to that
                            // nested list and must not get a loosening blank.
                            $strippedIsMarker = $this->listParser->parseListItemMarker(ltrim($stripped)) !== null;
                            if (
                                $strippedIsMarker
                                && !$subSawListMarker
                                && $subTrailingState['openParagraph']
                                && !$subTrailingState['inFence']
                                && !$subTrailingState['inDiv']
                            ) {
                                $subLines[] = '';
                                $subLineMap[] = -1;
                                $subTrailingState = $this->advanceTrailingBlockState($subTrailingState, '');
                            }
                            if ($strippedIsMarker) {
                                $subSawListMarker = true;
                            }
                            $subLines[] = $stripped;
                            $subLineMap[] = $this->sourceLineFor($i);
                            $subTrailingState = $this->advanceTrailingBlockState($subTrailingState, $stripped);
                            $sawBlankLine = false;
                            $i++;
                        } elseif ($lineIndent === $baseIndent) {
                            // Line is at base indent - check if it starts a new block or list item
                            $trimmedLine = ltrim($subLine);
                            $itemInfo = $this->listParser->parseListItemMarker($trimmedLine);
                            $sameStyle = !isset($listInfo['style']) || !isset($itemInfo['style']) || $itemInfo['style'] === $listInfo['style'];
                            if ($itemInfo !== null && $itemInfo['type'] === $listInfo['type'] && $itemInfo['marker'] === $listInfo['marker'] && $sameStyle) {
                                if ($sawBlankLine) {
                                    $lastItemHadBlankAfter = true;
                                    $brokeForParentContent = true;
                                }

                                break;
                            }
                            // After a blank line, content dropping back to base indent
                            // starts a new block outside the list - let parent handle it.
                            if ($sawBlankLine) {
                                $lastItemHadBlankAfter = true;
                                $brokeForParentContent = true;

                                break;
                            }
                            // Content at base indent that's not a matching list marker
                            // Check if it's a block element - if so, end list content collection
                            // Use isBlockElementStart() which detects blocks regardless of mode
                            if (
                                $this->isBlockElementStart($trimmedLine, $lines, $i)
                                || $this->startsNewBlock($trimmedLine, $lines, $i)
                            ) {
                                break;
                            }
                            // Otherwise it's lazy continuation at base level. It
                            // only folds into the nested content when that
                            // content ends in an OPEN paragraph; after a closed
                            // block (code/table/div) the dedented line ends the
                            // item (family-D rule, matching carve-js/carve-rs).
                            if (
                                !$subTrailingState['openParagraph']
                                && !$subTrailingState['inFence']
                                && !$subTrailingState['inDiv']
                            ) {
                                break;
                            }
                            $subLines[] = $trimmedLine;
                            $subLineMap[] = $this->sourceLineFor($i);
                            $subTrailingState = $this->advanceTrailingBlockState($subTrailingState, $trimmedLine);
                            $sawBlankLine = false;
                            $i++;
                        } elseif ($lineIndent > $baseIndent) {
                            // Line is at intermediate indent (between base and nested content)
                            // Without a preceding blank, plain text here lazily
                            // continues the deepest paragraph in the nested parse.
                            // Strip all leading whitespace before forwarding it,
                            // matching CommonMark lazy continuation.
                            $trimmedLine = ltrim($subLine);
                            // A block-shaped line HERE reaches neither the nested
                            // content column nor the outer item's, so under the
                            // strict content-column rule it opens nothing: with a
                            // paragraph still open it is a lazy line like any
                            // other text (PART 0 S4). Ending the item on it
                            // closed BOTH lists and re-opened the marker as a new
                            // top-level list (carve-php#706). The marker-line
                            // collector already folds the same shape (#693);
                            // these two collectors disagreed about one line.
                            // An INVISIBLE line (definition, comment) counts
                            // here too. It is not block-SHAPED, so it used to
                            // fall through and be pushed TRIMMED - which put a
                            // definition at the item's own column 0, where the
                            // block parser consumes it as an already-extracted
                            // definition and renders nothing. The line vanished
                            // from the document (carve-php#721). Kept where the
                            // author put it, the nested parse reads it as the
                            // text it is.
                            $blockShaped = $this->isBlockElementStart($trimmedLine, $lines, $i)
                                || $this->startsNewBlock($trimmedLine, $lines, $i)
                                || $this->isFoldableInvisibleLine($trimmedLine);
                            $dedentedOpener = $blockShaped
                                && !$sawBlankLine
                                && $subTrailingState['openParagraph']
                                && $subLines !== [];
                            if ($dedentedOpener) {
                                // Forward it with exactly ONE column, the way
                                // collectMarkerLeadItem() forwards a dedented
                                // line (#693): below the sub-list's content
                                // column the nested parse reads a marker as
                                // paragraph text, so the geometry decides it one
                                // level in rather than this loop deciding it
                                // here. Stripping it to column 0 would put it AT
                                // the nested list's marker column and open a
                                // sibling item; forwarding its OWN indent let
                                // two columns reach the nested CONTENT column
                                // and open a list one level deeper (carve#603).
                                // One column reaches neither.
                                $subLines[] = ' ' . $trimmedLine;
                                $subLineMap[] = $this->sourceLineFor($i);
                                $subTrailingState = $this->advanceTrailingBlockState($subTrailingState, $subLine);
                                $i++;

                                continue;
                            }
                            if (
                                !$sawBlankLine
                                && !$this->isBlockElementStart($trimmedLine, $lines, $i)
                                && !$this->startsNewBlock($trimmedLine, $lines, $i)
                            ) {
                                $subLines[] = $trimmedLine;
                                $subLineMap[] = $this->sourceLineFor($i);
                                $subTrailingState = $this->advanceTrailingBlockState($subTrailingState, $trimmedLine);
                                $i++;

                                continue;
                            }

                            break;
                        } else {
                            // End of list
                            break;
                        }
                    }
                    // Remove trailing blank lines from subLines
                    $subLineCount = count($subLines);
                    while ($subLineCount > 0 && $subLines[$subLineCount - 1] === '') {
                        array_pop($subLines);
                        array_pop($subLineMap);
                        $subLineCount--;
                    }
                    // Compact-list rule (carve#322): an internal blank line in
                    // the item's collected content loosens THIS list only when
                    // the content after the blank is the item's OWN block (a
                    // plain paragraph dedented back below the sub-list). Content
                    // at or past the sub-list's content column belongs to the
                    // sub-list, whose looseness is decided by its own recursive
                    // parse, so it must not propagate up (nested-item looseness
                    // does not propagate, corpus 142). Only the outer item, which
                    // owns the blank before its own attached block, goes loose.
                    if ($this->subContentHasLooseningBlank($subLines)) {
                        $list->setTight(false);
                    }
                    // Parse nested content
                    if ($subLines !== []) {
                        $this->parseItemBlocks($lastItem, $subLines, $subLineMap);
                    }
                    // In djot, blank lines within nested content don't make the parent list loose
                    // The list is only loose if there's a blank line directly after item content
                    // (before nested content starts), which is already handled elsewhere
                    // Only reset if we didn't break to handle content at parent level
                    if (!$brokeForParentContent) {
                        // ... unless everything collected after the blank
                        // RENDERS NOTHING. §17 L1 has two clauses, and only the
                        // second-paragraph one is answered above: an item
                        // FOLLOWED by a blank line before the next sibling
                        // marker is loose either way, and an invisible line in
                        // that gap does not fill it. Keeping the flag lets a
                        // following sibling loosen the list, while an item that
                        // ends the list stays tight - which is the pair the
                        // corpus pins as 87-compact-list-blocks-4/5 against -6
                        // (carve-php#744).
                        $lastItemHadBlankAfter = $this->contentRendersNothing($subLines);
                    }

                    continue;
                }
            }

            // For first item, use the already-parsed listInfo (may have been disambiguated)
            // For subsequent items, parse fresh
            $trimmedLine = ltrim($currentLine);
            if ($firstItem) {
                $itemInfo = $listInfo;
                $firstItem = false;
            } else {
                // Only match items at the same indentation level
                if ($currentIndent !== $baseIndent) {
                    break;
                }
                $itemInfo = $this->listParser->parseListItemMarker($trimmedLine);

                // Check if this is a list item of the same type, marker, and style
                if ($itemInfo === null || !$this->listParser->itemMatchesList($listInfo, $itemInfo)) {
                    break;
                }
            }

            // If there was a blank line before this item, list is loose
            if ($lastItemHadBlankAfter) {
                $list->setTight(false);
            }

            /** @var string|null $taskMarker */
            $taskMarker = $itemInfo['taskMarker'] ?? null;
            $listItem = new ListItem($taskMarker);
            $listItemSourceLine = $this->sourceLineFor($i);
            // Attributes from an abutting `{...}` block attach to the <li>.
            if (isset($itemInfo['attributes'])) {
                /** @var array<string, string> $markerAttributes */
                $markerAttributes = $itemInfo['attributes'];
                foreach ($markerAttributes as $key => $value) {
                    $listItem->setAttribute($key, $value);
                }
            }
            if ($this->trackSourceLines && $listItemSourceLine >= 0 && $listItem->getAttribute('data-source-line') === null) {
                $listItem->setAttribute('data-source-line', (string)($listItemSourceLine + 1));
            }
            /** @var string $itemContent */
            $itemContent = $itemInfo['content'];

            // Collect item content lines (without blank line = tight continuation)
            /** @var array<string> $itemLines */
            $itemLines = [$itemContent];
            $itemLineMap = [$listItemSourceLine];
            $i++;
            $lastItemHadBlankAfter = false;

            // Trailing-block tracker for CommonMark lazy continuation. Updated
            // incrementally for each collected line (advanceTrailingBlockState)
            // so the lazy-continuation gate below is O(1) per line instead of
            // rescanning all collected lines. `openParagraph` is false when the
            // trailing top-level block is a fenced code block or a table (no
            // open paragraph for a dedented line to fold into).
            $trailingState = self::INITIAL_TRAILING_BLOCK_STATE;
            $trailingState = $this->advanceTrailingBlockState($trailingState, $itemContent);

            // First-block item (Carve): `- +` opens an item whose body is the
            // flush-left block that follows, with no indentation. A lone `+` as
            // the sole item content is the continuation marker, not literal text
            // (`- + text` keeps `+ text` as literal content). This lets an item
            // start directly with a table, code block, quote or div at column 0.
            if (trim($itemContent) === '+') {
                [$i, $attached, $attachedLineMap] = $this->collectListContinuationBlock($lines, $i, $count, $baseIndent);
                if ($attached !== []) {
                    $this->parseItemBlocks($listItem, $attached, $attachedLineMap);
                }
                $list->appendChild($listItem);

                continue;
            }

            // Calculate content indent based on list type and marker width
            // For bullet lists (including task lists): use 2 (for "- ")
            // For ordered lists: use actual marker width (varies with number length)
            // Task list checkbox is considered part of content, not marker
            $markerWidth = $this->listMarkerWidth($trimmedLine, $itemInfo);
            $contentIndent = $baseIndent + $markerWidth;
            // Remember this item's content column for the next iteration's
            // post-blank / nested-marker continuation gate (content-column model).
            $lastItemContentIndent = $contentIndent;

            // When the item's content BEGINS, on the marker line, with another
            // list marker (`- - A`, `* - A`, `1. - A`, ...), the lead is itself
            // a sub-list, not a paragraph. Carve then parses the lead together
            // with every following dedented line as ONE block stream so the
            // marker-line sub-list behaves exactly like a sub-list opened on a
            // *following* line: following same-indent markers MERGE into it as
            // siblings, and post-blank indented blocks are ABSORBED into its
            // items. This MATCHES reference djot.js (the djot/djot package
            // 0.3.2) and CommonMark, which both treat a marker-line sub-list as
            // a normal nested list. It corrects Carve's prior line-scoping
            // (which split the sub-list from following items and leaked later
            // indented blocks to the parent row) -- a bug inherited from
            // djot-php, whose marker-line handling deviates from reference djot
            // (a parallel fix is in flight on php-collective/djot-php). The
            // single combined stream reuses the normal nested-list/absorption
            // logic -- no separate path.
            $leadIsMarker = $this->listParser->parseListItemMarker($itemContent) !== null;
            if ($leadIsMarker) {
                $i = $this->collectMarkerLeadItem(
                    $lines,
                    $i,
                    $count,
                    $baseIndent,
                    $contentIndent,
                    $itemLines,
                    $itemLineMap,
                );
                // A blank line between this item's blocks loosens the list, and a
                // sub-list lead is no exception: the item still holds two blocks,
                // the sub-list and whatever follows the blank at THIS item's
                // content column. The combined stream skipped the scan the plain
                // path runs, so `- - a` / blank / `  b` stayed tight while
                // `- x` / blank / `  b` went loose (carve-php#681). Content at or
                // past the sub-list's own content column still belongs to the
                // sub-list and does not propagate its looseness outwards.
                if ($this->subContentHasLooseningBlank($itemLines)) {
                    $list->setTight(false);
                }
                $this->parseItemBlocks($listItem, $itemLines, $itemLineMap);
                $list->appendChild($listItem);

                // A blank line directly before the next sibling marker still
                // loosens the list; mirror the plain-item rule by remembering
                // any trailing blank consumed inside the combined stream.
                if ($i < $count && IndentationHelper::isBlankLine($lines[$i])) {
                    $lastItemHadBlankAfter = true;
                }

                continue;
            }

            // When the item's lead content is a colon-fence opener (`::: note`
            // admonition or a bare `:::` div) and item-owned body follows at
            // the content column, that body -- including a NESTED LIST --
            // belongs to the container. This does not require a closer scan:
            // the container may close at EOF.
            // The normal item collector would split the nested sub-list into
            // its own block stream (so an ordered sub-list nests instead of
            // folding), which severs the opener from its body: the opener stays
            // literal and the closer becomes trailing text. Keep the whole item
            // stream together so tryParseDiv captures its nested-list body.
            if ($this->leadColonFenceHasBodyAtContentColumn($itemContent, $lines, $i, $count, $contentIndent)) {
                $i = $this->collectMarkerLeadItem(
                    $lines,
                    $i,
                    $count,
                    $baseIndent,
                    $contentIndent,
                    $itemLines,
                    $itemLineMap,
                );
                $listItem->setPos($this->spanForLineMap($itemLineMap));
                $this->parseItemBlocks($listItem, $itemLines, $itemLineMap);
                $list->appendChild($listItem);

                if ($i < $count && IndentationHelper::isBlankLine($lines[$i])) {
                    $lastItemHadBlankAfter = true;
                }

                continue;
            }

            // Strict content-column rule: a marker-line colon-fence opener
            // whose body starts below the item's content column is lazy
            // paragraph text for this item, not a container whose body can be
            // reconstructed from below-column lines.
            if ($trailingState['inDiv']) {
                $trailingState['inDiv'] = false;
                $trailingState['openParagraph'] = true;
            }

            [$i, $trailingState] = $this->collectPlainListItemContinuation(
                $lines,
                $i,
                $count,
                $baseIndent,
                $contentIndent,
                $itemLines,
                $itemLineMap,
                $trailingState,
            );

            // A marker-line colon fence whose body is BELOW the content column
            // opens nothing: §24 C3 puts that line outside the item body, and
            // with no blank it lazily continues the item's paragraph - so the
            // opener is literal text and takes the following lines with it.
            //
            // The collected stream had lost that: the opener sits at the
            // stream's own column 0 with the body under it, which is exactly
            // the shape tryParseDiv() builds a container from, so `- ::: note`
            // / `body` came back as an admonition where carve-js, carve-rs and
            // the executable spec all render two literal lines
            // (carve-php#748). Joining them into ONE line says what the
            // geometry said. The body AT the content column is handled above
            // and still nests.
            if (
                count($itemLines) > 1
                && $this->fencedBlockParser->parseDivFenceOpener($itemContent) !== null
            ) {
                $itemLines = [implode("\n", $itemLines)];
                $itemLineMap = [$itemLineMap[0] ?? -1];
            }

            // For tight lists with continuation lines, check if content starts with
            // a block element. If so, parse as blocks; otherwise parse as plain text.
            // This prevents "-like" lines from being parsed as nested lists while
            // still allowing blockquotes, code blocks, etc. to be properly recognized.
            // Item content parses as blocks. Per grammar §10 only a list marker
            // interrupts nested content without a blank line (sublists are
            // collected above); a non-list block opener after lead text stays
            // paragraph text, so tryParseParagraph folds it into the lead
            // paragraph rather than splitting it into a separate block.
            $listItem->setPos($this->spanForLineMap($itemLineMap));
            $this->parseItemBlocks($listItem, $itemLines, $itemLineMap);

            $list->appendChild($listItem);
        }

        // Apply the saved attributes to the list
        if ($listAttributes !== []) {
            $list->setAttributesWithOrder($listAttributes, $listAttributeOrder);
        }
        $parent->appendChild($list);

        return $i - $start;
    }

    /**
     * Parse a list item's block stream, with the pending-attribute run scoped
     * to that item.
     *
     * §15 A2a floats a pending attribute to the next VISIBLE block and A4
     * drops a run that reaches the end with nothing to attach to. The item
     * boundary is such an end: an attribute written inside one item that finds
     * no block there attaches to nothing, rather than reaching into the NEXT
     * item's paragraph - which would make a `{...}` line's effect depend on
     * where the list happens to break. The state is parser-global, so without
     * this the run simply survived into the sibling's parse
     * (carve-php#757, markup-carve/carve-js#620).
     *
     * @param \MarkupCarve\Carve\Node\Node $item
     * @param array<string> $lines
     * @param array<int, int>|null $lineMap
     */
    protected function parseItemBlocks(Node $item, array $lines, ?array $lineMap = null): void
    {
        $this->parseBlocks($item, $lines, 0, $lineMap);
        $this->pendingAttributes = [];
        $this->pendingAttributeOrder = [];
    }

    /**
     * Collect the flush-left block attached by a list continuation marker.
     *
     * @param array<string> $lines All lines being parsed.
     * @param int $i Index of the first line after the `+` marker.
     * @param int $count Total line count.
     * @param int $baseIndent The list's base column.
     *
     * @return array{0: int, 1: array<string>, 2: array<int, int>}
     */
    protected function collectListContinuationBlock(array $lines, int $i, int $count, int $baseIndent): array
    {
        $attached = [];
        $attachedLineMap = [];

        while ($i < $count) {
            $line = $lines[$i];
            if (IndentationHelper::isBlankLine($line)) {
                break;
            }
            $lineIndent = IndentationHelper::getLeadingColumns($line);
            if ($lineIndent < $baseIndent) {
                break;
            }
            $trimmed = ltrim($line);
            if (
                $lineIndent === $baseIndent
                && ($this->listParser->parseListItemMarker($trimmed) !== null || $trimmed === '+')
            ) {
                break;
            }
            $attached[] = IndentationHelper::stripLeadingColumns($line, $baseIndent);
            $attachedLineMap[] = $this->sourceLineFor($i);
            $i++;
        }

        return [$i, $attached, $attachedLineMap];
    }

    /**
     * Collect continuation lines for a normal list item.
     *
     * @param array<string> $lines All lines being parsed.
     * @param int $i Index of the first line after the marker line.
     * @param int $count Total line count.
     * @param int $baseIndent The list's base column.
     * @param int $contentIndent The item's content column.
     * @param array<string> $itemLines Collected item lines, appended in place.
     * @param array<int, int> $itemLineMap Source-line map, appended in place.
     * @param array{openParagraph: bool, inFence: bool, fenceChar: string, fenceLength: int, inDiv: bool, divFenceLength: int} $trailingState
     *
     * @return array{0: int, 1: array{openParagraph: bool, inFence: bool, fenceChar: string, fenceLength: int, inDiv: bool, divFenceLength: int}}
     */
    protected function collectPlainListItemContinuation(
        array $lines,
        int $i,
        int $count,
        int $baseIndent,
        int $contentIndent,
        array &$itemLines,
        array &$itemLineMap,
        array $trailingState,
    ): array {
        $sawIndentedUnclaimedColonFence = false;
        while ($i < $count) {
            $nextLine = $lines[$i];

            if (IndentationHelper::isBlankLine($nextLine)) {
                if ($trailingState['inFence']) {
                    $itemLines[] = '';
                    $itemLineMap[] = $this->sourceLineFor($i);
                    $i++;

                    continue;
                }

                break;
            }

            $nextIndent = IndentationHelper::getLeadingColumns($nextLine);
            $nextTrimmed = ltrim($nextLine);

            if ($this->listContinuationEndsAtDedentedBlock($nextIndent, $nextTrimmed, $baseIndent, $lines, $i)) {
                break;
            }

            if ($this->listContinuationEndsAtBaseColumn($nextIndent, $nextTrimmed, $baseIndent, $lines, $i)) {
                break;
            }

            if ($sawIndentedUnclaimedColonFence && $nextIndent <= $baseIndent) {
                break;
            }

            if ($nextIndent >= $contentIndent) {
                if ($this->listParser->parseListItemMarker($nextTrimmed) !== null) {
                    break;
                }
                $contentLine = IndentationHelper::stripLeadingColumns($nextLine, $contentIndent);
                if ($this->paragraphHasUnclaimedColonFenceLine($contentLine)) {
                    $sawIndentedUnclaimedColonFence = true;
                }
                $itemLines[] = $contentLine;
                $itemLineMap[] = $this->sourceLineFor($i);
                $trailingState = $this->advanceTrailingBlockState($trailingState, $contentLine);
                $i++;

                continue;
            }

            if (
                !$trailingState['openParagraph']
                && !$trailingState['inFence']
                && !$trailingState['inDiv']
            ) {
                break;
            }

            // A comment fence carries its body with it: pushed as its own
            // lines the block parser consumes the whole span and renders
            // nothing, and the item stays open across it exactly as it does
            // across a `%%` line.
            $commentFenceEnd = $this->commentFenceSpanEnd($nextTrimmed, $lines, $i);
            if ($commentFenceEnd !== null) {
                for ($j = $i; $j < $commentFenceEnd; $j++) {
                    $itemLines[] = ltrim($lines[$j]);
                    $itemLineMap[] = $this->sourceLineFor($j);
                }
                $i = $commentFenceEnd;

                continue;
            }

            // An UNCLOSED fence opens no block (PART 9 §28), but it is still a
            // COMMENT, and §24 C3 keeps a comment invisible at any column. The
            // span walk above returns null for it, so it fell through here and
            // `isBlockElementStart()` folded it as visible text - leaving this
            // engine rendering `%%% n` below an item's content column while
            // rendering nothing for the very same line at the top level, at the
            // content column, and in every other engine.
            $opensUnclosedCommentFence =
                $this->fencedBlockParser->parseFencedCommentOpenerAnyColumn($nextTrimmed) !== null;

            // A DEFINITION at the frame's own base column belongs to the
            // container this item sits in, not to this item: it is at THAT
            // item's content column, which §24 C3 reads as its block. Folding
            // it here left it rendered as item text while the prepass had
            // already registered it, so the note appeared twice and two
            // elements claimed the same id (carve-php#783). Ending the item
            // lets the enclosing frame see the definition at column 0, where
            // the skip pass consumes it.
            if (
                $nextIndent === 0
                && $trailingState['openParagraph']
                && !$this->isBlockElementStart($nextTrimmed)
                && !$this->startsNewBlock($nextTrimmed)
                && $this->isDefinitionLineForEnclosingItem($nextTrimmed)
            ) {
                break;
            }

            $foldedAsText = false;
            if (
                $trailingState['openParagraph']
                && $itemLines !== []
                && !$opensUnclosedCommentFence
                && (
                    $this->isBlockElementStart($nextTrimmed)
                    || $this->startsNewBlock($nextTrimmed)
                    // A definition or comment renders nothing of its own, so
                    // pushing it as its own line let the block parser consume
                    // it and emit nothing at all - the line disappeared
                    // (carve-php#721). Folded into the open paragraph it stays
                    // the text S4 says it is.
                    || $this->isFoldableInvisibleLine($nextTrimmed)
                )
            ) {
                // A COMMENT renders nothing, but it DOES end the open paragraph:
                // all of the executable spec, carve-js and carve-rs make the line
                // after it the item's SECOND paragraph, not a continuation of the
                // first. Folding onto the comment entry produced one entry holding
                // `%% x\n# h`, which the comment handling then consumed whole so
                // the author's line vanished (carve-php#791 for the fence form,
                // carve-php#800 for the line form). Folding PAST it instead ran
                // the two source lines together in one paragraph.
                //
                // So push it as its own entry, with ONE leading space. The space
                // is load-bearing: the item body is dedented, so a block-shaped
                // line like `# h` would re-parse as a real HEADING at column 0,
                // where §24 C3's BELOW branch says it is text. One column reaches
                // no content column at any depth, which is the same guard
                // carve-js uses for this case.
                $lastEntry = $itemLines[count($itemLines) - 1];
                $afterComment = $this->isCommentLineOrFence($lastEntry);
                if ($afterComment) {
                    $itemLines[] = ' ' . $nextTrimmed;
                    $itemLineMap[] = $this->sourceLineFor($i);
                } else {
                    $itemLines[count($itemLines) - 1] .= "\n" . $nextTrimmed;
                }
                $foldedAsText = true;
            } else {
                $itemLines[] = $nextTrimmed;
                $itemLineMap[] = $this->sourceLineFor($i);
            }
            if ($foldedAsText) {
                $trailingState['openParagraph'] = true;
            } else {
                $trailingState = $this->advanceTrailingBlockState($trailingState, $nextTrimmed);
            }
            $i++;
        }

        return [$i, $trailingState];
    }

    /**
     * @param int $nextIndent
     * @param string $nextTrimmed
     * @param int $baseIndent
     * @param array<string>|null $lines
     * @param int|null $index
     */
    protected function listContinuationEndsAtDedentedBlock(
        int $nextIndent,
        string $nextTrimmed,
        int $baseIndent,
        ?array $lines = null,
        ?int $index = null,
    ): bool {
        return $nextIndent < $baseIndent
            && (
                $this->listParser->parseListItemMarker($nextTrimmed) !== null
                || (
                    $nextIndent === 0
                    && (
                        $this->isBlockElementStart($nextTrimmed, $lines, $index)
                        || $this->startsNewBlock($nextTrimmed, $lines, $index)
                    )
                )
            );
    }

    /**
     * @param int $nextIndent
     * @param string $nextTrimmed
     * @param int $baseIndent
     * @param array<string>|null $lines
     * @param int|null $index
     */
    protected function listContinuationEndsAtBaseColumn(
        int $nextIndent,
        string $nextTrimmed,
        int $baseIndent,
        ?array $lines = null,
        ?int $index = null,
    ): bool {
        if ($nextIndent !== $baseIndent) {
            return false;
        }

        if ($this->listParser->parseListItemMarker($nextTrimmed) !== null || $nextTrimmed === '+') {
            return true;
        }

        return $baseIndent === 0
            && (
                $this->isBlockElementStart($nextTrimmed, $lines, $index)
                || $this->startsNewBlock($nextTrimmed, $lines, $index)
            );
    }

    /**
     * Collect the body of a list item whose lead content (on the marker line)
     * is itself a list marker, as a SINGLE block stream.
     *
     * The lead marker line is already in $itemLines (dedented to column 0). This
     * appends every following line that belongs to the item -- nested content at
     * or past the content column, and internal blank lines -- dedented by
     * $contentIndent, so the combined stream parses through the normal
     * nested-list/absorption path (one persistent sub-list rather than a split
     * sub-list plus a leaked parent-row block). Collection stops at end of
     * input, a blank line that is NOT followed by further item-owned indented
     * content, or a dedented line the stream has no open paragraph to fold into.
     *
     * A dedented line is lazy continuation, exactly as it is for a plain lead:
     * where the sub-list ends in an open paragraph, `- - a` / `b` folds `b` into
     * the sub-item (carve-php#693). A sibling marker or a block opener at the
     * base column still ends the item, and after a CLOSED block (fenced code,
     * table, div) there is no open paragraph, so the dedented line ends it too.
     *
     * @param array<string> $lines All lines being parsed.
     * @param int $i Index of the first line AFTER the lead marker line.
     * @param int $count Total line count.
     * @param int $baseIndent The list's base column.
     * @param int $contentIndent The item's content column.
     * @param array<string> $itemLines Collected stream (lead marker line already present); appended in place.
     * @param array<int, int> $itemLineMap Source-line map for $itemLines; appended in place.
     *
     * @return int The index of the first line NOT consumed.
     */
    protected function collectMarkerLeadItem(
        array $lines,
        int $i,
        int $count,
        int $baseIndent,
        int $contentIndent,
        array &$itemLines,
        array &$itemLineMap,
    ): int {
        // Trailing-block state over the stream, seeded with the lead marker line
        // the caller already put there. A dedented line folds only where this
        // says a paragraph is open, which is the same gate the plain-lead
        // collector applies (PART 0 S4: no open paragraph, no lazy line).
        $trailingState = self::INITIAL_TRAILING_BLOCK_STATE;
        foreach ($itemLines as $seedLine) {
            $trailingState = $this->advanceTrailingBlockState($trailingState, $seedLine);
        }
        while ($i < $count) {
            $nextLine = $lines[$i];

            if (IndentationHelper::isBlankLine($nextLine)) {
                // A blank only stays inside the item when item-owned indented
                // content (>= content column) follows it; otherwise it ends the
                // item (the next non-blank starts a sibling or an outer block).
                $look = $i + 1;
                while ($look < $count && IndentationHelper::isBlankLine($lines[$look])) {
                    $look++;
                }
                if ($look >= $count || IndentationHelper::getLeadingColumns($lines[$look]) < $contentIndent) {
                    break;
                }
                $itemLines[] = '';
                $itemLineMap[] = $this->sourceLineFor($i);
                $trailingState = $this->advanceTrailingBlockState($trailingState, '');
                $i++;

                continue;
            }

            $nextIndent = IndentationHelper::getLeadingColumns($nextLine);
            if ($nextIndent < $contentIndent) {
                $nextTrimmed = ltrim($nextLine);
                // A sibling marker or a block opener at the base column belongs
                // to the caller's loop, and a stream ending in a closed block
                // has nothing to continue: both end the item.
                if (
                    !$trailingState['openParagraph']
                    || $this->listContinuationEndsAtDedentedBlock($nextIndent, $nextTrimmed, $baseIndent, $lines, $i)
                    || $this->listContinuationEndsAtBaseColumn($nextIndent, $nextTrimmed, $baseIndent, $lines, $i)
                ) {
                    break;
                }
                // Lazy continuation of the stream's own last paragraph. The
                // line carries exactly ONE column instead of being dedented by
                // the content column it never reached: below the sub-list's
                // content column a block-shaped line is paragraph text, and the
                // nested parse decides that from the column, so ` # H` folds as
                // text where a flush-left `# H` would open a heading. Its OWN
                // indentation is not enough - two columns in reached the nested
                // list's content column and opened a list there (carve#603) -
                // and one column can reach no content column at all.
                $itemLines[] = ' ' . $nextTrimmed;
                $itemLineMap[] = $this->sourceLineFor($i);
                $trailingState = $this->advanceTrailingBlockState($trailingState, $nextLine);
                $i++;

                continue;
            }

            $stripped = IndentationHelper::stripLeadingColumns($nextLine, $contentIndent);
            $itemLines[] = $stripped;
            $itemLineMap[] = $this->sourceLineFor($i);
            $trailingState = $this->advanceTrailingBlockState($trailingState, $stripped);
            $i++;
        }

        // Drop trailing blank lines from the collected stream.
        $lineCount = count($itemLines);
        while ($lineCount > 0 && $itemLines[$lineCount - 1] === '') {
            array_pop($itemLines);
            array_pop($itemLineMap);
            $lineCount--;
        }

        return $i;
    }

    /**
     * Decide whether a list item's lead content is a colon-fence opener with
     * item-owned body beneath it.
     *
     * Used to keep a marker-line opener and its item-owned continuation lines
     * in one block stream so the div/admonition parser captures its body.
     *
     * @param string $itemContent
     * @param array<string> $lines
     * @param int $i
     * @param int $count
     * @param int $contentIndent
     */
    protected function leadColonFenceHasBodyAtContentColumn(
        string $itemContent,
        array $lines,
        int $i,
        int $count,
        int $contentIndent,
    ): bool {
        if ($this->fencedBlockParser->parseDivFenceOpener($itemContent) === null) {
            return false;
        }

        while ($i < $count && IndentationHelper::isBlankLine($lines[$i])) {
            $i++;
        }

        return $i < $count && IndentationHelper::getLeadingColumns($lines[$i]) >= $contentIndent;
    }

    /**
     * Carve definition list (§4.5): `:: term` (exactly two colons, not a
     * `:::` div) lines, then `: definition` (colon + two spaces) lines.
     * Deeper-indented lines continue a definition; a single blank line may
     * separate entries. Renders to <dl> of <dt> then <dd>.
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseDefinitionList(Node $parent, array $lines, int $start): ?int
    {
        if (!preg_match(self::DEFINITION_TERM_PATTERN, $lines[$start])) {
            return null;
        }

        $dl = new DefinitionList();
        $this->applyPendingAttributes($dl);
        $i = $start;
        $count = count($lines);

        while ($i < $count && preg_match(self::DEFINITION_TERM_PATTERN, $lines[$i])) {
            // An entry: one or more terms, then one or more definitions.
            while ($i < $count && preg_match(self::DEFINITION_TERM_PATTERN, $lines[$i], $m)) {
                $termStart = $i;
                $termText = trim($m[1]);
                $termLines = [$termText];
                $i++;
                // A term folds a following plain line like a heading (soft
                // break), so a wrapped term line does not strand the definition.
                // A blank line, a new marker (`::` / `:  `), or a block opener /
                // list marker ends the term.
                while ($i < $count) {
                    $nextLine = $lines[$i];
                    if (
                        trim($nextLine) === ''
                        || preg_match(self::DEFINITION_TERM_LINE_PREFIX, $nextLine)
                        || preg_match('/^:\s\s+/', $nextLine)
                        || $this->endsHeadingOrQuote($nextLine, $lines, $i)
                        // A construct that renders nothing is not term text. The
                        // term was folding a comment, a reference / footnote /
                        // abbreviation definition and a block-attribute line in
                        // as continuation, putting their SOURCE in the `<dt>`.
                        // A comment BLOCK already ended the term, so this engine
                        // disagreed with itself as well as with the other two
                        // (carve-php#671).
                        || $this->isInvisibleOrAttributeLine($nextLine)
                    ) {
                        break;
                    }
                    $termLines[] = $nextLine;
                    $termText .= "\n" . $nextLine;
                    $i++;
                }
                // A term folds continuation lines exactly as a paragraph does,
                // so it needs the same per-line map rather than the single-line
                // one - which found nothing the moment a term wrapped.
                $termContentLines = [];
                $termFirstLine = $this->sourceLineFor($termStart);
                foreach ($termLines as $offsetInTerm => $termLine) {
                    $termContentLines[] = [
                        $termFirstLine < 0 ? -1 : $termFirstLine + $offsetInTerm,
                        0,
                        strlen($termLine),
                        $termLine,
                    ];
                }

                $term = new DefinitionTerm();
                $term->setPos($this->foldedLinesSpan($termContentLines) ?? $this->wholeLineSpan($termStart));
                $this->inlineParser->parse(
                    $term,
                    $termText,
                    $termStart,
                    sourceMap: $this->foldedLinesMap($termContentLines),
                );
                $this->stampNodeSourceLine($term, $this->sourceLineFor($termStart));
                $dl->appendChild($term);
            }
            while ($i < $count) {
                // A blank line before a `:  ` definition is a separator (djot
                // parity): a definition may be separated from its term or a
                // previous definition by a blank line. A blank not followed by a
                // `:  ` definition ends the entry.
                if (trim($lines[$i]) === '') {
                    $look = $i;
                    while ($look < $count && trim($lines[$look]) === '') {
                        $look++;
                    }
                    if ($look < $count && preg_match('/^:\s\s+/', $lines[$look])) {
                        $i = $look;
                    } else {
                        break;
                    }
                }
                if (!preg_match('/^:\s\s+(.+)$/', $lines[$i], $m)) {
                    break;
                }
                $definitionStart = $i;
                $i++;
                // First-block form (`:  +`, mirroring the list `- +`): when the
                // sole content is a lone `+`, the body is the FOLLOWING
                // flush-left block, with no indentation. `:  \+` is a literal `+`.
                $bodyMap = [];
                if (preg_match('/^\+[ \t]*$/', trim($m[1]))) {
                    $body = [];
                    while ($i < $count) {
                        $a = $lines[$i];
                        if (
                            trim($a) === ''
                            || preg_match('/^\+[ \t]*$/', $a)
                            || preg_match(self::DEFINITION_TERM_LINE_PREFIX, $a)
                            || preg_match('/^:\s\s+/', $a)
                        ) {
                            break;
                        }
                        $body[] = $a;
                        $bodyMap[] = $this->sourceLineFor($i);
                        $i++;
                    }
                } else {
                    $body = [trim($m[1])];
                    $bodyMap = [$this->sourceLineFor($definitionStart)];
                }
                // A definition body continues like a list item (SS17):
                //  - form A: a deeper-indented (>= 3) line folds in, and a blank
                //    line is tolerated when a later line still continues, so a
                //    `<dd>` can hold multiple paragraphs;
                //  - form B: a lone `+` attaches the FOLLOWING flush-left block
                //    with no indentation (the same continuation marker lists and
                //    block quotes use);
                //  - lazy continuation: a flush-left line with no blank before
                //    it that does not start an interrupting block folds into the
                //    open paragraph (matching list items, block quotes and djot).
                while ($i < $count) {
                    $contLine = $lines[$i];
                    // Form B: `+` pull-left continuation.
                    if (preg_match('/^\+[ \t]*$/', $contLine)) {
                        $i++;
                        $attached = [];
                        $attachedLineMap = [];
                        while ($i < $count) {
                            $a = $lines[$i];
                            if (
                                trim($a) === ''
                                || preg_match('/^\+[ \t]*$/', $a)
                                || preg_match(self::DEFINITION_TERM_LINE_PREFIX, $a)
                                || preg_match('/^:\s\s+/', $a)
                            ) {
                                break;
                            }
                            $attached[] = $a;
                            $attachedLineMap[] = $this->sourceLineFor($i);
                            $i++;
                        }
                        if ($attached) {
                            $body[] = '';
                            $bodyMap[] = -1;
                            foreach ($attached as $attachedIndex => $a) {
                                $body[] = $a;
                                $bodyMap[] = $attachedLineMap[$attachedIndex];
                            }
                        }

                        continue;
                    }
                    $indent = strlen($contLine) - strlen(ltrim($contLine, ' '));
                    // Form A: an indented continuation line (no intervening blank).
                    if (trim($contLine) !== '' && $indent >= 3) {
                        $body[] = ltrim($contLine);
                        $bodyMap[] = $this->sourceLineFor($i);
                        $i++;

                        continue;
                    }
                    // Blank line: absorb as a paragraph separator ONLY when a
                    // later line still continues the definition; otherwise leave
                    // it for the entry separator / outer block stream.
                    if (trim($contLine) === '') {
                        $look = $i;
                        while ($look < $count && trim($lines[$look]) === '') {
                            $look++;
                        }
                        $after = $lines[$look] ?? null;
                        $afterIndent = $after === null ? 0 : strlen($after) - strlen(ltrim($after, ' '));
                        if ($after !== null && trim($after) !== '' && $afterIndent >= 3) {
                            for (; $i < $look; $i++) {
                                $body[] = '';
                                $bodyMap[] = $this->sourceLineFor($i);
                            }

                            continue;
                        }
                    }

                    // A new term/definition marker ends this definition (the
                    // outer loop picks it up).
                    if (preg_match(self::DEFINITION_TERM_LINE_PREFIX, $contLine) || preg_match('/^:\s\s+/', $contLine)) {
                        break;
                    }
                    // Lazy continuation: a flush-left line with no blank before it
                    // that does not start an interrupting block folds into the
                    // open paragraph (the same rule list items and block quotes
                    // use; djot-compatible). A block opener ends the definition.
                    if (trim($contLine) !== '' && !$this->startsInterruptingBlock($contLine, $lines, $i)) {
                        $body[] = $contLine;
                        $bodyMap[] = $this->sourceLineFor($i);
                        $i++;

                        continue;
                    }

                    break;
                }
                $dd = new DefinitionDescription();
                $this->stampNodeSourceLine($dd, $this->sourceLineFor($definitionStart));
                $this->parseBlocks($dd, $body, 0, $bodyMap);
                $dl->appendChild($dd);
            }
            // Allow a single blank line before the next entry's `:: term`.
            if ($i < $count && trim($lines[$i]) === '') {
                $look = $i;
                while ($look < $count && trim($lines[$look]) === '') {
                    $look++;
                }
                if ($look < $count && preg_match(self::DEFINITION_TERM_LINE_PREFIX, $lines[$look])) {
                    $i = $look;

                    continue;
                }
            }

            break;
        }

        $parent->appendChild($dl);

        return $i - $start;
    }

    /**
     * Split lines into blocks separated by blank lines
     *
     * @param array<string> $lines
     *
     * @return array<array<string>>
     */
    protected function splitByBlankLines(array $lines): array
    {
        $blocks = [];
        $current = [];

        // Skip leading blank lines using index (avoid O(n) array_shift)
        $start = 0;
        $count = count($lines);
        while ($start < $count && IndentationHelper::isBlankLine($lines[$start])) {
            $start++;
        }

        for ($i = $start; $i < $count; $i++) {
            $line = $lines[$i];
            if (IndentationHelper::isBlankLine($line)) {
                if ($current !== []) {
                    $blocks[] = $current;
                    $current = [];
                }
            } else {
                $current[] = $line;
            }
        }

        // Don't forget the last block
        if ($current !== []) {
            $blocks[] = $current;
        }

        return $blocks;
    }

    /**
     * Whether a line opens a LINE BLOCK, and with which fence length.
     *
     * A bare pipe `|` is the line-block type token (carve spec, jgm/djot#29);
     * `::: |` is the only line-block opener, so an ordinary `::: note` div is
     * not one. Shared by the parser and by the footnote-definition pre-pass,
     * which has to skip a line block's body: two copies of this predicate would
     * drift, and the pre-pass having no copy at all is what made a definition
     * written inside a line block register a footnote (carve-php#685).
     *
     * @param string $line
     *
     * @return array{length: int, attrs: string|null}|null
     */
    protected function parseLineBlockOpener(string $line): ?array
    {
        $divInfo = $this->fencedBlockParser->parseDivFenceOpener($line);
        if ($divInfo === null) {
            return null;
        }

        if (
            preg_match(
                '/^\|(?:\s*(?<attrs>\{.*\}))?\s*$/s',
                $divInfo['className'],
                $openerMatches,
                PREG_UNMATCHED_AS_NULL,
            ) !== 1
        ) {
            return null;
        }

        /** @var int $length */
        $length = $divInfo['length'];

        return ['length' => $length, 'attrs' => $openerMatches['attrs'] ?? null];
    }

    /**
     * Try to parse a line block (preserves author line layout).
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseLineBlock(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        $divInfo = $this->parseLineBlockOpener($line);
        if ($divInfo === null) {
            return null;
        }

        $i = $start;
        $count = count($lines);
        $contentLines = [];
        $closed = false;

        $i++;
        while ($i < $count) {
            $currentLine = $lines[$i];

            if ($this->fencedBlockParser->isDivFenceCloser($currentLine, $divInfo['length'])) {
                $i++;
                $closed = true;

                break;
            }

            $contentLines[] = $currentLine;
            $i++;
        }

        if (!$closed) {
            $i = $count;
        }

        $lineBlock = new LineBlock();
        $this->applyPendingAttributes($lineBlock);
        if ($divInfo['attrs'] !== null) {
            AttributeParser::applyToNode($lineBlock, substr($divInfo['attrs'], 1, -1));
        }

        $stanza = [];
        $lineNumber = $start + 1;
        foreach ($contentLines as $contentLine) {
            if (IndentationHelper::isBlankLine($contentLine)) {
                $this->appendLineBlockStanza($lineBlock, $stanza);
                $stanza = [];
            } else {
                $stanza[] = [$contentLine, $lineNumber];
            }

            $lineNumber++;
        }
        $this->appendLineBlockStanza($lineBlock, $stanza);

        $parent->appendChild($lineBlock);

        return $i - $start;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Block\LineBlock $lineBlock
     * @param list<array{0: string, 1: int}> $lines
     */
    protected function appendLineBlockStanza(LineBlock $lineBlock, array $lines): void
    {
        if ($lines === []) {
            return;
        }

        $paragraph = new Paragraph();
        $lastIndex = count($lines) - 1;

        // The stanza's extent is first-line start to last-line end - pure line
        // geometry, which a tab does not move. Leaving it to be derived from the
        // first PLACED child gave a tab-containing stanza a paragraph starting
        // at the newline that ends its first line, so that line fell outside its
        // own paragraph: a wrong span, which PART 12 §4 rates worse than an
        // absent one (#669). A tab expands to indent sentinels and shifts every
        // offset after it WITHIN a line, which is why the inline text stays
        // unplaced - but the line's own start and end are unaffected.
        $this->stampBlockSpan(
            $paragraph,
            $this->sourceLineFor($lines[0][1]),
            $this->sourceLineFor($lines[$lastIndex][1]),
        );

        foreach ($lines as $index => [$line, $lineNumber]) {
            $this->appendLineBlockLine($paragraph, $line, $lineNumber);
            if ($index < $lastIndex) {
                // The break IS the line ending, so its extent is the newline
                // that terminates this stanza line.
                $hardBreak = new HardBreak();
                $hardBreak->setPos($this->endOfLineSpan($lineNumber));
                $paragraph->appendChild($hardBreak);
            }
        }

        $lineBlock->appendChild($paragraph);
    }

    /**
     * Append a single line-block line, preserving significant whitespace.
     *
     * Leading indentation is always kept (even a single column). An inner or
     * trailing run of TWO OR MORE columns is a medial gap (inline alignment,
     * e.g. the caesura of Old English verse) and is kept too; a lone inner space
     * stays an ordinary, collapsible space so a long line can still wrap between
     * words. Preserved columns are emitted via the internal non-breaking-space
     * placeholder (U+E000), which each renderer converts (HTML &nbsp;, Markdown
     * U+00A0, plain space) and which never collides with a literal U+00A0 in the
     * author's text. Tabs expand to four-column stops.
     */
    protected function appendLineBlockLine(Paragraph $paragraph, string $line, int $lineNo): void
    {
        $length = strlen($line);
        $offset = 0;
        $column = 0;
        $text = '';
        $seenContent = false;

        while ($offset < $length) {
            $char = $line[$offset];
            if ($char !== ' ' && $char !== "\t") {
                $text .= $char;
                $seenContent = true;
                $column++;
                $offset++;

                continue;
            }

            $width = 0;
            $runStart = $offset;
            while ($offset < $length && ($line[$offset] === ' ' || $line[$offset] === "\t")) {
                if ($line[$offset] === "\t") {
                    $width += 4 - (($column + $width) % 4);
                } else {
                    $width++;
                }
                $offset++;
            }
            $column += $width;

            if (!$seenContent || $width >= 2) {
                if ($text !== '') {
                    $this->inlineParser->parse(
                        $paragraph,
                        $text,
                        $lineNo,
                        sourceMap: $this->contiguousMapFor($lineNo, $this->sourceLines[$this->sourceLineFor($lineNo)] ?? '', $text),
                    );
                    $text = '';
                }
                // The indent's own node. Each placeholder stands for exactly
                // one source SPACE, so the run spans the same characters it
                // replaced and is placeable - a value differing from its slice
                // by a one-for-one substitution still covers its region.
                //
                // A TAB is the exception: it widens to up to four placeholders
                // from one character, so `$width` and the source run's length
                // disagree and any span would be too long. That run declines,
                // per run rather than per line (carve-rs#411 draws the same
                // line for the same reason).
                $indent = new Text(str_repeat("\u{E000}", $width));
                if ($width === $offset - $runStart) {
                    $indent->setPos($this->verseIndentSpan($lineNo, $runStart, $offset));
                }
                $paragraph->appendChild($indent);

                continue;
            }

            $text .= ' ';
        }

        if ($text !== '') {
            $this->inlineParser->parse(
                $paragraph,
                $text,
                $lineNo,
                sourceMap: $this->contiguousMapFor($lineNo, $this->sourceLines[$this->sourceLineFor($lineNo)] ?? '', $text),
            );
        }
    }

    /**
     * Parse a Carve table cell's tight alignment/header marker (written
     * tight against the pipe): optional `=` (header) then optional one of
     * `< > ~` (left/right/center). Returns the flags plus the content with
     * the marker stripped. Spaced markers (`| ^ |`, `| < |`) are span
     * markers, not alignment — their leading space means index 0 is not a
     * marker char, so they are left untouched here.
     *
     * @return array{header: bool, align: string|null, content: string}
     */
    protected function parseTableCellMarker(string $raw): array
    {
        $header = false;
        $rest = $raw;
        // A leading `=` glued to the pipe marks a header cell and is stripped;
        // the remaining content is parsed inline. This holds even when the next
        // char is also `=` (`|==|` -> <th>=</th>, `|==x==|` -> header cell whose
        // content `=x==` renders <mark>x</mark>=), matching carve-js / carve-rs.
        // A SPACED `| ==x== |` is not a header cell: the leading space means
        // index 0 is not `=`, so it is left untouched here.
        if (isset($rest[0]) && $rest[0] === '=') {
            $header = true;
            $rest = substr($rest, 1);
        }
        $align = match ($rest[0] ?? '') {
            '>' => TableCell::ALIGN_RIGHT,
            '<' => TableCell::ALIGN_LEFT,
            '~' => TableCell::ALIGN_CENTER,
            default => null,
        };
        if ($align !== null) {
            $rest = substr($rest, 1);
        }

        return ['header' => $header, 'align' => $align, 'content' => $rest];
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseTable(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];
        $count = count($lines);

        // Use TableParser to check if this is a valid table row
        if (!$this->tableParser->isTableRow($line)) {
            // Check if it's a potential table row with unclosed code span
            // that might be closed by continuation rows
            if (!$this->tableParser->isPotentialTableRowWithUnclosedCodeSpan($line)) {
                return null;
            }

            // Look ahead for continuation rows that might close the code span
            if (!$this->canCloseCodeSpanWithContinuations($lines, $start, $count)) {
                return null;
            }
        }

        $table = new Table();
        $i = $start;
        $alignments = [];
        // Per-column alignment from Carve header markers (|=>, |=~, |=<),
        // keyed by column position; propagates to the column's body cells.
        $columnAligns = [];
        $headerFound = false;
        // Whether the most recently added data row's own line was shaped like
        // a separator ( |:-:| ): such a row must not be promoted to a header
        // by a following separator - carve-js / carve-rs treat both lines as
        // ordinary data rows.
        $lastRowSeparatorShaped = false;
        // Per-column "open" origin cell, carried down across rows so a `^`
        // marker extends it in O(1) instead of rescanning all prior rows.
        $columnOrigin = [];
        // Columns the most recently parsed row consumed via a `<` (keyed by
        // column, present = consumed). Only ever needed for the ONE row that
        // might get promoted to a header on the very next line - referenced
        // there to avoid seeding a column origin for a placeholder that
        // covers no real cell of its own (a `^` under it must still degrade
        // to an empty cell, matching the ordinary body-row walk below).
        $lastRowConsumedColspanColumnSet = [];

        while ($i < $count) {
            $currentLine = $lines[$i];

            // Strip row attributes for validation (|...|{.class} → |...|)
            $lineWithoutRowAttrs = $this->tableParser->stripRowAttributes($currentLine);

            // Trailing whitespace after the closing pipe is insignificant
            // (parity with carve-js / carve-rs).
            if ($lineWithoutRowAttrs === '||' || !preg_match('/^\|.*\|[ \t]*$/', $lineWithoutRowAttrs)) {
                break;
            }

            // A GFM header separator is recognized ONLY as the table's second row
            // (exactly one row precedes it and no separator was seen yet): it makes
            // that first row the header. A delimiter line anywhere else -- leading,
            // or after the header/body -- is an ordinary data row. This matches
            // carve-js / carve-rs (the separator is the second row, period).
            if (
                $this->tableParser->isSeparatorRow($lineWithoutRowAttrs)
                && count($table->getChildren()) === 1
                && !$headerFound
                && !$lastRowSeparatorShaped
            ) {
                $alignments = $this->tableParser->parseTableAlignments($lineWithoutRowAttrs);
                $headerFound = true;

                // Store separator widths for round-trip preservation
                $separatorWidths = $this->tableParser->parseSeparatorWidths($lineWithoutRowAttrs);
                $table->setSeparatorWidths($separatorWidths);

                // Mark previous row as header and apply alignments to it
                $children = $table->getChildren();
                if ($children !== []) {
                    $lastRow = $children[count($children) - 1];
                    if ($lastRow instanceof TableRow) {
                        // Recreate as header row with alignments
                        $headerRow = new TableRow(true);
                        // Preserve row attributes from original row
                        $headerRow->setAttributes($lastRow->getAttributes());
                        // Same source, so the same span; it is a re-typing of
                        // the row that was already parsed, not a new one.
                        $headerRow->setPos($lastRow->getPos());
                        // Every column has its own cell now (placeholders
                        // included), so the cell's position in the row IS its
                        // grid column - no more `+= colspan` accounting for
                        // columns a merge dropped from the array.
                        $cellIndex = 0;
                        foreach ($lastRow->getChildren() as $cell) {
                            if ($cell instanceof TableCell) {
                                $alignment = $alignments[$cellIndex] ?? TableCell::ALIGN_DEFAULT;
                                // Preserve rowspan and colspan from original cell
                                $headerCell = new TableCell(
                                    true,
                                    $alignment,
                                    $cell->getRowspan(),
                                    $cell->getColspan(),
                                    $cell->getSpanMarker(),
                                );
                                // Preserve cell attributes from original cell
                                $headerCell->setAttributes($cell->getAttributes());
                                // Same source as the cell it replaces.
                                $headerCell->setPos($cell->getPos());
                                foreach ($cell->getChildren() as $child) {
                                    $headerCell->appendChild($child);
                                }
                                $headerRow->appendChild($headerCell);
                                // The promoted header cell replaces the original, so
                                // repoint the rowspan origin to the NEW cell (else a
                                // later `^` extends the detached old cell and the
                                // header rowspan is lost). A placeholder this row's
                                // own `<` consumed is not seeded -- a colspan does
                                // not claim the columns it merely covers, so a `^`
                                // under a covered column has no origin and degrades
                                // to an empty cell (matching the body-row grid walk
                                // and carve-js / carve-rs).
                                if (!isset($lastRowConsumedColspanColumnSet[$cellIndex])) {
                                    $columnOrigin[$cellIndex] = $headerCell;
                                }
                                $cellIndex++;
                            }
                        }
                        // Replace last row
                        $table->replaceChild(count($children) - 1, $headerRow);
                    }
                }
                $i++;

                continue;
            }

            // Extract row attributes (|...|{.class})
            $lastRowSeparatorShaped = $this->tableParser->isSeparatorRow($lineWithoutRowAttrs);
            $rowAttributes = $this->tableParser->extractRowAttributes($currentLine);

            // Parse cells with their attributes
            $cellsWithAttrs = $this->tableParser->parseTableCellsWithAttributes($currentLine);

            // Store cell contents and attributes for potential merging
            $mergedCells = array_map(fn ($c) => $c['content'], $cellsWithAttrs);
            $cellAttributes = array_map(fn ($c) => $c['attributes'], $cellsWithAttrs);
            $cellSourceChunks = [];
            foreach ($cellsWithAttrs as $idx => $cell) {
                $cellSourceChunks[$idx] = $this->tableCellSourceChunks($i, $cell);
            }
            $baseLineForRow = $i;

            $i++;

            // Check for continuation rows (lines starting with +)
            while ($i < $count && $this->tableParser->isContinuationRow($lines[$i])) {
                $continuationCells = $this->tableParser->parseContinuationCells($lines[$i]);
                foreach ($this->continuationCellSourceChunks($i, $lines[$i]) as $idx => $chunks) {
                    if ($chunks === []) {
                        continue;
                    }
                    $cellSourceChunks[$idx] = array_merge($cellSourceChunks[$idx] ?? [], $chunks);
                }
                $mergedCells = $this->tableParser->mergeCellContents($mergedCells, $continuationCells);
                $i++;
            }

            // Rebuild cellsWithAttrs with merged content
            $mergedCellsWithAttrs = [];
            foreach ($mergedCells as $idx => $content) {
                // A cell whose content was merged from a continuation row is no
                // longer a run of THIS line, so it keeps the offset but loses
                // the claim to be a verbatim slice, and declines a position.
                $original = $cellsWithAttrs[$idx] ?? null;
                $mergedCellsWithAttrs[] = [
                    'content' => $content,
                    'attributes' => $cellAttributes[$idx] ?? '',
                    'offset' => $original === null ? null : $original['offset'],
                    'rawLength' => $original === null ? null : $original['rawLength'],
                    'raw' => $original === null ? null : $original['raw'],
                    'verbatim' => $original !== null
                        && $original['verbatim']
                        && $content === $original['content'],
                    'sourceChunks' => $cellSourceChunks[$idx] ?? [],
                ];
            }

            // Resolve `<`/`^` span markers into the row's output cells with the
            // same single grid walk the carve-js renderer uses (see resolveRowSpans).
            // Every column keeps its own placeholder cell now (carve-js parity,
            // uniform row width); a column a marker actually merged into a
            // target - as opposed to a degenerate marker with no target - is
            // recorded here so it stays invisible to the header-row check and
            // to column-origin tracking below, exactly as it was before it had
            // a cell of its own.
            $resolved = $this->resolveRowSpans($mergedCellsWithAttrs, $columnOrigin);
            $processedCells = $resolved['cells'];
            $consumedRowspanColumns = $resolved['consumedRowspanColumns'];
            $consumedColspanColumns = $resolved['consumedColspanColumns'];
            $consumedColumnSet = array_flip(array_merge($consumedRowspanColumns, $consumedColspanColumns));
            $lastRowConsumedColspanColumnSet = array_flip($consumedColspanColumns);

            // Carve header row: every cell is "=" prefixed (|= Header |).
            // No separator row is used. "==x==" stays a normal cell
            // (highlight), so "=" must not be followed by another "=".
            $isHeaderRow = $processedCells !== [];
            // A row where every column is consumed by a span (an all-`^`
            // rowspan continuation, say) has NOTHING left to examine below,
            // so it must not default to header-row-true just because it is
            // non-empty - before every marker kept its own cell, such a row
            // WAS empty and this defaulted correctly. `$examinedAny` restores
            // that: no examined cell means this is never a Carve header row.
            $examinedAny = false;
            foreach ($processedCells as $cellData) {
                if (isset($consumedColumnSet[$cellData['gridColumn']])) {
                    // A placeholder consumed by another cell's span was never
                    // its own entry before it got a cell of its own; it still
                    // is not examined here (carve-js parity).
                    continue;
                }
                $examinedAny = true;
                $content = $cellData['content'];
                // An empty span cell (a `<`/`^` that became its own slot) and a
                // cell carrying an attribute block are never `|=` header cells, so
                // they never make the row a Carve all-header row (carve-js parity).
                if (
                    $cellData['isEmpty']
                    || $cellData['attributes'] !== ''
                    || preg_match('/^=([^=]|$)/', $content) !== 1
                ) {
                    $isHeaderRow = false;

                    break;
                }
            }
            if (!$examinedAny) {
                $isHeaderRow = false;
            }

            // Parse regular row
            $row = new TableRow($isHeaderRow);
            $row->setPos($this->wholeLineSpan($baseLineForRow));
            if ($rowAttributes) {
                $row->setAttributes($rowAttributes);
            }

            // Build the row's cells. Spans are already resolved in the grid above,
            // so every entry in $processedCells emits exactly one cell; its
            // gridColumn keys per-column alignment, matching the carve-js renderer.
            /** @var array<array{cell: \MarkupCarve\Carve\Node\Block\TableCell, colPosition: int}> $rowCellData */
            $rowCellData = [];
            foreach ($processedCells as $cellData) {
                $colspan = $cellData['colspan'];
                $col = $cellData['gridColumn'];

                if ($cellData['isEmpty']) {
                    // A `<`/`^` marker that became an empty cell of its own (left
                    // edge, or a degenerate `^` with no cell above). It occupies
                    // its grid position and is never dropped. It still takes the
                    // column's alignment -- the Carve header marker first, then the
                    // GFM separator alignment -- so an empty span cell lines up with
                    // the real cells in its column (carve-js parity).
                    $alignment = $columnAligns[$col]
                        ?? $alignments[$col]
                        ?? TableCell::ALIGN_DEFAULT;
                    $cell = new TableCell($isHeaderRow, $alignment, 1, $colspan);
                    $cell->setSpanMarker($cellData['spanMarker']);
                    // The marker character occupies a real slice of this line,
                    // so the cell gets a position the same way an ordinary cell
                    // does (carve-php#510).
                    $cell->setPos($this->cellExtentSpan($baseLineForRow, $cellData));
                    $row->appendChild($cell);
                    // A column this marker actually merged into a target does
                    // NOT become that column's open origin -- the origin
                    // already open above (or to the left) stays the one a
                    // later `^` extends (matches carve-js: a consumed grid
                    // entry is excluded from `lastNonSkip`). Only a degenerate
                    // marker (no target) is eligible, same as before it kept
                    // its own row slot.
                    if (!isset($consumedColumnSet[$col])) {
                        $rowCellData[] = ['cell' => $cell, 'colPosition' => $col];
                    }

                    continue;
                }

                // Parse the tight alignment/header marker. A header row fixes
                // per-column alignment; a cell's own marker overrides it; a djot
                // separator row is the final fallback. A cell carrying a `{...}`
                // attribute block has no tight marker -- its content is literal
                // (so `{.x} <` keeps the `<`).
                $marker = $cellData['attributes'] !== ''
                    ? ['align' => null, 'header' => false, 'content' => $cellData['content']]
                    : $this->parseTableCellMarker($cellData['content']);
                if ($isHeaderRow && $marker['align'] !== null) {
                    $columnAligns[$col] = $marker['align'];
                }
                $alignment = $marker['align']
                    ?? $columnAligns[$col]
                    ?? $alignments[$col]
                    ?? TableCell::ALIGN_DEFAULT;
                // A cell carries its own `=` marker even in a body row, so a
                // `|=` cell in a data row becomes a row header (<th> inside
                // <tbody>). The row stays a body row; only the cell is a header.
                $cell = new TableCell($isHeaderRow || $marker['header'], $alignment, 1, $colspan);
                if ($cellData['attributes'] !== '') {
                    // Apply in source order (matching inline attributes and
                    // carve-js), not via setAttributes() which reorders.
                    AttributeParser::applyToNode($cell, $cellData['attributes']);
                }
                $trimmedContent = trim($marker['content']);
                $cellMap = $this->cellSourceMap($baseLineForRow, $cellData, $trimmedContent)
                    ?? $this->rebuiltCellSourceMap($cellData, $trimmedContent);
                $cellSpan = $this->cellExtentSpan($baseLineForRow, $cellData);
                // The text's OWN extent inside the cell: the cell span covers
                // the padding too, so the text is located within the raw slice
                // the split kept for exactly this.
                $cellTextSpan = $this->cellContentSpan($baseLineForRow, $cellData, $trimmedContent);
                if ($trimmedContent !== '' && $this->isPlainTableText($trimmedContent) && $this->appendPlainRebuiltCellText($cell, $cellData, $trimmedContent)) {
                    $cell->setPos($cellSpan ?? ($cellMap?->spanFor(0, $trimmedContent)));
                    $row->appendChild($cell);
                    $rowCellData[] = ['cell' => $cell, 'colPosition' => $col];

                    continue;
                }
                if ($trimmedContent !== '' && $this->isPlainText($trimmedContent)) {
                    $text = new Text($trimmedContent);
                    $text->setPos($cellMap?->spanFor(0, $trimmedContent) ?? $cellTextSpan);
                    $cell->appendChild($text);
                } else {
                    $this->inlineParser->parse($cell, $trimmedContent, $baseLineForRow, sourceMap: $cellMap);
                }
                // Prefer the measured extent: it covers the cell even when its
                // text was rewritten (an escaped pipe), where a text lookup
                // cannot match and rightly declines.
                $cell->setPos($cellSpan ?? ($cellMap?->spanFor(0, $trimmedContent)));
                $row->appendChild($cell);
                $rowCellData[] = ['cell' => $cell, 'colPosition' => $col];
            }

            // Resolve rowspan markers: each consumed `^` extends the cell open in
            // its column from a row above. Multiple `^` against one origin extend
            // it only once per row. The grid pass already flagged which columns
            // hold a consumed `^` ($consumedRowspanColumns).
            $extendedCells = [];
            foreach ($consumedRowspanColumns as $col) {
                $origin = $columnOrigin[$col] ?? null;
                if ($origin instanceof TableCell) {
                    $originId = spl_object_id($origin);
                    if (!isset($extendedCells[$originId])) {
                        $origin->setRowspan($origin->getRowspan() + 1);
                        $extendedCells[$originId] = true;
                    }
                }
            }

            // Carry each emitted cell down as the open origin for its grid column.
            // Only the cell's own start column is seeded (matching the carve-js
            // renderer, where a colspan does not claim the columns it merely
            // covers); a column consumed by a `^` keeps the origin above it.
            foreach ($rowCellData as $cellInfo) {
                $columnOrigin[$cellInfo['colPosition']] = $cellInfo['cell'];
            }

            $table->appendChild($row);
        }

        // A separator-only table is valid (creates empty table)
        // Only return null if we didn't parse anything at all
        if (count($table->getChildren()) === 0 && !$headerFound) {
            return null;
        }

        $this->applyPendingAttributes($table);
        $parent->appendChild($table);

        // Caption parsing is now handled by tryParseCaption

        return $i - $start;
    }

    /**
     * Resolve a table row's `<`/`^` span markers into output cells using the
     * same single LEFT-TO-RIGHT grid walk the carve-js renderer uses (carve spec
     * section 96).
     *
     * Every source cell occupies exactly one grid column and its index IS its
     * grid column - and, per carve-js parity (carve-php#527), EVERY column
     * keeps an output cell of its own, marker or not: a row never loses a cell,
     * so every row in a table has the same width. For each column:
     *  - a `<` (colspan marker) is always its own empty cell. When there is a
     *    NON-SKIPPED cell to its left - scanning PAST columns already merged
     *    into another span - it ALSO grows that cell's reported colspan and is
     *    recorded as consumed. At the very left edge (no cell to the left) it
     *    stays unconsumed, degrading to a plain empty cell a following `<` can
     *    still grow.
     *  - a `^` (rowspan marker) is likewise always its own empty cell, and is
     *    additionally recorded as consumed when its column has an open origin
     *    from a row above (the rowspan pass extends that origin once per row).
     *    With no cell above it stays unconsumed - a degenerate marker.
     *  - any other cell is a normal content cell.
     * A consumed column's own reported width is 1 (its span was credited to
     * the cell it merged into, matching carve-js keeping a placeholder rather
     * than a count on the origin); the caller uses `consumedRowspanColumns` /
     * `consumedColspanColumns` to know which columns must NOT become a new
     * open origin for a later row, exactly as if they had been dropped.
     *
     * @param array<int, array{content: string, attributes: string, offset: int|null, verbatim: bool, rawLength: int|null, raw: string|null, sourceChunks?: list<array{int, int, string}>}> $mergedCellsWithAttrs
     * @param array<int, \MarkupCarve\Carve\Node\Block\TableCell> $columnOrigin Per-column open
     *   origin cell carried down from earlier rows.
     *
     * @return array{cells: array<array{content: string, attributes: string, colspan: int<1, max>, gridColumn: int, isEmpty: bool, spanMarker: string|null, offset: int|null, rawLength: int|null, raw: string|null, verbatim: bool, sourceChunks: list<array{int, int, string}>}>, consumedRowspanColumns: array<int>, consumedColspanColumns: array<int>}
     */
    protected function resolveRowSpans(array $mergedCellsWithAttrs, array $columnOrigin): array
    {
        // Parallel per-column state (keyed by grid column = source index). Plain
        // scalar maps so the colspan++ / skip mutations stay simple for the
        // static analyzer.
        $count = count($mergedCellsWithAttrs);
        /** @var array<int, bool> $skip */
        $skip = array_fill(0, $count, false);
        /** @var array<int, bool> $empty */
        $empty = array_fill(0, $count, false);
        /** @var array<int, int> $colspan */
        $colspan = array_fill(0, $count, 1);
        /** @var array<int, string|null> $spanMarkers */
        $spanMarkers = array_fill(0, $count, null);
        $consumedRowspanColumns = [];
        $consumedColspanColumns = [];

        foreach ($mergedCellsWithAttrs as $col => $cellData) {
            $isColspanMarker = $cellData['attributes'] === ''
                && $this->tableParser->isColspanMarker($cellData['content']);
            $isRowspanMarker = $cellData['attributes'] === ''
                && $this->tableParser->isRowspanMarker($cellData['content']);

            // A cell carrying attributes is never a bare span marker, so its
            // `<`/`^` content is literal (carve-js / carve-rs parity).
            if ($isColspanMarker) {
                // Always its own placeholder cell (carve-js parity, uniform
                // row width); consumed on top of that when a target exists.
                $empty[$col] = true;
                $spanMarkers[$col] = '<';

                if ($col > 0) {
                    // Scan left, skipping columns already consumed by a span.
                    $left = $col - 1;
                    while ($left >= 0 && ($skip[$left] ?? false)) {
                        $left--;
                    }
                    if ($left >= 0) {
                        // Merge into the available cell to the left: its
                        // reported width grows by one column, and this column
                        // is consumed (its own reported width stays 1).
                        $colspan[$left] = ($colspan[$left] ?? 1) + 1;
                        $skip[$col] = true;
                        $consumedColspanColumns[] = $col;
                    }
                    // Ran off the left edge: stays an unconsumed empty cell (a
                    // later `<` can still grow it).
                }

                continue;
            }

            if ($isRowspanMarker) {
                // Always its own placeholder cell; consumed only when an
                // origin is actually open above it (resolved in the rowspan
                // pass). With no cell above it is a degenerate marker.
                $empty[$col] = true;
                $spanMarkers[$col] = '^';

                if (isset($columnOrigin[$col])) {
                    $skip[$col] = true;
                    $consumedRowspanColumns[] = $col;
                }
            }
        }

        // Every column emits a cell now - a consumed column's own width is 1;
        // the width it contributed lives on the cell it merged into.
        $cells = [];
        foreach ($mergedCellsWithAttrs as $col => $cellData) {
            $isEmpty = $empty[$col] ?? false;
            $width = ($skip[$col] ?? false) ? 1 : ($colspan[$col] ?? 1);
            $cells[] = [
                'content' => $isEmpty ? '' : $cellData['content'],
                'attributes' => $isEmpty ? '' : $cellData['attributes'],
                'colspan' => max(1, $width),
                'gridColumn' => $col,
                'isEmpty' => $isEmpty,
                'spanMarker' => $spanMarkers[$col],
                // A span marker (`^`/`<`) is still a real character the author
                // wrote at a real column on this line, so the cell built from
                // this entry CAN carry a position - only its CONTENT is
                // suppressed, because a marker is not text (carve-php#510).
                // `verbatim` alone stays false: an empty cell has no text to map
                // inline attributes or spans against.
                'offset' => $cellData['offset'],
                'rawLength' => $cellData['rawLength'],
                'raw' => $cellData['raw'],
                'verbatim' => !$isEmpty && $cellData['verbatim'],
                'sourceChunks' => $isEmpty ? [] : ($cellData['sourceChunks'] ?? []),
            ];
        }

        return [
            'cells' => $cells,
            'consumedRowspanColumns' => $consumedRowspanColumns,
            'consumedColspanColumns' => $consumedColspanColumns,
        ];
    }

    /**
     * Check if a row with unclosed code spans can be closed by continuation rows.
     *
     * This looks ahead for continuation rows and checks if merging their content
     * would result in balanced code spans.
     *
     * @param array<string> $lines All lines
     * @param int $start Starting line index
     * @param int $count Total line count
     *
     * @return bool True if continuation rows can close the code spans
     */
    protected function canCloseCodeSpanWithContinuations(array $lines, int $start, int $count): bool
    {
        $baseLine = $lines[$start];

        // Parse cells from base row (using raw parsing that ignores code span issues)
        $baseCells = $this->tableParser->parseTableCellsRaw($baseLine);
        if ($baseCells === []) {
            return false;
        }

        $mergedCells = $baseCells;
        $i = $start + 1;

        // Look for continuation rows
        while ($i < $count && $this->tableParser->isContinuationRow($lines[$i])) {
            $continuationCells = $this->tableParser->parseContinuationCells($lines[$i]);
            $mergedCells = $this->tableParser->mergeCellContents($mergedCells, $continuationCells);
            $i++;
        }

        // Check if we found any continuations and if merged content is valid
        if ($i === $start + 1) {
            // No continuation rows found
            return false;
        }

        return $this->tableParser->mergedCellsAreValid($mergedCells);
    }

    /**
     * Skip footnote definitions (already extracted in first pass)
     *
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseFootnoteDefinition(array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Match footnote definition: [^label]: content. Must mirror the
        // pre-pass collector exactly (literal space separator, PART 9 §16):
        // a bare `[^label]:` - or a tab-separated body the collector does not
        // accept - is never skipped here; it parses as a paragraph.
        if (!preg_match('/^\[\^([^\]]+)\]: +\S/', $line)) {
            return null;
        }

        // Skip the footnote definition and any continuation lines
        $i = $start + 1;
        $count = count($lines);

        while ($i < $count) {
            $nextLine = $lines[$i];
            if (IndentationHelper::isBlankLine($nextLine)) {
                // A blank line continues the footnote only if a >= 2-indented
                // line (or a `+` continuation marker) follows; otherwise it ends
                // the footnote. Must mirror the body-collection logic so a line
                // is never skipped here without being collected there (grammar
                // PART 9 §16, §17).
                if (
                    $i + 1 < $count
                    && (preg_match('/^(?:[ ]{2}|\t)/', $lines[$i + 1]) || preg_match('/^\+[ \t]*$/', $lines[$i + 1]))
                ) {
                    $i++;

                    continue;
                }

                break;
            }
            // Form B: a `+` continuation marker plus its attached flush-left
            // block (ends at a blank line, another `+`, or the next footnote
            // definition) - mirror extractFootnotes exactly.
            if (preg_match('/^\+[ \t]*$/', $nextLine)) {
                $i++;
                while ($i < $count) {
                    $a = $lines[$i];
                    if (
                        IndentationHelper::isBlankLine($a)
                        || preg_match('/^\+[ \t]*$/', $a)
                        || preg_match('/^\[\^[^\]]+\]:/', $a)
                    ) {
                        break;
                    }
                    $i++;
                }

                continue;
            }
            if (preg_match('/^(?:[ ]{2}|\t)/', $nextLine)) {
                $i++;
            } else {
                break;
            }
        }

        return $i - $start;
    }

    /**
     * Skip reference definitions (already extracted in first pass)
     *
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseReferenceDefinition(array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Match reference definition: [label]: url. The definition is
        // single-line and the destination must be present (an empty `[r]:` is
        // literal, not a definition), matching the first-pass collector and
        // the canonical carve-js / carve-rs. No continuation gathering. The
        // separator after `]:` must START with a literal SPACE; a tab-first
        // separator (`[r]:\t/u`) does not form a definition (issue 288).
        // `[^…]:` with a NON-EMPTY label is a footnote definition and takes
        // precedence, so it is excluded here. `[^]:` is not: `footnote_label`
        // is one-or-more characters, so an empty label never forms a footnote
        // definition and the line falls through to a reference definition with
        // the label `^` - which `reference_label` admits, being neither `]`
        // nor `@`. Excluding every `[^` left that line as paragraph text, where
        // carve-js and carve-rs both render nothing.
        if (!preg_match('/^\[(?!@)(?!\^[^\]]+\]:)([^\]]+)\]: [ \t]*(\S.*)$/', $line, $matches)) {
            return null;
        }

        // `\S` is satisfied by a Unicode space, so a destination made only of
        // those looks present here and is empty once trimmed. It has to stay
        // literal like `[r]:` does, and the first-pass collector applies the
        // same re-check - otherwise this pass would swallow a line the
        // collector deliberately refused to register (carve#352).
        if (self::trimUnicodeWhitespace($matches[2]) === '') {
            return null;
        }

        // The line is CONSUMED here and the node is appended at DOCUMENT level
        // instead. PART 12 §10 hoists a link reference definition exactly as §7
        // hoists the other two kinds, and `$parent` is whatever container the
        // line sits in - appending here put the node inside a block quote and
        // changed that quote's rendering.
        return 1;
    }

    /**
     * Skip abbreviation definitions (already extracted in first pass)
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     * @param bool $topLevel
     */
    protected function tryParseAbbreviationDefinition(
        Node $parent,
        array $lines,
        int $start,
        bool $topLevel = false,
    ): ?int {
        // PART 12 §7: recognized ONLY at document level. Inside a block quote,
        // list item or div the line falls through to the paragraph branch and
        // is preserved as the text the author typed.
        if (!$topLevel) {
            return null;
        }

        $line = $lines[$start];

        // Match abbreviation definition: *[abbr]: definition
        if (!$this->isAbbreviationDefinitionLine($line)) {
            return null;
        }

        // Collect continuation lines
        $i = $start + 1;
        $count = count($lines);

        while ($i < $count) {
            $nextLine = $lines[$i];
            if (IndentationHelper::isBlankLine($nextLine)) {
                break;
            }
            // Check if next line starts a new abbreviation definition
            if ($this->isAbbreviationDefinitionLine($nextLine)) {
                break;
            }
            if ($this->startsNewBlock($nextLine)) {
                break;
            }
            // A list marker (bullet or ordered, at any indent) starts a list,
            // not a definition continuation; stop the skip so it is parsed.
            if ($this->listParser->parseListItemMarker(ltrim($nextLine)) !== null) {
                break;
            }
            if (preg_match('/^\s+(.+)$/', $nextLine)) {
                $i++;
            } else {
                break;
            }
        }

        // The line is KEPT as a node rather than merely skipped. It renders
        // nothing on HTML and is emitted as written on the non-HTML targets
        // (PART 11 §10a), and those renderers walk `children` - so a definition
        // that leaves no node cannot be put back where the author wrote it. The
        // expansions are collected separately by extractAbbreviations(); this
        // carries the AUTHORED line (markup-carve/carve-php#708).
        if (preg_match(self::ABBREVIATION_DEFINITION_PATTERN, $line, $m) === 1) {
            $node = new AbbreviationDefinition($m[1], trim($m[2]));
            $parent->appendChild($node);
        }

        return $i - $start;
    }

    protected function isAbbreviationDefinitionLine(string $line): bool
    {
        return preg_match(self::ABBREVIATION_DEFINITION_PATTERN, $line) === 1;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     * @param bool $topLevel
     */
    protected function tryParseParagraph(Node $parent, array $lines, int $start, bool $topLevel = false): int
    {
        $line = $lines[$start];
        // Strip leading whitespace from first line (matching JS reference)
        $content = ltrim($line);
        /** @var list<string> $contentParts */
        $contentParts = [$content];
        $hasUnclaimedColonFenceLine = $this->paragraphHasUnclaimedColonFenceLine($content);
        // Where each folded line's content sits, so a multi-line paragraph can
        // still place its inlines: [line index, column in that line, length].
        // Nested content arrives PRE-JOINED: a list item hands its body over as
        // one entry containing newlines, so recording it as a single segment
        // would produce text that appears in no source line at all. Split it
        // back into physical lines, which is what the map resolves against.
        /** @var list<array{int, int, int, string}> $contentLines */
        $contentLines = [];
        $indent = strlen($line) - strlen($content);
        $firstSourceLine = $this->sourceLineFor($start);
        $this->appendParagraphContentLines($contentLines, $firstSourceLine, $indent, $content);

        $i = $start + 1;
        $count = count($lines);

        while ($i < $count) {
            $nextLine = $lines[$i];

            if (IndentationHelper::isBlankLine($nextLine)) {
                break;
            }

            // An unclosed `{` used to suppress this check, so every following
            // line became paragraph text until a blank line. That published
            // COMMENT bodies - `%%` and `%%%` hold content the author does not
            // want in the output - and swallowed headings and fences.
            //
            // It was this engine's rule alone: carve-js and carve-rs interrupt
            // normally after `text{a=x`, which is the example the rule was
            // written for, and PART 9 §10's I1 says nothing about brace state.
            // It protected nothing either - an inline attribute block cannot
            // span lines in any engine.
            if ($this->interruptsParagraph($lines, $i, $contentParts, $start, $hasUnclaimedColonFenceLine, $topLevel)) {
                break;
            }

            // Strip leading whitespace from continuation lines (matching JS reference)
            $rawNextLine = $nextLine;
            $nextLine = ltrim($nextLine);
            $this->appendParagraphContentLines(
                $contentLines,
                $this->sourceLineFor($i),
                strlen($rawNextLine) - strlen($nextLine),
                $nextLine,
            );
            $contentParts[] = $nextLine;
            $hasUnclaimedColonFenceLine = $hasUnclaimedColonFenceLine
                || $this->paragraphHasUnclaimedColonFenceLine($nextLine);
            $i++;
        }

        $content = implode("\n", $contentParts);

        // TRAILING WHITESPACE (NORMATIVE, grammar PART 2 paragraph rule; pinned
        // by corpus 102). Whitespace at the end of the paragraph's FINAL line is
        // stripped BEFORE rendering. It is applied here, to the SOURCE, rather
        // than to rendered output: a renderer cannot tell authored trailing
        // whitespace from spaces a construct legitimately produced, so trimming
        // the output ate the content of an all-space inline literal
        // (`` !`  ` `` alone rendered `<p></p>` instead of `<p>  </p>`).
        // Only the final line is affected - interior lines are followed by a
        // newline, so this rtrim cannot reach them. Space and tab only, matching
        // carve-rs; a trailing NBSP is content everywhere and must survive.
        $trimmedContent = rtrim($content, " \t");
        if ($trimmedContent !== $content) {
            $last = count($contentLines) - 1;
            $shrink = strlen($content) - strlen($trimmedContent);
            [$lineIndex, $column, $length, $lineText] = $contentLines[$last];
            $contentLines[$last] = [
                $lineIndex,
                $column,
                $length - $shrink,
                substr($lineText, 0, max(0, strlen($lineText) - $shrink)),
            ];
        }
        $content = $trimmedContent;

        $paragraph = new Paragraph();
        // Set here rather than leaving it to the block-loop stamp, which spans
        // whole lines: a folded paragraph knows exactly which lines it took and
        // where its content starts and ends within them.
        $paragraph->setPos($this->foldedLinesSpan($contentLines));
        $this->inlineParser->parse(
            $paragraph,
            $content,
            $start,
            sourceMap: $this->foldedLinesMap($contentLines),
        );
        $this->applyPendingAttributes($paragraph);
        $parent->appendChild($paragraph);

        return $i - $start;
    }

    /**
     * @param list<array{int, int, int, string}> &$contentLines
     * @param int $firstSourceLine
     * @param int $firstColumn
     * @param string $text
     */
    private function appendParagraphContentLines(array &$contentLines, int $firstSourceLine, int $firstColumn, string $text): void
    {
        foreach (explode("\n", $text) as $piece => $pieceText) {
            // The source line is resolved HERE, not later: the pieces are
            // consecutive physical lines, but pre-joined list-item content has
            // no line-map entry for embedded lines past the first.
            $contentLines[] = [
                $firstSourceLine < 0 ? -1 : $firstSourceLine + $piece,
                $piece === 0 ? $firstColumn : 0,
                strlen($pieceText),
                $pieceText,
            ];
        }
    }

    /**
     * A source map for inline text that is a verbatim run of ONE source line.
     *
     * The dominant case - a single-line paragraph, a heading, a cell - where the
     * only difference from the source is leading and trailing whitespace, so the
     * content sits at a known offset within the line and nothing was joined or
     * re-indented.
     *
     * Returns null the moment that does not hold (a multi-line paragraph, text
     * the block layer rebuilt). The inline parser then places nothing, which is
     * what PART 12 section 4 requires of a position it cannot know.
     */

    /**
     * A source map for one table cell's content.
     *
     * The cell's own offset comes from the split (TableParser::splitCells), not
     * from searching the row - `| a | a |` has two cells with identical text,
     * and a span selecting the right BYTES at the wrong cell would pass every
     * check a consumer could apply. Searching WITHIN the cell is fine, and is
     * how the alignment marker and surrounding whitespace are skipped.
     *
     * @param int $index
     * @param array{content: string, attributes: string, offset?: int|null, verbatim?: bool} $cellData
     * @param string $content
     */
    /**
     * The span covering one whole source line, for a node that IS its line.
     *
     * A table row is the case: it has no content of its own beyond the cells,
     * and its extent is exactly the line it was read from.
     */

    /**
     * The span covering every source line a nested block was built from.
     *
     * A list item is the case: its content is a re-indented copy of several
     * lines, and the line map is what records which ones. Using it keeps the
     * item's extent honest without needing the item text to be a slice.
     *
     * @param array<int, int> $lineMap
     */
    private function spanForLineMap(array $lineMap): ?SourceSpan
    {
        if (!$this->trackPositions || $lineMap === []) {
            return null;
        }

        $first = $lineMap[array_key_first($lineMap)];
        $last = $lineMap[array_key_last($lineMap)];
        $start = $this->lineStartOffsets[$first] ?? null;
        $lastStart = $this->lineStartOffsets[$last] ?? null;
        if ($start === null || $lastStart === null) {
            return null;
        }

        $lastLength = strlen($this->sourceLines[$last] ?? '');

        return $this->positionIndex?->span(
            $start,
            $lastStart + $lastLength,
            $first + 1,
            $last + 1,
            $start,
            $lastStart,
        );
    }

    /**
     * A source map for text folded from one or more whole lines.
     *
     * A paragraph's continuation lines are joined with "\n" after their
     * indentation is stripped, so the built string is not a slice of anything -
     * but each line within it IS. One segment per line is enough to resolve any
     * position in the result, which is what lets a multi-line paragraph place
     * its inlines instead of declining wholesale.
     *
     * @param list<array{int, int, int}> $contentLines line index, column, length
     */

    /**
     * The span from the first folded line's content to the last line's end.
     *
     * @param list<array{int, int, int, string}> $contentLines resolved source line, column, length, text
     */
    private function foldedLinesSpan(array $contentLines): ?SourceSpan
    {
        if (!$this->trackPositions || $contentLines === []) {
            return null;
        }

        [$firstLine, $firstColumn, , $firstText] = $contentLines[0];
        [$lastLine, $lastColumn, $lastLength, $lastText] = $contentLines[count($contentLines) - 1];

        // Both ends must actually be found in the lines they claim, or the span
        // would cover bytes belonging to something else.
        $firstFound = $firstText === '' ? $firstColumn : strpos($this->sourceLines[$firstLine] ?? '', $firstText);
        $lastFound = $lastText === '' ? $lastColumn : strpos($this->sourceLines[$lastLine] ?? '', $lastText);
        if ($firstFound === false || $lastFound === false) {
            return null;
        }
        $firstColumn = $firstFound;
        $lastColumn = $lastFound;
        $start = $this->lineStartOffsets[$firstLine] ?? null;
        $lastStart = $this->lineStartOffsets[$lastLine] ?? null;
        if ($start === null || $lastStart === null) {
            return null;
        }

        return $this->positionIndex?->span(
            $start + $firstColumn,
            $lastStart + $lastColumn + $lastLength,
            $firstLine + 1,
            $lastLine + 1,
            $start,
            $lastStart,
        );
    }

    /**
     * Map an admonition opener's quoted title back to the source it came from,
     * so its inline content can be placed.
     *
     * The title reaches this point as a regex capture out of an already-split
     * class string, so its column is not in hand - but the QUOTED form is
     * unambiguous in the opener line in a way the bare title is not. Searching
     * for `title` alone would match the type word first in `::: note "note"`,
     * pointing every inline in the title four columns too far left; searching
     * for the quoted form cannot, because the type word carries no quotes.
     *
     * Returns null when positions are off or the quoted form is not found,
     * which leaves the title's inlines unplaced rather than placed wrongly.
     */
    private function openerTitleMap(int $line, string $title): ?SourceMap
    {
        if (!$this->trackPositions || $title === '') {
            return null;
        }
        $lineText = $this->sourceLines[$line] ?? null;
        $lineStart = $this->lineStartOffsets[$line] ?? null;
        if ($lineText === null || $lineStart === null) {
            return null;
        }
        $quotedAt = strpos($lineText, '"' . $title . '"');
        if ($quotedAt === false) {
            return null;
        }
        $column = $quotedAt + 1;

        $map = new SourceMap();
        $map->add(0, $lineStart + $column, strlen($title), $line + 1, $column + 1);

        return $map;
    }

    /**
     * @param list<array{int, int, int, string}> $contentLines resolved source line, column, length, text
     */
    private function foldedLinesMap(array $contentLines): ?SourceMap
    {
        if (!$this->trackPositions || $contentLines === []) {
            return null;
        }

        $map = new SourceMap();
        $textOffset = 0;
        $any = false;
        foreach ($contentLines as [$sourceLine, $column, $length, $lineText]) {
            $lineStart = $this->lineStartOffsets[$sourceLine] ?? null;
            // Nested content arrives already re-indented, so the column measured
            // against that copy is short by whatever was stripped. Locate the
            // text in the real source line instead.
            $sourceColumn = $lineText === ''
                ? $column
                : strpos($this->sourceLines[$sourceLine] ?? '', $lineText);
            if ($sourceColumn === false) {
                // The line is not a run of the source line it claims to come
                // from - deeply nested content that was re-indented more than
                // once. Falling back to the nested column produced spans that
                // pointed at unrelated bytes; skipping the segment means the
                // affected nodes get no position, which is the correct answer.
                $textOffset += $length + 1;

                continue;
            }
            if ($lineStart !== null && $length >= 0) {
                $map->add($textOffset, $lineStart + $sourceColumn, $length, $sourceLine + 1, $sourceColumn + 1);
                $any = true;
            }
            // +1 for the "\n" the join inserted between lines.
            $textOffset += $length + 1;
        }

        return $any ? $map->withSource($this->normalizedSource, $this->positionIndex) : null;
    }

    /**
     * Give a container the extent of the children it holds.
     *
     * A node that wraps others - a figure around an image and its caption, a
     * footnote around its body, an emphasis around its text - has no source of
     * its own to measure, but its extent is exactly the span of what it
     * contains. Deriving it is not inventing a position (PART 12 §4): every
     * number comes from a child that was placed by measurement.
     *
     * Runs bottom-up so a container of containers resolves too, and never
     * overwrites a span the parser already set, which is always more precise.
     */
    private function deriveContainerSpans(Node $node): ?SourceSpan
    {
        $first = null;
        $last = null;
        foreach ($node->getChildren() as $child) {
            $span = $this->deriveContainerSpans($child);
            if ($span === null) {
                continue;
            }
            if ($first === null || $span->startOffset < $first->startOffset) {
                $first = $span;
            }
            if ($last === null || $span->endOffset > $last->endOffset) {
                $last = $span;
            }
        }

        $own = $node->getPos();
        if ($own !== null) {
            // A node contains what it holds, so its extent is the UNION of its
            // own and its children's - not whichever was set first. A list item
            // measured from its marker line stops there, while the nested list
            // inside it runs on for several more; reporting only the first line
            // is a span that does not cover its own content.
            if ($first === null || $last === null) {
                return $own;
            }
            if ($own->startOffset <= $first->startOffset && $own->endOffset >= $last->endOffset) {
                return $own;
            }
            $first = $own->startOffset <= $first->startOffset ? $own : $first;
            $last = $own->endOffset >= $last->endOffset ? $own : $last;
        }

        if ($first === null || $last === null) {
            return null;
        }

        $derived = new SourceSpan(
            startLine: $first->startLine,
            endLine: $last->endLine,
            startColumn: $first->startColumn,
            endColumn: $last->endColumn,
            startOffset: $first->startOffset,
            endOffset: $last->endOffset,
        );
        $node->setPos($derived);

        return $derived;
    }

    /**
     * The span of the newline that ends a source line.
     *
     * A hard break in a line block has no text of its own: what it represents
     * is the line ending, which is one byte of real source.
     */
    private function endOfLineSpan(int $index): ?SourceSpan
    {
        if (!$this->trackPositions) {
            return null;
        }

        $sourceLine = $this->sourceLineFor($index);
        $start = $this->lineStartOffsets[$sourceLine] ?? null;
        if ($start === null) {
            return null;
        }

        $end = $start + strlen($this->sourceLines[$sourceLine] ?? '');

        return $this->positionIndex?->span($end, $end + 1, $sourceLine + 1, $sourceLine + 1, $start, $start);
    }

    /**
     * Extend a node's span so it reaches the end of a later one.
     *
     * A span is immutable, so this replaces it. Both ends have to exist: a node
     * with no span keeps none rather than gaining one that starts nowhere.
     */
    private function widenSpanTo(Node $node, ?SourceSpan $reach): void
    {
        $span = $node->getPos();
        if ($span === null || $reach === null || $reach->endOffset <= $span->endOffset) {
            return;
        }

        $node->setPos(new SourceSpan(
            startLine: $span->startLine,
            endLine: $reach->endLine,
            startColumn: $span->startColumn,
            endColumn: $reach->endColumn,
            startOffset: $span->startOffset,
            endOffset: $reach->endOffset,
        ));
    }

    private function wholeLineSpan(int $index): ?SourceSpan
    {
        if (!$this->trackPositions) {
            return null;
        }

        $sourceLine = $this->sourceLineFor($index);
        $start = $this->lineStartOffsets[$sourceLine] ?? null;
        if ($start === null) {
            return null;
        }

        $length = strlen($this->sourceLines[$sourceLine] ?? '');

        return $this->positionIndex?->span(
            $start,
            $start + $length,
            $sourceLine + 1,
            $sourceLine + 1,
            $start,
            $start,
        );
    }

    /**
     * @param int $index
     * @param array{content: string, attributes: string, offset?: int|null, verbatim?: bool} $cellData
     * @param string $content
     */

    /**
     * The span of a cell's own source, from what the split measured.
     *
     * Independent of whether the cell's TEXT can be verified: an escaped pipe
     * collapses two source bytes into one of content, so the text lookup fails
     * and declines, but the cell still occupied a known stretch of the line.
     *
     * @param int $index
     * @param array{content: string, attributes: string, offset?: int|null, verbatim?: bool, rawLength?: int|null} $cellData
     */

    /**
     * The span of a cell's trimmed CONTENT, located inside its raw source slice.
     *
     * Different from cellExtentSpan(), which covers the whole cell including the
     * padding. Handing that to a text node produced spans covering bytes the
     * node did not hold; this finds where the content actually sits.
     *
     * @param int $index
     * @param array{content: string, attributes: string, offset?: int|null, verbatim?: bool, rawLength?: int|null, raw?: string|null} $cellData
     * @param string $content
     */
    private function cellContentSpan(int $index, array $cellData, string $content): ?SourceSpan
    {
        if (!$this->trackPositions || $content === '') {
            return null;
        }

        $offset = $cellData['offset'] ?? null;
        $raw = $cellData['raw'] ?? null;
        if ($offset === null || $raw === null) {
            return null;
        }

        // Locate the content in the raw slice. When the text was rewritten (an
        // escape collapsed) it will not be found verbatim, and the node keeps no
        // position rather than one that covers different bytes.
        $within = strpos($raw, $content);
        if ($within === false) {
            return null;
        }

        $sourceLine = $this->sourceLineFor($index);
        $lineStart = $this->lineStartOffsets[$sourceLine] ?? null;
        if ($lineStart === null) {
            return null;
        }

        $start = $lineStart + $offset + $within;

        // A table nested in a list item reaches here already re-indented, so the
        // cell offset is short by whatever was stripped and the span would land
        // on the wrong bytes. Check, and fall back to locating the content in
        // the real source line before giving up.
        if (substr($this->normalizedSource, $start, strlen($content)) !== $content) {
            $inSourceLine = strpos($this->sourceLines[$sourceLine] ?? '', $content);
            if ($inSourceLine === false) {
                return null;
            }
            $start = $lineStart + $inSourceLine;
            if (substr($this->normalizedSource, $start, strlen($content)) !== $content) {
                return null;
            }
        }

        return $this->positionIndex?->span(
            $start,
            $start + strlen($content),
            $sourceLine + 1,
            $sourceLine + 1,
            $lineStart,
            $lineStart,
        );
    }

    /**
     * The span of a verse line's leading whitespace run, in the original source.
     *
     * `$from` and `$to` are byte offsets into the line as the line-block parser
     * sees it, which is the source line - a verse line is not re-indented.
     */
    private function verseIndentSpan(int $index, int $from, int $to): ?SourceSpan
    {
        if (!$this->trackPositions || $to <= $from) {
            return null;
        }

        $sourceLine = $this->sourceLineFor($index);
        $lineStart = $this->lineStartOffsets[$sourceLine] ?? null;
        if ($lineStart === null) {
            return null;
        }

        return $this->positionIndex?->span(
            $lineStart + $from,
            $lineStart + $to,
            $sourceLine + 1,
            $sourceLine + 1,
            $lineStart,
            $lineStart,
        );
    }

    /**
     * @param int $index
     * @param array{content: string, attributes: string, offset?: int|null, verbatim?: bool, rawLength?: int|null, raw?: string|null} $cellData
     */
    private function cellExtentSpan(int $index, array $cellData): ?SourceSpan
    {
        if (!$this->trackPositions) {
            return null;
        }

        $offset = $cellData['offset'] ?? null;
        $rawLength = $cellData['rawLength'] ?? null;
        if ($offset === null || $rawLength === null) {
            return null;
        }

        $sourceLine = $this->sourceLineFor($index);
        $lineStart = $this->lineStartOffsets[$sourceLine] ?? null;
        if ($lineStart === null) {
            return null;
        }

        return $this->positionIndex?->span(
            $lineStart + $offset,
            $lineStart + $offset + $rawLength,
            $sourceLine + 1,
            $sourceLine + 1,
            $lineStart,
            $lineStart,
        );
    }

    /**
     * @param int $index
     * @param array{content: string, attributes: string, offset?: int|null, verbatim?: bool, rawLength?: int|null} $cellData
     * @param string $content
     */
    private function cellSourceMap(int $index, array $cellData, string $content): ?SourceMap
    {
        if (!$this->trackPositions || $content === '' || ($cellData['verbatim'] ?? false) !== true) {
            return null;
        }

        $sourceLine = $this->sourceLineFor($index);
        $lineStart = $this->lineStartOffsets[$sourceLine] ?? null;
        $cellOffset = $cellData['offset'] ?? null;
        if ($lineStart === null || $cellOffset === null) {
            return null;
        }

        $within = strpos($cellData['content'], $content);
        if ($within === false) {
            return null;
        }

        $start = $lineStart + $cellOffset + $within;
        if (substr($this->normalizedSource, $start, strlen($content)) !== $content) {
            $sourceColumn = strpos($this->sourceLines[$sourceLine] ?? '', $content);
            if ($sourceColumn === false) {
                return null;
            }
            $start = $lineStart + $sourceColumn;
            if (substr($this->normalizedSource, $start, strlen($content)) !== $content) {
                return null;
            }
        }

        return SourceMap::contiguous($start, strlen($content), $sourceLine + 1, $start - $lineStart + 1)
            ->withSource($this->normalizedSource, $this->positionIndex);
    }

    /**
     * Source chunks for a table cell before continuation rows rebuild it.
     *
     * @param int $index
     * @param array{content: string, offset?: int|null} $cellData
     *
     * @return list<array{int, int, string}> source line, source column, text
     */
    private function tableCellSourceChunks(int $index, array $cellData): array
    {
        $content = trim($cellData['content']);
        if ($content === '') {
            return [];
        }

        $offset = $cellData['offset'] ?? null;
        if ($offset === null) {
            return [];
        }

        $within = strpos($cellData['content'], $content);
        if ($within === false) {
            return [];
        }

        return [[$this->sourceLineFor($index), $offset + $within, $content]];
    }

    /**
     * @return array<int, list<array{int, int, string}>>
     */
    private function continuationCellSourceChunks(int $index, string $line): array
    {
        $trimmed = ltrim($line);
        $prefix = strlen($line) - strlen($trimmed);
        $normalizedLine = '|' . substr($trimmed, 1);
        $chunks = [];

        foreach ($this->tableParser->splitCells($normalizedLine) as $idx => $cell) {
            $content = trim($cell['content']);
            if ($content === '') {
                continue;
            }
            $within = strpos($cell['content'], $content);
            if ($within === false) {
                continue;
            }
            $chunks[$idx] = [[$this->sourceLineFor($index), $prefix + $cell['offset'] + $within, $content]];
        }

        return $chunks;
    }

    /**
     * A map for a table cell rebuilt from a base row plus `+` continuation rows.
     *
     * The spaces between chunks are parser-consumed joins, not source bytes, so
     * they are deliberately left unmapped. Inline nodes that land on authored
     * chunks keep positions; an all-plain rebuilt text node falls back to the
     * measured extent from first chunk to last chunk.
     *
     * @param array{sourceChunks?: list<array{int, int, string}>} $cellData
     * @param string $content
     */
    private function rebuiltCellSourceMap(array $cellData, string $content): ?SourceMap
    {
        if (!$this->trackPositions || $content === '') {
            return null;
        }

        $chunks = $cellData['sourceChunks'] ?? [];
        if ($chunks === []) {
            return null;
        }

        $joined = implode(' ', array_map(static fn (array $chunk): string => $chunk[2], $chunks));
        if ($joined !== $content) {
            return null;
        }

        $map = new SourceMap();
        $textOffset = 0;
        $any = false;
        foreach ($chunks as [$sourceLine, $column, $text]) {
            $lineStart = $this->lineStartOffsets[$sourceLine] ?? null;
            if ($lineStart !== null) {
                $map->add($textOffset, $lineStart + $column, strlen($text), $sourceLine + 1, $column + 1);
                $any = true;
            }
            $textOffset += strlen($text) + 1;
        }

        return $any ? $map->withSource($this->normalizedSource, $this->positionIndex) : null;
    }

    /**
     * A cell rebuilt from `+` continuation rows is ONE text node with NO span,
     * which is what carve-js emits (carve-php#612).
     *
     * carve-php#608 gave it a span by splitting the cell into one node per
     * source chunk. Every one of those spans was correct, and the tree was
     * still wrong: PART 12 says a consumer written against one implementation
     * must be able to read another's output, and this engine alone emitted
     * three nodes where the reference emits one. Span-correctness testing
     * cannot see that - three healthy spans read exactly like one.
     *
     * The obvious repair, one node spanning first chunk to last, is also wrong
     * here: that region contains the `+ |` markers the value does not, and
     * this repo already requires a text span to slice back to its value
     * (SourcePositionTest::testNoCorpusDocumentGetsAWrongTextSpan). Relaxing
     * that rule to fit this case would trade a real invariant for two nodes'
     * worth of coverage.
     *
     * So the content is genuinely not a contiguous slice and the node is left
     * unplaced, exactly as in carve-js. Inline nodes that DO land on an
     * authored chunk still get positions through rebuiltCellSourceMap; only
     * the all-plain rebuilt text is unplaced.
     *
     * @param \MarkupCarve\Carve\Node\Block\TableCell $cell
     * @param array{sourceChunks?: list<array{int, int, string}>} $cellData
     * @param string $content
     */
    private function appendPlainRebuiltCellText(TableCell $cell, array $cellData, string $content): bool
    {
        return false;
    }

    private function contiguousMapFor(int $index, string $line, string $content): ?SourceMap
    {
        if (!$this->trackPositions || $content === '') {
            return null;
        }

        $sourceLine = $this->sourceLineFor($index);
        $lineStart = $this->lineStartOffsets[$sourceLine] ?? null;
        if ($lineStart === null) {
            return null;
        }

        // Search the ORIGINAL source line, not the one passed in. Nested
        // content reaches here already re-indented - a paragraph inside a list
        // item or blockquote has had its marker and indentation stripped - so a
        // column measured against that copy would be short by the amount
        // removed, and the span would select the wrong bytes.
        $column = strpos($this->sourceLines[$sourceLine] ?? $line, $content);
        if ($column === false) {
            // Rebuilt rather than sliced - joined continuations, stripped
            // indentation, an expanded tab.
            return null;
        }

        return SourceMap::contiguous($lineStart + $column, strlen($content), $sourceLine + 1, $column + 1)
            ->withSource($this->normalizedSource, $this->positionIndex);
    }

    /**
     * Paragraph interruption (grammar PART 9 §10). Visible blocks interrupt
     * open paragraphs without requiring a blank line. Captions and invisible
     * constructs (reference definitions and comments) also interrupt, since
     * they annotate/attach to prose with no rendered block of their own.
     * Sublist nesting via indentation is handled in the list-item collector.
     *
     * @param array<string> $lines
     * @param int $i
     * @param list<string> $contentLines Current paragraph content before the candidate line.
     * @param int $sourceLine
     * @param bool $hasUnclaimedColonFenceLine
     * @param bool $topLevel
     */
    protected function interruptsParagraph(
        array $lines,
        int $i,
        array $contentLines,
        int $sourceLine,
        bool $hasUnclaimedColonFenceLine,
        bool $topLevel = false,
    ): bool {
        $line = $lines[$i];

        if (preg_match('/^\^ +.*\S/', $line)) {
            return $this->isCaptionableParagraphContent(implode("\n", $contentLines), $sourceLine);
        }

        if ($this->isBareColonFence($line) && $hasUnclaimedColonFenceLine) {
            return false;
        }

        if ($this->startsNewBlock($line, $lines, $i)) {
            return true;
        }

        // A standalone block-attribute line floats forward to the next block
        // (or is dropped when none follows), so it interrupts the paragraph
        // rather than folding in as literal text (grammar PART 9 §15).
        // PART 12 §7: an abbreviation definition is invisible, and so
        // interrupts, only at document level. Inside a container the same shape
        // is paragraph text and folds in as a lazy continuation.
        return $this->isInvisibleOrAttributeLine($line, $topLevel);
    }

    /**
     * A line that renders no block of its own: a block-attribute line, a
     * reference / footnote / abbreviation definition, or a `%%` comment.
     *
     * Shared so the two places that need it cannot drift. A definition TERM was
     * folding every one of these in as continuation text - rendering their
     * SOURCE inside the `<dt>` - because its own break test knew only about
     * headings and quotes (carve-php#671).
     *
     * A `%%` line comment may be indented: leading whitespace before `%%` does
     * not matter, so an indented comment line counts exactly like a column-0
     * one (matches carve-js / carve-rs and the grammar
     * `comment_line = [whitespace], "%%", …`).
     *
     * @param string $line
     * @param bool $abbreviationCounts
     */

    /**
     * The subset of {@see isInvisibleOrAttributeLine} that FOLDS below a
     * content column: definitions and attribute lines, but never a comment.
     *
     * PART 9 §24 C3: a comment is recognized at ANY column and stays
     * invisible, because folding it would make it VISIBLE - the one outcome a
     * comment may never have. Every other invisible line folds as text there
     * (carve#618).
     */

    /**
     * Where a comment fence opened by $line ends, or null if it opens none.
     *
     * A `%%` line below an item's content column already stays a comment
     * (carve-php#746, PART 9 §24 C3): the collectors do not fold it, so it
     * reaches the block parser and renders nothing. The FENCE form did fold -
     * `isBlockElementStart()` claims it - and folding a comment is the one
     * outcome the construct may never have, so `%%% n` came out as item text
     * while `%% n` did not. The whole span has to move together: pushing only
     * the opener would leave the body behind as text (carve-php#770).
     *
     * Returns the index AFTER the closer. An UNCLOSED fence opens no block
     * (PART 9 §28), so it stays whatever the caller decides for it.
     *
     * @param string $line
     * @param array<string> $lines
     * @param int $index
     */
    protected function commentFenceSpanEnd(string $line, array $lines, int $index): ?int
    {
        $info = $this->fencedBlockParser->parseFencedCommentOpenerAnyColumn($line);
        if ($info === null || !$this->hasClosingCommentFenceAhead($line, $lines, $index)) {
            return null;
        }

        $count = count($lines);
        for ($j = $index + 1; $j < $count; $j++) {
            if ($this->fencedBlockParser->isFencedCommentCloserAnyColumn($lines[$j], $info['length'])) {
                return $j + 1;
            }
        }

        return null;
    }

    /**
     * Is this line a DEFINITION - the one invisible kind that belongs to the
     * enclosing item rather than this one when it sits at the frame's base?
     *
     * A comment is excluded because it is invisible at ANY column and closes
     * nothing (§24 C3); an attribute line is excluded because it is
     * column-strict and attaches to what follows it here.
     *
     * @param string $line
     */
    protected function isDefinitionLineForEnclosingItem(string $line): bool
    {
        return $this->isReferenceDefinitionLine($line)
            || preg_match('/^\\[\\^[^\\]]+\\]: +\\S/', $line) === 1;
    }

    /**
     * Whether a collected item line is a comment - either spelling, any column.
     *
     * A comment renders nothing but still ends the open paragraph, so the line
     * after it starts a new one rather than folding into the comment's entry or
     * past it (carve-php#800).
     *
     * @param string $line
     */
    protected function isCommentLineOrFence(string $line): bool
    {
        return preg_match('/^[ \t]*%%/', $line) === 1;
    }

    protected function isFoldableInvisibleLine(string $line): bool
    {
        if (preg_match('/^[ \t]*%%/', $line) === 1) {
            return false;
        }

        return $this->isInvisibleOrAttributeLine($line);
    }

    protected function isInvisibleOrAttributeLine(string $line, bool $abbreviationCounts = true): bool
    {
        if ($this->isBlockAttributeLine($line)) {
            return true;
        }

        return $this->isReferenceDefinitionLine($line)
            || ($abbreviationCounts && $this->isAbbreviationDefinitionLine($line))
            || preg_match('/^[ \t]*%%/', $line) === 1;
    }

    /**
     * Whether a line opens a link reference definition.
     *
     * This is the INTERRUPTION side of the rule, so it has to accept exactly
     * what the definition parser accepts. A line it accepts and the parser then
     * rejects ends the paragraph and reappears as a visible one - which is what
     * a citation key did (issue 619): `@` is excluded from a label so
     * `[@key]: …` stays with CitationsExtension, but the predicate here matched
     * it anyway.
     *
     * The destination must be non-empty (a bare `[r]:`, with nothing but spaces
     * after it, is literal text) and the separator after `]:` must start with a
     * literal space, both matching {@see self::tryParseReferenceDefinition()} and
     * {@see \MarkupCarve\Carve\Parser\ReferenceDefinitionExtractor}.
     */
    protected function isReferenceDefinitionLine(string $line): bool
    {
        // NOTE the `^` is NOT excluded here, unlike the two REGISTRATION sites.
        // This predicate answers "does this line render nothing", which a
        // footnote definition also does - it is what makes one interrupt a
        // paragraph and end a definition term. Excluding `[^…]:` here stopped
        // footnote definitions doing either.
        //
        // Precedence between the two definition kinds is decided where they are
        // REGISTERED, not here.
        return preg_match('/^\[(?!@)[^\]]+\]: [ \t]*\S/', $line) === 1;
    }

    protected function paragraphHasUnclaimedColonFenceLine(string $content): bool
    {
        foreach (explode("\n", $content) as $line) {
            if ($this->isUnclaimedColonFenceLine($line)) {
                return true;
            }
        }

        return false;
    }

    protected function isUnclaimedColonFenceLine(string $line): bool
    {
        $trimmed = ltrim($line);

        return preg_match('/^:{3,}/', $trimmed) === 1
            && $this->fencedBlockParser->parseDivFenceOpener($trimmed) === null;
    }

    protected function isCaptionableParagraphContent(string $content, int $sourceLine): bool
    {
        $paragraph = new Paragraph();
        $this->inlineParser->parse($paragraph, $content, $sourceLine);

        $children = $paragraph->getChildren();

        if (count($children) !== 1) {
            return false;
        }

        // An UNRESOLVED reference image is literal text, not an image, so it
        // is not captionable either - and the caption line then folds into the
        // paragraph rather than interrupting it, which is what carve-js and
        // carve-rs do (carve-php#751). Asking the same question here as the
        // promotion does keeps the two answers from disagreeing: a paragraph
        // the caption cannot attach to must not be split by it.
        if ($children[0] instanceof Image) {
            return UnresolvedReference::sourceOf($children[0]) === null;
        }

        return $children[0] instanceof Math && $children[0]->isDisplay();
    }

    /**
     * Whether a line is a standalone single-line block-attribute line: a
     * `{...}` block alone on the line that yields attributes (matching the
     * single-line case recognised by tryParseBlockAttributes). Braced inline
     * markers (`_ * = + - ~ ^`) and comment blocks (`%`) are excluded.
     */
    protected function isBlockAttributeLine(string $line): bool
    {
        $attrStr = $this->parseSingleLineBlockAttributePayload($line);
        if ($attrStr === null) {
            return false;
        }

        // The payload must be a FULLY valid attribute block (§14), not just
        // start with an attribute char: an invalid one like `{# id}` (a
        // space-broken id) is NOT a block-attribute line, so it continues the
        // paragraph as text rather than splitting it. Matches carve-js / carve-rs.
        return preg_match('/^[.#a-zA-Z]/', $attrStr) === 1
            && !str_starts_with($attrStr, '%')
            && $this->inlineParser->isValidAttrPayload($attrStr);
    }

    /**
     * Normalize one standalone single-line block-attribute line. Adjacent
     * `{...}` blocks merge as if their contents were separated by spaces.
     */
    protected function parseSingleLineBlockAttributePayload(string $line): ?string
    {
        $line = rtrim($line, " \t");
        $length = strlen($line);
        if ($length === 0 || $line[0] !== '{') {
            return null;
        }

        $parts = [];
        $pos = 0;
        while ($pos < $length) {
            if ($line[$pos] !== '{') {
                return null;
            }

            $end = $this->findSingleLineAttributeBlockEnd($line, $pos);
            if ($end === null) {
                return null;
            }

            $part = trim(substr($line, $pos + 1, $end - $pos - 1));
            // An EMPTY `{}` is not a block-attribute block, so a line holding
            // one is not a block-attribute line - wherever it sits. Joining the
            // payloads with a space made the empty one vanish silently, so
            // `{}{x}` produced the payload `x` and the line was consumed;
            // standalone that dropped the whole document, because there was no
            // block to attach to. carve-js and carve-rs keep the line literal
            // in every position (#638).
            if ($part === '') {
                return null;
            }
            $parts[] = $part;
            $pos = $end + 1;
        }

        return trim(implode(' ', $parts));
    }

    protected function findSingleLineAttributeBlockEnd(string $line, int $start): ?int
    {
        $length = strlen($line);
        $quote = null;
        for ($i = $start + 1; $i < $length; $i++) {
            $char = $line[$i];
            if ($char === "\n") {
                return null;
            }
            if ($char === '\\' && $i + 1 < $length) {
                $i++;

                continue;
            }
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;

                continue;
            }
            if ($char === '}') {
                return $i;
            }
        }

        return null;
    }

    /**
     * Try to parse a caption line (^ caption text).
     *
     * Captions apply to the immediately preceding block:
     * - Table → adds <caption> element
     * - Paragraph with single Image → wraps in <figure> with <figcaption>
     * - BlockQuote → wraps in <figure> with <figcaption>
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseCaption(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Caption syntax: `^ caption text` (caret followed by space)
        // Mirror tryParseHeading: `^` + one-or-more spaces (a space, not a tab) +
        // content with at least one non-space char. `^ ` alone (or `^\t…`) is
        // not a caption, exactly as `# ` / `#\t…` is not a heading.
        if (!preg_match('/^\^ +(.*\S.*)$/', $line, $matches)) {
            return null;
        }

        $captionLines = [$matches[1]];
        $i = $start + 1;
        $count = count($lines);

        // Caption can continue on non-blank lines that don't start a new block
        while ($i < $count) {
            $nextLine = $lines[$i];
            if (IndentationHelper::isBlankLine($nextLine)) {
                break;
            }
            // Stop at block-level elements
            if ($this->startsNewBlock($nextLine, $lines, $i)) {
                break;
            }
            // Stop at new table
            if (preg_match('/^\|/', $nextLine)) {
                break;
            }
            // A line that RENDERS NOTHING is not caption text: a link,
            // footnote or abbreviation definition, a comment, a block-attribute
            // line. Folding them in published `[A]: /u` as caption text, and a
            // footnote definition twice - once in the caption and once as an
            // endnote (carve-php#688).
            if ($this->isInvisibleOrAttributeLine($nextLine)) {
                break;
            }
            $captionLines[] = $nextLine;
            $i++;
        }

        $captionText = implode("\n", $captionLines);

        // Get the last child to attach the caption to
        $children = $parent->getChildren();
        if (!$children) {
            // No preceding block to attach caption to - treat as regular paragraph
            return null;
        }

        $lastChild = $children[count($children) - 1];

        $linesConsumed = $i - $start;

        // Handle Table - add caption directly to table
        if ($lastChild instanceof Table) {
            $caption = new Caption();
            $caption->setPos($this->wholeLineSpan($start));
            $this->inlineParser->parse(
                $caption,
                $captionText,
                $start,
                true,
                $this->contiguousMapFor($start, $lines[$start], $captionText),
            );
            $lastChild->setCaption($caption);
            // The caption is one of the table's children, and it is written
            // after the last row, so the table's span has to reach the end of
            // the caption line. Attaching it without widening left the
            // caption's inlines outside their own parent - which carve-js does
            // not do, and which nothing could see: a span is compared against
            // source text for text nodes alone (carve#565).
            $this->widenSpanTo($lastChild, $caption->getPos());

            return $linesConsumed;
        }

        // Handle CodeBlock - wrap in figure (numbered listing)
        if ($lastChild instanceof CodeBlock) {
            $figure = new Figure();

            // A preceding block-attribute line (e.g. `{#lst-x}`) sits on the
            // code block; move it onto the figure so the id drives the crossref.
            foreach ($lastChild->getAttributes() as $key => $value) {
                $figure->setAttribute($key, $value);
                $lastChild->removeAttribute($key);
            }

            $caption = new Caption();
            $caption->setPos($this->wholeLineSpan($start));
            $this->inlineParser->parse(
                $caption,
                $captionText,
                $start,
                true,
                $this->contiguousMapFor($start, $lines[$start], $captionText),
            );

            $figure->appendChild($lastChild);
            $figure->appendChild($caption);

            $parent->replaceChild(count($children) - 1, $figure);

            return $linesConsumed;
        }

        // Handle BlockQuote - wrap in figure
        if ($lastChild instanceof BlockQuote) {
            $figure = new Figure();

            // Transfer attributes from blockquote to figure
            foreach ($lastChild->getAttributes() as $key => $value) {
                $figure->setAttribute($key, $value);
                $lastChild->removeAttribute($key);
            }

            // Create caption
            $caption = new Caption();
            $caption->setPos($this->wholeLineSpan($start));
            $this->inlineParser->parse(
                $caption,
                $captionText,
                $start,
                true,
                $this->contiguousMapFor($start, $lines[$start], $captionText),
            );

            // Build figure: blockquote + caption
            $figure->appendChild($lastChild);
            $figure->appendChild($caption);

            // Replace blockquote with figure in parent
            $parent->replaceChild(count($children) - 1, $figure);

            return $linesConsumed;
        }

        // Handle Paragraph containing only an Image - wrap in figure
        if ($lastChild instanceof Paragraph) {
            $paragraphChildren = $lastChild->getChildren();
            if (
                count($paragraphChildren) === 1
                && $paragraphChildren[0] instanceof Image
                // An UNRESOLVED reference image is not an image: `[nope]`
                // resolves to nothing, so every writer emits the author's
                // source text and there is no rendered image for a caption to
                // attach to. Promoting it built a `<figure>` around literal
                // text, which carve-js and carve-rs both decline
                // (carve-php#751). PART 12 §3a keeps the node with `ref` and
                // `rawRef` precisely so it can be recognized here.
                && UnresolvedReference::sourceOf($paragraphChildren[0]) === null
            ) {
                $image = $paragraphChildren[0];

                $figure = new Figure();

                // A preceding block-attribute line (carried on the paragraph)
                // floats onto the figure. The image's OWN trailing attributes
                // stay on the <img> -- the same target as a standalone block
                // image -- so they are NOT transferred to the figure.
                foreach ($lastChild->getAttributes() as $key => $value) {
                    $figure->setAttribute($key, $value);
                }

                // Create caption
                $caption = new Caption();
                $this->inlineParser->parse(
                    $caption,
                    $captionText,
                    $start,
                    true,
                    $this->contiguousMapFor($start, $lines[$start], $captionText),
                );

                // Build figure: image + caption
                $figure->appendChild($image);
                $figure->appendChild($caption);

                // Replace paragraph with figure in parent
                $parent->replaceChild(count($children) - 1, $figure);

                return $linesConsumed;
            }

            // A paragraph that is nothing but a display-math span is a numbered
            // EQUATION: wrap the whole paragraph (keeping the <p> wrapper) in a
            // figure. Inline math, or display math with trailing prose, does not
            // qualify (more than one child, or not display).
            if (
                count($paragraphChildren) === 1
                && $paragraphChildren[0] instanceof Math
                && $paragraphChildren[0]->isDisplay()
            ) {
                $figure = new Figure();

                // A preceding block-attribute line (`{#eq-x}`) sits on the
                // paragraph; move it onto the figure so the id is on <figure>,
                // not the inner <p>, and drives the crossref.
                foreach ($lastChild->getAttributes() as $key => $value) {
                    $figure->setAttribute($key, $value);
                }
                foreach (array_keys($lastChild->getAttributes()) as $key) {
                    $lastChild->removeAttribute($key);
                }

                $caption = new Caption();
                $this->inlineParser->parse(
                    $caption,
                    $captionText,
                    $start,
                    true,
                    $this->contiguousMapFor($start, $lines[$start], $captionText),
                );

                $figure->appendChild($lastChild);
                $figure->appendChild($caption);

                $parent->replaceChild(count($children) - 1, $figure);

                return $linesConsumed;
            }
        }

        // No valid preceding block for caption - treat as regular paragraph
        return null;
    }

    protected function appendToLastParagraph(Node $parent, string $content, int $line): void
    {
        $children = $parent->getChildren();
        $lastChild = $children[count($children) - 1] ?? null;

        if ($lastChild instanceof Paragraph) {
            $this->inlineParser->parse($lastChild, ' ' . $content, $line);
        }
    }

    /**
     * Whether a line ENDS an open heading (and starts a sibling block). A list
     * marker (bullet, task, or ordered) ends a heading and starts a sibling
     * list: a heading is a bounded title, so a list marker folds into a
     * PARAGRAPH but never into a heading. Every paragraph-interrupter ends the
     * heading too. (Block quotes use endsBlockQuote(), which lets a list marker
     * fold into the open quoted paragraph instead.)
     *
     * @param string $line
     * @param array<string>|null $lines
     * @param int|null $index
     */
    protected function endsHeadingOrQuote(string $line, ?array $lines = null, ?int $index = null): bool
    {
        if ($this->listParser->parseListItemMarker(ltrim($line)) !== null) {
            return true;
        }

        return $this->startsNewBlock($line, $lines, $index);
    }

    /**
     * Whether a non-">" line ENDS an open block quote (and starts a sibling
     * block) during lazy continuation. A list marker (bullet OR ordered) ends
     * the quote UNLESS an open plain paragraph precedes it: when one does, the
     * marker folds into that paragraph as literal text (the top-level rule that
     * a list marker does not interrupt an open paragraph, applied inside the
     * quote). After a heading, table, fenced code, thematic break, `:::` div,
     * or a blank line there is no open paragraph to fold into, so a list marker
     * ENDS the quote and starts a sibling list -- mirroring the top level,
     * where `# h\n- item` is a heading plus a sibling list. Visible
     * block-openers, invisible constructs, and captions still end the quote via
     * startsNewBlock().
     *
     * @param string $line
     * @param bool $paragraphTextOpen Whether an open plain paragraph precedes this line.
     * @param array<string>|null $lines
     * @param int|null $index
     */
    protected function endsBlockQuote(
        string $line,
        bool $paragraphTextOpen,
        ?array $lines = null,
        ?int $index = null,
    ): bool {
        // A list marker ends the quote only when there is no open paragraph to
        // fold into; with an open paragraph it folds (does not end the quote).
        if (!$paragraphTextOpen && $this->listParser->parseListItemMarker(ltrim($line)) !== null) {
            return true;
        }

        // This line is a flush-left lazy candidate by construction, so it is at
        // DOCUMENT level - where an abbreviation definition is a definition
        // (PART 12 §7). A definition is invisible and interrupts, so it ends
        // the quote rather than folding into it. `startsNewBlock` cannot answer
        // this: it is also asked about lines inside containers, where the same
        // shape is ordinary paragraph text.
        if ($this->isAbbreviationDefinitionLine($line)) {
            return true;
        }

        return $this->startsNewBlock($line, $lines, $index);
    }

    /**
     * @param string $line
     * @param array<string>|null $lines
     * @param int|null $index
     */
    protected function startsNewBlock(string $line, ?array $lines = null, ?int $index = null): bool
    {
        // Quick check: empty lines don't start blocks
        if ($line === '' || !isset($line[0])) {
            return false;
        }

        // Caption `^ text` can always interrupt paragraphs (special case for figure captions)
        // Quick first-char check before regex
        if (preg_match('/^\^ +.*\S/', $line)) {
            return true;
        }

        // Fenced comments `%%%` can always interrupt paragraphs
        // Comments should be invisible and not require extra formatting
        if ($line[0] === '%' && isset($line[1], $line[2]) && $line[1] === '%' && $line[2] === '%') {
            return true;
        }

        return $this->startsInterruptingBlock($line, $lines, $index);
    }

    /**
     * Check if line starts a visible block that interrupts an open paragraph.
     *
     * @param string $line
     * @param array<string>|null $lines
     * @param int|null $index
     */
    protected function startsInterruptingBlock(string $line, ?array $lines = null, ?int $index = null): bool
    {
        // SYMMETRIC LIST INTERRUPTION: no list marker interrupts a paragraph --
        // a bullet (`-`/`*`) needs a blank line before it, exactly like an
        // ordered marker (`1.`/`a.`/`i.`) already does. This drops the former
        // "Rule B" (an indented bullet at ANY indentation interrupted a
        // paragraph), so there is no indented-bullet arm here and the column-0
        // `-`/`*` arm below no longer returns true for a bullet. Tight nested
        // lists are unaffected: sublist nesting runs through
        // isBlockElementStart(), not this paragraph-interruption predicate.

        // Use first-char switch to avoid unnecessary regex checks
        $first = $line[0];

        switch ($first) {
            case '#':
                // Headings: #{1,6}, a space, then non-empty content (a bare
                // `#` / `# ` is not a heading).
                return preg_match('/^#{1,6} .*\S/', $line) === 1;
            case '-':
            case '*':
                // A bullet does NOT interrupt a paragraph (symmetric with ordered
                // markers; needs a blank line). Only a thematic break -- a
                // contiguous col-0 run of at least three IDENTICAL markers, with
                // no internal whitespace (§262) -- interrupts here.
                return preg_match('/^' . preg_quote($first, '/') . '{3,}[ \t]*$/', $line) === 1;
            case '+':
                // `+` is the list-continuation marker, NOT a bullet (only the
                // opt-in PlusBulletExtension re-enables it) and is not a
                // thematic-break char. A bare `+ x` line is ordinary prose, so
                // it must not interrupt -- otherwise "+ one\n+ two" splits into
                // two stray paragraphs that are neither prose nor a list.
                return false;
            case '_':
                // Thematic break: contiguous col-0 run of >= 3 `_`, no internal
                // whitespace (§262).
                return preg_match('/^_{3,}[ \t]*$/', $line) === 1;
            case '|':
                // Tables: a single "| a | b |" row is a valid table, but a pipe
                // in prose ("a\n| b als Oder.") is not a row, so validate before
                // interrupting to avoid splitting prose into stray paragraphs.
                return $this->tableParser->isTableRow($line);
            case '>':
                // Block quotes
                return $this->blockQuoteLineContent($line) !== null;
            case '`':
            case '~':
                // Code fences interrupt only if a matching closer exists ahead.
                return $this->hasClosingFenceAhead($line, $lines, $index);
            case ':':
                // Definition list term (`:: term`, not `:::` div) is a
                // first-class block opener (§24 C3), so it interrupts an open
                // paragraph exactly like a heading or quote.
                if (preg_match(self::DEFINITION_TERM_LINE_PATTERN, $line) === 1) {
                    return true;
                }

                return $this->fencedBlockParser->parseDivFenceOpener($line) !== null;
            case '%':
                // Fenced comments interrupt only if a matching closer exists ahead.
                return $this->hasClosingCommentFenceAhead($line, $lines, $index);
            default:
                // An ordered-list marker does NOT interrupt a paragraph: it
                // needs a blank line (matching Djot). Allowing it would require
                // the CommonMark `1.`-only heuristic to keep `2.`, `1985.` etc.
                // as prose, which Carve avoids. A bare image is inline too.
                return false;
        }
    }

    /**
     * @param string $line
     * @param array<string>|null $lines
     * @param int|null $index
     */
    protected function hasClosingFenceAhead(string $line, ?array $lines, ?int $index): bool
    {
        if (preg_match('/^([`~])\1{2,}/', $line, $matches) !== 1) {
            return false;
        }

        if ($lines === null || $index === null) {
            return true;
        }

        $char = $matches[1];
        $length = strspn($line, $char);
        $count = count($lines);

        // Reuse the collector's closer matcher so the interruption lookahead can
        // never accept a closer the fence collector would reject (no drift).
        for ($i = $index + 1; $i < $count; $i++) {
            if ($this->fencedBlockParser->isCodeFenceCloser($lines[$i], $char, $length)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $line
     * @param array<string>|null $lines
     * @param int|null $index
     */
    protected function hasClosingCommentFenceAhead(string $line, ?array $lines, ?int $index): bool
    {
        $fenceInfo = $this->fencedBlockParser->parseFencedCommentOpenerAnyColumn($line);
        if ($fenceInfo === null) {
            return false;
        }

        if ($lines === null || $index === null) {
            return true;
        }

        return $this->lastCommentFenceIndex($lines, $fenceInfo['length']) > $index;
    }

    /**
     * Last index in $lines carrying a comment fence of exactly $length, or -1.
     *
     * Built once per line set: see the $commentFenceLastIndex docblock for why
     * this is exact rather than an approximation.
     *
     * @param array<string> $lines
     * @param int $length
     */
    protected function lastCommentFenceIndex(array $lines, int $length): int
    {
        if ($this->commentFenceLastIndex === null) {
            $index = [];
            foreach ($lines as $i => $candidate) {
                // Any column: the consumption sites read an indented fence, so
                // the index answering whether a closer exists must see one too.
                $info = $this->fencedBlockParser->parseFencedCommentOpenerAnyColumn($candidate);
                if ($info !== null) {
                    $index[$info['length']] = $i;
                }
            }

            $this->commentFenceLastIndex = $index;
        }

        return $this->commentFenceLastIndex[$length] ?? -1;
    }

    /**
     * @param array<string> $lines
     * @param int $index
     * @param int $length
     */
    protected function hasClosingCommentFenceAheadInBlockQuote(array $lines, int $index, int $length): bool
    {
        $count = count($lines);
        for ($i = $index + 1; $i < $count; $i++) {
            if (IndentationHelper::isBlankLine($lines[$i])) {
                return false;
            }

            $content = $this->blockQuoteLineContent($lines[$i]);
            if ($content === null) {
                $content = $lines[$i];
            }

            if ($this->fencedBlockParser->isFencedCommentCloser($content, $length)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a line is a quote marker with nothing after it, at any depth.
     *
     * `> > q` holds a paragraph; `> >` holds none, and PART 1 S4 then gives a
     * following column-0 line nothing to fold into.
     */
    protected function isEmptyQuoteLine(string $line): bool
    {
        $rest = trim($line);
        $sawQuote = false;
        while ($rest === '>' || str_starts_with($rest, '> ')) {
            $sawQuote = true;
            $rest = $rest === '>' ? '' : ltrim(substr($rest, 2));
        }

        return $sawQuote && $rest === '';
    }

    /**
     * Advance the trailing-block tracker by one collected item content line.
     *
     * Tracks the kind of the item's most recent top-level block so the
     * lazy-continuation gate can answer, in O(1) per line, whether a dedented
     * plain-text line may lazily continue an OPEN paragraph (CommonMark lazy
     * continuation).
     *
     * `openParagraph` is true for a trailing paragraph -- including the open
     * paragraph at the end of a blockquote, div, or heading text -- so a
     * dedented line folds into it. It is false for a trailing fenced code block
     * or table (no open paragraph); a dedented line after one of those ends the
     * item and becomes a top-level block instead of being absorbed.
     *
     * The lines are already stripped to content-relative indentation, so a
     * fence or a table row sits at column 0 here. State is carried across
     * lines (`inFence` + fence char/length) so a multi-line fenced block keeps
     * `openParagraph` false until its closer is seen and the trailing block
     * changes. The tracker is intentionally narrow: it reports "no open
     * paragraph" only for a trailing fenced code block or table, leaving every
     * other shape to the existing lazy-continuation behavior.
     *
     * @param array{openParagraph: bool, inFence: bool, fenceChar: string, fenceLength: int, inDiv: bool, divFenceLength: int} $state
     * @param string $line Collected line, stripped to content-relative indentation.
     *
     * @return array{openParagraph: bool, inFence: bool, fenceChar: string, fenceLength: int, inDiv: bool, divFenceLength: int}
     */
    protected function advanceTrailingBlockState(array $state, string $line): array
    {
        if ($state['inFence']) {
            // Inside a fenced code block: stay code (no open paragraph) until
            // the matching closer is seen. The closer itself is still part of
            // the code block, so the trailing block remains code.
            if ($this->fencedBlockParser->isCodeFenceCloser($line, $state['fenceChar'], $state['fenceLength'])) {
                $state['inFence'] = false;
            }
            $state['openParagraph'] = false;

            return $state;
        }

        if ($state['inDiv']) {
            // Inside a `:::` div / admonition: a complete (closed) div has no
            // open paragraph, so the trailing block stays non-paragraph through
            // the body and the closing fence. An UNTERMINATED div (closer never
            // seen) is handled at the gate via inDiv, which keeps it foldable
            // (it is paragraph text under the §10 closer-lookahead rule).
            if ($this->fencedBlockParser->isDivFenceCloser($line, $state['divFenceLength'])) {
                $state['inDiv'] = false;
            }
            $state['openParagraph'] = false;

            return $state;
        }

        if (IndentationHelper::isBlankLine($line)) {
            // A blank line closes the current block. Until a fresh block opens,
            // a dedented line is a new top-level block, not a continuation.
            $state['openParagraph'] = false;

            return $state;
        }

        $opener = $this->fencedBlockParser->parseCodeFenceOpener($line);
        if ($opener !== null) {
            /** @var string $fenceChar */
            $fenceChar = $opener['char'];
            /** @var int $fenceLength */
            $fenceLength = $opener['length'];
            $state['inFence'] = true;
            $state['fenceChar'] = $fenceChar;
            $state['fenceLength'] = $fenceLength;
            $state['openParagraph'] = false;

            return $state;
        }

        $divOpener = $this->fencedBlockParser->parseDivFenceOpener($line);
        if ($divOpener !== null) {
            /** @var int $divFenceLength */
            $divFenceLength = $divOpener['length'];
            $state['inDiv'] = true;
            $state['divFenceLength'] = $divFenceLength;
            $state['openParagraph'] = false;

            return $state;
        }

        if ($this->tableParser->isTableRow($line)) {
            // A table has no open paragraph for a dedented line to continue.
            $state['openParagraph'] = false;

            return $state;
        }

        // A quote marker with NOTHING after it opens a quote holding no
        // paragraph, so a column-0 line has nothing to fold into and the item
        // closes (PART 1 S4: NO OPEN PARAGRAPH, NO LAZY LINE). `> q` does hold
        // one and still folds - one rule, opposite answers (carve#572).
        if ($this->isEmptyQuoteLine($line)) {
            $state['openParagraph'] = false;

            return $state;
        }

        // Any other non-blank line belongs to a paragraph-bearing block (plain
        // paragraph, blockquote, heading text). Treat the trailing block
        // as having an open paragraph and let the existing lazy-continuation
        // behavior fold the dedented line in.
        $state['openParagraph'] = true;

        return $state;
    }

    /**
     * Compact-list looseness scan over an item's collected (content-column
     * dedented) sub-content lines. Mirrors carve-js: for each internal blank
     * line, look at the next non-blank line. Content at or past the sub-list's
     * content column belongs to that sub-list (its looseness is decided by its
     * own recursive parse) and does not loosen THIS item; every other block
     * opener after the blank keeps the item tight too, but a plain paragraph
     * after the blank loosens it (carve#322).
     *
     * @param array<string> $subLines The item's dedented sub-content lines.
     */
    protected function subContentHasLooseningBlank(array $subLines): bool
    {
        // The first collected sub-list marker fixes the sub-list content column;
        // content at or past it belongs to the sub-list, not this item.
        $firstBlockIdx = -1;
        foreach ($subLines as $idx => $sl) {
            if ($sl === '') {
                continue;
            }
            if ($this->listParser->parseListItemMarker(ltrim($sl)) !== null) {
                $firstBlockIdx = $idx;

                break;
            }
        }
        $subCol = $firstBlockIdx === -1 ? -1 : $this->markerContentColumn($subLines[$firstBlockIdx]);

        $n = count($subLines);
        // Track fenced-code regions: a blank line INSIDE an open fence is
        // verbatim content, not an interior block separator, so it must not
        // loosen the item (carve#326 case C; matches carve-rs / carve-js).
        $fenceChar = null;
        $fenceLength = 0;
        for ($k = 0; $k < $n; $k++) {
            $sl = $subLines[$k];
            if ($fenceChar !== null) {
                if ($this->fencedBlockParser->isCodeFenceCloser($sl, $fenceChar, $fenceLength)) {
                    $fenceChar = null;
                }

                continue;
            }
            $opener = $this->fencedBlockParser->parseCodeFenceOpener($sl);
            if ($opener !== null) {
                /** @var string $fenceChar */
                $fenceChar = $opener['char'];
                /** @var int $fenceLength */
                $fenceLength = $opener['length'];

                continue;
            }
            if ($sl !== '') {
                continue;
            }
            $j = $k + 1;
            while ($j < $n && $subLines[$j] === '') {
                $j++;
            }
            if ($j >= $n) {
                continue;
            }
            if ($subCol >= 0 && IndentationHelper::getLeadingColumns($subLines[$j]) >= $subCol) {
                // Belongs to the sub-list; its looseness is its own business.
                continue;
            }
            if (!$this->lineOpensBlockForLooseness($subLines[$j])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The marker width of a list item, i.e. the column its content starts at
     * relative to the marker's own indent.
     *
     * ORDERED and BULLET markers are MEASURED, so `- item` puts its content
     * at 4 and `10. item` at 4. TASK items are pinned at 2: the checkbox is
     * content, not marker, and extra spaces before it do not move the column
     * either -- `- [ ] item` still has its content column at 2. That is not
     * an accident of this parser, it is what carve-js and carve-rs both do, and
     * the three engines are pinned together by the corpus.
     *
     * One helper rather than a copy per call site: the width is consulted by
     * the list parser, by the implicit-heading pre-scan and by the looseness
     * scan, and when those disagreed the pre-scan indexed a heading the
     * renderer never emitted (carve-php#580).
     *
     * @param string $stripped The marker line with its leading indent removed.
     * @param array{type: string, content: string} $info Parsed marker info.
     */
    protected function listMarkerWidth(string $stripped, array $info): int
    {
        if ($info['type'] === ListBlock::TYPE_TASK) {
            return 2;
        }

        return strlen($stripped) - strlen($info['content']);
    }

    /**
     * The content column of a list-marker line, mirroring THIS parser's own
     * content-column model (see the `$markerWidth` computation in tryParseList),
     * so the looseness scan's "belongs to the sub-list" test agrees with where
     * the recursive parse actually places the content. Returns -1 when the line
     * is not a list marker. See listMarkerWidth for the width rule.
     */
    protected function markerContentColumn(string $line): int
    {
        $stripped = ltrim($line, " \t");
        $info = $this->listParser->parseListItemMarker($stripped);
        if ($info === null) {
            return -1;
        }
        $base = IndentationHelper::getLeadingColumns($line);

        return $base + $this->listMarkerWidth($stripped, $info);
    }

    /**
     * Does this line OPEN a block (vs plain prose)? Used by the compact-list
     * looseness scan: a blank inside a list item loosens only when the content
     * after it is a plain paragraph; a blank followed by a block opener keeps
     * the item tight. Mirrors carve-js lineOpensBlock -- list markers count at
     * ANY indent, every other opener only at column 0. Lexer-free: a
     * colon-fence-shaped opener counts regardless of closer lookahead.
     */

    /**
     * The first line after `$index` that renders something, skipping blanks and
     * invisible lines, stripped to the item's content column - or null when the
     * item ends first.
     *
     * §17 L1b asks what sits BEHIND an invisible line, because an invisible
     * line neither is the second paragraph nor separates one from the blank
     * before it.
     *
     * @param array<string> $lines
     * @param int $contentIndent
     * @param int $index
     *
     * @return string|null
     */
    protected function firstVisibleLineAfterInvisible(array $lines, int $index, int $contentIndent): ?string
    {
        $count = count($lines);
        for ($j = $index + 1; $j < $count; $j++) {
            $line = $lines[$j];
            if (IndentationHelper::isBlankLine($line)) {
                continue;
            }

            // Dedented out of the item: nothing of the item follows.
            if (IndentationHelper::getLeadingColumns($line) < $contentIndent) {
                return null;
            }

            $stripped = IndentationHelper::stripLeadingColumns($line, $contentIndent);
            if ($this->isInvisibleOrAttributeLine($stripped)) {
                continue;
            }

            return $stripped;
        }

        return null;
    }

    protected function lineOpensBlockForLooseness(string $line): bool
    {
        if ($this->listParser->parseListItemMarker(ltrim($line)) !== null) {
            return true;
        }

        // A line that RENDERS NOTHING is not a second paragraph either. §17 L1
        // loosens an item that holds a blank-line-separated second PARAGRAPH,
        // and a comment or a definition produces no output at all - so an item
        // came back wrapped in `<p>` because of a line the reader never sees,
        // which is the blank line showing through (carve-php#744). The blank
        // before a following SIBLING marker is a different clause and still
        // loosens; that one is decided by the caller, not here.
        if ($this->isInvisibleOrAttributeLine($line)) {
            return true;
        }

        return $this->isBlockElementStart($line);
    }

    /**
     * Does this collected content produce no output at all?
     *
     * Only comments, definitions and attribute lines qualify - the constructs
     * §15 A2a calls invisible. Blank lines do not disqualify it; a stream of
     * nothing but invisible lines is still nothing.
     *
     * @param array<string> $lines
     */
    protected function contentRendersNothing(array $lines): bool
    {
        $sawLine = false;
        foreach ($lines as $line) {
            if (IndentationHelper::isBlankLine($line)) {
                continue;
            }
            if (!$this->isInvisibleOrAttributeLine($line)) {
                return false;
            }
            $sawLine = true;
        }

        return $sawLine;
    }

    /**
     * Does this line open a TABLE, by the same rule the table parser uses?
     *
     * A complete row (`| a |`) always does. A row that opens a code span is
     * only a row once a `+` continuation closes the span, so that shape needs
     * the surrounding lines to answer; without them the incomplete row is
     * treated as a row, which is what the block-boundary callers that have no
     * line context assumed before this predicate existed.
     *
     * @param string $line
     * @param array<string>|null $lines
     * @param int|null $index
     */
    protected function isTableBlockStart(string $line, ?array $lines = null, ?int $index = null): bool
    {
        if ($this->tableParser->isTableRow($line)) {
            return true;
        }

        if (!$this->tableParser->isPotentialTableRowWithUnclosedCodeSpan($line)) {
            return false;
        }

        if ($lines === null || $index === null) {
            return true;
        }

        return $this->canCloseCodeSpanWithContinuations($lines, $index, count($lines));
    }

    /**
     * Check if line starts a block element that should terminate list content collection.
     *
     * This is different from startsNewBlock() which is about paragraph interruption.
     * Block elements at column 0 (or less than list indent) should always break out
     * of list content collection.
     *
     * @param string $line The trimmed line to check
     * @param array<string>|null $lines
     * @param int|null $index
     */
    protected function isBlockElementStart(string $line, ?array $lines = null, ?int $index = null): bool
    {
        // Headings: #{1,6}, a space, then non-empty content (a bare `#` / `# `
        // is not a heading).
        if (preg_match('/^#{1,6} .*\S/', $line)) {
            return true;
        }

        // Code fences (``` or ~~~). Only a fence with a closer ahead opens a
        // block; an unterminated one stays paragraph text (PART 9 §10 I4). The
        // same rule lives in startsNewBlock(), and for a long time only that
        // copy had it - so the rule held at the top level and failed inside
        // every container that reaches the decision through here (carve-php#642).
        if (preg_match('/^[`~]{3,}/', $line)) {
            return $this->hasClosingFenceAhead($line, $lines, $index);
        }

        // Fenced divs / admonitions (::: but not glued typed text like :::note)
        if ($this->fencedBlockParser->parseDivFenceOpener($line) !== null) {
            return true;
        }

        // Comment fences (%%%)
        if ($this->fencedBlockParser->parseFencedCommentOpener($line) !== null) {
            return true;
        }

        // Thematic breaks (---, ***, ___): a contiguous col-0 run of >= 3
        // IDENTICAL markers, no internal whitespace (§262). Spaced Markdown
        // forms (`* * *`) are matched by the list arm below, not here.
        if (preg_match('/^([-*_])\1{2,}[ \t]*$/', $line)) {
            return true;
        }

        // Block quotes
        if ($this->blockQuoteLineContent($line) !== null) {
            return true;
        }

        // Tables. The row is VALIDATED, not just recognized by its first byte:
        // a pipe in prose (`|`, `|x`) opens no table, and treating it as one
        // made the block boundary depend on the character rather than on what
        // the line is. A column-0 `|` after a list item detached from the item
        // while `*`, `-` and `x` attached, purely because those three reach the
        // same decision through the paragraph-interruption predicate, which has
        // always validated the row (carve-php#683). carve-js validates in both
        // places (`isTableRow` in `lineOpensBlock`).
        //
        // The test has to accept exactly what {@see self::tryParseTable()}
        // accepts, INCLUDING its continuation path: a row that opens a code
        // span (`| ``a |`) is not a complete row on its own, but a following
        // `+` row closes the span and a table is what gets parsed. Answering
        // "no block here" for that shape folded the table into the preceding
        // list item instead of letting it break out.
        if ($this->isTableBlockStart($line, $lines, $index)) {
            return true;
        }

        // Definition list: only a term (`:: term`, not `:::` div) opens the
        // list and thus counts as a block start (a def-list nests at an item's
        // content column, §24 C3). A bare description line (`:  def`) is NOT an
        // independent block start -- it only continues an already-open def-list,
        // so it must never split off to document level when it follows a term
        // at a mismatched indent (carve#295: match carve-js, which keeps the
        // whole <dl> together rather than stranding the definition as a <p>).
        if (preg_match(self::DEFINITION_TERM_LINE_PATTERN, $line)) {
            return true;
        }

        // List markers - these indicate a new list at this level. A marker is a
        // list only with non-empty content (a content-less `- ` is paragraph
        // text, not a block start), matching ListParser::parseListItemMarker.
        // Bullet lists: - or * followed by space + content (`+` is not a bullet
        // in Carve -- it is the list-continuation marker).
        if (preg_match('/^[-*] +\S/', $line)) {
            return true;
        }

        // Ordered lists: digit(s) or letter plus delimiter, or the bare-dot
        // shorthand, followed by space + content.
        if (preg_match('/^(?:\.|(\d+|[a-zA-Z])[.)]) +\S/', $line)) {
            return true;
        }

        // Task lists: - [ ] or - [x]
        if (preg_match('/^- \[[xX ]\] /', $line)) {
            return true;
        }

        return false;
    }

    /**
     * Check if text has an unclosed brace (for attribute blocks)
     */
    protected function hasUnclosedBrace(string $text): bool
    {
        return $this->scanBraceState($text, self::INITIAL_BRACE_STATE)['depth'] > 0;
    }

    /**
     * Scan a text segment for brace nesting, carrying state across segments.
     *
     * Used to detect an unclosed attribute brace in a paragraph (`text{a=x`)
     * without re-scanning the whole accumulated content on every continuation
     * line. Quote state, brace depth and a dangling backslash (an escape that
     * straddles the segment boundary) are threaded through so scanning a string
     * in one call or split across calls yields the identical result.
     *
     * @param string $segment
     * @param array{depth: int, inQuote: bool, quoteChar: string, pendingEscape: bool} $state
     *
     * @return array{depth: int, inQuote: bool, quoteChar: string, pendingEscape: bool}
     */
    protected function scanBraceState(string $segment, array $state): array
    {
        $depth = $state['depth'];
        $inQuote = $state['inQuote'];
        $quoteChar = $state['quoteChar'];
        $len = strlen($segment);
        $i = 0;

        // A backslash at the end of the previous segment escapes this segment's
        // first character.
        if ($state['pendingEscape'] && $len > 0) {
            $i = 1;
        }
        $pendingEscape = false;

        for (; $i < $len; $i++) {
            $char = $segment[$i];

            // Handle escape sequences
            if ($char === '\\') {
                if ($i + 1 < $len) {
                    $i++;

                    continue;
                }

                // Trailing backslash escapes the next segment's first character.
                $pendingEscape = true;

                break;
            }

            // Handle quotes
            if (!$inQuote && ($char === '"' || $char === "'")) {
                $inQuote = true;
                $quoteChar = $char;

                continue;
            }

            if ($inQuote && $char === $quoteChar) {
                $inQuote = false;

                continue;
            }

            // Count braces only outside quotes
            if (!$inQuote) {
                if ($char === '{') {
                    $depth++;
                } elseif ($char === '}') {
                    $depth--;
                }
            }
        }

        return ['depth' => $depth, 'inQuote' => $inQuote, 'quoteChar' => $quoteChar, 'pendingEscape' => $pendingEscape];
    }

    /**
     * @return array<string>
     */
    protected function splitLines(string $input): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $input);
        $lines = explode("\n", $normalized);

        // Record where each line starts in the NORMALIZED source, while the
        // information still exists. After this point the block layer strips
        // indentation and re-joins lines, and a byte offset into what it builds
        // no longer relates to the document (PART 12 §4).
        $this->lineStartOffsets = [];
        $this->sourceLines = $lines;
        $this->normalizedSource = $normalized;
        $this->positionIndex = $this->trackPositions ? new PositionIndex($normalized) : null;
        $offset = 0;
        foreach ($lines as $index => $line) {
            $this->lineStartOffsets[$index] = $offset;
            $offset += strlen($line) + 1;
        }

        return $lines;
    }

    /**
     * Validate reference definitions vs usage
     * Generates warnings for unused references.
     * Note: Undefined references are warned about inline during parsing.
     */
    protected function validateReferences(): void
    {
        // Check for unused reference definitions (defined but never used)
        // Skip heading auto-references (URLs start with #)
        // Skip footnote definitions (labels start with ^)
        foreach ($this->references as $label => $def) {
            if (
                !isset($this->usedReferences[$label])
                && !str_starts_with($def->url, '#')
                && !str_starts_with($label, '^')
            ) {
                $this->addWarning(
                    "Reference '{$label}' defined but never used",
                    $def->line,
                    1,
                    false,
                    'reference',
                    null,
                );
            }
        }
    }

    /**
     * A tracker configured like the ones the parse passes use, so the ids the
     * reference index points at are the ids the renderer will emit.
     */
    protected function headingIdTrackerForReferences(): HeadingIdTracker
    {
        $tracker = new HeadingIdTracker();
        $tracker->setIdTransformer($this->headingIdTransformer);
        $tracker->setLowercase($this->headingIdLowercase);

        return $tracker;
    }

    /**
     * Re-run the parse with the tree-derived heading index seeded.
     *
     * Resets exactly the state `parse()` resets, so the second pass starts
     * from the same place the first did and cannot double-count warnings,
     * footnotes or used-reference bookkeeping. The seed is applied AFTER the
     * extract passes, so a real link definition still wins the tie (R1).
     *
     * @param array<string> $lines
     * @param array<string, array{0: string, 1: \MarkupCarve\Carve\Parser\ReferenceDefinition}> $headingReferences
     * @param int $sourceLength
     */
    protected function reparseWithHeadingReferences(
        array $lines,
        array $headingReferences,
        int $sourceLength,
    ): Document {
        $this->references = [];
        $this->headingReferencesByFoldedLabel = [];
        $this->footnotes = [];
        $this->abbreviations = [];
        $this->abbreviationDefinitions = [];
        $this->abbreviationsBeforeBody = false;
        $this->pendingAttributes = [];
        $this->pendingAttributeOrder = [];
        $this->warnings = [];
        $this->usedReferences = [];
        $this->anchorLinks = [];
        $this->headingIds = [];
        $this->lineOffset = 0;
        $this->sawUnresolvedCollapsedReference = false;

        $document = new Document();
        $this->extractReferences($lines);
        $this->extractFootnotes($lines);
        $this->extractAbbreviations($lines);
        $this->extractHeadingReferences($lines);
        $this->seedHeadingReferences($headingReferences);
        $this->parseBlocks($document, $lines, 0, topLevel: true);
        $document->setSourceLength($sourceLength);

        return $document;
    }

    public function getReference(string $label): ?ReferenceDefinition
    {
        return $this->references[$label] ?? null;
    }

    public function getCollapsedReference(string $label): ?ReferenceDefinition
    {
        return $this->references[$label] ?? $this->headingReferencesByFoldedLabel[$this->foldReferenceLabel($label)] ?? null;
    }

    /**
     * The line pre-scan no longer feeds the implicit-reference index.
     *
     * It matched `^#{1,6}` at column 0, so which headings it found came down
     * to source indentation: a div's inner lines start at column 0 and were
     * indexed, a list item's are indented and were not, and a blockquote's
     * carry `>` and were not. Two of those three answers were right and all
     * three were accidents - this engine had never implemented R1's blockquote
     * rule, it just never saw past the prefix (#572).
     *
     * The index is now built from the parsed tree by
     * HeadingReferenceCollector, which asks the question R1 actually asks:
     * does this heading have a blockquote ANCESTOR.
     */
    protected function registerHeadingReference(string $label, ReferenceDefinition $reference): void
    {
        if (!isset($this->references[$label])) {
            $this->references[$label] = $reference;
        }

        $this->headingReferencesByFoldedLabel[$this->foldReferenceLabel($label)] ??= $reference;
    }

    /**
     * The heading-index key: NFC-normalized, then case-folded (PART 9R R1).
     * The second copy of HeadingReferenceCollector::foldLabel() - both fold, so
     * both normalize, or a reference resolves on one path and not the other.
     */
    protected function foldReferenceLabel(string $label): string
    {
        return (string)preg_replace_callback(
            '/./us',
            static fn (array $m): string => mb_strtolower($m[0], 'UTF-8'),
            StringUtil::normalizeNfc($label),
        );
    }

    /**
     * Mark a reference as used (for validation warnings)
     * Only tracks when collectWarnings is enabled.
     */
    public function markReferenceUsed(string $label, int $line): void
    {
        if ($this->collectWarnings && !isset($this->usedReferences[$label])) {
            $this->usedReferences[$label] = $line;
        }
    }

    public function hasFootnote(string $label): bool
    {
        return isset($this->footnotes[$label]);
    }

    /**
     * Get all abbreviation definitions
     *
     * @return array<string, string> Map of abbreviation text to definition
     */
    public function getAbbreviations(): array
    {
        return $this->abbreviations;
    }

    /**
     * Get the definition for a specific abbreviation
     */
    public function getAbbreviation(string $abbr): ?string
    {
        return $this->abbreviations[$abbr] ?? null;
    }

    /**
     * Add warning for undefined reference (called from InlineParser)
     */

    /**
     * Record that a collapsed `[text][]` reference found no definition.
     *
     * Called from the inline parser wherever a reference found no definition.
     * The second pass only runs when this fired, so a document whose
     * references all resolved parses exactly once.
     */
    public function markCollapsedReferenceUnresolved(): void
    {
        $this->sawUnresolvedCollapsedReference = true;
    }

    /**
     * Heading references collected from the PARSED TREE, keyed by folded
     * heading text (PART 11 R1).
     *
     * Seeds BOTH lookups. A heading is reachable by the collapsed `[text][]`
     * form (folded, case-insensitive) and by the exact `[text][Label]` form,
     * matching carve-js; a real link definition still wins either way,
     * because the extract passes run first and these use `??=`.
     *
     * @param array<string, array{0: string, 1: \MarkupCarve\Carve\Parser\ReferenceDefinition}> $references
     */
    public function seedHeadingReferences(array $references): void
    {
        foreach ($references as $folded => [$label, $reference]) {
            $this->headingReferencesByFoldedLabel[$folded] ??= $reference;
            $this->references[$label] ??= $reference;
        }
    }

    public function addUndefinedReferenceWarning(string $ref, int $line, int $column): void
    {
        $this->addWarning(
            "Undefined reference '{$ref}'",
            $line,
            $column,
            false,
            'reference',
            "Define with [{$ref}]: url or use inline link",
        );
    }

    /**
     * Add warning for undefined footnote (called from InlineParser)
     */
    public function addUndefinedFootnoteWarning(string $label, int $line, int $column): void
    {
        $this->addWarning("Undefined footnote '{$label}'", $line, $column, false);
    }

    /**
     * Track an anchor link for validation (called from InlineParser)
     * Only tracks when collectWarnings is enabled.
     */
    public function trackAnchorLink(string $fragment, int $line, int $column): void
    {
        if ($this->collectWarnings) {
            $this->anchorLinks[] = [
                'fragment' => $fragment,
                'line' => $line,
                'column' => $column,
            ];
        }
    }

    /**
     * Validate anchor links point to existing IDs in the document
     *
     * Checks all links with `#fragment` destinations against:
     * - Heading IDs (from heading auto-references)
     * - Explicit `{#id}` attributes on any element
     */
    protected function validateAnchorLinks(Document $document): void
    {
        if ($this->anchorLinks === []) {
            return;
        }

        // Collect all known anchor targets
        $knownIds = $this->headingIds;

        // From explicit {#id} attributes on any node in the AST
        $this->collectExplicitIds($document, $knownIds);

        // Validate each tracked anchor link. Matching is exact (case-sensitive):
        // a plain `[link](#fragment)` href is emitted verbatim and HTML fragment
        // navigation is case-sensitive, so a `#my-heading` link to a
        // case-preserved `My-Heading` id is genuinely broken and must warn.
        // (Contrast `</#id>` crossrefs, which rewrite the href to the resolved
        // id and so resolve case-insensitively.)
        foreach ($this->anchorLinks as $anchor) {
            if (!isset($knownIds[$anchor['fragment']])) {
                $this->addWarning(
                    "Broken anchor link '#{$anchor['fragment']}' — no element with this ID exists",
                    $anchor['line'],
                    $anchor['column'],
                    false,
                    'anchor',
                    null,
                );
            }
        }
    }

    /**
     * Recursively collect explicit {#id} attributes from the AST
     *
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param array<string, bool> $ids
     */
    protected function collectExplicitIds(Node $node, array &$ids): void
    {
        if ($node->hasAttribute('id')) {
            $id = $node->getAttribute('id');
            if ($id !== null && $id !== '') {
                $ids[$id] = true;
            }
        }

        foreach ($node->getChildren() as $child) {
            $this->collectExplicitIds($child, $ids);
        }
    }

    /**
     * Get the inline parser for registering custom patterns
     */
    public function getInlineParser(): InlineParser
    {
        return $this->inlineParser;
    }

    /**
     * Get the list parser for tweaking list parsing (e.g. bullet markers)
     */
    public function getListParser(): ListParser
    {
        return $this->listParser;
    }

    /**
     * Check if text contains only plain characters (no inline markup triggers).
     *
     * Used to skip the inline parser for simple table cell content,
     * creating a Text node directly instead.
     */
    protected function isPlainText(string $text): bool
    {
        // Can't shortcut if custom patterns or abbreviations are registered
        if ($this->inlineParser->getInlinePatterns() || $this->abbreviations) {
            return false;
        }

        // Check for any character that triggers inline parsing. Includes
        // Carve's delimiters: / (italic), and , / = (the ,, subscript
        // and == highlight pairs).
        return strpbrk($text, '\\`*_[{^~<$:!"\'-.\n/,=') === false;
    }

    private function isPlainTableText(string $text): bool
    {
        return strpbrk($text, "\\`*_[{^~<\$:!\"'-\n/,=") === false;
    }

    /**
     * Trim Unicode whitespace (the White_Space property) from both ends.
     *
     * `trim()` only knows ASCII, which left invisible characters at the edges
     * of a link destination. Zero-width characters (U+200B, U+FEFF) are not
     * whitespace and are deliberately preserved.
     */
    private static function trimUnicodeWhitespace(string $value): string
    {
        $trimmed = preg_replace(
            '/^[\p{Z}\x{0009}-\x{000D}\x{0085}]+|[\p{Z}\x{0009}-\x{000D}\x{0085}]+$/u',
            '',
            $value,
        );

        return $trimmed ?? trim($value);
    }
}
