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
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Block\TableRow;
use MarkupCarve\Carve\Node\Block\ThematicBreak;
use MarkupCarve\Carve\Node\ContentNodeInterface;
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
     * The attached run holds nothing visible yet {@see self::attachedBlockKind()}.
     *
     * @var string
     */
    protected const ATTACHED_PENDING = 'pending';

    /**
     * The attached block is a paragraph; PART 9 §10 ends it.
     *
     * @var string
     */
    protected const ATTACHED_PARAGRAPH = 'paragraph';

    /**
     * The attached block has a multi-line extent of its own - a quote, a list,
     * a table, a fenced body - which the collectors' own boundaries end.
     *
     * @var string
     */
    protected const ATTACHED_SPANNING = 'spanning';

    /**
     * Initial trailing-block tracker state for list-item lazy continuation.
     *
     * `openParagraph` starts FALSE: a container that has collected no line yet
     * holds no block, and PART 1 S4 asks whether an OPEN PARAGRAPH is on the
     * stack - "nothing" is not one. It started true on the reading that an
     * empty item can absorb a lazy line, which no shape reaches: every seeding
     * site advances the tracker over the container's first line before the gate
     * reads it, so the only line that could leave the initial value standing is
     * one the tracker passes through unchanged - a COMMENT. `- %% c` / `tail`
     * is exactly that shape, and the old default made the empty item swallow
     * `tail` (corpus 326-5). See advanceTrailingBlockState().
     *
     * @var array{openParagraph: bool, inFence: bool, fenceChar: string, fenceLength: int, inDiv: bool, divFenceLength: int, absorbingFence: bool, divDepth: int, isLead: bool, inTable: bool, afterInvisible: bool, inFootnoteBody: bool, quotedTable: bool}
     */
    protected const INITIAL_TRAILING_BLOCK_STATE = ['openParagraph' => false, 'inFence' => false, 'fenceChar' => '', 'fenceLength' => 0, 'inDiv' => false, 'divFenceLength' => 0, 'absorbingFence' => false, 'divDepth' => 0, 'isLead' => true, 'inTable' => false, 'afterInvisible' => false, 'inFootnoteBody' => false, 'quotedTable' => false];

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
    /**
     * BULLETS ARE `-` AND `*` ONLY. `+` is not a Carve bullet -- it is the
     * list-continuation marker (PART 9 §17), so `+ text` is ordinary paragraph
     * text, which is what all three engines parse it as. Including `+` here
     * opened a phantom list item on such a line, and the `!$inListItem` guard
     * below then refused the abbreviation definition on the NEXT line: the term
     * expanded nowhere and `fmt` dropped the line, while a link definition and a
     * footnote definition in the same position were both collected normally.
     *
     * THE BARE DOT IS AN ORDERED MARKER AND BELONGS HERE. `. text` is a Carve
     * addition with no CommonMark or Djot equivalent (`resources/grammar.ebnf`,
     * `ordered_marker`, BARE DOT), so it is the marker least likely to have
     * inherited a reference implementation's behavior - and every ordered
     * alternative here required an enumerator BEFORE the delimiter, so it was
     * the one marker that opened no item as far as this guard could see.
     *
     * A column-0 abbreviation definition after it was therefore collected, and
     * PART 12 §7 says it is not one: the line "is an `abbreviation_definition`
     * only as a direct child of the document. Written inside a block quote, a
     * list item or a div, the line is not a definition at all: it is ordinary
     * paragraph text, it defines nothing, and it is preserved as the text the
     * author typed." Two wrong things happened together - the line stayed
     * visible as lazy item text AND registered the term, so it expanded inside
     * its own definition and anywhere else in the document (carve-php#1328).
     *
     * @var string
     */
    private const LIST_ITEM_CONTEXT_PATTERN = '/^[ \t]*(?:[-*]|\.|(?:[0-9]+|[ivxlcdm]+|[IVXLCDM]+|[a-zA-Z])[.)]) +[ \t]*[^ \t]/';

    /**
     * `abbreviation_definition = "*[", term, "]:", space+, expansion, newline`.
     *
     * THE SEPARATOR IS A RUN OF ASCII SPACES, and the first character that is
     * not one ENDS the separator and BEGINS the content
     * (markup-carve/carve#892). Both halves are in the pattern:
     *
     * - `]: +` - a literal SPACE first, so `*[HTML]:<TAB>x` is still a
     *   paragraph. Widening the run is not widening the terminal.
     * - `([^ ]…)` - the run is MAXIMAL, so a no-break space or a tab after it is
     *   the expansion's first character rather than more separator. The
     *   expansion used to be `trim()`ed, which ate both.
     * - `(?![ \t]*$)` - MARKER REQUIRES CONTENT still applies AFTER the run. A
     *   line of `whitespace` is blank (PART 1), so `*[HTML]:` followed by spaces
     *   and nothing else is a paragraph. Implemented as "eat spaces then take
     *   the rest", a spaces-only line defines an empty abbreviation.
     *
     * @var string
     */
    private const ABBREVIATION_DEFINITION_PATTERN = '/^\*\[([A-Za-z0-9]+)\]: +(?![ \t]*$)([^ ].*)$/';

    /**
     * `footnote_definition = "[^", label, "]:", space+, inline_content, …`.
     *
     * The same three halves as {@see self::ABBREVIATION_DEFINITION_PATTERN},
     * against the other marker. It was spelled `\]: +\S` in four places, and
     * `\S` is a whitespace test rather than a space test: it refused a TAB after
     * the run, which is content.
     *
     * The two markers answer differently one step downstream, and the reason is
     * not in the separator: an `abbreviation_expansion` is a raw string, so a
     * leading tab survives into the `title`, while a footnote's `inline_content`
     * is parsed as blocks and a leading tab is that body's own indentation run
     * (PART 9 §24 C1), so it does not appear in the body.
     *
     * @var string
     */
    private const FOOTNOTE_DEFINITION_PATTERN = '/^\[\^([^\]]+)\]: +(?![ \t]*$)([^ ].*)$/';

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
    /**
     * A footnote body's own column: the indent PART 9 §16 asks a continuation
     * line for, and the amount the body is dedented by - never the first
     * continuation line's actual indent, so a deeper line keeps its residual
     * columns and the body's own blocks read them.
     *
     * @var int
     */
    private const FOOTNOTE_BODY_COLUMN = 2;

    /**
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
    // The separator is ONE literal space; whitespace AFTER it is stripped
    // rather than being the term's first character. `(?=\S)` straight after
    // ` +` required the term to start on a non-space, so `:: <TAB>x` was a
    // paragraph while `::   x` - differing only in which whitespace follows -
    // was a term (carve-php#884, spec markup-carve/carve#794).
    //
    // The leading space stays REQUIRED. `::<TAB>x` is still prose, because a tab
    // does not satisfy the marker's separator at all (corpus
    // 176-a-marker-separator-is-a-space-never-a-tab). Widening the separator to
    // `[ \t]+` would have made both terms and broken that fixture.
    //
    // All THREE constants change together, for the reason the docblock above
    // gives: they exist because a fix applied to only the one a report named
    // would leave the rest deciding the old way. The prefix one matters most -
    // it is what strips the marker, and leaving it narrow would keep the tab as
    // the term's first character even once the other two matched.
    /**
     * @var string
     */
    protected const DEFINITION_TERM_PATTERN = '/^::(?!:) [ \t]*(?=' . StringUtil::NON_WHITESPACE_CLASS . ')(.+)$/';

    /**
     * A definition term, tested rather than captured.
     *
     * @var string
     */
    protected const DEFINITION_TERM_LINE_PATTERN = '/^::(?!:) [ \t]*' . StringUtil::NON_WHITESPACE_CLASS . '/';

    /**
     * A definition-term MARKER, where the caller checks only that the line
     * opens one.
     *
     * @var string
     */
    protected const DEFINITION_TERM_LINE_PREFIX = '/^::(?!:) [ \t]*/';

    // THE BODY SEPARATOR IS TWO LITERAL SPACES, for the same reason the term
    // marker's is one: `definition_body = ':', space, space, ...` with
    // `space = ' '` and `whitespace = ' ' | '\t'` kept deliberately apart. A
    // marker separator is literal; indentation is columns, and a separator is
    // not indentation (carve#692, and carve#698 for the `::` marker above).
    //
    // Six sites spelled this `/^:\s\s+/`. Without the `u` modifier PCRE's `\s`
    // is `[ \t\n\r\f\v]`, so a tab, a vertical tab or a form feed in either
    // slot opened a `<dd>` no other implementation opens - and it was consumed
    // with the separator, so the result was byte-identical to the two-space
    // spelling the grammar does admit (carve-php#935).
    //
    // ` {2,}` rather than exactly two: the old pattern was greedy over the run,
    // and a wider run is still the separator rather than leading indentation of
    // the body. Named constants because six independent spellings is how this
    // survived the fix that corrected the three term patterns beside it.
    /**
     * A definition body, captured.
     *
     * @var string
     */
    protected const DEFINITION_BODY_PATTERN = '/^: {2,}(.+)$/';

    /**
     * A definition BODY marker, where the caller checks only that the line
     * opens one.
     *
     * @var string
     */
    protected const DEFINITION_BODY_LINE_PREFIX = '/^: {2,}/';

    /**
     * The visual COLUMN a definition body's continuation line must reach.
     *
     * `definition_continuation = (space, space, space, inline_content, newline)`
     * - the body's content column, `:` plus its two-space separator. The number
     * is a column, not a character count: `definition_continuation` is a leading
     * indentation run, which is the one position where a tab IS syntax, and PART
     * 9 §24 C1 measures a leading run in columns with a tab advancing to the next
     * multiple of 4 (markup-carve/carve#888 signoff `direction=27fba08112af`,
     * reaffirmed by markup-carve/carve#901).
     *
     * @var int
     */
    protected const DEFINITION_CONTINUATION_COLUMN = 3;

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
    protected array $footnoteDefinitionSpans = [];

    /**
     * @var array<int, true> Nodes reassembled from discontiguous source.
     */
    protected array $unplaceableNodeIds = [];

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
     * The source exactly as the caller passed it, before any normalization.
     *
     * PART 12 §4 offsets index this string, not the normalized copy - stripping
     * a BOM and collapsing CRLF both shorten the text, and a table measured
     * against the result puts every span before the characters it names
     * (carve#876).
     */
    protected string $originalSource = '';

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
     * Where a closer of each fence shape LAST occurs in the current line set,
     * built once by fenceCloserIndex().
     *
     * @var array{comment: array<int, int>, colon: array<int, int>, code: array<string, array{runs: array<int, int>, lastAtLeast: array<int, int>}>}|null
     */
    private ?array $fenceCloserIndexCache = null;

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

    /**
     * Where THIS level's content begins on each source line, in bytes.
     *
     * PART 12 §4: a span begins at the construct's OPENING MARKUP (carve#913).
     * A block inside a container is parsed from lines the container prefix has
     * already been cut off, so `lineStartOffsets` - which records the start of
     * the whole source line - places a heading inside a block quote at the `>`
     * that opens the QUOTE, not at the `#` that opens the heading. The cut is
     * the only thing that knows how wide the prefix was, so the width is
     * recorded where the cut is still visible: the built line is a SUFFIX of
     * the source line, and the difference in length is the column.
     *
     * Keyed by SOURCE line and scoped exactly like `currentLineMap`, because a
     * deeper container cuts more and the outer level must not see it. A line
     * whose built text is not a suffix of its source (an item stream re-joined
     * into one line, a tab re-indented) records nothing and falls back to the
     * line start, which is where every span began before this existed.
     *
     * @var array<int, int>
     */
    protected array $currentContentColumns = [];

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

    public function enablePositionTracking(): self
    {
        $this->trackPositions = true;

        return $this;
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
        // Decode the source as UTF-8 the way PART 1 says it is encoded, and
        // substitute U+FFFD for anything that is not (carve-php#1082). Ahead
        // of everything else in this method so the rest of the parser - and
        // the ORIGINAL SOURCE the position table slices with mb_substr() -
        // only ever sees well-formed UTF-8.
        //
        // Measured before the budget above deliberately: substitution only
        // ever LENGTHENS the input (one byte becomes three), so charging the
        // pre-substitution length keeps the abbreviation-expansion guard on
        // the smaller of the two numbers.
        $input = StringUtil::toValidUtf8($input);
        $this->resetParseState();
        $document = new Document();
        // Strip a single leading UTF-8 BOM (U+FEFF) at the document start so
        // `﻿# T` is a heading, not literal text. Root only: this is the
        // top-level entry; nested content is parsed from line arrays.
        // Kept for the offset table: PART 12 §4 positions index the ORIGINAL
        // file, and everything below rewrites the text the parser sees.
        $this->originalSource = $input;
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
        foreach ($this->footnotes as $label => $footnote) {
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
            $footnote->setPos($this->footnoteDefinitionSpans[$label] ?? $footnote->getPos());
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

        // After the spans exist, because it sorts BY them.
        $this->orderCollectedDefinitions($document);

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

    /**
     * PART 12 §7: "Definitions appear in DOCUMENT ORDER by source position."
     *
     * Collection moves a definition to the document and §4 keeps the `pos` it
     * was written at, so the published order has to follow that `pos`. It
     * followed the collection tables instead - footnotes appended first, link
     * definitions second - so a footnote preceded a link definition whatever the
     * author wrote, and `pos` ran backwards between two adjacent siblings
     * (markup-carve/carve#746).
     *
     * Only the COLLECTED kinds move. An `abbreviation_def` is not collected out
     * of the document - §7 refuses that specifically, since hoisting it would
     * empty the line rather than relocate visible output - so it already sits at
     * its source position and keeps its index.
     *
     * The reordering is confined to the slots the collected definitions already
     * occupy, so no other child moves, and the sort is stable, so a definition
     * with no recorded span (position tracking is opt-in, §4) keeps the order it
     * was collected in rather than being given an invented one.
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
        // EVERY entry is authored now. This used to skip `$definition->fromHeading`,
        // because a heading was seeded into `$this->references` beside the folded
        // index; markup-carve/carve#742 stops that seeding, so the check can no
        // longer fail and is removed rather than left reading as a guard. The
        // observable it protected - no `link_reference_definition` node, and so no
        // invented `[H]: #H` line from the canonical writer, for a document that
        // only ever had a heading - is pinned in ImplicitHeadingReferenceTest.
        $authored = [];
        foreach ($this->references as $label => $definition) {
            // strval, because PHP turns an all-digit array key into an INT.
            // A reference label is any inline text, so `[5]: /u` keys the map
            // with 5 rather than "5", and the definition node's constructor
            // types its label `string` - a fatal TypeError on an ordinary
            // document (carve-php#881). Same coercion that broke a digit-only
            // abbreviation term in #880, and the same guard the attribute names
            // below already carry.
            $authored[] = [(string)$label, $definition];
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
        // The item content column the open line block was opened at, so its
        // closer is read at that column instead of after arbitrary indentation.
        $lineBlockColumn = 0;
        // A `%%%` COMMENT FENCE is opaque, so a literal `::: |` inside one is
        // not a line-block opener. Entering that state there left it open past
        // the comment's own closer -- which is not a colon fence -- and every
        // later definition in the document was skipped (#698).
        //
        // A comment's body is skipped outright, so a definition inside one no
        // longer registers either. That is an intended behaviour change: a
        // comment renders nothing, and carve-js has never registered from
        // inside one.
        //
        // WHERE the fence sits, when it opens and when it closes, spelled once
        // for all three definition prepasses {@see PrepassCommentFence}. It
        // indexes the closers in a single pass and memoizes the container bound
        // per depth and column, so no opener rescans the tail -- which is what
        // testDistinctWidthFenceOpenersDoNotRescanPerOpener forbids, and
        // `%%% x`, `%%%% x`, ... is all openers and no closers.
        $commentFence = new PrepassCommentFence($lines);
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
            $unquotedForColumns = ContainerPrefix::stripQuoteMarkers($line);
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
            $quoteStages = ContainerPrefix::quoteStages($line);
            $fenceLine = $quoteStages[count($quoteStages) - 1];

            if ($fence->isOpen()) {
                // LEFT means the line dropped out of the blockquote the fence
                // was opened in, so the region ended without a closer and this
                // line is read normally.
                if ($fence->advance($line) !== PrepassFenceTracker::LEFT) {
                    $i++;

                    continue;
                }
            }
            // A LINE BLOCK's body is verse, so a `%%%` written in one is text
            // and opens nothing. The open-region tests therefore all run before
            // any opener test: whichever region the line is already in owns it.
            if ($lineBlockLen > 0) {
                // The closer has to be read at the DEPTH the line block opened
                // at: inside `> ::: |` a nested `> > :::` is a quoted `> :::`,
                // which the real parser keeps as line-block content. Reading
                // the fully stripped tail would close the region there and let
                // the lines after it register again. A line that no longer
                // reaches that depth has left the blockquote, so the line block
                // ended with it.
                //
                // And at the COLUMN it opened at, for the same reason. Exactly
                // that column comes off and never arbitrary indentation: an
                // indented `:::` inside a TOP-LEVEL line block is verse text,
                // and trimming it closed the block there, so the lines after it
                // registered while the real parser still had them as verse.
                $closerLine = $quoteStages[$lineBlockDepth] ?? null;
                $closerView = $closerLine;
                if ($closerLine !== null && $lineBlockColumn > 0) {
                    $closerView = ContainerPrefix::atContentColumn($closerLine, $lineBlockColumn);
                    // A blank line is inside the block, not out of its
                    // container: it reaches no column and ends nothing.
                    if ($closerView === null && IndentationHelper::isBlankLine($closerLine)) {
                        $closerView = '';
                    }
                }
                if ($closerLine === null || $closerView === null) {
                    // Out of the blockquote, or dedented past the column the
                    // block was opened at: the container ended and took the
                    // unclosed line block with it.
                    $lineBlockLen = 0;
                } elseif ($this->fencedBlockParser->isDivFenceCloser($closerView, $lineBlockLen)) {
                    $lineBlockLen = 0;
                    $i++;

                    continue;
                } else {
                    $i++;

                    continue;
                }
            }
            // A comment fence's closer is a leading `%` run of the SAME length --
            // trailing text is allowed, so `%%% end` closes a `%%%` fence. Matching
            // only a bare fence missed real closers and left the state open.
            if ($commentFence->isOpen()) {
                $commentFence->advance($line);
                // The body is opaque: a code fence opener in there is comment
                // TEXT, and letting it reach the fence scanner below opened a
                // code block that swallowed the real comment closer.
                $i++;

                continue;
            }
            // Only a fence that CLOSES, and an indented one only when its
            // closer arrives before its container ends. An unterminated `%%%`
            // is not a fenced comment -- the block parser degrades it to a
            // single-line comment -- and treating it as open here stayed open
            // for the rest of the document, suppressing every later line block.
            //
            // Still BEFORE the line-block opener below: a `::: |` inside a
            // comment is comment text and opens no verse (carve-php#698).
            if ($commentFence->opensOn($line, $i, $contentCol)) {
                $i++;

                continue;
            }
            if ($fence->opensOn($line, $contentCol)) {
                $i++;

                continue;
            }
            // An INDENTED `::: |` opens a line block too, when the indent is an
            // item's CONTENT COLUMN. This read the raw line, so a line block
            // inside a list item went untracked and a `%%%` written in its
            // verse was read as a comment opener rather than the text it
            // renders.
            //
            // Exactly the content column comes off and never arbitrary
            // indentation: `   ::: |` at top level is prose, and admitting it
            // skipped the definition under it as verse.
            $openerView = $fenceLine;
            $openerColumn = 0;
            if ($contentCol > 0) {
                $dedented = ContainerPrefix::atContentColumn($fenceLine, $contentCol);
                if ($dedented !== null) {
                    $openerView = $dedented;
                    $openerColumn = $contentCol;
                }
            }
            $fc0 = $openerView[0] ?? '';
            if ($fc0 === ':') {
                $lineBlockOpener = $this->parseLineBlockOpener($openerView);
                if ($lineBlockOpener !== null) {
                    $lineBlockLen = $lineBlockOpener['length'];
                    $lineBlockDepth = count($quoteStages) - 1;
                    $lineBlockColumn = $openerColumn;
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

            $container = $this->footnoteContainerPrefix($line, $reachedCol, $lines[$i - 1] ?? '');
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
            $columnBare = $container['kind'] === 'none'
                ? ContainerPrefix::atContentColumn($unquotedForColumns, $reachedCol)
                : null;
            if ($columnBare !== null && preg_match('/^\[\^[^\]]+\]:/', $columnBare) === 1) {
                $quotePrefixLength = strlen($line) - strlen($unquotedForColumns);
                $container = [
                    'kind' => 'columnContainer',
                    'prefix' => substr($line, 0, $quotePrefixLength + $reachedCol),
                ];
                $bare = $columnBare;
            }

            // Match footnote definition: [^label]: content. The marker line
            // must carry inline content (grammar PART 9 §16 production:
            // `"]:", space, inline_content`); a bare `[^label]:` is an
            // ordinary paragraph line, and a following indented line folds
            // into it as paragraph text.
            if (($bare[0] ?? '') === '[' && preg_match(self::FOOTNOTE_DEFINITION_PATTERN, $bare, $matches)) {
                $label = $matches[1];
                $content = $matches[2];
                if (trim($content, StringUtil::WHITESPACE_CHARS) === '') {
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
                    // A DESCRIPTION MARKER in the prefix opens a block by
                    // itself for the same reason a quote marker does: the `dd`
                    // begins on that line, so the definition is the entry's
                    // first block and does not depend on what precedes it. What
                    // precedes it is the `::` term line, which is neither blank
                    // nor a container, so without this the opener test refused
                    // every definition written in a `dd` (carve-php#891, spec
                    // markup-carve/carve#801). The prefix holds only stripped
                    // container markers, so a `:` followed by whitespace in it
                    // is the description marker and nothing else.
                    $opensBlock = $container['kind'] === 'columnContainer'
                        || str_contains($container['prefix'], '>')
                        || preg_match('/:[ \t]/', $container['prefix']) === 1
                        || $i === 0
                        || IndentationHelper::isBlankLine($lines[$i - 1])
                        || $this->footnoteContainerPrefix($lines[$i - 1])['kind'] !== 'none'
                        || $this->blockQuoteLineContent(ltrim($lines[$i - 1], " \t")) !== null;
                    if ($opensBlock && trim($content, StringUtil::WHITESPACE_CHARS) !== '' && !isset($this->footnotes[$label])) {
                        $footnote = new Footnote($label);
                        if ($this->trackSourceLines) {
                            $footnote->setAttribute('data-source-line', (string)($i + 1));
                        }
                        $this->recordFootnoteDefinitionSpan($label, $i, $line, $bare);
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
                                    // THE BLOCK'S EXTENT IS THE DEFINITION'S,
                                    // BLANK LINES AND ALL (PART 1 S4,
                                    // markup-carve/carve#1363). A blank inside
                                    // the body separates the NOTE's own blocks;
                                    // it ends the body only when nothing below
                                    // reaches the body column again.
                                    //
                                    // MIRRORS tryParseFootnoteDefinition(),
                                    // which already reads it this way. The two
                                    // disagreeing is what made the note keep
                                    // one block while the block parser skipped
                                    // both - so the second block was consumed
                                    // by one pass, collected by neither, and
                                    // left the document entirely.
                                    $ahead = $k;
                                    while ($ahead < $count && IndentationHelper::isBlankLine($lines[$ahead])) {
                                        $ahead++;
                                    }
                                    if ($ahead >= $count) {
                                        break;
                                    }
                                    // COLUMNS, NOT BYTES. A tab is one byte and
                                    // four columns, so a byte measure read a
                                    // tab-indented body as below the column,
                                    // ended the note there - and the block
                                    // parser went on skipping the line as the
                                    // note's, so it was collected by neither
                                    // pass and left the document. The
                                    // continuation check below measures the
                                    // same way for the same reason.
                                    if (IndentationHelper::getLeadingColumns($lines[$ahead], $bodyIndent) < $bodyIndent) {
                                        break;
                                    }
                                    for (; $k < $ahead; $k++) {
                                        $bodyLines[] = '';
                                        $bodyLineMap[] = $k;
                                    }

                                    continue;
                                }
                                if (IndentationHelper::getLeadingColumns($continuation, $bodyIndent) < $bodyIndent) {
                                    break;
                                }
                                // STRIPPED IN COLUMNS TOO. A byte slice ate
                                // four bytes of a tab-indented body where the
                                // tab is one byte and four columns, so `more`
                                // arrived as `e` - the measure and the strip
                                // have to agree or the body is corrupted rather
                                // than merely mis-bounded.
                                $bodyLines[] = IndentationHelper::stripLeadingColumns($continuation, $bodyIndent);
                                $bodyLineMap[] = $k;
                                $k++;
                            }
                            $this->extendFootnoteDefinitionToLineStart(
                                $label,
                                (int)end($bodyLineMap) + 1,
                            );
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
                if (trim($content, StringUtil::WHITESPACE_CHARS) !== '') {
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
                        [$j, $attached, $attachedLineMap] = $this->collectAttachedBlock(
                            $lines,
                            $j,
                            $count,
                            static fn (string $a): bool => IndentationHelper::isBlankLine($a)
                                || preg_match('/^\+[ \t]*$/', $a)
                                || preg_match('/^\[\^[^\]]+\]:/', $a),
                        );
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
                    // A footnote body extends only to lines reaching the body's
                    // column, which PART 9 §16 puts at 2. The measure is COLUMNS:
                    // §24 C1 gives a tab a column value, so a bare tab, two
                    // spaces and `<SPACE><TAB>` all reach it. This matched
                    // `/^(?:[ ]{2}|\t)/` - two spaces or a tab, never the
                    // mixture - while carve-js and carve-rs took the mixture and
                    // refused the bare tab (carve#796, carve-php#887). A line
                    // reaching only column 1 is a top-level block, not part of
                    // the note.
                    if (IndentationHelper::getLeadingColumns($nextLine, self::FOOTNOTE_BODY_COLUMN) >= self::FOOTNOTE_BODY_COLUMN) {
                        $contentLines[] = IndentationHelper::stripLeadingColumns($nextLine, self::FOOTNOTE_BODY_COLUMN);
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
                    $this->recordFootnoteDefinitionSpan($label, $i, $line, $bare);
                    $this->extendFootnoteDefinitionToLineStart(
                        $label,
                        (int)end($contentLineMap) + 1,
                    );
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
    protected function footnoteContainerPrefix(
        string $line,
        int $contentCol = 0,
        string $previousLine = '',
    ): array {
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
            if ($quoteContent === null) {
                // EXACTLY the item's content column, never arbitrary
                // indentation: a top-level `    > [r]: /u` is indented text,
                // not a quote (tests/BlockquoteRefDefTest).
                $atColumn = ContainerPrefix::atContentColumn($rest, $contentCol);
                if ($atColumn !== null) {
                    $quoteContent = $this->blockQuoteLineContent($atColumn);
                }
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
            $trimmed = ltrim($rest, " \t");
            $info = $this->listParser->parseListItemMarker($trimmed);
            if ($info !== null && $info['type'] !== 'task') {
                $markerWidth = strlen($rest) - strlen((string)$info['content']);
                $rest = substr($rest, $markerWidth);
                $stripped = true;

                continue;
            }

            // A definition list's DESCRIPTION marker is a container opener too,
            // so a footnote definition written on that line is the entry's own
            // content and is collected from it - the same answer the bullet arm
            // above gives (carve-php#891, spec markup-carve/carve#801). `::` is
            // the TERM marker and does not match: it needs whitespace after the
            // single colon, which `::` and a `:::` fence opener both fail.
            // Only when a term opened the entry above it. A description line
            // with no term is not a description at all - it is paragraph text,
            // and a definition in it defines nothing (corpus
            // `216-a-description-line-needs-a-term-above-it`).
            // ONE SPELLING of "is there a term above this", shared with the
            // link-definition pass. Both passes had their own, and both read
            // the RAW previous line - so a term written inside a block quote or
            // a list item (`> :: term`) answered no, the description marker was
            // not stripped, and the definition on the line registered nowhere
            // while the block parser emptied the entry anyway
            // (markup-carve/carve#840).
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
        // A COMMENT's body is opaque too, and this pass alone did not know it:
        // the link-reference and footnote prepasses each tracked `%%%`, so a
        // `*[HTML]: ...` written inside one defined an abbreviation for the
        // whole document while the comment rendered nothing - an <abbr> the
        // reader never wrote, expanded from a line nobody can see (corpus 340).
        // The abbreviation collector reaches its lines by a different path than
        // the other two, which is why widening those left this one behind.
        //
        // Asked at column 0: PART 12 §7 recognizes the definition at document
        // level, so a line an indented or QUOTED comment could hide has already
        // been disqualified - by `$divs` or `$inListItem` below, or by the
        // anchored pattern itself. That is why this pass alone was already
        // right about `> %%%` / `> *[AB]: x` / `> %%%` while the other two
        // registered from inside it (markup-carve/carve#1341): a leak that
        // sorts definitions by KIND is a leak rather than a reading of §28.
        // The shared fence still tracks the quoted region here, so the three
        // passes cannot drift back apart over which lines are a comment's.
        $commentFence = new PrepassCommentFence($lines);
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
                    preg_match('/^([`~]{3,})[ \t]*$/', $line, $fm)
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
                if (preg_match('/^(:{3,})[ \t]*$/', $line, $vm) && strlen($vm[1]) >= $verseFence) {
                    $verseFence = 0;
                }
                $i++;

                continue;
            }
            if ($commentFence->isOpen()) {
                $commentFence->advance($line);
                $i++;

                continue;
            }
            if ($commentFence->opensOn($line, $i, 0)) {
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
                $definition = rtrim($matches[2], " \t");

                $j = $i + 1;

                // Store the abbreviation (case-sensitive). The map answers
                // WHICH definition wins - the last one (PART 9R) - and the
                // list keeps every line the author wrote, shadowed ones
                // included, because the tree is pre-resolve (PART 12 section
                // 3a).
                $this->abbreviations[$abbr] = $definition;
                $this->abbreviationDefinitions[] = ['abbr' => $abbr, 'expansion' => $definition];
                // The expansion is one physical line, as the grammar's
                // `abbreviation_expansion ... newline` production requires.
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
        $this->footnoteDefinitionSpans = [];
        $this->unplaceableNodeIds = [];
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
            if (!preg_match('/^[.#:a-zA-Z]/', $attrStr) || str_starts_with($attrStr, '%')) {
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
                if (!preg_match('/^[.#:a-zA-Z]/', $attrStr) || str_starts_with($attrStr, '%')) {
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
            // THE DEGRADED PARAGRAPH IS PLACEABLE, so it carries its span.
            // Each group is a contiguous run of lines, and the map that turns a
            // local index into a source line is the `$lineMap` argument - it is
            // in scope here and nowhere after, because `$this->currentLineMap`
            // is only swapped in below the cap check. Publishing no position
            // read as compliant (§4 permits it on a REASSEMBLED node) while
            // this node is nothing of the kind (carve-php#945, carve#534).
            // AND SO IS THE CONTENT COLUMN, for the same reason: the swap
            // below the cap check never happens on this path, so without this
            // the degraded paragraph is placed at the column of the level
            // ABOVE it - two hundred quote markers deep, at the second-to-last
            // `>` rather than at the text (PART 12 §4, carve#913).
            $group = [];
            $groupStart = null;
            $previousContentColumns = $this->currentContentColumns;
            $this->currentContentColumns = $this->contentColumnsFor($lines, $lineMap);
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
                $this->currentContentColumns = $previousContentColumns;
            }

            return;
        }

        $this->nestingDepth++;
        $previousLineMap = $this->currentLineMap;
        $previousContentColumns = $this->currentContentColumns;
        $previousCommentFenceLastIndex = $this->commentFenceLastIndex;
        $previousFenceCloserIndexCache = $this->fenceCloserIndexCache;
        $this->currentLineMap = $lineMap;
        $this->currentContentColumns = $this->contentColumnsFor($lines, $lineMap);
        $this->commentFenceLastIndex = null;
        $this->fenceCloserIndexCache = null;
        try {
            $this->parseBlocksImpl($parent, $lines, $indent, $topLevel);
        } finally {
            $this->currentLineMap = $previousLineMap;
            $this->currentContentColumns = $previousContentColumns;
            $this->commentFenceLastIndex = $previousCommentFenceLastIndex;
            $this->fenceCloserIndexCache = $previousFenceCloserIndexCache;
            $this->nestingDepth--;
        }
    }

    /**
     * Place the breaks the degraded text's own newlines produced.
     *
     * A soft break IS a line ending, so its extent is derivable from line
     * geometry exactly as it is on the line-block path - no offset inside the
     * rewritten text is needed, which is what keeps this safe where placing the
     * inline runs would not be.
     *
     * ONLY WHEN THE COUNT PROVES THE MAPPING. The breaks are matched to lines
     * positionally, so the mapping is only sound if the inline parser produced
     * exactly one per gap between the group's lines. If anything else appears -
     * an escape turning one into a hard break, a construct spanning lines - the
     * assumption is wrong and they are left unplaced, because PART 12 §4 rates
     * a wrong span worse than an absent one.
     *
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
        if (!$this->trackPositions || $firstIndex === null) {
            return;
        }

        $breaks = [];
        foreach ($paragraph->getChildren() as $child) {
            if ($child instanceof SoftBreak) {
                $breaks[] = $child;
            }
        }
        if (count($breaks) !== count($group) - 1) {
            return;
        }

        $previousLineMap = $this->currentLineMap;
        $this->currentLineMap = $lineMap;
        foreach ($breaks as $offset => $break) {
            $break->setPos($this->endOfLineSpan($firstIndex + $offset));
        }
        $this->currentLineMap = $previousLineMap;
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
            $previousLineMap = $this->currentLineMap;
            $this->currentLineMap = $lineMap;
            $this->stampBlockSpan(
                $paragraph,
                $this->sourceLineFor($firstIndex),
                $this->sourceLineFor($lastIndex),
            );
            $this->currentLineMap = $previousLineMap;
        }
        $this->inlineParser->parse($paragraph, $text);
        $this->placeDegradedSoftBreaks($paragraph, $group, $lineMap, $firstIndex);
        $this->placeDegradedTextRuns($paragraph, $group, $lineMap, $firstIndex);
        $parent->appendChild($paragraph);
    }

    /**
     * Place the text runs of a degraded paragraph, from line geometry.
     *
     * PART 12 §4 permits omitting `pos` on a REASSEMBLED node and names them; a
     * degraded paragraph is none of them, and neither are its runs - each one
     * is a contiguous slice of exactly one source line. carve-js publishes all
     * of them and its spans pass the slice rule, so an honest span EXISTS here
     * and the exemption does not apply (carve-php#965, carve#534). A node whose
     * parent is placed and whose own span is missing is also the awkward case
     * for a consumer: it can resolve an offset to the paragraph and then not
     * descend into it.
     *
     * ONLY WHEN THE SOURCE PROVES THE MAPPING, on three counts, because these
     * lines were rewritten on the way here - a container prefix was stripped -
     * so nothing about a run's offset can be assumed:
     *
     * 1. The paragraph's DIRECT text children have to number exactly one per
     *    group line, which is what makes the positional match meaningful.
     *    Smart typography splitting a line into two runs fails here.
     * 2. Each run's content has to be a SUFFIX of its source line. That
     *    identifies the stripped prefix without having to know what it was, and
     *    it fails closed when the inline parser rewrote the text rather than
     *    copying it. A trailing backslash fails HERE and not at 1: it makes a
     *    hard break, which leaves the run count intact and takes the backslash
     *    out of the text, so the run is no longer a suffix of its line.
     * 3. Every run has to pass before ANY is placed. A half-placed paragraph
     *    would be a new shape for a consumer to handle, and PART 12 §4 rates a
     *    wrong span worse than an absent one - so the group is all or nothing.
     *
     * Conditions 2 and 3 each reject a document the other two accept, and both
     * are pinned. An earlier draft guarded the shape by requiring strictly
     * alternating runs and SOFT breaks instead; that spelling made 2 and 3
     * unreachable and no mutation of any of the three could be made to fail.
     *
     * CONDITION 1 IS A CONTROL, said out loud rather than counted: no document
     * has been found that it alone rejects, because a line the inline parser
     * splits into two runs makes neither of them a suffix of that line, so 2
     * rejects it first. It is kept because without it an over-count would index
     * runs against lines AFTER the group, where a suffix match could
     * coincidentally succeed and publish a wrong span - which §4 rates worse
     * than the absent one. The same holds for the empty-content guard in
     * degradedRunSpan: no empty run is produced here, but `str_ends_with($line,
     * '')` is vacuously true, so without it an empty run would take a
     * zero-width span at an arbitrary line end.
     *
     * The spans this produces satisfy markup-carve/carve#913's containment
     * invariant by construction: the paragraph's span runs from its first
     * line's start to its last line's end, and every run lies inside one of
     * those lines.
     *
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
        if (!$this->trackPositions || $firstIndex === null) {
            return;
        }

        /** @var array<int, \MarkupCarve\Carve\Node\Inline\Text> $runs */
        $runs = [];
        foreach ($paragraph->getChildren() as $child) {
            if ($child instanceof Text) {
                $runs[] = $child;
            }
        }
        if (count($runs) !== count($group)) {
            return;
        }

        $previousLineMap = $this->currentLineMap;
        $this->currentLineMap = $lineMap;
        $spans = [];
        foreach ($runs as $offset => $run) {
            $spans[$offset] = $this->degradedRunSpan($firstIndex + $offset, $run->getContent());
        }
        $this->currentLineMap = $previousLineMap;

        if (in_array(null, $spans, true)) {
            return;
        }

        foreach ($runs as $offset => $run) {
            $run->setPos($spans[$offset]);
        }
    }

    /**
     * The span of one degraded run: the tail of its source line that the run
     * reproduces byte for byte.
     *
     * The run is a SUFFIX rather than the whole line because a container prefix
     * was stripped from the front on the way in. Matching from the end recovers
     * the offset without the caller having to know the prefix's width, and it
     * refuses outright when the text is not a copy of the source at all.
     */
    private function degradedRunSpan(int $index, string $content): ?SourceSpan
    {
        if ($content === '') {
            return null;
        }

        $sourceLine = $this->sourceLineFor($index);
        $start = $this->lineStartOffsets[$sourceLine] ?? null;
        $line = $this->sourceLines[$sourceLine] ?? null;
        if ($start === null || $line === null || !str_ends_with($line, $content)) {
            return null;
        }

        $runStart = $start + strlen($line) - strlen($content);

        return $this->positionIndex?->span(
            $runStart,
            $runStart + strlen($content),
            $sourceLine + 1,
            $sourceLine + 1,
            $start,
            $start,
        );
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
            if ($topLevel && !$parent->hasChildren() && preg_match('/^---[ \t]*$/', $line) === 1) {
                $matchConsumed = $this->tryBlockMatchers($parent, $lines, $i);
                if ($matchConsumed !== null) {
                    $this->stampSourceLine(
                        $parent,
                        $childrenBefore,
                        $sourceLine,
                        $matchConsumed > 0 ? $this->sourceLineFor($i + $matchConsumed - 1) : $sourceLine,
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
                    $this->stampSourceLine(
                        $parent,
                        $childrenBefore,
                        $sourceLine,
                        $matchConsumed > 0 ? $this->sourceLineFor($i + $matchConsumed - 1) : $sourceLine,
                    );
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
     * The width of the container prefix cut from each of `$lines`.
     *
     * See `$currentContentColumns`. Only the SUFFIX relation is trusted: a
     * built line that is not the tail of the source line it maps to says the
     * text was rewritten rather than merely un-prefixed, and PART 12 §4 rates
     * an absent adjustment above a guessed one.
     *
     * @param array<string> $lines
     * @param array<int, int>|null $lineMap
     *
     * @return array<int, int>
     */
    private function contentColumnsFor(array $lines, ?array $lineMap): array
    {
        if (!$this->trackPositions) {
            return [];
        }

        $columns = [];
        foreach ($lines as $index => $text) {
            $sourceLine = $lineMap[$index] ?? ($lineMap === null ? (int)$index : -1);
            if ($sourceLine < 0) {
                continue;
            }
            $source = $this->sourceLines[$sourceLine] ?? null;
            if ($source === null) {
                continue;
            }
            // THE TAIL IS TRIMMED ON BOTH SIDES, and the width is measured
            // between the trimmed forms. A trailing run does not survive to
            // the same place on both: a definition body arrives with it gone
            // (`:  # h  ` as `# h`) and a quoted line arrives with it kept
            // (`> # h ` as `# h `). Either mismatch alone makes the built text
            // a non-suffix of its source line, and declining the column there
            // is what put the heading back on the `:` and on the `>`. What is
            // being measured is the PREFIX, so the tail is not evidence about
            // it in either direction.
            //
            // THE SUFFIX TEST IS DEFENSIVE AND SAYS SO. Its one remaining
            // input is an item stream RE-JOINED into a single line, and every
            // block built from such a line is a paragraph, which is placed by
            // `foldedLinesSpan` and never reads this map - so removing the
            // test changes no published span in the corpus. It is kept because
            // what it prevents is the MAP recording a width the line does not
            // support, and that is a property of the map rather than of which
            // span helper currently happens to win.
            $trimmedSource = rtrim($source, " \t");
            $trimmedText = rtrim($text, " \t");
            // NO WIDTH TEST. A suffix is never longer than what it is a suffix
            // of, so the difference cannot be negative here, and a zero width
            // records a zero column - which is the same answer as recording
            // nothing. A `$width > 0` guard alongside this survived being
            // mutated away for exactly that reason.
            if (str_ends_with($trimmedSource, $trimmedText)) {
                $columns[$sourceLine] = strlen($trimmedSource) - strlen($trimmedText);
            }
        }

        return $columns;
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
     *
     * `$endBytesOnEndLine` narrows the last line to the bytes the block KEPT.
     * Whole-line geometry is right wherever the line is taken whole, and a line
     * block's content rule drops a trailing one-column run - so the paragraph
     * covered a space its content does not contain (carve-php#1363). Passed in
     * rather than derived here, because the rule belongs to the construct.
     */
    private function stampBlockSpan(Node $node, int $startLine, int $endLine, ?int $endBytesOnEndLine = null): void
    {
        if ($node->getPos() !== null) {
            return;
        }

        // A trailing blank line is normally spacing after the block, not part of
        // it. It is NOT spacing when the block is verbatim and the blank is its
        // own content: a fence that ends with the container rather than with a
        // closer holds that line, and trimming it reported the SAME extent for
        // two documents whose content differs (carve-php#1183).
        if (!$this->endsWithVerbatimBlankLine($node)) {
            while ($endLine > $startLine && IndentationHelper::isBlankLine($this->sourceLines[$endLine] ?? '')) {
                $endLine--;
            }
        }
        $start = $this->lineStartOffsets[$startLine] ?? null;
        $end = $this->lineStartOffsets[$endLine] ?? null;
        if ($start === null || $end === null) {
            // Synthesized content (a footnote section, a resolved reference)
            // has no line of its own. §4 forbids inventing one.
            return;
        }

        $endOffset = $end + ($endBytesOnEndLine ?? strlen($this->sourceLines[$endLine] ?? ''));
        // PART 12 §4: begin at the markup that opens THIS block, not at the
        // container prefix that carried its line (carve#913).
        $opening = $start + ($this->currentContentColumns[$startLine] ?? 0);
        $node->setPos($this->positionIndex?->span(
            min($opening, $endOffset),
            $endOffset,
            $startLine + 1,
            $endLine + 1,
            $start,
            $end,
        ));
    }

    /**
     * Whether this block's own content ends with a blank line.
     *
     * True for a verbatim block whose content ends in a newline - the newline
     * terminates a line, so one more (empty) line belongs to the node - and for
     * a container whose last child is such a block, since the container's span
     * has to reach at least as far as what it holds.
     */
    private function endsWithVerbatimBlankLine(Node $node): bool
    {
        if ($node instanceof CodeBlock || $node instanceof RawBlock || $node instanceof Comment) {
            return str_ends_with($node->getContent(), "\n");
        }

        $children = $node->getChildren();
        $last = $children === [] ? null : $children[array_key_last($children)];

        return $last instanceof Node && $this->endsWithVerbatimBlankLine($last);
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
            if (!preg_match('/^[.#:a-zA-Z]/', $attrStr) || str_starts_with($attrStr, '%')) {
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
            // A QUOTED VALUE STOPS AT THE NEWLINE. `quoted_value` excludes a
            // newline in both of its alternatives (PART 4, A QUOTED VALUE STOPS
            // AT THE NEWLINE), and `block_attributes` reads the same
            // production - so a break inside the quotes is neither content nor
            // a separator. It ends the production, and the whole block is
            // unrecognized (markup-carve/carve#888, carve-php#986).
            //
            // Tested BEFORE the closing branch, not after: `{k="a` + `b"}` has
            // its closing brace on the second line, so a check that ran after
            // the close was matched would accept exactly the shape this
            // refuses. Collapsing that newline to a space is what this engine
            // used to do, and no production in either normative file describes
            // it.
            if ($openQuote !== null) {
                return null;
            }

            $nextLine = $lines[$i];

            // Check if this line ends the attribute block
            if (preg_match('/^(.*)\}[ \t]*$/', $nextLine, $closeMatch)) {
                $attrContent .= ' ' . $closeMatch[1];
                $attrStr = trim($attrContent);

                // Exclude _ * = + - ~ ^ which are braced inline markers (not block attributes)
                if (!preg_match('/^[.#:a-zA-Z]/', $attrStr) || str_starts_with($attrStr, '%')) {
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

        $savedAttributes = $this->pendingAttributes;
        $savedOrder = $this->pendingAttributeOrder;
        try {
            return $this->tryParseBlockAttributes($lines, $start);
        } finally {
            $this->pendingAttributes = $savedAttributes;
            $this->pendingAttributeOrder = $savedOrder;
        }
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

            // A blank LAST line here is real content: the phantom element a
            // terminal newline leaves behind is dropped in splitLines(), where
            // the string is known to be a whole document. Refusing it here
            // refused the genuine blank at the end of a container body too.

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
            // Synthesized from the fence opener, so it takes no `order` slot -
            // there is no attribute block it appeared in (carve#785).
            $codeBlock->setSynthesizedAttribute('title', $header);
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
        if (str_starts_with(ltrim($line, " \t"), '%%')) {
            $comment = new Comment(trim(substr(ltrim($line, " \t"), 2)));
            $sourceLine = $this->sourceLineFor($start);
            $sourceText = $this->sourceLines[$sourceLine] ?? '';
            if (strlen($sourceText) - strlen(ltrim($sourceText, " \t")) === 1) {
                $comment->setPos($this->spanForLineMap([$sourceLine], 0));
            }
            $parent->appendChild($comment);

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
        while ($contentLines && IndentationHelper::isBlankLine($contentLines[0])) {
            array_shift($contentLines);
        }

        $content = implode("\n", $contentLines);

        // Comments are stored but not rendered
        $comment = new Comment($content, $fenceLength);
        $sourceLine = $this->sourceLineFor($start);
        $sourceText = $this->sourceLines[$sourceLine] ?? '';
        if (strlen($sourceText) - strlen(ltrim($sourceText, " \t")) === 1) {
            $lastSourceLine = $this->sourceLineFor(max($start, $i - 1));
            $comment->setPos($this->wholeLinesSpan($sourceLine, $lastSourceLine, 0));
        }
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
        // A dangling attribute line belongs to this container and dies at its
        // boundary. Letting the pending state escape attached it to the next
        // outer block (carve#1028).
        $this->pendingAttributes = [];
        $this->pendingAttributeOrder = [];
        $this->lineOffset = $previousOffset;

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
        foreach (array_keys($this->pendingAttributes) as $name) {
            $authorOrder[] = $name === 'id' ? '#id' : ($name === 'class' ? '.class' : (string)$name);
        }
        foreach ($this->pendingAttributes as $name => $value) {
            if ($name === 'class') {
                foreach (preg_split('/\s+/', trim((string)$value)) ?: [] as $class) {
                    if ($class !== '') {
                        $group->addClass($class);
                    }
                }
            } else {
                $group->setAttribute($name, $value);
            }
        }
        $group->setAttributeOrder($authorOrder);
        $this->pendingAttributes = [];
        $this->pendingAttributeOrder = [];

        $body = $this->collectColonFenceBody($lines, $start, $fenceLength, true);
        $innerLines = $body['lines'];
        $innerLineMap = $body['lineMap'];
        $i = $start + $body['consumed'];

        $previousOffset = $this->lineOffset;
        $this->lineOffset = $previousOffset + $start + 1;
        $this->figureGroupDepth++;
        try {
            $this->parseBlocks($group, $innerLines, 0, $innerLineMap);
        } finally {
            $this->figureGroupDepth--;
        }
        // A dangling attribute line belongs to this container and dies at its
        // boundary, exactly as in tryParseDiv() (carve#1028).
        $this->pendingAttributes = [];
        $this->pendingAttributeOrder = [];
        $this->lineOffset = $previousOffset;

        $parent->appendChild($group);

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
        $this->pendingAttributes = [];
        $this->pendingAttributeOrder = [];
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

        // djot-strict (spec PART 2 headings; matches carve-js #153): a heading
        // line carries NO trailing `{...}` attribute block -- a trailing brace
        // block is ordinary inline content, and the heading id derives from
        // the full literal text. Attributes attach via a PRECEDING
        // block-attribute line (applyPendingAttributes below, PART 9 §15).
        //
        // §756 (NORMATIVE): strip the line's trailing whitespace. A leading tab
        // is preserved (see the extraction note above).
        //
        // SPACE AND TAB ONLY, the same charlist the paragraph collector and the
        // caption use. `whitespace = ' ' | '\t'` (PART 1), so a trailing NBSP -
        // or a VERTICAL TAB, or a FORM FEED - is content and survives. PHP's
        // DEFAULT charlist stood here and is wider: it takes U+000B, which made
        // a heading the one construct in this engine that dropped a trailing
        // vertical tab where the identical paragraph kept it. The emptiness gate
        // above moved with it, because narrowing this charlist alone would have
        // left the heading accepting a TRAILING vertical tab as content while
        // still refusing a heading whose WHOLE content was one
        // (markup-carve/carve-php#1038).
        $content = rtrim($content, " \t");

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
     *
     * ONE SPELLING, shared with the prepasses and the pre-scan
     * ({@see \MarkupCarve\Carve\Parser\ContainerPrefix}). The nine call sites
     * below reach the rule through here, so a container-model change is made in
     * one place rather than in whichever spelling a bug report named
     * (markup-carve/carve-php#961).
     */
    private function blockQuoteLineContent(string $line): ?string
    {
        return ContainerPrefix::quoteContent($line);
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
        $quoteSourceLine = $this->sourceLineFor($start);
        $sourceTail = rtrim($this->sourceLines[$quoteSourceLine] ?? '', " \t");
        $lineTail = rtrim($line, " \t");
        $markerTail = ltrim($lineTail, " \t");
        $quoteOpeningColumn = str_ends_with($sourceTail, $markerTail)
            ? strlen($sourceTail) - strlen($markerTail)
            : ($this->currentContentColumns[$quoteSourceLine] ?? 0);
        $quoteOpeningColumn = max(
            $quoteOpeningColumn,
            $this->currentContentColumns[$quoteSourceLine] ?? 0,
            strlen($sourceTail) - strlen(ltrim($sourceTail, " \t")),
        );

        // Save and clear pending attributes - they apply to the blockquote, not inner content
        $quoteAttributes = $this->pendingAttributes;
        $this->pendingAttributes = [];
        $quoteAttributeOrder = $this->pendingAttributeOrder;
        $this->pendingAttributeOrder = [];

        $innerLines = [];
        $innerLineMap = [];
        $lazyState = self::initialBlockQuoteLazyState();

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
            if ($this->isContinuationMarker($currentLine)) {
                $i++; // consume the `+` marker
                // ONE BLOCK, AND ITS EXTENT IS THE BOUNDARY (§17 L3). The three
                // tests below end the run at the next CONTAINER marker, which is
                // not the same thing: a heading written under the attached
                // paragraph was attached too. {@see self::attachedBlockHasEnded()}
                // is the rule, shared with the list-item spelling so the two
                // cannot drift - they were already one rule with two answers.
                $attachedKind = self::ATTACHED_PENDING;
                $pendingThrough = -1;
                $attachedState = self::INITIAL_TRAILING_BLOCK_STATE;
                [$i, $attached, $attachedRawLineMap] = $this->collectAttachedBlock(
                    $lines,
                    $i,
                    $count,
                    function (string $line, int $index) use (&$attachedKind, &$pendingThrough, &$attachedState, $lines): bool {
                        if (
                            IndentationHelper::isBlankLine($line)
                            || $this->blockQuoteLineContent($line) !== null
                            || $this->isContinuationMarker($line)
                        ) {
                            return true;
                        }
                        if ($this->attachedBlockHasEnded($attachedKind, $line, $lines, $index, $attachedState)) {
                            return true;
                        }
                        $attachedKind = $this->advanceAttachedKind($attachedKind, $pendingThrough, $line, $lines, $index);
                        $attachedState = $this->advanceTrailingBlockState($attachedState, $line);

                        return false;
                    },
                );
                $attachedLineMap = array_map(fn (int $raw): int => $this->sourceLineFor($raw), $attachedRawLineMap);
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
                && !$this->endsBlockQuote($currentLine, $lazyState['paragraphOpen'], $lines, $i)
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
                // list (endsBlockQuote() handles this via paragraphOpen).
                $innerLines[] = $currentLine;
                $innerLineMap[] = $this->sourceLineFor($i);
                $this->trackBlockQuoteLazyState($currentLine, $lazyState, $lines, $i);
                $i++;
            } else {
                break;
            }
        }

        $blockQuote->setPos($this->wholeLinesSpan($start, $i - 1, $quoteOpeningColumn));
        $this->parseBlocks($blockQuote, $innerLines, 0, $innerLineMap);
        // A DANGLING ATTRIBUTE LINE BELONGS TO THIS CONTAINER AND DIES AT ITS
        // BOUNDARY (§15 A4: a pending run that reaches the end with no block
        // element to attach to is dropped). The state is parser-global, so
        // without this a `{...}` written inside the quote with nothing after it
        // reached the next block OUTSIDE and attributed that - the same defect
        // carve#1028 fixed for a div and carve-php#757 for a list item, in the
        // one container that never got the line.
        $this->endContainerAttributeScope();

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
     */

    /**
     * A quote's lazy tracker before it has read a line.
     *
     * @return array{mode:\MarkupCarve\Carve\Parser\BlockQuoteLazyMode,fenceChar:string,fenceLength:int,commentLength:int,paragraphOpen:bool,divFenceLength:int,divDepth:int,absorbingFence:bool,inTable:bool,innerDepth:int}
     */
    private static function initialBlockQuoteLazyState(): array
    {
        return [
            'mode' => BlockQuoteLazyMode::Content,
            'fenceChar' => '',
            'fenceLength' => 0,
            'commentLength' => 0,
            'paragraphOpen' => false,
            'divFenceLength' => 0,
            'divDepth' => 0,
            'absorbingFence' => false,
            'inTable' => false,
            'innerDepth' => 0,
        ];
    }

    /**
     * @param string $content Inner content line (after the "> " marker is stripped).
     * @param array{mode:\MarkupCarve\Carve\Parser\BlockQuoteLazyMode,fenceChar:string,fenceLength:int,commentLength:int,paragraphOpen:bool,divFenceLength:int,divDepth:int,absorbingFence:bool,inTable:bool,innerDepth:int} $state
     *     Running state, mutated in place.
     * @param array<string> $sourceLines
     * @param int $sourceIndex
     * @param bool $nested
     */
    private function trackBlockQuoteLazyState(
        string $content,
        array &$state,
        array $sourceLines,
        int $sourceIndex,
        bool $nested = false,
    ): void {
        // A NESTED RUN OWNS ITS STATE, AND ONLY WHILE IT LASTS. The state is
        // shared down the recursion so one run carries its own history - an
        // absorbed colon fence, an open code fence, a table's continuation row.
        // Between two runs it must not be: an unterminated fence inside `> >`,
        // a `> ` line that ends that quote, and a later `> >` would have read
        // the new quote's first line as more fence content, and the `> ` line
        // itself as content of a fence one level in.
        //
        // So the run is keyed by the DEPTH the line reaches, and the mode is
        // reset whenever that changes. Checked before the mode branches, since
        // those return early - and only on the outermost call, because the
        // recursion is one line's walk rather than a new line.
        if (!$nested) {
            $depth = 0;
            for ($rest = $content; ($deeper = ContainerPrefix::quoteContent(rtrim($rest, " \t"))) !== null; $rest = $deeper) {
                $depth++;
            }
            if ($state['innerDepth'] !== $depth) {
                $paragraphOpen = $state['paragraphOpen'];
                $state = self::initialBlockQuoteLazyState();
                $state['innerDepth'] = $depth;
                // The PARAGRAPH survives a change of depth: it is the outer
                // quote's own last block, and what ends is the nested run.
                $state['paragraphOpen'] = $paragraphOpen;
            }
        }

        // PART 9 §12's absorption belongs to ONE open paragraph, so it ends
        // wherever that paragraph does. Cleared here and re-armed only in the
        // branches that continue the same paragraph, exactly as the list-item
        // tracker does it.
        $wasAbsorbing = $state['absorbingFence'];
        $state['absorbingFence'] = false;
        // A CONTINUATION ROW IS MORE TABLE, and only where a table is above it
        // (markup-carve/carve#1349). Carried the same way the absorption is,
        // and for the same reason: every other block ends the table.
        $wasInTable = $state['inTable'];
        $state['inTable'] = false;

        if ($state['mode'] === BlockQuoteLazyMode::CommentFence) {
            if ($this->fencedBlockParser->isFencedCommentCloser($content, $state['commentLength'])) {
                $state['mode'] = BlockQuoteLazyMode::Content;
            }
            $state['paragraphOpen'] = false;

            return;
        }

        if ($state['mode'] === BlockQuoteLazyMode::CodeFence) {
            if ($this->fencedBlockParser->isCodeFenceCloser($content, $state['fenceChar'], $state['fenceLength'])) {
                $state['mode'] = BlockQuoteLazyMode::Content;
            }
            $state['paragraphOpen'] = false;

            return;
        }

        if (IndentationHelper::isBlankLine($content)) {
            $state['paragraphOpen'] = false;

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
                $state['mode'] = BlockQuoteLazyMode::CodeFence;
                $state['fenceChar'] = $fenceInfo['char'];
                $state['fenceLength'] = $fenceInfo['length'];
                $state['paragraphOpen'] = false;

                return;
            }

            $commentInfo = $this->fencedBlockParser->parseFencedCommentOpener($content);
            if ($commentInfo !== null && $this->hasClosingCommentFenceAheadInBlockQuote($sourceLines, $sourceIndex, $commentInfo['length'])) {
                $state['mode'] = BlockQuoteLazyMode::CommentFence;
                $state['commentLength'] = $commentInfo['length'];
                $state['paragraphOpen'] = false;

                return;
            }
        }

        // A DIV IS A CONTAINER ON THE OPEN STACK, and S4 asks what that stack
        // holds - not which container kind is on it. This branch used to sit
        // inside the `!paragraphOpen` guard above, so `> quote` + `> ::: note`
        // never reached it: the opener left the QUOTE's paragraph flag standing
        // and a flush-left line folded into the div. The identical shape in a
        // list item already answered correctly, and one construct answering S4
        // two ways is a bug in one of the two paths
        // (markup-carve/carve#920, corpus 271).
        if ($state['mode'] === BlockQuoteLazyMode::Div) {
            if ($this->fencedBlockParser->isDivFenceCloser($content, $state['divFenceLength'])) {
                // A CLOSED container holds no open paragraph either.
                $state['divDepth']--;
                $state['mode'] = $state['divDepth'] > 0 ? BlockQuoteLazyMode::Div : BlockQuoteLazyMode::Content;
                $state['paragraphOpen'] = false;

                return;
            }

            // A NESTED OPENER IS STILL AN OPENER. S4 asks about the INNERMOST
            // open container, so a `:::: tip` as the last line inside a `:::
            // note` leaves an EMPTY container on the stack and no paragraph -
            // the same answer the outer opener gets one level up. A code fence
            // opener leaves none either.
            if ($this->fencedBlockParser->parseDivFenceOpener($content) !== null) {
                $state['divDepth']++;
                $state['paragraphOpen'] = false;

                return;
            }
            if ($this->fencedBlockParser->parseCodeFenceOpener($content) !== null) {
                $state['paragraphOpen'] = false;

                return;
            }

            // A BOUNDED BLOCK inside the div leaves no open paragraph either,
            // for the same reason it does not outside one: a heading, a
            // thematic break and a table row all end at their own boundary.
            // Measured against the executable spec rather than assumed - the
            // list-item path answers the HEADING row the other way, and both
            // are reproduced as measured rather than made consistent.
            $trimmedInDiv = ltrim($content, " \t");
            if (
                preg_match('/^#{1,6} .*' . StringUtil::NON_WHITESPACE_CLASS . '/', $trimmedInDiv) === 1
                || preg_match('/^([-*_])\1{2,}[ \t]*$/', $trimmedInDiv) === 1
                || $this->tableParser->isTableRow($trimmedInDiv)
            ) {
                $state['paragraphOpen'] = false;

                return;
            }

            // An UNTERMINATED div's own trailing block decides: a line of body
            // text in it IS an open paragraph, which is what folds the
            // flush-left line into a real div rather than ending the quote. A
            // BLANK line never reaches here - the branch above it returns first
            // and leaves `inDiv` standing - so every line that does is body.
            $state['paragraphOpen'] = true;

            return;
        }

        $bareFence = preg_match('/^:{3,}[ \t]*$/', ltrim($content, " \t")) === 1;
        $divOpener = $this->fencedBlockParser->parseDivFenceOpener($content);
        if ($divOpener !== null) {
            // ...unless the paragraph above already absorbed a MALFORMED fence
            // and this is a BARE run, in which case §12 takes it as text too and
            // the paragraph stays open (corpus 260). Not width-tagged: after a
            // malformed `:::note` a following `::::` is absorbed as readily as a
            // `:::`.
            if ($wasAbsorbing && $bareFence) {
                $state['absorbingFence'] = true;
                $state['paragraphOpen'] = true;

                return;
            }
            // A container a quoted line has just opened is EMPTY and holds no
            // open paragraph, so a flush-left line after it closes the quote
            // instead of folding in.
            /** @var int $divFenceLength */
            $divFenceLength = $divOpener['length'];
            $state['mode'] = BlockQuoteLazyMode::Div;
            $state['divFenceLength'] = $divFenceLength;
            $state['divDepth'] = 1;
            $state['paragraphOpen'] = false;

            return;
        }

        // A fence-shaped line that is NOT a valid opener is ordinary paragraph
        // text, and from here the paragraph absorbs the next fence-shaped line
        // as well. `:::note` fails §12's opener test because a type word must be
        // separated from the fence by a space.
        if (preg_match('/^:{3,}/', ltrim($content, " \t")) === 1) {
            $state['absorbingFence'] = true;
            $state['paragraphOpen'] = true;

            return;
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
        // These used to leave the paragraph open, so `> # h` / `b` kept the
        // quote open and put `b` inside it. carve-rs closes it; tracking two
        // subtly different paragraph booleans was the bug (carve-php#652).
        // AND THE QUOTE IT IS ASKED OF MAY ITSELF BE A QUOTE (PART 1 S4,
        // markup-carve/carve#1355). A quote's answer is its own last block's,
        // and when that block is a QUOTE the question just moves in one. Asked
        // only of this quote's own content, `> > # H` read the inner `> # H` as
        // prose - it starts with `>` and not `#` - so the outer quote reported
        // an open paragraph and `tail` folded into it, while the same heading
        // one level up already ended the quote.
        //
        // ON THE SAME STATE, not a fresh one per line. Every flag a quote
        // carries has to cross its own line boundaries - an absorbed colon
        // fence (corpus 260-4), an open code fence, a table's continuation row
        // (corpus 356-8) - and while the content is a nested quote, the outer
        // quote holds no block of its own for those flags to describe. So the
        // inner call keeps them, and the two flags cleared at the top of THIS
        // call are handed back for it to decide.
        //
        // The mode branches above run FIRST, which is what keeps the levels
        // apart: a fence opened at the outer level owns the lines below it
        // whatever markers they carry, and this step is only reached by a plain
        // nested-quote line. Recursing on the CONTENT is also what makes three
        // levels work without counting them (corpus 356-6, 356-9).
        $innerContent = ContainerPrefix::quoteContent(rtrim($content, " \t"));
        if ($innerContent !== null) {
            // A NEW INNER QUOTE STARTS WITH NOTHING OPEN. The shared state is
            // right ACROSS the lines of one nested run and wrong between two of
            // them: an unterminated code fence inside `> >`, a `> ` line that
            // ends that quote, and a later `> >` would have read the new
            // quote's first line as more fence content. So the run is keyed by
            // its depth and the mode is reset when the depth changes, which is
            // the least state that still lets one run carry its own history.
            $state['absorbingFence'] = $wasAbsorbing;
            $state['inTable'] = $wasInTable;
            $this->trackBlockQuoteLazyState($innerContent, $state, $sourceLines, $sourceIndex, true);

            return;
        }

        $trimmed = ltrim($content, " \t");
        $atContentColumn = $trimmed === $content;
        $isHeading = $atContentColumn && preg_match('/^#{1,6} .*' . StringUtil::NON_WHITESPACE_CLASS . '/', $trimmed) === 1;
        $isThematicBreak = $atContentColumn && preg_match('/^([-*_])\1{2,}[ \t]*$/', $trimmed) === 1;
        $isTableRow = $atContentColumn && $this->tableParser->isTableRow($trimmed);
        // A TABLE IS A TABLE HOWEVER ITS LAST ROW IS SPELLED. A continuation
        // row carries no leading pipe, so the row test above does not see it,
        // and `> | a |` / `> + b |` / `tail` kept `tail` inside the quote where
        // the standard-row spelling of the same table sends it out
        // (markup-carve/carve#1348, corpus 349-3).
        $isContinuationRow = $atContentColumn
            && $wasInTable
            && $this->tableParser->isContinuationRow($trimmed);
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

        // A FLOATING ATTRIBUTE ATTACHES FORWARD, so it is not a paragraph the
        // line behind it could join, and it is the one invisible line this list
        // was missing. The list-item spelling of the tracker gained it with the
        // S4 sweep; leaving it out here made `> q` / `> {.k}` / `tail` fold
        // `tail` into the quote - where the attribute then landed ON it.
        // AT THE CONTENT COLUMN, like the three rows above it.
        // `tryParseBlockAttributes()` requires the line to BEGIN with `{`, so
        // `>  {.k}` - a space of indentation inside the quote - is ordinary
        // paragraph text and a flush-left line lazily continues it. Read
        // ltrimmed, this closed a paragraph the parser had built.
        $isAttributeLine = $atContentColumn && $this->isBlockAttributeLine($trimmed);
        // AN INVISIBLE LINE AT THE CONTENT COLUMN IS A BLOCK, and ends the
        // paragraph exactly as a definition does (markup-carve/carve#1350).
        // BELOW the column the same line is a lazy continuation and adds no
        // block, which is what keeps `> a` / `%% c` / `b` folding.
        $isCommentLine = $atContentColumn && $this->isCommentLineOrFence($trimmed);

        $leavesNoParagraph = $isHeading
            || $isThematicBreak
            || $isTableRow
            || $isContinuationRow
            || $isCommentLine
            || $isDefinitionTerm
            || $isDefinitionLine
            || $isAttributeLine;

        // An absorption already under way survives PROSE, because that is the
        // same paragraph - but not a heading or a thematic break, which end it.
        $state['absorbingFence'] = $wasAbsorbing && !$leavesNoParagraph;
        $state['inTable'] = $isTableRow || $isContinuationRow;
        $state['paragraphOpen'] = !$leavesNoParagraph;
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
        $listInfo = $this->listParser->parseListItemMarker(ltrim($line, " \t"));
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
        $listSourceLine = $this->sourceLineFor($start);
        $sourceTail = rtrim($this->sourceLines[$listSourceLine] ?? '', " \t");
        $lineTail = rtrim($line, " \t");
        $listOpeningColumn = $parent instanceof Document
            ? 0
            : (str_ends_with($sourceTail, $lineTail)
                ? strlen($sourceTail) - strlen($lineTail)
                : ($this->currentContentColumns[$listSourceLine] ?? 0));

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
            if ($currentIndent === $baseIndent && $this->isContinuationMarker(ltrim($currentLine, " \t"))) {
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
                && $this->listParser->parseListItemMarker(ltrim($currentLine, " \t")) !== null;
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
                    //
                    // NOT THE LEAD. This stream is the item's POST-BLANK nested
                    // content, so the item's lead is the marker line that was
                    // read further up and the first line HERE is a later block.
                    // Left at the constant's `true`, `- text` / blank / `  # N`
                    // / `lazy` read the heading as the item's lead and pushed
                    // `lazy` out of an item that plainly still holds `text`.
                    $subTrailingState = ['isLead' => false] + self::INITIAL_TRAILING_BLOCK_STATE;
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
                        // BOUNDED. This is asked of every collected line at
                        // every nesting level, and it walks the line's whole
                        // indentation run - so on a deep ladder it was 98.5% of
                        // this parser's indentation work and cubic in depth
                        // (markup-carve/carve#752). Every comparison below is
                        // against $subIndent or $baseIndent, and the only other
                        // consumer is $maxContentIndent, which is itself only
                        // ever read as `> $subIndent`. Saturating at one past
                        // the larger of the two therefore answers all of them
                        // exactly: a run that overshoots the cap had already
                        // decided every one of these tests.
                        $lineIndent = IndentationHelper::getLeadingColumns(
                            $subLine,
                            max($subIndent, $baseIndent) + 1,
                        );

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
                            $strippedIsMarker = $this->listParser->parseListItemMarker(ltrim($stripped, " \t")) !== null;
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
                            $trimmedLine = ltrim($subLine, " \t");
                            $itemInfo = $this->listParser->parseListItemMarker($trimmedLine);
                            $sameStyle = !isset($listInfo['style']) || !isset($itemInfo['style']) || $itemInfo['style'] === $listInfo['style'];
                            if ($itemInfo !== null && $itemInfo['type'] === $listInfo['type'] && $itemInfo['marker'] === $listInfo['marker'] && $sameStyle) {
                                if ($sawBlankLine) {
                                    $lastItemHadBlankAfter = true;
                                    $brokeForParentContent = true;
                                }

                                break;
                            }
                            // A lone `+` at the marker column is the CONTINUATION
                            // MARKER (§17 L3), whatever this item already holds.
                            // The clause conditions it on the column and on
                            // nothing else - not on the item being tight, and not
                            // on what was written above - so breaking here hands
                            // it to the loop that attaches the following block,
                            // exactly as happens when no blank line preceded it.
                            //
                            // Without this the marker fell through to the lazy
                            // branch below and came out as literal text inside the
                            // paragraph it was meant to end, so the same construct
                            // read two ways depending on unrelated context above
                            // (carve-php#925). `collectListContinuationBlock()`
                            // already stops on exactly this line; this collector
                            // was the one short of the case.
                            if ($this->isContinuationMarker($trimmedLine)) {
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
                            //
                            // AN OPEN FENCE IS NOT AN OPEN PARAGRAPH, and it
                            // does not extend the item's reach: §24's STEP walk
                            // stops at the ITEM for a line that supplies no
                            // indentation, so S2 FENCED BODY never fires and
                            // S4's lazy branch has no paragraph to fold into
                            // (markup-carve/carve#950). The plain-lead collector
                            // states the same rule for a fence opened on the
                            // MARKER line; this loop is the twin that collects
                            // an item's POST-BLANK nested content, and the two
                            // have to answer one question the same way.
                            if (
                                !$subTrailingState['openParagraph']
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
                            $trimmedLine = ltrim($subLine, " \t");
                            // AN OPEN FENCE ENDS THE ITEM HERE TOO. Between the
                            // base column and the content column the line still
                            // supplies less indentation than the item's prefix,
                            // so §24's STEP walk stops at the ITEM exactly as it
                            // does at column 0 and S4 finds no open paragraph to
                            // fold into (markup-carve/carve#950, corpus row 2 -
                            // written at column 1 precisely because the broken
                            // readings differed between the two columns).
                            if ($subTrailingState['inFence']) {
                                break;
                            }
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
            $trimmedLine = ltrim($currentLine, " \t");
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

            // The previous item ends HERE, so its pending-attribute run ends
            // here too (§15 A4).
            $this->endContainerAttributeScope();

            /** @var string|null $taskMarker */
            $taskMarker = $itemInfo['taskMarker'] ?? null;
            $listItem = new ListItem($taskMarker);
            $listItemSourceLine = $this->sourceLineFor($i);
            $itemSource = $this->sourceLines[$listItemSourceLine] ?? '';
            $itemMarker = ltrim($line, " \t");
            $itemMarkerColumn = str_ends_with($itemSource, $itemMarker)
                ? strlen($itemSource) - strlen($itemMarker)
                : $listOpeningColumn;
            $itemPrefix = substr($itemSource, 0, $itemMarkerColumn);
            $itemOpeningColumn = str_contains($itemPrefix, "\t")
                && $itemMarkerColumn <= $listOpeningColumn
                ? 0
                : $listOpeningColumn;
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
            //
            // THE MARKER LINE IS NOT A SPECIAL COLUMN. A table written there
            // used to re-arm `openParagraph`, on the reading that it "owns" the
            // following lazy line. S4 has no such ownership: it asks what the
            // container's last block left open, and a completed table leaves
            // nothing wherever it was written. The carve-out made `- | a | b |`
            // followed by a column-0 line fold that line into the item, while
            // the same table one line lower ended it (corpus 326-3).
            $trailingState = self::INITIAL_TRAILING_BLOCK_STATE;
            $trailingState = $this->advanceTrailingBlockState($trailingState, $itemContent);

            // First-block item (Carve): `- +` opens an item whose body is the
            // flush-left block that follows, with no indentation. A lone `+` as
            // the sole item content is the continuation marker, not literal text
            // (`- + text` keeps `+ text` as literal content). This lets an item
            // start directly with a table, code block, quote or div at column 0.
            if ($this->isContinuationMarker(ltrim($itemContent, " \t"))) {
                [$i, $attached, $attachedLineMap] = $this->collectListContinuationBlock($lines, $i, $count, $baseIndent);
                // PART 12 §4: the item begins at its MARKER (carve#913). This
                // item's body is flush left, so leaving the span to be derived
                // from the children started it at the attached block - `- +`
                // followed by a table gave the item the table's offset, past
                // its own marker line entirely. `deriveContainerSpans` unions
                // this with the body, so the extent still reaches the end.
                $listItem->setPos($this->spanForLineMap([$listItemSourceLine]));
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
                $listItem->setPos($this->spanForLineMap($itemLineMap, $itemOpeningColumn));
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
                $listItem->setPos($this->spanForLineMap($itemLineMap, $itemOpeningColumn));
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
            $listItem->setPos($this->spanForLineMap($itemLineMap, $itemOpeningColumn));
            $this->parseItemBlocks($listItem, $itemLines, $itemLineMap);

            $list->appendChild($listItem);
        }

        // The last item ends with the list, so a run still pending here found
        // no block inside it and attaches to nothing - it must not reach the
        // block that follows the list at document level (§15 A4).
        $this->endContainerAttributeScope();

        // Apply the saved attributes to the list
        if ($listAttributes !== []) {
            $list->setAttributesWithOrder($listAttributes, $listAttributeOrder);
        }
        $parent->appendChild($list);

        return $i - $start;
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
     */
    protected function parseItemBlocks(Node $item, array $lines, ?array $lineMap = null): void
    {
        $this->parseBlocks($item, $lines, 0, $lineMap);
        if ($lineMap !== null && $lineMap !== []) {
            $this->repairNestedParagraphSuffixes($item, $lineMap[0]);
        }
    }

    /**
     * End the pending-attribute run that a CONTAINER scopes.
     *
     * §15 A2a floats a pending attribute to the next VISIBLE block and A4
     * drops a run that reaches the end with nothing to attach to. The ITEM
     * boundary is such an end: an attribute written inside one item that finds
     * no block there attaches to nothing, rather than reaching into the NEXT
     * item's paragraph - which would make a `{...}` line's effect depend on
     * where the list happens to break. The state is parser-global, so without
     * this the run simply survived into the sibling's parse
     * (carve-php#757, markup-carve/carve-js#620).
     *
     * This used to fire at the end of every CHUNK, which is a boundary the item
     * does not have: the collector splits an item at a nested marker, so
     * `{.x}` on the line before that marker was stranded at the end of one
     * chunk with the nested list at the start of the next and was discarded,
     * while the same line before a paragraph, quote or fence - none of which
     * break the chunk - attached normally (markup-carve/carve#1238).
     */
    private function endContainerAttributeScope(): void
    {
        $this->pendingAttributes = [];
        $this->pendingAttributeOrder = [];
    }

    private function repairNestedParagraphSuffixes(Node $node, int $sourceLine): void
    {
        foreach ($node->getChildren() as $child) {
            if ($child instanceof Paragraph) {
                $inlines = $child->getChildren();
                if (count($inlines) === 1 && $inlines[0] instanceof Text) {
                    $value = $inlines[0]->getContent();
                    $existing = $inlines[0]->getPos();
                    if ($existing !== null && $existing->startLine !== $sourceLine + 1) {
                        $this->repairNestedParagraphSuffixes($child, $sourceLine);

                        continue;
                    }
                    $source = rtrim($this->sourceLines[$sourceLine] ?? '', " \t");
                    if ($value !== '' && str_ends_with($source, $value)) {
                        $lineStart = $this->lineStartOffsets[$sourceLine] ?? null;
                        if ($lineStart !== null) {
                            $byte = $lineStart + strlen($source) - strlen($value);
                            $span = $this->positionIndex?->span(
                                $byte,
                                $byte + strlen($value),
                                $sourceLine + 1,
                                $sourceLine + 1,
                                $lineStart,
                                $lineStart,
                            );
                            $inlines[0]->setPos($span);
                            $child->setPos($span);
                        }
                    }
                }
            }
            $this->repairNestedParagraphSuffixes($child, $sourceLine);
        }
    }

    /**
     * Is this the §17 L3 continuation marker?
     *
     * "A line whose only content is `+`" - so trailing whitespace is not
     * content, matching the executable spec's own `/^\+[ \t]*$/`.
     *
     * ONE PREDICATE, and the CALLER owns the column. This was spelled four ways
     * across seven sites - `trim()`, `rtrim()`, and twice against an already
     * `ltrim`ed value - so whether a trailing space broke the marker depended on
     * which code path a document happened to reach (carve-php#929, and the same
     * asymmetry produced carve-php#925). Leading whitespace is deliberately NOT
     * stripped here: the block-quote form requires column 0 and the list form
     * checks its own base indent, so each caller passes a line whose indentation
     * it has already accounted for.
     *
     * THE CHARLIST IS `whitespace`, not PHP's default. The default is
     * `" \t\n\r\0\x0B"`, which also takes a VERTICAL TAB - so a line holding
     * `+` and one U+000B was a marker here while the spelling this docblock
     * quotes says it is not. `continuation_marker = '+', newline` spells NO
     * RUN AT ALL (PART 7), so any character between the `+` and the line end
     * is content. The predicate was unified across seven sites by
     * carve-php#929; the DEFINITION was not (carve-php#1041).
     */
    protected function isContinuationMarker(string $line): bool
    {
        return rtrim($line, StringUtil::WHITESPACE_CHARS) === '+';
    }

    /**
     * Where a closer of each fence shape LAST occurs in $lines.
     *
     * PERMISSIVE ON PURPOSE. A caller may read a DEDENTED view of these lines,
     * where MORE lines are closer-shaped than in the raw text, so the patterns
     * tolerate a leading indentation run. The index is therefore a SUPERSET of
     * what any view can match, and "no closer ahead" holds for every view. It only
     * ever refutes; a positive answer sends the caller to the real scan.
     *
     * @param array<string> $lines
     *
     * @return array{comment: array<int, int>, colon: array<int, int>, code: array<string, array{runs: array<int, int>, lastAtLeast: array<int, int>}>}
     */
    private function fenceCloserIndex(array $lines): array
    {
        if ($this->fenceCloserIndexCache === null) {
            $comment = [];
            $colon = [];
            $code = [];
            foreach ($lines as $i => $line) {
                $info = $this->fencedBlockParser->parseFencedCommentOpenerAnyColumn($line);
                if ($info !== null) {
                    $comment[$info['length']] = $i;
                }
                // THE TRAILING RUN IS `\s`, matching `isDivFenceCloser()` and
                // `isCodeFenceCloser()` exactly. A narrower `[ \t]*` here is a
                // FALSE NEGATIVE rather than a stricter reading: those two
                // accept a closer padded with a vertical tab or a form feed, so
                // an index that does not see one refutes a fence that really
                // does close, and the collector falls back to the boundary set
                // and splits the body it was meant to keep. The invariant this
                // index owes its callers is that it is a SUPERSET of what they
                // can match - narrowing it is only safe once the closers
                // themselves narrow. Raised by codex review.
                if (preg_match('/^[ \t]*(:{3,})[ \t]*$/', $line, $m) === 1) {
                    $colon[strlen($m[1])] = $i;
                }
                if (preg_match('/^[ \t]*([`~]{3,})[ \t]*$/', $line, $m) === 1) {
                    $code[$m[1][0]][strlen($m[1])] = $i;
                }
            }
            // A CODE closer matches at the opener's length OR LONGER, so the
            // answer for length L is the largest last-index over every recorded
            // run >= L. Precomputed as a suffix maximum over the ascending
            // runs, then binary-searched: scanning the recorded runs per query
            // is itself quadratic on the shape this index exists to refute - a
            // document of openers with DISTINCT widths, where no width repeats
            // and every query walks the whole table.
            $codeRuns = [];
            foreach ($code as $char => $byRun) {
                ksort($byRun);
                $runs = array_keys($byRun);
                $lastAtLeast = [];
                $best = -1;
                for ($k = count($runs) - 1; $k >= 0; $k--) {
                    $best = max($best, $byRun[$runs[$k]]);
                    $lastAtLeast[$k] = $best;
                }
                ksort($lastAtLeast);
                $codeRuns[$char] = ['runs' => $runs, 'lastAtLeast' => $lastAtLeast];
            }
            $this->fenceCloserIndexCache = [
                'comment' => $comment,
                'colon' => $colon,
                'code' => $codeRuns,
            ];
        }

        return $this->fenceCloserIndexCache;
    }

    /**
     * Refute an exact-width closer without rescanning the document.
     *
     * @param array<int, int> $last
     * @param int $after
     * @param int $length
     */
    private function exactCloserPossible(array $last, int $length, int $after): bool
    {
        return ($last[$length] ?? -1) > $after;
    }

    /**
     * Refute a code/raw closer without rescanning the document; those closers
     * may be the opener width or wider.
     *
     * @param array<string, array{runs: array<int, int>, lastAtLeast: array<int, int>}> $index
     * @param int $after
     * @param int $length
     * @param string $char
     */
    private function codeCloserPossible(array $index, string $char, int $length, int $after): bool
    {
        $entry = $index[$char] ?? null;
        if ($entry === null) {
            return false;
        }
        // The first recorded run >= $length; its suffix maximum is the last
        // index of any run that could close this fence.
        $runs = $entry['runs'];
        $lo = 0;
        $hi = count($runs);
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($runs[$mid] < $length) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        return $lo < count($runs) && $entry['lastAtLeast'][$lo] > $after;
    }

    /**
     * Find the end of a code/raw or comment fence, whose body is opaque to all
     * other attached-block boundaries and fence shapes.
     *
     * @param array<string> $lines
     * @param callable|null $transform
     * @param int $count
     * @param int $i
     */
    private function opaqueSpanEnd(array $lines, int $i, int $count, ?callable $transform): int
    {
        // Past the end reads as empty, which opens nothing. See
        // `attachedFencedBlockEnd()` on why this is a value and not a branch.
        $view = $lines[$i] ?? '';
        $view = $transform === null ? $view : $transform($view);
        $opener = $this->fencedBlockParser->parseCodeFenceOpener($view)
            ?? $this->fencedBlockParser->parseRawBlockOpener($view);
        if ($opener !== null) {
            $index = $this->fenceCloserIndex($lines);
            if (!$this->codeCloserPossible($index['code'], $opener['char'] ?? $opener['fence'][0], $opener['length'], $i)) {
                return -1;
            }
            $char = $opener['char'] ?? $opener['fence'][0];
            for ($j = $i + 1; $j < $count; $j++) {
                $candidate = $transform === null ? $lines[$j] : $transform($lines[$j]);
                if ($this->fencedBlockParser->isCodeFenceCloser($candidate, $char, $opener['length'])) {
                    return $j;
                }
            }

            return -1;
        }

        $comment = $this->fencedBlockParser->parseFencedCommentOpenerAnyColumn($view);
        if ($comment === null) {
            return -1;
        }
        $index = $this->fenceCloserIndex($lines);
        if (!$this->exactCloserPossible($index['comment'], $comment['length'], $i)) {
            return -1;
        }
        for ($j = $i + 1; $j < $count; $j++) {
            $candidate = $transform === null ? $lines[$j] : $transform($lines[$j]);
            if ($this->fencedBlockParser->isFencedCommentCloserAnyColumn($candidate, $comment['length'])) {
                return $j;
            }
        }

        return -1;
    }

    /**
     * Find the exact-width closer of a colon fence while treating nested fence
     * widths as a stack and code/comment bodies as opaque.
     *
     * @param array<string> $lines
     * @param callable|null $transform
     * @param int $count
     * @param int $length
     * @param int $openIdx
     */
    private function colonFenceEnd(array $lines, int $openIdx, int $length, int $count, ?callable $transform): int
    {
        $index = $this->fenceCloserIndex($lines);
        if (!$this->exactCloserPossible($index['colon'], $length, $openIdx)) {
            return -1;
        }
        $stack = [$length];
        for ($j = $openIdx + 1; $j < $count; $j++) {
            $span = $this->opaqueSpanEnd($lines, $j, $count, $transform);
            if ($span !== -1) {
                $j = $span;

                continue;
            }
            $view = $transform === null ? $lines[$j] : $transform($lines[$j]);
            $top = $stack[count($stack) - 1];
            if ($this->fencedBlockParser->isDivFenceCloser($view, $top)) {
                array_pop($stack);
                if ($stack === []) {
                    return $j;
                }

                continue;
            }
            if (preg_match('/^(:{3,})[ \t]*$/', $view, $m) === 1 && strlen($m[1]) !== $top) {
                $stack[] = strlen($m[1]);

                continue;
            }
            $opener = $this->fencedBlockParser->parseDivFenceOpener($view);
            if ($opener !== null) {
                $stack[] = $opener['length'];
            }
        }

        return -1;
    }

    /**
     * Return the last line of a fenced block only when the attached block's
     * first line opens one; otherwise ordinary container boundaries decide.
     *
     * @param array<string> $lines
     * @param callable|null $transform
     * @param int $count
     * @param int $i
     */
    private function attachedFencedBlockEnd(array $lines, int $i, int $count, ?callable $transform): int
    {
        $opaque = $this->opaqueSpanEnd($lines, $i, $count, $transform);
        if ($opaque !== -1) {
            return $opaque;
        }
        // PAST THE END READS AS EMPTY rather than as a guarded branch. A `+` on
        // the last line reaches here with nothing after it, and an `$i >=
        // $count` test for it is a check no caller can fire: every spelling of
        // a trailing `+` was measured and none reaches it. A value fallback
        // opens nothing and needs no such claim.
        $view = $lines[$i] ?? '';
        $view = $transform === null ? $view : $transform($view);
        $opener = $this->fencedBlockParser->parseDivFenceOpener($view);

        return $opener === null
            ? -1
            : $this->colonFenceEnd($lines, $i, $opener['length'], $count, $transform);
    }

    /**
     * Collect the ONE flush-left block a `+` continuation marker attaches
     * (PART 9 §17 L3). The boundary remains container-specific, while a fence
     * opened by the first line makes its complete body opaque everywhere.
     *
     * An unterminated fence falls back to the caller's existing boundaries:
     * without a closer there is no complete fenced block to take as one unit.
     *
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
        $fenced = $this->attachedFencedBlockEnd($lines, $i, $count, $transform);
        if ($fenced !== -1) {
            $take = $fenced - $i + 1;
        } else {
            $take = 0;
            while ($i + $take < $count && !$isBoundary($lines[$i + $take], $i + $take)) {
                $take++;
            }
        }
        $collected = [];
        $rawLineMap = [];
        for ($j = 0; $j < $take; $j++) {
            $rawIndex = $i + $j;
            $collected[] = $transform === null ? $lines[$rawIndex] : $transform($lines[$rawIndex]);
            $rawLineMap[] = $rawIndex;
        }

        return [$i + $take, $collected, $rawLineMap];
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
        $savedAttributes = $this->pendingAttributes;
        $savedOrder = $this->pendingAttributeOrder;
        try {
            return $this->tryBlockMatchers(new Document(), $lines, $start) !== null;
        } finally {
            $this->pendingAttributes = $savedAttributes;
            $this->pendingAttributeOrder = $savedOrder;
        }
    }

    /**
     * Is this line PAST the one block a continuation marker attached?
     *
     * PART 9 §17 L3: the marker attaches ONE block, and the boundary is that
     * block's extent. Both collectors ran instead to the next CONTAINER marker -
     * a blank line, a dedent, a sibling marker, another `+` - so whatever was
     * written under the attached block came along with it and the marker
     * attached two blocks.
     *
     * THE EXTENT IS §10's FOR A PARAGRAPH, which is the only kind that needed a
     * test added. Asked with `isBlockElementStart()` it would also end on a LIST
     * MARKER, and a list marker deliberately does not interrupt a paragraph
     * (`startsInterruptingBlock()` says so in as many words), so `+` / `para` /
     * `- item` would have split a paragraph that folds.
     *
     * AN ATTRIBUTE LINE INTERRUPTS BUT DOES NOT OPEN, so it needs its own arm:
     * it ends an open paragraph and belongs to the block BELOW it, which
     * `startsNewBlock()` does not report because that predicate answers "does a
     * block start here".
     *
     * @param string $kind
     * @param string $line
     * @param array<string> $lines
     * @param int $index
     * @param array{openParagraph: bool, inFence: bool, fenceChar: string, fenceLength: int, inDiv: bool, divFenceLength: int, absorbingFence: bool, divDepth: int, isLead: bool, inTable: bool, afterInvisible: bool, inFootnoteBody: bool, quotedTable: bool} $trailingState
     */
    protected function attachedBlockHasEnded(string $kind, string $line, array $lines, int $index, array $trailingState): bool
    {
        if ($kind === self::ATTACHED_PENDING) {
            return false;
        }

        // A CAPTION IS THE OTHER DIRECTION, AND IT IS ASKED FIRST. It ends the
        // block above it by ATTACHING to it, so it extends the attached block
        // whatever kind that block is: an image and its `^ cap` are one FIGURE,
        // a table and its `^ cap` are one table with a `<caption>`. Asked after
        // the spanning arms below, the table row's "nothing left open" answered
        // first and the caption came back as literal text.
        if ($this->isCaptionLine($line)) {
            return false;
        }
        // AN EXTENSION'S BLOCK IS LEFT ALONE ENTIRELY. Its extent is its
        // matcher's business, and nothing here can compute it, so the run ends
        // only where the collectors' own container boundaries end it - the
        // behavior every attached block had before this predicate existed.
        // Falling through to the interruption test below cut a registered
        // block at the first block-shaped line in its BODY.
        if ($kind === self::ATTACHED_SPANNING . ':extension') {
            return false;
        }

        if ($kind !== self::ATTACHED_PARAGRAPH) {
            // MORE OF THE SAME BLOCK IS NOT A SECOND BLOCK. A quote's next `>`
            // line, a table's next row and a list's next marker all read as
            // block openers, and ending the run on them cut a table between its
            // rows (corpus 88-3) and a quote between its lines (corpus 327-4).
            //
            // The construct must be NAMED. `spanningConstruct()` returns the
            // empty string for everything it does not name, so comparing
            // without this guard made a heading's tag match any unnamed line
            // and the run never ended.
            $construct = $this->spanningConstruct($line);
            if ($construct !== '' && $kind === self::ATTACHED_SPANNING . ':' . $construct) {
                return false;
            }
            // AN OPEN FENCE OR DIV IS STILL THE SAME BLOCK. Its body holds no
            // paragraph, so without this the arm below would end the run on the
            // attached block's own first body line.
            if ($trailingState['inFence'] || $trailingState['inDiv']) {
                return false;
            }
            // PAST IT WHEN IT LEFT NOTHING OPEN. A completed table, a heading
            // and a thematic break leave no paragraph, so whatever is under
            // them is a block of its own; a quote and a list DO hold one, so
            // prose lazily continues them and only an interrupting line ends
            // the run (PART 1 S4 again, one level in).
            //
            // THIS IS WHY THERE IS NO "ONE-LINE BLOCK" KIND. A heading and a
            // thematic break were tracked as one for a while and the branch
            // could be deleted with every test still green: S4 had already
            // answered for them, because a one-line block is exactly a block
            // that leaves nothing open.
            if (!$trailingState['openParagraph']) {
                return true;
            }
        }

        return $this->startsNewBlock($line, $lines, $index)
            || $this->isBlockAttributeLine($line);
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
        // A MARKER INSIDE AN OPEN FENCE IS CODE TEXT here too (§24 S2). This
        // collector tracked no block state at all, so a `- x` line in the
        // attached block's fenced body ended the block and severed the body -
        // the same defect as in collectPlainListItemContinuation(), reached by
        // a different door. It serves BOTH `+` paths, the first-block form
        // (`- +`) and the mid-item form, so the two are one fix here.
        //
        // The state is local because the attached block starts fresh at column
        // 0 below the marker: nothing the item collected above it is open.
        $trailingState = self::INITIAL_TRAILING_BLOCK_STATE;
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
                        && !$trailingState['inFence']
                        && ($this->listParser->parseListItemMarker($trimmed) !== null || $this->isContinuationMarker($trimmed)))
                ) {
                    return true;
                }
                if ($this->attachedBlockHasEnded($attachedKind, $trimmed, $lines, $index, $trailingState)) {
                    return true;
                }
                $content = IndentationHelper::stripLeadingColumns($line, $baseIndent);
                $attachedKind = $this->advanceAttachedKind($attachedKind, $pendingThrough, $trimmed, $lines, $index);
                $trailingState = $this->advanceTrailingBlockState($trailingState, $content);

                return false;
            },
            static fn (string $line): string => IndentationHelper::stripLeadingColumns($line, $baseIndent),
        );
        $attachedLineMap = array_map(fn (int $raw): int => $this->sourceLineFor($raw), $attachedRawLineMap);

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
     * @param array{openParagraph: bool, inFence: bool, fenceChar: string, fenceLength: int, inDiv: bool, divFenceLength: int, absorbingFence: bool, divDepth: int, isLead: bool, inTable: bool, afterInvisible: bool, inFootnoteBody: bool, quotedTable: bool} $trailingState
     *
     * @return array{0: int, 1: array{openParagraph: bool, inFence: bool, fenceChar: string, fenceLength: int, inDiv: bool, divFenceLength: int, absorbingFence: bool, divDepth: int, isLead: bool, inTable: bool, afterInvisible: bool, inFootnoteBody: bool, quotedTable: bool}}
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
        // A COMMENT FENCE'S BODY IS OPAQUE AT THE CONTENT COLUMN TOO (PART 9
        // §28, §24 C3, corpus category 279). The shared trailing-block tracker
        // carries no comment state, while `trackBlockQuoteLazyState()` - the
        // mirror its own docblock names - has carried it since carve-php#800.
        // One question about one construct, answered two ways depending on the
        // container: a blank line inside an item's own `%%%` body ended the
        // item, so the span leaked out as two paragraphs AND the blank loosened
        // the item that held it (markup-carve/carve#985).
        //
        // Tracked HERE rather than in `advanceTrailingBlockState()` because
        // opening the span needs a CLOSER AHEAD. An opener with none opens no
        // block (§28) and must not latch this scan - latching it would swallow
        // the rest of the document into the item - and the shared tracker
        // cannot answer that without the line set. It is the same condition
        // `commentFenceSpanEnd()` applies for the below-column spelling below,
        // so the two columns now give one answer.
        // The seeded lines are the item's lead, which ends at the line before
        // this collector's first, so `$i - 1` is the index the last of them
        // sits at - the one a marker-line `- %%%` opener needs the lookahead to
        // start from.
        $openCommentLength = null;
        foreach ($itemLines as $seedLine) {
            $openCommentLength = $this->advanceItemCommentFence($openCommentLength, $seedLine, $lines, $i - 1);
        }
        while ($i < $count) {
            $nextLine = $lines[$i];

            if (IndentationHelper::isBlankLine($nextLine)) {
                // A BLANK LINE INSIDE AN OPEN CONTAINER DOES NOT END THE ITEM.
                // The code fence has always been read that way here; the `:::`
                // div was not, so `- item` / `  ::: note` / blank / `  :::`
                // severed the div at the blank and the closer below read as a
                // fresh bare-div OPENER, publishing a spurious `<div></div>`
                // beside the aside. carve-js publishes one aside. The state
                // this asks is already tracked - `advanceTrailingBlockState()`
                // maintains `inDiv` right beside `inFence` - and only the gate
                // was short of the case.
                //
                // The blank is a COLLECTED LINE and advances the tracker like
                // any other. Inside a code fence that changes nothing -
                // `openParagraph` is already false for the whole fence - but
                // inside a div the line above the blank may well have been
                // prose, and leaving the tracker at that line's answer let a
                // flush-left line below fold into a paragraph the blank had
                // closed: `- item` / `  ::: note` / `  a` / blank / `tail` put
                // `tail` inside the aside, where it is a top-level paragraph.
                //
                // AND THE COMMENT FENCE IS THE THIRD KIND, on the same reading:
                // §28 makes its body verbatim, so the blank is that body's
                // content and neither ends the item nor loosens it.
                // AND A FOOTNOTE DEFINITION'S BODY IS THE FOURTH KIND. THE
                // BLOCK'S EXTENT IS THE DEFINITION'S, BLANK LINES AND ALL
                // (PART 1 S4, markup-carve/carve#1363): a blank inside the body
                // separates the NOTE's own blocks, so ending the item's run
                // there gave the note one block and handed `more` to the item.
                // A LINK definition has no body and never arms this, which is
                // the control corpus 359-2 is.
                if (
                    $trailingState['inFence']
                    || $trailingState['inDiv']
                    || $trailingState['inFootnoteBody']
                    || $openCommentLength !== null
                ) {
                    $itemLines[] = '';
                    $itemLineMap[] = $this->sourceLineFor($i);
                    $trailingState = $this->advanceTrailingBlockState($trailingState, '');
                    $i++;

                    continue;
                }

                break;
            }

            $nextIndent = IndentationHelper::getLeadingColumns($nextLine, max($baseIndent, $contentIndent) + 1);
            $nextTrimmed = ltrim($nextLine, " \t");

            if ($this->listContinuationEndsAtDedentedBlock($nextIndent, $nextTrimmed, $baseIndent, $lines, $i)) {
                break;
            }

            if ($this->listContinuationEndsAtBaseColumn($nextIndent, $nextTrimmed, $baseIndent, $lines, $i)) {
                break;
            }

            // NO EXCEPTION FOR AN ABSORBED FENCE. This used to break when the
            // item had collected a colon-fence line that is not a valid opener,
            // which ended the item and made the flush-left line a document
            // paragraph. PART 1 S4 says the opposite: `:::note` fails PART 9
            // §12's opener test so it is paragraph text, §12 then has the
            // paragraph absorb the bare fence below it as text too, and a
            // paragraph nothing ever interrupted is still OPEN when the
            // flush-left line arrives (carve#891, corpus
            // `86-list-lazy-continuation-9`). What decides is whether a block
            // was opened, never the shape of the line that tried - and
            // `advanceTrailingBlockState` below already answers that question
            // for every other block kind.

            if ($nextIndent >= $contentIndent) {
                // A MARKER INSIDE AN OPEN FENCE IS CODE TEXT, not a marker.
                // §24 S1 matches the item, so the innermost MATCHED container
                // is the FENCED BODY and S2 makes the line code text. This
                // test asked about the marker before it asked about the fence,
                // so `- ``` ` / `  - x` / `  ``` ` ended the item at `  - x`
                // and published a sublist beside an empty code block - while
                // the plain-text sibling at the same column
                // (`276-a-fence-opened-on-a-list-marker-line-body-below-the-
                // content-column-3`) has always been code. A marker CHARACTER
                // decided whether a verbatim body was verbatim.
                //
                // ALL THREE FENCE KINDS, not just `inFence`. The reasoning that
                // stood here - "a `:::` div body is ordinary blocks, so a
                // marker in one IS a list" - answers a different question than
                // the one this gate asks. This gate decides whether the line
                // ends the ITEM, and §24 S1/S2 place a line by the column it
                // reaches, never by its first character: a marker at the body's
                // own column is inside the open container either way. Whether
                // it then opens a list is the BODY'S question, and the div body
                // answers it exactly as the top level does - `:::` / `a` /
                // `- m` / `b` / `:::` is one paragraph there too, because a
                // marker does not interrupt an open paragraph.
                //
                // So `- x` / `  :::` / `  a` / `  - m` / `  b` / `  :::` split
                // the div in two around a nested list and published a spurious
                // empty `<div>` (corpus category 279 row 5). A COMMENT body is
                // verbatim on the same reading (§28).
                if (
                    !$trailingState['inFence']
                    && !$trailingState['inDiv']
                    && $trailingState['divDepth'] === 0
                    && $openCommentLength === null
                    && $this->listParser->parseListItemMarker($nextTrimmed) !== null
                ) {
                    break;
                }
                $contentLine = IndentationHelper::stripLeadingColumns($nextLine, $contentIndent);
                $openCommentLength = $this->advanceItemCommentFence($openCommentLength, $contentLine, $lines, $i);
                if ($this->paragraphHasUnclaimedColonFenceLine($contentLine)) {
                    $sawIndentedUnclaimedColonFence = true;
                }
                $itemLines[] = $contentLine;
                $itemLineMap[] = $this->sourceLineFor($i);
                // AT the item's content column - the line was dedented BY that
                // column to get here - so an invisible block on it ends the
                // paragraph (markup-carve/carve#1350, corpus 357-2). The lazy
                // branch below leaves the flag off, which is what keeps corpus
                // 183 and 214-2 folding a comment written BELOW the column.
                $trailingState = $this->advanceTrailingBlockState($trailingState, $contentLine, true);
                $i++;

                continue;
            }

            // AN OPEN DIV IS NOT AN OPEN PARAGRAPH. `inDiv` used to keep the
            // item collecting, so an unterminated `:::` inside an item
            // swallowed the flush-left line INTO the div - where carve-js and
            // carve-rs end the item and leave the div empty. The comment that
            // justified it cited a §10 closer lookahead that carve#439 removed,
            // and the shape was only reachable through the absorbed-fence latch
            // deleted above, which is why it surfaced with that (carve#891).
            //
            // AN OPEN FENCE IS NOT AN OPEN PARAGRAPH EITHER, and this line is
            // BELOW the item's content column. §24's STEP walk is driven by the
            // indentation the line SUPPLIES: S1 stops at the first container
            // whose prefix the line does not carry, which here is the ITEM, so
            // the fenced body is never reached and S2 FENCED BODY never fires.
            // S4 governs, and its lazy branch continues an open PARAGRAPH - a
            // verbatim body is not one, so there is nothing to fold into. Close
            // the item and let the residue re-parse outside it
            // (markup-carve/carve#950, corpus 276).
            //
            // `inFence` used to keep collecting here on the reasoning that an
            // unterminated fence runs to end of input by §28. It does - inside
            // the container that opened it. The reach of a container is not
            // extended by what its innermost block happens to be, and the BLOCK
            // QUOTE spelling of this very shape already ends at the same line in
            // every engine.
            // TWO QUESTIONS, NOT ONE. "Is a paragraph open" answers whether a
            // line can FOLD; "is the container finished" answers whether it can
            // still collect. One flag served both, so closing the paragraph for
            // an invisible block at the content column also ended the item -
            // which corpus 197 and 277 refuse (markup-carve/carve-php#1421).
            //
            // A FLUSH-LEFT line still needs an open paragraph: it is a lazy
            // continuation and there is nothing to continue (corpus 357-2,
            // 357-3). An INDENTED one does not - it reaches no content column,
            // but §24 C3 reads it as the item's own block, and after a comment
            // that block is simply the item's next one.
            if (!$trailingState['openParagraph'] && ($nextIndent === 0 || !$trailingState['afterInvisible'])) {
                break;
            }

            // A comment fence carries its body with it: pushed as its own
            // lines the block parser consumes the whole span and renders
            // nothing, and the item stays open across it exactly as it does
            // across a `%%` line.
            $commentFenceEnd = $this->commentFenceSpanEnd($nextTrimmed, $lines, $i);
            if ($commentFenceEnd !== null) {
                for ($j = $i; $j < $commentFenceEnd; $j++) {
                    $itemLines[] = ltrim($lines[$j], " \t");
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
            //
            // The open-paragraph half of this test moved UP to the gate above,
            // which now ends the item whenever no paragraph is open. Re-asking
            // it here could no longer fail, and a check that cannot fail reads
            // as a guard while guarding nothing.
            if (
                $nextIndent === 0
                && !$this->isBlockElementStart($nextTrimmed)
                && !$this->startsNewBlock($nextTrimmed)
                && $this->isDefinitionLineForEnclosingItem($nextTrimmed)
            ) {
                break;
            }

            // Reached only with a paragraph open, for the same reason.
            $foldedAsText = false;
            if (
                $itemLines !== []
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
                || ($nextIndent === 0 && $this->flushLineEndsListContinuation($nextTrimmed, $lines, $index))
            );
    }

    /**
     * Does a line at the list's own column end the item's continuation?
     *
     * The two gates below ask this at their own columns, so it is written once:
     * a second spelling of one rule is a second place for it to drift, and the
     * arm this method exists for was missing from BOTH.
     *
     * A BLOCK-ATTRIBUTE LINE ENDS IT (PART 9 §10 I5, markup-carve/carve#1028).
     * I5 lists the invisible constructs that interrupt an open paragraph and are
     * consumed - a reference definition, a comment, "and a block-attribute line
     * (`{…}` alone on a line, §15)" - and I6 applies the relation to EVERY open
     * paragraph, an item's included. Neither predicate below could see one:
     * `isBlockElementStart()` enumerates the VISIBLE openers and
     * `startsInterruptingBlock()` has no `{` arm at all, so the top level got
     * I5 right (through `paragraphInterruptedBy()`, which asks
     * `isInvisibleOrAttributeLine()` as well) and the list path did not.
     *
     * What that cost: `- item` / `{.cls}` / `> quote` kept the attribute line
     * inside the item, where it is below the content column and renders as
     * LITERAL TEXT - so the author saw `{.cls}` printed in the `<li>` and the
     * quote it was written for carried no class. PART 2's LIST-ITEM ATTRIBUTES
     * clause names that reading and REJECTS it: "The lazy-continuation accident
     * - a trailing `{…}` line folded onto a tight item, which carve-php attached
     * to the `<li>` and carve-js dropped - is REJECTED as the mechanism".
     *
     * A reference definition at the same column already ended the item here, and
     * I5 names the two kinds in one breath, so this also removes an asymmetry
     * inside this engine.
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
                if ($look >= $count || IndentationHelper::getLeadingColumns($lines[$look], $contentIndent) < $contentIndent) {
                    break;
                }
                $itemLines[] = '';
                $itemLineMap[] = $this->sourceLineFor($i);
                $trailingState = $this->advanceTrailingBlockState($trailingState, '');
                $i++;

                continue;
            }

            $nextIndent = IndentationHelper::getLeadingColumns($nextLine, max($baseIndent, $contentIndent) + 1);
            if ($nextIndent < $contentIndent) {
                $nextTrimmed = ltrim($nextLine, " \t");
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
                $termText = trim($m[1], StringUtil::WHITESPACE_CHARS);
                $termLines = [$termText];
                $i++;
                // A term folds a following plain line like a heading (soft
                // break), so a wrapped term line does not strand the definition.
                // A blank line, a new marker (`::` / `:  `), or a block opener /
                // list marker ends the term.
                while ($i < $count) {
                    $nextLine = $lines[$i];
                    if (
                        IndentationHelper::isBlankLine($nextLine)
                        || preg_match(self::DEFINITION_TERM_LINE_PREFIX, $nextLine)
                        || preg_match(self::DEFINITION_BODY_LINE_PREFIX, $nextLine)
                        || $this->endsDefinitionTerm($nextLine, $lines, $i)
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
                    // A term line is a CONTENT LINE, so the trailing-whitespace
                    // rule applies to it as it does to a paragraph's: a
                    // `whitespace` run at the end of one is dropped. The strip
                    // is on the SOURCE line, before the term reaches the inline
                    // parser, because a renderer cannot tell an authored
                    // trailing space from one a construct produced - trimming
                    // rendered output instead would eat the content of an
                    // all-space verbatim span (markup-carve/carve#926).
                    $nextLine = rtrim($nextLine, " \t");
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
                $termSource = $this->sourceLineFor($termStart);
                $term->setPos($this->wholeLinesSpan(
                    $termStart,
                    $i - 1,
                    $this->currentContentColumns[$termSource] ?? 0,
                ));
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
                if (IndentationHelper::isBlankLine($lines[$i])) {
                    $look = $i;
                    while ($look < $count && IndentationHelper::isBlankLine($lines[$look])) {
                        $look++;
                    }
                    if ($look < $count && preg_match(self::DEFINITION_BODY_LINE_PREFIX, $lines[$look])) {
                        $i = $look;
                    } else {
                        break;
                    }
                }
                if (!preg_match(self::DEFINITION_BODY_PATTERN, $lines[$i], $m)) {
                    break;
                }
                $definitionStart = $i;
                $i++;
                // First-block form (`:  +`, mirroring the list `- +`): when the
                // sole content is a lone `+`, the body is the FOLLOWING
                // flush-left block, with no indentation. `:  \+` is a literal `+`.
                $bodyMap = [];
                if (preg_match('/^\+[ \t]*$/', trim($m[1], StringUtil::WHITESPACE_CHARS))) {
                    [$i, $body, $bodyRawMap] = $this->collectAttachedBlock(
                        $lines,
                        $i,
                        $count,
                        static fn (string $a): bool => IndentationHelper::isBlankLine($a)
                            || preg_match('/^\+[ \t]*$/', $a)
                            || preg_match(self::DEFINITION_TERM_LINE_PREFIX, $a)
                            || preg_match(self::DEFINITION_BODY_LINE_PREFIX, $a),
                    );
                    $bodyMap = array_map(fn (int $raw): int => $this->sourceLineFor($raw), $bodyRawMap);
                } else {
                    $body = [trim($m[1], StringUtil::WHITESPACE_CHARS)];
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
                // Whether a FORM A line has been pushed since the last blank.
                // Past-the-column laziness is about a line following the BODY'S
                // OWN paragraph; once an indented block has been opened, the
                // lines under it belong to that block and its own indentation
                // governs them. Without this the second line of an indented list
                // or fence was folded into the first.
                $formABlockOpen = false;
                // A DEFINITION BODY IS AN INDENTED-BLOCK COLLECTOR LIKE THE
                // OTHER TWO (markup-carve/carve#956), so it owes the same answer
                // about an OPEN FENCE that a list item and a block quote already
                // give. This loop tracked no fence state at all, which is why it
                // was the last collector still folding a below-column line into
                // one. Advanced one body ENTRY at a time because `parseBlocks()`
                // reads an entry as a line - an entry that grew a `"\n"` from a
                // past-the-column append is still one line to it, so only the
                // entry's first line decides block structure and the cursor below
                // stays correct when the last entry is appended to in place.
                $bodyState = self::INITIAL_TRAILING_BLOCK_STATE;
                $bodyStateCursor = 0;
                /** @var array<int, true> $bodyLazy Body indexes collected BELOW the content column. */
                $bodyLazy = [];
                $bodyAttributeThrough = -1;
                $bodyEndsWithAttribute = false;
                while ($i < $count) {
                    $contLine = $lines[$i];
                    // Form B: `+` pull-left continuation.
                    if (preg_match('/^\+[ \t]*$/', $contLine)) {
                        $i++;
                        [$i, $attached, $attachedRawLineMap] = $this->collectAttachedBlock(
                            $lines,
                            $i,
                            $count,
                            static fn (string $a): bool => IndentationHelper::isBlankLine($a)
                                || preg_match('/^\+[ \t]*$/', $a)
                                || preg_match(self::DEFINITION_TERM_LINE_PREFIX, $a)
                                || preg_match(self::DEFINITION_BODY_LINE_PREFIX, $a),
                        );
                        $attachedLineMap = array_map(fn (int $raw): int => $this->sourceLineFor($raw), $attachedRawLineMap);
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
                    // COLUMNS, not literal spaces. This counted the leading
                    // SPACES, so a tab never continued the body and a mixed run
                    // continued it only once three spaces had appeared - one
                    // reader of five spellings, and the only one that made the
                    // answer depend on which character an editor inserted
                    // (carve-php#964).
                    $indent = IndentationHelper::getLeadingColumns($contLine, self::DEFINITION_CONTINUATION_COLUMN + 1);
                    // PAST THE COLUMN IS LAZY TEXT, NOT A NESTED BLOCK
                    // (markup-carve/carve#918). `definition_indent` REACHES the
                    // body's column and does not measure how far past it a line
                    // went, because there is nothing past that column for
                    // indentation to mean. A line indented further therefore
                    // continues the body's OPEN PARAGRAPH, and a paragraph
                    // continuation carries inline content - so
                    //
                    //     :: t
                    //     :  body
                    //         > q
                    //
                    // gives `<dd>body\n&gt; q</dd>` rather than a nested quote.
                    // The alternative reading makes indentation depth mean two
                    // different things one line apart: lazy continuation already
                    // governs the line above, folding it into the same
                    // paragraph, and a stray four-space indent would silently
                    // become a block quote.
                    //
                    // The line is APPENDED TO THE PREVIOUS BODY ENTRY rather
                    // than pushed as a new one. `parseBlocks()` reads each entry
                    // as a line, so a `>` at the front of its own entry opens a
                    // quote however the entry was indented; inside an entry it
                    // is inline content, which is what a paragraph continuation
                    // is. An entry holding newlines is the shape a list item
                    // already hands over.
                    //
                    // FORM A still works, because it goes through the blank-line
                    // branch below first: that pushes an empty entry, the test
                    // here sees it, and the next indented line opens a real
                    // block. The blank is what separates the two readings.
                    //
                    // AN OPEN PARAGRAPH, not merely a non-empty entry. If the
                    // body's own first line OPENS A BLOCK - `:  - x`, `:  ```` -
                    // then there is no open paragraph for a past-the-column line
                    // to continue, and the line belongs to that block's own
                    // reading. Testing only for non-emptiness turned a nested
                    // list into literal text.
                    //
                    // A LIST MARKER IS ASKED FOR SEPARATELY, because
                    // `startsNewBlock()` answers the INTERRUPTION question and
                    // PART 9 §10 says a bullet or ordered marker never
                    // interrupts a paragraph - so it reports false for `- x`,
                    // which does open a block when it is the body's first line.
                    $lastBodyKey = $body === [] ? null : array_key_last($body);
                    $lastBodyEntry = $lastBodyKey === null ? '' : $body[$lastBodyKey];
                    $lastBodyOpener = strtok($lastBodyEntry, "\n");
                    if (
                        !IndentationHelper::isBlankLine($contLine)
                        && $indent > self::DEFINITION_CONTINUATION_COLUMN
                        && !$formABlockOpen
                        && $lastBodyKey !== null
                        && $lastBodyEntry !== ''
                        && $lastBodyOpener !== false
                        && !$this->startsNewBlock($lastBodyOpener)
                        && $this->listParser->parseListItemMarker($lastBodyOpener) === null
                    ) {
                        $body[$lastBodyKey] .= "\n" . ltrim($contLine, " \t");
                        $i++;

                        continue;
                    }
                    // Form A: an indented continuation line (no intervening blank).
                    if (!IndentationHelper::isBlankLine($contLine) && $indent >= self::DEFINITION_CONTINUATION_COLUMN) {
                        $formABlockOpen = true;
                        $body[] = ltrim($contLine, " \t");
                        $bodyMap[] = $this->sourceLineFor($i);
                        $i++;

                        continue;
                    }
                    // Blank line: absorb as a paragraph separator ONLY when a
                    // later line still continues the definition; otherwise leave
                    // it for the entry separator / outer block stream.
                    if (IndentationHelper::isBlankLine($contLine)) {
                        $look = $i;
                        while ($look < $count && IndentationHelper::isBlankLine($lines[$look])) {
                            $look++;
                        }
                        $after = $lines[$look] ?? null;
                        // The SECOND spelling of the same rule, with a different
                        // job: this one decides whether the blank is an internal
                        // paragraph break or the end of the body. It has to read
                        // the column the same way, or a tab-indented paragraph
                        // is unreachable through a blank line while reachable
                        // without one.
                        $afterIndent = $after === null ? 0 : IndentationHelper::getLeadingColumns($after, self::DEFINITION_CONTINUATION_COLUMN);
                        if ($after !== null && !IndentationHelper::isBlankLine($after) && $afterIndent >= self::DEFINITION_CONTINUATION_COLUMN) {
                            $formABlockOpen = false;
                            for (; $i < $look; $i++) {
                                $body[] = '';
                                $bodyMap[] = $this->sourceLineFor($i);
                            }

                            continue;
                        }
                    }

                    // A new term/definition marker ends this definition (the
                    // outer loop picks it up).
                    if (preg_match(self::DEFINITION_TERM_LINE_PREFIX, $contLine) || preg_match(self::DEFINITION_BODY_LINE_PREFIX, $contLine)) {
                        break;
                    }
                    // AN OPEN FENCE IS NOT AN OPEN PARAGRAPH, so nothing folds
                    // into it (markup-carve/carve#956). §24's STEP walk is driven
                    // by the indentation a line SUPPLIES: this line supplies less
                    // than the body's content column, so S1 MATCH PREFIXES stops
                    // at the DEFINITION ENTRY, the fenced body is never reached
                    // and S2 FENCED BODY never fires. S4 governs, and its lazy
                    // branch continues an open PARAGRAPH - "fold in as lazy
                    // paragraph text" has no meaning inside content that is not
                    // markup. So the containers close, the `dd` holds an EMPTY
                    // code block, and the line re-parses at document level.
                    //
                    // THE QUESTION IS "IS A PARAGRAPH OPEN NOW", not "did the
                    // marker line open a fence". Once the body has collected a
                    // line AT the content column the fence may have closed and a
                    // paragraph reopened, and then the below-column line folds in
                    // as it always did. Asking about the open PARAGRAPH is also
                    // what makes a CLOSED fence with nothing after it end the
                    // body: a finished code block is no more an open paragraph
                    // than an unfinished one, and the list and block-quote
                    // spellings both put that line at document level. This is
                    // byte for byte the guard `collectPlainListItemContinuation()`
                    // carries for the list spelling (carve-php#1003).
                    for ($k = count($body); $bodyStateCursor < $k; $bodyStateCursor++) {
                        $bodyLine = explode("\n", $body[$bodyStateCursor], 2)[0];
                        $bodyState = $this->advanceTrailingBlockState(
                            $bodyState,
                            $bodyLine,
                            !isset($bodyLazy[$bodyStateCursor]),
                        );
                        // A WRAPPED ATTRIBUTE BLOCK LEAVES NO PARAGRAPH EITHER,
                        // and the tracker above cannot say so: it reads one line,
                        // and `{.k` is a block-attribute line only once a later
                        // line closes it. Carried INCREMENTALLY, on the same
                        // cursor the tracker walks - rescanning the whole body
                        // per collected line made a description of N lazy lines
                        // quadratic (raised by codex review).
                        if ($bodyStateCursor <= $bodyAttributeThrough) {
                            continue;
                        }
                        if (IndentationHelper::isBlankLine($bodyLine)) {
                            continue;
                        }
                        $wrapped = $this->wrappedBlockAttributeLength($body, $bodyStateCursor);
                        if ($wrapped !== null) {
                            $bodyAttributeThrough = $bodyStateCursor + $wrapped - 1;
                            $bodyEndsWithAttribute = true;

                            continue;
                        }
                        $bodyEndsWithAttribute = $this->isBlockAttributeLine($bodyLine);
                    }
                    // A WRAPPED ATTRIBUTE BLOCK LEAVES NO PARAGRAPH EITHER, and
                    // the tracker above cannot say so: it reads one line, and
                    // `{.k` is a block-attribute line only once a later line
                    // closes it. The single-line form is already answered there;
                    // this is the same rule for the form that spans lines.
                    if (!$bodyState['openParagraph'] || $bodyEndsWithAttribute) {
                        break;
                    }
                    // Lazy continuation: a FLUSH-LEFT line with no blank before
                    // it that does not start an interrupting block folds into the
                    // open paragraph (the same rule list items and block quotes
                    // use; djot-compatible). A block opener ends the definition.
                    //
                    // FLUSH-LEFT IS PART OF THE PRODUCTION, not an accident of
                    // how the indent is measured (markup-carve/carve#932).
                    // `definition_indent` states the body's content column, and
                    // BELOW that column the body ENDS and the line is classified
                    // in the surviving context - the same thing "below the content
                    // column" means for a list item and for a footnote body.
                    // Column 0 is not a special case of that, it is the ordinary
                    // case: the body ends there too, and `lazy_continuation_line`
                    // then picks the line up because that production is written
                    // for a flush-left line.
                    //
                    // One or two columns of indentation reach neither. Folding
                    // such a line in as lazy text gave a SUB-COLUMN indent the
                    // PAST-the-column band's meaning, which is the third meaning
                    // the clause refuses: it would make indentation depth mean
                    // two different things one column apart. So
                    //
                    //     :: t
                    //     :  body
                    //      > q
                    //
                    // ends the body, and `> q` is classified where it now sits -
                    // at document level, where an indented `>` is a paragraph
                    // under the strict column-0 opener rule. At column 0 the same
                    // rule opens a quote, and at column 3 the quote opens INSIDE
                    // the `dd`; both are pinned as controls beside this.
                    if (
                        $indent === 0
                        && !IndentationHelper::isBlankLine($contLine)
                        && !$this->startsInterruptingBlock($contLine, $lines, $i)
                    ) {
                        // COLLECTED BELOW THE CONTENT COLUMN, so it adds no
                        // block: the tracker must read it as the lazy line it
                        // is rather than as content at the column
                        // {@see self::advanceTrailingBlockState()}.
                        $bodyLazy[count($body)] = true;
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
                // The description is a container too, and its boundary ends the
                // pending run for the same reason a quote's does.
                $this->endContainerAttributeScope();
                $definitionSource = $this->sourceLineFor($definitionStart);
                $ddPos = $this->wholeLinesSpan(
                    $definitionStart,
                    $definitionStart,
                    $this->currentContentColumns[$definitionSource] ?? 0,
                );
                $ddChildren = $dd->getChildren();
                $lastChildPos = $ddChildren === [] ? null : $ddChildren[count($ddChildren) - 1]->getPos();
                if ($ddPos !== null && $lastChildPos !== null && $lastChildPos->endOffset > $ddPos->endOffset) {
                    $ddPos = new SourceSpan(
                        startLine: $ddPos->startLine,
                        endLine: $lastChildPos->endLine,
                        startColumn: $ddPos->startColumn,
                        endColumn: $lastChildPos->endColumn,
                        startOffset: $ddPos->startOffset,
                        endOffset: $lastChildPos->endOffset,
                    );
                }
                $dd->setPos($ddPos);
                if ($dd->getChildren() === [] && $dd->getPos() === null) {
                    // A description EMPTIED by collection still occupied a line,
                    // and §4 wants a position on every node but the root.
                    // Container spans are derived from children, so this one
                    // came out with none - and the writer then had no way to
                    // find the definition the author wrote on it, which is what
                    // made the emptied `dd` unable to round-trip (carve#805,
                    // carve-php#903).
                    $dd->setPos($this->wholeLineSpan($definitionStart));
                }
                $dl->appendChild($dd);
            }
            // The next entry may follow with NO blank line at all:
            // `definition_list = definition_entry+`, and the blank is only ever
            // a separator the grammar permits ("for readability"), never one it
            // requires. Falling through to the break below ended the list at
            // the first entry and started a second `<dl>` for the next
            // (carve#839). The outer condition re-tests the same line, and the
            // term loop above always consumes it, so this cannot spin.
            if ($i < $count && preg_match(self::DEFINITION_TERM_LINE_PREFIX, $lines[$i])) {
                continue;
            }
            // Allow a single blank line before the next entry's `:: term`.
            if ($i < $count && IndentationHelper::isBlankLine($lines[$i])) {
                $look = $i;
                while ($look < $count && IndentationHelper::isBlankLine($lines[$look])) {
                    $look++;
                }
                if ($look < $count && preg_match(self::DEFINITION_TERM_LINE_PREFIX, $lines[$look])) {
                    $i = $look;

                    continue;
                }
            }

            break;
        }

        $entries = $dl->getChildren();
        if ($entries !== []) {
            $first = $entries[0]->getPos();
            $last = $entries[count($entries) - 1]->getPos();
            if ($first !== null && $last !== null) {
                // THE LIST OWNS EVERY LINE IT CONSUMED, and its children do
                // not all show. A floating attribute is scoped to the
                // container that holds it (markup-carve/carve#1298), so the
                // attribute line is INSIDE the list - and no child covers it,
                // because it became attributes on the list itself rather than
                // a block. An extent derived from the children alone therefore
                // stopped one line short of the line it now scopes
                // (carve-php#1362); under §4 a span covers the markup its node
                // owns, and this list owns that line.
                //
                // The lines are the answer, but only the ones the list OWNS.
                // Every line it consumed was too many: the parse walks past a
                // reference definition written under the description, and that
                // line belongs to the definition node it becomes, not to the
                // list - so the extent covered markup the node does not own,
                // which is the mirror of the gap it was fixing
                // (carve-php#1362 ended one line short, carve-php#1371 ran one
                // line long).
                //
                // The START still comes from the children, because a list
                // inside an item does not begin at column 1 of its line.
                $end = $this->wholeLinesSpan($start, $this->lastDefinitionListLine($lines, $start, $i - 1)) ?? $last;
                $dl->setPos(new SourceSpan(
                    startLine: $first->startLine,
                    endLine: $end->endLine,
                    startColumn: $first->startColumn,
                    endColumn: $end->endColumn,
                    startOffset: $first->startOffset,
                    endOffset: $end->endOffset,
                ));
            }
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
                '/^\|(?:[ \t]*(?<attrs>\{.*\}))?[ \t]*$/s',
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
     * The last line of `$start .. $lastIndex` a definition list OWNS.
     *
     * The parse walks past lines the list does not hold. A floating attribute
     * written at the description's column IS the list's - it is scoped to the
     * container that holds it (markup-carve/carve#1298) and becomes attributes
     * on the list, so no child covers it and the extent has to reach it
     * (carve-php#1362). A reference definition written at COLUMN 0 under the
     * same description is not: it becomes a definition node of its own, with
     * its own span, and an extent covering it claims markup the list does not
     * own (carve-php#1371).
     *
     * The column is what separates them. A line the list owns either carries a
     * marker of its own - a `::` term, a `:` description, or the §17 L3
     * continuation marker - or is indented into the description it continues.
     * A flush-left line that is none of those has left the list, whatever the
     * parse did with it afterwards.
     *
     * The continuation marker is FLUSH-LEFT and still the list's. A list
     * written as a term, a description and then a lone `+` ends on a marker it
     * consumed, so reading that as an unrelated column-0 line walked back past
     * a line the list owns and shortened the extent by it. Asked through the one predicate that spells
     * the marker {@see self::isContinuationMarker()} rather than a second copy
     * of `/^\+[ \t]*$/`, which is the spelling carve-php#929 unified across
     * seven sites.
     *
     * @param array<string> $lines
     * @param int $start
     * @param int $lastIndex
     */
    private function lastDefinitionListLine(array $lines, int $start, int $lastIndex): int
    {
        while ($lastIndex > $start) {
            $line = $lines[$lastIndex] ?? '';
            if (
                IndentationHelper::isBlankLine($line)
                || ($line[0] ?? '') === ' '
                || ($line[0] ?? '') === "\t"
                || preg_match(self::DEFINITION_TERM_LINE_PREFIX, $line) === 1
                || preg_match(self::DEFINITION_BODY_LINE_PREFIX, $line) === 1
                || $this->isContinuationMarker($line)
            ) {
                break;
            }
            $lastIndex--;
        }

        return $lastIndex;
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
        [, , $keptOnLastLine] = $this->expandLineBlockLine($lines[$lastIndex][0], $lines[$lastIndex][1]);
        $this->stampBlockSpan(
            $paragraph,
            $this->sourceLineFor($lines[0][1]),
            $this->sourceLineFor($lines[$lastIndex][1]),
            $keptOnLastLine,
        );

        // ONE PARSE FOR THE WHOLE STANZA, and the break comes back out of it.
        //
        // Each line used to be handed to the inline parser on its own, with a
        // HardBreak appended between them unconditionally. That made the line
        // ending a hard boundary no inline construct could cross, and PART 2
        // says the opposite: an unclosed inline verbatim run "renders as a
        // `<code>` span to the end of the BLOCK". A line block is a block like
        // any other, so the run reaches its end here too - and the break the
        // run swallows produces no `<br>` at all, because it is content inside
        // the span rather than a sibling of it (markup-carve/carve#1282).
        //
        // It was never only verbatim: math, inline literal, an inline footnote
        // and emphasis all closed at the line ending for the same reason, since
        // all of them are decided by one pass over one string.
        //
        // So the stanza is joined and parsed once, and the SOFT breaks the
        // parser produces for the newlines it did not swallow are promoted to
        // hard ones afterwards - the same order the `::: \` hard-break block
        // already uses, and the reason that sibling never had this defect.
        // A COMMENT-ONLY BODY LINE IS DECIDED HERE, at the block layer, and
        // that is the whole of markup-carve/carve#1333. `comment_line` is a
        // BLOCK (PART 1's invisible blocks, PART 9 §10 I5), so PART 9 §23
        // removes it WITH the other block-layer decisions - before any inline
        // content exists. Deciding it inside the one inline pass below instead
        // let an unclosed verbatim run opened on an EARLIER line claim the
        // line under §21's verbatim exclusion and PUBLISH the comment's own
        // text, on a document whose only defect is a stray backtick above it.
        //
        // What is left behind is an EMPTY VERSE LINE, not a blank line: the
        // stanza split has already happened above, so emptying the line keeps
        // the stanza's shape rather than ending it. The line boundary survives
        // and the run carries it as a newline like any other it swallows.
        //
        // ONLY A LINE WHOSE FIRST CHARACTER IS `%` qualifies. Leading
        // whitespace is CONTENT in verse (§23), so `comment_line`'s optional
        // `[whitespace]` prefix has nothing to consume and an INDENTED `%%`
        // line stays ordinary verse text.
        //
        // The trailing comment is a different construct and is not touched:
        // `x %% secret` is `inline_comment` (PART 3, §21), which §21's third
        // bullet leaves standing inside a verbatim run - an engine may leave a
        // `%%` in a run and may never delete author bytes out of one.
        $verseComments = $this->verseCommentLines($lines);

        $texts = [];
        $segments = [];
        $endingSegments = [];
        $lineEndings = [];
        $offsetInStanza = 0;
        foreach ($lines as $index => [$line, $lineNumber]) {
            [$expanded, $runs, $kept] = $this->expandLineBlockLine($line, $lineNumber);
            if (isset($verseComments[$index])) {
                $expanded = '';
                $runs = [];
            }
            foreach ($runs as [$offsetInLine, $sourceColumn, $length, $sourceLength]) {
                $segments[] = [$offsetInStanza + $offsetInLine, $sourceColumn, $length, $lineNumber, false, $sourceLength];
            }
            $texts[] = $expanded;
            if ($index < $lastIndex) {
                // THE JOINED NEWLINE NEEDS A SEGMENT OF ITS OWN, so a break can
                // be resolved at all: no literal run reaches it, because a
                // preserved trailing gap or a dropped one-column run can sit
                // between the last mapped byte and the line ending.
                //
                // It is enough to IDENTIFY the break, not to place it - see the
                // promotion below for why the two are different here.
                $lineEndings[] = [
                    $offsetInStanza + strlen($expanded),
                    $lineNumber,
                ];
                // A FALLBACK SEGMENT, because lookup takes the FIRST segment
                // covering an offset and this one deliberately overlaps its
                // neighbours at both ends. A line ending's offset is also the
                // exclusive end of the text before it, and its end is also the
                // first offset of the line after it; the run segments own both
                // of those readings, so this one must only answer where no run
                // does - which is exactly the case it exists for, a line whose
                // ending no literal run reaches. Keeping it out of the primary
                // list is also what leaves that list TILING, and so searchable
                // rather than scanned.
                $endingSegments[] = [
                    $offsetInStanza + strlen($expanded),
                    strlen($this->sourceLines[$this->sourceLineFor($lineNumber)] ?? $line),
                    1,
                    $lineNumber,
                    true,
                    1,
                ];
            }
            // +1 for the "\n" the join inserts after this line.
            $offsetInStanza += strlen($expanded) + 1;
        }

        $this->inlineParser->parse(
            $paragraph,
            implode("\n", $texts),
            $lines[0][1],
            sourceMap: $this->lineBlockMap(array_merge($segments, $endingSegments)),
        );
        $this->convertParagraphSoftBreaksToHardBreaks($paragraph, $lineEndings);
        $this->placeVerseComments($paragraph, $verseComments);

        $lineBlock->appendChild($paragraph);
    }

    /**
     * The stanza's comment-only body lines, as `comment` nodes keyed by their
     * index in the stanza.
     *
     * @param list<array{0: string, 1: int}> $lines
     *
     * @return array<int, \MarkupCarve\Carve\Node\Block\Comment>
     */
    private function verseCommentLines(array $lines): array
    {
        $comments = [];
        foreach ($lines as $index => [$line, $lineNumber]) {
            if (!str_starts_with($line, '%%')) {
                continue;
            }

            // The same content the inline reader takes: everything after the
            // marker, less exactly one separating space or tab. Any further
            // spacing is the comment's own.
            $content = substr($line, 2);
            if ($content !== '' && ($content[0] === ' ' || $content[0] === "\t")) {
                $content = substr($content, 1);
            }
            $comment = new Comment($content);
            // The node keeps the SPAN the inline reader used to give it: its
            // own line, from the container's content column to the end. A node
            // that loses its position when the layer deciding it moves is a
            // silent PART 12 §4 regression - the surrounding text and breaks
            // still carry theirs, so nothing else would have shown it.
            $sourceLine = $this->sourceLineFor($lineNumber);
            $this->stampBlockSpan($comment, $sourceLine, $sourceLine);
            $comments[$index] = $comment;
        }

        return $comments;
    }

    /**
     * Put each removed comment back into the stanza, in document order.
     *
     * REMOVED FROM THE RENDER, NOT FROM THE TREE (PART 9 §23): the line is a
     * `comment` node like any other, so the canonical writer can emit it back
     * unchanged and at the same column. Every other target drops it, which is
     * the point of removing it.
     *
     * A comment sits after the line boundary that opens its line, and THE
     * BOUNDARY IS WHEREVER THE INLINE PARSE PUT IT. Counting only the
     * paragraph's own children found it for a stanza of plain verse and nowhere
     * else: an inline container spanning the emptied line holds the boundary
     * among its OWN children, so the walk stepped over the container in one
     * move, landed on a node that is not a break, and dropped the comment.
     *
     * ```
     * ::: |
     * *a
     * %% secret
     * c*
     * :::
     * ```
     *
     * lost the author's text entirely. Neither gate could see it: the comment
     * publishes nothing, so the HTML agrees before and after, and the writer's
     * bare `%%` re-parses to the tree the loss produced, so `parse(fmt(x))`
     * still equals `parse(x)` while the text is gone
     * (markup-carve/carve-php#1411, markup-carve/carve#1340).
     *
     * So the placement DESCENDS. The soft-to-hard break conversion deliberately
     * does not {@see self::convertParagraphSoftBreaksToHardBreaks()} - whether a
     * break at a nested boundary hardens is a separate and contested question
     * (markup-carve/carve#1351), and the comment belongs at the boundary
     * whichever way the boundary is spelled.
     *
     * Where a verbatim run SWALLOWED the boundaries the count runs past the
     * comment's line in one step, and the comment does not survive - the line it
     * opens is inside the run's content rather than between two nodes.
     *
     * @param \MarkupCarve\Carve\Node\Block\Paragraph $paragraph
     * @param array<int, \MarkupCarve\Carve\Node\Block\Comment> $comments
     */
    private function placeVerseComments(Paragraph $paragraph, array $comments): void
    {
        if ($comments === []) {
            return;
        }

        ksort($comments);
        // A SORTED LIST WITH A CURSOR, not an array consumed by key. Both
        // consumers take the lowest pending index, so a cursor answers in
        // constant time where a lookup has to walk: unsetting from the front of
        // a PHP array leaves tombstones that `array_key_first()` re-skips on
        // every call, which turned a stanza alternating runs with comment lines
        // quadratic - a regression on an input this fix has no other effect on.
        $pending = [];
        foreach ($comments as $index => $comment) {
            $pending[] = [$index, $comment];
        }

        $cursor = 0;
        $line = 0;
        // A comment on the stanza's FIRST line needs no boundary at all - the
        // stanza opens it - and that opening is the PARAGRAPH's, so it is drawn
        // here rather than inside whatever container the first line begins.
        $this->placeVerseCommentsIn($paragraph, $pending, $cursor, $line, true);
    }

    /**
     * Walk one node's children in document order, placing comments by line.
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param list<array{0: int, 1: \MarkupCarve\Carve\Node\Block\Comment}> $pending Line index and node, ascending.
     * @param int $cursor The first entry of `$pending` neither placed nor dropped.
     * @param int $line Boundaries seen so far, carried across the whole stanza.
     * @param bool $atStanzaStart Whether this call opens the stanza itself.
     */
    private function placeVerseCommentsIn(
        Node $parent,
        array &$pending,
        int &$cursor,
        int &$line,
        bool $atStanzaStart,
    ): void {
        $placed = [];
        $inserted = false;
        if ($atStanzaStart) {
            $inserted = $this->takeVerseCommentAt($placed, $pending, $cursor, $line);
        }

        foreach ($parent->getChildren() as $child) {
            // NOTHING LEFT TO PLACE ends the walk of this node. The boundary
            // count only matters while a comment is pending, and a node this
            // pass does not touch must not have its child list rebuilt.
            if (!isset($pending[$cursor])) {
                if (!$inserted) {
                    return;
                }
                $placed[] = $child;

                continue;
            }

            if ($child instanceof SoftBreak || $child instanceof HardBreak) {
                $placed[] = $child;
                $line++;
                $inserted = $this->takeVerseCommentAt($placed, $pending, $cursor, $line) || $inserted;

                continue;
            }

            $placed[] = $child;
            if ($child->hasChildren()) {
                $this->placeVerseCommentsIn($child, $pending, $cursor, $line, false);

                continue;
            }

            // A verbatim run holds the boundaries it swallowed inside its own
            // content, where they are newlines rather than nodes.
            $swallowed = $child instanceof ContentNodeInterface
                ? substr_count($child->getContent(), "\n")
                : 0;
            if ($swallowed === 0) {
                continue;
            }
            $line += $swallowed;
            $this->dropVerseCommentsThrough($child, $pending, $cursor, $line);
        }

        if ($inserted) {
            $parent->setChildren($placed);
        }
    }

    /**
     * Take the comment opened by the boundary just passed, if there is one.
     *
     * @param array<int, \MarkupCarve\Carve\Node\Node> $placed
     * @param list<array{0: int, 1: \MarkupCarve\Carve\Node\Block\Comment}> $pending
     * @param int $cursor
     * @param int $line The stanza line the boundary opens.
     */
    private function takeVerseCommentAt(array &$placed, array &$pending, int &$cursor, int $line): bool
    {
        if (!isset($pending[$cursor]) || $pending[$cursor][0] !== $line) {
            return false;
        }

        $placed[] = $pending[$cursor][1];
        $cursor++;

        return true;
    }

    /**
     * Drop every comment a run's swallowed newlines carried away.
     *
     * IT DOES NOT SURVIVE A RUN THAT ATE ITS LINE -- NORMATIVE (§23). What an
     * unclosed verbatim run carries across an emptied line is the NEWLINE, the
     * same thing it carries across every boundary it swallows, so there is no
     * boundary left in the tree for a `comment` node to sit on: the run's value
     * holds an EMPTY LINE instead. Appending the node anyway puts its span
     * before the run that contains it and after the node that follows it, which
     * PART 12 containment refuses.
     *
     * The writer's answer for that empty line is PART 11 §7c: an empty line
     * inside a verbatim run is spelled `%%`, the one spelling that empties to
     * nothing. The author's own comment TEXT is not recoverable there and is
     * not required to be - the run consumed it, and §1 is about the tree.
     *
     * The run's own value is a reassembled one either way, since the comment's
     * text came out of the middle of it, so it gives up the position no offset
     * pair could describe (PART 12 §4).
     *
     * @param \MarkupCarve\Carve\Node\Node $run
     * @param list<array{0: int, 1: \MarkupCarve\Carve\Node\Block\Comment}> $pending
     * @param int $cursor
     * @param int $line The stanza line the run's content reaches.
     */
    private function dropVerseCommentsThrough(Node $run, array &$pending, int &$cursor, int $line): void
    {
        $dropped = false;
        while (isset($pending[$cursor]) && $pending[$cursor][0] <= $line) {
            $cursor++;
            $dropped = true;
        }

        if ($dropped) {
            $run->setPos(null);
        }
    }

    /**
     * A stanza's source map: one segment per run of the expansion a segment can
     * describe.
     *
     * A preserved run of PLAIN SPACES is one of them, through a shape that
     * records both lengths. Each placeholder stands for one source column, but
     * U+E000 is three bytes in UTF-8 where the space it replaced is one, so an
     * ordinary segment - which maps N source bytes onto N built bytes - cannot
     * describe it, and the whole region used to be left out. Everything over it
     * then went unplaced, including three corpus documents another engine places
     * (carve-php#1351).
     *
     * A preserved run holding a TAB still is skipped. A tab widens to between
     * one and four placeholders depending on the column it starts at, so no
     * fixed count of source bytes stands behind its sentinels, and a node over
     * it gets no position - which PART 12 §4 rates well above a wrong one.
     *
     * @param list<array{0: int, 1: int, 2: int, 3: int, 4: bool, 5: int}> $segments
     *   Text offset, source column, byte length in the built string, line
     *   number, whether the segment answers only where no other one does, and
     *   byte length in the source - which differs from the built length exactly
     *   for a rewritten run.
     *
     * @return \MarkupCarve\Carve\Parser\SourceMap|null
     */
    private function lineBlockMap(array $segments): ?SourceMap
    {
        if (!$this->trackPositions || $segments === []) {
            return null;
        }

        $map = new SourceMap();
        $any = false;
        foreach ($segments as [$textOffset, $sourceColumn, $length, $lineNumber, $fallback, $sourceLength]) {
            $sourceLine = $this->sourceLineFor($lineNumber);
            $lineStart = $this->lineStartOffsets[$sourceLine] ?? null;
            if ($lineStart === null || $length <= 0) {
                continue;
            }
            // THE COLUMN IS MEASURED AGAINST THE LINE THE STANZA WAS HANDED,
            // which a container has already stripped its prefix from. Mapping
            // it straight from the physical line start put every span inside a
            // quoted or listed line block short by the prefix width, the check
            // that a span selects the node's own text then failed, and the
            // nodes lost their positions - visibly, and only when nested.
            $prefix = $this->currentContentColumns[$sourceLine] ?? 0;
            if ($fallback) {
                $map->addFallback(
                    $textOffset,
                    $lineStart + $prefix + $sourceColumn,
                    $length,
                    $sourceLine + 1,
                    $prefix + $sourceColumn + 1,
                );
            } elseif ($sourceLength !== $length) {
                $map->addSentinelRun(
                    $textOffset,
                    $lineStart + $prefix + $sourceColumn,
                    $sourceLength,
                    $sourceLine + 1,
                    $prefix + $sourceColumn + 1,
                );
            } else {
                $map->add(
                    $textOffset,
                    $lineStart + $prefix + $sourceColumn,
                    $length,
                    $sourceLine + 1,
                    $prefix + $sourceColumn + 1,
                );
            }
            $any = true;
        }

        return $any ? $map->withSource($this->positionSource(), $this->positionIndex) : null;
    }

    /**
     * Promote a stanza's soft breaks to hard ones, AT EVERY DEPTH.
     *
     * ONE LINE BOUNDARY PRODUCES ONE `<br>`, HOWEVER THE BOUNDARY IS SPELLED
     * (PART 9 §23, markup-carve/carve#1351). The promotion used to reach DIRECT
     * children only, on the reasoning that a break inside an emphasis run is
     * content belonging to that construct. That reading made the engine
     * contradict itself: `*a\` over `b*` put a `<br>` inside the `<strong>` and
     * `*a` over `b*` put none, so the same boundary produced a different
     * document for the backslash spelling than for the plain one.
     *
     * THE EXEMPTION IS NODE-PRESENCE, NOT DEPTH - a difference in KIND. A
     * backslash break and an unclosed verbatim run are exempt because they
     * leave NO soft break behind: the backslash already produced a hard break,
     * and the run swallowed the boundary into its own content as a newline. So
     * the rule is driven by node kind and applies wherever the node is, which
     * is what this walk now does.
     *
     * THE BREAK IS PLACED FROM ITS LINE, NOT FROM THE MAP. Resolving it through
     * the map is what IDENTIFIES which line ending survived - breaks and line
     * endings are both in document order, and a swallowed ending is simply one
     * that no break claims - but the resolved offset is not a span the break
     * can keep. A text offset at the end of a line means two things at once:
     * the exclusive end of the text before it, and the start of the newline.
     * Those are the same byte until a trailing one-column run is dropped, and
     * then they are one apart, so no single map answers both and the first
     * segment covering the offset wins. Letting the break take that answer put
     * it on the discarded space instead of the newline - a WRONG span, which
     * PART 12 §4 rates below no span at all.
     *
     * So the text keeps the map and the break is stamped from its own line,
     * which is where a line ending's extent was always measured.
     *
     * @param \MarkupCarve\Carve\Node\Block\Paragraph $paragraph
     * @param list<array{0: int, 1: int}> $lineEndings Text offset and line number, ascending.
     */
    protected function convertParagraphSoftBreaksToHardBreaks(Paragraph $paragraph, array $lineEndings = []): void
    {
        $next = 0;
        $this->hardenSoftBreaksIn($paragraph, $lineEndings, $next);
    }

    /**
     * Walk one node's children in document order, hardening the breaks.
     *
     * The line-ending cursor is carried ACROSS the whole stanza rather than per
     * node, because the breaks and the line endings are both in document order
     * and this walk visits them in it - a descent that restarted the cursor at
     * each container would hand the second container the first one's spans.
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param list<array{0: int, 1: int}> $lineEndings Text offset and line number, ascending.
     * @param int $next The first line ending no break has claimed.
     */
    private function hardenSoftBreaksIn(Node $parent, array $lineEndings, int &$next): void
    {
        $count = count($lineEndings);
        foreach ($parent->getChildren() as $index => $inline) {
            if (!$inline instanceof SoftBreak) {
                if ($inline->hasChildren()) {
                    $this->hardenSoftBreaksIn($inline, $lineEndings, $next);
                }

                continue;
            }

            $pos = $inline->getPos();
            $span = $pos;
            if ($pos !== null) {
                while ($next < $count && ($this->lineEndingStart($lineEndings[$next][1]) ?? $pos->startOffset) < $pos->startOffset) {
                    $next++;
                }
                if ($next < $count) {
                    $span = $this->endOfLineSpan($lineEndings[$next][1]);
                    $next++;
                }
            }

            $hardBreak = new HardBreak();
            $hardBreak->setPos($span);
            $parent->replaceChild($index, $hardBreak);
        }
    }

    /**
     * A line ending's start, in the unit the AST counts. Used for MATCHING a
     * break to its line, never as the break's own span.
     */
    private function lineEndingStart(int $index): ?int
    {
        $sourceLine = $this->sourceLineFor($index);
        $lineStart = $this->lineStartOffsets[$sourceLine] ?? null;
        if ($lineStart === null) {
            return null;
        }

        return $this->positionIndex?->codepointAt($lineStart + strlen($this->sourceLines[$sourceLine] ?? ''));
    }

    /**
     * Expand one line-block line, preserving significant whitespace.
     *
     * Leading indentation is always kept (even a single column). An inner or
     * trailing run of TWO OR MORE columns is a medial gap (inline alignment,
     * e.g. the caesura of Old English verse) and is kept too; a lone inner space
     * stays an ordinary, collapsible space so a long line can still wrap between
     * words. Preserved columns become the internal non-breaking-space
     * placeholder (U+E000), which each renderer converts (HTML &nbsp;, Markdown
     * U+00A0, plain space) and which never collides with a literal U+00A0 in the
     * author's text. Tabs expand to four-column stops.
     *
     * A PURE STRING TRANSFORM, and that is the fix. It used to append inline
     * NODES, which forced a separate inline parse per line - and, because a
     * preserved run also flushed, per whitespace-delimited segment WITHIN a
     * line. Every one of those parses was a fresh pass over a fresh string, so
     * no inline construct could reach past the nearest gap. Returning the
     * rewritten text instead lets the caller join the stanza and parse it once.
     *
     * The returned runs are the regions a segment can describe, each as
     * `[offset in the expanded line, column in the source line, byte length in
     * the expanded line, byte length in the source]`. The two lengths are equal
     * for a region the expansion did not rewrite: a literal character is copied
     * unchanged, and a one-column inner run is exactly one source character
     * replaced by one space. They DIFFER for a preserved run of plain spaces,
     * which becomes one three-byte sentinel per source column - a shape
     * {@see \MarkupCarve\Carve\Parser\SourceMap::addSentinelRun()} carries and
     * an ordinary segment cannot (carve-php#1351). A preserved run holding a TAB
     * is still not returned at all, because a tab widens to between one and four
     * columns and no fixed correspondence describes it.
     *
     * The third value is the byte length of the line the expansion KEPT: the
     * whole line, unless it ended in a dropped one-column whitespace run. It is
     * what a span over the line has to stop at, and it is returned from here
     * rather than re-derived at the call site because the drop rule below is
     * the only place that decides it (carve-php#1363).
     *
     * @param string $line
     * @param int $lineNo
     *
     * @return array{0: string, 1: list<array{0: int, 1: int, 2: int, 3: int}>, 2: int}
     */
    protected function expandLineBlockLine(string $line, int $lineNo): array
    {
        $length = strlen($line);
        $kept = $length;
        $offset = 0;
        $column = 0;
        $expanded = '';
        $runs = [];
        $runStartInSource = null;
        $runStartInExpanded = 0;
        $seenContent = false;

        while ($offset < $length) {
            $char = $line[$offset];
            if ($char !== ' ' && $char !== "\t") {
                if ($runStartInSource === null) {
                    $runStartInSource = $offset;
                    $runStartInExpanded = strlen($expanded);
                }
                $expanded .= $char;
                $seenContent = true;
                $column++;
                $offset++;

                continue;
            }

            $width = 0;
            $wsStart = $offset;
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
                if ($runStartInSource !== null) {
                    $runs[] = [$runStartInExpanded, $runStartInSource, $wsStart - $runStartInSource, $wsStart - $runStartInSource];
                    $runStartInSource = null;
                }
                // A run of PLAIN SPACES is one sentinel per source character,
                // so the map can carry it as a rewritten run and the nodes over
                // it keep their positions (carve-php#1351). A tab is the one
                // thing that breaks the correspondence - it widens to between
                // one and four columns depending on where it starts - so a run
                // holding one is left unmapped exactly as before, which is why
                // the tab form of this construct is unplaceable in every engine
                // rather than merely unplaced in this one.
                //
                // NO SECOND SPELLING of that rule here. A width test would pass
                // a run whose tabs happen to widen by one each, and the map
                // would then hold a segment claiming more source columns than
                // the run has - which OVERLAPS the literal run after it, leaves
                // the segment list non-tiling, and costs every other node in the
                // stanza its position. `SourceMap::addSentinelRun()` asks the
                // source the same question when it verifies a span, so the rule
                // is written once and checked once.
                if (!str_contains(substr($line, $wsStart, $offset - $wsStart), "\t")) {
                    $runs[] = [strlen($expanded), $wsStart, $width * strlen(SourceMap::INDENT_SENTINEL), $width];
                }
                $expanded .= str_repeat(SourceMap::INDENT_SENTINEL, $width);

                continue;
            }

            // A ONE-COLUMN run at the END of the line is TRAILING WHITESPACE and
            // is dropped like anywhere else (PART 2, markup-carve/carve#926).
            // The order is what makes this reachable: §23 converts an inner or
            // trailing run of TWO OR MORE columns into NBSP CONTENT above, and
            // content is not whitespace - so the rule never reaches that run.
            // What is left here is §23's one-column case, and at the end of a
            // line it is the only kind of whitespace still standing.
            if ($offset >= $length) {
                // The open run ends where the DROPPED whitespace begins, not
                // where the line does. Carrying it to the line end left the run
                // one byte longer than the text it describes, and since lookup
                // takes the first segment covering an offset, the line ending's
                // own segment was shadowed: the break landed on the discarded
                // space instead of the newline. A wrong span, which §4 rates
                // below no span at all.
                if ($runStartInSource !== null) {
                    $runs[] = [$runStartInExpanded, $runStartInSource, $wsStart - $runStartInSource, $wsStart - $runStartInSource];
                    $runStartInSource = null;
                }
                // The line KEEPS nothing past here, so neither may a span over
                // it. A paragraph stamped with whole-line geometry covered this
                // discarded space, and §4 has a span end immediately after the
                // last codepoint the construct owns (carve-php#1363).
                $kept = $wsStart;

                break;
            }

            // One source character, one space: the run stays mappable, so it
            // continues whatever literal run is already open rather than
            // breaking it.
            if ($runStartInSource === null) {
                $runStartInSource = $wsStart;
                $runStartInExpanded = strlen($expanded);
            }
            $expanded .= ' ';
        }

        if ($runStartInSource !== null) {
            $runs[] = [$runStartInExpanded, $runStartInSource, $offset - $runStartInSource, $offset - $runStartInSource];
        }

        return [$expanded, $runs, $kept];
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
    public const TABLE_ALIGNMENT_MARKERS = [
        '>' => TableCell::ALIGN_RIGHT,
        '<' => TableCell::ALIGN_LEFT,
        '~' => TableCell::ALIGN_CENTER,
    ];

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
        // The run's WIDTH is measured once, in the table parser, because
        // `parseTableCellsWithAttributes()` needs the same measurement to find
        // the attribute block that binds after it (PART 9 §5 T10). Only the
        // meaning is read here.
        $run = $this->tableParser->cellMarkerRunLength($raw);
        $prefix = substr($raw, 0, $run);
        // A leading `=` glued to the pipe marks a header cell and is stripped;
        // the remaining content is parsed inline. This holds even when the next
        // char is also `=` (`|==|` -> <th>=</th>, `|==x==|` -> header cell whose
        // content `=x==` renders <mark>x</mark>=), matching carve-js / carve-rs.
        // A SPACED `| ==x== |` is not a header cell: the leading space means
        // index 0 is not `=`, so it is left untouched here.
        $header = str_starts_with($prefix, '=');
        $align = self::TABLE_ALIGNMENT_MARKERS[substr($prefix, $header ? 1 : 0)] ?? null;

        return ['header' => $header, 'align' => $align, 'content' => substr($raw, $run)];
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
                                // The delimiter row is where a GFM table
                                // DECLARES its column alignment, so a header
                                // cell promoted here carries alignment of its
                                // own - the same shape carve-rs publishes for
                                // `|:---|---:|`, and what keeps the markers in
                                // the written form after a ProseMirror trip.
                                $headerCell = new TableCell(
                                    true,
                                    $alignment,
                                    $cell->getRowspan(),
                                    $cell->getColspan(),
                                    $cell->getSpanMarker(),
                                    isset($alignments[$cellIndex]),
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
            $cellMarkers = array_map(fn ($c) => $c['marker'], $cellsWithAttrs);
            $cellSourceChunks = [];
            foreach ($cellsWithAttrs as $idx => $cell) {
                $cellSourceChunks[$idx] = $this->tableCellSourceChunks($i, $cell);
            }
            $baseLineForRow = $i;

            $i++;

            // Check for continuation rows (lines starting with +)
            while ($i < $count && $this->tableParser->isContinuationRow($lines[$i])) {
                // THE ROW ABOVE DECIDES WHERE THIS ROW'S CELLS ARE. A verbatim
                // run left open in cell k reaches ACROSS the row boundary
                // (PART 9 §19 - the run ends at its closing delimiter, and a
                // row boundary is not one), so a `|` inside it is content and
                // not a cell delimiter. Split without that state, `| a `b |`
                // followed by `+ c | d` |` broke one cell into two.
                $openRuns = $this->openVerbatimRunsByCell($mergedCells);
                $continuationCells = $this->tableParser->parseContinuationCells($lines[$i], $openRuns);
                foreach ($this->continuationCellSourceChunks($i, $lines[$i], $openRuns) as $idx => $chunks) {
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
                    'marker' => $cellMarkers[$idx] ?? '',
                    'offset' => $original === null ? null : $original['offset'],
                    // Carried alongside `offset` everywhere a cell array is
                    // rebuilt: it is the one `rawLength` measures from.
                    'cellOffset' => $original === null ? null : $original['cellOffset'],
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
                // An empty span cell (a `<`/`^` that became its own slot) is
                // never a `|=` header cell. A cell carrying an attribute block
                // now can be: PART 9 §5 T10 puts the block AFTER the marker
                // run, so `|={.total} Total |` is a header cell and the row it
                // sits in is a Carve all-header row. The marker the split
                // already stripped is what decides it.
                if (
                    $cellData['isEmpty']
                    || ($cellData['attributes'] !== ''
                        ? !str_starts_with($cellData['marker'], '=')
                        : preg_match('/^=([^=]|$)/', $content) !== 1)
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
            $rowStartSpan = $this->tableLineSpan($baseLineForRow);
            $rowEndSpan = $this->wholeLineSpan($i - 1);
            if ($rowStartSpan !== null && $rowEndSpan !== null) {
                $row->setPos(new SourceSpan(
                    startLine: $rowStartSpan->startLine,
                    endLine: $rowEndSpan->endLine,
                    startColumn: $rowStartSpan->startColumn,
                    endColumn: $rowEndSpan->endColumn,
                    startOffset: $rowStartSpan->startOffset,
                    endOffset: $rowEndSpan->endOffset,
                ));
            }
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
                    // The alignment here is the COLUMN's, taken so the empty
                    // cell lines up; the cell carries no marker of its own, so
                    // it is not explicit.
                    $cell = new TableCell($isHeaderRow, $alignment, 1, $colspan, null, false);
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
                // separator row is the final fallback.
                //
                // A cell carrying a `{...}` attribute block reads its markers
                // from the run the split already took off the FRONT of the
                // block (PART 9 §5 T10), never from what follows it: everything
                // after the block is content, which is what keeps the `<` in
                // `|{#x}< content |` literal and the `=` in `|{#x}=R|` text.
                $attributed = $cellData['attributes'] !== '';
                $marker = $this->parseTableCellMarker($attributed ? $cellData['marker'] : $cellData['content']);
                if ($attributed) {
                    $marker['content'] = $cellData['content'];
                }
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
                // The cell's OWN alignment, which is what the writers and the
                // ProseMirror bridge may spell: its marker, or - on a header
                // cell - the GFM delimiter row, which is where `|:---|---:|`
                // declares the column. A body cell that merely INHERITS the
                // column's alignment carries none of its own, which is the
                // shape carve-rs publishes.
                $explicitAlign = $marker['align'] !== null
                    || (($isHeaderRow || $marker['header']) && ($alignments[$col] ?? null) !== null);
                $cell = new TableCell(
                    $isHeaderRow || $marker['header'],
                    $alignment,
                    1,
                    $colspan,
                    null,
                    $explicitAlign,
                );
                if ($cellData['attributes'] !== '') {
                    // Apply in source order (matching inline attributes and
                    // carve-js), not via setAttributes() which reorders.
                    AttributeParser::applyToNode($cell, $cellData['attributes']);
                }
                $trimmedContent = trim($marker['content'], ' ');
                $cellMap = $this->cellSourceMap($baseLineForRow, $cellData, $trimmedContent)
                    ?? $this->rebuiltCellSourceMap($cellData, $trimmedContent);
                $cellSpan = $this->cellExtentSpan($baseLineForRow, $cellData);
                if (count($cellData['sourceChunks']) > 1) {
                    $cellSpan = null;
                    $this->unplaceableNodeIds[spl_object_id($cell)] = true;
                }
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
        $rows = $table->getChildren();
        if ($rows !== []) {
            $first = $this->tableLineSpan($start);
            $last = $this->wholeLineSpan(max($start, $i - 1));
            if ($first !== null && $last !== null) {
                $table->setPos(new SourceSpan(
                    startLine: $first->startLine,
                    endLine: $last->endLine,
                    startColumn: $first->startColumn,
                    endColumn: $last->endColumn,
                    startOffset: $first->startOffset,
                    endOffset: $last->endOffset,
                ));
            }
        }
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
     * @param array<int, array{content: string, attributes: string, marker: string, offset: int|null, cellOffset?: int|null, verbatim: bool, rawLength: int|null, raw: string|null, sourceChunks?: list<array{int, int, string}>}> $mergedCellsWithAttrs
     * @param array<int, \MarkupCarve\Carve\Node\Block\TableCell> $columnOrigin Per-column open
     *   origin cell carried down from earlier rows.
     *
     * @return array{cells: array<array{content: string, attributes: string, marker: string, colspan: int<1, max>, gridColumn: int, isEmpty: bool, spanMarker: string|null, offset: int|null, cellOffset?: int|null, rawLength: int|null, raw: string|null, verbatim: bool, sourceChunks: list<array{int, int, string}>}>, consumedRowspanColumns: array<int>, consumedColspanColumns: array<int>}
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
                'marker' => $isEmpty ? '' : $cellData['marker'],
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
                'cellOffset' => $cellData['cellOffset'] ?? $cellData['offset'],
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
        if (!preg_match(self::FOOTNOTE_DEFINITION_PATTERN, $line)) {
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
                    && (IndentationHelper::getLeadingColumns($lines[$i + 1], self::FOOTNOTE_BODY_COLUMN) >= self::FOOTNOTE_BODY_COLUMN
                        || preg_match('/^\+[ \t]*$/', $lines[$i + 1]))
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
                [$i] = $this->collectAttachedBlock(
                    $lines,
                    $i,
                    $count,
                    static fn (string $a): bool => IndentationHelper::isBlankLine($a)
                        || preg_match('/^\+[ \t]*$/', $a)
                        || preg_match('/^\[\^[^\]]+\]:/', $a),
                );

                continue;
            }
            if (IndentationHelper::getLeadingColumns($nextLine, self::FOOTNOTE_BODY_COLUMN) >= self::FOOTNOTE_BODY_COLUMN) {
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
        if ($this->referenceDefinitionExtractor->matchDefinitionLine($line) === null) {
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

        // The line is KEPT as a node rather than merely skipped. It renders
        // nothing on HTML and is emitted as written on the non-HTML targets
        // (PART 11 §10a), and those renderers walk `children` - so a definition
        // that leaves no node cannot be put back where the author wrote it. The
        // expansions are collected separately by extractAbbreviations(); this
        // carries the AUTHORED line (markup-carve/carve-php#708).
        if (preg_match(self::ABBREVIATION_DEFINITION_PATTERN, $line, $m) === 1) {
            $node = new AbbreviationDefinition($m[1], rtrim($m[2], " \t"));
            $parent->appendChild($node);
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
     */
    protected function tryParseParagraph(Node $parent, array $lines, int $start, bool $topLevel = false): int
    {
        $line = $lines[$start];
        // Strip leading whitespace from first line (matching JS reference)
        $content = ltrim($line, " \t");
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
        $indent += $this->currentContentColumns[$firstSourceLine] ?? 0;
        $sourceTail = rtrim($this->sourceLines[$firstSourceLine] ?? '', " \t");
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
            if ($this->interruptsParagraph($lines, $i, $contentParts, $start, $hasUnclaimedColonFenceLine, $topLevel)) {
                break;
            }

            // Strip leading whitespace from continuation lines (matching JS reference)
            $rawNextLine = $nextLine;
            $nextLine = ltrim($nextLine, " \t");
            $this->appendParagraphContentLines(
                $contentLines,
                $this->sourceLineFor($i),
                strlen($rawNextLine) - strlen($nextLine)
                    + ($this->currentContentColumns[$this->sourceLineFor($i)] ?? 0),
                $nextLine,
            );
            $contentParts[] = $nextLine;
            $hasUnclaimedColonFenceLine = $hasUnclaimedColonFenceLine
                || $this->paragraphHasUnclaimedColonFenceLine($nextLine);
            $i++;
        }

        $content = implode("\n", $contentParts);

        // TRAILING WHITESPACE (NORMATIVE, grammar PART 2 NO TRAILING WHITESPACE;
        // pinned by corpus 102 and 268). A `whitespace` run at the end of a
        // CONTENT LINE is DROPPED. It is applied here, to the SOURCE, rather
        // than to rendered output: a renderer cannot tell authored trailing
        // whitespace from spaces a construct legitimately produced, so trimming
        // the output ate the content of an all-space inline literal
        // (`` !`  ` `` alone rendered `<p></p>` instead of `<p>  </p>`).
        //
        // EVERY LINE, not just the paragraph's last. The rule was written down
        // for the final line and implemented as a single `rtrim($content)`,
        // which by construction could not reach an interior line - so
        // `abc<SP>` + newline + `def` and `abc` + newline + `def` were
        // different documents. They are the same document
        // (markup-carve/carve#926), and PART 12 §7 asserted the opposite until
        // it was corrected. This loop is the whole of the fix for a paragraph, a
        // list item, a block quote line and a footnote body line, because all
        // four fold through here.
        //
        // Space and tab ONLY. `whitespace = ' ' | '\t'` (PART 1,
        // markup-carve/carve#890) and every other invisible character is
        // content: an implementation that strips with a Unicode whitespace
        // property, or a language's legacy `\s`, fails seven of the nine rows
        // corpus 268-7 pins, and a plain-space fixture cannot see it.
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
     * @param int|null $openingColumn
     */
    private function spanForLineMap(array $lineMap, ?int $openingColumn = null): ?SourceSpan
    {
        if (!$this->trackPositions || $lineMap === []) {
            return null;
        }

        $firstKey = array_key_first($lineMap);
        $first = $lineMap[$firstKey];
        $remaining = count($lineMap);
        while ($remaining > 1) {
            $lastKey = array_key_last($lineMap);
            if ($lastKey === null) {
                return null;
            }
            $candidate = $lineMap[$lastKey];
            if (!IndentationHelper::isBlankLine($this->sourceLines[$candidate] ?? '')) {
                break;
            }
            array_pop($lineMap);
            $remaining--;
        }
        $lastKey = array_key_last($lineMap);
        if ($lastKey === null) {
            return null;
        }
        $last = $lineMap[$lastKey];
        $start = $this->lineStartOffsets[$first] ?? null;
        $lastStart = $this->lineStartOffsets[$last] ?? null;
        if ($start === null || $lastStart === null) {
            return null;
        }

        $lastLength = strlen($this->sourceLines[$last] ?? '');
        // PART 12 §4, as in `stampBlockSpan`: an item nested in a container
        // begins at its own marker, not at the container prefix (carve#913).
        $opening = $start + ($openingColumn ?? ($this->currentContentColumns[$first] ?? 0));

        return $this->positionIndex?->span(
            min($opening, $lastStart + $lastLength),
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

        return $any ? $map->withSource($this->positionSource(), $this->positionIndex) : null;
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
        if (isset($this->unplaceableNodeIds[spl_object_id($node)])) {
            $node->setPos(null);

            return null;
        }
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
        $allChildrenPlaced = count($node->getChildren()) === count(array_filter(
            $node->getChildren(),
            static fn (Node $child): bool => $child->getPos() !== null,
        ));
        if ($node instanceof Paragraph && $allChildrenPlaced && $first !== null && $last !== null) {
            $exact = new SourceSpan(
                startLine: $first->startLine,
                endLine: $last->endLine,
                startColumn: $first->startColumn,
                endColumn: $last->endColumn,
                startOffset: $first->startOffset,
                endOffset: $last->endOffset,
            );
            $node->setPos($exact);

            return $exact;
        }
        if (($node instanceof ListItem || $node instanceof DefinitionDescription) && $own !== null && $last !== null) {
            $own = new SourceSpan(
                startLine: $own->startLine,
                endLine: $last->endLine,
                startColumn: $own->startColumn,
                endColumn: $last->endColumn,
                startOffset: $own->startOffset,
                endOffset: $last->endOffset,
            );
            $node->setPos($own);
        }
        if (
            $node instanceof ListItem
            && $own !== null
            && $node->getParent() instanceof ListBlock
            && $node->getParent()->getParent() instanceof Document
        ) {
            $lineStart = $this->lineStartOffsets[$own->startLine - 1] ?? null;
            if ($lineStart !== null) {
                $own = new SourceSpan(
                    startLine: $own->startLine,
                    endLine: $own->endLine,
                    startColumn: 1,
                    endColumn: $own->endColumn,
                    startOffset: $this->positionIndex?->codepointAt($lineStart) ?? $own->startOffset,
                    endOffset: $own->endOffset,
                );
                $node->setPos($own);
            }
        }
        if ($own !== null && $node instanceof ListBlock) {
            $lineIndex = $own->endLine - 1;
            $continuation = $this->sourceLines[$lineIndex + 2] ?? '';
            if (
                ($this->sourceLines[$lineIndex + 1] ?? null) === ''
                && array_key_exists($lineIndex + 2, $this->sourceLines)
                && $own->endColumn === mb_strlen($this->sourceLines[$lineIndex] ?? '', 'UTF-8') + 1
                && strlen($continuation) - strlen(ltrim($continuation, " \t")) >= $own->startColumn - 1
            ) {
                $nextByte = $this->lineStartOffsets[$lineIndex + 1] ?? null;
                if ($nextByte !== null) {
                    $own = new SourceSpan(
                        startLine: $own->startLine,
                        endLine: $own->endLine + 1,
                        startColumn: $own->startColumn,
                        endColumn: 1,
                        startOffset: $own->startOffset,
                        endOffset: $this->positionIndex?->codepointAt($nextByte) ?? $own->endOffset,
                    );
                    $node->setPos($own);
                }
            }
        }
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
            // A measured container's opener is authoritative. A child cannot
            // own bytes before its parent; an earlier mapped child is a prefix
            // from the re-indented parsing stream, not source owned by it.
            $first = $own;
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
        // A blank line closes a list after the terminator of its last content
        // line. The terminator is owned by the list; the blank line is not.
        if ($node instanceof ListBlock) {
            $lineIndex = $derived->endLine - 1;
            $continuation = $this->sourceLines[$lineIndex + 2] ?? '';
            if (
                ($this->sourceLines[$lineIndex + 1] ?? null) === ''
                && array_key_exists($lineIndex + 2, $this->sourceLines)
                && $derived->endColumn === mb_strlen($this->sourceLines[$lineIndex] ?? '', 'UTF-8') + 1
                && strlen($continuation) - strlen(ltrim($continuation, " \t")) >= $derived->startColumn - 1
            ) {
                $nextByte = $this->lineStartOffsets[$lineIndex + 1] ?? null;
                if ($nextByte !== null) {
                    $derived = new SourceSpan(
                        startLine: $derived->startLine,
                        endLine: $derived->endLine + 1,
                        startColumn: $derived->startColumn,
                        endColumn: 1,
                        startOffset: $derived->startOffset,
                        endOffset: $this->positionIndex?->codepointAt($nextByte) ?? $derived->endOffset,
                    );
                }
            }
        }
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

        $next = $this->lineStartOffsets[$sourceLine + 1] ?? ($end + 1);

        return $this->positionIndex?->span(
            $end,
            $next,
            $sourceLine + 1,
            $sourceLine + 2,
            $start,
            $next,
        );
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

    /**
     * Record where a footnote definition was WRITTEN, for the one case its body
     * cannot answer.
     *
     * PART 12 §4 puts a span's start at the markup that opens the construct -
     * for a definition, the `[` of `[^label]:` and not the container prefix that
     * carried the line. The prefix is measured by taking the stripped line off
     * the end of the raw one rather than by re-deriving a column, so a tab or a
     * quote marker is counted here exactly as the strip that produced `$bare`
     * counted it.
     *
     * FIRST definition of a label wins, matching `$this->footnotes`.
     *
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
        if (!$this->trackPositions || isset($this->footnoteDefinitionSpans[$label])) {
            return;
        }
        // Every caller strips from the FRONT, so this holds; a caller that ever
        // stopped doing so would record nothing rather than a wrong column,
        // which is the answer §4 asks for.
        if (!str_ends_with($raw, $bare)) {
            return;
        }
        $start = $this->lineStartOffsets[$index] ?? null;
        if ($start === null) {
            return;
        }

        $span = $this->positionIndex?->span(
            $start + strlen($raw) - strlen($bare),
            $start + strlen($raw),
            $index + 1,
            $index + 1,
            $start,
            $start,
        );
        if ($span !== null) {
            $this->footnoteDefinitionSpans[$label] = $span;
        }
    }

    private function extendFootnoteDefinitionToLineStart(string $label, int $lineIndex): void
    {
        $span = $this->footnoteDefinitionSpans[$label] ?? null;
        $endByte = $this->lineStartOffsets[$lineIndex] ?? null;
        if (
            $span === null
            || $endByte === null
            || $lineIndex >= count($this->sourceLines) - 1
            || !IndentationHelper::isBlankLine($this->sourceLines[$lineIndex] ?? '')
        ) {
            return;
        }
        $this->footnoteDefinitionSpans[$label] = new SourceSpan(
            startLine: $span->startLine,
            endLine: $lineIndex + 1,
            startColumn: $span->startColumn,
            endColumn: 1,
            startOffset: $span->startOffset,
            endOffset: $this->positionIndex?->codepointAt($endByte) ?? $span->endOffset,
        );
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

    private function wholeLinesSpan(int $firstIndex, int $lastIndex, int $openingColumn = 0): ?SourceSpan
    {
        if (!$this->trackPositions) {
            return null;
        }
        while (
            $lastIndex > $firstIndex
            && IndentationHelper::isBlankLine($this->sourceLines[$this->sourceLineFor($lastIndex)] ?? '')
        ) {
            $lastIndex--;
        }
        $firstLine = $this->sourceLineFor($firstIndex);
        $lastLine = $this->sourceLineFor($lastIndex);
        $start = $this->lineStartOffsets[$firstLine] ?? null;
        $lastStart = $this->lineStartOffsets[$lastLine] ?? null;
        if ($start === null || $lastStart === null) {
            return null;
        }
        $end = $lastStart + strlen($this->sourceLines[$lastLine] ?? '');

        return $this->positionIndex?->span(
            min($start + $openingColumn, $end),
            $end,
            $firstLine + 1,
            $lastLine + 1,
            $start,
            $lastStart,
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
     * @param array{content: string, attributes: string, offset?: int|null, cellOffset?: int|null, verbatim?: bool, rawLength?: int|null, raw?: string|null} $cellData
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
        if (substr($this->positionSource(), $start, strlen($content)) !== $content) {
            $inSourceLine = strpos($this->sourceLines[$sourceLine] ?? '', $content);
            if ($inSourceLine === false) {
                return null;
            }
            $start = $lineStart + $inSourceLine;
            if (substr($this->positionSource(), $start, strlen($content)) !== $content) {
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
     * @param int $index
     * @param array{content: string, attributes: string, offset?: int|null, cellOffset?: int|null, verbatim?: bool, rawLength?: int|null, raw?: string|null} $cellData
     */
    private function cellExtentSpan(int $index, array $cellData): ?SourceSpan
    {
        if (!$this->trackPositions) {
            return null;
        }

        // The CELL's own offset: `offset` is advanced past an attribute block so
        // the cell's TEXT can be placed where it was written, while `rawLength`
        // still measures the whole cell from its start. Adding one to the other
        // slid the span right by the block's width (carve-php#889).
        $offset = $cellData['cellOffset'] ?? $cellData['offset'] ?? null;
        $rawLength = $cellData['rawLength'] ?? null;
        if ($offset === null || $rawLength === null) {
            return null;
        }

        $sourceLine = $this->sourceLineFor($index);
        $lineStart = $this->lineStartOffsets[$sourceLine] ?? null;
        if ($lineStart === null) {
            return null;
        }

        $prefix = strpos($this->sourceLines[$sourceLine] ?? '', '|');
        $prefix = $prefix === false ? 0 : $prefix;

        return $this->positionIndex?->span(
            $lineStart + $prefix + $offset,
            $lineStart + $prefix + $offset + $rawLength,
            $sourceLine + 1,
            $sourceLine + 1,
            $lineStart,
            $lineStart,
        );
    }

    private function tableLineSpan(int $index): ?SourceSpan
    {
        if (!$this->trackPositions) {
            return null;
        }
        $sourceLine = $this->sourceLineFor($index);
        $lineStart = $this->lineStartOffsets[$sourceLine] ?? null;
        if ($lineStart === null) {
            return null;
        }
        $line = $this->sourceLines[$sourceLine] ?? '';
        $prefix = strpos($line, '|');
        if ($prefix === false) {
            return null;
        }
        $end = $lineStart + strlen($line);

        return $this->positionIndex?->span(
            $lineStart + $prefix,
            $end,
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
        if (substr($this->positionSource(), $start, strlen($content)) !== $content) {
            $sourceColumn = strpos($this->sourceLines[$sourceLine] ?? '', $content);
            if ($sourceColumn === false) {
                return null;
            }
            $start = $lineStart + $sourceColumn;
            if (substr($this->positionSource(), $start, strlen($content)) !== $content) {
                return null;
            }
        }

        return SourceMap::contiguous($start, strlen($content), $sourceLine + 1, $start - $lineStart + 1)
            ->withSource($this->positionSource(), $this->positionIndex);
    }

    /**
     * Which cells of the row so far leave a verbatim run OPEN, and how wide.
     *
     * Keyed by cell index because the run belongs to the cell it was written
     * in: `| x | a `b |` reopens at cell 1, and cell 0 of the continuation row
     * splits as usual. The width matters because only a run of the SAME length
     * closes it.
     *
     * @param array<int, string> $cells Merged content of the row so far.
     *
     * @return array<int, int> Cell index => open delimiter width.
     */
    private function openVerbatimRunsByCell(array $cells): array
    {
        $open = [];
        foreach ($cells as $index => $content) {
            $width = $this->tableParser->openCodeSpanDelimiter($content);
            if ($width > 0) {
                $open[$index] = $width;
            }
        }

        return $open;
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
        $content = trim($cellData['content'], ' ');
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
     * @param int $index
     * @param string $line
     * @param array<int, int> $openDelimiters Verbatim run width left open by the row above, by cell index.
     *
     * @return array<int, list<array{int, int, string}>>
     */
    private function continuationCellSourceChunks(int $index, string $line, array $openDelimiters = []): array
    {
        $trimmed = ltrim($line, " \t");
        $prefix = strlen($line) - strlen($trimmed);
        $normalizedLine = '|' . substr($trimmed, 1);
        $chunks = [];

        // SPLIT THE SAME WAY THE CONTENT WAS. This walk exists to say WHERE
        // each cell's text came from, so a division that differs from the one
        // that produced the text describes a row that was never built: with the
        // inherited run dropped here, a pipe inside it split a chunk onto a
        // cell index that does not exist, `rebuiltCellSourceMap()`'s
        // joined-content check then failed, and the nodes came back with no
        // position at all. Raised by codex review.
        foreach ($this->tableParser->splitCells($normalizedLine, $openDelimiters) as $idx => $cell) {
            $content = trim($cell['content'], ' ');
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

        // JOINED FROM CHUNKS, so a span across a join is refused rather than
        // published: the spaces between chunks are parser-consumed, and the
        // markup they stand for - the row's closing `|`, the continuation
        // marker - belongs to no node here (carve-php#1361). Marked on the map
        // rather than tested by geometry, because a gap alone does not mean
        // reassembly: a stripped indent leaves one too, and reading that as
        // reassembly dropped honest fence extents (carve-php#1369).
        return $any
            ? $map->withSource($this->positionSource(), $this->positionIndex)->joinedFromChunks()
            : null;
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
            ->withSource($this->positionSource(), $this->positionIndex);
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

        if (preg_match('/^\^ +.*' . StringUtil::NON_WHITESPACE_CLASS . '/', $line)) {
            return $this->isCaptionableParagraphContent(implode("\n", $contentLines), $sourceLine);
        }

        if ($this->isBareColonFence($line) && $hasUnclaimedColonFenceLine) {
            return false;
        }

        if ($this->startsNewBlock($line, $lines, $i)) {
            return true;
        }

        // A WRAPPED ATTRIBUTE BLOCK INTERRUPTS TOO. §15 does not distinguish an
        // attribute block written on one line from one broken across several -
        // `attr_separator` admits a line break between attributes - so `{.k` +
        // `#x}` floats forward exactly as `{.k #x}` does. The predicate below
        // reads ONE line, and `{.k` is not a block-attribute line on its own,
        // so the whole wrapped form folded into the paragraph as literal text
        // and rendered its own source (`{.k` and `#x}` both visible, the second
        // as an id-shaped inline). It is the same question the attached-run
        // classifier asks, so it is asked through the same helper.
        // NOT GATED ON `$topLevel`, and that is a deliberate divergence from
        // carve-rs on a shape the corpus does not pin. §15 carves out no
        // container: a wrapped attribute block is one wherever it is written,
        // and this engine now reads it that way at the top level, inside a
        // quote and inside a definition description - the last of which corpus
        // 329-6 pins. carve-rs agrees on all three and then keeps
        // `- q` / `  {.k` / `  #x}` as LITERAL TEXT inside a list item, where
        // it reads the SINGLE-line form as an attribute in the same position.
        // That is the inconsistent answer of the four, so it is not copied.
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
        //
        // ANCHORED, THROUGH THE COLLECTOR. This predicate is the INTERRUPTION
        // side of the rule, and its docblock above already says it has to accept
        // exactly what the definition parser accepts. While the parser's pattern
        // ended in a swallow-everything tail, an open-coded copy here got the
        // right answer by accident, because `[a]: /u {.c}` matched it raw. With
        // the line anchored (markup-carve/carve#911) it cannot: a copy would
        // have to split the trailing attribute block off before testing, and
        // `[a]: /u {.c}` would stop interrupting a paragraph. So the copy is
        // gone and the collector answers.
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
            return UnresolvedReference::sourceOf($children[0]) === null;
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
        return preg_match('/^[.#:a-zA-Z]/', $attrStr) === 1
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

        // TRAILING WHITESPACE (NORMATIVE, grammar PART 2 NO TRAILING
        // WHITESPACE; pinned by corpus 268). A caption line is a CONTENT LINE,
        // so a `whitespace` run at its end is dropped - the same rule the
        // paragraph collector applies a few thousand lines up, and for the same
        // reason: it belongs to the SOURCE, because a renderer cannot tell an
        // authored trailing space from one a construct legitimately produced.
        //
        // The caption path had no spelling of the rule at all. HTML looked
        // right only because HtmlRenderer trimmed its own output, which is the
        // very substitution the paragraph note warns against: it also ate the
        // content of an all-space inline literal, so `^ x !` + backtick-space-
        // space-backtick published `<caption>x</caption>` where the identical
        // paragraph published `<p>x   </p>`. The published AST kept the space
        // either way (markup-carve/carve#963).
        //
        // EVERY LINE, not just the first: a caption folds its continuation
        // lines in here exactly as a paragraph does.
        //
        // Space and tab ONLY - `whitespace = ' ' | '\t'` (PART 1,
        // markup-carve/carve#890). Every other invisible character is content
        // and survives, which is what corpus 268-7 pins.
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
            $caption->setPos($this->wholeLineSpan($start));
            $this->inlineParser->parse(
                $caption,
                $captionText,
                $start,
                true,
                $this->contiguousMapFor($start, $lines[$start], $captionText),
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
                && self::isCaptionableDisplayMath($paragraphChildren[0])
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
        if ($this->listParser->parseListItemMarker(ltrim($line, " \t")) !== null) {
            return true;
        }

        return $this->startsNewBlock($line, $lines, $index);
    }

    /**
     * Whether a line ENDS an open definition term.
     *
     * The term's own rule (PART 2, `definition_term`): "A term folds a following
     * plain line as a soft break; a blank line, a new `::`/`: ` marker, or a
     * BLOCK OPENER ends it."
     *
     * A `^ ` CAPTION LINE IS NOT ONE HERE (markup-carve/carve#1028). PART 9 §4
     * gives a caption exactly five hosts - an image paragraph, a code block, a
     * block quote, a table and a standalone display-math block - and a
     * definition term is none of them. PART 2's `caption_slot` note draws the
     * conclusion: "A `^ ` line that follows neither a slot-carrying host nor one
     * of those two is ordinary inline/paragraph content." Ordinary inline
     * content is precisely what `term_continuation_line` folds, and §10's two
     * enumerations of what OPENS a block - I1's visible openers and I5's
     * invisible ones - name no caption line in either. So `:: term` / `^ cap`
     * is one `<dt>` holding both lines.
     *
     * This engine already read it that way for an open PARAGRAPH: `para` /
     * `^ cap` is one paragraph here, as it is in carve-js and carve-rs. Only the
     * term disagreed, because it reaches the decision through
     * `startsNewBlock()`, whose caption arm was written for the block quote -
     * where a caption really does end the fold, because it ATTACHES to the quote
     * (PART 2, LAZY CONTINUATION: "not a caption ('^ ' ...), which attaches to
     * the blockquote instead"). That arm stays where it is; it just does not
     * reach a host that cannot take a caption.
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

        // This line is a flush-left lazy candidate by construction, so it is at
        // DOCUMENT level - where an abbreviation definition is a definition
        // (PART 12 §7). An INVISIBLE CONSTRUCT interrupts, so it ends the quote
        // rather than folding into it. `startsNewBlock` cannot answer this: it
        // is also asked about lines inside containers, where the abbreviation
        // shape is ordinary paragraph text.
        //
        // ALL FOUR INVISIBLE KINDS, not the abbreviation alone
        // (markup-carve/carve#1028). PART 2's LAZY CONTINUATION clause names
        // them in one breath - a line continues the quote provided it is "not a
        // block-opener: a heading, table, fenced code, `:::` div, thematic
        // break, OR an 'invisible' reference / footnote / abbreviation
        // definition OR COMMENT -- each ends the blockquote and starts that
        // block OUTSIDE it" - and PART 9 §10 I5 adds the block-attribute line to
        // the same set, with I6 applying the relation to "EVERY open paragraph,
        // including a blockquote's lazy continuation".
        //
        // Only one of the four was here, so this engine ended the quote on a
        // reference definition and kept it open across a `%%` comment and a
        // `{…}` line. That is not cosmetic: `> quote` / `%% c` / `more` put
        // `more` INSIDE the quote as a second paragraph, where carve-js and
        // carve-rs make it a sibling paragraph of the document - one line of the
        // author's prose, attributed to a quotation they did not write it in.
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
     * @param array{openParagraph: bool, inFence: bool, fenceChar: string, fenceLength: int, inDiv: bool, divFenceLength: int, absorbingFence: bool, divDepth: int, isLead: bool, inTable: bool, afterInvisible: bool, inFootnoteBody: bool, quotedTable: bool} $state
     * @param string $line Collected line, stripped to content-relative indentation.
     * @param bool $atContentColumn Whether the line sits AT the container's
     *   content column rather than below it. Only the comment branch reads it:
     *   an invisible block at the column ends the paragraph, while the same
     *   line collected lazily adds no block at all.
     *
     * @return array{openParagraph: bool, inFence: bool, fenceChar: string, fenceLength: int, inDiv: bool, divFenceLength: int, absorbingFence: bool, divDepth: int, isLead: bool, inTable: bool, afterInvisible: bool, inFootnoteBody: bool, quotedTable: bool}
     */
    protected function advanceTrailingBlockState(
        array $state,
        string $line,
        bool $atContentColumn = false,
    ): array {
        // PART 9 §12's absorption belongs to ONE open paragraph, so it ends
        // wherever that paragraph does. Clearing it here and re-arming it only
        // in the two branches that continue the same paragraph is what keeps a
        // heading, a table or a code fence between a malformed fence and a
        // later bare `:::` from leaving it set: those end the paragraph, and
        // the later fence opens a real div (carve#891).
        $wasAbsorbing = $state['absorbingFence'];
        $state['absorbingFence'] = false;
        // `isLead` is true for exactly the FIRST line handed to this tracker -
        // the container's LEAD, which for a list item is its marker-line
        // content and for the recursive quote step below is the quote's first
        // line. Only the heading branch reads it. Cleared here so every branch
        // sees one consistent answer for the line after.
        $wasLead = $state['isLead'];
        $state['isLead'] = false;
        // A CONTINUATION ROW IS MORE TABLE, and only where a table is above it
        // (markup-carve/carve#1349). Cleared here and re-armed only by the two
        // row branches, for the same reason `absorbingFence` is: every other
        // block ENDS the table, so a `+ b |` under a blank line, a heading or a
        // fence is the ordinary prose it looks like.
        $wasInTable = $state['inTable'];
        $state['inTable'] = false;
        // The nested quote's own table run, carried across ITS lines and never
        // spent on this container's - see the quote branch below.
        $wasQuotedTable = $state['quotedTable'];
        $state['quotedTable'] = false;
        // AN INVISIBLE BLOCK ENDS THE PARAGRAPH WITHOUT ENDING THE CONTAINER,
        // which are two questions one flag used to answer
        // (markup-carve/carve-php#1421). Cleared here and re-armed only by the
        // branches that write an invisible block, like the two above it.
        $state['afterInvisible'] = false;
        // A FOOTNOTE DEFINITION IS THE ONE INVISIBLE BLOCK WITH A BODY, so it
        // is the only one whose further-indented line continues it rather than
        // being the container's own prose.
        $wasInFootnoteBody = $state['inFootnoteBody'];
        $state['inFootnoteBody'] = false;

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
                // The closer is consumed HERE rather than by the bare-run branch
                // below, so the depth has to come back down here too. Left
                // unbalanced, a later malformed fence in the same item saw a
                // container still open, armed nothing, and the bare run after it
                // read as a phantom closer.
                if ($state['divDepth'] > 0) {
                    $state['divDepth']--;
                }
                // A CLOSED div holds no open paragraph either. S4 is about the
                // OPEN STACK, and a closed container is not on it.
                $state['openParagraph'] = false;

                return $state;
            }

            // AN UNTERMINATED DIV'S OWN TRAILING BLOCK DECIDES
            // (markup-carve/carve#909, corpus 270 and 271). PART 1 S4 asks
            // whether an open paragraph is on the stack, not which container
            // kind is; a div the flush-left line can still reach has its own
            // last block, and a line of body text in it IS an open paragraph.
            // Forcing false here answered "no" for every line inside the div
            // alike, so `- item` / `::: note` / `body` / `tail` ended the item
            // where the corpus folds `tail` into the div's paragraph. The EMPTY
            // case - a div whose opener is the last thing on the stack - is
            // decided by the opener branch below, which sets false and is what
            // keeps `::: note` / `tail` a sibling.
            //
            // A NESTED OPENER IS STILL AN OPENER, and leaves an EMPTY container
            // on the stack rather than a paragraph - the same answer the outer
            // opener gets one level up. A code fence opener leaves none either.
            if ($this->fencedBlockParser->parseDivFenceOpener($line) !== null) {
                $state['divDepth']++;
                $state['openParagraph'] = false;

                return $state;
            }
            // A CODE FENCE INSIDE A DIV OPENS A VERBATIM BODY, so its lines
            // are content and not structure. Recorded as `inFence` here, the
            // fence branch at the top of this function skips the body and the
            // div is not closed by a `:::` written INSIDE it - which is exactly
            // the shape `BoundaryLineInsideAnOpenFenceTest` pins for the walk
            // that collects an attached block. Left untracked, the opener only
            // said "no open paragraph" and the very next `:::` read as the
            // div's closer.
            $divCodeFence = $this->fencedBlockParser->parseCodeFenceOpener($line);
            if ($divCodeFence !== null) {
                /** @var string $divFenceChar */
                $divFenceChar = $divCodeFence['char'];
                /** @var int $divCodeFenceLength */
                $divCodeFenceLength = $divCodeFence['length'];
                $state['inFence'] = true;
                $state['fenceChar'] = $divFenceChar;
                $state['fenceLength'] = $divCodeFenceLength;
                $state['openParagraph'] = false;

                return $state;
            }

            // A TABLE and a THEMATIC BREAK inside the div leave no open
            // paragraph, exactly as they do outside one. A HEADING does NOT go
            // with them here, and that is measured rather than tidy: the
            // executable spec puts the flush-left line INSIDE the div after
            // `- item` / `::: note` / `# h`, while it puts it at the top level
            // for the same shape in a block quote. Both are reproduced as
            // measured.
            $trimmedInDiv = ltrim($line, " \t");
            if (
                preg_match('/^([-*_])\1{2,}[ \t]*$/', $trimmedInDiv) === 1
                || $this->tableParser->isTableRow($trimmedInDiv)
            ) {
                $state['openParagraph'] = false;

                return $state;
            }

            // Deliberately as narrow as the rest of this tracker: any other
            // non-blank line inside the div counts as paragraph-bearing.
            $state['openParagraph'] = !IndentationHelper::isBlankLine($line);

            return $state;
        }

        if (IndentationHelper::isBlankLine($line)) {
            // A blank line closes the current block. Until a fresh block opens,
            // a dedented line is a new top-level block, not a continuation.
            // The paragraph that was absorbing malformed fences ends here too,
            // so the next fence-shaped line is an opener again. `$wasAbsorbing`
            // is deliberately not carried past this point.
            //
            // A FOOTNOTE DEFINITION'S BODY IS THE EXCEPTION, because the blank
            // is INSIDE that block rather than after it: THE BLOCK'S EXTENT IS
            // THE DEFINITION'S, BLANK LINES AND ALL (PART 1 S4,
            // markup-carve/carve#1363). The body run is carried across so the
            // line below the blank is still the note's, and only a line that
            // stops reaching the body column gives the container its paragraph
            // back.
            $state['inFootnoteBody'] = $wasInFootnoteBody;
            $state['afterInvisible'] = $wasInFootnoteBody;
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

        $bareFence = preg_match('/^:{3,}[ \t]*$/', $line) === 1;
        // A bare run with a container open is that container's CLOSER, so it is
        // neither an opener nor absorbable text.
        if ($bareFence && $state['divDepth'] > 0) {
            $state['divDepth']--;
            $state['openParagraph'] = false;

            return $state;
        }

        $divOpener = $this->fencedBlockParser->parseDivFenceOpener($line);
        if ($divOpener !== null) {
            // ...unless the paragraph above already absorbed a malformed fence
            // and this is a BARE run, in which case §12 takes it as text too and
            // the paragraph stays open. Not width-tagged: after a malformed
            // `:::note` a following `::::` is absorbed as readily as a `:::`. A
            // line that opens something of its own - `::: note`, `::: |`,
            // `::: [label]` - still interrupts, exactly as it does at the top
            // level, where this engine already implements §12.
            if ($wasAbsorbing && $bareFence) {
                $state['absorbingFence'] = true;
                $state['openParagraph'] = true;

                return $state;
            }
            /** @var int $divFenceLength */
            $divFenceLength = $divOpener['length'];
            $state['inDiv'] = true;
            $state['divFenceLength'] = $divFenceLength;
            $state['divDepth']++;
            $state['openParagraph'] = false;

            return $state;
        }

        // A fence-shaped line that is NOT a valid opener is ordinary paragraph
        // text, and from here the paragraph absorbs the next fence-shaped line
        // as well. `:::note` fails §12's opener test because a type word must be
        // separated from the fence by a space. Inside an open container it is
        // body text and arms nothing: the bare run below it is still that
        // container's closer.
        if (preg_match('/^:{3,}/', $line) === 1) {
            $state['absorbingFence'] = $state['divDepth'] === 0;
            $state['openParagraph'] = true;

            return $state;
        }

        if ($this->tableParser->isTableRow($line)) {
            // A table has no open paragraph for a dedented line to continue.
            $state['openParagraph'] = false;
            $state['inTable'] = true;

            return $state;
        }

        // A TABLE IS A TABLE HOWEVER ITS LAST ROW IS SPELLED. A continuation
        // row carries no leading pipe, so the row test above does not see it,
        // and the container reported an open paragraph its table did not have:
        // `> | a |` / `> + b |` / `tail` kept `tail` inside the quote where the
        // standard-row spelling of the same table sends it out
        // (markup-carve/carve#1348, corpus 349).
        //
        // ONLY WHERE A TABLE IS ABOVE IT, which is the whole of #1349. With no
        // row above, `- a` / `  + b |` is a paragraph and its `+ b |` is prose,
        // so the paragraph stays open and a dedented line still folds into it.
        if ($wasInTable && $this->tableParser->isContinuationRow($line)) {
            $state['openParagraph'] = false;
            $state['inTable'] = true;

            return $state;
        }

        // A QUOTE IS DECIDED BY THE BLOCK INSIDE IT, not by being a quote.
        // PART 1 S4 asks whether an open paragraph is on the stack; a quote is
        // a container, so the answer is its own last block's. `> q` holds an
        // open paragraph and a column-0 line folds into it; `>` alone holds
        // nothing and the item closes (carve#572); `> # H` holds a HEADING,
        // which is closed, and the item closes there too (corpus 326-11).
        //
        // Recursing on the quote's content is what makes those one rule rather
        // than three: the marker-only case is the blank-line branch one level
        // in, and the heading case is the heading branch one level in. Spelled
        // as an `isEmptyQuoteLine` special case, only the degenerate answer was
        // reachable and every non-empty quote reported an open paragraph.
        // RTRIM ONLY, and then {@see ContainerPrefix} alone. The two halves are
        // separate rules and each was got wrong by the obvious spelling:
        //
        //  - NOT ltrim. `>  >` is ONE marker and the content ` >`, so stripping
        //    the leading space before the recursive step re-reads that content
        //    as a second marker with an empty tail - the two-spellings defect
        //    carve-php#969 removed, reintroduced one recursion deeper.
        //  - BUT rtrim. Trailing whitespace is dropped from a content line, so
        //    `> >` and `> >` plus a tab are the same line, and the parser builds
        //    an empty quote for both. Reading the tab as content made them
        //    disagree (carve-php#967 is the same class one level up).
        $quoteContent = ContainerPrefix::quoteContent(rtrim($line, " \t"));
        if ($quoteContent !== null) {
            // The recursive step starts from the INITIAL state on every line,
            // so a quote's table would forget itself between its own rows: the
            // row arrives one recursion in and the continuation row arrives at
            // a state that never saw it. Seeding the step with the table flag -
            // and reading it back out - is what lets `> | a |` / `> + b |` be
            // ONE table, exactly as the unquoted spelling is. Nothing else in
            // the inner state crosses lines, because nothing else has to.
            $seed = self::INITIAL_TRAILING_BLOCK_STATE;
            $seed['inTable'] = $wasQuotedTable;
            $inner = $this->advanceTrailingBlockState($seed, $quoteContent);
            $state['openParagraph'] = $inner['openParagraph'];
            // THE ROW ABOVE IS THE ONE IN THE SAME CONTAINER (PART 9 §5 T6,
            // markup-carve/carve-php#1436). A table inside the QUOTE is not
            // above a `+` line written in the ITEM, so the quote's run is
            // carried in a slot of its own and this container's own `inTable`
            // stays as the top of this call left it: false.
            //
            // Handing the quote's table outward made `- > | a |` / `  + b |`
            // read the `+` line as that table's continuation row, so the item
            // reported no open paragraph and the flush-left line went out -
            // where PART 1 S4 has PROSE reopening the item's paragraph, because
            // it does not ask whether the open paragraph is the container's
            // FIRST block (corpus 361).
            $state['quotedTable'] = $inner['inTable'];

            return $state;
        }

        // PART 1 S4: NO OPEN PARAGRAPH, NO LAZY LINE. Every block below CLOSES
        // when its own line ends, so it leaves nothing on the stack for a
        // column-0 line to continue and the container ends there (corpus 326).
        //
        // Listed rather than derived because the tracker's fallback is "prose
        // unless proven otherwise", and each of these is a line the fallback
        // read as prose:
        //
        //  - a HEADING and a THEMATIC BREAK are one-line blocks with no
        //    paragraph after them;
        //  - a LINK REFERENCE and a FOOTNOTE DEFINITION are consumed as
        //    metadata, leaving the container with no visible trailing block;
        //  - a FLOATING ATTRIBUTE attaches FORWARD, so it is not a paragraph a
        //    line behind it could join. Left as prose here it did worse than
        //    fold the line in: the attribute then landed ON the folded line.
        //
        // The two facts stay separate below: `absorbingFence` already tracked a
        // heading and a thematic break as paragraph-ENDING while this reported
        // an open paragraph anyway. That disagreement inside one function is
        // what this resolves.
        // A COMMENT IS TRANSPARENT, WHICH IS NEITHER OF THE TWO ANSWERS. §24 C3
        // keeps it invisible at any column and closing nothing, so it must
        // leave `openParagraph` exactly as it found it: `- a` / `%% c` / `b`
        // folds `b` into `a`'s paragraph (corpus 183, 214-2) while `- %% c` /
        // `tail` ends an item that never held a paragraph at all (corpus 326-5).
        // Answering `false` got the second and broke the first; answering
        // `true` does the reverse. Only "unchanged" gets both, and it is the
        // reason INITIAL_TRAILING_BLOCK_STATE now starts CLOSED - an item whose
        // first line is a comment has to inherit "nothing open" from somewhere.
        if ($this->isCommentLineOrFence($line)) {
            // AT THE CONTENT COLUMN IT IS A BLOCK, and an invisible block ends
            // the paragraph exactly as a definition does - which is the rule
            // markup-carve/carve#1350 states and corpus 350-6 pins:
            //
            //     :: t
            //     :  a
            //        %% c
            //     tail
            //
            // leaves `tail` OUTSIDE. Below the column it is a LAZY line and
            // adds no block at all, so the state is the caller's to keep.
            if ($atContentColumn) {
                $state['openParagraph'] = false;
                // ...AND ONLY THE PARAGRAPH. A comment renders nothing, so the
                // container it sits in is not finished by it: corpus 197 puts
                // the indented line after it in the item as a SECOND paragraph,
                // and corpus 277 opens a nested list there. What must not
                // happen is a FLUSH-LEFT line folding in, which is what the
                // closed paragraph refuses (corpus 357-2, 357-3).
                $state['afterInvisible'] = true;
            }

            return $state;
        }

        // A HEADING CLOSES THE CONTAINER ONLY WHEN IT IS THE LEAD, and unlike
        // the comment above that is MEASURED from the corpus rather than read
        // off a clause. Two pinned documents put the same heading on either
        // side of the answer:
        //
        //   - # H        `tail` is a NEW TOP-LEVEL BLOCK (corpus 326)
        //   tail
        //
        //   - b          `lazy` FOLDS INTO THE ITEM (corpus 75-4, nested)
        //     # N
        //   lazy
        //
        // The heading is the container's own last block in both, so neither
        // "always closes" nor "never closes" fits. What separates them is
        // whether the container OPENS with it: an item whose lead is a heading
        // never held a paragraph, while an item that leads with text still is
        // one and a heading written under it does not end the item.
        //
        // NOT "whatever the state already was" - that reading passes this pair
        // and then loses `- text` / blank / `  # N` / `lazy`, where the blank
        // has cleared the flag and the heading has to put it back. The lead is
        // the fact; the running flag is not.
        //
        // It is also why the branches that DO close unconditionally - a table
        // and a fence above, a thematic break and a definition below - are not
        // joined by a heading here.
        if (preg_match('/^#{1,6} .*' . StringUtil::NON_WHITESPACE_CLASS . '/', $line) === 1) {
            $state['openParagraph'] = !$wasLead;

            return $state;
        }

        // THE REST CLOSE, AND ARE TESTED AT COLUMN 0, which is this tracker's
        // convention: its docblock says the lines arrive stripped to
        // content-relative indentation, so a block of the CONTAINER's own sits
        // at column 0 and an indented line belongs to something nested inside
        // it. The existing branches already read the line that way - a code
        // fence opener and a table row are tested unindented.
        if (
            preg_match('/^([-*_])\1{2,}[ \t]*$/', $line) === 1
            || $this->isReferenceDefinitionLine($line)
            || $this->isBlockAttributeLine($line)
        ) {
            $state['openParagraph'] = false;
            // A DEFINITION IS AN INVISIBLE BLOCK TOO (PART 9 section 10 I5), so
            // it ends the paragraph without ending the container, exactly as
            // the comment above does. A thematic break is not invisible and an
            // attribute block attaches forward, but neither keeps a container
            // collecting either, so the flag is set for the branch rather than
            // split three ways for a difference nothing reads.
            $state['afterInvisible'] = true;
            // ONLY A FOOTNOTE DEFINITION HAS A BODY. A reference definition is
            // one line, and the indented line under it is the container's own
            // prose that a flush-left line still folds into (corpus 357-6) -
            // reading it as a body ended the item there.
            $state['inFootnoteBody'] = preg_match(self::FOOTNOTE_DEFINITION_PATTERN, $line) === 1;

            return $state;
        }

        // AN INDENTED LINE UNDER A FOOTNOTE DEFINITION IS ITS BODY, not the
        // container's prose. A definition written at an item's content column
        // carries its continuation one indent further in, and reading that
        // continuation as item text reopened the paragraph a flush-left line
        // then folded into (markup-carve/carve#1357, corpus 357-4). The
        // ONE-LINE spelling of the same definition already answered correctly,
        // which is what makes the pair discriminating.
        //
        // Indentation is measured in THIS tracker's view, where a block of the
        // container's own sits at column 0 - so an indented line here is
        // already nested inside the container's last block.
        // THE BLOCK'S EXTENT IS THE DEFINITION'S, BLANK LINES AND ALL (PART 1
        // S4, markup-carve/carve#1363). A blank INSIDE the body separates the
        // note's own blocks rather than ending it, so the body runs to the
        // first line that neither is blank nor reaches the indent - and only
        // then does the container get its paragraph back.
        //
        // Settled by an internal contradiction rather than by a count: this
        // engine ended the item on the contiguous spelling and folded the
        // flush-left line as soon as a blank sat between the note's blocks, so
        // one definition answered differently by how its own body was laid out.
        if (
            $wasInFootnoteBody
            && (
                trim($line) === ''
                // THE BODY COLUMN, not merely some indent. A definition sits at
                // column 0 in this tracker's view, so its body reaches column
                // two; one column short is the container's own prose and a
                // flush-left line still folds into it.
                || IndentationHelper::getLeadingColumns($line, self::FOOTNOTE_BODY_COLUMN) >= self::FOOTNOTE_BODY_COLUMN
            )
        ) {
            $state['openParagraph'] = false;
            $state['afterInvisible'] = true;
            $state['inFootnoteBody'] = true;

            return $state;
        }

        // A LIST ITEM IS DECIDED BY THE BLOCK INSIDE IT, exactly as the quote
        // above is, and for the same clause: PART 1 S4 asks whether an open
        // paragraph is on the STACK, and an item is a container, so the answer
        // is its own last block's.
        //
        // Without this branch the fallback below read a marker line as prose,
        // so every nested item reported an open paragraph whatever it held. The
        // clause names this case explicitly - "it binds even where the
        // unmatched container is a LIST ITEM whose last block is a container"
        // (markup-carve/carve#1280) - and this engine answered it only at depth
        // 1, where the marker line never reaches this tracker at all: the item's
        // own lead arrives with the marker already off. From depth 2 the lead
        // IS a marker line, and the rule stopped applying
        // (markup-carve/carve-php#1403, markup-carve/carve-php#1404).
        //
        // Depth 3 folded one level in rather than not at all, which is the same
        // fact seen from the other end: one level of the walk was missing, not
        // the rule.
        //
        // A LOOP AND NOT A RECURSIVE CALL PER MARKER. The two are EQUIVALENT -
        // the recursive step below would take the next marker off by itself -
        // so no output test can tell them apart, and one written as a mutant
        // survives the whole file. What separates them is cost: `- - - ... x`
        // is one LINE, so its marker count is bounded by the line rather than
        // by the document, and a frame per marker measured ~2.4x slower on a
        // 5000-marker line (16.1s against 6.3s). It did NOT overflow the stack
        // at that size, which is the claim this comment used to make.
        //
        // The single recursive step is on the CONTENT, which is where a quote
        // or a comment one level in is decided.
        //
        // AFTER the thematic break above, which a spaced `- - -` would
        // otherwise take from it, and after the heading, which decides by the
        // LEAD and would lose that fact one level down.
        //
        // WALKED AS OFFSETS, NOT AS STRINGS. Asking `parseListItemMarker()` for
        // each nested marker copies the whole remaining line every time it
        // matches, so the walk cost markers TIMES line length per entry and the
        // entries are capped rather than bounded - 8 KB of markers took about
        // three seconds with the ratio per doubling still climbing
        // (carve-php#1426). PART 9 section 25 is normative about refusing
        // rather than degrading, so that shape is a defect and not a slow path.
        // `markerContentOffset()` answers the same question from the SAME
        // spelling of the grammar with a zero-width lookahead, so one `substr`
        // at the end replaces N of them.
        $contentOffset = $this->listParser->innermostMarkerContentOffset($line);
        if ($contentOffset !== null) {
            $markerContent = substr($line, $contentOffset);
            $inner = $this->advanceTrailingBlockState(self::INITIAL_TRAILING_BLOCK_STATE, $markerContent);
            // ONLY A BLOCK THAT FINISHES ON THE LEAD LINE ANSWERS HERE. A code
            // fence or a `:::` opener CONTINUES onto lines this step never
            // sees - they arrive at this tracker, one container out, where they
            // are not the nested item's content - so the recursion has not read
            // the block it would be reporting on. Reporting anyway ended the
            // outer item on the fence's first body line, which changed what the
            // item CONTAINS and not just where the lazy line went: `- - ::: note`
            // / `b` / `:::` turned a literal `::: note` into a real admonition
            // and moved `b` out of the item. carve-js and carve-rs both leave
            // an unfinished opener as prose here, and so does the fallback
            // below, so this falls through to it.
            if (!$inner['inFence'] && !$inner['inDiv'] && !$inner['absorbingFence']) {
                $state['openParagraph'] = $inner['openParagraph'];

                return $state;
            }
        }

        // Any other non-blank line belongs to a paragraph-bearing block (plain
        // paragraph, blockquote, heading text). Treat the trailing block
        // as having an open paragraph and let the existing lazy-continuation
        // behavior fold the dedented line in.
        //
        // An absorption already under way survives PROSE, because that is the
        // same paragraph - but not a heading or a thematic break, which end it.
        // This tracker keeps `openParagraph` true for those (its own older
        // choice, and the gate above is the only consumer), so the two facts are
        // tracked separately: after `:::note` + `# h`, the bare `:::` below is a
        // real div opener, exactly as it is at the top level.
        $trimmedForBoundary = ltrim($line, " \t");
        $endsTheParagraph = preg_match('/^#{1,6} .*' . StringUtil::NON_WHITESPACE_CLASS . '/', $trimmedForBoundary) === 1
            || preg_match('/^([-*_])\1{2,}[ \t]*$/', $trimmedForBoundary) === 1;
        $state['absorbingFence'] = $wasAbsorbing && !$endsTheParagraph;
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
            if ($this->listParser->parseListItemMarker(ltrim($sl, " \t")) !== null) {
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
            if ($subCol >= 0 && IndentationHelper::getLeadingColumns($subLines[$j], $subCol) >= $subCol) {
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
            if (IndentationHelper::getLeadingColumns($line, $contentIndent) < $contentIndent) {
                return null;
            }

            $stripped = IndentationHelper::stripLeadingColumns($line, $contentIndent);
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

            return $stripped;
        }

        return null;
    }

    protected function lineOpensBlockForLooseness(string $line): bool
    {
        if ($this->listParser->parseListItemMarker(ltrim($line, " \t")) !== null) {
            return true;
        }

        // A line that RENDERS NOTHING is not a second paragraph either. §17 L1
        // loosens an item that holds a blank-line-separated second PARAGRAPH,
        // and a comment or a definition produces no output at all - so an item
        // came back wrapped in `<p>` because of a line the reader never sees,
        // which is the blank line showing through (carve-php#744). The blank
        // before a following SIBLING marker is a different clause and still
        // loosens; that one is decided by the caller, not here.
        // `abbreviationCounts: false`: this predicate only ever sees lines
        // inside an item body, and PART 12 §7 says an abbreviation definition
        // is one only as a direct child of the document. Here the same shape is
        // ordinary paragraph text that RENDERS, so it is exactly the second
        // paragraph §17 L1 asks about (carve#1267).
        if ($this->isInvisibleOrAttributeLine($line, false)) {
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
        if (preg_match('/^[-*] +' . StringUtil::NON_WHITESPACE_CLASS . '/', $line)) {
            return true;
        }

        // Ordered lists: digit(s) or letter plus delimiter, or the bare-dot
        // shorthand, followed by space + content.
        if (preg_match('/^(?:\.|(\d+|[a-zA-Z])[.)]) +' . StringUtil::NON_WHITESPACE_CLASS . '/', $line)) {
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
     * The text PART 12 §4 offsets are measured against: the source as given.
     *
     * Every site that turns an offset back into characters has to use the same
     * string the offset table was built from, or a document with a BOM or CRLF
     * verifies a span against text three (or one-per-line) bytes away and
     * silently reports no position at all.
     */
    protected function positionSource(): string
    {
        return $this->originalSource !== '' ? $this->originalSource : $this->normalizedSource;
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
        // MEASURED ON THE SOURCE AS GIVEN. `strlen($line) + 1` assumes every
        // line ending is one byte, and a stripped BOM is three more - so a CRLF
        // or BOM-led document had every span land before the text it named
        // (carve#876). The widths come from the original, so `\n`, `\r\n` and a
        // lone `\r` are each counted at their real size, and the mark is
        // skipped so line 0 starts at the first real character.
        $original = $this->originalSource !== '' ? $this->originalSource : $normalized;
        $this->positionIndex = $this->trackPositions ? new PositionIndex($original) : null;
        $offset = str_starts_with($original, "\u{FEFF}") ? 3 : 0;
        foreach ($lines as $index => $line) {
            $this->lineStartOffsets[$index] = $offset;
            $offset += strlen($line);
            $ending = substr($original, $offset, 2);
            if (str_starts_with($ending, "\r\n")) {
                $offset += 2;

                continue;
            }
            $offset += 1;
        }

        // Drop the empty line a TERMINAL newline leaves behind. `explode` on
        // "a\n" yields ['a', ''] and the document has one line, not two.
        //
        // It belongs here, where the string is known to be a whole document.
        // The fence collector used to do it instead, by refusing to absorb a
        // blank LAST line - which also refused the real blank at the end of a
        // container body, so a fence ended by a div closer or a bare quote
        // marker came out a line short (carve-php#1177).
        if (end($lines) === '') {
            array_pop($lines);
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
        $this->references = [];
        $this->headingReferencesByFoldedLabel = [];
        $this->footnotes = [];
        $this->footnoteDefinitionSpans = [];
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
     *
     * ONE LOOKUP, NOT TWO (markup-carve/carve#742). A heading goes into the
     * FOLDED index and nowhere else. `$this->references` is the linkDefs table
     * and `getReference()` reads it for the EXPLICIT `[text][label]` form, so a
     * heading seeded there made an explicit label reach the heading index - a
     * shape R1's fallback does not offer, since the fallback is scoped to the
     * collapsed `[text][]` and to nothing else.
     */
    protected function registerHeadingReference(string $label, ReferenceDefinition $reference): void
    {
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
     * Seeds ONE lookup: the folded heading index, which only the COLLAPSED
     * `[text][]` form reads. It used to seed the linkDefs table beside it, so
     * an exact `[text][Label]` reached a heading too - and markup-carve/carve#742
     * scopes R1's fallback to the collapsed form and to nothing else, at any
     * spelling, folded or exact.
     *
     * THE ASYMMETRY IS THE ONE R1 ALREADY GIVES: a collapsed label is the author
     * quoting prose from elsewhere in the document, which is why its matching is
     * loose, and an explicit label is an identifier the author wrote twice and
     * can keep identical, which is why its matching is exact. An identifier that
     * names nothing names nothing; it is not retried as prose.
     *
     * A real link definition still wins, because `getCollapsedReference()` reads
     * `$this->references` first and only falls back here.
     *
     * @param array<string, array{0: string, 1: \MarkupCarve\Carve\Parser\ReferenceDefinition}> $references
     */
    public function seedHeadingReferences(array $references): void
    {
        foreach ($references as $folded => [, $reference]) {
            $this->headingReferencesByFoldedLabel[$folded] ??= $reference;
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
}
