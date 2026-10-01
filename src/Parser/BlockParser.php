<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use Closure;
use MarkupCarve\Carve\Ast\SourceSpan;
use MarkupCarve\Carve\CodePayload;
use MarkupCarve\Carve\Exception\ParseException;
use MarkupCarve\Carve\Exception\ParseWarning;
use MarkupCarve\Carve\Node\Block\AbbreviationDefinition;
use MarkupCarve\Carve\Node\Block\BlockQuote;
use MarkupCarve\Carve\Node\Block\Caption;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Block\DefinitionList;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\Figure;
use MarkupCarve\Carve\Node\Block\FigureGroup;
use MarkupCarve\Carve\Node\Block\Footnote;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\LineBlock;
use MarkupCarve\Carve\Node\Block\LinkReferenceDefinition;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\ListItem;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Block\RawBlock;
use MarkupCarve\Carve\Node\Block\Table;
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
use MarkupCarve\Carve\Parser\Utility\ContainerLabelParser;
use MarkupCarve\Carve\Parser\Utility\IndentationHelper;
use MarkupCarve\Carve\Parser\Utility\LayoutWork;
use MarkupCarve\Carve\Renderer\HeadingIdTracker;
use MarkupCarve\Carve\Transform\BlockImagePromotion;
use MarkupCarve\Carve\Util\StringUtil;
use WeakMap;

/**
 * Block-level parser for Carve
 */
class BlockParser
{
    use LegacyBlockParserProperties;

    private BlockParserState $state;

    private ?ListBlockBuilder $listsImplementation = null;

    private ?DefinitionListBuilder $definitionsImplementation = null;

    private ?LineBlockBuilder $linesImplementation = null;

    private ?TableBlockBuilder $tablesImplementation = null;

    private ?BlockQuoteBuilder $quotesImplementation = null;

    private ?BlockSourceMapper $sourceService = null;

    private ?BlockReferenceResolver $referencesService = null;

    private ?BlockContinuationScanner $continuationsService = null;

    /**
     * Neutral starting point for incremental brace scanning.
     *
     * @var array{depth: int, inQuote: bool, quoteChar: string, pendingEscape: bool}
     */
    private const INITIAL_BRACE_STATE = ['depth' => 0, 'inQuote' => false, 'quoteChar' => '', 'pendingEscape' => false];

    /**
     * The attached run holds nothing visible yet {@see self::attachedBlockKind()}.
     *
     * @var string
     */
    protected const ATTACHED_PENDING = BlockGrammar::ATTACHED_PENDING;

    /**
     * The attached block is a paragraph; PART 9 §10 ends it.
     *
     * @var string
     */
    protected const ATTACHED_PARAGRAPH = BlockGrammar::ATTACHED_PARAGRAPH;

    /**
     * The attached block has a multi-line extent of its own - a quote, a list,
     * a table, a fenced body - which the collectors' own boundaries end.
     *
     * @var string
     */
    protected const ATTACHED_SPANNING = BlockGrammar::ATTACHED_SPANNING;

    /**
     * Initial state for list-item lazy continuation. No paragraph is open
     * until a content line starts one; a comment alone must not admit a lazy
     * continuation (corpus 326-5). See advanceTrailingBlockState().
     *
     * @var array{openParagraph: bool, inFence: bool, fenceChar: string, fenceLength: int, fenceColumn: int, fenceHostColumn: int, inDiv: bool, divFenceLength: int, divColumn: int, absorbingFence: bool, divDepth: int, isLead: bool, inTable: bool, afterInvisible: bool, afterComment: bool, inFootnoteBody: bool, quotedTable: bool, quoteParagraph: bool, nestedColumn: int}
     */
    protected const INITIAL_TRAILING_BLOCK_STATE = ['openParagraph' => false, 'inFence' => false, 'fenceChar' => '', 'fenceLength' => 0, 'fenceColumn' => 0, 'fenceHostColumn' => 0, 'inDiv' => false, 'divFenceLength' => 0, 'divColumn' => 0, 'absorbingFence' => false, 'divDepth' => 0, 'isLead' => true, 'inTable' => false, 'afterInvisible' => false, 'afterComment' => false, 'inFootnoteBody' => false, 'quotedTable' => false, 'quoteParagraph' => false, 'nestedColumn' => 0];

    /**
     * Marks a line an enclosing container folded in BELOW its content column
     * (markup-carve/carve-php#1900). Its first character is not whitespace, so
     * the line stands at column 0 and matches no opener and no closer - which
     * is the whole difference from the one-column clamp beside it, since that
     * clamp still lets a fence closer close the fence it was written inside.
     * The executable spec spells the same frame `LAZY` in `layout.mjs`.
     *
     * Unforgeable rather than merely unlikely: every U+0000 in the source is
     * replaced with U+FFFD before the first line is read.
     *
     * @var string
     */
    protected const LAZY_FRAME = BlockGrammar::LAZY_FRAME;

    /**
     * Abbreviation separators are maximal runs of ASCII spaces. A tab or
     * no-break space after the run belongs to the expansion. A tab cannot
     * replace the required space after `]:`. Whitespace-only
     * content does not open a definition (carve#892).
     *
     * @var string
     */
    private const ABBREVIATION_DEFINITION_PATTERN = '/^\*\[([A-Za-z0-9]+)\]: +(?![ \t]*$)([^ ].*)$/';

    /**
     * Footnote separators follow ABBREVIATION_DEFINITION_PATTERN. A leading
     * tab belongs to the body, where block parsing treats it as indentation
     * (PART 9 §24 C1).
     *
     * @var string
     */
    private const FOOTNOTE_DEFINITION_PATTERN = BlockGrammar::FOOTNOTE_DEFINITION_PATTERN;

    /**
     * A footnote body's own column: the indent PART 9 §16 asks a continuation
     * line for, and the amount the body is dedented by - never the first
     * continuation line's actual indent, so a deeper line keeps its residual
     * columns and the body's own blocks read them.
     *
     * @var int
     */
    private const FOOTNOTE_BODY_COLUMN = BlockGrammar::FOOTNOTE_BODY_COLUMN;

    /**
     * How many footnote bodies the current parse is inside.
     *
     * Only a note nested in another note's body needs its floor measured on the
     * authored source; a note in a list item or a description body is placed by
     * the host's own content column and has always been read correctly from
     * this coordinate system. carve-js#1666 draws the same line with its
     * `hostIsFootnoteBody` parameter.
     */
    private int $footnoteBodyDepth = 0;

    /**
     * @var int
     */
    public const MAX_NESTING_DEPTH = BlockGrammar::MAX_NESTING_DEPTH;

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
    protected const MAX_HEADING_WALK_DEPTH = BlockGrammar::MAX_HEADING_WALK_DEPTH;

    /**
     * @var string
     */
    protected const DEFINITION_TERM_PATTERN = BlockGrammar::DEFINITION_TERM_PATTERN;

    /**
     * A definition term, tested rather than captured.
     *
     * @var string
     */
    protected const DEFINITION_TERM_LINE_PATTERN = BlockGrammar::DEFINITION_TERM_LINE_PATTERN;

    /**
     * A definition-term MARKER, where the caller checks only that the line
     * opens one.
     *
     * Content-guarded like the body prefix below, for the same carve#755
     * reason: `:: ` with only whitespace after it is the empty marker `::`
     * (PART 2, CARVE-P2-025), opens no term, and must not end a body or break
     * a term's fold where `::` does not (markup-carve/carve-php#2218).
     *
     * @var string
     */
    protected const DEFINITION_TERM_LINE_PREFIX = BlockGrammar::DEFINITION_TERM_LINE_PREFIX;

    /**
     * A definition body: its separator run, then its content.
     *
     * MARKER REQUIRES CONTENT ignores TRAILING WHITESPACE, and NO TRAILING
     * WHITESPACE spells whitespace as space or tab here, so a body of nothing
     * but tabs is a trailing run and opens no description. The separator run is
     * SPACES ONLY, so a body may still START with a tab: `: <TAB>text` opens
     * with the tab as content (markup-carve/carve#1836).
     *
     * @var string
     */
    protected const DEFINITION_BODY_PATTERN = BlockGrammar::DEFINITION_BODY_PATTERN;

    /**
     * A definition BODY marker, where the caller checks only that the line
     * opens one.
     *
     * Content-guarded exactly as the capturing pattern is. These two are the
     * carve#755 pair: the prefix breaks a term's fold and ends a body, the
     * pattern opens one, and a line one of them accepts while the other refuses
     * falls out of the definition loop as a stray paragraph. That is what a
     * separator-only two-space line used to do.
     *
     * @var string
     */
    protected const DEFINITION_BODY_LINE_PREFIX = BlockGrammar::DEFINITION_BODY_LINE_PREFIX;

    /**
     * The width of the `:` marker the separator run follows.
     *
     * A body's content column is `self::DEFINITION_MARKER_WIDTH + strlen($separator)`
     * - a one-space separator establishes column 2, a two-space separator
     * column 3 and a four-space separator column 5 - and a continuation line
     * qualifies by REACHING ITS OWN BODY'S column, which is what PART 9 §24 C1
     * already asks of a footnote body and a list item. This was a fixed `3`, so
     * the two-space spelling was the only one a body could have (carve#1757).
     *
     * The number is a column, not a character count: `definition_continuation`
     * is a leading indentation run, which is the one position where a tab IS
     * syntax, and PART 9 §24 C1 measures a leading run in columns with a tab
     * advancing to the next multiple of 4 (markup-carve/carve#888 signoff
     * `direction=27fba08112af`, reaffirmed by markup-carve/carve#901).
     *
     * @var int
     */
    protected const DEFINITION_MARKER_WIDTH = BlockGrammar::DEFINITION_MARKER_WIDTH;

    private int $nestingDepth = 0;

    protected InlineParser $inlineParser;

    protected ListParser $listParser;

    protected TableParser $tableParser;

    protected FencedBlockParser $fencedBlockParser;

    protected ReferenceDefinitionExtractor $referenceDefinitionExtractor;

    /**
     * Unresolved image captions, keyed weakly so discarded paragraphs do not
     * stay alive until the parse ends. Only surviving nodes are patched.
     *
     * @var \WeakMap<\MarkupCarve\Carve\Node\Block\Paragraph, array{image: \MarkupCarve\Carve\Node\Inline\Image, captionText: string, captionLines: array<string>, start: int, markerWidth: int, rawLines: array<string>, rawSpans: list<array{break: \MarkupCarve\Carve\Ast\SourceSpan|null, text: \MarkupCarve\Carve\Ast\SourceSpan|null}>}>|null
     */
    private ?WeakMap $deferredImageCaptions = null;

    /**
     * Paragraph inlines deferred by the scratch pass of
     * {@see self::indexHeadingsFromStructure()}, which keeps only headings. Set
     * only during that pass; a deferred paragraph is parsed where block
     * structure reads its inlines (a caption host).
     *
     * @var \WeakMap<\MarkupCarve\Carve\Node\Block\Paragraph, array{string, int, list<array{int, int, int, string}>}>|null
     */
    private ?WeakMap $deferredScratchInlines = null;

    /**
     * @return list<\MarkupCarve\Carve\Ast\SourceSpan>
     */
    public function getUnattachedBlockAttributes(): array
    {
        return $this->state->session->unattachedBlockAttributes;
    }

    /**
     * Whether to collect warnings during parsing
     */
    protected bool $collectWarnings = false;

    /**
     * Whether to throw on parse errors
     */
    protected bool $strictMode = false;

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

    /**
     * The tracker headingIndexKey() reduces a reference LABEL through.
     *
     * Held rather than rebuilt per reference: the extraction is stateless, and
     * a document quoting the same heading a hundred times should not construct
     * a hundred trackers to ask them all the same question.
     *
     * UNCONFIGURED, deliberately. headingIdTrackerForReferences() mirrors the
     * slug transform and the lowercase flag because it hands out IDS; this one
     * is only ever asked for PLAIN TEXT, which neither setting touches. It was
     * built through that helper and invalidated from both setters at first,
     * which made the two setters look like they could move a label key they
     * cannot: one of the two invalidations was unreachable by any test, and the
     * other only looked reachable. Taking the configuration out is what makes
     * the invalidation unnecessary rather than merely unexercised.
     */
    protected ?HeadingIdTracker $referenceLabelTracker = null;

    public function setHeadingIdTransformer(?Closure $headingIdTransformer): void
    {
        $this->headingIdTransformer = $headingIdTransformer;
    }

    public function setHeadingIdLowercase(bool $lowercase): void
    {
        $this->headingIdLowercase = $lowercase;
    }

    public function __clone(): void
    {
        $this->state = clone $this->state;
        $this->listsImplementation = null;
        $this->definitionsImplementation = null;
        $this->linesImplementation = null;
        $this->tablesImplementation = null;
        $this->quotesImplementation = null;
        $this->sourceService = null;
        $this->referencesService = null;
        $this->continuationsService = null;
        $this->inlineParser = $this->inlineParser->copyForBlockParser($this);
        $this->referenceDefinitionExtractor = $this->referenceDefinitionExtractor->copyForInlineParser($this->inlineParser);
        $this->sortedBlockMatchers = null;
        $this->bindLegacySession();
        $this->bindLegacyFrame();
        $this->bindLegacySource();
    }

    public function __construct(
        bool $collectWarnings = false,
        bool $strictMode = false,
        bool $trackSourceLines = false,
        bool $trackPositions = false,
    ) {
        $this->state = new BlockParserState();
        $this->bindLegacySource();
        $this->bindNewSession();
        $this->bindBlockFrame(new BlockParseFrame());
        $this->collectWarnings = $collectWarnings;
        $this->strictMode = $strictMode;
        $this->state->source->trackSourceLines = $trackSourceLines;
        $this->state->source->trackPositions = $trackPositions;
        $this->inlineParser = new InlineParser($this);
        $this->listParser = new ListParser();
        $this->tableParser = new TableParser();
        $this->fencedBlockParser = new FencedBlockParser();
        $this->referenceDefinitionExtractor = new ReferenceDefinitionExtractor($this->inlineParser);
    }

    private function bindBlockFrame(BlockParseFrame $frame): void
    {
        $this->state->frame = $frame;
        $this->bindLegacyFrame();
    }

    public function enablePositionTracking(): self
    {
        $this->state->source->trackPositions = true;

        return $this;
    }

    /**
     * Register a block pattern. The callback receives the source lines, start
     * index, parent node, and parser. It returns consumed lines or null when
     * the pattern does not match.
     *
     * @param string $pattern Regex for the first line
     * @param callable(array<string>, int, \MarkupCarve\Carve\Node\Node, self): ?int $callback
     */
    public function addBlockPattern(string $pattern, callable $callback): void
    {
        $this->removeBlockPattern($pattern);
        $this->customBlockPatterns[$pattern] = $callback;
        $this->registerBlockMatcher(
            static function (array $lines, int $start, MatcherContext $ctx) use ($pattern, $callback): ?int {
                if (!preg_match($pattern, $lines[$start])) {
                    return null;
                }

                // Legacy callbacks append their node(s) to the parent themselves
                // and return the number of lines consumed. Preserve that contract
                // verbatim — a pattern emitting several sibling blocks keeps them
                // flat, with no synthetic wrapper. The dispatcher reads the int
                // return as "already appended".
                $parser = $ctx->getBlockParser();
                $parent = $parser->currentMatcherParent;
                if ($parent === null) {
                    return null;
                }

                $parser->materializeScratchParagraphs();
                $consumed = $callback($lines, $start, $parent, $parser);

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
        if ($pattern === null) {
            $callback = $matcher;
            $matcher = static function (array $lines, int $start, MatcherContext $ctx) use ($callback): mixed {
                $ctx->getBlockParser()->materializeScratchParagraphs();

                return $callback($lines, $start, $ctx);
            };
        }
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
     * Whether diagnostics are being collected at all.
     *
     * Read by the inline parser so it does not compute a diagnostic's
     * coordinates for a diagnostic nobody will keep.
     */
    public function collectsWarnings(): bool
    {
        return $this->collectWarnings;
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
        return $this->state->session->warnings;
    }

    /**
     * Clear collected warnings
     */
    public function clearWarnings(): self
    {
        $this->state->session->warnings = [];

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
        $line = $line + $this->state->session->lineOffset + 1;

        if ($isError && $this->strictMode) {
            throw new ParseException($message, $line, $column);
        }

        if ($this->collectWarnings) {
            $this->state->session->warnings[] = new ParseWarning($message, $line, $column, $category, $suggestion);
        }
    }

    public function parse(string $input): Document
    {
        // Capture the original source byte length before any normalization so
        // renderers can size the abbreviation-expansion budget (DoS guard).
        $sourceLength = strlen($input);
        // Normalize invalid UTF-8 before parsing and source mapping (PART 1).
        // Keep the original byte length for the output-expansion budget.
        $input = StringUtil::toValidUtf8($input);
        // Replace NUL after capturing sourceLength and before building offsets
        // so the byte budget describes received input, and source spans and node text
        // use the same U+FFFD characters (carve-php#1563). This also prevents
        // collisions with internal NUL sentinels.
        if (str_contains($input, "\0")) {
            $input = str_replace("\0", "\u{FFFD}", $input);
        }
        $this->resetParseState();
        $document = new Document();
        // Strip a single leading UTF-8 BOM (U+FEFF) at the document start so
        // `﻿# T` is a heading, not literal text. Root only: this is the
        // top-level entry; nested content is parsed from line arrays.
        // Kept for the offset table: PART 12 §4 positions index the ORIGINAL
        // file, and everything below rewrites the text the parser sees.
        $this->state->source->originalSource = $input;
        if (str_starts_with($input, "\u{FEFF}")) {
            $input = substr($input, 3);
        }
        $lines = $this->splitLines($input);

        // Each physical byte may be inspected a small constant number of times
        // by the parser-backed marker probe below. Exhaustion deliberately
        // collects: metadata disappearing is the unsafe failure mode.

        // First pass: extract reference definitions, footnotes, abbreviations, and heading references
        $this->extractDefinitions($lines, $input);
        if ($this->needsStructuredHeadingIndex($input)) {
            $this->indexHeadingsFromStructure($lines);
        } elseif ($this->collectWarnings && str_contains($input, '](#')) {
            $this->extractHeadingReferences($lines);
        }

        $this->state->session->discoveringDefinitions = true;

        // Definitions are collected by this authoritative structural walk, and
        // forward inline references are resolved afterwards.
        $this->parseBlocks($document, $lines, 0, topLevel: true);
        $this->state->session->discoveringDefinitions = false;
        $this->finishIntegratedDefinitionPass($document, $lines);

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
        if ($this->state->session->sawUnresolvedCollapsedReference) {
            $headingReferences = (new HeadingReferenceCollector($this->headingIdTrackerForReferences()))
                ->collect($document);
            // Only re-parse for headings the first pass could not already
            // reach. Without this a single typo'd reference in a document full
            // of top-level headings would pay for a second parse that changes
            // nothing, since those headings resolved in pass 1 anyway.
            // An explicit definition beats the implicit heading, so a heading
            // whose label is also DEFINED is not worth seeding a reparse for.
            // Folded the way the collector folds: a heading-reference key is
            // `mb_strtolower`ed, a definition key only collapses whitespace.
            $definedFolded = [];
            foreach (array_keys($this->state->session->references) as $label) {
                $definedFolded[mb_strtolower((string)$label, 'UTF-8')] = true;
            }
            // AND ONLY FOR HEADINGS THAT COULD RESCUE A FAILED REFERENCE
            // (carve-php#2245). The flag above fires for any reference that
            // found no definition, which includes the ordinary case of a
            // definition written below its use. Filtering on headings alone
            // then reparsed the whole document because it contained a heading
            // - any heading, related to the failed label or not.
            $headingReferences = array_filter(
                $headingReferences,
                fn (string $folded): bool => !isset($this->state->session->headingReferencesByFoldedLabel[$folded])
                    && !isset($definedFolded[$folded])
                    && ($this->state->session->unresolvedReferenceLabelUnknown
                        || isset($this->state->session->unresolvedReferenceLabels[$folded])),
                ARRAY_FILTER_USE_KEY,
            );
            if ($headingReferences !== []) {
                $document = $this->reparseWithHeadingReferences($lines, $headingReferences, $sourceLength);
            }
        }

        // Append footnotes section if any
        foreach ($this->state->session->footnotes as $label => $footnote) {
            // A definition's extent is derived from its body by
            // `deriveContainerSpans`, and a definition with NO BLOCKS has no
            // body to derive it from: `[^f]: {empty}` reached the wire with no
            // `pos`, which §4 permits only for a node that CANNOT be placed.
            // This one can - it is written on a line of its own - so the
            // definition line is its extent, which is what the reference
            // publishes (markup-carve/carve#1023).
            //
            // Only when there are no children, so a definition that has content
            // keeps the extent its body already gives it.
            $footnote->setPos($this->state->session->footnoteDefinitionSpans[$label] ?? $footnote->getPos());
            $document->appendChild($footnote);
        }

        $this->appendLinkReferenceDefinitions($document);

        // A sole-image paragraph carrying a leading block-attribute line's attrs
        // renders as a bare block <img> with those attrs on the image (§15). Run
        // this AFTER caption wrapping (so a captioned image is already a <figure>
        // and keeps its id there) and BEFORE rendering (so render-time extension
        // attributes are untouched -- they still land on the <p> wrapper).
        $this->promoteBlockImages($document);

        if ($this->state->source->trackPositions) {
            // After every pass that can move or wrap nodes, so a container sees
            // its final children.
            $this->deriveContainerSpans($document);
        }

        // After the spans exist, because it sorts BY them.
        $this->orderCollectedDefinitions($document);

        // Validate references and anchor links if warnings are enabled
        if ($this->collectWarnings) {
            $this->validateReferences();
            $this->validateAnchorLinks($document);
        }

        // Store abbreviations on document for round-trip support
        if ($this->state->session->abbreviations !== []) {
            $document->setAbbreviations($this->state->session->abbreviations);
            $document->setAbbreviationDefinitions($this->state->session->abbreviationDefinitions);
            $document->setAbbreviationsBeforeBody($this->state->session->abbreviationsBeforeBody);
            $document->setAbbreviationSpans($this->state->session->abbreviationSpans);
        }

        // Record the source byte length so renderers can size the
        // abbreviation-expansion budget (output-amplification DoS guard).
        $document->setSourceLength($sourceLength);

        return $document;
    }

    protected function promoteBlockImages(Node $node): void
    {
        $children = $node->getChildren();
        $replaced = false;
        foreach ($children as $index => $child) {
            if ($child instanceof Paragraph) {
                $kids = $child->getChildren();
                $child->setBlockImage(BlockImagePromotion::isBlockImage($child));
                // Only resolved images at the container's content column become block nodes.
                if (
                    count($kids) === 1
                    && $kids[0] instanceof Image
                    && ($kids[0]->getRawReferenceLabel() === null || $kids[0]->getSource() !== '')
                    && !isset($this->state->session->paragraphsAboveContentColumn[spl_object_id($child)])
                ) {
                    if ($child->getAttributeEntries() !== []) {
                        $kids[0]->mergeLeadingAttributes($child->getAttributeEntries(), $child->getAttributeOrder());
                        foreach (array_keys($child->getAttributeEntries()) as $key) {
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
            $this->promoteBlockImages($child);
        }
        if ($replaced) {
            $node->setChildren($children);
        }
    }

    /**
     * PART 12 §7: "Definitions appear in DOCUMENT ORDER by source position."
     */
    protected function orderCollectedDefinitions(Document $document): void
    {
        $children = $document->getChildren();
        $slots = [];
        $collected = [];
        foreach ($children as $index => $child) {
            if ($child instanceof Footnote || $child instanceof LinkReferenceDefinition) {
                $slots[] = $index;
                $collected[] = [count($slots), $child->getPos()->startOffset ?? PHP_INT_MAX, $child];
            }
        }
        if (count($slots) < 2) {
            return;
        }

        // Stable: the collection index breaks a tie rather than usort's
        // unspecified order for equal keys.
        usort(
            $collected,
            static fn (array $a, array $b): int => [$a[1], $a[0]] <=> [$b[1], $b[0]],
        );
        foreach ($slots as $k => $index) {
            $children[$index] = $collected[$k][2];
        }
        $document->setChildren($children);
    }

    protected function appendLinkReferenceDefinitions(Document $document): void
    {
        $this->referencesMapper()->appendLinkReferenceDefinitions($document);
    }

    /**
     * Reset the layout collection the definition extractor reads.
     *
     * EVERY DOCUMENT COLLECTS ITS DEFINITIONS IN THE STRUCTURAL WALK
     * (markup-carve/carve#1895, carve-php#2241). The specialized collectors
     * this used to gate cannot see whether a paragraph is open, so they asked
     * by reparsing the run once per candidate - quadratic work against a
     * budget that grows linearly with the source. It ran out after nine
     * marker-led definitions and then, conforming to PART 9R R1a's fallback,
     * collected nothing. The structural walk never asks, because a definition
     * line only reaches it when no paragraph is open.
     *
     * @param array<string> $lines
     * @param string $input
     */
    protected function extractDefinitions(array $lines, string $input): void
    {
    }

    /**
     * Bind or hand back the caption slots held during the walk, now that every
     * definition is known (carve-php#1851).
     *
     * A reference image that resolved becomes the same figure the pre-pass
     * path builds directly. One that did not gets ALL of its slot's source
     * lines back as paragraph text, which is what they would have folded into
     * had the slot never been held.
     */
    private function settleDeferredImageCaptions(Document $document): void
    {
        if ($this->deferredImageCaptions === null || count($this->deferredImageCaptions) === 0) {
            return;
        }

        $this->settleDeferredImageCaptionsIn($document);
        $this->deferredImageCaptions = null;
    }

    /**
     * Settle every held slot reachable from $parent.
     *
     * Reachability is the point: only a paragraph still in the finished
     * document gets its slot settled, so a slot recorded in a subtree the walk
     * later discarded resolves to nothing rather than to a patch nobody reads.
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param int $depth
     * @param array<\MarkupCarve\Carve\Node\Node> $ancestors Every node above $parent, outermost first.
     */
    private function settleDeferredImageCaptionsIn(Node $parent, int $depth = 0, array $ancestors = []): void
    {
        if ($this->deferredImageCaptions === null || $depth >= self::MAX_HEADING_WALK_DEPTH) {
            return;
        }

        $chain = $ancestors;
        $chain[] = $parent;

        foreach ($parent->getChildren() as $child) {
            $deferred = $this->deferredImageCaptions[$child] ?? null;
            if ($deferred === null) {
                $this->settleDeferredImageCaptionsIn($child, $depth + 1, $chain);

                continue;
            }

            $this->settleDeferredImageCaption($parent, $child, $deferred, $chain);
        }
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param \MarkupCarve\Carve\Node\Node $paragraph
     * @param array{image: \MarkupCarve\Carve\Node\Inline\Image, captionText: string, captionLines: array<string>, start: int, markerWidth: int, rawLines: array<string>, rawSpans: list<array{break: \MarkupCarve\Carve\Ast\SourceSpan|null, text: \MarkupCarve\Carve\Ast\SourceSpan|null}>} $deferred
     * @param array<\MarkupCarve\Carve\Node\Node> $chain The paragraph's ancestors, outermost first.
     */
    private function settleDeferredImageCaption(
        Node $parent,
        Node $paragraph,
        array $deferred,
        array $chain = [],
    ): void {
        $image = $deferred['image'];

        if (UnresolvedReference::sourceOf($image) !== null) {
            $reach = null;
            foreach (array_values($deferred['rawLines']) as $offset => $rawLine) {
                $spans = $deferred['rawSpans'][$offset] ?? ['break' => null, 'text' => null];

                $softBreak = new SoftBreak();
                $softBreak->setPos($spans['break']);
                $paragraph->appendChild($softBreak);

                $text = new Text($rawLine);
                $text->setPos($spans['text']);
                $paragraph->appendChild($text);

                $reach = $spans['text'] ?? $reach;
            }

            // The paragraph and every container holding it stopped at the
            // image, because that was the last child they had when the walk
            // stamped them. They own the given-back lines now.
            $this->widenSpanTo($paragraph, $reach);
            foreach ($chain as $ancestor) {
                $this->widenSpanTo($ancestor, $reach);
            }

            return;
        }

        $figure = new Figure();
        foreach ($paragraph->getAttributeEntries() as $key => $value) {
            $figure->setAttribute($key, $value);
        }

        $caption = new Caption();
        $this->inlineParser->parse(
            $caption,
            $deferred['captionText'],
            $deferred['start'],
            true,
            $this->captionSourceMap(
                $deferred['start'],
                array_values($deferred['captionLines']),
                $deferred['markerWidth'],
            ),
        );

        $figure->appendChild($image);
        $figure->appendChild($caption);
        $parent->replaceChildNode($paragraph, $figure);
    }

    /**
     * Parse definition-owned bodies after the document walk exposed every
     * global definition, then resolve references that appeared before their
     * definitions without rebuilding the block tree.
     *
     * @param \MarkupCarve\Carve\Node\Document $document
     * @param array<string> $lines
     */
    private function finishIntegratedDefinitionPass(Document $document, array $lines): void
    {
        // Parsing one footnote body can discover another definition inside it.
        // Iterate to a fixed point instead of relying on foreach's snapshot;
        // otherwise the nested note is registered but its body stays empty.
        $finishedFootnoteBodies = [];
        while (true) {
            $found = false;
            foreach ($this->state->session->discoveredFootnoteBodies as $label => $body) {
                if (isset($finishedFootnoteBodies[$label])) {
                    continue;
                }
                $finishedFootnoteBodies[$label] = true;
                $found = true;
                $this->state->session->discoveringDefinitions = true;
                $body['lines'] = $this->footnoteBodyDefinitionReach(
                    $this->rebaseOverindentedItemBlocks(
                        $body['lines'],
                        array_fill_keys(array_keys($body['lines']), true),
                        includeSublists: true,
                    ),
                );
                // The document walk has already finished. Its pending block
                // attributes belong after the document, not before this
                // deferred body, so isolate the same parser-global state that
                // the standalone footnote pass isolates below.
                $outerPendingSpan = $this->state->session->pendingAttributeSpan;
                $outerPendingAttributes = $this->state->session->pendingAttributes;
                $outerPendingAttributeOrder = $this->state->session->pendingAttributeOrder;
                $this->state->session->pendingAttributes = [];
                $this->state->session->pendingAttributeSpan = null;
                $this->state->session->pendingAttributeOrder = [];
                $this->footnoteBodyDepth++;
                try {
                    if (count($body['lines']) !== 1 || rtrim($body['lines'][0], " \t") !== '{empty}') {
                        $this->parseBlocks($this->state->session->footnotes[$label], $body['lines'], 0, $body['lineMap']);
                    }
                    $this->endContainerAttributeScope();
                } finally {
                    $this->footnoteBodyDepth--;
                    $this->state->session->discoveringDefinitions = false;
                    $this->state->session->pendingAttributeSpan = $outerPendingSpan;
                    $this->state->session->pendingAttributes = $outerPendingAttributes;
                    $this->state->session->pendingAttributeOrder = $outerPendingAttributeOrder;
                }
            }
            if (!$found) {
                break;
            }
        }
        if ($this->state->session->discoveredAbbreviationLines !== []) {
            $firstAbbreviationLine = min(array_keys($this->state->session->discoveredAbbreviationLines));
            $firstBodyLine = null;
            foreach ($lines as $lineNumber => $line) {
                if (IndentationHelper::isBlankLine($line) || isset($this->state->session->discoveredAbbreviationLines[$lineNumber])) {
                    continue;
                }
                $firstBodyLine = $lineNumber;

                break;
            }
            $this->state->session->abbreviationsBeforeBody = $firstBodyLine === null || $firstAbbreviationLine < $firstBodyLine;
        }
        $this->resolveForwardReferences($document);
        foreach ($this->state->session->footnotes as $footnote) {
            $this->resolveForwardReferences($footnote);
        }
        $this->settleDeferredImageCaptions($document);
        $this->state->session->warnings = array_values(array_filter(
            $this->state->session->warnings,
            function (ParseWarning $warning): bool {
                if (
                    preg_match("/^Undefined footnote '(.+)'$/", $warning->getMessage(), $match) === 1
                    && $this->hasFootnote($match[1])
                ) {
                    return false;
                }
                if (
                    preg_match("/^Undefined reference '(.+)'$/", $warning->getMessage(), $match) === 1
                    && isset($this->state->session->references[$match[1]])
                ) {
                    return false;
                }

                return true;
            },
        ));
    }

    private function resolveForwardReferences(Node $node, int $depth = 0): void
    {
        $this->referencesMapper()->resolveForwardReferences($node, $depth);
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
    protected function footnoteContainerPrefix(
        string $line,
        int $contentCol = 0,
        string $previousLine = '',
    ): array {
        $length = strlen($line);
        $newline = strpos($line, "\n");
        if ($newline !== false && $newline !== $length - 1) {
            return $this->footnoteContainerPrefixFromCopies($line, $contentCol, $previousLine);
        }

        $at = 0;
        $stripped = false;
        $budget = $contentCol;
        do {
            $previousAt = $at;
            $whitespaceAt = IndentationHelper::pastLeadingWhitespace($line, $at);
            $spend = min($whitespaceAt - $at, $budget);
            $at += $spend;
            $budget -= $spend;

            $quoteWidth = ContainerPrefix::quoteMarkerWidth($line, $at);
            if ($quoteWidth !== null) {
                $budget = max(0, $budget - $quoteWidth);
                $at += $quoteWidth;
                $stripped = true;

                continue;
            }

            $head = $this->listParser->markerHeadAt($line, IndentationHelper::pastLeadingWhitespace($line, $at));
            if ($head !== null && $head['name'] !== 'task') {
                $budget = max(0, $budget - ($head['content'] - $at));
                $at = $head['content'];
                $stripped = true;

                continue;
            }

            if (ReferenceDefinitionExtractor::opensDefinitionEntry($previousLine)) {
                $pattern = '/[ \t]*:[ \t][ \t]*(?=' . StringUtil::NON_WHITESPACE_CLASS . ')/A';
                if (preg_match($pattern, $line, $match, 0, $at) === 1) {
                    $at += strlen($match[0]);
                    $stripped = true;
                }
            }
        } while ($at !== $previousAt);

        if ($stripped && preg_match('/\G\[\^[^\]]+\]:/', $line, $match, 0, $at) === 1) {
            if (LayoutWork::$on) {
                LayoutWork::$footnotePrescan += $length;
            }

            return ['kind' => 'container', 'prefix' => substr($line, 0, $at)];
        }

        return ['kind' => 'none', 'prefix' => ''];
    }

    /**
     * Exact capturing fallback for a subject containing an interior newline.
     *
     * @return array{kind: string, prefix: string}
     */
    private function footnoteContainerPrefixFromCopies(
        string $line,
        int $contentCol = 0,
        string $previousLine = '',
    ): array {
        $rest = $line;
        $stripped = false;
        // THE COLUMN IS A BUDGET, SPENT ACROSS THE WHOLE PREFIX - the same one
        // the link prepass spends in referenceLineView(), because the two
        // prepasses answer one question. Asking for the FULL content column on
        // every turn of the loop only worked while the prefix was one kind:
        // `- > - > x` puts its innermost quote past column 4, and the
        // continuation under it spends 2 on indent, 2 on the quote marker and 2
        // on indent again before the last marker. Re-asking for 4 after the
        // first quote found 2 columns of indentation and matched nothing, so
        // the definition was consumed by the block parser and registered by
        // nobody (markup-carve/carve-php#1431).
        //
        // The BOUND is what carries markup-carve/carve-php#788 through: at top
        // level the budget is 0, so no indentation is eaten and a
        // `    > [^f]: x` is indented text rather than a quote. Exactly the
        // column, never arbitrary indentation - now counted across the
        // composition rather than at one step of it.
        $budget = $contentCol;
        do {
            $previous = $rest;

            // Indentation is one of the strips the column composes, so it is
            // spent here rather than admitted wholesale: whatever the budget
            // still holds, and never more.
            $whitespace = strlen($rest) - strlen(ltrim($rest, " \t"));
            $spend = min($whitespace, $budget);
            if ($spend > 0) {
                $rest = substr($rest, $spend);
                $budget -= $spend;
            }

            // Blockquote marker `>` alone or `>` then a literal space. The
            // marker may be INDENTED: inside a list item the quote sits at the
            // item's content column (`- a` / `  > [^f]: x`), and testing
            // position 0 only left that line unstripped - so the definition was
            // never collected while the block parser still emptied the quote,
            // and the author's line rendered nothing AND defined nothing
            // (carve-php#788). The list-marker arm below already ltrims.
            $quoteContent = $this->blockQuoteLineContent($rest);
            if ($quoteContent !== null) {
                $budget = max(0, $budget - (strlen($rest) - strlen($quoteContent)));
                $rest = $quoteContent;
                $stripped = true;

                continue;
            }

            // Ordered/bullet list marker (non-task). Use the canonical list
            // parser so every accepted marker (bullet `-`/`*`/`+`, decimal /
            // alpha / roman ordered) is recognized identically to the real
            // parser; a task marker stops the loop (its content is not a def).
            $trimmed = ltrim($rest, " \t");
            $info = $this->listParser->parseListItemMarker($trimmed);
            if ($info !== null && $info['type'] !== 'task') {
                $markerWidth = strlen($rest) - strlen((string)$info['content']);
                $budget = max(0, $budget - $markerWidth);
                $rest = substr($rest, $markerWidth);
                $stripped = true;

                continue;
            }

            $afterTerm = ReferenceDefinitionExtractor::opensDefinitionEntry($previousLine);
            if ($afterTerm && preg_match('/^[ \t]*:[ \t][ \t]*(?=' . StringUtil::NON_WHITESPACE_CLASS . ')/', $rest, $descMatch) === 1) {
                $rest = substr($rest, strlen($descMatch[0]));
                $stripped = true;
            }
        } while ($rest !== $previous);

        if ($stripped && preg_match('/^\[\^[^\]]+\]:/', $rest)) {
            return ['kind' => 'container', 'prefix' => substr($line, 0, strlen($line) - strlen($rest))];
        }

        return ['kind' => 'none', 'prefix' => ''];
    }

    /**
     * Is this document one where the difference between the two ways of
     * building the heading index can be observed?
     *
     * Two consumers read it. A collapsed reference link `[text][]` resolves
     * through it; the explicit `[text][ref]` form only reads authored link
     * definitions and must not pay for a structure pass it cannot use. Anchor
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
        if (str_contains($input, '][]')) {
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
        $previousDeferredInlines = $this->deferredScratchInlines;
        $this->deferredScratchInlines = new WeakMap();
        try {
            $this->parseBlocks($scratch, $lines, 0, topLevel: true);
        } finally {
            $this->deferredScratchInlines = $previousDeferredInlines;
        }

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
        $this->extractDefinitions($lines, $this->state->source->normalizedSource);

        foreach ($index as $label => $id) {
            $this->registerHeadingReference((string)$label, new ReferenceDefinition('#' . $id, [], 0, null, true));
        }
        // Anchor validation asks a different question - does an element with
        // this id exist - so it takes EVERY heading, blockquote ancestors
        // included. Leaving these out warned "broken anchor link" for a link
        // to a heading that is right there.
        foreach ($ids as $id => $_present) {
            $this->state->session->headingIds[(string)$id] = true;
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
        $this->bindNewSession();
    }

    private function bindNewSession(): void
    {
        $this->state->session = new BlockParseSession();
        $this->bindLegacySession();
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
            if (
                ($contentLine[0] ?? '') === '#'
                && preg_match('/^(#{1,6}) +(.*' . StringUtil::NON_WHITESPACE_CLASS . '.*)$/', $contentLine, $matches)
            ) {
                // Content required (same rule as tryParseHeading): a bare
                // `#` / `# ` is not a heading and must not consume a slug here.
                // The charlist is tryParseHeading's too - a pre-scan that trims
                // a character the parser keeps derives its slug from a text the
                // rendered heading does not have (markup-carve/carve-php#1038).
                $headingText = trim($matches[2], " \t");
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
                    $this->state->session->headingIds[$id] = true;
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
                        : strpos($this->state->source->sourceLines[$partSourceLine] ?? '', $partText);
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
                $this->state->session->headingIds[$id] = true;

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
            // The stack rises left to right, so its last entry is its largest
            // and bounds every comparison this loop makes.
            $leadingColumns = IndentationHelper::getLeadingColumns(
                $line,
                $listContentColumns === [] ? null : $listContentColumns[array_key_last($listContentColumns)],
            );
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

        $length = strlen($content);
        $newline = strpos($content, "\n");
        $screened = $newline !== false && $newline !== $length - 1;
        $at = 0;

        while ($at < $length) {
            $strippedAt = IndentationHelper::pastLeadingWhitespace($content, $at);
            $leadingColumns = IndentationHelper::getLeadingColumns($content, null, $at);

            $quoteWidth = ContainerPrefix::quoteMarkerWidth($content, $strippedAt);
            if ($quoteWidth !== null) {
                $quoted = true;
                $at = $strippedAt + $quoteWidth;

                continue;
            }

            $head = $screened
                ? $this->markerHeadFromCopy($content, $strippedAt, $length)
                : $this->listParser->markerHeadAt($content, $strippedAt);
            if ($head === null) {
                break;
            }

            $openedList = true;
            // The measured width, which is now what the list parser itself
            // uses (carve-php#580). While a bullet was pinned at 2 here, this
            // scan deliberately hardcoded 2 as well so it could not index a
            // heading the renderer never emitted; both sides measure now, so
            // the pre-scan and the parse agree by construction.
            $markerWidth = $this->listMarkerWidthFor(
                $head['name'],
                $head['content'] - $strippedAt,
                $head['attrs'],
            );
            $baseColumn += $leadingColumns + $markerWidth;
            $listContentColumns[] = $baseColumn;
            $at = $head['content'];
        }

        if ($at === 0) {
            return ['content' => $content, 'quoted' => $quoted, 'openedList' => $openedList];
        }

        if (LayoutWork::$on) {
            LayoutWork::$prescan += $length - $at;
        }

        return ['content' => substr($content, $at), 'quoted' => $quoted, 'openedList' => $openedList];
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
            if (!preg_match('/^[.#:a-zA-Z_]/', $attrStr) || str_starts_with($attrStr, '%')) {
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
            if (preg_match('/^(.*)\}[ \t]*$/', $nextLine, $closeMatch)) {
                $attrStr = trim($attrContent . ' ' . $closeMatch[1]);
                if (!preg_match('/^[.#:a-zA-Z_]/', $attrStr) || str_starts_with($attrStr, '%')) {
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
     * @param bool $itemBody
     */
    protected function parseBlocks(
        Node $parent,
        array $lines,
        int $indent,
        ?array $lineMap = null,
        bool $topLevel = false,
        bool $itemBody = false,
    ): void {
        if ($this->nestingDepth >= self::MAX_NESTING_DEPTH) {
            $group = [];
            $groupStart = null;
            $previousContentColumns = $this->state->frame->currentContentColumns;
            $this->state->frame->currentContentColumns = $this->contentColumnsFor($lines, $lineMap);
            try {
                foreach ($lines as $offset => $line) {
                    $index = (int)$offset;
                    if (IndentationHelper::isBlankLine($line)) {
                        $this->appendDegradedParagraph($parent, $group, $lineMap, $groupStart, $index - 1);
                        $group = [];
                        $groupStart = null;

                        continue;
                    }
                    if ($groupStart === null) {
                        $groupStart = $index;
                    }
                    $group[] = $line;
                }
                $this->appendDegradedParagraph($parent, $group, $lineMap, $groupStart, count($lines) - 1);
            } finally {
                $this->state->frame->currentContentColumns = $previousContentColumns;
            }

            return;
        }

        $this->nestingDepth++;
        $previousFrame = $this->state->frame;
        $frame = new BlockParseFrame();
        $frame->currentLineMap = $lineMap;
        $frame->currentContentColumns = $this->contentColumnsFor($lines, $lineMap);
        $this->bindBlockFrame($frame);
        try {
            $this->parseBlocksImpl($parent, $lines, $indent, $topLevel, $itemBody);
        } finally {
            $this->bindBlockFrame($previousFrame);
            $this->nestingDepth--;
        }
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $paragraph
     * @param array<string> $group
     * @param array<int, int>|null $lineMap
     * @param int|null $firstIndex
     */
    private function placeDegradedSoftBreaks(
        Node $paragraph,
        array $group,
        ?array $lineMap,
        ?int $firstIndex,
    ): void {
        $this->sourceMapper()->placeDegradedSoftBreaks($paragraph, $group, $lineMap, $firstIndex);
    }

    /**
     * One paragraph of over-cap content (PART 9 §25), or nothing when the
     * group holds no visible text.
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $group
     * @param array<int, int>|null $lineMap
     * @param int|null $firstIndex
     * @param int|null $lastIndex
     */
    private function appendDegradedParagraph(
        Node $parent,
        array $group,
        ?array $lineMap = null,
        ?int $firstIndex = null,
        ?int $lastIndex = null,
    ): void {
        $text = rtrim(implode("\n", $group), "\n");
        if (trim($text, StringUtil::WHITESPACE_CHARS) === '') {
            return;
        }

        $paragraph = new Paragraph();
        // Pure line geometry - first line's start to last line's end - which is
        // what `stampBlockSpan` wants and what carve-js publishes for the same
        // document. The inline runs are placed separately, from the same line
        // geometry and only where the source proves the mapping, because these
        // lines may have been rewritten on the way here and §4 rates a wrong
        // span worse than an absent one - see placeDegradedTextRuns.
        if ($firstIndex !== null && $lastIndex !== null && $lastIndex >= $firstIndex) {
            $previousLineMap = $this->state->frame->currentLineMap;
            $this->state->frame->currentLineMap = $lineMap;
            $this->stampBlockSpan(
                $paragraph,
                $this->sourceLineFor($firstIndex),
                $this->sourceLineFor($lastIndex),
            );
            $this->state->frame->currentLineMap = $previousLineMap;
        }
        $this->inlineParser->parse($paragraph, $text);
        $this->placeDegradedSoftBreaks($paragraph, $group, $lineMap, $firstIndex);
        $this->placeDegradedTextRuns($paragraph, $group, $lineMap, $firstIndex);
        $parent->appendChild($paragraph);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $paragraph
     * @param array<string> $group
     * @param array<int, int>|null $lineMap
     * @param int|null $firstIndex
     */
    private function placeDegradedTextRuns(
        Node $paragraph,
        array $group,
        ?array $lineMap,
        ?int $firstIndex,
    ): void {
        $this->sourceMapper()->placeDegradedTextRuns($paragraph, $group, $lineMap, $firstIndex);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $indent
     * @param bool $topLevel
     * @param bool $itemBody
     */
    private function parseBlocksImpl(
        Node $parent,
        array $lines,
        int $indent,
        bool $topLevel = false,
        bool $itemBody = false,
    ): void {
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
                $this->state->session->pendingAttributeSpan ??= $this->wholeLinesSpan(
                    $i,
                    $i + $attrConsumed - 1,
                    $this->authoredColumnOf($i, $line) ?? 0,
                );
                $i += $attrConsumed;

                continue;
            }

            // Source-line tracking (opt-in): remember where this block starts and
            // how many children the parent had, so newly appended blocks can be
            // stamped with `data-source-line` after the dispatch below.
            $tracking = $this->state->source->trackSourceLines || $this->state->source->trackPositions;
            $sourceLine = $tracking ? $this->sourceLineFor($i) : -1;
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
            if ($topLevel && !$parent->hasChildren() && preg_match('/^---[ \t]*$/', $line) === 1) {
                $matchConsumed = $this->tryBlockMatchers($parent, $lines, $i);
                if ($matchConsumed !== null) {
                    $this->stampSourceLine(
                        $parent,
                        $childrenBefore,
                        $sourceLine,
                        $i,
                        $matchConsumed,
                    );
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
                    ?? $this->tryParseParagraph($parent, $lines, $i, $topLevel, $itemBody);
                // END LINE, not just the start. A block matched here can run
                // several lines - an extension matcher registered through
                // `addBlockPattern()` places no span of its own, so this stamp
                // is the only one it gets - and stamping the opener alone gave
                // it a one-line extent. A tagged frontmatter opener (`---json`)
                // reaches this path through the `-` family while a bare `---`
                // takes the branch above, which already passes the end line: one
                // construct, two spans, decided by whether the author wrote the
                // format tag (markup-carve/carve#1451). Blocks whose own parser
                // already placed them are unaffected - `stampBlockSpan()` never
                // overwrites a span.
                $this->stampSourceLine(
                    $parent,
                    $childrenBefore,
                    $sourceLine,
                    $i,
                    $consumed,
                );
                $i += $consumed;

                continue;
            }

            // Two unambiguous hot punctuation families. A pipe can only open
            // a table among core blocks. A hyphen can open a thematic break or
            // a list (the initial bare `---` frontmatter ambiguity already had
            // its extension-first check above). Avoid probing every unrelated
            // fence/container parser for every row and list item.
            if ($fc === '|') {
                $consumed = $this->tryParseTable($parent, $lines, $i)
                    ?? $this->tryBlockMatchers($parent, $lines, $i)
                    ?? $this->tryParseParagraph($parent, $lines, $i, $topLevel, $itemBody);
                // END LINE, not just the start. A block matched here can run
                // several lines - an extension matcher registered through
                // `addBlockPattern()` places no span of its own, so this stamp
                // is the only one it gets - and stamping the opener alone gave
                // it a one-line extent. A tagged frontmatter opener (`---json`)
                // reaches this path through the `-` family while a bare `---`
                // takes the branch above, which already passes the end line: one
                // construct, two spans, decided by whether the author wrote the
                // format tag (markup-carve/carve#1451). Blocks whose own parser
                // already placed them are unaffected - `stampBlockSpan()` never
                // overwrites a span.
                $this->stampSourceLine(
                    $parent,
                    $childrenBefore,
                    $sourceLine,
                    $i,
                    $consumed,
                );
                $i += $consumed;

                continue;
            }
            if ($fc === '-') {
                $consumed = $this->tryParseThematicBreak($parent, $line, $i)
                    ?? $this->tryParseList($parent, $lines, $i)
                    ?? $this->tryBlockMatchers($parent, $lines, $i)
                    ?? $this->tryParseParagraph($parent, $lines, $i, $topLevel, $itemBody);
                // END LINE, not just the start. A block matched here can run
                // several lines - an extension matcher registered through
                // `addBlockPattern()` places no span of its own, so this stamp
                // is the only one it gets - and stamping the opener alone gave
                // it a one-line extent. A tagged frontmatter opener (`---json`)
                // reaches this path through the `-` family while a bare `---`
                // takes the branch above, which already passes the end line: one
                // construct, two spans, decided by whether the author wrote the
                // format tag (markup-carve/carve#1451). Blocks whose own parser
                // already placed them are unaffected - `stampBlockSpan()` never
                // overwrites a span.
                $this->stampSourceLine(
                    $parent,
                    $childrenBefore,
                    $sourceLine,
                    $i,
                    $consumed,
                );
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
                ?? $this->tryParseQuoteBlock($parent, $lines, $i)
                ?? $this->tryParseDiv($parent, $lines, $i)
                ?? $this->tryParseDefinitionList($parent, $lines, $i)
                ?? $this->tryParseHeading($parent, $lines, $i)
                ?? $this->tryParseThematicBreak($parent, $line, $i)
                ?? $this->tryParseBlockQuote($parent, $lines, $i)
                ?? $this->tryParseList($parent, $lines, $i)
                ?? $this->tryParseTable($parent, $lines, $i)
                ?? $this->tryParseFootnoteDefinition($parent, $lines, $i)
                ?? $this->tryParseReferenceDefinition($lines, $i)
                ?? $this->tryParseAbbreviationDefinition($parent, $lines, $i, $topLevel)
                ?? $this->tryParseCaption($parent, $lines, $i);

            if ($consumed === null) {
                $matchConsumed = $this->tryBlockMatchers($parent, $lines, $i);
                if ($matchConsumed !== null) {
                    $this->stampSourceLine(
                        $parent,
                        $childrenBefore,
                        $sourceLine,
                        $i,
                        $matchConsumed,
                    );
                    $i += $matchConsumed;

                    continue;
                }
            }

            $consumed ??= $this->tryParseParagraph($parent, $lines, $i, $topLevel, $itemBody);

            // The block ran from $i to $i + $consumed - 1 in THIS line array;
            // resolve the last one back to the top-level array the offsets are
            // keyed by, the same way the first one was.
            $this->stampSourceLine(
                $parent,
                $childrenBefore,
                $sourceLine,
                $i,
                $consumed,
            );
            $i += $consumed;
        }
        if ($topLevel) {
            $this->endContainerAttributeScope();
        }
    }

    private function authoredColumnOf(int $index, string $line): ?int
    {
        return $this->sourceMapper()->authoredColumnOf($index, $line);
    }

    /**
     * A note's body floor on the AUTHORED source: two columns past the column
     * its `[^label]:` marker was written at. Null when the mapping is absent, so
     * the caller keeps the coordinate-system floor it used before.
     */
    private function authoredFootnoteBodyFloor(int $definitionIndex, string $definitionLine): ?int
    {
        $marker = $this->authoredColumnOf($definitionIndex, $definitionLine);

        return $marker === null ? null : $marker + self::FOOTNOTE_BODY_COLUMN;
    }

    private function sourceLineFor(int $index): int
    {
        return $this->state->frame->sourceLineFor($index);
    }

    /**
     * @param array<string> $lines
     * @param array<int, int>|null $lineMap
     *
     * @return array<int, int>
     */
    private function contentColumnsFor(array $lines, ?array $lineMap): array
    {
        return $this->sourceMapper()->contentColumnsFor($lines, $lineMap);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param int $childrenBefore Child count before the block was parsed, or -1 when disabled.
     * @param int $sourceLine 0-indexed original source line; emitted as 1-based (+1).
     * @param int $first
     * @param int $consumed
     *
     * @return void
     */
    private function stampSourceLine(Node $parent, int $childrenBefore, int $sourceLine, int $first, int $consumed): void
    {
        if ($childrenBefore < 0 || $sourceLine < 0) {
            return;
        }

        $source = $this->sourceMapper();
        $source->stampSourceLine($parent, $childrenBefore, $sourceLine, $source->blockEndSourceLine($first, $consumed, $sourceLine));
    }

    private function stampBlockSpan(Node $node, int $startLine, int $endLine, ?int $endBytesOnEndLine = null): void
    {
        $this->sourceMapper()->stampBlockSpan($node, $startLine, $endLine, $endBytesOnEndLine);
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
            // Exclude * = + - ~ ^ which are braced inline markers. `_` leads an
            // identifier, so `{_k=1}` is a block-attribute line; the boolean
            // form that collides with forced underline is refused by
            // `isValidAttrPayload` instead (carve-php#2021).
            if (!preg_match('/^[.#:a-zA-Z_]/', $attrStr) || str_starts_with($attrStr, '%')) {
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
        // Which quote character, if any, the payload so far ends INSIDE. A
        // QUOTED VALUE STOPS AT THE NEWLINE (PART 4), so this is what refuses
        // the block at the line boundary below.
        $openQuote = $this->attrPayloadOpenQuote($attrContent, null);
        $i = $start + 1;

        while ($i < $count) {
            if ($openQuote !== null) {
                return null;
            }

            $nextLine = $lines[$i];

            // Check if this line ends the attribute block
            if (preg_match('/^(.*)\}[ \t]*$/', $nextLine, $closeMatch)) {
                $attrContent .= ' ' . $closeMatch[1];
                $attrStr = trim($attrContent);

                // Exclude * = + - ~ ^ which are braced inline markers; `_` leads an
                // identifier and is refused for the boolean form alone.
                if (!preg_match('/^[.#:a-zA-Z_]/', $attrStr) || str_starts_with($attrStr, '%')) {
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

            // A BLANK LINE ENDS THE ATTEMPT (PART 15 A5, and `continuation`
            // in the grammar says "NOT a blank line"): the text is then
            // literal, not a block_attributes. A line of spaces or tabs is a
            // blank line - it was previously accepted as interior padding
            // because it matched the indent test below.
            if (IndentationHelper::isBlankLine($nextLine)) {
                return null;
            }

            // CONTINUATION LINE, INDENTED OR NOT. `continuation = newline,
            // opt_ws` puts the indentation in `opt_ws`, which is optional, and
            // `attr_separator = (whitespace | continuation), opt_ws` admits one
            // line break per separator with no cardinality limit - so a block
            // may span any number of lines. Requiring an indent here capped the
            // block at ONE line break: `{.a` + `.b}` worked because the second
            // line matched the CLOSE branch above, and `{.a` + `.b` + `.c}`
            // did not, because `.b` reached this test unindented.
            //
            // `opt_ws` is spaces and tabs, not PCRE `\s` - the charlist this
            // engine keeps getting wrong. The difference is UNOBSERVABLE today,
            // because the payload tokenizer splits on any whitespace and reads
            // a leftover vertical tab as an attribute separator; it is spelled
            // correctly here so a sweep of the indentation strips finds one
            // charlist, not two.
            // A LINE BREAK FALLS BETWEEN ATTRIBUTES, NEVER INSIDE ONE.
            // `attr_separator = (whitespace | continuation), opt_ws` puts the
            // break between two attributes, and `opt_pad` puts it at the ends;
            // no production splits one attribute across lines. So a
            // continuation line is a whole number of attributes, and one that
            // is not a valid attribute list on its own can never become part of
            // a valid block - `# h` never does, whatever follows it.
            //
            // Stopping HERE rather than at the closing brace is also what
            // keeps the widened scan linear, and it is the ONLY bound needed. A
            // `{` line is not a valid attribute list on its own, so a scan
            // always stops at the next block start; a document of `{`-opening
            // block starts therefore cannot re-walk the same run once per
            // start. Without this the same document measured 6.4s at 4,000
            // openers against 0.3s before the widening. A memo of ranges
            // already known to hold no closing line was written first and then
            // removed: with this bound in place nothing could reach it, and no
            // mutation of it could be made to fail.
            //
            // No exception for a quoted value: a line break can never be
            // inside one, because the check at the top of this loop has already
            // refused the block when the payload reached the boundary with a
            // quote open.
            $fragment = ltrim($nextLine, " \t");
            if (!$this->inlineParser->isValidAttrPayload($fragment)) {
                return null;
            }

            $attrContent .= ' ' . $fragment;
            $openQuote = $this->attrPayloadOpenQuote($fragment, $openQuote);
            $i++;
        }

        return null;
    }

    /**
     * Does a WRAPPED block-attribute block open on this line?
     *
     * `{.a` on its own is not a block-attribute line - it becomes one only
     * once a later line closes it - so `isBlockAttributeLine()`, which reads a
     * SINGLE line, answers no for the opener and yes for nothing in the run.
     * Anything that has to classify the line before the block is parsed needs
     * this instead.
     *
     * ASKED BY RUNNING THE REAL MATCHER AND ROLLING BACK, deliberately. The
     * accept condition is a walk with a quoted-value rule, a blank-line rule, a
     * per-line validity rule and a linearity bound, and a predicate that
     * re-spelled any of them would be the second spelling that disagrees - the
     * failure this file keeps recording. {@see self::tryParseBlockAttributes()}
     * writes only the pending-attribute pair, which is saved and restored here,
     * so the probe leaves no trace.
     *
     * Returns how many LINES it spans, so a caller can stay undecided across
     * all of them - the opener alone is not enough. `{.a` / `.b}` / `# H` put
     * the second line back in play, where it read as a paragraph and the
     * heading under it ended the run, dropping the attributes and the block
     * they belonged to.
     *
     * @param array<string> $lines
     * @param int $start
     */
    protected function wrappedBlockAttributeLength(array $lines, int $start): ?int
    {
        if (!str_starts_with($lines[$start] ?? '', '{')) {
            return null;
        }
        if ($this->isBlockAttributeLine($lines[$start])) {
            return null;
        }

        $savedSpan = $this->state->session->pendingAttributeSpan;
        $savedAttributes = $this->state->session->pendingAttributes;
        $savedOrder = $this->state->session->pendingAttributeOrder;
        try {
            return $this->tryParseBlockAttributes($lines, $start);
        } finally {
            $this->state->session->pendingAttributeSpan = $savedSpan;
            $this->state->session->pendingAttributes = $savedAttributes;
            $this->state->session->pendingAttributeOrder = $savedOrder;
        }
    }

    /**
     * Does this line OPEN a wrapped block-attribute block that has not closed on
     * its own line?
     *
     * Section 15 A5 lets a block-attribute block wrap, and one block is one
     * block however many lines it takes. `isBlockAttributeLine()` answers only
     * the single-line form - all a tracker handed one line at a time could see -
     * so a quote body ending `{.k` / `#x}` read as two lines of prose and the
     * braces reached the page (markup-carve/carve#1962). Flush-left only, like
     * `isBlockAttributeLine`: an indented brace is lazy paragraph text under the
     * strict column-0 rule.
     */
    private function opensWrappedAttributeBlock(string $content): bool
    {
        return str_starts_with($content, '{') && !str_contains($content, '}');
    }

    /**
     * Advance a lazy-state tracker's wrapped-attribute run by one line, and say
     * whether that line CLOSED one as real attributes.
     *
     * ALONGSIDE THE CLASSIFIERS, NEVER INSTEAD OF THEM: a `{` with no `}` after
     * it anywhere is not a block at all, and a streaming tracker cannot know
     * which it is until a `}` arrives. So the run only ever OVERRIDES, and only
     * when it closes as real attributes, at which point the container holds no
     * open paragraph (section 15 A5, markup-carve/carve#1962). A blank
     * abandons it: a blank inside an open brace is not a block.
     *
     * The run is collected LINE BY LINE and joined only when a `}` arrives, so a
     * `{` opener followed by many lines that never close stays LINEAR rather
     * than copying the growing run on every line.
     *
     * @param array{mode:\MarkupCarve\Carve\Parser\BlockQuoteLazyMode,fenceChar:string,fenceLength:int,commentLength:int,paragraphOpen:bool,divFenceLength:int,divDepth:int,absorbingFence:bool,inTable:bool,innerDepth:int,attrRun:list<string>|null,hosts?:array{columns:non-empty-list<int>, markers:list<int>, kinds:list<string>, fence:array{char:string, length:int, column:int, base:int}|null, pending:array{char:string, length:int, column:int}|null}} $state Mutated in place.
     * @param string $content
     */
    private function trackWrappedAttributeRun(array &$state, string $content): bool
    {
        if ($state['attrRun'] !== null) {
            if (IndentationHelper::isBlankLine($content)) {
                // A blank inside an open brace is not a block: abandon the run.
                $state['attrRun'] = null;

                return false;
            }
            $state['attrRun'][] = $content;
            if (!str_contains($content, '}')) {
                return false;
            }
            $lines = $state['attrRun'];
            $state['attrRun'] = null;

            return $this->wrappedBlockAttributeLength($lines, 0) === count($lines);
        }
        if ($this->opensWrappedAttributeBlock($content)) {
            $state['attrRun'] = [$content];
        }

        return false;
    }

    /**
     * Settle the attached run's kind for this line, or stay undecided.
     *
     * Shared by the two collectors so the "still pending" bookkeeping - which
     * spans the whole wrapped attribute block, not just its first line - has
     * one spelling.
     *
     * @param string $kind
     * @param int $pendingThrough Last line index still inside a wrapped block, by reference.
     * @param string $line
     * @param array<string> $lines
     * @param int $index
     */
    protected function advanceAttachedKind(
        string $kind,
        int &$pendingThrough,
        string $line,
        array $lines,
        int $index,
    ): string {
        if ($kind !== self::ATTACHED_PENDING || $index <= $pendingThrough) {
            return $kind;
        }

        $wrapped = $this->wrappedBlockAttributeLength($lines, $index);
        if ($wrapped !== null) {
            $pendingThrough = $index + $wrapped - 1;

            return self::ATTACHED_PENDING;
        }

        return $this->attachedBlockKind($line, $lines, $index);
    }

    /**
     * The quote character an attribute payload ends inside, or null.
     *
     * Follows the executable spec's brace scanner - a backslash escapes the
     * next character only while a quote is open - with one deliberate
     * difference: Carve accepts SINGLE-quoted values as well as double-quoted
     * ones (a documented enhancement over djot), so both open a value here and
     * only the matching character closes it. Tracking `"` alone would let
     * `{k='a` + `b'}` through the newline rule that `{k="a` + `b"}` is refused
     * by, and the escape matters for the same reason: read `\\"` as an ordinary
     * closing quote and `{k="a\\" b` looks balanced when it is not.
     */
    private function attrPayloadOpenQuote(string $chunk, ?string $openQuote): ?string
    {
        $length = strlen($chunk);
        for ($k = 0; $k < $length; $k++) {
            $char = $chunk[$k];
            if ($char === '\\' && $openQuote !== null) {
                $k++;

                continue;
            }
            if ($openQuote === null) {
                if ($char === '"' || $char === "'") {
                    $openQuote = $char;
                }

                continue;
            }
            if ($char === $openQuote) {
                $openQuote = null;
            }
        }

        return $openQuote;
    }

    /**
     * Parse attribute string and add to pending attributes
     */
    protected function parseAttributeString(string $attrStr): void
    {
        $parsed = AttributeParser::parseOrderedWithSlots($attrStr);
        if (isset($parsed['attributes']['class'], $this->state->session->pendingAttributes['class'])) {
            $parsed['attributes']['class'] = [...(array)$this->state->session->pendingAttributes['class'], ...(array)$parsed['attributes']['class']];
        }
        $this->state->session->pendingAttributes = array_merge($this->state->session->pendingAttributes, $parsed['attributes']);
        $this->state->session->pendingAttributeOrder = array_merge($this->state->session->pendingAttributeOrder, $parsed['order']);
    }

    /**
     * PART 9 §17 L7: consume the `loose` boolean off a container's preceding
     * block-attribute line.
     *
     * The key is STRUCTURAL and it is CONSUMED - it never reaches the output as
     * an HTML attribute. The precedent is PART 12 §15's `header-rows`, which
     * rides the same line, carries a structural fact as a boolean, and is
     * likewise consumed rather than emitted.
     *
     * It says the container's children render as BLOCKS rather than as inline
     * runs, which reaches the shapes a blank line cannot spell: a ONE-ITEM loose
     * list has no "between items" to put one in, and a definition description
     * holding ONE block has none at any entry count, since a blank line between
     * two ENTRIES does not loosen a `<dl>` at all.
     *
     * THE AXIS EXISTS IN EXACTLY TWO PLACES, so the key applies in exactly two.
     * On a block quote, a div or anything else the name has no meaning at all
     * and renders `loose=""` like any other boolean - the clause adds a meaning
     * where there is one and reserves the name nowhere else.
     *
     * A BOOLEAN AND AN EMPTY VALUE ARE ONE KEY (PART 4), so `{loose}` and
     * `{loose=""}` both arrive here as `''` and both are consumed. `loose=x`
     * names a value this key does not take, so it stays an ordinary attribute
     * and renders `loose="x"`. There is no error state.
     *
     * REDUNDANT USE IS A NO-OP: on a list the blank lines already loosened, and
     * on a description that already holds two blocks, this changes nothing.
     */
    protected function consumeLooseKey(ListBlock|DefinitionList $node): void
    {
        if ($node->getAttribute('loose') !== '') {
            return;
        }

        $node->removeAttribute('loose');
        $node->setAttributeOrder(array_values(array_filter(
            $node->getAttributeOrder(),
            static fn (string $slot): bool => $slot !== 'loose',
        )));

        if ($node instanceof ListBlock) {
            $node->setTight(false);

            return;
        }
        $node->setLoose(true);
    }

    /**
     * Apply pending attributes to a node and clear them
     */
    protected function applyPendingAttributes(Node $node): void
    {
        if ($this->state->session->pendingAttributes !== []) {
            $node->setAttributesWithOrder($this->state->session->pendingAttributes, $this->state->session->pendingAttributeOrder);
            $this->state->session->pendingAttributes = [];
            $this->state->session->pendingAttributeSpan = null;
            $this->state->session->pendingAttributeOrder = [];
        }
    }

    private function applyTableColumns(Table $table): void
    {
        $widest = 0;
        foreach ($table->getChildren() as $row) {
            if ($row instanceof TableRow) {
                $widest = max($widest, count($row->getChildren()));
            }
        }
        $columns = array_fill(0, $widest, []);
        $fields = [
            'aligns' => ['field' => 'align', 'allowed' => ['left', 'right', 'center']],
            'valigns' => ['field' => 'valign', 'allowed' => ['top', 'middle', 'bottom']],
        ];
        foreach ($fields as $key => $definition) {
            $raw = $table->getAttribute($key);
            if (!is_string($raw)) {
                continue;
            }
            foreach (explode(',', $raw) as $index => $value) {
                $value = trim($value);
                if ($index < $widest && in_array($value, $definition['allowed'], true)) {
                    $columns[$index][$definition['field']] = $value;
                }
            }
        }
        $rawWidths = $table->getAttribute('widths');
        if (is_string($rawWidths)) {
            foreach (explode(',', $rawWidths) as $index => $value) {
                $width = is_numeric(trim($value)) ? (float)trim($value) : 0.0;
                if ($index < $widest && $width > 0.0 && $width <= 100.0) {
                    $columns[$index]['width'] = $width / 100.0;
                }
            }
        }
        if (array_filter($columns) !== []) {
            $table->setColumns($columns);
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
     * @return array<string, string|list<string>> The pending attributes (empty array if none)
     */
    public function consumePendingAttributes(): array
    {
        $attrs = $this->state->session->pendingAttributes;
        $this->state->session->pendingAttributes = [];
        $this->state->session->pendingAttributeSpan = null;
        $this->state->session->pendingAttributeOrder = [];

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

        $payload = [];
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

            // A blank LAST line here is real content: the phantom element a
            // terminal newline leaves behind is dropped in splitLines(), where
            // the string is known to be a whole document. Refusing it here
            // refused the genuine blank at the end of a container body too.

            // Remove indent from content lines (up to the same amount as opening fence)
            $currentLine = $this->fencedBlockParser->removeIndent($currentLine, $indentLen);
            $currentLine = self::stripLazyFrame($currentLine);

            $payload[] = $currentLine;
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

        $content = CodePayload::content($payload, $closed || $this->lineOwnsBreak($i - 1));

        $codeBlock = new CodeBlock($content, $language, $label, $header);
        $this->applyPendingAttributes($codeBlock);
        // The opener "header" becomes the <pre> title attribute (rendering A),
        // unless a preceding {title=...} block-attribute line already set one
        // (the explicit attribute channel wins).
        if ($header !== null && !$codeBlock->hasAttribute('title')) {
            // Synthesized from the fence opener, so it takes no `order` slot -
            // there is no attribute block it appeared in (carve#785).
            $codeBlock->setSynthesizedAttribute('title', $header);
        }
        $parent->appendChild($codeBlock);

        return $i - $start;
    }

    /**
     * Whether the document line at container-relative `$line` ends with a break.
     *
     * Only the document's own last line can lack one, and only when the source
     * does not end with a newline. A code fence that runs to EOF there keeps its
     * last payload line unterminated rather than inventing a break
     * (`CARVE-P12-064`).
     */
    protected function lineOwnsBreak(int $line): bool
    {
        if ($this->state->source->normalizedSource === '' || str_ends_with($this->state->source->normalizedSource, "\n")) {
            return true;
        }

        return $this->sourceLineFor($line) !== count($this->state->source->sourceLines) - 1;
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
        if (str_starts_with(ltrim($line, " \t"), '%%')) {
            $content = substr(ltrim($line, " \t"), 2);
            if ($content !== '' && ($content[0] === ' ' || $content[0] === "\t")) {
                $content = substr($content, 1);
            }
            $comment = new Comment(rtrim($content, " \t"));
            $sourceLine = $this->sourceLineFor($start);
            $sourceText = $this->state->source->sourceLines[$sourceLine] ?? '';
            // A comment is a LEAF, so its span begins at the `%` markup, not in
            // the leading indentation or a container marker it follows - the
            // latitude a container keeps was withdrawn from leaves by
            // markup-carve/carve#1928.
            $markerColumn = $this->commentMarkerColumn($sourceText, $line);
            $comment->setPos($this->spanForLineMap([$sourceLine], $markerColumn));
            $parent->appendChild($comment);

            return 1;
        }

        return null;
    }

    /**
     * The column the comment markup opens on, measured in bytes from the source
     * line start.
     *
     * A comment is a leaf, so its span begins at the `%` - past any leading
     * indentation AND any container marker it sits behind (`- `, `> `, `: `),
     * not at column 1 (markup-carve/carve#1928). The parser sees the comment
     * line with its container prefix already cut off, and that stripped line is
     * a SUFFIX of the source line, so the prefix width plus the stripped line's
     * own leading run is where the `%%` opens. A whole-line `strpos('%%')`
     * would instead match a `%%` inside the prefix - a `[^%%]:` footnote label -
     * and start the span there.
     */
    private function commentMarkerColumn(string $sourceText, string $strippedLine): int
    {
        $prefix = strlen($sourceText) - strlen($strippedLine);
        if ($prefix >= 0 && substr($sourceText, $prefix) === $strippedLine) {
            return $prefix + (strlen($strippedLine) - strlen(ltrim($strippedLine, " \t")));
        }

        // The line was rewritten rather than merely un-prefixed (the suffix
        // relation the rest of this file trusts does not hold), so the prefix
        // width is unknown - fall back to the source line's own leading run,
        // never worse than the whole-line default this replaced.
        return strlen($sourceText) - strlen(ltrim($sourceText, " \t"));
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

        // The body keeps its bytes: blank and whitespace-only lines, leading or
        // trailing, are content (markup-carve/carve-php#2506).
        $content = implode("\n", $contentLines);

        // Comments are stored but not rendered
        $comment = new Comment($content, $fenceLength);
        $sourceLine = $this->sourceLineFor($start);
        $sourceText = $this->state->source->sourceLines[$sourceLine] ?? '';
        // A comment fence is a LEAF too: its span begins at the opening `%` run,
        // not in the leading indentation or a container marker it follows
        // (markup-carve/carve#1928).
        $markerColumn = $this->commentMarkerColumn($sourceText, $line);
        $lastSourceLine = $this->sourceLineFor(max($start, $i - 1));
        $comment->setPos($this->spanForLineMap([$sourceLine, $lastSourceLine], $markerColumn));
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

            $content .= self::stripLazyFrame($currentLine) . "\n";
            $i++;
        }

        if (!$closed) {
            $this->addWarning('Unclosed raw block', $start, 1, true);
        }

        // The last newline is the structural separator before the closing
        // fence. Remove exactly that one, not the whole trailing run: any
        // earlier newline represents an authored blank payload line and is
        // part of raw_block.content (carve#1414, corpus 366).
        if (str_ends_with($content, "\n") && trim($content, "\n") !== '') {
            $content = substr($content, 0, -1);
        }

        $rawBlock = new RawBlock($content, $format);
        $this->applyPendingAttributes($rawBlock);
        $parent->appendChild($rawBlock);

        return $i - $start;
    }

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

        // PART 9 §4c (markup-carve/carve#1122): a BARE `::: figure` opener -
        // the kind word and nothing else - is a composite figure, not a
        // container. An opener carrying a quoted title or a `[label]` does NOT
        // match the figure production and stays a generic Tier-2 div, title
        // and label preserved losslessly; and GROUPS DO NOT NEST - a bare
        // figure opener anywhere inside an open group's body, any depth, is a
        // generic container too.
        if (
            $className === 'figure'
            && $title === null
            && $label === null
            && $this->figureGroupDepth === 0
        ) {
            return $this->parseFigureGroup($parent, $lines, $start, $fenceLength);
        }

        $div = new Div();

        // Keep the label source for group extensions and its inline nodes for
        // the fallback caption. Only a comment in the outer run cuts the source.
        if ($label !== null) {
            // READ WITH THE DOCUMENT'S OWN INLINE PARSER. A container label is an
            // inline run (`CARVE-P9-041`, ruled on markup-carve/carve#2572), so an
            // extension-registered construct has to read the same inside a label
            // as outside one.
            $commentOffset = null;
            $labelNodes = ContainerLabelParser::parse($label, $this->inlineParser, $commentOffset);
            $div->setLabel($commentOffset === null ? $label : rtrim(substr($label, 0, $commentOffset), " \t"));
            $div->setLabelNodes($labelNodes);
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
                $this->state->session->lineOffset + $start,
                sourceMap: $this->openerTitleMap($start, $title),
            );
            $div->setHeaderNodes($headerContainer->getChildren());
        }
        // Author source order, in the Node's canonical slot form (`#id` / `.class`
        // / key), taken from the pending attribute insertion order.
        $authorOrder = [];
        foreach (array_keys($this->state->session->pendingAttributes) as $name) {
            $authorOrder[] = $name === 'id' ? '#id' : ($name === 'class' ? '.class' : (string)$name);
        }
        $this->applyPendingContainerAttributes($div);
        // Storage stays class-first (the type class leads; the core renderer
        // emits it that way). But the type class polluted the recorded order,
        // so restore the author's SOURCE order for extensions and fmt (#304).
        // A type class with no authored class slot is not added to the order;
        // the extension serializer appends it after the ordered attributes.
        $div->setAttributeOrder($authorOrder);
        $this->state->session->pendingAttributes = [];
        $this->state->session->pendingAttributeSpan = null;
        $this->state->session->pendingAttributeOrder = [];

        $body = $this->collectColonFenceBody($lines, $start, $fenceLength, true);
        $innerLines = $body['lines'];
        $innerLineMap = $body['lineMap'];
        $i = $start + $body['consumed'];

        // Parse inner content as blocks (track line offset for nested content)
        $previousOffset = $this->state->session->lineOffset;
        $this->state->session->lineOffset = $previousOffset + $start + 1;
        $this->parseBlocks($div, $innerLines, 0, $innerLineMap);
        // A dangling attribute line belongs to this container and dies at its
        // boundary. Letting the pending state escape attached it to the next
        // outer block (carve#1028).
        $this->endContainerAttributeScope();
        $this->state->session->lineOffset = $previousOffset;

        // (Pending block attributes were already applied before the
        // opener's own attributes above, per PART 9 §15 precedence.)
        $parent->appendChild($div);

        return $i - $start;
    }

    /**
     * Whether the parser is currently inside a `::: figure` composite-figure
     * body. Groups do not nest (PART 9 §4c): while this is non-zero a bare
     * figure opener at ANY depth builds a generic container instead.
     */
    protected int $figureGroupDepth = 0;

    private function applyPendingContainerAttributes(Node $node): void
    {
        foreach ($this->state->session->pendingAttributes as $name => $value) {
            if ($name === 'class') {
                foreach ((array)$value as $class) {
                    $node->appendClass($class);
                }
            } else {
                $node->setAttribute($name, $value);
            }
        }
    }

    /**
     * Parse a bare `::: figure` fence into a FigureGroup (PART 9 §4c).
     *
     * The body parses under the unchanged inner rules - the existing caption
     * pass already forms the Figure panels inside - and the GROUP caption is
     * not consumed here: the `^ ` line after the closing fence reaches
     * tryParseCaption() like any other caption slot, which is what gives it
     * the shared one-blank-line allowance for free.
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     * @param int $fenceLength
     */
    protected function parseFigureGroup(Node $parent, array $lines, int $start, int $fenceLength): int
    {
        $group = new FigureGroup();

        // Leading block-attribute lines are the group's only attribute source
        // (the opener is bare by definition); author source order is recorded
        // for the formatter, exactly as for a div.
        $authorOrder = [];
        foreach (array_keys($this->state->session->pendingAttributes) as $name) {
            $authorOrder[] = $name === 'id' ? '#id' : ($name === 'class' ? '.class' : (string)$name);
        }
        $this->applyPendingContainerAttributes($group);
        $group->setAttributeOrder($authorOrder);
        $this->state->session->pendingAttributes = [];
        $this->state->session->pendingAttributeSpan = null;
        $this->state->session->pendingAttributeOrder = [];

        $body = $this->collectColonFenceBody($lines, $start, $fenceLength, true);
        $innerLines = $body['lines'];
        $innerLineMap = $body['lineMap'];
        $i = $start + $body['consumed'];

        $previousOffset = $this->state->session->lineOffset;
        $this->state->session->lineOffset = $previousOffset + $start + 1;
        $this->figureGroupDepth++;
        try {
            $this->parseBlocks($group, $innerLines, 0, $innerLineMap);
        } finally {
            $this->figureGroupDepth--;
        }
        // A dangling attribute line belongs to this container and dies at its
        // boundary, exactly as in tryParseDiv() (carve#1028).
        $this->endContainerAttributeScope();
        $this->state->session->lineOffset = $previousOffset;

        $parent->appendChild($group);

        return $i - $start;
    }

    /**
     * Try to parse a fenced block quote: a colon fence whose type token is a
     * bare `>`. A second SPELLING of the block quote, not a second block - the
     * body is ordinary block content and the node is the one a `>` prefix
     * produces, with `fenced` recording which spelling was authored so the
     * canonical writer can write it back (markup-carve/carve#1718).
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseQuoteBlock(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];
        // The separator is a SPACE, as it is for the line block and the
        // hard-break block: the marker after it selects the block, so PART 7
        // makes the slot a marker separator rather than padding.
        if (preg_match('/^(?<fence>:{3,}) +>[ \t]*$/', $line, $matches) !== 1) {
            return null;
        }

        $fenceLength = strlen($matches['fence']);
        $quote = new BlockQuote();
        $quote->setFenced(true);
        $this->applyPendingAttributes($quote);

        $body = $this->collectColonFenceBody($lines, $start, $fenceLength, true);
        $innerLines = $body['lines'];
        $innerLineMap = $body['lineMap'];
        $i = $start + $body['consumed'];

        $previousOffset = $this->state->session->lineOffset;
        $this->state->session->lineOffset = $previousOffset + $start + 1;
        $this->parseBlocks($quote, $innerLines, 0, $innerLineMap);
        $this->endContainerAttributeScope();
        $this->state->session->lineOffset = $previousOffset;

        $parent->appendChild($quote);

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
        // The separator is a SPACE, like every other colon-fence opener: the
        // backslash after it is what selects this block, so PART 7 makes the
        // slot a marker separator rather than padding (carve-php#941).
        if (preg_match('/^(?<fence>:{3,}) +\\\\[ \t]*$/', $line, $matches) !== 1) {
            return null;
        }

        $fenceLength = strlen($matches['fence']);
        $div = new Div();
        $div->addClass('hardbreaks');
        // The attribute line is authored BEFORE the sigil fence. Merge it
        // through the same leading-attribute path as other blocks so its class
        // values and attrs.order stay ahead of the structural `hardbreaks`
        // class. Adding the values one by one reversed both facts.
        $div->mergeLeadingAttributes($this->state->session->pendingAttributes, $this->state->session->pendingAttributeOrder);
        $this->state->session->pendingAttributes = [];
        $this->state->session->pendingAttributeSpan = null;
        $this->state->session->pendingAttributeOrder = [];

        $body = $this->collectColonFenceBody($lines, $start, $fenceLength, true);
        $innerLines = $body['lines'];
        $innerLineMap = $body['lineMap'];
        $i = $start + $body['consumed'];

        $previousOffset = $this->state->session->lineOffset;
        $this->state->session->lineOffset = $previousOffset + $start + 1;
        $this->parseBlocks($div, $innerLines, 0, $innerLineMap);
        $this->endContainerAttributeScope();
        $this->state->session->lineOffset = $previousOffset;

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
     * fences as a stack. All bodies skip closed verbatim/comment spans before
     * considering a colon closer.
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
            $skippedTo = $this->appendOpaqueColonFenceSpan($lines, $i, $innerLines, $innerLineMap);
            if ($skippedTo !== null) {
                $i = $skippedTo;

                continue;
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
        if (preg_match('/^(:+)[ \t]*$/', $line, $m) !== 1 || strlen($m[1]) < 3) {
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
        if (
            $this->fencedBlockParser::class === FencedBlockParser::class
            && !$this->codeCloserPossible($this->fenceCloserIndex($lines)['code'], $fenceChar, $fenceLength, $openIndex)
        ) {
            return false;
        }

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
        // heading: the class only requires one non-`whitespace` char after the
        // space, and `whitespace` is a space or a tab and nothing else (PART 1)
        // - a lone NBSP, VERTICAL TAB or FORM FEED is content, so `# ` followed
        // by one of them IS a heading. This gate is the reason the trailing trim
        // below cannot narrow on its own (markup-carve/carve-php#1038).
        if (!preg_match('/^(#{1,6}) +(.*' . StringUtil::NON_WHITESPACE_CLASS . '.*)$/', $line, $matches)) {
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

        $content = rtrim($content, " \t");

        // One source segment for the heading's single line.
        $headingLines = [];
        foreach ($foldedLines as [$foldIndex, $foldText]) {
            $foldSourceLine = $this->sourceLineFor($foldIndex);
            $foldColumn = $foldSourceLine < 0
                ? false
                : strpos($this->state->source->sourceLines[$foldSourceLine] ?? '', $foldText);
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

    private function blockQuoteLineContent(string $line): ?string
    {
        return $this->quotesBuilder()->blockQuoteLineContent($line);
    }

    /**
     * Return the index of the last line owned by the quote at `$start`.
     *
     * The quote takes every further `>` line, and a line WITHOUT one only as a
     * lazy continuation - which needs an open paragraph and nothing else
     * (PART 1 S4, markup-carve/carve-php#1897).
     *
     * Use the quote parser's tracker so a closed fence releases the next line
     * to the enclosing item's block classification.
     *
     * @param array<string> $lines
     * @param int $start
     */
    protected function blockQuoteLazyExtentEnd(array $lines, int $start): int
    {
        return $this->quotesBuilder()->blockQuoteLazyExtentEnd($lines, $start);
    }

        /**
         * @param array<string> $lines
         * @param int $start
         */
    private function blockQuoteExtentThroughDefinition(array $lines, int $start): int
    {
        return $this->quotesBuilder()->blockQuoteExtentThroughDefinition($lines, $start);
    }

    /**
     * The verbatim fence open after this quoted line, given the one open before
     * it: `[char, length]` while a fence is open, null otherwise.
     *
     * @param string $line
     * @param array{0: string, 1: int}|null $open
     *
     * @return array{0: string, 1: int}|null
     */
    protected function quotedFenceOpenedBy(string $line, ?array $open): ?array
    {
        return $this->quotesBuilder()->quotedFenceOpenedBy($line, $open);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseBlockQuote(Node $parent, array $lines, int $start): ?int
    {
        return $this->quotesBuilder()->tryParseBlockQuote($parent, $lines, $start);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Block\ListBlock $list
     * @param array<string> $lines
     * @param int $i
     * @param int $count
     * @param int $baseIndent
     */
    private function attachListContinuation(ListBlock $list, array $lines, int $i, int $count, int $baseIndent): ?int
    {
        $lastItem = $this->listParser->getLastListItem($list);
        if ($lastItem === null) {
            return null;
        }

        [$next, $attached, $lineMap] = $this->collectListContinuationBlock($lines, $i + 1, $count, $baseIndent);
        if ($attached !== []) {
            $this->parseItemBlocks($lastItem, $attached, $lineMap);
        }

        return $next;
    }

    /**
     * @param array<string> $lines
     * @param int $i
     * @param int $baseIndent The item's marker column.
     * @param int $contentIndent
     */
    private function indentedContinuationOpensBlock(
        array $lines,
        int $i,
        int $baseIndent,
        int $contentIndent,
    ): bool {
        $line = IndentationHelper::stripLeadingColumns($lines[$i], $contentIndent);
        $trimmed = ltrim($line, " \t");
        if (
            $trimmed !== $line
            && $this->listParser->parseListItemMarker($trimmed) === null
            && $this->lineOpensBlockForLooseness($trimmed)
        ) {
            $line = $trimmed;
        }

        if (!$this->lineOpensBlockForLooseness($line)) {
            return false;
        }

        // Invisible lines do not separate two paragraphs. A `%%%` span is one of
        // them, and the scan starts past its CLOSER: its payload is still not
        // mistaken for the second paragraph, and the paragraph behind the closer
        // now reaches the question (corpus 517, document 4). An opener with no
        // closer has no line behind it - every line below is payload - so the
        // scan starts past the end and the list stays tight, which is what the
        // oracle reads for that shape at either column.
        if ($this->isInvisibleOrAttributeLine($line)) {
            $commentFence = $this->fencedBlockParser->parseFencedCommentOpener($line);
            $from = $commentFence === null
                ? $i
                : $this->commentSpanEndForLooseness($lines, $i, $commentFence['length'], $baseIndent, $contentIndent);
            $next = $this->firstVisibleLineAfterInvisible($lines, $from, $baseIndent, $contentIndent);

            return $next === null || $this->lineOpensBlockForLooseness($next);
        }

        return true;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseList(Node $parent, array $lines, int $start): ?int
    {
        return $this->listsBuilder()->tryParseList($parent, $lines, $start);
    }

    /**
     * Parse ONE CHUNK of a list item's block stream.
     *
     * An item's body is not always a single stream: the continuation collector
     * stops at a nested marker reaching the item's content column so the list
     * parser can own the sub-list, which splits the same item across two calls
     * here. So a chunk end is NOT an item end, and the pending-attribute run
     * survives it - see endContainerAttributeScope() for where the run really
     * ends.
     *
     * @param \MarkupCarve\Carve\Node\Node $item
     * @param array<string> $lines
     * @param array<int, int>|null $lineMap
     * @param int|null $leadNestedColumn
     * @param array<int, true>|null $authoredBaseEligible
     */
    protected function parseItemBlocks(
        Node $item,
        array $lines,
        ?array $lineMap = null,
        ?array $authoredBaseEligible = null,
        ?int $leadNestedColumn = null,
    ): void {
        $this->listsBuilder()->parseItemBlocks($item, $lines, $lineMap, $authoredBaseEligible, $leadNestedColumn);
    }

    /**
     * Would a container body's rebase pass MOVE any line of `$rendered`?
     *
     * @param string $rendered
     *
     * @return bool
     */
    public function bodyRebaseWouldMoveALine(string $rendered): bool
    {
        return $this->listsBuilder()->bodyRebaseWouldMoveALine($rendered);
    }

        /**
         * @param array<string> $lines
         * @param array<int, true>|null $eligible
         * @param int|null $leadNestedColumn
         * @param bool $includeSublists
         * @param bool $skipOpaqueAtMinimum
         * @param bool $skipOnlyClosedOpaqueAtMinimum
         * @param bool $absorbLeadNoteBody Let a note at the start of a collected
         *
         * @return array<string>
         */
    private function rebaseOverindentedItemBlocks(
        array $lines,
        ?array $eligible = null,
        ?int $leadNestedColumn = null,
        bool $includeSublists = false,
        bool $skipOpaqueAtMinimum = true,
        bool $skipOnlyClosedOpaqueAtMinimum = false,
        bool $absorbLeadNoteBody = false,
    ): array {
        return $this->listsBuilder()->rebaseOverindentedItemBlocks($lines, $eligible, $leadNestedColumn, $includeSublists, $skipOpaqueAtMinimum, $skipOnlyClosedOpaqueAtMinimum, $absorbLeadNoteBody);
    }

    private function endContainerAttributeScope(): void
    {
        $this->listsBuilder()->endContainerAttributeScope();
    }

    /**
     * Is the bottom block of a marker line's content a lone `+`?
     *
     * `* +` is the outer item's content and the CONTINUATION MARKER one level
     * in, exactly as `- - # H` is a heading two levels in. The peel is what
     * PART 1 S4 asks for - a list item is decided by the block inside it - and
     * this is the same question {@see self::isContinuationMarker()} answers for
     * a line that carries no marker of its own.
     */
    protected function leadBottomIsContinuationMarker(string $content): bool
    {
        $rest = $content;
        while (($offset = $this->listParser->markerContentOffset($rest)) !== null) {
            $rest = substr($rest, $offset);
        }

        return $rest === ltrim($rest, " \t") && $this->isContinuationMarker($rest);
    }

    /**
     * Does the marker lead's bottom block open a code, raw, or verse fence?
     *
     * Asked of the same text {@see self::leadBottomIsContinuationMarker()} asks
     * of - the lead with every nested marker peeled off - because that is the
     * block the lines below the column would join. `- ``` x` opens a fence for
     * the INNER item, and a fence test anchored at column 0 of the whole lead
     * cannot see it past the marker.
     *
     * No closer lookahead: a fence at an item's block start opens
     * unconditionally and runs to the end of its container.
     */
    protected function leadBottomOpensFence(string $content): bool
    {
        $rest = $content;
        while (($offset = $this->listParser->markerContentOffset($rest)) !== null) {
            $rest = substr($rest, $offset);
        }

        if ($rest !== ltrim($rest, " \t")) {
            return false;
        }

        return $this->fencedBlockParser->parseCodeFenceOpener($rest) !== null
            || $this->fencedBlockParser->parseRawBlockOpener($rest) !== null
            || $this->parseLineBlockOpener($rest) !== null;
    }

    /**
     * The line with a {@see self::LAZY_FRAME} removed, if it carries one.
     */
    protected static function stripLazyFrame(string $line): string
    {
        return str_starts_with($line, self::LAZY_FRAME)
            ? substr($line, strlen(self::LAZY_FRAME))
            : $line;
    }

    /**
     * Did a continuation marker attach nothing BECAUSE OF THE COLUMN RULE?
     *
     * Distinguishes the two ways an attach comes back empty. Nothing following
     * at all leaves an empty first-block item, which is a document in its own
     * right; a follower at some other column means the marker reached past a
     * line that belongs to whichever container its own column names (SS17 L3,
     * carve#1436), and the ordinary collector has to be given the chance to
     * claim it.
     *
     * @param int $index
     * @param int $count
     * @param array<string> $lines
     */
    protected function continuationMarkerHasIndentedFollower(int $index, int $count, array $lines): bool
    {
        if ($index >= $count) {
            return false;
        }
        $line = $lines[$index] ?? null;
        if ($line === null || IndentationHelper::isBlankLine($line)) {
            return false;
        }

        return !$this->continuationAttachesAtColumnZero($index);
    }

    protected function continuationAttachesAtColumnZero(int $index): bool
    {
        $sourceLine = $this->sourceLineFor($index);
        if ($sourceLine < 0) {
            return true;
        }
        $line = $this->state->source->sourceLines[$sourceLine] ?? null;
        if ($line === null) {
            return true;
        }
        $rest = $line;
        while (preg_match('/^[ \t]*>[ \t]?/', $rest, $m) === 1) {
            $rest = substr($rest, strlen($m[0]));
        }

        return $rest === ltrim($rest, " \t");
    }

    protected function isContinuationMarker(string $line): bool
    {
        return rtrim($line, StringUtil::WHITESPACE_CHARS) === '+';
    }

    /**
     * Does the `+` on view line $index sit at $column in the SOURCE?
     *
     * The quote's lines arrive already stripped of the enclosing item's
     * indentation, so a marker written one column left of the quote's own
     * marker is spelled exactly like one written at it (CARVE-P9-031,
     * carve-php#2470). Only the source line still carries the distinction.
     * A line the source never had keeps the old, column-blind answer.
     */
    protected function markerSitsAtColumn(int $index, int $column): bool
    {
        $sourceLine = $this->sourceLineFor($index);
        $sourceText = $this->state->source->sourceLines[$sourceLine] ?? null;
        if ($sourceText === null) {
            return true;
        }

        return IndentationHelper::getLeadingColumns($sourceText) === $column;
    }

    /**
     * @param array<string> $lines
     *
     * @return array{comment: array<int, int>, colon: array<int, int>, code: array<string, array{runs: array<int, int>, lastAtLeast: array<int, int>}>}
     */
    private function fenceCloserIndex(array $lines): array
    {
        return $this->continuationsMapper()->fenceCloserIndex($lines);
    }

    /**
     * @param array<string, array{runs: array<int, int>, lastAtLeast: array<int, int>}> $index
     * @param int $after
     * @param int $length
     * @param string $char
     */
    private function codeCloserPossible(array $index, string $char, int $length, int $after): bool
    {
        return $this->continuationsMapper()->codeCloserPossible($index, $char, $length, $after);
    }

    /**
     * @param array<string> $lines
     * @param int $i Index of the first line after the `+` marker.
     * @param int $count Total line count.
     * @param (callable(string): bool)|null $endsAtSibling Names a sibling of this container, or null.
     *
     * @return array{0: int, 1: array<string>, 2: array<int>}
     */
    private function attachedFlushLeftBlock(array $lines, int $i, int $count, ?callable $endsAtSibling = null): array
    {
        return $this->continuationsMapper()->attachedFlushLeftBlock($lines, $i, $count, $endsAtSibling);
    }

    /**
     * @param array<string> $lines
     * @param callable|null $transform
     * @param callable $isBoundary
     * @param int $count
     * @param int $i
     *
     * @return array{0: int, 1: array<string>, 2: array<int, int>}
     */
    private function collectAttachedBlock(array $lines, int $i, int $count, callable $isBoundary, ?callable $transform = null): array
    {
        return $this->continuationsMapper()->collectAttachedBlock($lines, $i, $count, $isBoundary, $transform);
    }

    /**
     * Advance a list item's own comment-fence tracker over ONE collected line.
     *
     * Null means no comment fence is open; an int is the EXACT delimiter width
     * that closes the open one, because a longer opener nests shorter fences
     * (PART 9 §28).
     *
     * AN OPENER WITH NO CLOSER AHEAD OPENS NOTHING and must not latch this
     * tracker: §28 gives it no block, and latching it would run the item to end
     * of input. That is the whole reason this lives beside
     * `advanceTrailingBlockState()` instead of inside it - the shared tracker
     * sees one line and cannot ask the question. `lastCommentFenceIndex()`
     * answers it from a width -> last-index map built once per line set, so a
     * document full of unclosable openers with DISTINCT widths costs one pass
     * rather than one scan per opener.
     *
     * @param int|null $openLength The width currently open, or null.
     * @param string $line The collected line, already dedented.
     * @param array<string> $lines The raw line set, for the closer lookahead.
     * @param int $index The RAW index this line sits at.
     */
    protected function advanceItemCommentFence(?int $openLength, string $line, array $lines, int $index): ?int
    {
        if ($openLength !== null) {
            return $this->fencedBlockParser->isFencedCommentCloserAnyColumn($line, $openLength) ? null : $openLength;
        }

        $info = $this->fencedBlockParser->parseFencedCommentOpenerAnyColumn($line);
        // STRICTLY AFTER. The opener is itself a line of its own width, so a
        // `>=` here lets it count as its own closer and every unterminated
        // `%%% x` opens a span that runs to end of input - which is the exact
        // latch this lookahead exists to prevent.
        if ($info === null || $this->lastCommentFenceIndex($lines, $info['length']) <= $index) {
            return null;
        }

        return $info['length'];
    }

    /**
     * The content column of the definition body an item's collected run is
     * currently inside, or null when it is inside none.
     *
     * @param int|null $openColumn
     * @param string $line
     */
    protected function advanceItemDefinitionBody(?int $openColumn, string $line): ?int
    {
        // A NEW BODY REPLACES THE OPEN ONE. Its separator sets a fresh column,
        // so an entry whose two bodies are written apart does not measure the
        // second against the first.
        if (preg_match(self::DEFINITION_BODY_PATTERN, $line, $m) === 1) {
            return self::DEFINITION_MARKER_WIDTH + strlen($m[1]);
        }

        if ($openColumn === null) {
            return null;
        }

        // A TERM ENDS THE BODY ABOVE IT. The definition list stays open, but
        // the entry it opens has no body yet, so there is no body column for a
        // following blank to sit inside.
        if (preg_match(self::DEFINITION_TERM_LINE_PREFIX, $line) === 1) {
            return null;
        }

        if (IndentationHelper::isBlankLine($line)) {
            return $openColumn;
        }

        return IndentationHelper::getLeadingColumns($line, $openColumn) >= $openColumn
            ? $openColumn
            : null;
    }

    /**
     * Does the definition body open at `$openColumn` continue BELOW this blank?
     *
     * Asked at the blank rather than after it, because whether the blank is
     * INSIDE the body or AFTER it is decided by the immediate next line. A
     * second blank ends the body. Inside, its run carries across (PART 1 S4);
     * after, the blank ends the item's run exactly as it did before there was
     * a body to ask about - and it is that second answer §17 L1 needs, because
     * a blank separating two of the ITEM's blocks loosens the list.
     *
     * Getting this wrong in the permissive direction is not a near-miss: it
     * turns every below-column payload from a loose item's second block into a
     * tight item's lazy continuation, which is a change to documents this
     * ticket is not about.
     *
     * @param array<string> $lines
     * @param int $index Index of the blank line.
     * @param int $count
     * @param int $contentIndent The item's content column, which the body's own
     *   column is measured from.
     * @param int $openColumn
     */
    protected function definitionBodyContinuesPastBlank(
        array $lines,
        int $index,
        int $count,
        int $contentIndent,
        int $openColumn,
    ): bool {
        $after = $index + 1 < $count ? $lines[$index + 1] : null;
        if ($after === null || IndentationHelper::isBlankLine($after)) {
            return false;
        }

        $bodyColumn = $contentIndent + $openColumn;

        return IndentationHelper::getLeadingColumns($after, $bodyColumn) >= $bodyColumn;
    }

    /**
     * The attached run's block KIND, once its first visible line is known.
     *
     * Three answers, because §17 L3's boundary needs exactly three and not a
     * per-construct table:
     *
     *  - `self::ATTACHED_PENDING` - nothing visible yet. An ATTRIBUTE LINE, a
     *    comment or a definition leaves the run here: none of them is a block
     *    §17 L3 could be counting, and an attribute is the leading edge of the
     *    block still to come (corpus 325, `+` / `{.x}` / `> q` attaches the
     *    quote WITH its attribute).
     *  - `self::ATTACHED_PARAGRAPH` - anything that opens no block. Its extent
     *    is §10's: it runs until an INTERRUPTING line.
     *  - `self::ATTACHED_SPANNING` - a quote, a list, a table, a fenced body.
     *    Each has a multi-line extent of its own, and the collectors' existing
     *    boundary tests already end it (a dedent, a sibling marker, another
     *    `+`, a blank). Asking anything more of it cut a table between its rows
     *    (corpus 88-3) and a quote between its lines (corpus 327-4).
     *
     * @param string $line
     * @param array<string> $lines
     * @param int $index
     */
    protected function attachedBlockKind(string $line, array $lines, int $index): string
    {
        if ($this->isInvisibleOrAttributeLine($line)) {
            return self::ATTACHED_PENDING;
        }
        // A REGISTERED MATCHER'S BLOCK IS SPANNING, whatever it looks like.
        // `isBlockElementStart()` knows the BUILT-IN openers only, so a block
        // added through `addBlockPattern()` / `addBlockMatcher()` classified as
        // a paragraph and the run then ended on the first block-shaped line in
        // its body - the matcher was handed its opener alone and never fired.
        // An extension's block has an extent this file cannot compute, so it is
        // left to the collectors' own container boundaries, exactly as it was
        // before there was an extent test at all.
        if ($this->blockMatchers !== [] && $this->matchesRegisteredBlockOpener($lines, $index)) {
            return self::ATTACHED_SPANNING . ':extension';
        }

        if (!$this->isBlockElementStart($line, $lines, $index)) {
            return self::ATTACHED_PARAGRAPH;
        }

        return self::ATTACHED_SPANNING . ':' . $this->spanningConstruct($line);
    }

    /**
     * Which multi-line construct a block-opening line belongs to.
     *
     * The tag exists to answer ONE question - "is this line more of the block
     * already attached, or the start of a different one?" - so it is as coarse
     * as that question needs and no finer. A quote's second `>` line, a table's
     * second row and a list's second marker are all block-opening lines by
     * every predicate this file has, and all three CONTINUE the block above
     * them rather than beginning a second one.
     *
     * The empty string is "not one of these", which is what a heading, a
     * thematic break or ordinary prose gets - none of them can continue
     * anything, so none of them ever matches an attached construct.
     *
     * @param string $line
     */
    protected function spanningConstruct(string $line): string
    {
        if ($this->blockQuoteLineContent($line) !== null) {
            return 'quote';
        }
        if ($this->listParser->parseListItemMarker($line) !== null) {
            return 'list';
        }
        // A CONTINUATION ROW IS MORE TABLE, not a new one. `isTableRow()` reads
        // only the ordinary `|`-led form, so `+ c | d |` returned no construct
        // at all - and the row above it leaves no open paragraph, so the run
        // ended between a table and the row that merges into it and the
        // continuation came back as literal text.
        if ($this->tableParser->isTableRow($line) || $this->tableParser->isContinuationRow($line)) {
            return 'table';
        }
        if (preg_match(self::DEFINITION_TERM_LINE_PATTERN, $line) === 1) {
            return 'definition';
        }

        return '';
    }

    /**
     * Would a REGISTERED matcher claim a block starting on this line?
     *
     * Asked with a SCRATCH parent, the pattern `indexHeadingsFromStructure()`
     * already uses one level up: a matcher is a black box that reports how many
     * lines it consumes, so there is no way to ask about its extent except to
     * run it, and running it into a throwaway node keeps the real tree
     * untouched. `addBlockPattern()` callbacks append to the parent they are
     * handed, so they append to the scratch; `addBlockMatcher()` returns its
     * node to the dispatcher, which appends it here and nowhere else.
     *
     * The pending-attribute pair is saved and restored for the same reason it
     * is around {@see self::wrappedBlockAttributeLength()} - a matcher that
     * consumed an attribute line must not leave the run's state changed.
     *
     * @param array<string> $lines
     * @param int $start
     */
    protected function matchesRegisteredBlockOpener(array $lines, int $start): bool
    {
        $savedSpan = $this->state->session->pendingAttributeSpan;
        $savedAttributes = $this->state->session->pendingAttributes;
        $savedOrder = $this->state->session->pendingAttributeOrder;
        try {
            return $this->tryBlockMatchers(new Document(), $lines, $start) !== null;
        } finally {
            $this->state->session->pendingAttributeSpan = $savedSpan;
            $this->state->session->pendingAttributes = $savedAttributes;
            $this->state->session->pendingAttributeOrder = $savedOrder;
        }
    }

    /**
     * @param string $kind
     * @param string $line
     * @param array<string> $lines
     * @param int $index
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     */
    private function trailingBlockHasEndedCore(string $kind, string $line, array $lines, int $index, TrailingBlockState $trailingState): bool
    {
        return $this->continuationsMapper()->trailingBlockHasEndedCore($kind, $line, $lines, $index, $trailingState);
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
        $trailingState = new TrailingBlockState();
        $attachedKind = self::ATTACHED_PENDING;
        $pendingThrough = -1;
        [$i, $attached, $attachedRawLineMap] = $this->collectAttachedBlock(
            $lines,
            $i,
            $count,
            function (string $line, int $index) use (&$trailingState, &$attachedKind, &$pendingThrough, $lines, $baseIndent): bool {
                $lineIndent = IndentationHelper::getLeadingColumns($line, $baseIndent + 1);
                $trimmed = ltrim($line, " \t");
                if (
                    IndentationHelper::isBlankLine($line)
                    || $lineIndent < $baseIndent
                    || ($lineIndent === $baseIndent
                        && $trailingState->fence === null
                        && ($this->listParser->parseListItemMarker($trimmed) !== null || $this->isContinuationMarker($trimmed)))
                ) {
                    return true;
                }
                if ($this->trailingBlockHasEnded($attachedKind, $trimmed, $lines, $index, $trailingState)) {
                    return true;
                }
                $content = IndentationHelper::stripLeadingColumns($line, $baseIndent);
                $attachedKind = $this->advanceAttachedKind($attachedKind, $pendingThrough, $trimmed, $lines, $index);
                $trailingState = $this->advanceTrailingState($trailingState, $content);

                return false;
            },
            static fn (string $line): string => IndentationHelper::stripLeadingColumns($line, $baseIndent),
        );
        $attachedLineMap = array_map(fn (int $raw): int => $this->sourceLineFor($raw), $attachedRawLineMap);

        return [$i, $attached, $attachedLineMap];
    }

    /**
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $state
     * @param string $line
     *
     * @return \MarkupCarve\Carve\Parser\TrailingBlockState
     */
    private function advanceFootnoteBodyFenceState(TrailingBlockState $state, string $line): TrailingBlockState
    {
        return $this->continuationsMapper()->advanceFootnoteBodyFenceState($state, $line);
    }

    /**
     * @param string $line
     * @param int $contentIndent
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     */
    private function blankLineResidue(string $line, int $contentIndent, TrailingBlockState $trailingState): string
    {
        return $this->continuationsMapper()->blankLineResidue($line, $contentIndent, $trailingState);
    }

    /**
     * @param array<string> $lines All lines being parsed.
     * @param int $i Index of the first line after the marker line.
     * @param int $count Total line count.
     * @param int $baseIndent The list's base column.
     * @param int $contentIndent The item's content column.
     * @param array<string> $itemLines Collected item lines, appended in place.
     * @param array<int, int> $itemLineMap Source-line map, appended in place.
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     * @param bool $leadIsBareContinuationMarker
     * @param array<int, true> $authoredBaseEligible
     *
     * @return array{0: int, 1: \MarkupCarve\Carve\Parser\TrailingBlockState}
     */
    private function collectPlainContinuationCore(
        array $lines,
        int $i,
        int $count,
        int $baseIndent,
        int $contentIndent,
        array &$itemLines,
        array &$itemLineMap,
        TrailingBlockState $trailingState,
        bool $leadIsBareContinuationMarker = false,
        array &$authoredBaseEligible = [],
    ): array {
        return $this->continuationsMapper()->collectPlainContinuationCore($lines, $i, $count, $baseIndent, $contentIndent, $itemLines, $itemLineMap, $trailingState, $leadIsBareContinuationMarker, $authoredBaseEligible);
    }

    /**
     * Length of a wrapped attribute block beginning in an item's normalized
     * content stream, or null when the brace run is ordinary text.
     *
     * @param string $first
     * @param array<string> $lines
     * @param int $index
     * @param int $count
     * @param int $contentIndent
     */
    protected function wrappedItemAttributeLength(
        string $first,
        array $lines,
        int $index,
        int $count,
        int $contentIndent,
    ): ?int {
        if (!str_starts_with($first, '{') || $this->isBlockAttributeLine($first)) {
            return null;
        }

        $probe = [$first];
        for ($i = $index; $i < $count; $i++) {
            $line = $lines[$i];
            if (
                IndentationHelper::isBlankLine($line)
                || IndentationHelper::getLeadingColumns($line, $contentIndent + 1) < $contentIndent
            ) {
                break;
            }
            $content = IndentationHelper::stripLeadingColumns($line, $contentIndent);
            $probe[] = $content;
            if (str_contains($content, '}')) {
                break;
            }
        }

        return $this->wrappedBlockAttributeLength($probe, 0);
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
                || ($nextIndent === 0 && $this->flushLineEndsListContinuation($nextTrimmed, $lines, $index))
            );
    }

    /**
     * Does a line at the list's own column end the item's continuation?
     *
     * @param string $line
     * @param array<string>|null $lines
     * @param int|null $index
     */
    protected function flushLineEndsListContinuation(string $line, ?array $lines = null, ?int $index = null): bool
    {
        if ($this->isBlockAttributeLine($line)) {
            return true;
        }

        return $this->isBlockElementStart($line, $lines, $index)
            || $this->startsNewBlock($line, $lines, $index);
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

        if ($this->listParser->parseListItemMarker($nextTrimmed) !== null || $this->isContinuationMarker($nextTrimmed)) {
            return true;
        }

        return $baseIndent === 0
            && $this->flushLineEndsListContinuation($nextTrimmed, $lines, $index);
    }

    /**
     * Collect the body of a list item whose lead content (on the marker line)
     * is itself a list marker, as a SINGLE block stream.
     *
     * @param array<string> $lines All lines being parsed.
     * @param int $i Index of the first line AFTER the lead marker line.
     * @param int $count Total line count.
     * @param int $baseIndent The list's base column.
     * @param int $contentIndent The item's content column.
     * @param array<string> $itemLines Collected stream (lead marker line already present); appended in place.
     * @param array<int, int> $itemLineMap Source-line map for $itemLines; appended in place.
     * @param array<int, true> $authoredBaseEligible Entries this collector DEDENTED, by index; filled in place.
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
        array &$authoredBaseEligible = [],
    ): int {
        // Trailing-block state over the stream, seeded with the lead marker line
        // the caller already put there. A dedented line folds only where this
        // says a paragraph is open, which is the same gate the plain-lead
        // collector applies (PART 0 S4: no open paragraph, no lazy line).
        $trailingState = new TrailingBlockState();
        foreach ($itemLines as $seedLine) {
            $trailingState = $this->advanceTrailingState($trailingState, $seedLine);
        }
        while ($i < $count) {
            $nextLine = $lines[$i];

            if (IndentationHelper::isBlankLine($nextLine)) {
                // Keep the run for the child parser, including a fence that
                // reaches the end of its item. The next content line still
                // decides whether this item continues.
                $itemLines[] = $this->blankLineResidue($nextLine, $contentIndent, $trailingState);
                $itemLineMap[] = $this->sourceLineFor($i);
                $trailingState = $this->advanceTrailingState($trailingState, '');
                $i++;

                continue;
            }

            $nextIndent = IndentationHelper::getLeadingColumns($nextLine, max($baseIndent, $contentIndent) + 1);
            // A LAZY QUOTE LINE REACHES NO CONTENT COLUMN. It carries no `>`,
            // so it extends an open paragraph and nothing else; the quote hands
            // it down unstripped, which is the only reason its own indentation
            // looks like it reaches this item. The plain-lead collector already
            // asks this before dedenting - without it here, `> - - x` over an
            // indented block opener opened the block inside the item where
            // every other engine folds it.
            $isBlockQuoteLazyLine = isset($this->state->session->blockQuoteLazySourceLines[$this->sourceLineFor($i)]);
            if ($nextIndent < $contentIndent || $isBlockQuoteLazyLine) {
                $nextTrimmed = ltrim($nextLine, " \t");
                // A sibling marker or a block opener at the base column belongs
                // to the caller's loop, and a stream ending in a closed block
                // has nothing to continue: both end the item.
                if (
                    (!$trailingState->openParagraph
                        && !($trailingState->afterComment && $trailingState->nestedColumn > 0 && $nextIndent > $baseIndent))
                    || $this->listContinuationEndsAtDedentedBlock($nextIndent, $nextTrimmed, $baseIndent, $lines, $i)
                    || $this->listContinuationEndsAtBaseColumn($nextIndent, $nextTrimmed, $baseIndent, $lines, $i)
                ) {
                    break;
                }
                // A BARE CONTINUATION MARKER LEAD FOLDS COLUMN 0 AND NOTHING
                // ELSE (SS17 L3, carve#1436). The lead is an empty first-block
                // item waiting for a flush-left block: the document-column-0
                // line IS that block and has to reach the nested parse, and a
                // line at any other column was never the marker's - it falls
                // through to the ordinary column rules, which for a column
                // below the item's own content column means no container holds
                // it.
                //
                // Asked of the SOURCE line, because the fold below normalizes
                // every below-column line to exactly ONE column: `* * +` over a
                // column-0 line and over a column-1 line both arrive at the
                // nested parse as ` x`, and by then they cannot be told apart.
                if (
                    $itemLines !== []
                    && $this->leadBottomIsContinuationMarker((string)$itemLines[0])
                    && !$this->continuationAttachesAtColumnZero($i)
                ) {
                    break;
                }
                // A DEFINITION AT THE FRAME'S BASE BELONGS TO THE ENCLOSING
                // ITEM, exactly as it does on the plain-lead path. It is not a
                // block element start, so neither dedent gate above ends the
                // item over it, and folding it left `- - x` hiding a definition
                // that `- x` hands out (markup-carve/carve#1896).
                if (
                    $nextIndent === 0
                    && !$this->isBlockElementStart($nextTrimmed)
                    && !$this->startsNewBlock($nextTrimmed)
                    && $this->isDefinitionLineForEnclosingItem($nextTrimmed)
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
                //
                // AN UNFINISHED FENCE ON THE LEAD OWNS THESE LINES INSTEAD
                // (markup-carve/carve-php#1900, ruled on markup-carve/carve#1900).
                // A fence at an item's block start runs to the end of its
                // container, so a line this container folds in is the fence's
                // body, and a closing run among them is body text because a
                // fence's content is not re-scanned for structure. The clamp
                // cannot say that: it leaves the line re-classifying at column
                // 1, where the closer still closed and the body came back as a
                // paragraph of the item ABOVE. Framed ONCE however many
                // containers fold it, so the single strip in the body suffices.
                $folded = str_starts_with($nextTrimmed, self::LAZY_FRAME)
                    ? $nextTrimmed
                    : ($this->leadBottomOpensFence((string)($itemLines[0] ?? ''))
                        ? self::LAZY_FRAME . $nextTrimmed
                        : ' ' . $nextTrimmed);
                $itemLines[] = $folded;
                $itemLineMap[] = $this->sourceLineFor($i);
                // Tracked as the line the item RECEIVED, not as the source line.
                // A framed closing run is body text, so it must not close the
                // tracker's fence either - fed the source line, the tracker shut
                // the fence at the closer and the run below it left the item.
                $trailingState = $this->advanceTrailingState(
                    $trailingState,
                    str_starts_with($folded, self::LAZY_FRAME)
                        ? $folded
                        : ($this->itemFenceOpenerAt($nextTrimmed) !== null ? 'text' : $nextLine),
                );
                $i++;

                continue;
            }

            // The line REACHED this item's content column and was dedented by
            // it, which is the only thing that distinguishes it from a line
            // that reached no column at all once both arrive as one residual
            // column. Recorded so the authored-base pass can tell them apart
            // (markup-carve/carve#1896).
            $stripped = IndentationHelper::stripLeadingColumns($nextLine, $contentIndent);
            $authoredBaseEligible[count($itemLines)] = true;
            $itemLines[] = $stripped;
            $itemLineMap[] = $this->sourceLineFor($i);
            // AT OR PAST the content column, the same reading the plain-lead
            // collector uses: an invisible block here ends the paragraph under
            // it (carve-php#1866).
            $trailingState = $this->advanceTrailingState($trailingState, $stripped, true);
            $i++;
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

        return $i < $count && IndentationHelper::getLeadingColumns($lines[$i], $contentIndent) >= $contentIndent;
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
        return $this->definitionsBuilder()->tryParseDefinitionList($parent, $lines, $start);
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
        return $this->linesBuilder()->splitByBlankLines($lines);
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
        return $this->linesBuilder()->parseLineBlockOpener($line);
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
        return $this->linesBuilder()->tryParseLineBlock($parent, $lines, $start);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Block\LineBlock $lineBlock
     * @param list<array{0: string, 1: int}> $lines
     */
    protected function appendLineBlockStanza(LineBlock $lineBlock, array $lines): void
    {
        $this->linesBuilder()->appendLineBlockStanza($lineBlock, $lines);
    }

    /**
     * Promote a stanza's soft breaks to hard ones, AT EVERY DEPTH.
     *
     * @param \MarkupCarve\Carve\Node\Block\Paragraph $paragraph
     * @param list<array{0: int, 1: int}> $lineEndings Text offset and line number, ascending.
     */
    protected function convertParagraphSoftBreaksToHardBreaks(Paragraph $paragraph, array $lineEndings = []): void
    {
        $this->linesBuilder()->convertParagraphSoftBreaksToHardBreaks($paragraph, $lineEndings);
    }

    /**
     * Expand one line-block line, preserving significant whitespace.
     *
     * @param string $line
     * @param int $lineNo
     *
     * @return array{0: string, 1: list<array{0: int, 1: int, 2: int, 3: int}>, 2: int}
     */
    protected function expandLineBlockLine(string $line, int $lineNo): array
    {
        return $this->linesBuilder()->expandLineBlockLine($line, $lineNo);
    }

    /**
     * The characters a table cell reads as an ALIGNMENT MARKER, glued to `|` or
     * `|=`, mapped to the alignment each one means.
     *
     * Public so the Carve writer can read the set OFF THE PARSER instead of
     * carrying a second copy of it. The writer must not emit a header marker
     * immediately followed by one of these, because the next parse eats it as
     * alignment and keeps the rest of the cell as text (carve-php#1069 cause 5).
     * A guard built from a hand-listed set would be a second spelling of this
     * rule, and this repository keeps finding one rule spelled N times with N
     * larger than anyone claimed.
     *
     * @var array<string, string>
     */
    public const TABLE_ALIGNMENT_MARKERS = BlockGrammar::TABLE_ALIGNMENT_MARKERS;

    /**
     * Parse a Carve table cell's tight alignment/header marker (written
     * tight against the pipe): optional `=` (header) then optional one of
     * `< > ~` (left/right/center). Returns the flags plus the content with
     * the marker stripped. Spaced markers (`| ^ |`, `| < |`) are span
     * markers, not alignment — their leading space means index 0 is not a
     * marker char, so they are left untouched here.
     *
     * @return array{header: bool, align: string|null, valign: string|null, content: string}
     */
    protected function parseTableCellMarker(string $raw, bool $markerOnly = false): array
    {
        return $this->tablesBuilder()->parseTableCellMarker($raw, $markerOnly);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseTable(Node $parent, array $lines, int $start): ?int
    {
        return $this->tablesBuilder()->tryParseTable($parent, $lines, $start);
    }

    /**
     * Resolve a table row's `<`/`^` span markers into output cells using the
     * same single LEFT-TO-RIGHT grid walk the carve-js renderer uses (carve spec
     * section 96).
     *
     * @param array<int, array{content: string, attributes: string, marker: string, offset: int|null, cellOffset?: int|null, verbatim: bool, rawLength: int|null, raw: string|null, sourceChunks?: list<array{int, int, string}>}> $mergedCellsWithAttrs
     * @param array<int, \MarkupCarve\Carve\Node\Block\TableCell> $columnOrigin Per-column open
     *   origin cell carried down from earlier rows.
     *
     * @return array{cells: array<array{content: string, attributes: string, marker: string, colspan: int<1, max>, gridColumn: int, isEmpty: bool, spanMarker: string|null, offset: int|null, cellOffset?: int|null, rawLength: int|null, raw: string|null, verbatim: bool, sourceChunks: list<array{int, int, string}>}>, consumedRowspanColumns: array<int>, consumedColspanColumns: array<int>}
     */
    protected function resolveRowSpans(array $mergedCellsWithAttrs, array $columnOrigin): array
    {
        return $this->tablesBuilder()->resolveRowSpans($mergedCellsWithAttrs, $columnOrigin);
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
            // THE LOOKAHEAD SPLITS THE SAME WAY THE COLLECTOR DOES. Measured,
            // this argument changes no output today: the validity check below
            // asks whether the MERGED content is balanced, and
            // `mergeCellContents()` joins the cells with a space, so the total
            // text is the same however the row was divided. Removing it fails
            // nothing - a diagnosis rather than a gap, recorded here because
            // the next reader will notice.
            //
            // It stays because the alternative is the failure this file keeps
            // recording: one rule with two spellings, where the second is only
            // wrong once something starts depending on it.
            $continuationCells = $this->tableParser->parseContinuationCells(
                $lines[$i],
                $this->openVerbatimRunsByCell($mergedCells),
            );
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
     * Where a footnote body resumes after a run of blank lines, or null when
     * the run ends the definition.
     *
     * @param array<string> $lines
     * @param int $blank the index of the first blank line of the run
     * @param int $count
     * @param int $bodyColumn the column a continuation has to reach
     * @param bool $allowContinuationMarker whether a lone `+` also resumes it
     */
    private function footnoteBodyResumesAfter(
        array $lines,
        int $blank,
        int $count,
        int $bodyColumn,
        bool $allowContinuationMarker,
    ): ?int {
        $ahead = $blank;
        while ($ahead < $count && IndentationHelper::isBlankLine($lines[$ahead])) {
            $ahead++;
        }
        if ($ahead >= $count) {
            return null;
        }
        // COLUMNS, NOT BYTES. A tab is one byte and four columns, so a byte
        // measure reads a tab-indented body as below the column.
        if (IndentationHelper::getLeadingColumns($lines[$ahead], $bodyColumn) >= $bodyColumn) {
            return $ahead;
        }
        if ($allowContinuationMarker && preg_match('/^\+[ \t]*$/', $lines[$ahead]) === 1) {
            return $ahead;
        }

        return null;
    }

    /**
     * Skip footnote definitions (already extracted in first pass)
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param array<string> $lines
     * @param int $start
     */
    protected function tryParseFootnoteDefinition(Node $parent, array $lines, int $start): ?int
    {
        $line = $lines[$start];

        // Match footnote definition: [^label]: content. Must mirror the
        // pre-pass collector exactly (literal space separator, PART 9 §16):
        // a bare `[^label]:` - or a tab-separated body the collector does not
        // accept - is never skipped here; it parses as a paragraph.
        if (!preg_match(self::FOOTNOTE_DEFINITION_PATTERN, $line, $match)) {
            return null;
        }

        $label = $match[1];
        $key = LabelKey::normalize($label);
        $content = $match[2];
        if (trim($content, StringUtil::WHITESPACE_CHARS) === '') {
            return null;
        }
        if ($parent instanceof ListItem && $parent->isTask()) {
            return null;
        }

        // Skip the footnote definition and any continuation lines
        $i = $start + 1;
        $count = count($lines);

        $bodyLines = [$content];
        $bodyLineMap = [$this->sourceLineFor($start)];
        // Tracked for the same reason the list-item collectors track it: inside
        // an open fence a whitespace-only source line is a verbatim line, so
        // what lies past the body column is content rather than a blank
        // (CARVE-P11-016, PART 9 section 24 C5).
        $trailingState = $this->advanceFootnoteBodyFenceState(new TrailingBlockState(), $content);
        while ($i < $count) {
            $nextLine = $lines[$i];
            if (IndentationHelper::isBlankLine($nextLine)) {
                // A BLANK RUN continues the footnote when the body resumes
                // after it. Must mirror the body-collection logic so a line is
                // never skipped here without being collected there (grammar
                // PART 9 §16, §17) - see
                // {@see self::footnoteBodyResumesAfter()} for why the whole run
                // has to be measured rather than the line after the blank.
                $resumes = $this->footnoteBodyResumesAfter(
                    $lines,
                    $i,
                    $count,
                    self::FOOTNOTE_BODY_COLUMN,
                    true,
                );
                if ($resumes === null) {
                    break;
                }
                // INTACT, one blank per source line, so a §11 N1a boundary
                // inside the body survives to the parser.
                for (; $i < $resumes; $i++) {
                    $residue = $this->blankLineResidue($lines[$i], self::FOOTNOTE_BODY_COLUMN, $trailingState);
                    $bodyLines[] = $residue;
                    $bodyLineMap[] = $this->sourceLineFor($i);
                    $trailingState = $this->advanceFootnoteBodyFenceState($trailingState, $residue);
                }

                continue;
            }
            // Form B: a `+` continuation marker plus its attached flush-left
            // block (ends at a blank line, another `+`, or the next footnote
            // definition) - the same rebase the footnote body reader uses.
            if (preg_match('/^\+[ \t]*$/', $nextLine)) {
                $i++;
                // ...AND THE NOTE ENDS WHERE A COMMENT ENDS IT
                // (markup-carve/carve#1814). The gate below decides
                // whether this `+` is a marker at all; when it is not
                // the line is an ordinary invisible line at document
                // column 0, and a footnote body ends at one of those
                // exactly as it ends at a comment line there. Asked ONE
                // LINE EARLY because this loop's own continuation
                // branch would otherwise claim the following line
                // before any extent is measured. The `+` is consumed
                // either way, so the enclosing parse resumes on the
                // line the marker did not take.
                if (!$this->continuationAttachesAtColumnZero($i)) {
                    break;
                }
                [$i, $attached, $attachedLineMap] = $this->attachedFlushLeftBlock(
                    $lines,
                    $i,
                    $count,
                    static fn (string $a): bool => (bool)preg_match('/^\[\^[^\]]+\]:/', $a),
                );
                if ($attached !== []) {
                    $bodyLines[] = '';
                    $bodyLineMap[] = -1;
                    $trailingState = $this->advanceFootnoteBodyFenceState($trailingState, '');
                    foreach ($attached as $attachedIndex => $attachedLine) {
                        $bodyLines[] = $attachedLine;
                        $bodyLineMap[] = $this->sourceLineFor($attachedLineMap[$attachedIndex]);
                        $trailingState = $this->advanceFootnoteBodyFenceState($trailingState, $attachedLine);
                    }
                }

                continue;
            }
            // TWO COLUMNS PAST THE NOTE'S OWN MARKER, measured on the AUTHORED
            // source rather than on this coordinate system. A nested note is
            // handed here already dedented to the column it reaches, so its
            // marker reads as flush and a fixed floor of two let the note claim
            // a trailing line BELOW its own content column - which PART 0's
            // owner-selection table gives to the nearest surviving ancestor
            // (markup-carve/carve#1971). The authored columns are still on
            // `sourceLines`, so the comparison is made there and the dedent that
            // placement depends on is left alone. carve-js#1666 and
            // carve-rs#1575 measure the same floor from the marker they were
            // written at.
            $authoredFloor = $this->footnoteBodyDepth > 0
                ? $this->authoredFootnoteBodyFloor($start, $lines[$start])
                : null;
            $authoredNext = $this->authoredColumnOf($i, $nextLine);
            $reaches = $authoredFloor === null || $authoredNext === null
                ? IndentationHelper::getLeadingColumns($nextLine, self::FOOTNOTE_BODY_COLUMN) >= self::FOOTNOTE_BODY_COLUMN
                : $authoredNext >= $authoredFloor;
            if ($reaches) {
                $bodyLine = IndentationHelper::stripLeadingColumns($nextLine, self::FOOTNOTE_BODY_COLUMN);
                $bodyLines[] = $bodyLine;
                $bodyLineMap[] = $this->sourceLineFor($i);
                $trailingState = $this->advanceFootnoteBodyFenceState($trailingState, $bodyLine);
                $i++;
            } elseif (
                // A COMMENT INSIDE A SPAN THE BODY ALREADY HOLDS IS NOT "A
                // COMMENT BELOW THE COLUMN" (markup-carve/carve#2488).
                // {@see self::linesLeaveACommentSpanOpen()}
                $this->isCommentLineOrFence(ltrim($nextLine, " \t"))
                && $this->linesLeaveACommentSpanOpen($bodyLines)
            ) {
                $bodyLine = $this->keptCommentDelimiter($nextLine);
                $bodyLines[] = $bodyLine;
                $bodyLineMap[] = $this->sourceLineFor($i);
                $trailingState = $this->advanceFootnoteBodyFenceState($trailingState, $bodyLine);
                $i++;
            } else {
                break;
            }
        }

        while ($bodyLines !== [] && end($bodyLines) === '') {
            array_pop($bodyLines);
            array_pop($bodyLineMap);
        }

        if ($this->state->session->discoveringDefinitions && !isset($this->state->session->footnotes[$key])) {
            $footnote = new Footnote($label);
            if ($this->state->source->trackSourceLines) {
                $footnote->setAttribute('data-source-line', (string)($this->sourceLineFor($start) + 1));
            }
            $sourceLine = $this->sourceLineFor($start);
            $this->recordFootnoteDefinitionSpan(
                $key,
                $sourceLine,
                $this->state->source->sourceLines[$sourceLine] ?? $line,
                $line,
            );
            $lastLine = end($bodyLineMap);
            if (is_int($lastLine) && $lastLine >= 0) {
                $this->extendFootnoteDefinitionToLineStart($key, $lastLine + 1);
            }
            $this->state->session->footnotes[$key] = $footnote;
            $this->state->session->discoveredFootnoteBodies[$key] = [
                'lines' => $bodyLines,
                'lineMap' => $bodyLineMap,
            ];
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

        // A definition-shaped lazy line in a quote remains the open
        // paragraph's text (carve-php#1908, PART 1 S4).
        if (isset($this->state->session->blockQuoteLazySourceLines[$this->sourceLineFor($start)])) {
            return null;
        }

        // ONE SPELLING. This pass CONSUMES a line the first-pass collector
        // registered, so it has to accept exactly what the collector accepts: a
        // line it consumes and the collector refused renders nothing and
        // resolves nothing, and a line it leaves behind reappears as visible
        // prose beside a working reference. It carried its own copy of the
        // pattern and the copy is what went stale, so it asks the collector
        // instead (markup-carve/carve#911).
        //
        // The line is ANCHORED AT END OF LINE there: `[r]: a b c` is no longer a
        // definition, and `[a]: /u {.c}` only is when the block parses.
        $definition = $this->referenceDefinitionExtractor->matchDefinitionLine($line);
        if ($definition === null) {
            return null;
        }

        if ($this->state->session->discoveringDefinitions) {
            $sourceLine = $this->sourceLineFor($start);
            $this->state->session->references[LabelKey::normalize($definition['label'])] = new ReferenceDefinition(
                $definition['url'],
                $definition['attrs'],
                $sourceLine,
                $definition['title'],
                false,
                $definition['label'],
            );
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

        // The line is KEPT as a node rather than merely skipped. It renders
        // nothing on HTML and is emitted as written on the non-HTML targets
        // (PART 11 §10a), and those renderers walk `children` - so a definition
        // that leaves no node cannot be put back where the author wrote it. The
        // expansions are collected separately by the abbreviation pass; this
        // carries the AUTHORED line (markup-carve/carve-php#708).
        if (preg_match(self::ABBREVIATION_DEFINITION_PATTERN, $line, $m) === 1) {
            $node = new AbbreviationDefinition($m[1], rtrim($m[2], " \t"));
            $parent->appendChild($node);
            if ($this->state->session->discoveringDefinitions) {
                $definition = rtrim($m[2], " \t");
                $this->state->session->abbreviations[$m[1]] = $definition;
                $this->state->session->abbreviationDefinitions[] = ['abbr' => $m[1], 'expansion' => $definition];
                $this->state->session->discoveredAbbreviationLines[$this->sourceLineFor($start)] = true;
                if ($this->state->source->trackPositions) {
                    $span = $this->wholeLineSpan($this->sourceLineFor($start));
                    if ($span !== null) {
                        $this->state->session->abbreviationSpans[$m[1]] = $span->toArray();
                    }
                }
            }
        }

        // The grammar's expansion ends at `newline`; an indented following
        // line is a new paragraph, not a continuation of the definition.
        return 1;
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
     * @param bool $itemBody
     */
    protected function tryParseParagraph(
        Node $parent,
        array $lines,
        int $start,
        bool $topLevel = false,
        bool $itemBody = false,
    ): int {
        $line = self::stripLazyFrame($lines[$start]);
        // Strip leading whitespace from first line (matching JS reference)
        $content = ltrim($line, " \t");
        // WHERE THE PARAGRAPH BEGAN, relative to its container's content column,
        // captured HERE because both variables above are reassigned by the fold
        // loop below - reading them at the construction site answered a question
        // about whichever line the paragraph stopped on. `$lines` is already
        // dedented to the container's content column, so a leading space here is
        // one the author put ABOVE it.
        $firstLineIsAboveContentColumn = $line !== $content;
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
        $indent += $this->state->frame->currentContentColumns[$firstSourceLine] ?? 0;
        $sourceTail = rtrim($this->state->source->sourceLines[$firstSourceLine] ?? '', " \t");
        $contentTail = rtrim($content, " \t");
        if ($contentTail !== '' && str_ends_with($sourceTail, $contentTail)) {
            $indent = max($indent, strlen($sourceTail) - strlen($contentTail));
        }
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
            if ($this->interruptsParagraph($lines, $i, $contentParts, $start, $hasUnclaimedColonFenceLine, $topLevel, $itemBody)) {
                break;
            }

            // Strip leading whitespace from continuation lines (matching JS reference)
            $rawNextLine = self::stripLazyFrame($nextLine);
            $nextLine = ltrim($rawNextLine, " \t");
            $this->appendParagraphContentLines(
                $contentLines,
                $this->sourceLineFor($i),
                strlen($rawNextLine) - strlen($nextLine)
                    + ($this->state->frame->currentContentColumns[$this->sourceLineFor($i)] ?? 0),
                $nextLine,
            );
            $contentParts[] = $nextLine;
            $hasUnclaimedColonFenceLine = $hasUnclaimedColonFenceLine
                || $this->paragraphHasUnclaimedColonFenceLine($nextLine);
            $i++;
        }

        $content = implode("\n", $contentParts);

        $physicalLines = explode("\n", $content);
        foreach ($physicalLines as $index => $physicalLine) {
            $trimmedLine = rtrim($physicalLine, " \t");
            if ($trimmedLine === $physicalLine) {
                continue;
            }
            $physicalLines[$index] = $trimmedLine;
            if (!isset($contentLines[$index])) {
                continue;
            }
            $shrink = strlen($physicalLine) - strlen($trimmedLine);
            [$lineIndex, $column, $length, $lineText] = $contentLines[$index];
            $contentLines[$index] = [
                $lineIndex,
                $column,
                max(0, $length - $shrink),
                substr($lineText, 0, max(0, strlen($lineText) - $shrink)),
            ];
        }
        $content = implode("\n", $physicalLines);
        foreach ($contentLines as $index => [$lineIndex, $column, $length, $lineText]) {
            $trimmedSourceText = rtrim($lineText, " \t");
            $trimmedLength = min($length, strlen($trimmedSourceText));
            if ($trimmedLength !== $length || $trimmedSourceText !== $lineText) {
                $contentLines[$index] = [
                    $lineIndex,
                    $column,
                    $trimmedLength,
                    $trimmedSourceText,
                ];
            }
        }

        $paragraph = new Paragraph();
        if ($firstLineIsAboveContentColumn) {
            $this->state->session->paragraphsAboveContentColumn[spl_object_id($paragraph)] = true;
        }
        // Set here rather than leaving it to the block-loop stamp, which spans
        // whole lines: a folded paragraph knows exactly which lines it took and
        // where its content starts and ends within them.
        $paragraph->setPos($this->foldedLinesSpan($contentLines));
        if ($this->deferredScratchInlines !== null) {
            $this->deferredScratchInlines[$paragraph] = [$content, $start, $contentLines];
            $this->applyPendingAttributes($paragraph);
            $parent->appendChild($paragraph);

            return $i - $start;
        }
        $this->inlineParser->parse(
            $paragraph,
            $content,
            $start,
            sourceMap: $this->foldedLinesMap($contentLines),
        );
        $placed = array_values(array_filter(
            $paragraph->getChildren(),
            static fn (Node $node): bool => $node->getPos() !== null,
        ));
        $measured = $this->foldedLinesSpan($contentLines);
        if ($placed !== []) {
            $first = $placed[0]->getPos();
            $last = $placed[count($placed) - 1]->getPos();
            if ($first !== null && $last !== null) {
                if ($measured !== null && $measured->endOffset < $last->endOffset) {
                    $last = new SourceSpan(
                        startLine: $last->startLine,
                        endLine: $measured->endLine,
                        startColumn: $last->startColumn,
                        endColumn: $measured->endColumn,
                        startOffset: $last->startOffset,
                        endOffset: $measured->endOffset,
                    );
                    $placed[count($placed) - 1]->setPos($last);
                }
                $paragraph->setPos(new SourceSpan(
                    startLine: $first->startLine,
                    endLine: $measured !== null ? $measured->endLine : $last->endLine,
                    startColumn: $first->startColumn,
                    endColumn: $measured !== null ? $measured->endColumn : $last->endColumn,
                    startOffset: $first->startOffset,
                    endOffset: $measured !== null ? $measured->endOffset : $last->endOffset,
                ));
            }
        }
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
     * @param array<int, int> $lineMap
     * @param int|null $openingColumn
     */
    private function spanForLineMap(array $lineMap, ?int $openingColumn = null): ?SourceSpan
    {
        return $this->sourceMapper()->spanForLineMap($lineMap, $openingColumn);
    }

    /**
     * @param list<array{int, int, int, string}> $contentLines resolved source line, column, length, text
     */
    private function foldedLinesSpan(array $contentLines): ?SourceSpan
    {
        return $this->sourceMapper()->foldedLinesSpan($contentLines);
    }

    private function openerTitleMap(int $line, string $title): ?SourceMap
    {
        return $this->sourceMapper()->openerTitleMap($line, $title);
    }

    /**
     * @param list<array{int, int, int, string}> $contentLines resolved source line, column, length, text
     * @param int $firstLineSearchFrom Column the FIRST line's text is searched from.
     */
    private function foldedLinesMap(array $contentLines, int $firstLineSearchFrom = 0): ?SourceMap
    {
        return $this->sourceMapper()->foldedLinesMap($contentLines, $firstLineSearchFrom);
    }

    private function deriveContainerSpans(Node $node): ?SourceSpan
    {
        return $this->sourceMapper()->deriveContainerSpans($node);
    }

    /**
     * @param int $start Index of the slot's first line in `$lines`.
     * @param array<string> $rawLines
     *
     * @return list<array{break: \MarkupCarve\Carve\Ast\SourceSpan|null, text: \MarkupCarve\Carve\Ast\SourceSpan|null}>
     */
    private function givenBackLineSpans(int $start, array $rawLines): array
    {
        return $this->sourceMapper()->givenBackLineSpans($start, $rawLines);
    }

    private function widenSpanTo(Node $node, ?SourceSpan $reach): void
    {
        $this->sourceMapper()->widenSpanTo($node, $reach);
    }

    /**
     * @param string $label
     * @param int $index
     * @param string $raw The line as written, container prefix included.
     * @param string $bare The same line with that prefix stripped.
     */
    private function recordFootnoteDefinitionSpan(
        string $label,
        int $index,
        string $raw,
        string $bare,
    ): void {
        $this->sourceMapper()->recordFootnoteDefinitionSpan($label, $index, $raw, $bare);
    }

    private function extendFootnoteDefinitionToLineStart(string $label, int $lineIndex): void
    {
        $this->sourceMapper()->extendFootnoteDefinitionToLineStart($label, $lineIndex);
    }

    private function wholeLineSpan(int $index): ?SourceSpan
    {
        return $this->sourceMapper()->wholeLineSpan($index);
    }

    private function wholeLinesSpan(int $firstIndex, int $lastIndex, int $openingColumn = 0): ?SourceSpan
    {
        return $this->sourceMapper()->wholeLinesSpan($firstIndex, $lastIndex, $openingColumn);
    }

    /**
     * @param array<int, string> $cells Merged content of the row so far.
     *
     * @return array<int, int> Cell index => open delimiter width.
     */
    private function openVerbatimRunsByCell(array $cells): array
    {
        return $this->sourceMapper()->openVerbatimRunsByCell($cells);
    }

    /**
     * @param int $start Index of the `^ ` line.
     * @param list<string> $captionLines The caption's text, one entry per source line.
     * @param int $markerWidth Width of the `^` and the spaces after it.
     */
    private function captionSourceMap(int $start, array $captionLines, int $markerWidth): ?SourceMap
    {
        return $this->sourceMapper()->captionSourceMap($start, $captionLines, $markerWidth);
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
     * @param bool $itemBody
     */
    protected function interruptsParagraph(
        array $lines,
        int $i,
        array $contentLines,
        int $sourceLine,
        bool $hasUnclaimedColonFenceLine,
        bool $topLevel = false,
        bool $itemBody = false,
    ): bool {
        $line = $lines[$i];

        // A definition-shaped lazy line in a quote is paragraph
        // text, so it cannot break the run (carve-php#1908, PART 1 S4).
        if (
            isset($this->state->session->blockQuoteLazySourceLines[$this->sourceLineFor($i)])
            && $this->isReferenceDefinitionLine(ltrim($line, " \t"))
        ) {
            return false;
        }

        if (
            $itemBody
            && $line !== ''
            && $line[0] !== ' '
            && $line[0] !== "\t"
            && $this->listParser->parseListItemMarker($line) !== null
        ) {
            return true;
        }

        if (preg_match('/^\^ +.*' . StringUtil::NON_WHITESPACE_CLASS . '/', $line)) {
            return $this->isCaptionableParagraphContent(implode("\n", $contentLines), $sourceLine);
        }

        // A bare colon run does not interrupt only when the open paragraph
        // already carries an unclaimed colon opener the run would close (PART
        // 9 §12). Otherwise an opener ALWAYS opens: a wrong-width bare run is
        // an ordinary opener, and it interrupts the paragraph and opens an
        // (empty) block - matching carve-js / carve-rs and grammar §12
        // (markup-carve/carve#1970). The lazy-folded `- :::` marker-line
        // exception is handled in the list-item collector, not here.
        if ($this->isBareColonFence($line) && $hasUnclaimedColonFenceLine) {
            return false;
        }

        if ($this->startsNewBlock($line, $lines, $i)) {
            return true;
        }

        if ($this->wrappedBlockAttributeLength($lines, $i) !== null) {
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
     * A comment delimiter as a container keeps it: one column of the authored
     * indentation, so the container's own parse cannot read a delimiter written
     * below its column back as an authored column-0 one.
     */
    protected function keptCommentDelimiter(string $line): string
    {
        $rest = ltrim($line, " \t");

        return $rest === $line ? $rest : ' ' . $rest;
    }

    /**
     * Do these collected lines leave a comment span OPEN?
     *
     * Section 28 pairs a span's delimiters and indentation is part of neither
     * (markup-carve/carve#2471), so a closer written BELOW a container's content
     * column still belongs to the span the container already holds. Ending the
     * container there split the span, the container's own parse then read an
     * opener with no closer, section 28 made that one `%%` line comment, and the
     * PAYLOAD reached the page while both delimiters did not
     * (markup-carve/carve#2488, carve-php#2650).
     *
     * A code fence's payload is opaque, so a `%%%` written inside one opens
     * nothing. BOTH DIRECTIONS LEAK: an invented opaque body hides a real
     * opener, and a missed one invents a span that claims a real delimiter as
     * its closer - hence the block-start flag rather than a stripped line
     * everywhere (markup-carve/carve#2505). A list marker never interrupts
     * (section 10 I2), so its line is walked off only where a block may begin;
     * mid-paragraph the same characters are text.
     *
     * @param array<string> $lines Lines as the collector holds them.
     */
    protected function linesLeaveACommentSpanOpen(array $lines): bool
    {
        $openComment = null;
        $openCode = null;
        $atBlockStart = true;
        foreach ($lines as $raw) {
            foreach (explode("\n", self::stripLazyFrame($raw)) as $part) {
                $line = ltrim($part, " \t");
                if ($openComment !== null) {
                    if ($this->fencedBlockParser->isFencedCommentCloser($line, $openComment)) {
                        $openComment = null;
                        $atBlockStart = true;
                    }

                    continue;
                }
                if ($openCode !== null) {
                    if ($this->closesCodeFence($line, $openCode['char'], $openCode['length'])) {
                        $openCode = null;
                        $atBlockStart = true;
                    }

                    continue;
                }
                if ($line === '') {
                    $atBlockStart = true;

                    continue;
                }
                $opener = $atBlockStart ? $this->markerFreeContent($line) : $line;
                $atBlockStart = false;
                $code = $this->fencedBlockParser->parseCodeFenceOpener($opener);
                if ($code !== null) {
                    $openCode = ['char' => $code['char'], 'length' => $code['length']];

                    continue;
                }
                $comment = $this->fencedBlockParser->parseFencedCommentOpenerAnyColumn($opener);
                if ($comment !== null) {
                    $openComment = $comment['length'];
                }
            }
        }

        return $openComment !== null;
    }

    /**
     * A closing code fence of at least the opener's run, and nothing after it.
     */
    private function closesCodeFence(string $line, string $char, int $length): bool
    {
        if (preg_match('/^(' . preg_quote($char, '/') . '+)[ \t]*$/', $line, $m) !== 1) {
            return false;
        }

        return strlen($m[1]) >= $length;
    }

    /**
     * A line's content with every leading list marker walked off.
     */
    private function markerFreeContent(string $line): string
    {
        $content = $line;
        for ($guard = 0; $guard < 64; $guard++) {
            $marker = $this->listParser->parseListItemMarker($content);
            if ($marker === null) {
                return $content;
            }
            $content = ltrim($marker['content'], " \t");
        }

        return $content;
    }

    /**
     * A container's extent, cut short before an invisible DEFINITION in it.
     *
     * A definition is classified before block ownership, so in a body host it
     * still reaches the rebase and is consumed. In an ITEM host a DIV keeps the
     * definition as text, while a QUOTE releases it under carve-php#1908.
     *
     * A VERBATIM BODY INSIDE THE CONTAINER IS SKIPPED, because a definition
     * written in one is payload and not a definition at all. Without that the
     * scan cut a div's extent at its own code content.
     *
     * @param array<string> $lines
     * @param int $start
     * @param int $end
     */
    protected function containerExtentBeforeADefinition(array $lines, int $start, int $end): int
    {
        $fence = null;
        for ($j = $start + 1; $j <= $end; $j++) {
            $content = ltrim($lines[$j], " \t");
            if ($fence !== null) {
                if ($this->fencedBlockParser->isCodeFenceCloser($content, $fence[0], $fence[1])) {
                    $fence = null;
                }

                continue;
            }

            $opener = $this->fencedBlockParser->parseCodeFenceOpener($content)
                ?? $this->fencedBlockParser->parseRawBlockOpener($content);
            if ($opener !== null) {
                $fence = [((string)$opener['fence'])[0], (int)$opener['length']];

                continue;
            }

            if ($this->isDefinitionLineForEnclosingItem($content)) {
                return $j - 1;
            }
        }

        return $end;
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
            || preg_match(self::FOOTNOTE_DEFINITION_PATTERN, $line) === 1;
    }

    /**
     * Whether a collected item line is a comment - either spelling, any column.
     *
     * A comment renders nothing but still ends the open paragraph, so the line
     * after it starts a new one rather than folding into the comment's entry or
     * past it (carve-php#800).
     *
     * @param string $line
     * @param int $at
     */
    protected function isCommentLineOrFence(string $line, int $at = 0): bool
    {
        // `/A` ANCHORS AT `$at` WHERE `^` ANCHORS AT ZERO, and the two are the
        // same assertion when `$at` is zero. Spelled this way so a walk that has
        // crossed a container prefix can ask without cutting the tail out of the
        // line to ask it (markup-carve/carve-php#1437).
        return preg_match('/[ \t]*%%/A', $line, $ignored, 0, $at) === 1;
    }

    /**
     * Does a collected item entry OPEN A CONTAINER?
     *
     * A container's body is re-read LINE BY LINE from the entry it opened on,
     * so a lazy line folded into that entry with an embedded newline never
     * arrives as a line of its own: `- > - x` over an indented `[r]: /url`
     * handed the quote one "line" holding `> - x\n[r]: /url`, and the sub-list
     * inside the quote was lost with its marker back as literal text
     * (markup-carve/carve-php#1858). Such a line is pushed as its OWN entry
     * instead, one column in - the same move
     * {@see self::isCommentLineOrFence()} already earns for an entry that
     * renders nothing.
     *
     * @param string $entry
     */
    private function entryOpensContainer(string $entry): bool
    {
        $first = strstr($entry, "\n", true);
        if ($first === false) {
            $first = $entry;
        }

        return $this->blockQuoteLineContent($first) !== null
            || $this->listParser->parseListItemMarker($first) !== null;
    }

    /**
     * Whether a block-attribute line could start at `$at`, by its first byte.
     *
     * The offset-side head for
     * {@see self::parseSingleLineBlockAttributePayload()}, pinned against it by
     * `OffsetHeadsAgreeWithTheirParsersTest` for the reason
     * {@see \MarkupCarve\Carve\Parser\Block\TableParser::isTableRowHead()} gives.
     */
    protected static function isBlockAttributeHead(string $line, int $at = 0): bool
    {
        return ($line[$at] ?? '') === '{';
    }

    protected static function lastInteriorNewline(string $line): int
    {
        if (strlen($line) < 2) {
            return -1;
        }

        // ONE LIBRARY SCAN, NOT A BYTE LOOP. This runs once for every line the
        // tracker is handed, so a PHP-level loop here costs more than the copy
        // the offset walk removes - measured as a 5 percent regression on an
        // ordinary document before it was written this way. The `-2` offset is
        // what makes the line's own terminator not count as interior.
        $pos = strrpos($line, "\n", -2);

        return $pos === false ? -1 : $pos;
    }

    /**
     * Whether a collected line renders nothing and so folds as text.
     *
     * Asked only about lines already INSIDE a container, which is why the
     * abbreviation definition does not count: PART 12 §7 recognizes one only as
     * a direct child of the document, so under an item it renders and is
     * ordinary content. Counted invisible it was appended to the entry above it
     * rather than pushed as its own line, and `. :: t` over `*[A]: b` handed
     * the nested parse one entry holding a newline - which no longer matched the
     * term pattern, so the definition list was never built
     * (markup-carve/carve-php#2632).
     */
    protected function isFoldableInvisibleLine(string $line): bool
    {
        if (preg_match('/^[ \t]*%%/', $line) === 1) {
            return false;
        }

        return $this->isInvisibleOrAttributeLine($line, false);
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
        return $this->referenceDefinitionExtractor->matchDefinitionLine($line) !== null
            || preg_match(self::FOOTNOTE_DEFINITION_PATTERN, $line) === 1;
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
        $trimmed = ltrim($line, " \t");

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
            // THE ANSWER IS NOT KNOWN YET (carve-php#1851). Definitions are
            // collected during this same walk, so a reference defined BELOW
            // the image is still unresolved here, and answering no would fold
            // the caption line into the paragraph - a decision nothing later
            // can take back, because the line stops being a separate line at
            // all.
            //
            // A lone image paragraph is therefore captionable either way, and
            // the caption becomes the UNBOUND SLOT that PART 9R R7 describes.
            // settleDeferredImageCaptions() binds it where the reference
            // resolved and hands every source line back where it did not.
            return true;
        }

        return $children[0] instanceof Math && self::isCaptionableDisplayMath($children[0]);
    }

    /**
     * Is this the display-math span PART 9 §4 makes a captionable host?
     *
     * ON ONE LINE, which is the half this engine was missing. §4's second
     * prose-spelled host is a paragraph whose whole content is a display-math
     * span, and carve-js, carve-rs and the executable spec all read that test
     * on a SINGLE line - the spec's own test requires it deliberately. Spanning
     * a line boundary, carve-php alone built a figure where the other three
     * leave a paragraph and the caption line literal
     * (markup-carve/carve-php#1422).
     *
     * markup-carve/carve#1352 did not move this. That ruling made a BRACKETED
     * construct admit a soft break like any other inline content; the
     * captionable host is a different question and its answer is unchanged.
     *
     * @param \MarkupCarve\Carve\Node\Inline\Math $node
     */
    private static function isCaptionableDisplayMath(Math $node): bool
    {
        return $node->isDisplay() && !str_contains($node->getContent(), "\n");
    }

    /**
     * Whether a line is a standalone single-line block-attribute line: a
     * `{...}` block alone on the line that yields attributes (matching the
     * single-line case recognised by tryParseBlockAttributes). Braced inline
     * markers (`* = + - ~ ^`) and comment blocks (`%`) are excluded.
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
        return preg_match('/^[.#:a-zA-Z_]/', $attrStr) === 1
            && !str_starts_with($attrStr, '%')
            && $this->inlineParser->isValidAttrPayload($attrStr);
    }

    /**
     * Normalize one standalone single-line block-attribute line. Adjacent
     * `{...}` blocks merge as if their contents were separated by spaces.
     */
    protected function parseSingleLineBlockAttributePayload(string $line): ?string
    {
        // THE HEAD IS READ BEFORE THE COPY. `rtrim()` cannot move byte zero, so
        // asking the head first answers the same question and skips copying the
        // line for every one that is not an attribute block at all
        // (markup-carve/carve-php#1437). Spelled inline rather than through
        // `isBlockAttributeHead()` for the reason
        // `IndentationHelper::isBlankFrom()` gives; the pair is pinned by
        // `OffsetHeadsAgreeWithTheirParsersTest`.
        if (($line[0] ?? '') !== '{') {
            return null;
        }

        $line = rtrim($line, " \t");
        $length = strlen($line);

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
     * How many blank lines immediately precede `$start`.
     *
     * Counting rather than testing one line back: `caption_slot` allows exactly
     * one, so "is the line above blank" cannot tell one from two and a caption
     * attached across any run at all.
     *
     * @param array<string> $lines
     * @param int $start
     */
    protected function blankLineRunBefore(array $lines, int $start): int
    {
        $run = 0;
        for ($i = $start - 1; $i >= 0; $i--) {
            if (!IndentationHelper::isBlankLine($lines[$i])) {
                break;
            }
            $run++;
        }

        return $run;
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
        // content holding at least one character that is not `whitespace`. `^ `
        // alone (or `^\t…`) is not a caption, exactly as `# ` / `#\t…` is not a
        // heading - but `whitespace` is a space or a tab and NOTHING else
        // (PART 1), so a lone NBSP, VERTICAL TAB or FORM FEED is content and
        // does make a caption (markup-carve/carve-php#1038).
        if (!preg_match('/^\^ +(.*' . StringUtil::NON_WHITESPACE_CLASS . '.*)$/', $line, $matches)) {
            return null;
        }

        // `caption_slot = [blank_line], caption` carries ONE optional blank
        // line, and PART 9 §4 spells the same allowance in words: adjacent or
        // exactly one blank line attaches, TWO DETACH and leave the `^ ` line an
        // ordinary paragraph.
        //
        // The distance has to be recovered HERE, by looking back, because
        // nothing carries it in: parseBlocksImpl() skips a run of blank lines at
        // the top of its loop without counting them, so by the time any block
        // parser is dispatched the run is gone. That is why one shared predicate
        // covers all five captionable hosts rather than five copies drifting
        // apart - the hosts are decided further down this method, on the block
        // this caption would attach to, and the distance is the same question
        // for every one of them.
        $blankLines = $this->blankLineRunBefore($lines, $start);
        if ($blankLines > 1) {
            return null;
        }
        // An invisible interrupter still occupies its source line. It renders
        // nothing, but it is not caption_slot's optional blank line and a
        // caption cannot attach across it (carve#1028).
        $lineBeforeSlot = $start - $blankLines - 1;
        if ($lineBeforeSlot >= 0 && $this->isInvisibleOrAttributeLine($lines[$lineBeforeSlot])) {
            return null;
        }

        // `$matches[1]` is a SUFFIX of the line, so the difference is exactly
        // the `^` plus the spaces after it - which is where the caption's own
        // text starts, and where a search for it has to begin.
        $markerWidth = strlen($line) - strlen($matches[1]);

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
            // Stop at a new TABLE, which is not the same as a line beginning
            // with a pipe. A bare `|` is no row - `isTableRow()` says so, and a
            // `|` line on its own renders as a paragraph everywhere - so asking
            // the character rather than the parser ended the caption over
            // ordinary text (markup-carve/carve-php#2632).
            if ($this->isTableBlockStart($nextLine, $lines, $i)) {
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

        foreach ($captionLines as $captionIndex => $captionLine) {
            $captionLines[$captionIndex] = rtrim($captionLine, " \t");
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

        // Handle FigureGroup - §4's SIXTH host (PART 9 §4c): a caption after
        // the closing fence of a bare `::: figure` container is the caption of
        // the WHOLE group. Only this kind; a `^ ` line after any other `:::`
        // closer stays ordinary paragraph content.
        if ($lastChild instanceof FigureGroup) {
            // A SECOND `^ ` line does not replace an attached group caption -
            // the same rule the table arm below spells out (carve-php#1199).
            if ($lastChild->hasCaption()) {
                return null;
            }

            $caption = new Caption();
            $caption->setPos($this->wholeLinesSpan($start, $i - 1));
            $this->inlineParser->parse(
                $caption,
                $captionText,
                $start,
                true,
                $this->captionSourceMap($start, $captionLines, $markerWidth),
            );
            $lastChild->setCaption($caption);
            // The caption is the group's own child written after the closing
            // fence, so the group's span reaches the end of the caption line -
            // the same containment the table arm preserves (carve#565).
            $this->widenSpanTo($lastChild, $caption->getPos());

            return $linesConsumed;
        }

        // Handle Table - add caption directly to table
        if ($lastChild instanceof Table) {
            // A SECOND `^ ` line does not replace the caption already attached.
            // PART 9 section 4, `resources/grammar.ebnf` near line 1101: "a
            // further `^ ` line does NOT continue the caption ...; it ends the
            // caption and, having no captionable block to attach to, is
            // ordinary paragraph text."
            //
            // Overwriting discarded the first caption SILENTLY - `^ One` then
            // `^ Two` published `<caption>Two</caption>` and `One` appeared
            // nowhere in the output. carve-js and carve-rs both keep the first
            // and leave the second as a paragraph (markup-carve/carve-php#1199).
            if ($lastChild->getCaption() !== null) {
                return null;
            }

            $caption = new Caption();
            $caption->setPos($this->wholeLinesSpan($start, $i - 1));
            $this->inlineParser->parse(
                $caption,
                $captionText,
                $start,
                true,
                $this->captionSourceMap($start, $captionLines, $markerWidth),
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
            foreach ($lastChild->getAttributeEntries() as $key => $value) {
                $figure->setAttribute($key, $value);
                $lastChild->removeAttribute($key);
            }

            $caption = new Caption();
            $caption->setPos($this->wholeLinesSpan($start, $i - 1));
            $this->inlineParser->parse(
                $caption,
                $captionText,
                $start,
                true,
                $this->captionSourceMap($start, $captionLines, $markerWidth),
            );

            $parent->replaceChild(count($children) - 1, $figure);
            $figure->appendChild($lastChild);
            $figure->appendChild($caption);

            return $linesConsumed;
        }

        // Handle BlockQuote - wrap in figure
        if ($lastChild instanceof BlockQuote) {
            $figure = new Figure();

            foreach ($lastChild->getAttributeEntries() as $key => $value) {
                $figure->setAttribute($key, $value);
                $lastChild->removeAttribute($key);
            }

            $caption = new Caption();
            $caption->setPos($this->wholeLinesSpan($start, $i - 1));
            $this->inlineParser->parse(
                $caption,
                $captionText,
                $start,
                true,
                $this->captionSourceMap($start, $captionLines, $markerWidth),
            );

            $parent->replaceChild(count($children) - 1, $figure);
            $figure->appendChild($lastChild);
            $figure->appendChild($caption);

            return $linesConsumed;
        }

        // Handle Paragraph containing only an Image - wrap in figure
        if ($lastChild instanceof Paragraph) {
            $this->parseDeferredScratchInlines($lastChild);
            $paragraphChildren = $lastChild->getChildren();
            if (
                count($paragraphChildren) === 1
                && $paragraphChildren[0] instanceof Image
            ) {
                // An UNRESOLVED reference image is not an image: `[nope]`
                // resolves to nothing, so every writer emits the author's
                // source text and there is no rendered image for a caption to
                // attach to. Promoting it builds a `<figure>` around literal
                // text, which carve-js and carve-rs both decline
                // (carve-php#751). PART 12 §3a keeps the node with `ref` and
                // `rawRef` precisely so it can be recognized below, where the
                // slot is held until resolution settles the question.
                $image = $paragraphChildren[0];

                // Hold the slot rather than binding it: whether this is a
                // figure is not decided until every definition is known.
                if (UnresolvedReference::sourceOf($image) !== null) {
                    $rawLines = array_slice($lines, $start, $linesConsumed);
                    $this->deferredImageCaptions ??= new WeakMap();
                    $this->deferredImageCaptions[$lastChild] = [
                        'image' => $image,
                        'captionText' => $captionText,
                        'captionLines' => $captionLines,
                        'start' => $start,
                        'markerWidth' => $markerWidth,
                        'rawLines' => $rawLines,
                        // Measured HERE, not where the slot settles: settling
                        // runs after the walk, when the line map these indices
                        // resolve through belongs to some other container.
                        'rawSpans' => $this->givenBackLineSpans($start, $rawLines),
                    ];

                    return $linesConsumed;
                }

                $figure = new Figure();

                // A preceding block-attribute line (carried on the paragraph)
                // floats onto the figure. The image's OWN trailing attributes
                // stay on the <img> -- the same target as a standalone block
                // image -- so they are NOT transferred to the figure.
                foreach ($lastChild->getAttributeEntries() as $key => $value) {
                    $figure->setAttribute($key, $value);
                }

                // Create caption
                $caption = new Caption();
                $this->inlineParser->parse(
                    $caption,
                    $captionText,
                    $start,
                    true,
                    $this->captionSourceMap($start, $captionLines, $markerWidth),
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
                && self::isCaptionableDisplayMath($paragraphChildren[0])
            ) {
                $figure = new Figure();

                // A preceding block-attribute line (`{#eq-x}`) sits on the
                // paragraph; move it onto the figure so the id is on <figure>,
                // not the inner <p>, and drives the crossref.
                foreach ($lastChild->getAttributeEntries() as $key => $value) {
                    $figure->setAttribute($key, $value);
                }
                foreach (array_keys($lastChild->getAttributeEntries()) as $key) {
                    $lastChild->removeAttribute($key);
                }

                $caption = new Caption();
                $this->inlineParser->parse(
                    $caption,
                    $captionText,
                    $start,
                    true,
                    $this->captionSourceMap($start, $captionLines, $markerWidth),
                );

                $parent->replaceChild(count($children) - 1, $figure);
                $figure->appendChild($lastChild);
                $figure->appendChild($caption);

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
            $this->parseDeferredScratchInlines($lastChild);
            $this->inlineParser->parse($lastChild, ' ' . $content, $line);
        }
    }

    private function materializeScratchParagraphs(): void
    {
        if ($this->deferredScratchInlines === null) {
            return;
        }
        $pending = [];
        foreach ($this->deferredScratchInlines as $paragraph => $deferred) {
            $pending[] = $paragraph;
        }
        foreach ($pending as $paragraph) {
            $this->parseDeferredScratchInlines($paragraph);
        }
    }

    protected function parseDeferredScratchInlines(Paragraph $paragraph): void
    {
        $deferred = $this->deferredScratchInlines[$paragraph] ?? null;
        if ($deferred === null) {
            return;
        }
        unset($this->deferredScratchInlines[$paragraph]);
        [$content, $start, $contentLines] = $deferred;
        $this->inlineParser->parse($paragraph, $content, $start, sourceMap: $this->foldedLinesMap($contentLines));
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
        if ($this->listParser->parseListItemMarker(ltrim($line, " \t")) !== null) {
            return true;
        }

        return $this->startsNewBlock($line, $lines, $index);
    }

    /**
     * Whether a line ENDS an open definition term.
     *
     * @param string $line
     * @param array<string>|null $lines
     * @param int|null $index
     */
    protected function endsDefinitionTerm(string $line, ?array $lines = null, ?int $index = null): bool
    {
        if ($this->isCaptionLine($line)) {
            return false;
        }

        return $this->endsHeadingOrQuote($line, $lines, $index);
    }

    /**
     * A `^ ` caption line, in the one spelling every caller reads it by.
     *
     * @param string $line
     */
    protected function isCaptionLine(string $line): bool
    {
        return preg_match('/^\^ +.*' . StringUtil::NON_WHITESPACE_CLASS . '/', $line) === 1;
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
     * @param bool $paragraphOpen Whether an open paragraph precedes this line.
     * @param array<string>|null $lines
     * @param int|null $index
     */
    protected function endsBlockQuote(
        string $line,
        bool $paragraphOpen,
        ?array $lines = null,
        ?int $index = null,
    ): bool {
        // A list marker ends the quote only when there is no open paragraph to
        // fold into; with an open paragraph it folds (does not end the quote).
        if (!$paragraphOpen && $this->listParser->parseListItemMarker(ltrim($line, " \t")) !== null) {
            return true;
        }

        if ($this->isInvisibleOrAttributeLine($line)) {
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

        // Caption `^ text` ends an open fold, because it ATTACHES to the block
        // above it (PART 2, LAZY CONTINUATION: "not a caption ('^ ' ...), which
        // attaches to the blockquote instead"). A host that cannot TAKE a
        // caption asks {@see self::endsDefinitionTerm()} instead.
        if ($this->isCaptionLine($line)) {
            return true;
        }

        // NO `%%%` ARM HERE. One used to return true for any line opening
        // `%%%`, which shadowed the `%` case in `startsInterruptingBlock()`
        // below - the case that asks §28's closer question - and made a
        // DEGRADED fence interrupt where the `%%` line form does not
        // (carve-php#1877, markup-carve/carve#1903). Removing it is what lets
        // that case answer, and it had never been reached before.
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
                return preg_match('/^#{1,6} .*' . StringUtil::NON_WHITESPACE_CLASS . '/', $line) === 1;
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
                if ($index !== null && isset($this->state->session->blockQuoteLazySourceLines[$this->sourceLineFor($index)])) {
                    return false;
                }

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
        $opener = $this->fencedBlockParser->parseRawBlockOpener($line)
            ?? $this->fencedBlockParser->parseCodeFenceOpener($line);
        if ($opener === null) {
            return false;
        }

        if ($lines === null || $index === null) {
            return true;
        }

        $char = $opener['char'] ?? $opener['fence'][0];
        $length = $opener['length'];
        $count = count($lines);

        // REFUTE FROM THE INDEX FIRST. The scan below is O(remaining lines) and
        // this predicate is asked once per fence-shaped line, so a document of
        // UNCLOSABLE fences pays it once per fence - which is quadratic, and is
        // what `AttachedFenceLookaheadScaleTest` measures. The index is built
        // once per line set and is a SUPERSET of what the matcher below can
        // accept, so a negative answer here is final and a positive one still
        // goes to the real scan (the invariant `fenceCloserIndex()` documents).
        //
        // The other three callers of that index already refute this way; this
        // one scanned because nothing asked it often enough to matter until the
        // §17 L3 boundary started classifying every attached run's first line.
        if (!$this->codeCloserPossible($this->fenceCloserIndex($lines)['code'], $char, $length, $index)) {
            return false;
        }

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
        if ($this->state->frame->commentFenceLastIndex === null) {
            $index = [];
            foreach ($lines as $i => $candidate) {
                // Any column: the consumption sites read an indented fence, so
                // the index answering whether a closer exists must see one too.
                $info = $this->fencedBlockParser->parseFencedCommentOpenerAnyColumn($candidate);
                if ($info !== null) {
                    $index[$info['length']] = $i;
                }
            }

            $this->state->frame->commentFenceLastIndex = $index;
        }

        return $this->state->frame->commentFenceLastIndex[$length] ?? -1;
    }

    /**
     * @param string $line
     * @param int $depth
     */
    private static function quotedContentAtDepth(string $line, int $depth): ?string
    {
        return BlockGrammar::quotedContentAtDepth($line, $depth);
    }

    /**
     * @param array<string> $lines
     * @param int $index
     * @param int $depth
     * @param string $char
     * @param int $length
     * @param array<string, array{from:int, end:int, maxRun:int}> $memo
     * @param int $column
     */
    private function quotedCodeFenceHasCloser(array $lines, int $index, int $depth, string $char, int $length, array &$memo, int $column = 0): bool
    {
        $key = $depth . ':' . $column . ':' . $char;
        $start = $index + 1;
        $cached = $memo[$key] ?? null;
        if ($cached !== null && $start >= $cached['from'] && $start <= $cached['end'] && $length > $cached['maxRun']) {
            return false;
        }
        $maxRun = 0;
        $count = count($lines);
        for ($i = $start; $i < $count; $i++) {
            $content = self::quotedContentAtDepth($lines[$i], $depth);
            if ($content === null) {
                break;
            }
            if (IndentationHelper::getLeadingColumns($content) !== $column) {
                continue;
            }
            $content = IndentationHelper::stripLeadingColumns($content, $column);
            if ($this->fencedBlockParser->isCodeFenceCloser($content, $char, $length)) {
                return true;
            }
            if (preg_match('/^(`{3,}|~{3,})[ \t]*$/', $content, $match) === 1 && $match[1][0] === $char) {
                $maxRun = max($maxRun, strlen($match[1]));
            }
        }
        $memo[$key] = ['from' => $start, 'end' => $i, 'maxRun' => $maxRun];

        return false;
    }

    /**
     * @param array<string> $lines
     * @param int $index
     * @param int $length
     */
    protected function hasClosingCommentFenceAheadInBlockQuote(array $lines, int $index, int $length): bool
    {
        if ($this->state->frame->blockQuoteCommentCloserIndex === null) {
            $nextByLength = [];
            $indexByLine = [];
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                if (IndentationHelper::isBlankLine($lines[$i])) {
                    $nextByLength = [];

                    continue;
                }
                $content = $this->blockQuoteLineContent($lines[$i]);
                if ($content === null) {
                    // A non-quoted line ends the quoted region. A later fence
                    // cannot close an opener before this boundary.
                    $nextByLength = [];

                    continue;
                }
                $info = $this->fencedBlockParser->parseFencedCommentOpener($content);
                if ($info === null) {
                    continue;
                }
                $fenceLength = $info['length'];
                $indexByLine[$i] = $nextByLength[$fenceLength] ?? -1;
                $nextByLength[$fenceLength] = $i;
            }
            $this->state->frame->blockQuoteCommentCloserIndex = $indexByLine;
        }

        return ($this->state->frame->blockQuoteCommentCloserIndex[$index] ?? -1) > $index;
    }

    /**
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $state
     * @param string $line Collected line, stripped to content-relative indentation.
     * @param bool $atContentColumn Whether the line REACHED the container's
     *
     * @return \MarkupCarve\Carve\Parser\TrailingBlockState
     */
    private function advanceTrailingStateCore(
        TrailingBlockState $state,
        string $line,
        bool $atContentColumn = false,
    ): TrailingBlockState {
        return $this->continuationsMapper()->advanceTrailingStateCore($state, $line, $atContentColumn);
    }

    /**
     * @param array<string> $lines Body lines, already rebased.
     *
     * @return array<string>
     */
    private function footnoteBodyDefinitionReach(array $lines): array
    {
        return $this->continuationsMapper()->footnoteBodyDefinitionReach($lines);
    }

    /**
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $state
     * @param string $line
     * @param array<string> $lines
     * @param int $index
     * @param bool $atContentColumn
     * @param int $stripColumns
     * @param bool $closerKnownAhead
     *
     * @return \MarkupCarve\Carve\Parser\TrailingBlockState
     */
    private function advanceTrailingStateWithFenceLookaheadCore(
        TrailingBlockState $state,
        string $line,
        array $lines,
        int $index,
        bool $atContentColumn = false,
        int $stripColumns = 0,
        bool $closerKnownAhead = false,
    ): TrailingBlockState {
        return $this->continuationsMapper()->advanceTrailingStateWithFenceLookaheadCore($state, $line, $lines, $index, $atContentColumn, $stripColumns, $closerKnownAhead);
    }

    /**
     * @param string $line
     *
     * @return array{fence: string, length: int, char?: string}|null
     */
    private function itemFenceOpenerAt(string $line): ?array
    {
        return $this->continuationsMapper()->itemFenceOpenerAt($line);
    }

    /**
     * Compact-list looseness scan over an item's collected (content-column
     * dedented) sub-content lines. Mirrors carve-js: for each internal blank
     * line, look at the next non-blank line. Content at or past the sub-list's
     * content column belongs to that sub-list (its looseness is decided by its
     * own recursive parse) and does not loosen THIS item; every other block
     *
     * @param array<string> $subLines The item's dedented sub-content lines.
     * @param bool $sourceIsTheItemBody Whether these lines are the item's WHOLE
     *   body rather than a chunk collected after a preceding block. A container
     *   that is the whole body is not one block among several, so its interior
     *   blank does separate two of the item's rendered blocks and loosens it
     *   (markup-carve/carve#1602); the identical five lines reach this scan in
     *   both shapes, so only the caller can tell them apart.
     */
    protected function subContentHasLooseningBlank(array $subLines, bool $sourceIsTheItemBody): bool
    {
        // The content column of the sub-list item the scan is in; content at or
        // past it belongs to the sub-list, not this item. A marker at column 0
        // opens a sibling sub-list item or a new sibling sub-list, whose own
        // column then applies; one indented further folds into the open item
        // (carve-php#2262, carve-js#1951). Markers inside a fence never count.
        $subCol = -1;

        $n = count($subLines);
        // The last line that renders anything. A colon span reaching past it
        // and starting at line 0 IS the body, rather than one block in it.
        $lastContentIdx = -1;
        for ($k = $n - 1; $k >= 0; $k--) {
            if ($subLines[$k] !== '') {
                $lastContentIdx = $k;

                break;
            }
        }

        // Track fenced-code regions: a blank line INSIDE an open fence is
        // verbatim content, not an interior block separator, so it must not
        // loosen the item (carve#326 case C; matches carve-rs / carve-js).
        //
        // ONE STATEFUL LEFT-TO-RIGHT PASS, not one scan per line: each closed
        // span is jumped over whole and spans never overlap, so the walk stays
        // linear in the number of lines.
        $fenceChar = null;
        $fenceLength = 0;
        $fenceBase = 0;
        $k = 0;
        while ($k < $n) {
            $sl = $subLines[$k];
            if ($fenceChar !== null) {
                // The closer sits at the fence's own base or at the item's
                // column, and nowhere between: a run in that band is payload,
                // so the fence stays open across the blank lines below it.
                $closerColumn = IndentationHelper::getLeadingColumns($sl, $fenceBase + 1);
                if (
                    ($closerColumn === 0 || $closerColumn === $fenceBase)
                    && $this->fencedBlockParser->isCodeFenceCloser(ltrim($sl, " \t"), $fenceChar, $fenceLength)
                ) {
                    $fenceChar = null;
                }
                $k++;

                continue;
            }
            $authored = $sl;
            if (
                ($k === 0 || $subLines[$k - 1] === '')
                && ($subCol < 0 || IndentationHelper::getLeadingColumns($sl, $subCol) < $subCol)
            ) {
                // Normalize only a block-start line owned by this item. A
                // nested item's closing run belongs to its own recursive scan.
                $authored = ltrim($sl, " \t");
            }
            $opener = $this->fencedBlockParser->parseCodeFenceOpener($authored)
                ?? $this->fencedBlockParser->parseRawBlockOpener($authored);
            if ($opener !== null) {
                $fenceChar = $opener['fence'][0];
                $fenceLength = $opener['length'];
                $fenceBase = IndentationHelper::getLeadingColumns($sl);
                $k++;

                continue;
            }
            // THE COLON FAMILY IS ONE OPENER HERE. `parseDivFenceOpener()`
            // answers for the bare div, the admonition and the line block
            // alike, so the three spellings of the rule are one branch rather
            // than three (carve-rs#1307 needed three).
            $colon = $this->fencedBlockParser->parseDivFenceOpener($authored);
            if ($colon !== null) {
                $end = $this->colonSpanEndForLooseness($subLines, $k, $colon['length']);
                if (!($sourceIsTheItemBody && $k === 0 && $end > $lastContentIdx)) {
                    $k = $end;

                    continue;
                }
            }
            if ($sl !== '') {
                // `=== 0` only needs to know whether the run reaches column 1,
                // so the walk stops there. Unbounded it re-measures the whole
                // indentation run of every body line at every nesting level,
                // which is the quadratic shape NestedContainerRescanTest bounds
                // (markup-carve/carve-php#2265, markup-carve/carve#752).
                if (
                    ($subCol < 0 || IndentationHelper::getLeadingColumns($sl, 1) === 0)
                    && $this->listParser->parseListItemMarker(ltrim($sl, " \t")) !== null
                ) {
                    $subCol = $this->markerContentColumn($sl);
                }
                $k++;

                continue;
            }
            $j = $k + 1;
            while ($j < $n && $subLines[$j] === '') {
                $j++;
            }
            if ($j >= $n) {
                $k++;

                continue;
            }
            if ($subCol >= 0 && IndentationHelper::getLeadingColumns($subLines[$j], $subCol) >= $subCol) {
                // Belongs to the sub-list; its looseness is its own business.
                $k++;

                continue;
            }
            $candidate = $subLines[$j];
            $authored = ltrim($candidate, " \t");
            if (
                $authored !== $candidate
                && $this->listParser->parseListItemMarker($authored) === null
                && $this->lineOpensBlockForLooseness($authored)
            ) {
                $candidate = $authored;
            }
            if (!$this->lineOpensBlockForLooseness($candidate)) {
                return true;
            }
            $k++;
        }

        return false;
    }

    /**
     * The line index just past a colon fence opened at `$openIdx`, for the
     * looseness scan.
     *
     * The closer is an EXACT-length colon run ({@see
     * \MarkupCarve\Carve\Parser\Block\FencedBlockParser::isDivFenceCloser()}),
     * which is what makes the span skippable at all: a longer run below is a
     * nested opener, not this one's end. With no closer at all everything
     * below the opener IS its content, so the span runs to the end of the
     * chunk - the same answer a closer written on the last line would give.
     *
     * A COLON RUN INSIDE VERBATIM PAYLOAD CLOSES NOTHING. This walk tracks the
     * code fence for the same reason the caller does: a `:::` line inside a
     * code block is that block's text, and reading it as the container's end
     * put the span's end ABOVE the real closer - so the caller resumed on the
     * code fence's own closing line, opened a fence there, and swallowed the
     * item-level blank and the paragraph below the container with it.
     *
     * @param array<string> $subLines The item's dedented sub-content lines.
     * @param int $openIdx The index of the opener line.
     * @param int $fenceLength The opener's colon-run length.
     */
    protected function colonSpanEndForLooseness(array $subLines, int $openIdx, int $fenceLength): int
    {
        $n = count($subLines);
        $base = IndentationHelper::getLeadingColumns($subLines[$openIdx]);
        $fenceChar = null;
        $fenceLen = 0;
        for ($j = $openIdx + 1; $j < $n; $j++) {
            $line = $subLines[$j];
            $column = IndentationHelper::getLeadingColumns($line);
            if ($column !== 0 && $column !== $base) {
                continue;
            }
            $line = ltrim($line, " \t");
            if ($fenceChar !== null) {
                if ($this->fencedBlockParser->isCodeFenceCloser($line, $fenceChar, $fenceLen)) {
                    $fenceChar = null;
                }

                continue;
            }
            $opener = $this->fencedBlockParser->parseCodeFenceOpener($line);
            if ($opener !== null) {
                /** @var string $fenceChar */
                $fenceChar = $opener['char'];
                /** @var int $fenceLen */
                $fenceLen = $opener['length'];

                continue;
            }
            if ($this->fencedBlockParser->isDivFenceCloser($line, $fenceLength)) {
                return $j + 1;
            }
        }

        return $n;
    }

    /**
     * The line index of a fenced comment span's closer, opened at `$openIdx`,
     * for the looseness scan - or the last index when the item ends first.
     *
     * The caller's `continue` steps one further, so the last index lands past the
     * end and the scan finds nothing visible. That is the right answer twice
     * over. With no closer, PART 9 §28 gives the fence a body that recognizes no
     * block construct, so every line below the opener is payload. And a span
     * whose payload DEDENTS OUT of the item takes the item's end with it: the
     * collector stops there, so a paragraph written below the closer is not the
     * item's second one, and skipping to the closer regardless let an outside
     * paragraph loosen the list.
     *
     * @param array<string> $lines
     * @param int $openIdx The index of the opener line.
     * @param int $fenceLength The opener's percent-run length.
     * @param int $baseIndent The item's marker column.
     * @param int $contentIndent The item's content column, bounding the measure.
     */
    protected function commentSpanEndForLooseness(
        array $lines,
        int $openIdx,
        int $fenceLength,
        int $baseIndent,
        int $contentIndent,
    ): int {
        $n = count($lines);
        for ($j = $openIdx + 1; $j < $n; $j++) {
            if ($this->fencedBlockParser->isFencedCommentCloserAnyColumn($lines[$j], $fenceLength)) {
                return $j;
            }
            if (
                !IndentationHelper::isBlankLine($lines[$j])
                && IndentationHelper::getLeadingColumns($lines[$j], $contentIndent) <= $baseIndent
            ) {
                return $n;
            }
        }

        return $n;
    }

    /**
     * The marker width of a list item, i.e. the column its content starts at
     * relative to the marker's own indent.
     *
     * ORDERED and BULLET markers are measured without item metadata. An
     * abutting attribute block contributes zero, as does a task checkbox:
     * `-{#k} [ ] item` and `- item` both put the body at column 2
     * (markup-carve/carve#1701, #1698).
     *
     * One helper rather than a copy per call site: the width is consulted by
     * the list parser, by the implicit-heading pre-scan and by the looseness
     * scan, and when those disagreed the pre-scan indexed a heading the
     * renderer never emitted (carve-php#580).
     *
     * @param string $stripped The marker line with its leading indent removed.
     * @param array{type: string, content: string, attributesWidth?: int} $info
     *   Parsed marker info.
     */
    protected function listMarkerWidth(string $stripped, array $info): int
    {
        return $this->listMarkerWidthFor(
            $info['type'],
            strlen($stripped) - strlen($info['content']),
            $info['attributesWidth'] ?? 0,
        );
    }

    /**
     * The content-column width of a marker whose head spans `$span` bytes.
     *
     * A TASK'S COLUMN IS ITS BULLET'S. Its head runs
     * past the checkbox, because that is where its CONTENT starts, but the
     * column a continuation line has to reach is the bullet's - which is why
     * the two numbers are different here and only here. Spelled once so the
     * offset walk in {@see self::headingReferenceScanLine()} and the copying
     * one above cannot drift (markup-carve/carve-php#1463).
     *
     * An attribute block is item metadata, not marker width, so it is removed
     * from every head. `$span` cannot answer this on its own: it has already
     * counted the checkbox or attributes, and neither moves the column.
     *
     * @param string $type The marker head that matched.
     * @param int $span Bytes from the marker's first byte to its content.
     * @param int $attrsWidth Bytes of the abutting `{...}` block, 0 if none.
     */
    protected function listMarkerWidthFor(string $type, int $span, int $attrsWidth = 0): int
    {
        return $type === ListBlock::TYPE_TASK ? 2 : $span - $attrsWidth;
    }

    /**
     * {@see \MarkupCarve\Carve\Parser\Block\ListParser::markerHeadAt()} asked
     * of a copy, for a subject the offset form is not exact on.
     *
     * @return array{name: string, content: int, attrs: int}|null
     */
    protected function markerHeadFromCopy(string $line, int $at, int $length): ?array
    {
        if (LayoutWork::$on) {
            LayoutWork::$prescan += $length - $at;
        }
        $stripped = substr($line, $at);
        $info = $this->listParser->parseListItemMarker($stripped);
        if ($info === null) {
            return null;
        }

        return [
            'name' => $info['type'],
            'content' => $at + strlen($stripped) - strlen($info['content']),
            'attrs' => $info['attributesWidth'] ?? 0,
        ];
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
     * The first line after `$index` that renders something, skipping blanks and
     * invisible lines, stripped to the item's content column - or null when the
     * item ends first.
     *
     * §17 L1b asks what sits BEHIND an invisible line, because an invisible
     * line neither is the second paragraph nor separates one from the blank
     * before it.
     *
     * NEITHER L1 NOR L1b CARRIES A COLUMN TERM, so the band between the marker
     * column and the content column is not where the item ends. A line written
     * there folds as the item's own paragraph text - the collector keeps it, and
     * the tree it builds is the content-column tree - so reading the item as
     * ended at `$contentIndent` answered the tightness question for a document
     * this parser does not produce: 54 of 180 band/content-column pairs whose
     * layout trees were identical disagreed on tightness, every one of them a
     * prose follower reading tight where its content-column twin read loose
     * (markup-carve/carve#2558, corpus 517). The item ends at or below the
     * MARKER column, which is where the collector detaches the line to document
     * level.
     *
     * @param array<string> $lines
     * @param int $baseIndent The item's marker column.
     * @param int $contentIndent
     * @param int $index
     *
     * @return string|null
     */
    protected function firstVisibleLineAfterInvisible(
        array $lines,
        int $index,
        int $baseIndent,
        int $contentIndent,
    ): ?string {
        $count = count($lines);
        $sawBlank = false;
        for ($j = $index + 1; $j < $count; $j++) {
            $line = $lines[$j];
            if (IndentationHelper::isBlankLine($line)) {
                $sawBlank = true;

                continue;
            }

            // Dedented out of the item: nothing of the item follows. The band
            // reaches the item only as a LAZY line - once a blank has
            // intervened the collected stream is closed, so a band line behind
            // one detaches to document level and the content column is the floor
            // again. Without that half `- t` / blank / `  %% c` / blank / ` z`
            // loosened a list whose `z` is not in it.
            $reach = IndentationHelper::getLeadingColumns($line, $contentIndent);
            if ($reach <= $baseIndent || ($sawBlank && $reach < $contentIndent)) {
                return null;
            }

            $stripped = IndentationHelper::stripLeadingColumns($line, $contentIndent);
            // A FENCED PERCENT BLOCK IS ONE INVISIBLE BLOCK, so the scan steps
            // over the whole span rather than over its opener. Its payload is
            // not the second paragraph - returning the opener was the guard
            // against reading `c` as one - but neither is the span the end of
            // the item, and returning the opener said it was: the paragraph
            // BELOW the closer never reached this scan, so corpus 517's `%%%`
            // spelling read tight where its `%%` twin read loose. With no closer
            // everything below is payload and the scan correctly finds nothing.
            $commentFence = $this->fencedBlockParser->parseFencedCommentOpener($stripped);
            if ($commentFence !== null) {
                $j = $this->commentSpanEndForLooseness($lines, $j, $commentFence['length'], $baseIndent, $contentIndent);

                continue;
            }
            // The second half of PART 12 §7's consequence. §7 recognizes an
            // abbreviation definition only as a direct child of the document,
            // and every line this scan walks sits in an ITEM BODY - so the same
            // shape here is ordinary paragraph text that RENDERS. It is the
            // visible line the scan exists to find, not a line to step over.
            // The looseness predicate stopped counting it invisible in #1319;
            // this scan is the OTHER site that carried the classification, and
            // it answers a different shape: reached through a line that really
            // is invisible, the abbreviation line was skipped like one more of
            // them, and the item was reported as holding nothing behind the
            // blank (markup-carve/carve#1269).
            if ($this->isInvisibleOrAttributeLine($stripped, false)) {
                continue;
            }

            $authored = ltrim($stripped, " \t");
            if (
                $authored !== $stripped
                && $this->listParser->parseListItemMarker($authored) === null
                && $this->lineOpensBlockForLooseness($authored)
            ) {
                return $authored;
            }

            return $stripped;
        }

        return null;
    }

    protected function lineOpensBlockForLooseness(
        string $line,
        bool $authoredBase = false,
        bool $invisibleArms = true,
    ): bool {
        if ($this->listParser->parseListItemMarker(ltrim($line, " \t")) !== null) {
            return true;
        }

        if ($invisibleArms) {
            if ($this->isInvisibleOrAttributeLine($line, false)) {
                return true;
            }
        } elseif (preg_match('/^[ \t]*%%/', $line) === 1) {
            return true;
        }

        // A resolved direct image on its own line is a block image (§15), so a
        // blank before it separates blocks rather than creating a second prose
        // paragraph. This must be asked here as well as in the post-parse image
        // promotion; otherwise list tightness changes while the HTML shape does
        // not expose why (carve#1705, corpus 411-5/6).
        if ($authoredBase && preg_match('/^!\[[^\]\r\n]*\]\([^()\r\n]*(?:\([^()\r\n]*\)[^()\r\n]*)*\)[ \t]*$/', $line) === 1) {
            return true;
        }

        if (preg_match('/^:{3,} +\\\\[ \t]*$/', $line) === 1) {
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
        if (preg_match('/^#{1,6} .*' . StringUtil::NON_WHITESPACE_CLASS . '/', $line)) {
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

        // Comment fences (%%%). Only a fence with an exact-width closer ahead
        // opens a block; §28 degrades an unterminated one to the LINE form, and
        // markup-carve/carve#1903 makes that classification total, ownership
        // included - so it leaves a container's frame open exactly as `%%`
        // does. The same rule already lives in `startsInterruptingBlock()`, and
        // for a long time only that copy had it (carve-php#1877).
        if ($this->fencedBlockParser->parseFencedCommentOpener($line) !== null) {
            return $this->hasClosingCommentFenceAhead($line, $lines, $index);
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

        // LIST MARKERS COME FROM THE MARKER PARSER, not from a copy of its
        // patterns. Three hand-spelled arms stood here - bullet, ordered, task -
        // and each had drifted from `ListParser::parseListItemMarker()`: neither
        // the abutting `{...}` attribute block (grammar `item_attributes`) nor a
        // multi-letter roman marker matched, so `-{} x` and `iv. x` opened a
        // list everywhere a list is parsed while answering "not a block start"
        // here. In a quote that answer decides laziness, so `> . a` over a lazy
        // `.{} b` promoted the lazy line to a nested list where every other
        // reader keeps it paragraph text (markup-carve/carve-php#2632).
        return $this->listParser->parseListItemMarker($line) !== null;
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
     * The text PART 12 §4 offsets are measured against: the source as given.
     *
     * Every site that turns an offset back into characters has to use the same
     * string the offset table was built from, or a document with a BOM or CRLF
     * verifies a span against text three (or one-per-line) bytes away and
     * silently reports no position at all.
     */
    protected function positionSource(): string
    {
        return $this->state->source->originalSource !== '' ? $this->state->source->originalSource : $this->state->source->normalizedSource;
    }

    /**
     * @return array<string>
     */
    protected function splitLines(string $input): array
    {
        $source = new SourceLines($input, $this->state->source->originalSource);
        $this->state->source->sourceLines = $source->lines;
        $this->state->source->normalizedSource = $source->normalized;
        $this->state->source->lineStartOffsets = $source->byteLineStarts;
        $this->state->source->positionIndex = $this->state->source->trackPositions ? new PositionIndex($source->original) : null;

        return $source->blockLines();
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
        foreach ($this->state->session->references as $label => $def) {
            if (
                !isset($this->state->session->usedReferences[$label])
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
     * The key `$label` enters the implicit heading index under (PART 9R R1).
     *
     * ONE DERIVATION FOR BOTH SIDES. The index is keyed by a heading's rendered
     * plain text, and R1's "ON THIS PATH THE LABEL ENTERS AS ITS RENDERED PLAIN
     * TEXT, the same string kind the heading side already enters as" makes that
     * a single routine rather than two that have to be kept in step: this is
     * HeadingReferenceCollector::register()'s own trim-and-collapse over
     * HeadingIdTracker::getPlainText(), reached from the reference site instead
     * of the heading. A second spelling here is what let `# an /em/ heading` go
     * unreachable by `[an /em/ heading][]` (markup-carve/carve#1011).
     *
     * @param \MarkupCarve\Carve\Node\Node $label The label's PARSED inline nodes.
     */
    public function headingIndexKey(Node $label): string
    {
        return trim(preg_replace('/\s+/', ' ', $this->headingIndexLabel($label)) ?? '');
    }

    /**
     * The label's DERIVED TEXT: the same extraction headingIndexKey() matches
     * on, before the trim-and-collapse that only the MATCH needs.
     *
     * Two different strings, and PART 12 §3a publishes this one. `ref` is the
     * resolution key in the sense markup-carve/carve#962 ruled - markup stripped, so
     * `` [`code()` heading][] `` publishes `code() heading` and not its
     * backticks, while `rawRef` keeps the authored spelling. It is NOT the
     * lookup key: PART 9R R1 matches the heading index looser than it matches a
     * definition, trimming, collapsing whitespace, NFC-normalizing and folding
     * CASE, and no engine publishes a case-folded `ref`. Publishing the
     * half-normalized middle - collapsed but not folded - names a string that
     * appears nowhere in the resolution, and dropped an authored double space
     * that carve-js and carve-rs both keep (markup-carve/carve#1023).
     *
     * @param \MarkupCarve\Carve\Node\Node $label The label's PARSED inline nodes.
     */
    public function headingIndexLabel(Node $label): string
    {
        $this->referenceLabelTracker ??= new HeadingIdTracker();

        return $this->referenceLabelTracker->getPlainText($label);
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
        $this->resetParseState();

        $document = new Document();
        $this->extractDefinitions($lines, $this->state->source->normalizedSource);
        // ARMED THE SAME WAY parse() ARMS IT. The definitions are collected by
        // the structural walk below, and only while discovery is on. The first
        // pass turns it OFF before finishing, so without this the second walk
        // collected no definitions at all and every reference in the rebuilt
        // tree was unresolvable (carve-php#1937).
        $this->state->session->discoveringDefinitions = true;
        $this->extractHeadingReferences($lines);
        $this->seedHeadingReferences($headingReferences);
        $this->parseBlocks($document, $lines, 0, topLevel: true);
        // THE SECOND PASS HAS TO FINISH THE SAME WAY THE FIRST ONE DOES.
        //
        // Inline parsing resolves a reference against the definitions
        // collected SO FAR, so a definition written below its use is still
        // missing when the link is built. `parse()` repairs that afterwards by
        // running this same block - but on the tree the FIRST pass produced,
        // which this pass has just replaced. So the rebuilt tree kept every
        // forward reference unresolved and rendered it as its source text:
        // `[text][ref]` written above `[ref]: /target` came out literally
        // (carve-php#1937).
        //
        // Calling the whole finish rather than the forward-reference walk
        // alone is deliberate: the work the walk defers - footnote bodies
        // discovered mid-walk, caption slots, the collected definitions
        // themselves - has to land before anything can be resolved against it.
        $this->state->session->discoveringDefinitions = false;
        $this->finishIntegratedDefinitionPass($document, $lines);
        $document->setSourceLength($sourceLength);

        return $document;
    }

    public function getReference(string $label): ?ReferenceDefinition
    {
        if (!LabelKey::isSingleLine($label)) {
            return null;
        }

        return $this->state->session->references[LabelKey::normalize($label)] ?? null;
    }

    public function getCollapsedReference(string $label): ?ReferenceDefinition
    {
        if (!LabelKey::isSingleLine($label)) {
            return null;
        }

        return $this->state->session->references[LabelKey::normalize($label)] ?? $this->state->session->headingReferencesByFoldedLabel[$this->foldReferenceLabel($label)] ?? null;
    }

    /**
     * The line pre-scan no longer feeds the implicit-reference index.
     */
    protected function registerHeadingReference(string $label, ReferenceDefinition $reference): void
    {
        $this->state->session->headingReferencesByFoldedLabel[$this->foldReferenceLabel($label)] ??= $reference;
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
        if ($this->collectWarnings && !isset($this->state->session->usedReferences[$label])) {
            $this->state->session->usedReferences[$label] = $line;
        }
    }

    public function hasFootnote(string $label): bool
    {
        return LabelKey::isSingleLine($label) && isset($this->state->session->footnotes[LabelKey::normalize($label)]);
    }

    /**
     * Get all abbreviation definitions
     *
     * @return array<string, string> Map of abbreviation text to definition
     */
    public function getAbbreviations(): array
    {
        return $this->state->session->abbreviations;
    }

    /**
     * Get the definition for a specific abbreviation
     */
    public function getAbbreviation(string $abbr): ?string
    {
        return $this->state->session->abbreviations[$abbr] ?? null;
    }

    /**
     * Record that a collapsed `[text][]` reference found no definition.
     *
     * Called from the inline parser wherever a reference found no definition.
     * The second pass only runs when this fired, so a document whose
     * references all resolved parses exactly once.
     */
    public function markCollapsedReferenceUnresolved(string $label = ''): void
    {
        $this->state->session->sawUnresolvedCollapsedReference = true;
        if ($label === '') {
            // An inline parser outside this package may not pass one.
            $this->state->session->unresolvedReferenceLabelUnknown = true;

            return;
        }

        $this->state->session->unresolvedReferenceLabels[$this->foldReferenceLabel(
            trim((string)preg_replace('/\s+/', ' ', $label)),
        )] = true;
    }

    /**
     * Heading references collected from the PARSED TREE, keyed by folded
     * heading text (PART 11 R1).
     *
     * @param array<string, array{0: string, 1: \MarkupCarve\Carve\Parser\ReferenceDefinition}> $references
     */
    public function seedHeadingReferences(array $references): void
    {
        foreach ($references as $folded => [, $reference]) {
            $this->state->session->headingReferencesByFoldedLabel[$folded] ??= $reference;
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
            $this->state->session->anchorLinks[] = [
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
        if ($this->state->session->anchorLinks === []) {
            return;
        }

        // Collect all known anchor targets
        $knownIds = $this->state->session->headingIds;

        // From explicit {#id} attributes on any node in the AST
        $this->collectExplicitIds($document, $knownIds);

        // Validate each tracked anchor link. Matching is exact (case-sensitive):
        // a plain `[link](#fragment)` href is emitted verbatim and HTML fragment
        // navigation is case-sensitive, so a `#my-heading` link to a
        // case-preserved `My-Heading` id is genuinely broken and must warn.
        // (Contrast `</#id>` crossrefs, which rewrite the href to the resolved
        // id and so resolve case-insensitively.)
        foreach ($this->state->session->anchorLinks as $anchor) {
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
        if ($this->inlineParser->getInlinePatterns() || $this->state->session->abbreviations) {
            return false;
        }

        // Check for any character that triggers inline parsing. Includes
        // Carve's delimiters: / (italic), and , / = (the ,, subscript
        // and == highlight pairs).
        return strpbrk($text, '\\`*_[{^~<$:!"\'-.\n/,=') === false;
    }

    /**
     * @param array{openParagraph: bool, inFence: bool, fenceChar: string, fenceLength: int, fenceColumn: int, fenceHostColumn: int, inDiv: bool, divFenceLength: int, divColumn: int, absorbingFence: bool, divDepth: int, isLead: bool, inTable: bool, afterInvisible: bool, afterComment: bool, inFootnoteBody: bool, quotedTable: bool, quoteParagraph: bool, nestedColumn: int} $state
     * @param bool $atContentColumn
     * @param string $line
     *
     * @return array{openParagraph: bool, inFence: bool, fenceChar: string, fenceLength: int, fenceColumn: int, fenceHostColumn: int, inDiv: bool, divFenceLength: int, divColumn: int, absorbingFence: bool, divDepth: int, isLead: bool, inTable: bool, afterInvisible: bool, afterComment: bool, inFootnoteBody: bool, quotedTable: bool, quoteParagraph: bool, nestedColumn: int}
     */
    protected function advanceTrailingBlockState(array $state, string $line, bool $atContentColumn = false): array
    {
        return $this->advanceTrailingStateCore(TrailingBlockState::fromArray($state), $line, $atContentColumn)->toArray();
    }

    private function advanceTrailingState(TrailingBlockState $state, string $line, bool $atContentColumn = false): TrailingBlockState
    {
        if ($this->usesLegacyTrailingHook('advanceTrailingBlockState')) {
            return TrailingBlockState::fromArray($this->advanceTrailingBlockState($state->toArray(), $line, $atContentColumn));
        }

        return $this->advanceTrailingStateCore($state, $line, $atContentColumn);
    }

    /**
     * @param array{openParagraph: bool, inFence: bool, fenceChar: string, fenceLength: int, fenceColumn: int, fenceHostColumn: int, inDiv: bool, divFenceLength: int, divColumn: int, absorbingFence: bool, divDepth: int, isLead: bool, inTable: bool, afterInvisible: bool, afterComment: bool, inFootnoteBody: bool, quotedTable: bool, quoteParagraph: bool, nestedColumn: int} $state
     * @param string $line
     * @param array<string> $lines
     * @param bool $closerKnownAhead
     * @param int $stripColumns
     * @param bool $atContentColumn
     * @param int $index
     *
     * @return array{openParagraph: bool, inFence: bool, fenceChar: string, fenceLength: int, fenceColumn: int, fenceHostColumn: int, inDiv: bool, divFenceLength: int, divColumn: int, absorbingFence: bool, divDepth: int, isLead: bool, inTable: bool, afterInvisible: bool, afterComment: bool, inFootnoteBody: bool, quotedTable: bool, quoteParagraph: bool, nestedColumn: int}
     */
    protected function advanceTrailingBlockStateWithFenceLookahead(
        array $state,
        string $line,
        array $lines,
        int $index,
        bool $atContentColumn = false,
        int $stripColumns = 0,
        bool $closerKnownAhead = false,
    ): array {
        return $this->advanceTrailingStateWithFenceLookaheadCore(TrailingBlockState::fromArray($state), $line, $lines, $index, $atContentColumn, $stripColumns, $closerKnownAhead)->toArray();
    }

    /**
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $state
     * @param string $line
     * @param array<string> $lines
     * @param bool $closerKnownAhead
     * @param int $stripColumns
     * @param bool $atContentColumn
     * @param int $index
     */
    private function advanceTrailingStateWithFenceLookahead(
        TrailingBlockState $state,
        string $line,
        array $lines,
        int $index,
        bool $atContentColumn = false,
        int $stripColumns = 0,
        bool $closerKnownAhead = false,
    ): TrailingBlockState {
        if ($this->usesLegacyTrailingHook('advanceTrailingBlockStateWithFenceLookahead')) {
            return TrailingBlockState::fromArray($this->advanceTrailingBlockStateWithFenceLookahead($state->toArray(), $line, $lines, $index, $atContentColumn, $stripColumns, $closerKnownAhead));
        }

        return $this->advanceTrailingStateWithFenceLookaheadCore($state, $line, $lines, $index, $atContentColumn, $stripColumns, $closerKnownAhead);
    }

    /**
     * @param string $kind
     * @param string $line
     * @param array<string> $lines
     * @param int $index
     * @param array{openParagraph: bool, inFence: bool, fenceChar: string, fenceLength: int, fenceColumn: int, fenceHostColumn: int, inDiv: bool, divFenceLength: int, divColumn: int, absorbingFence: bool, divDepth: int, isLead: bool, inTable: bool, afterInvisible: bool, afterComment: bool, inFootnoteBody: bool, quotedTable: bool, quoteParagraph: bool, nestedColumn: int} $trailingState
     */
    protected function attachedBlockHasEnded(string $kind, string $line, array $lines, int $index, array $trailingState): bool
    {
        return $this->trailingBlockHasEndedCore($kind, $line, $lines, $index, TrailingBlockState::fromArray($trailingState));
    }

    /**
     * @param string $kind
     * @param string $line
     * @param array<string> $lines
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     * @param int $index
     */
    private function trailingBlockHasEnded(string $kind, string $line, array $lines, int $index, TrailingBlockState $trailingState): bool
    {
        if ($this->usesLegacyTrailingHook('attachedBlockHasEnded')) {
            return $this->attachedBlockHasEnded($kind, $line, $lines, $index, $trailingState->toArray());
        }

        return $this->trailingBlockHasEndedCore($kind, $line, $lines, $index, $trailingState);
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
     * @param \MarkupCarve\Carve\Parser\TrailingBlockState $trailingState
     * @param bool $leadIsBareContinuationMarker
     * @param array<int, true> $authoredBaseEligible
     *
     * @return array{0: int, 1: \MarkupCarve\Carve\Parser\TrailingBlockState}
     */
    private function collectPlainContinuation(
        array $lines,
        int $i,
        int $count,
        int $baseIndent,
        int $contentIndent,
        array &$itemLines,
        array &$itemLineMap,
        TrailingBlockState $trailingState,
        bool $leadIsBareContinuationMarker = false,
        array &$authoredBaseEligible = [],
    ): array {
        if ($this->usesLegacyTrailingHook('collectPlainListItemContinuation')) {
            [$next, $state] = $this->collectPlainListItemContinuation($lines, $i, $count, $baseIndent, $contentIndent, $itemLines, $itemLineMap, $trailingState->toArray(), $leadIsBareContinuationMarker, $authoredBaseEligible);

            return [$next, TrailingBlockState::fromArray($state)];
        }

        return $this->collectPlainContinuationCore($lines, $i, $count, $baseIndent, $contentIndent, $itemLines, $itemLineMap, $trailingState, $leadIsBareContinuationMarker, $authoredBaseEligible);
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
     * @param array{openParagraph: bool, inFence: bool, fenceChar: string, fenceLength: int, fenceColumn: int, fenceHostColumn: int, inDiv: bool, divFenceLength: int, divColumn: int, absorbingFence: bool, divDepth: int, isLead: bool, inTable: bool, afterInvisible: bool, afterComment: bool, inFootnoteBody: bool, quotedTable: bool, quoteParagraph: bool, nestedColumn: int} $trailingState
     * @param bool $leadIsBareContinuationMarker
     * @param array<int, true> $authoredBaseEligible
     *
     * @return array{0: int, 1: array{openParagraph: bool, inFence: bool, fenceChar: string, fenceLength: int, fenceColumn: int, fenceHostColumn: int, inDiv: bool, divFenceLength: int, divColumn: int, absorbingFence: bool, divDepth: int, isLead: bool, inTable: bool, afterInvisible: bool, afterComment: bool, inFootnoteBody: bool, quotedTable: bool, quoteParagraph: bool, nestedColumn: int}}
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
        bool $leadIsBareContinuationMarker = false,
        array &$authoredBaseEligible = [],
    ): array {
        [$next, $state] = $this->collectPlainContinuationCore($lines, $i, $count, $baseIndent, $contentIndent, $itemLines, $itemLineMap, TrailingBlockState::fromArray($trailingState), $leadIsBareContinuationMarker, $authoredBaseEligible);

        return [$next, $state->toArray()];
    }

    private function sourceMapper(): BlockSourceMapper
    {
        return $this->sourceService ??= new BlockSourceMapper(
            $this->state,
            fn (): TableParser => $this->tableParser,
            $this->positionSource(...),
        );
    }

    private function referencesMapper(): BlockReferenceResolver
    {
        return $this->referencesService ??= new BlockReferenceResolver(
            $this->state,
            fn (): InlineParser => $this->inlineParser,
            $this->getReference(...),
            $this->hasFootnote(...),
            $this->markReferenceUsed(...),
            $this->trackAnchorLink(...),
            $this->wholeLineSpan(...),
        );
    }

    private function continuationsMapper(): BlockContinuationScanner
    {
        return $this->continuationsService ??= new BlockContinuationScanner(
            $this->state,
            fn (): FencedBlockParser => $this->fencedBlockParser,
            fn (): ListParser => $this->listParser,
            fn (): TableParser => $this->tableParser,
            $this->advanceAttachedKind(...),
            $this->advanceItemCommentFence(...),
            $this->advanceItemDefinitionBody(...),
            $this->usesLegacyTrailingHook('advanceTrailingBlockState') ? $this->advanceTrailingState(...) : null,
            $this->usesLegacyTrailingHook('advanceTrailingBlockStateWithFenceLookahead') ? $this->advanceTrailingStateWithFenceLookahead(...) : null,
            $this->commentFenceSpanEnd(...),
            $this->continuationAttachesAtColumnZero(...),
            $this->continuationMarkerHasIndentedFollower(...),
            $this->definitionBodyContinuesPastBlank(...),
            $this->entryOpensContainer(...),
            $this->footnoteBodyResumesAfter(...),
            $this->isBlockAttributeLine(...),
            $this->isBlockElementStart(...),
            $this->isCaptionLine(...),
            $this->isCommentLineOrFence(...),
            $this->isContinuationMarker(...),
            $this->isDefinitionLineForEnclosingItem(...),
            $this->isFoldableInvisibleLine(...),
            $this->isReferenceDefinitionLine(...),
            $this->lastCommentFenceIndex(...),
            $this->lineOpensBlockForLooseness(...),
            $this->listContinuationEndsAtBaseColumn(...),
            $this->listContinuationEndsAtDedentedBlock(...),
            $this->listMarkerWidth(...),
            $this->markerFreeContent(...),
            $this->paragraphHasUnclaimedColonFenceLine(...),
            $this->sourceMapper(),
            $this->spanningConstruct(...),
            $this->startsNewBlock(...),
            $this->usesLegacyTrailingHook('attachedBlockHasEnded') ? $this->trailingBlockHasEnded(...) : null,
            $this->wrappedItemAttributeLength(...),
        );
    }

    private function listsBuilder(): ListBlockBuilder
    {
        return $this->listsImplementation ??= new ListBlockBuilder(
            state: $this->state,
            source: $this->sourceMapper(),
            continuations: $this->continuationsMapper(),
            getFencedBlockParser: fn (): FencedBlockParser => $this->fencedBlockParser,
            getListParser: fn (): ListParser => $this->listParser,
            getTableParser: fn (): TableParser => $this->tableParser,
            advanceItemCommentFenceCallback: $this->advanceItemCommentFence(...),
            advanceTrailingStateCallback: $this->usesLegacyTrailingHook('advanceTrailingBlockState') ? $this->advanceTrailingState(...) : null,
            advanceTrailingStateWithFenceLookaheadCallback: $this->usesLegacyTrailingHook('advanceTrailingBlockStateWithFenceLookahead') ? $this->advanceTrailingStateWithFenceLookahead(...) : null,
            attachListContinuationCallback: $this->attachListContinuation(...),
            blockQuoteExtentThroughDefinitionCallback: $this->blockQuoteExtentThroughDefinition(...),
            blockQuoteLineContentCallback: $this->blockQuoteLineContent(...),
            collectListContinuationBlockCallback: $this->collectListContinuationBlock(...),
            collectMarkerLeadItemCallback: $this->collectMarkerLeadItem(...),
            collectPlainContinuationCallback: $this->usesLegacyTrailingHook('collectPlainListItemContinuation') ? $this->collectPlainContinuation(...) : null,
            consumeLooseKeyCallback: $this->consumeLooseKey(...),
            containerExtentBeforeADefinitionCallback: $this->containerExtentBeforeADefinition(...),
            contentRendersNothingCallback: $this->contentRendersNothing(...),
            continuationMarkerHasIndentedFollowerCallback: $this->continuationMarkerHasIndentedFollower(...),
            footnoteBodyResumesAfterCallback: $this->footnoteBodyResumesAfter(...),
            indentedContinuationOpensBlockCallback: $this->indentedContinuationOpensBlock(...),
            isBlockElementStartCallback: $this->isBlockElementStart(...),
            isCommentLineOrFenceCallback: $this->isCommentLineOrFence(...),
            isContinuationMarkerCallback: $this->isContinuationMarker(...),
            isFoldableInvisibleLineCallback: $this->isFoldableInvisibleLine(...),
            keptCommentDelimiterCallback: $this->keptCommentDelimiter(...),
            leadBottomIsContinuationMarkerCallback: $this->leadBottomIsContinuationMarker(...),
            leadColonFenceHasBodyAtContentColumnCallback: $this->leadColonFenceHasBodyAtContentColumn(...),
            lineOpensBlockForLoosenessCallback: $this->lineOpensBlockForLooseness(...),
            linesLeaveACommentSpanOpenCallback: $this->linesLeaveACommentSpanOpen(...),
            listMarkerWidthCallback: $this->listMarkerWidth(...),
            markerFreeContentCallback: $this->markerFreeContent(...),
            parseBlocksCallback: $this->parseBlocks(...),
            startsNewBlockCallback: $this->startsNewBlock(...),
            subContentHasLooseningBlankCallback: $this->subContentHasLooseningBlank(...),
            parseItemBlocksCallback: static::class !== self::class ? $this->parseItemBlocks(...) : null,
        );
    }

    private function definitionsBuilder(): DefinitionListBuilder
    {
        return $this->definitionsImplementation ??= new DefinitionListBuilder(
            state: $this->state,
            source: $this->sourceMapper(),
            continuations: $this->continuationsMapper(),
            getFencedBlockParser: fn (): FencedBlockParser => $this->fencedBlockParser,
            getInlineParser: fn (): InlineParser => $this->inlineParser,
            getListParser: fn (): ListParser => $this->listParser,
            advanceTrailingStateWithFenceLookaheadCallback: $this->usesLegacyTrailingHook('advanceTrailingBlockStateWithFenceLookahead') ? $this->advanceTrailingStateWithFenceLookahead(...) : null,
            applyPendingAttributesCallback: $this->applyPendingAttributes(...),
            consumeLooseKeyCallback: $this->consumeLooseKey(...),
            continuationAttachesAtColumnZeroCallback: $this->continuationAttachesAtColumnZero(...),
            endContainerAttributeScopeCallback: $this->endContainerAttributeScope(...),
            endsDefinitionTermCallback: $this->endsDefinitionTerm(...),
            isBlockAttributeLineCallback: $this->isBlockAttributeLine(...),
            isCommentLineOrFenceCallback: $this->isCommentLineOrFence(...),
            isInvisibleOrAttributeLineCallback: $this->isInvisibleOrAttributeLine(...),
            isReferenceDefinitionLineCallback: $this->isReferenceDefinitionLine(...),
            keptCommentDelimiterCallback: $this->keptCommentDelimiter(...),
            lastCommentFenceIndexCallback: $this->lastCommentFenceIndex(...),
            leadBottomOpensFenceCallback: $this->leadBottomOpensFence(...),
            lineOpensBlockForLoosenessCallback: $this->lineOpensBlockForLooseness(...),
            linesLeaveACommentSpanOpenCallback: $this->linesLeaveACommentSpanOpen(...),
            parseBlocksCallback: $this->parseBlocks(...),
            rebaseOverindentedItemBlocksCallback: $this->rebaseOverindentedItemBlocks(...),
            startsInterruptingBlockCallback: $this->startsInterruptingBlock(...),
            startsNewBlockCallback: $this->startsNewBlock(...),
            tryParseCommentCallback: $this->tryParseComment(...),
            tryParseFencedCommentCallback: $this->tryParseFencedComment(...),
            wrappedBlockAttributeLengthCallback: $this->wrappedBlockAttributeLength(...),
        );
    }

    private function linesBuilder(): LineBlockBuilder
    {
        return $this->linesImplementation ??= new LineBlockBuilder(
            state: $this->state,
            source: $this->sourceMapper(),
            getFencedBlockParser: fn (): FencedBlockParser => $this->fencedBlockParser,
            getInlineParser: fn (): InlineParser => $this->inlineParser,
            applyPendingAttributesCallback: $this->applyPendingAttributes(...),
            positionSourceCallback: $this->positionSource(...),
            collectColonFenceBodyCallback: $this->collectColonFenceBody(...),
            appendLineBlockStanzaCallback: static::class !== self::class ? $this->appendLineBlockStanza(...) : null,
            convertParagraphSoftBreaksToHardBreaksCallback: static::class !== self::class ? $this->convertParagraphSoftBreaksToHardBreaks(...) : null,
            expandLineBlockLineCallback: static::class !== self::class ? $this->expandLineBlockLine(...) : null,
            parseLineBlockOpenerCallback: static::class !== self::class ? $this->parseLineBlockOpener(...) : null,
        );
    }

    private function tablesBuilder(): TableBlockBuilder
    {
        return $this->tablesImplementation ??= new TableBlockBuilder(
            state: $this->state,
            source: $this->sourceMapper(),
            getInlineParser: fn (): InlineParser => $this->inlineParser,
            getTableParser: fn (): TableParser => $this->tableParser,
            applyPendingAttributesCallback: $this->applyPendingAttributes(...),
            applyTableColumnsCallback: $this->applyTableColumns(...),
            canCloseCodeSpanWithContinuationsCallback: $this->canCloseCodeSpanWithContinuations(...),
            isPlainTextCallback: $this->isPlainText(...),
            parseTableCellMarkerCallback: static::class !== self::class ? $this->parseTableCellMarker(...) : null,
            resolveRowSpansCallback: static::class !== self::class ? $this->resolveRowSpans(...) : null,
        );
    }

    private function quotesBuilder(): BlockQuoteBuilder
    {
        return $this->quotesImplementation ??= new BlockQuoteBuilder(
            state: $this->state,
            source: $this->sourceMapper(),
            continuations: $this->continuationsMapper(),
            getFencedBlockParser: fn (): FencedBlockParser => $this->fencedBlockParser,
            getListParser: fn (): ListParser => $this->listParser,
            getTableParser: fn (): TableParser => $this->tableParser,
            advanceTrailingStateCallback: $this->usesLegacyTrailingHook('advanceTrailingBlockState') ? $this->advanceTrailingState(...) : null,
            endContainerAttributeScopeCallback: $this->endContainerAttributeScope(...),
            endsBlockQuoteCallback: $this->endsBlockQuote(...),
            hasClosingCommentFenceAheadInBlockQuoteCallback: $this->hasClosingCommentFenceAheadInBlockQuote(...),
            isAbbreviationDefinitionLineCallback: $this->isAbbreviationDefinitionLine(...),
            isBlockAttributeLineCallback: $this->isBlockAttributeLine(...),
            isCommentLineOrFenceCallback: $this->isCommentLineOrFence(...),
            isContinuationMarkerCallback: $this->isContinuationMarker(...),
            isDefinitionLineForEnclosingItemCallback: $this->isDefinitionLineForEnclosingItem(...),
            isReferenceDefinitionLineCallback: $this->isReferenceDefinitionLine(...),
            listMarkerWidthCallback: $this->listMarkerWidth(...),
            markerSitsAtColumnCallback: $this->markerSitsAtColumn(...),
            parseBlocksCallback: $this->parseBlocks(...),
            quotedCodeFenceHasCloserCallback: $this->quotedCodeFenceHasCloser(...),
            trackWrappedAttributeRunCallback: $this->trackWrappedAttributeRun(...),
            blockQuoteLazyExtentEndCallback: static::class !== self::class ? $this->blockQuoteLazyExtentEnd(...) : null,
        );
    }
}
