<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

use ArrayObject;
use Closure;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\RenderDepthExceededException;
use MarkupCarve\Carve\Exception\SourceUnspellableException;
use MarkupCarve\Carve\Extension\Frontmatter;
use MarkupCarve\Carve\Node\Block\AbbreviationDefinition;
use MarkupCarve\Carve\Node\Block\BlockExtension;
use MarkupCarve\Carve\Node\Block\BlockQuote;
use MarkupCarve\Carve\Node\Block\Caption;
use MarkupCarve\Carve\Node\Block\CitationDefinition;
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
use MarkupCarve\Carve\Node\Block\Section;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Block\TableRow;
use MarkupCarve\Carve\Node\Block\ThematicBreak;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Abbreviation;
use MarkupCarve\Carve\Node\Inline\CaptionNumber;
use MarkupCarve\Carve\Node\Inline\CitationGroup;
use MarkupCarve\Carve\Node\Inline\Code;
use MarkupCarve\Carve\Node\Inline\CriticComment;
use MarkupCarve\Carve\Node\Inline\Delete;
use MarkupCarve\Carve\Node\Inline\Emphasis;
use MarkupCarve\Carve\Node\Inline\EscapedText;
use MarkupCarve\Carve\Node\Inline\FootnoteRef;
use MarkupCarve\Carve\Node\Inline\HardBreak;
use MarkupCarve\Carve\Node\Inline\HeadingRef;
use MarkupCarve\Carve\Node\Inline\Highlight;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Inline\InlineExtension;
use MarkupCarve\Carve\Node\Inline\InlineFootnote;
use MarkupCarve\Carve\Node\Inline\InlineNode;
use MarkupCarve\Carve\Node\Inline\Insert;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\LiteralInline;
use MarkupCarve\Carve\Node\Inline\Math;
use MarkupCarve\Carve\Node\Inline\Mention;
use MarkupCarve\Carve\Node\Inline\NonBreakingSpace;
use MarkupCarve\Carve\Node\Inline\RawInline;
use MarkupCarve\Carve\Node\Inline\RawText;
use MarkupCarve\Carve\Node\Inline\Ruby;
use MarkupCarve\Carve\Node\Inline\SmallCaps;
use MarkupCarve\Carve\Node\Inline\SmartPunctuation;
use MarkupCarve\Carve\Node\Inline\SoftBreak;
use MarkupCarve\Carve\Node\Inline\Span;
use MarkupCarve\Carve\Node\Inline\Strike;
use MarkupCarve\Carve\Node\Inline\Strong;
use MarkupCarve\Carve\Node\Inline\Subscript;
use MarkupCarve\Carve\Node\Inline\Substitution;
use MarkupCarve\Carve\Node\Inline\Superscript;
use MarkupCarve\Carve\Node\Inline\Symbol;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Inline\Underline;
use MarkupCarve\Carve\Node\Inline\UnresolvedReference;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Parser\Utility\AttributeParser;
use MarkupCarve\Carve\Parser\Utility\BracketScanner;
use MarkupCarve\Carve\Renderer\Utility\DocumentSentinels;
use MarkupCarve\Carve\Renderer\Utility\TableCellBlockFlattener;
use MarkupCarve\Carve\Transform\IncludeDirectiveSyntax;
use MarkupCarve\Carve\Util\StringUtil;
use ReflectionObject;
use stdClass;
use Throwable;

/**
 * Renders AST back to canonical Carve source.
 *
 * Output re-reads as what it was given, with structural carve-outs. A BLOCK
 * WHOSE WHOLE CONTENT IS ONE COMMENT may read back as a block opener of that
 * node's kind (PART 11 section 1c). It is THE ONLY ONE THE IMPORTER CAN BUILD;
 * the other carve-outs require a hand-built or ingested tree.
 */
class CarveRenderer implements RendererInterface, RenderLossAwareRendererInterface, ConversionDiagnosticCollector
{
    use RenderLossCollectorTrait;
    use ConversionDiagnosticCollectorTrait;

    /**
     * @var list<string>
     */
    private const ADMONITION_TYPES = ['note', 'tip', 'warning', 'danger', 'info', 'success', 'example', 'quote'];

    /**
     * Canonical definition marker.
     *
     * @var string
     */
    protected const DEFINITION_BODY_MARKER = ': ';

    /**
     * The body written for a footnote definition or a definition description
     * whose body holds no blocks (PART 11 §7b, markup-carve/carve#1827).
     *
     * A block-attribute line: the block it would attach to does not exist, so
     * the parse consumes the line and leaves the body empty. It has to be a
     * VALID attribute block, which is why it is not `{}` or `{ }` - those need
     * at least one attribute to be one and stay literal text. `{empty}` is a
     * boolean attribute, collected on the line and discarded with the rest of
     * the pending attributes, so it reaches nothing.
     *
     * The name is discarded by the parse, so it is chosen to be readable rather
     * than to carry anything.
     *
     * @var string
     */
    protected const EMPTY_BODY_SENTINEL = '{empty}';

    /**
     * Continuation indent matching the canonical marker width.
     *
     * @var string
     */
    protected const DEFINITION_BODY_INDENT = '  ';

    /**
     * The column a raised `::` line sits at - ONE past the body's minimum.
     *
     * @var string
     */
    protected const ENTRY_RAISE_INDENT = ' ';

    /**
     * @var string
     */
    private const ESCAPE_MODE_MINIMAL = 'minimal';

    /**
     * @var string
     */
    private const ESCAPE_MODE_CONSERVATIVE = 'conservative';

    protected int $blockDepth = 0;

    protected int $inlineDepth = 0;

    /**
     * Inside a table cell, where a leading `^` cannot open a caption: a caption
     * marker is a BLOCK line, and a cell's content is not one.
     */
    protected int $tableCellDepth = 0;

    private ?TableCell $flattenedTableCell = null;

    /**
     * Object ids of the hard breaks at a cell's edge, which write nothing.
     *
     * @var array<int, true>
     */
    protected array $edgeCellBreaks = [];

    /**
     * Object ids of the inline spans written in the braced form.
     *
     * @var array<int, true>
     */
    protected array $bracedSpans = [];

    /**
     * Inside an inline note's content, where `^[` opens nothing.
     *
     * PART 9 §16: a note's content is parsed with footnote recognition
     * DISABLED, at every depth, in both directions. So the inner spelling is
     * ordinary text there and the writer has nothing to escape
     * (markup-carve/carve#1191).
     */
    protected int $inlineNoteDepth = 0;

    protected int $listDepth = 0;

    protected int $colonFenceDepth = 0;

    /**
     * Whether the block about to be written sits DIRECTLY in a body that gives
     * a definition entry its own authored base: a footnote body or a definition
     * description (PART 9 §24 C3, markup-carve/carve#1763). A definition list is
     * the one block whose payload needs one - see atARaisedBase().
     *
     * A blockquote and a colon fence are not among them: they carry a marker or
     * a fence rather than a column, so there is no base to raise. A list item is
     * a container the reader's rule now reaches (carve#1791), but its two
     * spellings carry the same offset from the `::`, so the raise would buy
     * nothing there and the gate below answers no for it on its own.
     */
    protected bool $atAnAuthoredBodyColumn = false;

    /**
     * The parser the writer asks its column question of - see atARaisedBase().
     */
    protected ?BlockParser $rebaseProbe = null;

    protected string $escapeMode = self::ESCAPE_MODE_CONSERVATIVE;

    /**
     * The units written in the conservative form, keyed by `spl_object_id`,
     * when the writer is deciding unit by unit rather than document by
     * document.
     *
     * Null means the whole pass follows $escapeMode, which is what the two
     * exploratory renders in render() do. Non-null is PART 11 section 2b's
     * pass: a unit in the set is escaped in full, every other unit is emitted
     * by section 2's own test, and for a character nothing needs that means
     * bare.
     *
     * @var array<int, true>|null
     */
    protected ?array $escalatedUnits = null;

    /**
     * Where the writer records the unit a character it is escaping belongs to.
     *
     * Non-null only for narrowEscalation()'s control render, which uses it to
     * learn which units the escape arms actually ask about - see the comment
     * there. Null everywhere else, so no other render pays for the bookkeeping.
     *
     * @var array<int, true>|null
     */
    protected ?array $askedUnits = null;

    /**
     * The source canonicalTree() last parsed, and the tree it gave.
     *
     * @var string|null
     */
    protected ?string $treeCacheSource = null;

    /**
     * @var array{tree: mixed}|null
     */
    protected ?array $treeCache = null;

    /**
     * Whether a narrowing candidate re-parsed to the conservative tree, keyed
     * by a hash of its bytes. The halving and the sweep re-render states they
     * already measured, and parsing is pure, so the verdict cannot change.
     *
     * @var array<string, bool>
     */
    protected array $candidateVerdicts = [];

    /**
     * @var array<string, array<string, \ReflectionProperty>>
     */
    protected array $canonicalProperties = [];

    /**
     * How many more narrowing renders the current document may pay for.
     *
     * THE SEARCH IS BOUNDED, because its cost is proportional to how many units
     * FAIL. A group that holds no failing unit is relaxed in one render, so a
     * document with a handful of them costs about log(n) renders - but one where
     * nearly every unit fails drives the recursion to its leaves and pays a
     * render and a parse per unit, which is quadratic in the document.
     *
     * Such a document gains almost nothing from narrowing: it IS the
     * conservative form, arrived at because every block needed it. So the search
     * stops when the budget runs out and returns the state it has reached, which
     * is verified like every other - the escalation is wider than §2b's minimum
     * there, never narrower, and no document's output can be wrong for it.
     */
    protected int $narrowingBudget = 0;

    /**
     * The node whose render arm is currently writing, and therefore the unit
     * the next escaped character belongs to.
     *
     * Set by renderBlock() and renderInline(), so a run of prose is charged to
     * its text node and the strings a block writes itself are charged to the
     * block.
     */
    protected ?Node $escapeUnit = null;

    /**
     * Byte offsets in a text node's content escaped in BOTH forms, keyed by
     * `spl_object_id`: PART 11 §5's lone bracket and destination-opening `(`.
     *
     * @var array<int, array<int, true>>
     */
    protected array $structuralEscapes = [];

    /**
     * The units the logged render asked about, and the end of the log.
     *
     * @return array<int, true>
     */
    protected function takeAskedUnits(): array
    {
        $asked = $this->askedUnits ?? [];
        $this->askedUnits = null;

        return $asked;
    }

    /**
     * The characters the occurrence search never offers back.
     *
     * Section 5's UNCONDITIONAL set is written in the minimal form too, so
     * relaxing one is not a narrower escaping of the same tree but a different
     * document. `^`, `!` and `$` join them for a different reason: the callback
     * in escapeText() already applies section 2's own test to each of them,
     * position by position, so there is nothing left for a search to decide -
     * and leaving every guarded character out is what keeps this engine and
     * carve-js offering the SAME sites in the same order.
     *
     * @var string
     */
    protected const NOT_OFFERED_PER_OCCURRENCE = '\\`"\'^!$';

    /**
     * What separates two backtick runs that would otherwise merge: an empty
     * delimited comment, which renders nothing and compares equal to nothing
     * (PART 11 section 10k N3, ruled on markup-carve/carve-js#1818).
     *
     * @var string
     */
    protected const VERBATIM_SEPARATOR = '{%  %}';

    /**
     * Which units the occurrence search numbers, keyed by `spl_object_id`, so
     * a key survives a re-render.
     *
     * Non-null only during that search. Everywhere else the whole unit follows
     * escapeModeHere(), which is section 2b's per-unit knob.
     *
     * @var array<int, int>|null
     */
    protected ?array $unitNumbers = null;

    /**
     * The occurrences handed back their bare form by the search (PART 11
     * section 2).
     *
     * @var array<string, true>|null
     */
    protected ?array $relaxedOccurrences = null;

    /**
     * Where a pass records the occurrences it visited, in emission order.
     *
     * AN ArrayObject AND NOT AN ARRAY, because the collector is not the reader:
     * the log is filled from occurrenceIsRelaxed() several frames inside a
     * render, and static analysis reading narrowOccurrences() on its own sees
     * only the empty array it was opened with - which makes the "did this pass
     * visit anything?" test look like a constant. A handle keeps the question
     * honest at the one place it is asked.
     *
     * @var \ArrayObject<int, string>|null
     */
    protected ?ArrayObject $occurrenceLog = null;

    /**
     * The decision the last candidate site took, so a RUN can inherit it.
     */
    protected bool $lastOccurrenceRelaxed = false;

    /**
     * How many escaped runs each unit has written in this pass, keyed by
     * `spl_object_id`.
     *
     * THE OFFSET ALONE IS NOT A KEY. A unit is the node whose arm wrote the
     * character, and a BLOCK's arm can write several runs - a table row's
     * cells, a fence title beside its info string - each with its own offsets
     * starting at zero. Two of them collide at offset 0 and the search would
     * then relax both sites or neither, which is the per-unit knob this whole
     * change removes, one level down.
     *
     * The count is stable across the search for the same reason the offsets
     * are: relaxing an occurrence changes which characters are emitted and
     * never which arms run, so a unit writes the same runs in the same order on
     * every render.
     *
     * @var array<int, int>|null
     */
    protected ?array $escapeCallIndexes = null;

    /**
     * The index of the run now being escaped, within its unit.
     */
    protected function nextEscapeCallIndex(): int
    {
        if ($this->escapeCallIndexes === null || $this->escapeUnit === null) {
            return 0;
        }
        $id = spl_object_id($this->escapeUnit);
        $index = $this->escapeCallIndexes[$id] ?? 0;
        $this->escapeCallIndexes[$id] = $index + 1;

        return $index;
    }

    /**
     * How many more occurrence renders the current document may pay for.
     */
    protected int $occurrenceBudget = 0;

    /**
     * The occurrences the pass just rendered visited, in emission order.
     *
     * Read through a method rather than in place, so the list is typed by the
     * PROPERTY - which is what the collector fills - instead of by the empty
     * handle the caller opened two statements earlier.
     *
     * @return array<int, string>
     */
    protected function loggedOccurrences(): array
    {
        return $this->occurrenceLog === null ? [] : array_values($this->occurrenceLog->getArrayCopy());
    }

    /**
     * Whether the search has handed the candidate at `$offset` back its bare
     * form.
     *
     * THE KEY IS THE POSITION, NOT AN ORDINAL, and that is what makes it
     * survive a re-render: relaxing an occurrence changes the emitted BYTES and
     * never the node's own text, so a site keeps the offset it had. An ordinal
     * would have to be counted at every site whether it was offered or not, and
     * two engines whose escape classes differ by one character would then
     * number every later site differently.
     *
     * THE OCCURRENCE IS THE RUN, WHICH IS SECTION 2's OWN UNIT. "THE UNIT IS
     * THE OPENER, NOT THE CHARACTER" - where a construct opens on a run of
     * characters the whole run is escaped, so `\\#\\# H` and never `\\## H`. A
     * search that offered the two hashes separately relaxes the second one,
     * because with the first still escaped no heading forms either way, and
     * emits precisely the half-escaped run section 2 calls "a shape that
     * happens to work rather than one that says what it means". So a candidate
     * repeating the character before it inherits that character's decision
     * instead of taking one, and the run is escaped or bare as a whole.
     */
    protected function occurrenceIsRelaxed(int $call, int $offset, bool $continuesRun): bool
    {
        if ($this->unitNumbers === null || $this->escapeUnit === null) {
            return false;
        }
        if ($continuesRun) {
            return $this->lastOccurrenceRelaxed;
        }
        $unit = $this->unitNumbers[spl_object_id($this->escapeUnit)] ?? null;
        if ($unit === null) {
            return false;
        }
        $key = $unit . ':' . $call . ':' . $offset;
        $this->occurrenceLog?->append($key);
        $this->lastOccurrenceRelaxed = isset($this->relaxedOccurrences[$key]);

        return $this->lastOccurrenceRelaxed;
    }

    protected function escapeModeHere(): string
    {
        if ($this->askedUnits !== null && $this->escapeUnit !== null) {
            $this->askedUnits[spl_object_id($this->escapeUnit)] = true;
        }
        if ($this->escalatedUnits === null) {
            return $this->escapeMode;
        }
        if ($this->escapeUnit !== null && isset($this->escalatedUnits[spl_object_id($this->escapeUnit)])) {
            return self::ESCAPE_MODE_CONSERVATIVE;
        }

        return self::ESCAPE_MODE_MINIMAL;
    }

    /**
     * The four writer-only sentinels, chosen per render from code points the
     * DOCUMENT does not contain.
     *
     * @var array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string}
     */
    protected array $verbatimSentinels = [
        "\u{E001}",
        "\u{E002}",
        "\u{E003}",
        "\u{E004}",
        "\u{E005}",
        "\u{E006}",
        "\u{E007}",
    ];

    /**
     * Every string in the tree, joined.
     *
     * The walk itself lives in DocumentSentinels, because the HTML target needs
     * the same one to keep an authored U+0001 out of its soft-break guard
     * (carve-php#1077), and two copies of a collision rule is how one rule
     * acquires two answers.
     */
    protected function collectStrings(object $root): string
    {
        return DocumentSentinels::collectStrings($root);
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string}
     */
    protected function pickVerbatimSentinels(string $text): array
    {
        // SEVEN, not six: the last is §11 N1a's list boundary. It is picked
        // here rather than fixed for the reason the whole scheme exists - a
        // fixed code point cannot be told apart from an authored one, and this
        // sentinel expands to THREE BLANK LINES, so an authored occurrence
        // would be rewritten into a list boundary the author never wrote.
        /** @var array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string} $picked */
        $picked = DocumentSentinels::pick($text, 7, 0xE001);

        return $picked;
    }

    /**
     * The tag that says section 11 N1a's boundary - three blank lines - goes
     * ABOVE the line it opens.
     */
    protected function listBoundary(): string
    {
        return $this->verbatimSentinels[6];
    }

    public function render(Document $document): string
    {
        // Choose the sentinels before anything is rendered, so both escape passes
        // below agree on them.
        $this->verbatimSentinels = $this->pickVerbatimSentinels($this->collectStrings($document));
        $this->treeCacheSource = null;
        $this->bracedSpans = [];
        $this->treeCache = null;
        $this->structuralEscapes = [];
        $this->planStructuralEscapes($document);
        $minimal = $this->renderWithEscapeMode($document, self::ESCAPE_MODE_MINIMAL);
        $conservative = $this->renderWithEscapeMode($document, self::ESCAPE_MODE_CONSERVATIVE);
        if ($minimal === $conservative) {
            return $minimal;
        }
        if ($this->escapingIsRedundant($minimal, $conservative)) {
            return $minimal;
        }

        // The minimal form of the WHOLE document does not hold, which used to
        // end the decision here with the conservative form of the whole
        // document. PART 11 section 2b says how far that fallback actually
        // reaches: the smallest unit whose minimal form fails, and section 2's
        // own test everywhere else.
        return $this->narrowEscalation($document, $conservative, $minimal);
    }

    /**
     * The conservative form of the units that need it, and the minimal form of
     * every other unit (PART 11 section 2b).
     */
    protected function narrowEscalation(Document $document, string $conservative, ?string $minimal = null): string
    {
        $conservativeTree = $this->canonicalTree($conservative);
        // Null answers "cannot tell", exactly as it does for the minimal form:
        // with no tree to hold the narrowing against, there is nothing to
        // narrow toward.
        if ($conservativeTree === null) {
            return $conservative;
        }

        $all = $this->collectEscapeUnits($document);
        if ($all === []) {
            return $conservative;
        }

        $escalated = [];
        foreach ($all as $unit) {
            $escalated[spl_object_id($unit)] = true;
        }
        $this->escalatedUnits = $escalated;
        $this->candidateVerdicts = [$this->candidateKey($conservative) => true];
        if ($minimal !== null) {
            // render() narrows only after the minimal form failed to hold.
            $this->candidateVerdicts[$this->candidateKey($minimal)] = false;
        }

        try {
            // THE CONTROL RENDER LOGS WHICH UNITS THE WRITER ACTUALLY ASKS
            // ABOUT, so the search below can skip the ones it cannot move.
            // collectEscapeUnits() is a generic walk over every node that COULD
            // carry an escaped character; the units that DO are whatever the
            // writer's own escape arms charge a character to, and only those
            // read $escalatedUnits. A unit the writer never asks about renders
            // the same bytes in or out of the set, so offering it its minimal
            // form is a render and a parse spent to learn nothing.
            //
            // Deep nesting produces many units the writer never asks about.
            // Probing each would re-render and re-parse the expanding output.
            //
            // Logging it rather than predicting it is the same choice
            // collectEscapeUnits() makes and for the same reason: the set is
            // whatever the arms visit, so an arm that grows a new escape cannot
            // fall out of the search. And a unit wrongly left out cannot
            // produce wrong output - every state the search returns is
            // re-parsed against $conservativeTree, exactly as before.
            $this->askedUnits = [];
            try {
                $best = $this->renderSelectively($document);
            } finally {
                $asked = $this->takeAskedUnits();
            }
            if ($best !== $conservative) {
                return $conservative;
            }
            $units = [];
            foreach ($all as $unit) {
                if (isset($asked[spl_object_id($unit)])) {
                    $units[] = $unit;
                }
            }
            // No guard for an EMPTY $units: relaxUnits() returns on an empty
            // group, and a check here would be one no corpus document can
            // reach - the control render asks about a unit for every byte the
            // two forms differ in, and they differ or this is not running.
            // Eight times the depth of the halving, which is what narrowing four
            // independent failing units costs.
            $this->narrowingBudget = 8 * (int)ceil(log(count($units) + 1, 2)) + 8;
            $this->relaxUnits($document, $units, $conservativeTree, $best);
            // PART 11 section 2 TAKES THE DECISION PER OPENER OCCURRENCE, and
            // a unit is still ONE KNOB: a unit that fails is written
            // conservatively IN FULL, so every candidate character beside the
            // one that needed it is escaped for nothing. Section 2b bounds how
            // far the fallback reaches; this is what is left inside the bound
            // (markup-carve/carve#1533).
            $this->narrowOccurrences($document, $units, $conservativeTree, $best);

            return $best;
        } finally {
            $this->escalatedUnits = null;
            $this->candidateVerdicts = [];
        }
    }

    /**
     * Whether `$candidate` re-parses to `$conservativeTree`, parsing each
     * distinct candidate once per narrowing.
     *
     * @param string $candidate
     * @param array{tree: mixed} $conservativeTree
     */
    protected function candidateHolds(string $candidate, array $conservativeTree): bool
    {
        $key = $this->candidateKey($candidate);
        if (!isset($this->candidateVerdicts[$key])) {
            $candidateTree = $this->canonicalTree($candidate);
            // Loose, because escapingIsRedundant() compares the same trees the
            // same way: two spellings of one document differ in field ORDER,
            // not in content.
            $this->candidateVerdicts[$key] = $candidateTree !== null && $candidateTree == $conservativeTree;
        }

        return $this->candidateVerdicts[$key];
    }

    protected function candidateKey(string $candidate): string
    {
        return strlen($candidate) . ':' . hash('xxh128', $candidate);
    }

    /**
     * Hand `$units` their minimal form where the document still holds, halving
     * the group on failure.
     *
     * `$best` carries the render of the CURRENT escalation set, so the caller
     * always holds bytes that were verified: an accepted relaxation replaces
     * it, a rejected one restores the set it was measured against.
     *
     * @param \MarkupCarve\Carve\Node\Document $document
     * @param array<int, \MarkupCarve\Carve\Node\Node> $units
     * @param array{tree: mixed} $conservativeTree
     * @param string $best
     */
    protected function relaxUnits(Document $document, array $units, array $conservativeTree, string &$best): void
    {
        $count = count($units);
        if ($count === 0 || $this->narrowingBudget <= 0) {
            return;
        }
        $this->narrowingBudget--;
        foreach ($units as $unit) {
            unset($this->escalatedUnits[spl_object_id($unit)]);
        }
        $candidate = $this->renderSelectively($document);
        if ($this->candidateHolds($candidate, $conservativeTree)) {
            $best = $candidate;

            return;
        }
        foreach ($units as $unit) {
            $this->escalatedUnits[spl_object_id($unit)] = true;
        }
        if ($count === 1) {
            return;
        }
        $half = intdiv($count, 2);
        $this->relaxUnits($document, array_slice($units, 0, $half), $conservativeTree, $best);
        $this->relaxUnits($document, array_slice($units, $half), $conservativeTree, $best);
    }

    /**
     * The candidate escapes an escalated unit can still hand back, one
     * occurrence at a time (PART 11 section 2).
     *
     * SAME SEARCH, ONE LEVEL FINER. The comparison is still document-scoped,
     * so a failure still reports THAT the document changed and never WHERE;
     * the occurrence is found by trying, and every state kept is one that
     * re-parsed to the tree the conservative form parses to.
     *
     * THE OCCURRENCES ARE LOGGED, NOT PREDICTED. A candidate site is whatever
     * the writer's own escape arms visit, so they are collected by rendering
     * once with the log switched on rather than by a second enumeration here
     * that could drift from the one that emits. A key is `unit:ordinal` within
     * the unit, which is stable across the search because relaxing one
     * occurrence changes the bytes and not the sites: the arms walk the node's
     * own text, which no relaxation touches.
     *
     * THE FIRST RENDER IS A CONTROL, as it is one level up. With nothing
     * relaxed it must reproduce the state the unit search settled on byte for
     * byte; if logging changed what was written, the unit-scoped answer stands
     * rather than a narrowing built on a pass that is not the pass being
     * measured.
     *
     * BOUNDED THE SAME WAY AND FOR THE SAME REASON. A group holding no failing
     * occurrence is relaxed in one render, so a document with a handful of them
     * costs about log(n) renders - but a document where every occurrence is
     * load bearing drives the halving to its leaves and pays a render and a
     * parse per occurrence, which is a render of the whole document per escaped
     * character. A paragraph of indented table rows is exactly that, and it is
     * ordinary input rather than an adversarial one. The OUTPUT is unchanged
     * where the budget binds: those occurrences are the opener runs section 2
     * requires escaped in full.
     *
     * @param \MarkupCarve\Carve\Node\Document $document
     * @param array<int, \MarkupCarve\Carve\Node\Node> $units
     * @param array{tree: mixed} $conservativeTree
     * @param string $best
     */
    protected function narrowOccurrences(
        Document $document,
        array $units,
        array $conservativeTree,
        string &$best,
    ): void {
        $numbers = [];
        foreach (array_values($units) as $index => $unit) {
            $numbers[spl_object_id($unit)] = $index;
        }
        $unitScoped = $best;

        $this->unitNumbers = $numbers;
        $this->relaxedOccurrences = [];
        $this->occurrenceLog = new ArrayObject();
        try {
            $control = $this->renderSelectively($document);
            $occurrences = $this->loggedOccurrences();
            $this->occurrenceLog = null;
            if ($control !== $unitScoped || $occurrences === []) {
                return;
            }

            $this->occurrenceBudget = 8 * (int)ceil(log(count($occurrences) + 1, 2)) + 8;
            // OFFERED FROM THE END OF THE DOCUMENT BACKWARDS, which is what
            // makes the escape that survives the OPENER's. Section 2 asks
            // whether omitting the escapes on an occurrence would let the
            // construct FORM, and a construct forms at its opener - so with the
            // opener still escaped every later candidate on the same line is
            // free, while relaxing the opener first leaves the escape on a
            // closer that was never load bearing (`{.note \}` where section 2
            // wants `\{.note}`). Both spellings re-parse to the same tree, so
            // only the order separates them.
            $order = array_reverse($occurrences);
            $this->relaxOccurrences($document, $order, $conservativeTree, $best);
            // AND THEN ONE SWEEP OF WHAT IS LEFT, because the halving is not a
            // FIXPOINT. Relaxing occurrences is not monotone: an occurrence
            // rejected while a neighbour was still escaped can be free once
            // that neighbour is relaxed, and the halving never revisits a group
            // it has descended past. Corpus 160 is the case - the closing
            // `:::` line cannot go bare while the OPENING one is escaped,
            // because then it is the only fence marker on the page, and it can
            // once the opener is bare. The sweep offers every still-escalated
            // occurrence once more, on top of everything the halving accepted,
            // and spends the same budget - so where the budget is already gone
            // it costs nothing, which is the pathological document.
            foreach ($order as $key) {
                if ($this->occurrenceBudget <= 0) {
                    break;
                }
                if (isset($this->relaxedOccurrences[$key])) {
                    continue;
                }
                $this->relaxOccurrences($document, [$key], $conservativeTree, $best);
            }
        } finally {
            $this->unitNumbers = null;
            $this->relaxedOccurrences = null;
            $this->occurrenceLog = null;
            $this->escapeCallIndexes = null;
        }
    }

    /**
     * Hand `$group` its bare form where the document still holds, halving the
     * group on failure.
     *
     * @param \MarkupCarve\Carve\Node\Document $document
     * @param array<int, string> $group
     * @param array{tree: mixed} $conservativeTree
     * @param string $best
     */
    protected function relaxOccurrences(
        Document $document,
        array $group,
        array $conservativeTree,
        string &$best,
    ): void {
        $count = count($group);
        if ($count === 0 || $this->occurrenceBudget <= 0) {
            return;
        }
        $this->occurrenceBudget--;
        foreach ($group as $key) {
            $this->relaxedOccurrences[$key] = true;
        }
        $candidate = $this->renderSelectively($document);
        if ($this->candidateHolds($candidate, $conservativeTree)) {
            $best = $candidate;

            return;
        }
        foreach ($group as $key) {
            unset($this->relaxedOccurrences[$key]);
        }
        if ($count === 1) {
            return;
        }
        $half = intdiv($count, 2);
        $this->relaxOccurrences($document, array_slice($group, 0, $half), $conservativeTree, $best);
        $this->relaxOccurrences($document, array_slice($group, $half), $conservativeTree, $best);
    }

    protected function renderSelectively(Document $document): string
    {
        return $this->renderWithEscapeMode($document, self::ESCAPE_MODE_CONSERVATIVE);
    }

    /**
     * The canonical tree of `$source`, or null when it does not parse.
     *
     * Null answers "cannot tell" for every caller, exactly as it does in
     * escapingIsRedundant(): a writer bug that produces unparseable source must
     * not throw out of the renderer.
     *
     * @return array{tree: mixed}|null
     */
    protected function canonicalTree(string $source): ?array
    {
        // ONE SLOT, keyed by the source, because the conservative form is asked
        // for twice in a row - once by escapingIsRedundant() and once by the
        // narrowing it hands off to - and a full re-parse of the writer's own
        // output for an answer just computed is the most expensive kind of
        // nothing. Parsing is pure, so a hit on the same bytes cannot be stale,
        // and the search below overwrites the slot on its first probe, which is
        // why one slot is enough to catch the pair and never grows.
        if ($this->treeCacheSource === $source) {
            return $this->treeCache;
        }
        $this->treeCacheSource = $source;

        try {
            // Wrapped, so "did not parse" is null and cannot compare equal to
            // another document that did not parse either.
            $this->treeCache = ['tree' => $this->canonicalizeAst((new CarveConverter())->parse($source))];
        } catch (Throwable) {
            $this->treeCache = null;
        }

        return $this->treeCache;
    }

    /**
     * Every node that can carry an escaped character, in document order.
     *
     * A GENERIC WALK rather than a list of types, because the unit is "the node
     * whose render arm wrote this character" and every arm can grow one. A node
     * this misses is not silently mis-escaped: it is charged to no unit,
     * written minimally, and the control render in narrowEscalation() sees the
     * byte difference and declines to narrow.
     *
     * @return array<int, \MarkupCarve\Carve\Node\Node>
     */
    protected function collectEscapeUnits(Document $document): array
    {
        $out = [];
        $seen = [];
        $stack = [$document];
        while ($stack !== []) {
            $value = array_pop($stack);
            if (is_array($value)) {
                foreach (array_reverse($value) as $item) {
                    $stack[] = $item;
                }

                continue;
            }
            if (!is_object($value)) {
                continue;
            }
            $id = spl_object_id($value);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            if ($value instanceof Node) {
                $out[] = $value;
            }
            // The cast reaches private and protected properties too, which is
            // the point: a node's children are not all behind getChildren(),
            // and a walk that only asked for those would miss a table's rows.
            foreach (array_reverse(array_values((array)$value)) as $property) {
                $stack[] = $property;
            }
        }

        return $out;
    }

    /**
     * The spelling a thematic break is written with.
     *
     * The document-wide fallback spelling for a break that would otherwise
     * open manufactured frontmatter. PART 11 section 1 requires
     * `to_html(fmt(x)) == to_html(x)`.
     */
    protected string $thematicBreakMarker = '---';

    /**
     * Render, and fall back to a break spelling that cannot be read as
     * frontmatter when the finished bytes would be.
     */
    protected function renderWithEscapeMode(Document $document, string $escapeMode): string
    {
        $canonical = $this->renderOnePass($document, $escapeMode);
        // The frontmatter arm is a COST GATE, not a correctness one, and saying
        // so is the honest reading: a document that really carries frontmatter
        // has it written by renderFrontmatter(), whose closer is not a break, so
        // the fallback pass would open frontmatter too and the canonical form
        // would be returned anyway. Removing the arm changes no output, only the
        // number of renders paid by every document with frontmatter.
        if ($this->documentOpensFrontmatter($document) || !$this->opensFrontmatter($canonical)) {
            return $canonical;
        }

        $previousMarker = $this->thematicBreakMarker;
        $this->thematicBreakMarker = '***';
        try {
            $fallback = $this->renderOnePass($document, $escapeMode);

            return $this->opensFrontmatter($fallback) ? $canonical : $fallback;
        } finally {
            $this->thematicBreakMarker = $previousMarker;
        }
    }

    /**
     * Whether the AST itself carries frontmatter, which the writer emits as
     * frontmatter rather than manufacturing.
     */
    protected function documentOpensFrontmatter(Document $document): bool
    {
        return ($document->getChildren()[0] ?? null) instanceof Frontmatter;
    }

    /**
     * Whether `$text` would be READ AS OPENING A FRONTMATTER BLOCK.
     *
     * "Would this be read as frontmatter" is a question only the parser can
     * answer here, and it is spread across six sites in two files: the opener
     * pattern, the first-production guard and the closer search live in
     * FrontmatterExtension, behind BlockParser's first-refusal hand-off for a
     * bare `---` on line 1. A pattern match in the writer would be a seventh
     * spelling of that rule, and this org keeps finding one rule spelled N times
     * with N larger than anyone claimed - so the bytes are parsed instead, by
     * the same default converter escapingIsRedundant() already trusts to
     * decide the escape mode.
     */
    protected function opensFrontmatter(string $text): bool
    {
        // Frontmatter is document-leading, so nothing that does not start with
        // the fence can open one. The gate keeps a whole parse off the path
        // every ordinary document takes.
        if (!str_starts_with($text, '---')) {
            return false;
        }

        return $this->documentOpensFrontmatter((new CarveConverter())->parse($text));
    }

    protected function renderOnePass(Document $document, string $escapeMode): string
    {
        $previousEscapeMode = $this->escapeMode;
        $previousColonFenceDepth = $this->colonFenceDepth;
        $this->escapeMode = $escapeMode;
        $this->colonFenceDepth = 0;
        // The LOG and the call indexes are per PASS: renderWithEscapeMode() can
        // render twice for the frontmatter fallback, and keeping either would
        // count the second pass's runs on from the end of the first.
        if ($this->unitNumbers !== null) {
            $this->escapeCallIndexes = [];
            $this->occurrenceLog?->exchangeArray([]);
        }
        try {
            return $this->renderDocumentParts($document);
        } finally {
            $this->escapeMode = $previousEscapeMode;
            $this->colonFenceDepth = $previousColonFenceDepth;
        }
    }

    /**
     * Attributes carried by each authored definition, keyed by label.
     *
     * @var array<string, array<string, string>>
     */
    protected array $definitionAttributes = [];

    /**
     * Collected definitions, by the source line the author wrote them on.
     *
     * @var array<int, \MarkupCarve\Carve\Node\Block\LinkReferenceDefinition|\MarkupCarve\Carve\Node\Block\Footnote>
     */
    protected array $definitionsByLine = [];

    /**
     * Definitions already written on a description line, so the document-level
     * pass does not write them a second time.
     *
     * @var array<int, true>
     */
    protected array $definitionsWrittenInPlace = [];

    /**
     * A term defined twice is two lines the author wrote; which one wins is
     * resolution (PART 9R) and the formatter does not resolve, so every
     * authored node is written, each at its own position.
     */
    protected function renderAbbreviationDefinition(AbbreviationDefinition $node): string
    {
        return '*[' . $this->escapeBracketText($node->getAbbr()) . ']: '
            . str_replace("\n", ' ', $node->getExpansion());
    }

    protected function renderDocumentParts(Document $document): string
    {
        $parts = [];
        // PART 12 §10: definition attributes serialize ONCE, on the definition.
        // Resolution materializes them onto every link that resolves the label
        // so the HTML target can render them, which leaves the writer unable to
        // tell them from attributes the author wrote AT the reference. Emitting
        // both wrote `{.x}` twice and broke PART 11 §1 (carve#642), so the
        // definition's own keys are subtracted at the reference site.
        $this->definitionAttributes = [];
        // A definition COLLECTED from a description is written back on that
        // description's line, so the emptied `dd` is not a bare `:` that
        // re-parses into the term above it (carve#805, carve-php#903). Indexed
        // by the line the author wrote it on; the description carries the same
        // line since the parser stopped losing it.
        $this->definitionsByLine = [];
        $this->definitionsWrittenInPlace = [];
        foreach ($document->getChildren() as $child) {
            if ($child instanceof LinkReferenceDefinition && $child->getAttributes() !== []) {
                $this->definitionAttributes[$child->getLabel()] = $child->getAttributes();
            }
            // BOTH collected kinds, because the author can write either on a
            // description line: a link reference definition or a footnote.
            if ($child instanceof LinkReferenceDefinition || $child instanceof Footnote) {
                // ONLY a definition authored in THIS file is written back where
                // it was authored. A definition an include brought in carries
                // its own file's line numbers (`pos.file` names which file),
                // and those coordinates mean nothing here - indexing by them
                // wrote a merged child's footnote into the middle of the parent
                // instead of hoisting it with the rest.
                $pos = $child->getPos();
                $line = $pos?->file === null ? $pos?->startLine : null;
                // First writer wins for a line, which cannot normally collide:
                // two definitions on one line is not a shape the parser builds.
                if ($line !== null && !isset($this->definitionsByLine[$line])) {
                    $this->definitionsByLine[$line] = $child;
                }
            }
        }
        // The definition is written WHERE IT WAS AUTHORED, from its node, because
        // `renderBlocks` has an arm for it. This used to place the whole set at
        // one end of the body, chosen by `hasAbbreviationsBeforeBody()` - two
        // positions, which is one fewer than a document can express, so a
        // definition authored BETWEEN two blocks moved to an end and
        // `parse(fmt(x)) != parse(x)` (PART 11 section 1). The parser in this
        // engine already keeps the node at its source position because PART 12
        // section 7 refuses to collect it (BlockParser::orderCollectedDefinitions),
        // so the two halves disagreed about the same clause.
        $residual = [];
        foreach ($document->getAbbreviationDefinitionsNotInTree() as $definition) {
            $residual[] = '*[' . $this->escapeBracketText($definition['abbr']) . ']: '
                . str_replace("\n", ' ', $definition['expansion']);
        }
        if ($residual !== [] && $document->hasAbbreviationsBeforeBody()) {
            $parts[] = implode("\n\n", $residual);
        }
        $body = $this->renderBlocks($document->getChildren());
        if ($body !== '') {
            $parts[] = $body;
        }
        if ($residual !== [] && !$document->hasAbbreviationsBeforeBody()) {
            $parts[] = implode("\n\n", $residual);
        }

        return $this->normalize(implode("\n\n", $parts));
    }

    /**
     * PART 11 section 4: compare the parsed minimal and conservative renders,
     * not either render against the source AST. If parsing fails, keep the old
     * conservative behavior.
     *
     * Both trees come from canonicalTree(), which is where the docblock there
     * already says they must: the narrowing compares through that same
     * normalization, and it is also what lets its own parse of the conservative
     * form be the one this call just took.
     */
    protected function escapingIsRedundant(string $minimal, string $conservative): bool
    {
        $minimalTree = $this->canonicalTree($minimal);
        $conservativeTree = $this->canonicalTree($conservative);

        return $minimalTree !== null && $conservativeTree !== null && $minimalTree == $conservativeTree;
    }

    /**
     * @return mixed
     */
    protected function canonicalizeAst(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $child) {
                $out[$key] = $this->canonicalizeAst($child);
            }
            if (array_is_list($out)) {
                $out = $this->coalesceTextNodes($out);
            } else {
                ksort($out);
            }

            return $out;
        }

        if (is_object($value)) {
            $name = $value::class;
            // A stdClass carries only dynamic properties, which differ per object.
            $properties = $value instanceof stdClass
                ? $this->canonicalPropertiesOf($value)
                : $this->canonicalProperties[$name] ??= $this->canonicalPropertiesOf($value);
            $out = ['__class' => $value instanceof EscapedText ? Text::class : $name];
            foreach ($properties as $propertyName => $property) {
                $out[$propertyName] = $this->canonicalizeAst($property->getValue($value));
            }
            ksort($out);

            return $out;
        }

        return $value;
    }

    /**
     * @return array<string, \ReflectionProperty>
     */
    protected function canonicalPropertiesOf(object $value): array
    {
        $properties = [];
        foreach ((new ReflectionObject($value))->getProperties() as $property) {
            $name = $property->getName();
            if ($name === 'parent' || $name === 'sourceLength' || $name === 'ingestPayloadLength') {
                continue;
            }
            $properties[$name] = $property;
        }

        return $properties;
    }

    /**
     * @param array<mixed> $nodes
     *
     * @return array<mixed>
     */
    protected function coalesceTextNodes(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $lastIndex = count($out) - 1;
            $content = $this->canonicalTextContent($node);
            if ($lastIndex >= 0 && $content !== null) {
                $previousContent = $this->canonicalTextContent($out[$lastIndex]);
                if ($previousContent !== null && is_array($out[$lastIndex])) {
                    $out[$lastIndex]['content'] = $previousContent . $content;

                    continue;
                }
            }
            $out[] = $node;
        }

        return $out;
    }

    protected function canonicalTextContent(mixed $node): ?string
    {
        if (
            is_array($node)
            && ($node['__class'] ?? null) === Text::class
            && ($node['attributes'] ?? []) === []
            && ($node['attributeOrder'] ?? []) === []
            && ($node['children'] ?? []) === []
            && is_string($node['content'] ?? null)
        ) {
            return $node['content'];
        }

        return null;
    }

    /**
     * Whether two adjacent sibling lists would read back as ONE list.
     *
     * PART 9 section 11 N1's axes. `listType` already separates a task list
     * from a plain one, so what remains is the authored marker character (the
     * bullet, or the ordered delimiter) and the ordered dialect. Where any of
     * them differs the lists separate on their own and the writer owes them
     * nothing, which is what carve#286 established.
     */
    protected static function listsWouldMerge(ListBlock $a, ListBlock $b): bool
    {
        return $a->getListType() === $b->getListType()
            && $a->getMarker() === $b->getMarker()
            && $a->getStyle() === $b->getStyle();
    }

    /**
     * PART 11 6g. An item with no recorded state takes the default for its box.
     */
    protected static function taskMarker(ListItem $item): string
    {
        return '[' . ($item->getAuthoredTaskState() ?? ($item->isCompleted() ? 'x' : ' ')) . ']';
    }

    protected static function indentLines(string $text, int $columns): string
    {
        $pad = str_repeat(' ', $columns);

        return implode("\n", array_map(
            static fn (string $line): string => $line === '' ? $line : $pad . $line,
            explode("\n", $text),
        ));
    }

    /**
     * @param array<\MarkupCarve\Carve\Node\Node> $blocks
     *
     * @throws \MarkupCarve\Carve\Exception\RenderDepthExceededException
     */
    protected function renderBlocks(array $blocks): string
    {
        if ($this->blockDepth >= self::MAX_RENDER_DEPTH) {
            throw new RenderDepthExceededException(self::MAX_RENDER_DEPTH, 'Carve');
        }

        $this->blockDepth++;
        $previousCaptionHost = $this->afterCaptionHost;
        try {
            $parts = [];
            // TWO ADJACENT SIBLING LISTS NEED SOMETHING BETWEEN THEM. Written at
            // the same column with matching markers they merge on re-parse, so
            // `parse(fmt(x)) == parse(x)` is false for a document the parser
            // reads as two lists (carve#1088). carve#286 spent the marker axis -
            // emit the marker as authored - which separates them only while the
            // markers DIFFER; when both are `1.` at column 0 there is nothing
            // left to preserve and indentation is the axis remaining.
            //
            // THE SEPARATOR IS THE HARD BOUNDARY (§11 N1a): three blank lines.
            // That is the language's own way of saying "these are two lists", so
            // the writer says it instead of encoding the same fact as layout.
            //
            // It REPLACES a cumulative one-space offset, which existed only
            // because no separator was spelled. That offset returned a list at a
            // column the author never wrote, and it could not survive a third
            // list: +1 per list put the second at one space and the third at two,
            // where a bullet's content column NESTS the later list in the earlier.
            $previousList = null;
            $previousBlock = null;
            $listSeparated = false;
            foreach ($blocks as $block) {
                $rendered = $this->renderBlock($block);
                // Remember, for the NEXT block, whether a `^ ` line after it
                // would be read back as a caption. Only then does the caption
                // marker need escaping (PART 11 §2, carve-php#758).
                $this->afterCaptionHost = self::hostsACaption($block);
                if ($block instanceof ListBlock) {
                    $listSeparated = $previousList !== null && self::listsWouldMerge($previousList, $block);
                    $previousList = $block;
                } elseif (self::spellsSomething($rendered)) {
                    $previousList = null;
                    $listSeparated = false;
                }
                // A block that spells nothing contributes nothing - not even
                // the blank line a part of its own would open. As far as the
                // page is concerned it is the empty paragraph (PART 11 §10j).
                if (self::spellsSomething($rendered)) {
                    if ($listSeparated && $parts !== []) {
                        // The tag OPENS the next block's first line rather than
                        // joining two blocks, so every host that indents line by
                        // line can see the break and prefix the line - see
                        // listBoundary(). normalize() spells the three blank
                        // lines with whatever prefix ends up to its left.
                        $parts[array_key_last($parts)] .= "\n" . $this->listBoundary() . $rendered;
                    } elseif (
                        $parts !== []
                        && $previousBlock instanceof Node
                        && $previousBlock->getRenderHint("\0carve-compact-definition") === '1'
                        && $block->getRenderHint("\0carve-compact-definition") === '1'
                    ) {
                        $parts[array_key_last($parts)] .= "\n" . $rendered;
                    } else {
                        $parts[] = $rendered;
                    }
                }
                $previousBlock = $block;
            }

            return implode("\n\n", $parts);
        } finally {
            $this->blockDepth--;
            $this->afterCaptionHost = $previousCaptionHost;
        }
    }

    /**
     * Does this block put anything on the page that a re-parse can see?
     *
     * PART 11 §10j: an unspellable block does not cancel the adjacency it
     * cannot spell. When two sibling lists are parted by a block that
     * reaches the page, that block separates them and PART 9 §11 N1a's
     * boundary is not needed; when it reaches the page with NOTHING, the
     * lists are still adjacent and the boundary is the only thing keeping
     * them two.
     *
     * ASKED OVER WHAT THE BLOCK SPELLS, NEVER OVER ITS TYPE. A test written
     * against a paragraph would pass the shape that found this and miss the
     * rule - a figure wrapping a table is interchange-only too (PART 12
     * §17), and so is anything a later clause makes unspellable.
     *
     * An emptiness test was the near miss: an EMPTY paragraph renders to
     * nothing and was already handled, while a paragraph holding one space
     * rendered to one space and cancelled the boundary - so the writer
     * disagreed with itself about two trees it puts the same page on. A
     * space and a tab are the `whitespace` terminal, which a re-parse reads
     * as a blank line. A NO-BREAK space is not in it and IS content
     * (PART 11 §7), so a paragraph holding one spells a paragraph and does
     * separate the lists.
     *
     * The class is spelled out rather than left to `trim()`, which happens
     * to agree here: PHP trims a NUL and a vertical tab and does NOT trim
     * U+00A0, so it lands on the same answer for every character this rule
     * is about. Naming the characters keeps the rule readable against the
     * clause instead of against a function's charset - and the sibling
     * engine's `trim` DOES eat U+00A0, so agreement here is a coincidence
     * of this language rather than a property to rely on.
     *
     * @param string $rendered The block's rendered source.
     *
     * @return bool True when a character outside the whitespace terminal
     *   reaches the page.
     */
    private static function spellsSomething(string $rendered): bool
    {
        return preg_match('/[^ \\t\\r\\n]/u', $rendered) === 1;
    }

    /**
     * Could a `^ ` line following this block be read back as its caption?
     *
     * The parser attaches a caption to a table, a code block, a block quote,
     * and a paragraph holding nothing but an image or display math. After
     * anything else the marker cannot form, so escaping it says nothing -
     * which is what §2 calls a defect rather than a safe default.
     *
     * An UNRESOLVED reference image is not an image here either, for the same
     * reason it is not a figure (#751): the label resolves to nothing, so
     * there is no image for a caption to attach to.
     *
     * @param \MarkupCarve\Carve\Node\Node $block
     */
    private static function hostsACaption(Node $block): bool
    {
        if ($block instanceof Table || $block instanceof CodeBlock || $block instanceof BlockQuote) {
            return true;
        }

        // The closing fence of a bare `::: figure` container is §4's sixth
        // caption host (PART 9 §4c), so a paragraph starting with `^` right
        // after a composite figure needs its escape - and the group's own
        // caption, written by renderFigureGroup() itself, does not pass
        // through here at all.
        if ($block instanceof FigureGroup) {
            return true;
        }

        if ($block instanceof Image) {
            return UnresolvedReference::sourceOf($block) === null;
        }

        if (!$block instanceof Paragraph) {
            return false;
        }

        $children = $block->getChildren();
        if (count($children) !== 1) {
            return false;
        }

        if ($children[0] instanceof Image) {
            return UnresolvedReference::sourceOf($children[0]) === null;
        }

        return $children[0] instanceof Math && $children[0]->isDisplay();
    }

    /**
     * Depth of line-block nesting, so the inline writer can drop the explicit
     * hard-break backslash where the container already implies one.
     */
    protected int $inLineBlock = 0;

    /**
     * Whether the block just written can host a caption, so a following `^ `
     * line would be read back as one (see hostsACaption()).
     */
    protected bool $afterCaptionHost = false;

    /**
     * Whether the first inline line of the paragraph being rendered follows a
     * block that can host a caption. Kept separate from $afterCaptionHost so a
     * soft break inside that paragraph cannot inherit the previous block's
     * caption slot (carve-php#1113).
     */
    protected bool $paragraphStartsAfterCaptionHost = false;

    /**
     * Render one block, recording it as the escape unit its own arm writes
     * with.
     *
     * PART 11 section 2b bounds an escalation to the smallest unit that fails,
     * so the escape pass has to know which unit each escaped character belongs
     * to. The unit is the node whose render arm is running: a text node for a
     * run of prose, the block itself for the strings a block writes directly.
     */
    protected function renderBlock(Node $node): string
    {
        $previous = $this->escapeUnit;
        $this->escapeUnit = $node;
        // THE FLAG DESCRIBES THIS NODE, NOT ITS SUBTREE. A host sets it once
        // and renderBlocks() walks several children with it still set, so it is
        // read here and cleared for whatever this node renders inside itself -
        // a definition list inside a blockquote inside a footnote body is at
        // the QUOTE's column, not the body's - and restored so the next sibling
        // still sees it.
        $atAnAuthoredBodyColumn = $this->atAnAuthoredBodyColumn;
        $this->atAnAuthoredBodyColumn = false;
        try {
            return $this->renderBlockBody($node, $atAnAuthoredBodyColumn);
        } finally {
            $this->escapeUnit = $previous;
            $this->atAnAuthoredBodyColumn = $atAnAuthoredBodyColumn;
        }
    }

    protected function renderBlockBody(Node $node, bool $atAnAuthoredBodyColumn = false): string
    {
        $stored = $node->getRenderHint("\0carve-stored-source");
        if ($stored !== null) {
            return $stored;
        }
        $attrs = $this->renderAttrs($node);
        $withAttrs = static fn (string $body): string => $attrs === '' ? $body : $attrs . "\n" . $body;
        // PART 9 §17 L7: the writer spells looseness with `{loose}` ONLY where
        // the blank-line spelling cannot.
        //
        // This is the load-bearing rule for churn. Deriving the key onto every
        // loose container would rewrite a large share of the corpus and of every
        // document anyone has written - on a multi-item loose list the blank
        // lines already say it, so the key would be an idle mark. The precedent
        // is PART 12 §15, whose writer retains `header-rows` where it is present
        // rather than deriving it onto every table, and PART 11 §2, which spends
        // a mark only where omitting it would change the re-parsed document.
        //
        // `$attrs` is the node's own already-rendered attribute run, which never
        // contains `loose`: the parser CONSUMED it, so the writer re-derives it
        // from the tree rather than echoing what the author wrote. That is what
        // makes a redundant `{loose}` a no-op through a format pass as well as
        // through a render.
        $withLooseAttrs = function (ListBlock|DefinitionList $container, string $body) use ($attrs): string {
            if (!$this->needsLooseKey($container, $body)) {
                return $attrs === '' ? $body : $attrs . "\n" . $body;
            }
            // The key LEADS, which is where an author writes it and where the
            // corpus shows it. Its position among the other slots is not
            // observable in the output - it is consumed before any renderer sees
            // it - so leading is a spelling choice rather than a fact moved.
            $order = array_values(array_filter(
                $container->getAttributeOrder(),
                static fn (string $slot): bool => $slot !== 'loose',
            ));
            array_unshift($order, 'loose');

            return $this->renderAttrList($container->getAttributes() + ['loose' => ''], $order) . "\n" . $body;
        };

        return match (true) {
            $node instanceof Frontmatter => $withAttrs($this->renderFrontmatter($node)),
            $node instanceof Heading => $withAttrs(str_repeat('#', $node->getLevel()) . ' ' . $this->headingText($this->renderInlines($node->getChildren()))),
            // A REFERENCE image cannot carry its attributes inline: the writer
            // returns the authored `rawRef` verbatim, and an attribute block
            // that came from the block-attribute LINE above is not part of that
            // source - so it was dropped, and an `#id` on a captionless
            // `![a][r]` was lost outright (carve-php#831). Written back as the
            // line it came from, which is where carve-js and carve-rs keep it.
            $node instanceof Image && $this->referenceImageAttributeLine($node) !== '' => $this->referenceImageAttributeLine($node) . "\n" . $this->renderImage($node),
            // A LONE image is a block node, not a paragraph wrapping one (the
            // `image` node's own description in the AST vocabulary).
            $node instanceof Image => $this->renderImage($node),
            $node instanceof AbbreviationDefinition
            => $withAttrs($this->renderAbbreviationDefinition($node)),
            $node instanceof Paragraph => $withAttrs($this->renderParagraph($node, $attrs === '')),
            // The opener's quoted title is resolved onto the `title` attribute at
            // parse time so it reaches every consumer, but the fence carries it
            // too - emitting both says it twice and re-parses with an attribute
            // order the source never had (carve#369). The fence is the authored
            // spelling, so it wins.
            $node instanceof CodeBlock => $this->withCodeBlockAttrs($node),
            $node instanceof BlockQuote => $withAttrs($this->renderBlockQuote($node)),
            $node instanceof ListBlock => $withLooseAttrs($node, $this->renderList($node)),
            $node instanceof ListItem => $this->renderListItem($node),
            $node instanceof ThematicBreak => $withAttrs(
                $this->thematicBreakMarker === '---' ? str_repeat($node->char, 3) : $this->thematicBreakMarker,
            ),
            $node instanceof Table => $this->renderTableWithAttrs($node, $withAttrs),
            $node instanceof Div && $node->isTyped() && $this->canRenderTypedDiv($node) => $this->withFencedDivAttrs($node, [$node->getClassList()[0] ?? ''], $this->renderTypedDiv($node)),
            $node instanceof Div && $node->isTyped() && $this->admonitionKind($node) !== null => $this->withFencedDivAttrs($node, [$this->admonitionKind($node)], $this->renderAdmonition($node)),
            $node instanceof Div => $withAttrs($this->renderDiv($node)),
            $node instanceof BlockExtension => $this->renderBlockExtensionFallback($node),
            $node instanceof Section => $this->renderSection($node),
            $node instanceof LineBlock => $withAttrs($this->renderLineBlock($node)),
            // THE ATTRIBUTE LINE MOVES WITH THE LIST. It is part of how this
            // block is spelled, so raising the body alone would leave `{loose}`
            // at the body minimum with the `::` line a column past it - a shape
            // no author writes and one the rebase then has to reconcile a line
            // at a time.
            $node instanceof DefinitionList => $this->atARaisedBase(
                $withLooseAttrs($node, $this->renderDefinitionList($node)),
                $atAnAuthoredBodyColumn,
            ),
            $node instanceof FigureGroup => $withAttrs($this->renderFigureGroup($node)),
            $node instanceof Figure => $withAttrs($this->renderFigure($node)),
            $node instanceof RawBlock => $withAttrs($this->renderRawBlock($node)),
            $node instanceof Comment => $this->renderComment($node),
            $node instanceof Footnote => isset($this->definitionsWrittenInPlace[spl_object_id($node)])
                ? ''
                : $this->renderFootnote($node),
            // PART 12 §10 gives the definition a node, so the writer emits the
            // AUTHORED line instead of folding the destination into every
            // reference. Inlining satisfied `toHtml(fmt(x)) == toHtml(x)` and
            // broke PART 11 §1: `ref`/`rawRef` - which §3a keeps precisely so
            // `[a][r]` and `[a](/u)` stay distinguishable - were absent from the
            // reparse (carve#642).
            $node instanceof LinkReferenceDefinition => isset($this->definitionsWrittenInPlace[spl_object_id($node)])
                ? ''
                : $this->renderLinkReferenceDefinition($node),
            $node instanceof Caption => '^ ' . $this->renderInlines($node->getChildren()),
            // PART 12 §18 gives the bibliography line a node; it does NOT move
            // rendered output on any target, and this writer is a target. The
            // line was dropped when the collect pass consumed it and it is
            // dropped here, so `fmt` is byte-identical either way
            // (markup-carve/carve#1276). Writing it back is a separate change
            // to what this renderer emits, not a consequence of the node
            // existing - without this arm the default branch would emit the
            // entry's inlines as a bare paragraph, which is neither.
            $node instanceof CitationDefinition => '',
            default => $this->renderBlocks($node->getChildren()),
        };
    }

    protected function renderSection(Section $node): string
    {
        $this->recordUnspellableStructure($node, 'Carve source cannot spell an explicit section');

        return $this->renderBlocks($node->getChildren());
    }

    protected function renderBlockExtensionFallback(BlockExtension $node): string
    {
        $this->recordUnspellableStructure($node, 'Carve source can only spell the block extension fallback');

        return $this->renderBlocks($node->getChildren());
    }

    protected function renderParagraph(Paragraph $node, bool $canUsePreviousCaptionSlot): string
    {
        $previous = $this->paragraphStartsAfterCaptionHost;
        $this->paragraphStartsAfterCaptionHost = $canUsePreviousCaptionSlot && $this->afterCaptionHost;
        try {
            $body = $this->guardThematicBreakLines($this->renderInlines($node->getChildren()));

            return $this->inLineBlock > 0 ? self::spellEmptyVerseLines($body) : $body;
        } finally {
            $this->paragraphStartsAfterCaptionHost = $previous;
        }
    }

    /**
     * AN EMPTY LINE INSIDE A VERBATIM RUN IS SPELLED `%%` (PART 11 §7c).
     *
     * A run left unclosed on an earlier line swallows an emptied verse line as
     * a NEWLINE in its value (PART 9 §23), so the tree holds no `hard_break`
     * for §7c to spell and no `comment` node to put back: what the writer has
     * is a value containing an empty line, and a blank body line would END THE
     * STANZA. An empty verse line has exactly ONE spelling that does not - a
     * comment line, which the block layer removes before the run exists, so
     * `%%` re-reads to the empty line it was written for and to nothing else.
     *
     * There is no other source of an empty line here. A `hard_break` over an
     * empty line is written `\` by §7c, and the blank line BETWEEN stanzas is
     * the container's, added after this runs.
     */
    private static function spellEmptyVerseLines(string $body): string
    {
        if (!str_contains($body, "\n")) {
            return $body;
        }

        $lines = explode("\n", $body);
        foreach ($lines as $index => $line) {
            if ($line === '') {
                $lines[$index] = '%%';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * A code block's attribute line, minus a `title` the fence already carries.
     */
    protected function withCodeBlockAttrs(CodeBlock $node): string
    {
        $body = $this->renderCodeBlock($node);
        $header = $node->getHeader();
        $attributes = $node->getAttributes();
        if ($header !== null && ($attributes['title'] ?? null) === $header) {
            $clone = clone $node;
            $clone->removeAttribute('title');
            $attrs = $this->renderAttrs($clone);
        } else {
            $attrs = $this->renderAttrs($node);
        }

        return $attrs === '' ? $body : $attrs . "\n" . $body;
    }

    protected function renderCodeBlock(CodeBlock $node): string
    {
        $content = $node->getContent();
        $fence = $this->safeFence($content, 3);
        $info = $this->codeFenceInfo($node);

        return $fence . $info . "\n" . $this->protectVerbatim($content) . "\n" . $fence;
    }

    protected function codeFenceInfo(CodeBlock $node): string
    {
        $parts = [];
        $language = $node->getLanguage();
        if ($language !== null && $language !== '') {
            $parts[] = $this->escapeFenceToken($language);
        }
        $header = $node->getHeader() ?? $node->getAttribute('title');
        if (is_string($header)) {
            $parts[] = $this->quotedTitleToken($node, $header);
        }
        $label = $node->getLabel();
        if ($label !== null) {
            $parts[] = '[' . $this->writeFlatBracketRun($label) . ']';
        }

        // NO SPACE between the fence run and the info string. `fenced_code_block`
        // names the slot OPTIONAL and the no-space form CANONICAL: "The no-space
        // form (```php) is canonical and is what the X->Carve converters emit."
        // The reader stays lenient and accepts both, which is why a single-pass
        // output check never caught this - ``` php re-parses to the same tree.
        //
        // The separators BETWEEN the parts are a different slot and stay: inside
        // `code_fence_info` they are `space+`, mandatory, so ```php"t" is not a
        // fence opener at all and joining without one would lose the header.
        return implode(' ', $parts);
    }

    protected function renderBlockQuote(BlockQuote $node): string
    {
        // Written back in the spelling it was read in
        // (markup-carve/carve#1718). Choosing structurally instead - the fence
        // whenever the quote holds a non-paragraph block - rewrites authored
        // multi-block quotes, so the node carries the author's choice.
        if ($node->isFenced()) {
            $fence = $this->colonFenceFor($node);

            return $fence . ' >' . self::fencedDivBody($this->renderColonFenceBody($node)) . $fence;
        }

        $inner = $this->withResetColonFenceDepth(fn (): string => $this->renderBlocks($node->getChildren()));
        $lines = explode("\n", $inner);

        $quoted = implode("\n", array_map(static fn (string $line): string => $line === '' ? '>' : '> ' . $line, $lines));

        if ($node->getRenderHint("\0carve-leading-blank") === '1') {
            $quoted = ">\n" . $quoted;
        }

        return $quoted;
    }

    protected function renderList(ListBlock $node): string
    {
        $this->listDepth++;
        try {
            $compactItems = $node->getRenderHint("\0carve-compact-items") === '1';
            $out = '';
            $counter = $node->getStart();
            // The marker is semantic (section 11: a different bullet char or
            // ordered delimiter starts a new list), so emit it as authored -
            // normalizing would merge adjacent sibling lists on re-parse
            // (carve issue 286). Absent markers fall back to `-` / `.`.
            $marker = $node->getMarker();
            $delim = $marker === ')' ? ')' : '.';
            $bullet = $marker === '*' ? '*' : '-';
            $bareDot = $node->getListType() === ListBlock::TYPE_ORDERED
                && $node->hasBareMarker()
                && $node->getStart() === 1
                && $node->getStyle() === null
                && $delim === '.';
            $children = array_values(array_filter($node->getChildren(), static fn (Node $child): bool => $child instanceof ListItem));
            foreach ($children as $index => $item) {
                // NO absolute depth term here. The parent item's continuation
                // prefix already IS the child list's indentation, so adding
                // `'  ' * (depth - 1)` on top indented every level twice, and
                // the two-space strip below was compensating for it. Output grew
                // as O(depth^3) where the source is O(depth^2) - 1720 bytes in,
                // 23040 out at depth 40 - and `05-lists-5` came back with four
                // spaces where it was written with two (carve-php#792). Same
                // defect and same fix as carve-js#653 and carve-rs#594.
                if ($node->getListType() === ListBlock::TYPE_ORDERED) {
                    $prefix = $bareDot
                        ? '. '
                        : $this->orderedMarker($counter, $node->getStyle()) . $delim . ' ';
                    $counter++;
                } elseif ($item->isTask()) {
                    $prefix = $bullet . ' ' . self::taskMarker($item) . ' ';
                } else {
                    $prefix = $bullet . ' ';
                }
                $continuationWidth = $node->getListType() === ListBlock::TYPE_ORDERED ? strlen($prefix) : 2;

                $itemAttrs = $this->renderAttrs($item);
                if ($itemAttrs !== '') {
                    $prefix = $node->getListType() === ListBlock::TYPE_ORDERED
                        ? rtrim($prefix) . $itemAttrs . ' '
                        : $bullet . $itemAttrs . ($item->isTask() ? ' ' . self::taskMarker($item) . ' ' : ' ');
                }

                $content = $this->trimNonNbsp($this->renderListItem($item, $node->isTight()));
                // A definition authored on an item's marker line is collected
                // into the document, leaving the item empty. Spell it back on
                // that same marker line. Using `+` for the empty item would
                // attach the following outer-item block to this inner item on
                // the next parse (carve-php#1492).
                if (
                    $content === ''
                    && $item->getChildren() === []
                    // Nested depth ONLY. At the top level the canonical form is
                    // `- +`, pinned by corpus fixtures 16-reference-link-4 and
                    // 117-footnote-definition-inside-a-container-is-collected-2, and it
                    // round-trips there because nothing follows at a shallower column.
                    && $this->listDepth > 1
                ) {
                    $line = $item->getPos()?->startLine;
                    $collected = $line === null ? null : ($this->definitionsByLine[$line] ?? null);
                    if ($collected !== null && !isset($this->definitionsWrittenInPlace[spl_object_id($collected)])) {
                        $this->definitionsWrittenInPlace[spl_object_id($collected)] = true;
                        $content = $collected instanceof Footnote
                            ? $this->renderFootnote($collected)
                            : $this->renderLinkReferenceDefinition($collected);
                    }
                }
                $lines = $content === '' ? [''] : explode("\n", $content);
                $first = array_shift($lines);
                $out .= $prefix . ($first === '' ? '+' : $first) . "\n";
                $continuation = str_repeat(' ', $continuationWidth);
                foreach ($lines as $line) {
                    // A BLANK continuation line stays blank: indenting it emits a
                    // whitespace-only line, which the writer never may
                    // (NoWhitespaceOnlyLineTest).
                    //
                    // The U+E003 form is the one that actually reaches here. A
                    // blank line inside a fenced code block in a list item is
                    // verbatim content, so protectVerbatim() encodes it to keep
                    // the document-wide trim off it; indenting that placeholder
                    // left `  ` behind once restoreVerbatim() mapped it back to
                    // nothing. The blank is content, but it is BLANK - the indent
                    // was trailing whitespace the source never had.
                    //
                    // A code line that genuinely holds spaces arrives as those
                    // spaces (U+E001), not as this placeholder, and still indents.
                    $blank = $this->isBlankContinuationLine($line);
                    if (!$blank && str_starts_with($line, $this->markerColumn())) {
                        // The continuation marker and the block it attaches sit
                        // at the ITEM's marker column, not its content column
                        // (§17 L3). Indenting either is what made the attached
                        // paragraph fold (carve#861).
                        $out .= substr($line, strlen($this->markerColumn())) . "\n";

                        continue;
                    }
                    $out .= $blank ? $line . "\n" : $continuation . $line . "\n";
                }
                if (!$compactItems && !$node->isTight() && $index < count($children) - 1) {
                    $out .= "\n";
                }
            }

            return $this->trimEndNonNbsp($out);
        } finally {
            $this->listDepth--;
        }
    }

    /**
     * A continuation line is BLANK when it has no content of its own.
     *
     * Two spellings reach a writer and both mean the same thing. `''` is an
     * ordinary blank line. The SENTINEL form is the one that actually bites: a
     * blank line inside a fenced code block, a raw block or a block comment is
     * VERBATIM content, so protectVerbatim() encodes it to keep the
     * document-wide trim off it, and restoreVerbatim() maps it back to nothing
     * at the very end - after every container has already prefixed it with its
     * own indent. What is left is a line holding the indent and nothing else.
     *
     * A verbatim line that genuinely holds spaces arrives as those spaces under
     * a DIFFERENT sentinel and still indents; only the empty one is blank.
     */
    protected function isBlankContinuationLine(string $line): bool
    {
        return $line === '' || $line === $this->verbatimSentinels[2];
    }

    /**
     * `$line` at `$indent`, except a blank one, which stays blank.
     */
    protected function indentContinuationLine(string $line, string $indent): string
    {
        return $this->isBlankContinuationLine($line) ? $line : $indent . $line;
    }

    /**
     * Sentinel marking a line to be written at the ITEM's marker column.
     */
    protected function markerColumn(): string
    {
        return $this->verbatimSentinels[5];
    }

    /**
     * Whether `$node`'s canonical source is a bare inline run on its own line,
     * so at a container's content column it CONTINUES an open paragraph instead
     * of opening a block of its own.
     *
     * Derived by sweeping twenty-two block constructs rather than by reasoning
     * about them: a `figure` is an image line plus a caption line and an `image`
     * is the image line alone, which is why both read as paragraph text one
     * column in.
     */
    protected function foldsIntoAnOpenParagraph(Node $node): bool
    {
        return $node instanceof Paragraph || $node instanceof Image || $node instanceof Figure;
    }

    /**
     * Whether this block leaves a PARAGRAPH OPEN on its last line, so a line
     * written below it at the same column is read as its continuation rather
     * than as a block of its own.
     *
     * The other half of foldsIntoAnOpenParagraph()'s question: not "does this
     * block fold INTO an open paragraph" but "does it leave one open BELOW it".
     * The first three members are the same three, for the same reason - their
     * canonical source IS a bare inline run on its own line. A definition list
     * joins them because its last description ends in one too.
     *
     * EACH MEMBER IS LOAD-BEARING, not carried along for symmetry: in an item
     * holding a sub-list, a table, one of these four blocks and a second
     * sub-list, that second sub-list is lost without the blank line. A heading,
     * fence, table, break, div, admonition and a sub-list with a different
     * marker close at their last line and owe the block under them nothing.
     */
    protected function leavesAParagraphOpen(Node $node): bool
    {
        return $this->foldsIntoAnOpenParagraph($node) || $node instanceof DefinitionList;
    }

    /**
     * Whether a sub-list written at the item's content column needs a blank line
     * above it to open at all.
     */
    protected function needsABlankLineAbove(
        ?Node $previousEmitted,
        bool $previousAtMarkerColumn,
        bool $aSubListAlreadyOpened,
    ): bool {
        if ($previousAtMarkerColumn) {
            return true;
        }
        if ($previousEmitted === null) {
            return false;
        }
        if ($previousEmitted instanceof BlockQuote) {
            return true;
        }

        return $aSubListAlreadyOpened && $this->leavesAParagraphOpen($previousEmitted);
    }

    /**
     * Whether this block's WRITTEN form is a bare `%%` line.
     *
     * The predicate is about the block BELOW a sub-list, not about the
     * sub-list: leavesAParagraphOpen() answers the neighbouring question for
     * the paragraph-shaped blocks and is the wrong tool here, because a comment
     * does not fold into an open paragraph - it is re-owned by an open LIST.
     * The fence form (`%%%`) and the delimited form are excluded: a fence
     * opener closes the sub-list above it on its own.
     */
    protected function isALineComment(Node $node): bool
    {
        return $node instanceof Comment
            && !$node->isDelimited()
            && $node->getFenceLength() === null
            && !str_contains($node->getContent(), "\n");
    }

    /**
     * Whether the WRITTEN form of a block opens with a block-attributes line.
     */
    protected function opensWithAnAttributeLine(string $rendered): bool
    {
        $first = explode("\n", $rendered, 2)[0];

        return (bool)preg_match('/^\{.*\}$/', $first);
    }

    protected function adjacentBlocksMerge(Node $left, Node $right): bool
    {
        if ($left::class !== $right::class) {
            return false;
        }
        if ($left instanceof ListBlock && $right instanceof ListBlock) {
            return $left->getListType() === $right->getListType()
                && $left->getMarker() === $right->getMarker()
                && $left->getStyle() === $right->getStyle();
        }

        return $left instanceof BlockQuote
            || $left instanceof Table
            || $left instanceof LineBlock
            || $left instanceof DefinitionList;
    }

    protected function atMarkerColumn(string $text): string
    {
        return implode("\n", array_map(
            fn (string $line): string => $this->markerColumn() . $line,
            explode("\n", $text),
        ));
    }

    protected function renderListItem(ListItem $node, bool $tight = false): string
    {
        $children = $node->getChildren();
        if (!$tight || count($children) < 2) {
            return $this->withResetColonFenceDepth(fn (): string => $this->renderBlocks($children));
        }

        // A tight item with more than one child block must not gain a blank line
        // between its blocks - a blank there loosens the item on re-parse, so
        // toHtml(fmt(x)) would diverge from toHtml(x) (carve corpus 162).
        // Adjacent blocks are joined with a single newline instead, matching
        // the canonical carve-js writer.
        if ($this->blockDepth >= self::MAX_RENDER_DEPTH) {
            throw new RenderDepthExceededException(self::MAX_RENDER_DEPTH, 'Carve');
        }

        $previousColonFenceDepth = $this->colonFenceDepth;
        $this->colonFenceDepth = 0;
        $this->blockDepth++;
        try {
            $out = '';
            $previous = null;
            // Whether any child so far was written at the item's MARKER column,
            // which is column 0. Everything after it has to sit there too - see
            // below - so this only ever latches on.
            $atMarkerColumn = false;
            // The last child that actually WROTE something, which is what the
            // block below it is read against. `$previous` is not that: a
            // definition hoisted out of the item renders nothing and still sits
            // in the children.
            $previousEmitted = null;
            // Whether a sub-list has already opened at this item's content
            // column - the condition under which a later bullet written there
            // joins it instead of opening below the paragraph above it. See
            // needsABlankLineAbove().
            $aSubListAlreadyOpened = false;
            foreach ($children as $index => $child) {
                $next = $children[$index + 1] ?? null;
                // A definition the author wrote BETWEEN these two blocks was
                // collected out of the item, and the gap it left is what split
                // one paragraph into two. Dropping the line would rejoin them,
                // so it is written back where it was (carve#805).
                $separated = false;
                if ($previous !== null) {
                    $written = $this->definitionInGap($previous, $child);
                    if ($written !== null && $written !== '') {
                        if ($out !== '') {
                            $out .= "\n";
                        }
                        $out .= $written;
                        $separated = true;
                    }
                }
                // A list item's content column is an authored block base just
                // like a definition description's or footnote's.  Let a
                // definition list among its direct children raise its opener
                // when its payload would otherwise be re-owned by the item.
                $rendered = $this->atAnAuthoredBodyColumn(
                    fn (): string => $this->renderBlock($child),
                );
                if ($rendered === '') {
                    $previous = $child;

                    continue;
                }
                if ($out !== '') {
                    $out .= "\n";
                }
                if ($child instanceof ListBlock) {
                    if (!$separated && $previousEmitted !== null && $this->adjacentBlocksMerge($previousEmitted, $child)) {
                        $out .= $this->listBoundary() . $rendered;
                    } elseif (
                        !$separated
                        && $this->needsABlankLineAbove($previousEmitted, $atMarkerColumn, $aSubListAlreadyOpened)
                    ) {
                        $out .= "\n" . $rendered;
                    } else {
                        $out .= $rendered;
                    }
                    // Back at the content column, so a child below this one is
                    // read against the list rather than against whatever stood
                    // at column 0 above it.
                    $atMarkerColumn = false;
                    $aSubListAlreadyOpened = true;
                    $previous = $child;
                    $previousEmitted = $child;

                    continue;
                }
                if (
                    $atMarkerColumn
                    // `+` CONTINUES the marker line; it is not a block of its
                    // own. Looking ahead at a mergeable next sibling is right
                    // only once something stands there to continue - as the
                    // item's FIRST child the `+` becomes the item's whole
                    // content and both halves of the pair are written at column
                    // 0, escaping the item (carve-php#1950).
                    || ($out !== '' && $next !== null && $this->adjacentBlocksMerge($child, $next))
                    // The pair still has to be parted when it opens the item,
                    // so that half of the question asks about the PRECEDING
                    // sibling - which by then IS on the marker line. Below any
                    // other child this adds nothing: the lookahead above has
                    // already latched $atMarkerColumn.
                    || ($previousEmitted !== null && $this->adjacentBlocksMerge($previousEmitted, $child))
                    || (
                        !$separated
                        && $previous instanceof Paragraph
                        && $this->foldsIntoAnOpenParagraph($child)
                        && !$this->opensWithAnAttributeLine($rendered)
                    )
                ) {
                    $out .= $this->atMarkerColumn('+') . "\n" . $this->atMarkerColumn($rendered);
                    $previous = $child;
                    $previousEmitted = $child;
                    $atMarkerColumn = true;

                    continue;
                }
                // A line comment opens no container of its own, so at the
                // item's content column - which IS the marker column of a
                // sub-list standing above it - a re-parse reads it into that
                // sub-list's last item instead of into this one, and the next
                // writer pass spells it at the deeper column. The blank line
                // closes the sub-list; a comment spells no paragraph for the
                // blank to part, so the item stays tight and the HTML is
                // unchanged (carve-php#1948).
                if (!$separated && $previousEmitted instanceof ListBlock && $this->isALineComment($child)) {
                    $out .= "\n";
                }
                $previous = $child;
                $previousEmitted = $child;
                $out .= $rendered;
            }

            return $out;
        } finally {
            $this->blockDepth--;
            $this->colonFenceDepth = $previousColonFenceDepth;
        }
    }

    /**
     * The definition the author wrote on a line strictly between two blocks.
     *
     * Collecting it emptied the line, and an emptied line is a blank one: the
     * blocks it separated re-parse as a single paragraph, which is a different
     * document (carve#805, corpus 228). The description write-back finds its
     * definition by the description's own line; here there is no node left to
     * carry it, so the gap between the neighbours names it instead.
     */
    protected function definitionInGap(Node $before, Node $after): ?string
    {
        $from = $before->getPos()?->endLine;
        $to = $after->getPos()?->startLine;
        if ($from === null || $to === null) {
            return null;
        }

        foreach ($this->definitionsByLine as $line => $node) {
            if ($line <= $from || $line >= $to) {
                continue;
            }
            if (isset($this->definitionsWrittenInPlace[spl_object_id($node)])) {
                continue;
            }
            $this->definitionsWrittenInPlace[spl_object_id($node)] = true;

            return $node instanceof Footnote
                ? $this->renderFootnote($node)
                : $this->renderLinkReferenceDefinition($node);
        }

        return null;
    }

    protected function orderedMarker(int $n, ?string $type): string
    {
        return match ($type) {
            'a' => chr((($n - 1) % 26) + 97),
            'A' => chr((($n - 1) % 26) + 65),
            'i' => strtolower($this->romanMarker($n)),
            'I' => $this->romanMarker($n),
            default => (string)$n,
        };
    }

    protected function romanMarker(int $n): string
    {
        $values = [[1000, 'M'], [900, 'CM'], [500, 'D'], [400, 'CD'], [100, 'C'], [90, 'XC'], [50, 'L'], [40, 'XL'], [10, 'X'], [9, 'IX'], [5, 'V'], [4, 'IV'], [1, 'I']];
        $out = '';
        foreach ($values as [$value, $token]) {
            while ($n >= $value) {
                $out .= $token;
                $n -= $value;
            }
        }

        return $out === '' ? 'I' : $out;
    }

    /**
     * Join a colon fence's body to its closer.
     *
     * An EMPTY body is written as a BLANK LINE, for every container shape
     * including the bare `:::` div (markup-carve/carve#961 ruling 1). PART 10
     * §4 already settled the same question for the HTML target and chose the
     * blank line; this follows that sibling clause rather than inventing a
     * second rule, and deliberately does NOT import §4's bare-div exception,
     * which §4 itself says "has no principle behind it".
     */
    private static function fencedDivBody(string $body): string
    {
        return $body === '' ? "\n\n" : "\n" . $body . "\n";
    }

    protected function renderDiv(Div $node): string
    {
        $label = $node->getLabel() === null ? '' : ' [' . $this->writeFlatBracketRun($node->getLabel()) . ']';
        $fence = $this->colonFenceFor($node);
        $body = $this->renderColonFenceBody($node);

        return $fence . $label . self::fencedDivBody($body) . $fence;
    }

    protected function canRenderTypedDiv(Div $node): bool
    {
        // Only the OPENER class decides. Requiring exactly one class meant a
        // class-carrying attribute line above a typed custom div (`{.sidebar}`
        // + `::: widget "Title"`) fell through to the untyped writer, which
        // has no title slot - so the quoted title was dropped and one fmt pass
        // changed the rendered HTML (carve-php#1284). Extra classes are the
        // attribute line's business; withFencedDivAttrs() already writes them
        // back there with the opener excluded.
        $classes = $node->getClassList();

        return $classes !== []
            && $classes[0] !== 'line-block'
            && preg_match('/^[A-Za-z0-9_][\w-]*$/', $classes[0]) === 1;
    }

    protected function renderTypedDiv(Div $node): string
    {
        $classes = $node->getClassList();
        $kind = $classes[0] ?? '';
        $title = $node->getHeader();
        $titlePart = is_string($title) ? ' ' . $this->quotedTitleToken($node, $title) : '';
        $label = $node->getLabel() === null ? '' : ' [' . $this->writeFlatBracketRun($node->getLabel()) . ']';
        $fence = $this->colonFenceFor($node);
        $body = $this->renderColonFenceBody($node);

        return $fence . ' ' . $kind . $titlePart . $label . self::fencedDivBody($body) . $fence;
    }

    protected function renderAdmonition(Div $node): string
    {
        $kind = $this->admonitionKind($node) ?? 'note';
        $title = $node->getHeader();
        $titlePart = is_string($title) ? ' ' . $this->quotedTitleToken($node, $title) : '';
        $label = $node->getLabel() === null ? '' : ' [' . $this->writeFlatBracketRun($node->getLabel()) . ']';
        $fence = $this->colonFenceFor($node);
        $body = $this->renderColonFenceBody($node);

        return $fence . ' ' . $kind . $titlePart . $label . self::fencedDivBody($body) . $fence;
    }

    protected function admonitionKind(Div $node): ?string
    {
        foreach ($node->getClassList() as $class) {
            if (in_array($class, self::ADMONITION_TYPES, true)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Block\Div $node
     * @param array<string> $structuralClasses
     * @param string $body
     */
    protected function withFencedDivAttrs(Div $node, array $structuralClasses, string $body): string
    {
        $attrs = $this->renderFencedDivAttrs($node, $structuralClasses);

        return $attrs === '' ? $body : $attrs . "\n" . $body;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Block\Div $node
     * @param array<string> $structuralClasses
     */
    protected function renderFencedDivAttrs(Div $node, array $structuralClasses): string
    {
        if ($node->getAttributes() === []) {
            return '';
        }

        $attrs = $node->getAttributes();
        $structural = array_flip($structuralClasses);
        $parts = [];
        $seen = [];
        $emit = function (string $slot) use (&$parts, &$seen, $attrs, $structural): void {
            if ($slot === '#id') {
                if (isset($seen['id']) || !array_key_exists('id', $attrs)) {
                    return;
                }
                $seen['id'] = true;
                $id = $attrs['id'];
                $parts[] = $this->isExplicitIdOrClassIdentifier($id) ? '#' . $this->escapeAttrNameValue($id) : 'id=' . $this->quoteAttrValue($id);

                return;
            }
            if ($slot === '.class') {
                foreach (preg_split('/\s+/', trim($attrs['class'] ?? '')) ?: [] as $class) {
                    if ($class !== '' && !isset($structural[$class])) {
                        $parts[] = '.' . $this->escapeAttrNameValue($class);
                    }
                }

                return;
            }
            if (isset($seen[$slot]) || !array_key_exists($slot, $attrs) || $slot === 'id' || $slot === 'class') {
                return;
            }
            $seen[$slot] = true;
            $value = $attrs[$slot];
            // EXACT key match, not case-insensitive: `LANG` and `lang` are
            // different attribute names, so folding here rewrote
            // `[x]{LANG=fr}` into `[x]{:fr}` and changed the name, which
            // breaks PART 11 SS1 (carve#1137).
            if ($slot === 'lang' && ($value === '' || preg_match('/^[A-Za-z0-9]{1,8}(?:-[A-Za-z0-9]{1,8})*$/D', $value) === 1)) {
                $parts[] = ':' . $value;
            } elseif ($value === '' && $this->isBooleanAttrName($slot)) {
                // PART 11 SS6c: a value-less attribute comes back as the bare
                // name, which is the production the language has for it. A key
                // needing escaping has no bare spelling to fall back to, and
                // neither does a `_`-first name: `boolean_attribute` refuses the
                // leading underscore (carve#1450), so `{_u=""}` written bare is
                // text and `{_x_=""}` is a forced underline. Either way the
                // writer would change the document, which PART 11 SS1 forbids.
                $parts[] = $this->escapeAttrKey($slot);
            } else {
                $parts[] = $this->escapeAttrKey($slot) . '=' . $this->quoteAttrValue($value);
            }
        };

        $order = $node->getAttributeOrder();
        if ($order !== []) {
            foreach ($order as $slot) {
                $emit($slot);
            }
            foreach ($attrs as $key => $_value) {
                // An id with no `#id` slot is a GENERATED one - since carve#750
                // a heading's slugged id is on the wire, so a decoded node
                // carries it - and a writer reproduces what the author wrote.
                // A generated id was not written by the author.
                if ((string)$key === 'id' && !in_array('#id', $order, true)) {
                    continue;
                }
                $emit((string)$key);
            }
        } else {
            // NO SLOTS AT ALL. An `id` here is a GENERATED one - since carve#750
            // a heading's slugged id is published, and an AUTHORED id always
            // carries its `#id` slot - so emitting it writes `{#Welcome}` above
            // a heading whose source has no attribute block. A programmatic
            // tree that wants the id in the source records the slot.
            if (!array_key_exists('id', $attrs)) {
                $emit('#id');
            }
            $emit('.class');
            foreach ($attrs as $key => $_value) {
                $emit((string)$key);
            }
        }

        return $parts === [] ? '' : '{' . implode(' ', $parts) . '}';
    }

    protected function renderLineBlock(LineBlock $node): string
    {
        // Inside a line block every newline IS a hard break (grammar PART 3,
        // line_block_body), so the explicit backslash the inline writer emits
        // for a HardBreak would double it on re-parse.
        $this->inLineBlock++;

        try {
            $body = $this->renderColonFenceBody($node);
        } finally {
            $this->inLineBlock--;
        }

        // `::: |` is the line-block opener (grammar PART 3, line_block_open).
        // Emitting a bare `:::` and tagging the node with a `line-block` class
        // instead re-parsed as an ordinary div, so the node type changed across
        // a format round trip and `parse(fmt(x)) == parse(x)` did not hold.
        $fence = $this->colonFenceFor($node);

        return $fence . " |\n" . $body . "\n" . $fence;
    }

    protected function colonFenceFor(Node $node): string
    {
        return str_repeat(':', 3 + $this->colonFenceDepth);
    }

    protected function renderColonFenceBody(Node $node): string
    {
        $this->colonFenceDepth++;
        try {
            return $this->renderBlocks($node->getChildren());
        } finally {
            $this->colonFenceDepth--;
        }
    }

    protected function withResetColonFenceDepth(callable $render): string
    {
        $previous = $this->colonFenceDepth;
        $this->colonFenceDepth = 0;
        try {
            return $render();
        } finally {
            $this->colonFenceDepth = $previous;
        }
    }

    /**
     * Render a body that gives a definition entry its own authored base - a
     * footnote body or a definition description - so a definition list among
     * its DIRECT children can take that base one column in.
     *
     * @param callable(): string $render
     *
     * @return string
     */
    protected function atAnAuthoredBodyColumn(callable $render): string
    {
        $previous = $this->atAnAuthoredBodyColumn;
        $this->atAnAuthoredBodyColumn = true;
        try {
            return $render();
        } finally {
            $this->atAnAuthoredBodyColumn = $previous;
        }
    }

    /**
     * One extra column, when a definition list's payload needs a base of its own.
     *
     * @param string $rendered
     * @param bool $atAnAuthoredBodyColumn
     *
     * @return string
     */
    protected function atARaisedBase(
        string $rendered,
        bool $atAnAuthoredBodyColumn,
    ): string {
        if (
            !$atAnAuthoredBodyColumn
            || !$this->bodyRebaseWouldMoveALine($rendered)
        ) {
            return $rendered;
        }

        // A BLANK LINE STAYS BLANK. Indenting it produces a line of nothing but
        // spaces, which this writer never emits.
        $raised = array_map(
            static fn (string $line): string => $line === '' ? $line : self::ENTRY_RAISE_INDENT . $line,
            explode("\n", $rendered),
        );

        // AT A RAISED BASE A BLANK LINE ENDS THE DESCRIPTION. The raise exists
        // because the payload has to stay inside the `dd`; a blank written above
        // that payload hands it back to the host as a sibling, which is the same
        // loss the raise was applied to avoid.
        //
        // ONLY THE BLANK ABOVE A RECOGNIZED OPENER. A blank above ordinary
        // payload text is a PARAGRAPH BREAK and carries its own meaning -
        // dropping it merges two paragraphs of a `<dd>`, which is a loss of
        // exactly the kind this method exists to prevent, and one that a list
        // with two entries would reach whenever any single entry asks for the
        // raise. So the question is asked per candidate line, and it is THE SAME
        // question the raise itself is gated on rather than a second spelling of
        // it: the rebase over the line at its description-relative column, with
        // the raise taken back off.
        //
        // A blank between two ENTRIES never reaches the test: its next line sits
        // at the entry column, not past it.
        $raise = strlen(self::ENTRY_RAISE_INDENT);
        $kept = [];
        $count = count($raised);
        for ($i = 0; $i < $count; $i++) {
            $line = $raised[$i];
            if (trim($line) === '') {
                $j = $i + 1;
                while ($j < $count && trim($raised[$j]) === '') {
                    $j++;
                }
                // A BLANK RUN AT THE END OF THE LIST HAS NOTHING BELOW IT TO
                // KEEP, and the loop bound is the whole guard: `$j` reaches
                // `$count` only where the run ran off the end. Read as an empty
                // line there, which sits at column 0 and fails the test below
                // like any line at the entry column.
                $next = $j < $count ? $raised[$j] : '';
                if (
                    self::leadingSpaces($next) > $raise
                    && $this->bodyRebaseWouldMoveALine(substr($next, $raise))
                ) {
                    $i = $j - 1;

                    continue;
                }
            }
            $kept[] = $line;
        }

        return implode("\n", $kept);
    }

    /**
     * Leading spaces of a line, for comparing a payload against its entry column.
     *
     * @param string $line
     *
     * @return int
     */
    protected static function leadingSpaces(string $line): int
    {
        return strlen($line) - strlen(ltrim($line, ' '));
    }

    /**
     * Would a body's rebase pass move a line of `$rendered`?
     *
     * Asked of the PARSER, so the writer and the reader cannot drift: the reader
     * owns the column rule and the writer asks it rather than restating it.
     *
     * @param string $rendered
     *
     * @return bool
     */
    protected function bodyRebaseWouldMoveALine(string $rendered): bool
    {
        // ONE instance for the whole render. The pass reads its sub-parsers and
        // writes no parser state, so the answer does not depend on what was
        // asked before it - and the raise asks once per candidate blank line on
        // top of once per list, which is enough to make five sub-parsers per
        // question worth not building.
        $this->rebaseProbe ??= new BlockParser();

        return $this->rebaseProbe->bodyRebaseWouldMoveALine($rendered);
    }

    protected function renderDefinitionList(DefinitionList $node): string
    {
        $out = [];
        // Every entry writes its own description line, so consecutive `::`
        // lines never end up sharing one: the list writes back with the
        // grouping it parsed from.
        foreach ($node->getChildren() as $child) {
            if ($child instanceof DefinitionTerm) {
                $out[] = ':: ' . $this->renderInlines($child->getChildren());
            } elseif ($child instanceof DefinitionDescription) {
                // An EMPTY description whose line carries a collected definition
                // is one the author wrote that definition on: write it back
                // there. Without this the line came out as a bare `:`, which
                // re-parses into the term above it (carve#805).
                $line = $child->getChildren() === [] ? $child->getPos()?->startLine : null;
                $collected = $line === null ? null : ($this->definitionsByLine[$line] ?? null);
                if ($collected !== null) {
                    $this->definitionsWrittenInPlace[spl_object_id($collected)] = true;
                    $written = $collected instanceof Footnote
                        ? $this->renderFootnote($collected)
                        : $this->renderLinkReferenceDefinition($collected);
                    // A note's own continuation lines move right by the marker
                    // too. Left at the note's standalone column they fell one
                    // marker short of the description body, so on re-parse the
                    // line dropped out of the note and the empty `dd` filled
                    // back in (carve#1980).
                    $writtenLines = explode("\n", $written);
                    $out[] = self::DEFINITION_BODY_MARKER . array_shift($writtenLines);
                    foreach ($writtenLines as $writtenLine) {
                        $out[] = $this->indentContinuationLine($writtenLine, self::DEFINITION_BODY_INDENT);
                    }

                    continue;
                }
                $body = $this->atAnAuthoredBodyColumn(
                    fn (): string => $this->withResetColonFenceDepth(
                        fn (): string => $this->renderBlocks($child->getChildren()),
                    ),
                );
                $body = $this->trimNonNbsp($body);
                if ($body === '') {
                    $out[] = self::DEFINITION_BODY_MARKER . self::EMPTY_BODY_SENTINEL;

                    continue;
                }

                $lines = explode("\n", $body);
                $out[] = self::DEFINITION_BODY_MARKER . array_shift($lines);
                foreach ($lines as $line) {
                    $out[] = $this->indentContinuationLine($line, self::DEFINITION_BODY_INDENT);
                }
            }
        }

        return implode("\n", $out);
    }

    /**
     * @throws \MarkupCarve\Carve\Exception\SourceUnspellableException
     */
    protected function renderTable(Table $node): string
    {
        $this->recordUnspellableTableSectionAttributes($node);

        $rows = [];
        $tableRows = array_values(array_filter($node->getChildren(), static fn (Node $child): bool => $child instanceof TableRow));
        $columnWidths = $node->getRenderHint("\0carve-col-widths") !== null
            ? array_map(
                static fn (string $width): int => (int)$width > 0 ? min(1000, (int)$width) : 3,
                explode(',', (string)$node->getRenderHint("\0carve-col-widths")),
            )
            : [];
        $forceDelimiter = $node->getRenderHint("\0carve-delimiter-row") === '1' || $columnWidths !== [];
        $headerRow = isset($tableRows[0]) && ($tableRows[0]->isHeader() || $forceDelimiter);
        // This parser resolves a cell's alignment at parse time, so a body cell
        // carries the column's alignment even when the author only wrote it on
        // the header. carve-js and carve-rs keep the author's own marker and
        // resolve at render, and their AST is the one the writer can reproduce.
        // Until the three agree (carve#361), suppress the marker on a body cell
        // that merely inherited it: the emitted source then matches the other
        // two engines byte for byte, and re-parsing it here restores the same
        // resolved alignment.
        $headerAligns = [];
        $headerValigns = [];
        if ($headerRow) {
            $headerColumn = 0;
            foreach ($tableRows[0]->getChildren() as $cell) {
                if ($cell instanceof TableCell) {
                    $headerAligns[$headerColumn] = $cell->getAlignment();
                    $headerValigns[$headerColumn] = $cell->getVerticalAlignment();
                    $headerColumn++;
                }
            }
        }
        // A colspan cell is always written plain (`| < |`), so a header row can
        // keep the native `|=` form when its span markers form a TRAILING run
        // of colspans after at least one real header cell: each `<` absorbs into
        // the `|=` header on its left, and the row is still promoted by those
        // markers. Every other span shape needs a delimiter row: a leading span
        // has no `|=` anchor before it, a real cell after a span would have to
        // be written `|=< K` (an aligned header, not the promoted data cell),
        // and a trailing rowspan (`^`) does not absorb left, so a first-row
        // `| ^ |` is not a header cell and the row falls out of the header.
        $needsDelimiter = $forceDelimiter;
        if ($headerRow) {
            $headerCellsRow = array_values(array_filter(
                $tableRows[0]->getChildren(),
                static fn (Node $child): bool => $child instanceof TableCell,
            ));
            $firstSpan = -1;
            foreach ($headerCellsRow as $index => $cell) {
                if ($cell->getSpanMarker() !== null) {
                    $firstSpan = $index;

                    break;
                }
            }
            if ($firstSpan >= 0) {
                $trailingColspansOnly = $firstSpan >= 1;
                $headerCellCount = count($headerCellsRow);
                for ($column = $firstSpan; $column < $headerCellCount; $column++) {
                    // '<' is the colspan marker (rowspan is '^').
                    if ($headerCellsRow[$column]->getSpanMarker() !== '<') {
                        $trailingColspansOnly = false;

                        break;
                    }
                }
                $needsDelimiter = $needsDelimiter || !$trailingColspansOnly;
            }
        }
        foreach ($tableRows as $rowIndex => $row) {
            $cells = [];
            $column = 0;
            foreach ($row->getChildren() as $cell) {
                if (!$cell instanceof TableCell) {
                    continue;
                }
                // In the delimiter form the promoted row is written as ordinary
                // data cells - the row after it is what makes them headers.
                $markHeader = !($needsDelimiter && $rowIndex === 0);
                $inherited = ($needsDelimiter && $rowIndex === 0)
                    || ($headerRow
                        && $rowIndex > 0
                        && !$cell->hasExplicitAlignment()
                        && ($headerAligns[$column] ?? null) === $cell->getAlignment());
                $inheritedVertical = $headerRow
                    && $rowIndex > 0
                    && !$cell->hasExplicitVerticalAlignment()
                    && ($headerValigns[$column] ?? null) === $cell->getVerticalAlignment();
                $cells[] = $this->renderTableCell($cell, $markHeader, $inherited, $inheritedVertical);
                $column++;
            }
            // A row whose every cell is blank is not a table row (markup-carve/carve#1954).
            if ($cells !== [] && array_diff($cells, [' ', '= ']) === []) {
                throw new SourceUnspellableException('table_row', 'a table row whose every cell is blank has no Carve source spelling');
            }
            $rows[] = $this->renderTableRow($cells, $this->renderAttrs($row));
        }
        if ($needsDelimiter) {
            $headerCells = array_values(array_filter(
                $tableRows[0]->getChildren(),
                static fn (Node $child): bool => $child instanceof TableCell,
            ));
            $separators = [];
            foreach ($headerCells as $index => $cell) {
                $width = $columnWidths[$index] ?? 3;
                $separators[] = $this->renderTableSeparator($width, $cell->getAlignment());
            }
            array_splice($rows, 1, 0, '|' . implode('|', $separators !== [] ? $separators : ['---']) . '|');
        }
        if ($node->hasCaption()) {
            $caption = $node->getCaption();
            if ($caption !== null) {
                $rows[] = '^ ' . $this->renderInlines($caption->getChildren());
            }
        }

        return implode("\n", $rows);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Block\Table $node
     * @param \Closure(string): string $withAttrs
     */
    protected function renderTableWithAttrs(Table $node, Closure $withAttrs): string
    {
        $body = $withAttrs($this->renderTable($node));
        $encoded = $node->getRenderHint("\0carve-prefix-attrs");
        if ($encoded === null || $encoded === '') {
            return $body;
        }
        $structured = json_decode($encoded, true);
        if (!is_array($structured)) {
            return $body;
        }
        $attrs = is_array($structured['keyValues'] ?? null)
            ? array_filter($structured['keyValues'], 'is_string')
            : [];
        if (is_string($structured['id'] ?? null)) {
            $attrs['id'] = $structured['id'];
        }
        $classes = is_array($structured['classes'] ?? null)
            ? array_values(array_filter($structured['classes'], 'is_string'))
            : [];
        if ($classes !== []) {
            $attrs['class'] = implode(' ', $classes);
        }
        $order = is_array($structured['order'] ?? null)
            ? array_values(array_filter($structured['order'], 'is_string'))
            : [];
        $prefix = $this->renderAttrList($attrs, $order);

        return $prefix === '' ? $body : $prefix . "\n" . $body;
    }

    protected function renderTableSeparator(int $width, string $alignment): string
    {
        return match ($alignment) {
            TableCell::ALIGN_LEFT => ':' . str_repeat('-', max(2, $width)),
            TableCell::ALIGN_RIGHT => str_repeat('-', max(2, $width)) . ':',
            TableCell::ALIGN_CENTER => ':' . str_repeat('-', max(1, $width)) . ':',
            default => str_repeat('-', max(2, $width)),
        };
    }

    /**
     * @param list<string> $cells Rendered cells, each already padded.
     * @param string $attrs Row attributes.
     */
    protected function renderTableRow(array $cells, string $attrs): string
    {
        return '|' . implode('|', $cells) . '|' . $attrs;
    }

    /**
     * A cell's written form: its PREFIX glued to the opening pipe, then one
     * space, then the content, then one space before the closing pipe.
     *
     * The prefix has to touch the pipe - a space in front of `=` or of an
     * attribute block makes it literal content - but the CONTENT does not, and
     * the padded form is the readable one. It is also the safe one: the
     * alignment scan runs right after `|` or `|=` off the UNTRIMMED cell, so a
     * glued content sigil was read as a marker nobody wrote. That used to be a
     * guard listing the characters that merge; the space covers every cell.
     *
     * An EMPTY cell takes a single space, not two, so a column does not grow a
     * space each time the document is formatted.
     */
    protected function padCell(string $prefix, string $content): string
    {
        if ($content === '') {
            return $prefix . ' ';
        }

        return $prefix . ' ' . $content . ' ';
    }

    protected function renderTableCell(
        TableCell $cell,
        bool $markHeader = true,
        bool $inheritedAlign = false,
        bool $inheritedValign = false,
    ): string {
        if ($cell->hasBlockContent()) {
            $this->recordUnspellableField($cell, 'blocks', 'Carve table cells cannot hold blocks');
        }
        $attrs = $this->renderAttrs($cell);
        // A lone span marker keeps a SPACE before it. Glued to the opening pipe,
        // `<` is also the left-alignment sigil, and the two readings differ: the
        // executable spec reads `|<|` as alignment on an empty cell where all
        // three engines read a colspan (carve#710). `alignment_marker` is
        // defined as glued and `colspan_marker` may carry surrounding
        // whitespace, so the padded form means the same thing to every reader
        // and the writer must not emit the ambiguous one. `^` is not an
        // alignment sigil, but takes the same shape so a row of span cells stays
        // readable.
        //
        // A cell attribute block is GLUED to the pipe here, because a span cell
        // has no marker run for it to bind after (PART 9 §5 T10); the space
        // goes between it and the span marker.
        if ($cell->getSpanMarker() !== null) {
            return $this->padCell($attrs, $cell->getSpanMarker());
        }
        $align = $inheritedAlign ? '' : $this->alignMarker($cell->getAlignment());
        $valign = $inheritedValign ? '' : match ($cell->getVerticalAlignment()) {
            TableCell::VALIGN_TOP => '^',
            TableCell::VALIGN_MIDDLE => '~',
            TableCell::VALIGN_BOTTOM => 'v',
            default => '',
        };
        $inheritHorizontal = $align === '' && $valign !== '' ? '?' : '';
        // MARKER RUN FIRST, BLOCK LAST (PART 9 §5 T10). Writing the block ahead
        // of the `=` produced `|{#x}=R|`, which every reader takes as a DATA
        // cell whose content starts with `=`, so a `<th id="x">R</th>` came back
        // as `<td id="x">=R</td>` and PART 11 §1 failed on it. This order is
        // meaning-preserving instead: `|={#x} R |` parses back to the node that
        // was written.
        $prefix = ($cell->isHeader() && $markHeader ? '=' : '') . $align . $inheritHorizontal . $valign . $attrs;

        $inlines = $cell->hasBlockContent()
            ? TableCellBlockFlattener::flatten($cell)->getChildren()
            : $cell->getChildren();
        $previousFlattenedCell = $this->flattenedTableCell;
        $this->flattenedTableCell = $cell->hasBlockContent() ? $cell : null;
        $this->tableCellDepth++;
        $this->edgeCellBreaks = $this->edgeHardBreaks($inlines);
        try {
            $content = $this->renderInlines($inlines);
        } finally {
            $this->tableCellDepth--;
            $this->edgeCellBreaks = [];
            $this->flattenedTableCell = $previousFlattenedCell;
        }

        return $this->padCell($prefix, $content);
    }

    /**
     * The hard breaks a cell can drop instead of writing a space for them.
     *
     * A cell is one line, so a break becomes a space. The space is worth
     * dropping only where the cell's own trim would eat it anyway, which is the
     * cell's outer edge and nothing else. A break INSIDE an inline construct is
     * never at that edge: the construct's own closer stands between them, so
     * dropping the space emptied `{+ +}` to `{++}` - an empty brace pair, which
     * reads back as literal text rather than the `<ins>` it was written for.
     *
     * @param array<\MarkupCarve\Carve\Node\Node> $children
     *
     * @return array<int, true>
     */
    protected function edgeHardBreaks(array $children): array
    {
        $sequence = [];
        foreach ($children as $node) {
            if ($node instanceof HardBreak) {
                $sequence[] = $node;
            } elseif (!$node instanceof Text || trim($node->getContent()) !== '') {
                $sequence[] = true;
            }
        }

        $edges = [];
        foreach ([$sequence, array_reverse($sequence)] as $run) {
            foreach ($run as $entry) {
                if ($entry === true) {
                    break;
                }
                $edges[spl_object_id($entry)] = true;
            }
        }

        return $edges;
    }

    protected function renderFigure(Figure $node): string
    {
        $targets = [];
        foreach ($node->getTargets() as $nodeTarget) {
            $targets[] = $nodeTarget instanceof Image
                ? $this->renderImage($nodeTarget)
                : $this->renderBlock($nodeTarget);
        }
        $target = implode("\n", $targets);
        $captions = array_map(
            fn (Caption $caption): string => '^ ' . $this->renderInlines($caption->getChildren()),
            $node->getCaptions(),
        );
        $caption = implode("\n", $captions);

        return $caption === '' ? $target : $target . "\n" . $caption;
    }

    /**
     * The canonical writer emits the AUTHORED form (grammar PART 11 §10g): the
     * bare `::: figure` opener - a figure_group has no title or label to spell
     * - the children with one blank line between them, the closer at the
     * opener's width, and the group caption as a `^ ` line after the closer.
     * The caret is NOT escaped there: the group caption is the caption the
     * closer hosts, not text in that position, and `\^ ` would re-parse as a
     * paragraph and break `parse(fmt(x)) == parse(x)`.
     */
    protected function renderFigureGroup(FigureGroup $node): string
    {
        $fence = $this->colonFenceFor($node);
        $body = $this->renderColonFenceBody($node);
        $out = $fence . ' figure' . self::fencedDivBody($body) . $fence;

        $caption = $node->getCaption();
        if ($caption !== null) {
            $out .= "\n^ " . $this->renderInlines($caption->getChildren());
        }

        return $out;
    }

    protected function renderRawBlock(RawBlock $node): string
    {
        $content = $node->getContent();
        $fence = $this->safeFence($content, 3);

        $body = $this->protectVerbatim($content);

        return $fence . '=' . $this->escapeFormat($node->getFormat()) . "\n"
            . $body . ($content !== '' && trim($content, "\n") === '' ? '' : "\n") . $fence;
    }

    protected function renderComment(Comment $node): string
    {
        $content = $node->getContent();
        if ($node->isDelimited()) {
            return '{% ' . $content . ' %}';
        }
        $recorded = $node->getFenceLength();
        if ($recorded === null && !str_contains($content, "\n")) {
            // An empty comment writes its marker and nothing else. The inline
            // arm below has always done this; this one appended unconditionally
            // and produced `%% `, a trailing space on a writer-produced line
            // that no clause asks for and that made this engine disagree with
            // carve-js on the corpus (markup-carve/carve#1472).
            return $content === '' ? '%%' : '%% ' . $content;
        }

        preg_match_all('/%+/', $content, $matches);
        $longest = 0;
        foreach ($matches[0] as $match) {
            $longest = max($longest, strlen($match));
        }
        $fence = str_repeat('%', max(3, $longest + 1));

        return $fence . "\n" . $this->protectVerbatim($content) . "\n" . $fence;
    }

    /**
     * Write an authored `[label]: /url "title" {attrs}` line back as written.
     *
     * The href is re-escaped the way the inline tail's is: the reader resolves
     * `\(`, `\)` and `\\`, so writing the resolved value bare would hand back a
     * line whose parentheses no longer balance. The title is emitted verbatim;
     * the trailing attribute block is the node's own attributes (PART 9 §15
     * A2b), which transfer to every link or image resolving the label rather
     * than styling this line.
     */
    protected function renderLinkReferenceDefinition(LinkReferenceDefinition $node): string
    {
        $out = '[' . $node->getLabel() . ']: ' . $this->escapeDestinationEscapes($node->getHref());
        $title = $node->getTitle();
        if ($title !== null) {
            $out .= ' "' . str_replace('"', '\\"', $title) . '"';
        }
        $attrs = $this->renderAttrs($node);
        if ($attrs !== '') {
            $out .= ' ' . $attrs;
        }

        return $out;
    }

    /**
     * A body holding NO blocks takes the SENTINEL `{empty}` (PART 11 §7b).
     *
     * `[^f]:` with nothing after the colon is not a definition at all - MARKER
     * REQUIRES CONTENT (PART 2) - so writing it degrades the definition to a
     * paragraph and every reference to it to literal text. PART 11 §1a is why
     * the writer may depart from the per-construct spelling here: the emitted
     * bytes have to re-parse to the tree they came from.
     *
     * The sentinel has to be a VALID ATTRIBUTE BLOCK, which is why it is not
     * `{ }` or `{}`: a block-attribute line requires at least one attribute,
     * so both of those stay literal text inside the note. `{empty}` is a
     * boolean attribute, collected on the definition line and discarded with
     * the rest of the body's pending attributes, so it reaches neither the
     * endnote item nor anything after it.
     */
    protected function renderFootnote(Footnote $node): string
    {
        $body = $this->trimNonNbsp(
            $this->atAnAuthoredBodyColumn(fn (): string => $this->renderBlocks($node->getChildren())),
        );
        if ($body === '') {
            return '[^' . $this->writeFlatBracketRun($node->getLabel()) . ']: ' . self::EMPTY_BODY_SENTINEL;
        }

        $lines = explode("\n", $body);
        $out = '[^' . $this->writeFlatBracketRun($node->getLabel()) . ']: ' . array_shift($lines);
        foreach ($lines as $line) {
            // TWO spaces, the body's own column (PART 9 §16). A wider indent is
            // legal continuation but puts the body's blocks at a relative column
            // above zero, and an indented block opener does not open a block - so
            // a table or list written at three came back as a paragraph.
            $out .= "\n" . ($line === '' && $node->getRenderHint("\0carve-indent-blank-lines") === '1'
                ? str_repeat($this->verbatimSentinels[0], 2)
                : $this->indentContinuationLine($line, '  '));
        }

        return $out;
    }

    /**
     * The opener SPELLS THE FORMAT TOKEN OUT, `yaml` included
     * (markup-carve/carve#961 ruling 2).
     *
     * The grammar uses the word "canonical" for the exact string `---yaml`, and
     * this is the canonical writer. `yaml` used to be the one format written as
     * a bare `---`, which made the default format the single case where the
     * writer did not say what it had parsed; `---toml` and `---json` were
     * already spelled out. One rule across formats replaces the special case.
     */
    protected function renderFrontmatter(Frontmatter $node): string
    {
        return '---' . $this->escapeFormat($node->getFormat()) . "\n" . $this->protectVerbatim($node->getContent()) . "\n---";
    }

    /**
     * @param array<\MarkupCarve\Carve\Node\Node> $nodes
     *
     * @throws \MarkupCarve\Carve\Exception\RenderDepthExceededException
     */
    protected function renderInlines(array $nodes): string
    {
        if ($this->inlineDepth >= self::MAX_RENDER_DEPTH) {
            throw new RenderDepthExceededException(self::MAX_RENDER_DEPTH, 'Carve');
        }
        $this->inlineDepth++;
        try {
            $out = '';
            $count = count($nodes);
            $captionCanOpen = $this->paragraphStartsAfterCaptionHost && $this->inlineDepth === 1;
            $isFirstInlineLine = true;
            $lineNodeCount = 0;
            $lineHostsCaption = false;
            $lineEndsInComment = false;
            $previousRendered = '';
            for ($i = 0; $i < $count; $i++) {
                $node = $nodes[$i];
                if (
                    $this->inLineBlock > 0 && $node instanceof NonBreakingSpace && $node->getAttributes() === []
                    && ($i === 0 || ($nodes[$i - 1] ?? null) instanceof HardBreak
                        || ($nodes[$i - 1] ?? null) instanceof NonBreakingSpace
                        || ($nodes[$i + 1] ?? null) instanceof NonBreakingSpace)
                ) {
                    $out .= $this->verbatimSentinels[0];

                    continue;
                }
                if ($node instanceof HardBreak && $this->inLineBlock > 0) {
                    // ONLY THE STANZA'S OWN LAST NODE ENDS THE PARAGRAPH. A
                    // break nested inside an emphasis run ends that run's list
                    // instead, and dropping its newline there closed the
                    // emphasis with an escaped delimiter (`/a\/`). The parser
                    // never builds that tree - the promotion in
                    // BlockParser::convertParagraphSoftBreaksToHardBreaks()
                    // reaches direct children only - but an imported AST can.
                    $out .= $this->verseLineBreak(
                        $out,
                        $i === $count - 1 && $this->inlineDepth === 1,
                        $lineEndsInComment,
                    );
                    $captionCanOpen = false;
                    $isFirstInlineLine = false;
                    $lineNodeCount = 0;
                    $lineHostsCaption = false;
                    $lineEndsInComment = false;

                    continue;
                }
                $directive = $this->matchIncludeDirective($nodes, $i);
                if ($directive !== null) {
                    $out .= $directive['source'];
                    $i = $directive['end'];

                    continue;
                }
                if ($node instanceof InlineNode) {
                    $prevChar = $this->lastBoundary($nodes[$i - 1] ?? null);
                    $rendered = $this->renderInline(
                        $node,
                        // A span leaves no boundary character of its own, so the
                        // one it WROTE (its closer) is what the next opener sits against.
                        $prevChar === '' ? substr($out, -1) : $prevChar,
                        $this->firstBoundary($nodes[$i + 1] ?? null),
                        $captionCanOpen,
                        self::opensAVerbatimRun($nodes[$i + 1] ?? null),
                    );
                    // A bare caret the previous node ended on opens an inline
                    // note against a `[` this node writes, in both passes.
                    if (
                        $this->inlineNoteDepth === 0
                        && str_ends_with($out, '^')
                        && self::backslashRunBefore($out, strlen($out) - 1) % 2 === 0
                        && self::inlineNoteCouldOpen($rendered, 0)
                    ) {
                        $out = substr($out, 0, -1) . '\\^';
                    }
                    // A bare `:name` the previous node ended on opens an inline
                    // extension against that `[` (markup-carve/carve#2068).
                    if (str_starts_with($rendered, '[') && preg_match('/:[A-Za-z_][A-Za-z0-9_-]*\z/', $out, $name, PREG_OFFSET_CAPTURE) === 1) {
                        $colon = $name[0][1];
                        if (self::backslashRunBefore($out, $colon) % 2 === 0) {
                            $out = substr($out, 0, $colon) . '\\' . substr($out, $colon);
                        }
                    }
                    $this->refuseGluedMention($node, $nodes[$i - 1] ?? null, $out, $previousRendered, $rendered);
                    // Two backtick runs that touch merge into one run, so an
                    // empty delimited comment separates them (PART 11 section 10k N3).
                    if (str_starts_with($rendered, '`') && self::endsInABareBacktickRun($out)) {
                        $out .= self::VERBATIM_SEPARATOR;
                    }
                    $out .= $rendered;
                    $previousRendered = $rendered;
                    if ($node instanceof SoftBreak) {
                        $captionCanOpen = $isFirstInlineLine && $lineNodeCount === 1 && $lineHostsCaption;
                        $isFirstInlineLine = false;
                        $lineNodeCount = 0;
                        $lineHostsCaption = false;

                        continue;
                    }
                    $lineNodeCount++;
                    $lineHostsCaption = $lineNodeCount === 1 && self::inlineHostsACaption($node);
                    $captionCanOpen = false;
                    $lineEndsInComment = false;
                } elseif ($node instanceof Comment) {
                    $content = $node->getContent();
                    // THE UNIT IS THE OPENER (PART 11 §2a [CARVE-P11-008]). A
                    // percent-leading content joins the marker, so `%%%` is not
                    // written back as `%% %` - an opener run split into an
                    // opener plus a stray character (carve#581, carve#544). The
                    // BLOCK arm must NOT copy this: there a run of three is a
                    // comment FENCE (PART 9 §28) and joining swallows the body
                    // between two of them (carve-js#1675).
                    $body = match (true) {
                        $content === '' => '%%',
                        str_starts_with($content, '%') => '%%' . $content,
                        default => '%% ' . $content,
                    };

                    // IN VERSE THAT IS WHAT PUTS THE COMMENT BACK ON THE LINE
                    // IT EMPTIED. PART 9 §23 removes a comment-only body line
                    // at the BLOCK layer, and a `comment` node survives only
                    // where the boundary that OPENS its line survives - so the
                    // walk is standing at the start of that line when it gets
                    // here, `$out` ends with the boundary's newline, and no
                    // separator is what writes the marker at column 0.
                    $separator = ($out === '' || str_ends_with($out, "\n")) ? '' : ' ';
                    $out .= $node->isDelimited()
                        ? $this->renderComment($node)
                        : $separator . $body;
                    $lineEndsInComment = !$node->isDelimited();
                    $lineNodeCount++;
                    $lineHostsCaption = false;
                    $captionCanOpen = false;
                }
            }

            return $out;
        } finally {
            $this->inlineDepth--;
        }
    }

    /**
     * How a line block spells a `hard_break` (PART 11 §7c).
     */
    protected function verseLineBreak(string $out, bool $endsTheParagraph, bool $lineEndsInComment): string
    {
        // A LINE WHOSE LAST NODE IS A COMMENT IS EXEMPT -- and the rule is
        // keyed on the NODE, not on where the line sits. `%%` runs to the END
        // OF ITS LINE, so a trailing space there is INSIDE the note rather
        // than content PART 2 is about to take: stripping it leaves the same
        // node, and protecting it does not, because the block layer claims the
        // whole line before the inline parser sees it and the backslash lands
        // in the comment's own content. An EMPTY comment line is where this
        // bites, since the writer used to spell one as the marker plus a
        // separator space (corpus 346-3).
        if ($lineEndsInComment) {
            return $endsTheParagraph ? '' : "\n";
        }

        if ($endsTheParagraph) {
            return '\\';
        }

        $lineStart = strrpos($out, "\n");
        $line = $lineStart === false ? $out : substr($out, $lineStart + 1);

        return ($this->verseLineNeedsBackslash($line) ? '\\' : '') . "\n";
    }

    /**
     * Match the include directives in the run of literal-text nodes starting at
     * $start, and return the run's source form plus the index of its last node.
     *
     * The core never parses a directive as a node of its own (spec section 19
     * makes it unreachable from block/inline), so it arrives here as ordinary
     * text and would otherwise be escaped like any other punctuation-bearing
     * text - turning `{{ chapter.crv }}` into `\{\{ chapter\.crv \}\}` and
     * silently breaking every include in a formatted document. A well-formed
     * directive is therefore emitted verbatim.
     *
     * The test is SHAPE-well-formedness, not validity: the run must open `{{`,
     * close `}}` and carry a non-empty path token. Section and option validity
     * are NOT required, because they are diagnostics the expander reports at
     * expansion time (I7) - escaping a shape-valid run over a bad option would
     * convert a fixable typo into permanent literal text AND destroy the very
     * warning that would have explained it. A run that is not shape-well-formed
     * (`{{ oops` with no close, or an empty path) stays ordinary text and is
     * escaped as before.
     *
     * Every directive in the run is preserved, not just the first: prose splits
     * a paragraph into one text-like run, so `a {{ x.crv }} b {{ y.crv }} c` is
     * a single run holding two directives, and stopping after the first escaped
     * the rest.
     *
     * A serializer cannot tell an authored literal `{{` from a directive - both
     * parse to the same text - which is accepted, because an author who needs a
     * guaranteed literal writes it in code, where a directive is inert by
     * construction (I9).
     *
     * @param array<\MarkupCarve\Carve\Node\Node> $nodes Positionally indexed,
     *   exactly as the surrounding renderInlines() loop already assumes.
     * @param int $start
     *
     * @return array{source: string, end: int}|null
     */
    protected function matchIncludeDirective(array $nodes, int $start): ?array
    {
        if (!IncludeDirectiveSyntax::isTextLike($nodes[$start])) {
            return null;
        }
        if (!str_contains(IncludeDirectiveSyntax::textLikeContent([$nodes[$start]]), '{{')) {
            return null;
        }

        $count = count($nodes);
        $run = [];
        for ($i = $start; $i < $count && IncludeDirectiveSyntax::isTextLike($nodes[$i]); $i++) {
            $run[] = $nodes[$i];
        }
        $last = $i - 1;
        $text = IncludeDirectiveSyntax::textLikeContent($run);

        // One run can hold ANY number of directives with prose between them, so
        // the whole run is scanned: every shape-well-formed span is collected
        // and the gaps around them are escaped as ordinary text. Handling only
        // the first span - and escaping the remainder wholesale - destroyed
        // every directive after the first.
        $spans = [];
        $cursor = 0;
        $length = strlen($text);
        while (
            $cursor < $length
            && preg_match(IncludeDirectiveSyntax::SCAN, $text, $match, PREG_OFFSET_CAPTURE, $cursor) === 1
        ) {
            $span = $match[0][0];
            $offset = (int)$match[0][1];
            // A span that is not shape-well-formed is prose, not a reason to
            // stop: scanning resumes after it so a valid directive later in the
            // same run is still preserved.
            if (IncludeDirectiveSyntax::parse($span) !== null) {
                $spans[] = ['offset' => $offset, 'length' => strlen($span), 'source' => $this->emitDirective($span)];
            }
            // Every span opens `{{` and closes `}}`, so the cursor always advances.
            $cursor = $offset + strlen($span);
        }

        if ($spans === []) {
            return null;
        }

        $out = '';
        $position = 0;
        foreach ($spans as $span) {
            $out .= $this->emitDirectiveGap($run, $text, $position, $span['offset']) . $span['source'];
            $position = $span['offset'] + $span['length'];
        }

        return [
            'source' => $out . $this->emitDirectiveGap($run, $text, $position, strlen($text)),
            'end' => $last,
        ];
    }

    /**
     * Serialize the prose BETWEEN two preserved directives, per node.
     *
     * The run was reassembled in SOURCE form so the grammar could match it, and
     * for some nodes that form is already escaped: an EscapedText contributes
     * its backslash, a smart-punctuation node the author's own run. Escaping
     * the reassembled slice wholesale therefore escapes those a second time -
     * `here\.` came back out as `here\\.`. Only a Text node carries PARSED
     * content that still needs escaping, so the gap is emitted node by node.
     *
     * A directive span always opens `{{` and closes `}}` in plain text, so a
     * gap boundary can only fall inside a Text node; every other node kind is
     * either wholly inside the gap or wholly inside a directive.
     *
     * @param list<\MarkupCarve\Carve\Node\Node> $run
     * @param int $to
     * @param int $from
     * @param string $text
     */
    protected function emitDirectiveGap(array $run, string $text, int $from, int $to): string
    {
        if ($from >= $to) {
            return '';
        }

        $out = '';
        $offset = 0;
        foreach ($run as $node) {
            $piece = IncludeDirectiveSyntax::textLikeContent([$node]);
            $start = $offset;
            $end = $offset + strlen($piece);
            $offset = $end;
            if ($end <= $from || $start >= $to) {
                continue;
            }
            if (!$node instanceof Text) {
                // Already source form; emitting it again is the round trip.
                $out .= $piece;

                continue;
            }
            $out .= $this->escapeText(substr($piece, max($from, $start) - $start, min($to, $end) - max($from, $start)));
        }

        return $out;
    }

    /**
     * Serialize one preserved directive.
     *
     * Verbatim, because the run was reassembled from each node's AUTHOR text: a
     * quoted path reaches the writer as SmartPunctuation nodes whose content is
     * the `"` the author typed, not the `“` the parser resolved it to. So
     * the source spelling is still in hand here and needs neither un-curling nor
     * escaping.
     *
     * Escaping the quotes would be actively wrong: a backslash-escaped quote is
     * not the directive grammar's quoted path, so a formatted document would
     * stop resolving. The round trip is safe without it - the next parse curls
     * the delimiters again for rendering while recognition keeps reading the
     * author's run.
     */
    protected function emitDirective(string $raw): string
    {
        return $raw;
    }

    /**
     * Whether a bare newline after this line's bytes would be read back as
     * something else.
     */
    private function verseLineNeedsBackslash(string $line): bool
    {
        if ($line === '') {
            return true;
        }

        // ONE TRAILING COLUMN, IN EITHER OF ITS TWO SPELLINGS.
        //
        // A plain space is the obvious one. An ESCAPED space is the other, and
        // it is not exempt for looking like content: the line block drops a
        // lone trailing COLUMN before the inline reader ever sees the escape,
        // so `a\ ` comes back as `a\` - a hard break with the non-breaking
        // space gone. The backslash is what stops the column being dropped, so
        // the test is on the column and not on what put it there.
        //
        // A MEDIAL GAP OF TWO OR MORE COLUMNS matches NEITHER, and that is the
        // whole of its exemption (§23 MEDIAL GAPS). By the time a line reaches
        // here {@see self::resolveIndentPlaceholder()} has rewritten such a run
        // to the writer's own protected-space sentinel, which is not a space
        // and is not the escape placeholder - so it needs no branch of its own,
        // and a branch spelled against plain spaces would never run.
        return str_ends_with($line, ' ') || str_ends_with($line, $this->verbatimSentinels[4]);
    }

    protected function renderInline(
        InlineNode $node,
        string $prevChar = '',
        string $nextChar = '',
        bool $captionCanOpen = false,
        bool $nextOpensVerbatim = false,
    ): string {
        $previous = $this->escapeUnit;
        $this->escapeUnit = $node;
        try {
            return $this->renderInlineBody($node, $prevChar, $nextChar, $captionCanOpen, $nextOpensVerbatim);
        } finally {
            $this->escapeUnit = $previous;
        }
    }

    protected function renderInlineBody(
        InlineNode $node,
        string $prevChar = '',
        string $nextChar = '',
        bool $captionCanOpen = false,
        bool $nextOpensVerbatim = false,
    ): string {
        $withAttrs = fn (string $body): string => $body . $this->renderAttrs($node);
        // An unresolved reference renders as the source the author
        // wrote, never as a link (PART 12 section 3a).
        $rawReference = UnresolvedReference::sourceOf($node);

        return match (true) {
            $node instanceof Text => $this->escapeImportedText($node, $this->escapeText(
                $this->resolveIndentPlaceholder($node->getContent()),
                // Does this node's first character sit at the start of a block
                // line? Only there can `^ ` be read back as a caption marker.
                $captionCanOpen && $this->tableCellDepth === 0,
                // Does a trailing `!` abut the next node's backtick run? Only
                // there does PART 9 §27 bind it to an inline literal.
                $nextOpensVerbatim,
                $this->structuralEscapes[spl_object_id($node)] ?? [],
            )),
            // The whole point: reproduce the author's source run verbatim.
            $node instanceof SmartPunctuation => $node->getContent(),
            // The author escaped this character; the writer says so again. No
            // minimal/conservative decision applies -- the node IS the decision.
            // Routing it through escapeText() made the minimal render DROP the
            // author's escape, so `\*x\*` came back as `*x*`, re-parsed with a
            // Strong, and W4 escalated the whole document to conservative
            // (carve#374).
            $node instanceof EscapedText => '\\' . $node->getContent(),
            $node instanceof Emphasis => $withAttrs($this->spellSameKind($node, '/', $this->renderEmphasis('/', $this->renderMarked('emphasis', $node), $prevChar, $nextChar, self::endsInEmptyCodeSpan($node), self::holdsLineComment($node)))),
            $node instanceof Strong => $withAttrs($this->spellSameKind($node, '*', $this->renderStrongNode($node, $prevChar, $nextChar))),
            $node instanceof Underline => $withAttrs($this->spellSameKind($node, '_', $this->renderEmphasis('_', $this->renderMarked('underline', $node), $prevChar, $nextChar, self::endsInEmptyCodeSpan($node), self::holdsLineComment($node)))),
            $node instanceof Strike => $withAttrs($this->spellSameKind($node, '~', $this->renderEmphasis('~', $this->renderMarked('strike', $node), $prevChar, $nextChar, self::endsInEmptyCodeSpan($node), self::holdsLineComment($node)))),
            $node instanceof Superscript => $withAttrs($this->spellSameKind($node, '^', $this->renderForcedEmphasis('^', $this->renderMarked('superscript', $node)))),
            $node instanceof Subscript => $withAttrs($this->spellSameKind($node, ',', $this->renderForcedEmphasis(',', $this->renderMarked('subscript', $node)))),
            $node instanceof Highlight => $withAttrs($this->spellSameKind($node, '=', $this->renderEmphasis('=', $this->renderMarked('highlight', $node), $prevChar, $nextChar, self::endsInEmptyCodeSpan($node), self::holdsLineComment($node)))),
            $node instanceof Code => $node->getContent() === '' && !$this->emptyCodeSpanIsSpellable($node)
                ? throw new SourceUnspellableException('code', 'an empty code span has no Carve source spelling where its open run does not end')
                : $withAttrs($this->renderCode($node->getContent())),
            $node instanceof Mention => $this->renderMention($node),
            $node instanceof Link && $node->isAutolink() => $withAttrs('<' . $this->escapeAutolinkHref($this->plainInlineText($node)) . '>'),
            $rawReference !== null => $rawReference,
            $node instanceof Link => $this->renderLink($node),
            $node instanceof Image => $this->renderImage($node),
            $node instanceof CriticComment => '{#' . $this->escapeCriticText($node->getContent()) . '#}',
            $node instanceof Span => '[' . $this->renderInlines($node->getChildren()) . ']' . ($this->renderAttrs($node) ?: '{}'),
            $node instanceof Math => $withAttrs($this->renderMath($node)),
            $node instanceof RawInline => $node->getContent() === ''
                ? throw new SourceUnspellableException('raw_inline', 'an empty raw inline has no Carve source spelling')
                : $this->renderCode($node->getContent()) . '{=' . $this->escapeFormat($node->getFormat()) . '}',
            $node instanceof LiteralInline => $this->renderLiteralInline($node),
            $node instanceof RawText => $node->getContent(),
            $node instanceof Symbol => $withAttrs(':' . $this->escapeSymbolName($node->getName()) . ':'),
            $node instanceof InlineExtension => $withAttrs(':' . $this->escapeIdentifier($node->getExtensionType()) . '[' . $this->renderInlines($node->getChildren()) . ']'),
            $node instanceof Abbreviation => $this->escapeText($this->renderInlines($node->getChildren())),
            $node instanceof InlineFootnote => $withAttrs('^[' . $this->renderInlineNoteContent($node) . ']'),
            $node instanceof FootnoteRef => $withAttrs('[^' . $this->writeFlatBracketRun($node->getLabel()) . ']'),
            $node instanceof NonBreakingSpace => $node->getAttributes() === []
                ? $this->verbatimSentinels[4]
                : $withAttrs('[' . $this->verbatimSentinels[4] . ']'),
            $node instanceof SoftBreak => "\n",
            // A line block's own spelling is decided in renderInlines(), which
            // is the only place that can see the line the break ends
            // (PART 11 §7c) - see verseLineBreak().
            // A pipe cell is one line and has no hard break: one space (PART 11 §1b).
            $node instanceof HardBreak => $this->tableCellDepth === 0 ? "\\\n" : (isset($this->edgeCellBreaks[spl_object_id($node)]) ? '' : ' '),
            $node instanceof Insert => $withAttrs($this->spellSameKind($node, '+', '{+' . $this->renderMarked('insert', $node) . '+}')),
            $node instanceof Delete => $withAttrs($this->spellSameKind($node, '-', '{-' . $this->renderMarked('delete', $node) . '-}')),
            $node instanceof Substitution => '{~' . $this->renderInlines($node->getOld()->getChildren()) . '~>' . $this->renderInlines($node->getNew()->getChildren()) . '~}',
            $node instanceof HeadingRef => '</#' . $this->escapeCrossrefTarget($node->getTargetId()) . '>',
            $node instanceof CaptionNumber => '#',
            $node instanceof CitationGroup => $node->getRaw(),
            $node instanceof Ruby => $this->renderRuby($node),
            $node instanceof SmallCaps => $this->renderSmallCaps($node),
            default => $this->renderInlines($node->getChildren()),
        };
    }

    protected function renderRuby(Ruby $node): string
    {
        $this->recordRubyFlattened($node);
        $content = '';
        foreach ($node->getPairs() as $pair) {
            $content .= $this->renderInlines([...$pair['base'], new Text('('), ...$pair['annotation'], new Text(')')]);
        }

        return $node->getAttributes() === [] ? $content : '[' . $content . ']' . $this->renderAttrs($node);
    }

    /**
     * PART 12 §28: write the children WITHOUT the small-caps wrapper, and keep
     * `attrs` on an ordinary attributed span around them.
     */
    protected function renderSmallCaps(SmallCaps $node): string
    {
        $this->recordUnspellableStructure($node, 'Carve source cannot spell small caps');
        $content = $this->renderInlines($node->getChildren());

        return $node->getAttributes() === [] ? $content : '[' . $content . ']' . $this->renderAttrs($node);
    }

    private static function inlineHostsACaption(InlineNode $node): bool
    {
        if ($node instanceof Image) {
            return UnresolvedReference::sourceOf($node) === null;
        }

        return $node instanceof Math && $node->isDisplay();
    }

    /**
     * The span kinds PART 9 section 9 keeps on the E1-E5 stack.
     *
     * @var array<int, class-string<\MarkupCarve\Carve\Node\Node>>
     */
    protected const SPAN_KINDS = [
        Emphasis::class,
        Strong::class,
        Underline::class,
        Strike::class,
        Superscript::class,
        Subscript::class,
        Highlight::class,
        Insert::class,
        Delete::class,
    ];

    /**
     * Spell a span so a span of its kind inside it reads back.
     *
     * E3 leaves an opener of an open kind literal, bare or forced, but a braced
     * inline starts its own scope (markup-carve/carve#2078, #2091). So a span
     * between two spans of one kind is written braced, and a span of the same
     * kind reachable without a braced span between has no spelling.
     *
     * @throws \MarkupCarve\Carve\Exception\SourceUnspellableException
     */
    protected function spellSameKind(Node $node, string $delimiter, string $written): string
    {
        if (!str_starts_with($written, '{') && $this->separatesAnOuterKind($node)) {
            $written = '{' . $written . '}';
        }

        $pending = $node->getChildren();
        while ($pending !== []) {
            $child = array_shift($pending);
            if ($child::class === $node::class) {
                throw new SourceUnspellableException(
                    $node->getType(),
                    'a span inside a span of the same kind has no Carve source spelling',
                );
            }
            if (!isset($this->bracedSpans[spl_object_id($child)])) {
                array_push($pending, ...$child->getChildren());
            }
        }
        if (str_starts_with($written, '{')) {
            $this->bracedSpans[spl_object_id($node)] = true;
        }

        return $written;
    }

    /**
     * Does a span of an enclosing kind other than this node's sit inside it?
     */
    protected function separatesAnOuterKind(Node $node): bool
    {
        $outer = [];
        for ($parent = $node->getParent(); $parent instanceof InlineNode; $parent = $parent->getParent()) {
            if (in_array($parent::class, self::SPAN_KINDS, true) && $parent::class !== $node::class) {
                $outer[$parent::class] = true;
            }
        }
        if ($outer === []) {
            return false;
        }

        $pending = $node->getChildren();
        while ($pending !== []) {
            $child = array_shift($pending);
            if (isset($outer[$child::class])) {
                return true;
            }
            array_push($pending, ...$child->getChildren());
        }

        return false;
    }

    protected function renderStrongNode(Strong $node, string $prevChar, string $nextChar): string
    {
        // The COMBINED bold-italic form is a single production, and the nested
        // spelling parses to the same Strong>Emphasis tree -- so serializing the
        // nesting alone normalized one into the other, rewriting the spelling
        // Carve documents (cheatsheet, migrate-from-markdown) into one documented
        // nowhere. `isBoldItalic()` carries which one the author wrote
        // (PART 11 section 6; carve#375).
        $children = $node->getChildren();
        $inner = $children[0] ?? null;
        if ($node->isBoldItalic() && count($children) === 1 && $inner instanceof Emphasis) {
            $content = $this->renderInlines($inner->getChildren());
            // `/*` needs content that hugs it: `/* x*/` or `/**/` reparses as an
            // emphasis holding literal stars, so fall back to the nested spelling.
            if ($content !== '' && !preg_match('/^[ \t\r\n]|[ \t\r\n]$/', $content)) {
                return '/*' . $content . '*/';
            }
        }

        return $this->renderEmphasis(
            '*',
            $this->renderMarked('strong', $node),
            $prevChar,
            $nextChar,
            self::endsInEmptyCodeSpan($node),
            self::holdsLineComment($node),
        );
    }

    /**
     * An empty brace pair is not a construct, and `{--}` is the braced en dash
     * (markup-carve/carve#1608), so an empty mark has no spelling.
     *
     * @throws \MarkupCarve\Carve\Exception\SourceUnspellableException
     */
    protected function renderMarked(string $nodeType, Node $node): string
    {
        $content = $this->renderInlines($node->getChildren());
        if ($content === '') {
            throw new SourceUnspellableException($nodeType, "an empty {$nodeType} has no Carve source spelling");
        }

        return $content;
    }

    protected function renderLink(Link $node): string
    {
        $text = $this->renderInlines($node->getChildren());

        $referenceLabel = $node->getReferenceLabel();
        if (!$node->isFromHeadingReference() && $referenceLabel !== null && $referenceLabel !== '') {
            // `rawRef` is the authored source VERBATIM and already includes any
            // attribute block the author wrote at the reference, so appending
            // renderAttrs() here wrote `{.own}` twice.
            $raw = $node->getRawReferenceLabel();
            if ($raw !== null) {
                return $raw;
            }

            return '[' . $text . '][' . $referenceLabel . ']'
                . $this->renderAttrsExcept($node, $this->definitionAttributes[$referenceLabel] ?? []);
        }

        if ($node->isFromHeadingReference()) {
            // The AUTHORED source. `ref` now holds the real label rather than
            // `''` for the collapsed form (PART 12 §3a, carve#597), so building
            // the reference from it would write `[text][text]` where the author
            // wrote `[text][]`. `rawRef` is that source verbatim.
            $raw = $node->getRawReferenceLabel();
            if ($raw !== null) {
                return $raw . $this->renderAttrs($node);
            }

            return '[' . $text . '][' . $node->getReferenceLabel() . ']' . $this->renderAttrs($node);
        }

        $title = $node->getTitle() === null ? '' : ' "' . $this->escapeQuoted($node->getTitle()) . '"';

        return '[' . $text . '](' . $this->escapeDestination((string)$node->getDestination()) . $title . ')' . $this->renderAttrs($node);
    }

    /**
     * The block-attribute line a REFERENCE image needs, or '' when it needs none.
     *
     * `renderImage()` writes a reference image as the authored `rawRef`, which is
     * the reference source verbatim - `![a][r]`. An attribute block the author
     * wrote AT the reference is already inside that string; one that came from
     * the block-attribute line above is not, and returning `rawRef` alone
     * dropped it. Emitting the line back is the only spelling that survives a
     * re-parse, since appending the block to `rawRef` would attach it to the
     * image's own slot instead (carve-php#831).
     *
     * The DEFINITION's own attributes are excluded. They reach every link that
     * resolves the label (PART 9R R1) and are already written once on the
     * definition line, so treating the copy on this node as authored here wrote
     * the same `{#id}` twice - the same reason the reference site itself uses
     * renderAttrsExcept().
     */
    protected function referenceImageAttributeLine(Image $node): string
    {
        if ($node->getAttributes() === []) {
            return '';
        }
        $raw = UnresolvedReference::sourceOf($node) ?? $node->getRawReferenceLabel();
        if ($raw === null) {
            return '';
        }
        // A block the author wrote AT the reference is inside `rawRef` already, so
        // only what it does NOT state becomes a line. Bailing out whenever `rawRef`
        // ended in `}` dropped the block-attribute line wholesale for
        // `{#f}` + `![a][r]{.c}`, where the two blocks are different sets
        // (carve-php#831 follow-up); carve-js and carve-rs both keep the `{#f}`.
        $atReference = $this->trailingAttributesOf(rtrim($raw));
        $label = $node->getReferenceLabel();

        $claimed = $label === null ? [] : ($this->definitionAttributes[$label] ?? []);
        // `+` keeps the LEFT side's value for a shared key, which would drop the
        // reference's own classes whenever the definition also carried one. Class
        // tokens from both are subtracted, everything else keeps the union.
        $subtract = $claimed + $atReference;
        $classes = trim(($claimed['class'] ?? '') . ' ' . ($atReference['class'] ?? ''));
        if ($classes !== '') {
            $subtract['class'] = $classes;
        }

        return $this->renderAttrsExcept($node, $subtract);
    }

    /**
     * Attributes an authored inline already states in its trailing `{...}` block.
     *
     * QUOTE-AWARE: a value may itself contain a brace (`{k="{y}"}`), so the block's
     * opening brace is the last one seen OUTSIDE quotes - `strrpos()` finds the one
     * inside the value and mis-parses the payload (corpus 71).
     *
     * @return array<string, string>
     */
    protected function trailingAttributesOf(string $text): array
    {
        if (!str_ends_with($text, '}')) {
            return [];
        }
        $quote = null;
        $open = null;
        foreach (str_split($text) as $i => $ch) {
            if ($quote !== null) {
                if ($ch === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $quote = $ch;

                continue;
            }
            if ($ch === '{') {
                $open = $i;
            }
        }
        if ($open === null) {
            return [];
        }
        $payload = substr($text, $open + 1, -1);
        if (!AttributeParser::isValidInlinePayload($payload)) {
            return [];
        }

        return AttributeParser::parse($payload);
    }

    protected function renderImage(Image $node): string
    {
        // An UNRESOLVED reference image round-trips via its verbatim source.
        // The guard used to live in renderInline()'s dispatch, so it covered an
        // image in a paragraph and not one inside a figure: `![a][nope]` with a
        // caption came out `![a]()`, the label gone and the destination empty,
        // and the re-parse was a different document - PART 11 §1's invariant
        // broken inside this engine alone (carve-php#751).
        //
        // It belongs HERE, where every caller is covered, which is where
        // carve-js keeps it.
        $raw = UnresolvedReference::sourceOf($node);
        if ($raw !== null) {
            return $raw;
        }

        $title = $node->getTitle() === null ? '' : ' "' . $this->escapeQuoted($node->getTitle()) . '"';

        // A RESOLVED reference image writes the reference, for the same reason a
        // reference link does (PART 12 §10): the definition is in the tree now,
        // so both halves round-trip. Inlining here while the definition is also
        // emitted wrote the destination TWICE - once folded into the image and
        // once as the definition line (carve#642).
        $referenceLabel = $node->getReferenceLabel();
        if ($referenceLabel !== null && $referenceLabel !== '') {
            $rawRef = $node->getRawReferenceLabel();
            if ($rawRef !== null) {
                return $rawRef;
            }

            return '![' . $this->escapeImageAlt($node->getAlt()) . '][' . $referenceLabel . ']'
                . $this->renderAttrsExcept($node, $this->definitionAttributes[$referenceLabel] ?? []);
        }

        return '![' . $this->escapeImageAlt($node->getAlt()) . '](' . $this->escapeDestination($node->getSource()) . $title . ')' . $this->renderAttrs($node);
    }

    /**
     * @throws \MarkupCarve\Carve\Exception\SourceUnspellableException
     */
    protected function renderMention(Mention $node): string
    {
        // A trailing attribute block after `@name` stays literal text, so no
        // source reads back as a mention or tag that carries attributes.
        if ($node->getAttributes() !== []) {
            throw new SourceUnspellableException(
                $node->getCssClass() === 'tag' ? 'tag' : 'mention',
                'it has no Carve source spelling with attributes',
            );
        }

        // The plain text, not the rendered inlines: a name is tested against
        // what the author wrote, and `renderInlines()` has already escaped the
        // dot in `john.doe` into `john\.doe`, which is not a name.
        $label = $this->plainInlineText($node);
        $sigil = str_starts_with($label, '#') ? '#' : '@';
        // ONE sigil, not a run of them: `ltrim($label, '@')` read `@@user` as
        // the name `user` and wrote back one `@` fewer than it was handed.
        $name = str_starts_with($label, $sigil) ? substr($label, 1) : $label;

        if (($node->getDestination() ?? '') === '') {
            // No link form to fall back to: `@Lea Thompson` would read back as
            // the mention `Lea` (markup-carve/carve-php#2159).
            if (!$this->isMentionName($name)) {
                throw new SourceUnspellableException(
                    $node->getCssClass() === 'tag' ? 'tag' : 'mention',
                    'its name has no Carve source spelling',
                );
            }

            return $label;
        }

        // A mention name carries no escape, so a label holding anything else
        // has no spelling in this syntax. It degrades to the link form rather
        // than to a name the author did not write: `@o'brien` would have to
        // become `@obrien`, which is a DIFFERENT mention, silently.
        // Nested markup has no spelling either: `@*user*` is not a mention.
        if (!$this->isMentionName($name) || !$this->isFlatText($node)) {
            return $this->renderMentionAsLink($node);
        }

        return $sigil . $name;
    }

    /**
     * Whether a label can be spelled as a mention or tag name.
     *
     * `mention_name = name_word, {'.', name_word}`, dots interior-only. The
     * character set is the one {@see \MarkupCarve\Carve\Extension\MentionsExtension}
     * actually accepts, which is ASCII: writing a name this engine's own parser
     * would then read differently is the bug being fixed, not a fix. (The
     * grammar's `letter` reads wider than that, but a writer has to target the
     * reader that exists.)
     */
    protected function isMentionName(string $name): bool
    {
        return preg_match('/^[a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_-]+)*$/', $name) === 1;
    }

    /**
     * Is the node's content a plain run of text, with no markup inside it?
     */
    protected function isFlatText(Mention $node): bool
    {
        foreach ($node->getChildren() as $child) {
            if (!$child instanceof Text && !$child instanceof EscapedText) {
                return false;
            }
        }

        return true;
    }

    /**
     * The nearest construct that holds everything a mention does: the label,
     * the destination and the class, rendering the same anchor.
     *
     * A CLONE, not a fresh node the children are appended to: `appendChild()`
     * reparents, so building the link that way left every child of the mention
     * pointing at a throwaway parent once the document had been written. The
     * renderer is handed a tree it does not own.
     */
    protected function renderMentionAsLink(Mention $node): string
    {
        $link = clone $node;
        if ($node->getCssClass() !== '') {
            $link->addClass($node->getCssClass());
        }

        return $this->renderLink($link);
    }

    protected function renderMath(Math $node): string
    {
        if ($node->getLabel() !== null) {
            $this->recordUnspellableField($node, 'label', 'Carve source cannot spell an equation label');
        }
        if ($node->getNumber() !== null) {
            $this->recordUnspellableField($node, 'number', 'Carve source cannot spell an equation number');
        }

        return ($node->isDisplay() ? '$$' : '$') . $this->renderCode($node->getContent());
    }

    /**
     * Superscript and subscript have no bare delimiter form - always emit the
     * braced form.
     */
    protected function renderForcedEmphasis(string $delimiter, string $content): string
    {
        return '{' . $delimiter . $content . $delimiter . '}';
    }

    /**
     * An EMPTY code span has one spelling, a backtick run its container ends,
     * and inside an emphasis only the braced closer ends it - a bare closer is
     * swallowed by the open run (markup-carve/carve#2051).
     */
    protected static function endsInEmptyCodeSpan(InlineNode $node): bool
    {
        $children = $node->getChildren();
        $last = $children === [] ? null : $children[array_key_last($children)];

        return $last instanceof Code && $last->getContent() === '';
    }

    /**
     * A `%%` comment runs to the end of its line and does not cross an EXPLICIT
     * closer (CARVE-P9-042), so a span holding one reads back only in the
     * braced form: a bare closer behind the comment is swallowed with it.
     */
    protected static function holdsLineComment(InlineNode $node): bool
    {
        $pending = $node->getChildren();
        while ($pending !== []) {
            $child = array_shift($pending);
            // A DELIMITED `{% … %}` comment carries its own closer and stops
            // there, so only the line form takes the span's closer with it.
            if ($child instanceof Comment && !$child->isDelimited()) {
                return true;
            }
            array_push($pending, ...$child->getChildren());
        }

        return false;
    }

    /**
     * Does the open run of this empty code span end where the span does? It
     * ends at the end of a block or at a braced closer (PART 3, UNCLOSED RUN);
     * anything else behind it is read into the span, and an enclosing link or
     * span label never closes. Attributes attach to a closing run, which the
     * span has not got.
     */
    protected function emptyCodeSpanIsSpellable(Code $node): bool
    {
        if ($node->getAttributes() !== []) {
            return false;
        }

        $current = $node;
        $closed = false;
        for ($parent = $node->getParent(); $parent !== null; $current = $parent, $parent = $parent->getParent()) {
            if (!$closed) {
                $after = false;
                foreach ($parent->getChildren() as $sibling) {
                    if ($after && (!$sibling instanceof Text || $sibling->getContent() !== '')) {
                        return false;
                    }
                    $after = $after || $sibling === $current;
                }
            }
            if (!$parent instanceof InlineNode) {
                if ($parent instanceof Paragraph && $this->flattenedTableCell !== null) {
                    $row = $this->flattenedTableCell->getParent();

                    return !$row instanceof TableRow || $row->getChildren()[count($row->getChildren()) - 1] === $this->flattenedTableCell;
                }
                if ($parent instanceof TableCell) {
                    // Cells are split after the run is read, so only the last
                    // cell's run ends with its line, braced closer or not.
                    $row = $parent->getParent();

                    return !$row instanceof TableRow || $row->getChildren()[count($row->getChildren()) - 1] === $parent;
                }

                return true;
            }
            $braced = $parent instanceof Emphasis
                || $parent instanceof Strong
                || $parent instanceof Underline
                || $parent instanceof Strike
                || $parent instanceof Highlight
                || $parent instanceof Superscript
                || $parent instanceof Subscript
                || $parent instanceof Insert
                || $parent instanceof Delete;
            if (!$braced && !$parent instanceof Abbreviation) {
                return false;
            }
            $closed = $closed || $braced;
        }

        return true;
    }

    protected function renderEmphasis(
        string $delimiter,
        string $content,
        string $prevChar,
        string $nextChar,
        bool $endsInEmptyCodeSpan = false,
        bool $holdsLineComment = false,
    ): string {
        // A line comment ends at ITS line break, so it takes the bare closer
        // with it only when it opens on the closer's own line. The node gate
        // keeps a `%%` that is merely code-span content out of this.
        $commentTakesTheCloser = $holdsLineComment
            && preg_match('/[ \t\n]%%(?![^\n]*\n)/', $content) === 1;

        // The characters `bare_opener` refuses before a marker (CARVE-P3-013).
        $needsForced = $endsInEmptyCodeSpan
            || $commentTakesTheCloser
            || preg_match('/[A-Za-z0-9_]/', $prevChar) === 1
            || $prevChar === $delimiter
            || ($prevChar === '/' && ($delimiter === '/' || $delimiter === '_'))
            || preg_match('/[A-Za-z0-9_]/', $nextChar) === 1
            || str_starts_with($content, $delimiter)
            || str_ends_with($content, $delimiter)
            // Whitespace against a bare delimiter stops it opening or closing,
            // and a trailing line break puts the closer at the start of the next
            // line, where only the braced closer closes.
            || preg_match('/^[ \t\r\n]|[ \t\r\n]$/', $content) === 1
            // `/*` opens `bold_italic` and `*/` closes it, so a bare emphasis
            // whose content has both would read back as a strong wrapping an
            // emphasis -- the other nesting (carve-php#2012).
            || ($delimiter === '/' && str_starts_with($content, '*') && str_ends_with($content, '*'));

        return $needsForced ? '{' . $delimiter . $content . $delimiter . '}' : $delimiter . $content . $delimiter;
    }

    /**
     * Serialize an inline literal back to `` !`content` `` / `` !`content`{.cls
     * #id} `` (grammar PART 9 §27): a `!` prefix on a verbatim span, mirroring
     * the `$`-math prefix. A trailing attribute block is the ordinary inline
     * attribute block (as a code span carries). renderCode widens the backtick
     * fence when the content holds backticks, so the round-trip is byte-stable
     * and idempotent.
     */
    protected function renderLiteralInline(LiteralInline $node): string
    {
        return '!' . $this->renderCode($node->getContent()) . $this->renderAttrs($node);
    }

    protected function renderCode(string $content): string
    {
        // A code span is verbatim too, so an authored U+E000 is the CHARACTER
        // here as much as inside a fence - and normalize() would otherwise
        // rewrite it to `\ `, a literal backslash and a space inside backticks
        // (carve-php#829). Same sentinel protectVerbatim() uses.

        $fence = $this->safeFence($content, 1);

        // Pad exactly where the parser strips, so the strip is reversible and fmt
        // stays idempotent; the padding sits inside the fence, so a trailing
        // attribute block still attaches to the closing run. The parser strips
        // one leading and one trailing space when the content BOTH begins and
        // ends with a space but is NOT entirely spaces (see
        // InlineParser::stripVerbatimPadding), and needs a space around
        // backtick-adjacent content. All-space content must therefore NOT be
        // padded: it is emitted verbatim and read back unchanged. Padding it
        // instead grew the span by two spaces on every fmt pass. One-sided space
        // is left as-is (the parser only strips when both sides are spaces).
        $needsPad = str_starts_with($content, '`')
            || str_ends_with($content, '`')
            || (str_starts_with($content, ' ')
                && str_ends_with($content, ' ')
                && strspn($content, ' ') !== strlen($content));

        return $needsPad
            ? $fence . ' ' . $content . ' ' . $fence
            : $fence . $content . $fence;
    }

    /**
     * renderAttrs(), minus keys the DEFINITION already carries.
     *
     * Resolution copies a definition's attributes onto every link resolving the
     * label so HTML can render them (PART 9R R1). They belong to the definition
     * on the wire (PART 12 §10), so writing them at the reference too says the
     * same thing twice and does not re-parse to the same tree.
     *
     * @param \MarkupCarve\Carve\Node\Node|null $node
     * @param array<string, string> $definitionAttributes
     */
    protected function renderAttrsExcept(?Node $node, array $definitionAttributes): string
    {
        if ($node === null || $definitionAttributes === []) {
            return $this->renderAttrs($node);
        }

        $own = $node->getAttributes();
        foreach ($definitionAttributes as $key => $value) {
            // CLASSES SUBTRACT PER TOKEN. `class` is the one attribute that
            // MERGES rather than replaces: a `{.lead}` line above and a `{.trail}`
            // block at the reference arrive on the node as the single string
            // `lead trail`, which equals neither source. Comparing whole values
            // therefore subtracted nothing, and the writer emitted `{.lead
            // .trail}` beside a reference that already said `.trail` - the
            // duplicate growing by one on every pass (carve-php#839).
            if ($key === 'class') {
                $stated = preg_split('/\s+/', trim((string)$value)) ?: [];
                $mine = preg_split('/\s+/', trim((string)($own['class'] ?? ''))) ?: [];
                $left = array_values(array_filter(
                    $mine,
                    static fn (string $class): bool => $class !== '' && !in_array($class, $stated, true),
                ));
                if ($left === []) {
                    unset($own['class']);
                } else {
                    $own['class'] = implode(' ', $left);
                }

                continue;
            }
            if (($own[$key] ?? null) === $value) {
                unset($own[$key]);
            }
        }
        if ($own === $node->getAttributes()) {
            return $this->renderAttrs($node);
        }

        // NOT via a clone. Both `setAttributes()` and `setAttributesWithOrder()`
        // MERGE into what the node already holds, so setting the subtracted list
        // on a copy put every removed key straight back - this subtraction could
        // never fire, on any input, for as long as it has existed
        // (carve-php#831). The attribute list is rendered directly instead.
        return $this->renderAttrList($own, $node->getAttributeOrder());
    }

    /**
     * PART 9 §17 L7 for a LIST, decided from the written body alone.
     *
     * @param bool $isLoose whether the list is loose at all
     * @param int $itemCount how many items the written body holds
     * @param string $body the body as written, without any key
     */
    public static function looseKeyIsNeededForBody(bool $isLoose, int $itemCount, string $body): bool
    {
        if (!$isLoose || $itemCount === 0) {
            return false;
        }
        // A BLANK LINE BETWEEN ITEMS ALWAYS LOOSENS (§17 L2), and both writers
        // emit one between every pair of a loose list's items, so two or more
        // items already spell it. A FAST PATH, not a rule: the re-parse below
        // reaches the same answer.
        if ($itemCount > 1) {
            return false;
        }
        // A body with NO blank line in it cannot re-read loose either way, so
        // the common shape - a one-item list holding one paragraph - is
        // answered without a parse.
        if (preg_match('/\n[ \t]*\n/', $body) !== 1) {
            return true;
        }

        try {
            $first = (new CarveConverter())->parse($body)->getChildren()[0] ?? null;
        } catch (Throwable) {
            // A writer bug that produces unparseable source must not throw out
            // of the writer, and the conservative answer is the mark.
            return true;
        }

        return !$first instanceof ListBlock || $first->isTight();
    }

    /**
     * Does this container's looseness need the `{loose}` key spelled?
     *
     * Only where the blank-line spelling cannot say it (PART 9 §17 L7), because
     * a mark that says what the layout already says is an idle one.
     */
    protected function needsLooseKey(ListBlock|DefinitionList $node, string $body): bool
    {
        if ($node instanceof ListBlock) {
            $items = $node->getChildren();
            if ($node->isTight() || $items === []) {
                return false;
            }

            // ONE ITEM has no "between items" for a blank line to stand in, so
            // the only spelling left is one the item's own content produces -
            // and whether it does is the PARSER's question, not a shape this
            // writer can read off the tree. §17's looseness rules (L1, L2, L6)
            // decide it together, so a second copy of them here would answer
            // differently the day any of them moves: a lead container holding a
            // blank line re-reads LOOSE, while the same blank line before a
            // fence does not.
            //
            // The procedure itself is {@see self::looseKeyIsNeededForBody()},
            // which the HTML importer's writer calls too.
            return self::looseKeyIsNeededForBody(true, count($items), $body);
        }

        return $node->isLoose();
    }

    protected function renderAttrs(?Node $node): string
    {
        if ($node === null) {
            return '';
        }

        return $this->renderAttrList($node->getAttributes(), $node->getAttributeOrder());
    }

    /**
     * The `{...}` block for an attribute list, in the author's slot order.
     *
     * Takes the LIST rather than the node so a caller can render a subset, which
     * renderAttrsExcept() needs and could not express through a node copy.
     *
     * @param array<string, string> $attrs
     * @param list<string> $order
     */
    protected function renderAttrList(array $attrs, array $order): string
    {
        if ($attrs === []) {
            return '';
        }
        $parts = [];
        $seen = [];
        $emit = function (string $slot) use (&$parts, &$seen, $attrs): void {
            if ($slot === '#id') {
                if (isset($seen['id']) || !array_key_exists('id', $attrs)) {
                    return;
                }
                $seen['id'] = true;
                $id = $attrs['id'];
                $parts[] = $this->isExplicitIdOrClassIdentifier($id) ? '#' . $this->escapeAttrNameValue($id) : 'id=' . $this->quoteAttrValue($id);

                return;
            }
            if ($slot === '.class') {
                foreach (preg_split('/\s+/', trim($attrs['class'] ?? '')) ?: [] as $class) {
                    if ($class !== '') {
                        $parts[] = '.' . $this->escapeAttrNameValue($class);
                    }
                }

                return;
            }
            if (
                isset($seen[$slot])
                || !array_key_exists($slot, $attrs)
                || $slot === 'id'
                || $slot === 'class'
                || $slot === "\0carve-col-widths"
                || $slot === "\0carve-delimiter-row"
                || $slot === "\0carve-compact-items"
                || $slot === "\0carve-leading-blank"
                || $slot === "\0carve-prefix-attrs"
                || $slot === "\0carve-indent-blank-lines"
                || $slot === "\0carve-literal-symbol"
                || $slot === "\0carve-literal-inline-opener"
                || $slot === "\0carve-literal-caret"
                || $slot === "\0carve-stored-source"
                || $slot === "\0carve-compact-definition"
            ) {
                return;
            }
            $seen[$slot] = true;
            $value = $attrs[$slot];
            // EXACT key match, not case-insensitive: `LANG` and `lang` are
            // different attribute names, so folding here rewrote
            // `[x]{LANG=fr}` into `[x]{:fr}` and changed the name, which
            // breaks PART 11 SS1 (carve#1137).
            if ($slot === 'lang' && ($value === '' || preg_match('/^[A-Za-z0-9]{1,8}(?:-[A-Za-z0-9]{1,8})*$/D', $value) === 1)) {
                $parts[] = ':' . $value;
            } elseif ($value === '' && $this->isBooleanAttrName($slot)) {
                // PART 11 SS6c: a value-less attribute comes back as the bare
                // name, which is the production the language has for it. A key
                // needing escaping has no bare spelling to fall back to, and
                // neither does a `_`-first name: `boolean_attribute` refuses the
                // leading underscore (carve#1450), so `{_u=""}` written bare is
                // text and `{_x_=""}` is a forced underline. Either way the
                // writer would change the document, which PART 11 SS1 forbids.
                $parts[] = $this->escapeAttrKey($slot);
            } else {
                $parts[] = $this->escapeAttrKey($slot) . '=' . $this->quoteAttrValue($value);
            }
        };

        if ($order !== []) {
            foreach ($order as $slot) {
                $emit($slot);
            }
            foreach ($attrs as $key => $_value) {
                // An id with no `#id` slot is a GENERATED one - since carve#750
                // a heading's slugged id is on the wire, so a decoded node
                // carries it - and a writer reproduces what the author wrote.
                // A generated id was not written by the author.
                if ((string)$key === 'id' && !in_array('#id', $order, true)) {
                    continue;
                }
                $emit((string)$key);
            }
        } else {
            // NO SLOTS AT ALL. An `id` here is a GENERATED one - since carve#750
            // a heading's slugged id is published, and an AUTHORED id always
            // carries its `#id` slot - so emitting it writes `{#Welcome}` above
            // a heading whose source has no attribute block. A programmatic
            // tree that wants the id in the source records the slot.
            if (!array_key_exists('id', $attrs)) {
                $emit('#id');
            }
            $emit('.class');
            foreach ($attrs as $key => $_value) {
                $emit((string)$key);
            }
        }

        return $parts === [] ? '' : '{' . implode(' ', $parts) . '}';
    }

    /**
     * Protect a paragraph line that would re-parse as a thematic break.
     *
     * Source indentation is not in the AST, so an indented `---` - a paragraph
     * holding an em dash - is emitted at column 0, where it stops being a
     * paragraph and becomes a thematic break.
     *
     * Text nodes are already covered: the conservative form escapes the
     * hyphens, so the round-trip check sees the difference and picks that form.
     * A smart-punctuation run is not, because its source run is emitted
     * verbatim in BOTH forms - that is the point of the node - so the check
     * never has a difference to act on. Escaping the run in the conservative
     * form does not work either: it would make that form change the document,
     * and the check could then never prefer the minimal one.
     *
     * It INDENTS rather than escapes: escaping would split the run (a leading
     * escaped hyphen plus an en dash) and change the document just as surely,
     * while a single leading space keeps the line a paragraph and keeps the em
     * dash - which is what the source said.
     *
     * The marker is a sentinel rather than a literal space because
     * normalize() trims the document's leading whitespace, which would
     * silently undo the guard whenever the paragraph is the first block.
     */
    protected function guardThematicBreakLines(string $body): string
    {
        if (!str_contains($body, '-')) {
            return $body;
        }

        $lines = explode("\n", $body);
        foreach ($lines as $i => $line) {
            if (preg_match('/^-{3,}[ \t]*$/', $line) === 1) {
                $lines[$i] = $this->verbatimSentinels[3] . $line;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Write a line block's preserved whitespace back as ordinary spaces.
     *
     * The parser records it with the U+E000 placeholder - the same sentinel an
     * escaped space uses, so it never collides with a literal nbsp - and
     * normalize() resolves every remaining one to a real nbsp. That is right
     * for an escaped space and wrong here: the source form of a line block's
     * layout is plain spaces, and a real nbsp re-parses as literal text rather
     * than as layout, so the text node came back different (carve#359).
     *
     * The runs handed to the verbatim scheme - which restores plain spaces
     * after normalize() has run - are exactly the ones the parser reproduces
     * from plain spaces (PART 9 §23, carve#487): a LEADING run of any width,
     * and a medial or trailing run of TWO OR MORE. A lone medial sentinel can
     * then only have come from an escaped space, so `a\ b` still round-trips
     * as written. Two adjacent escaped spaces are the one form that changes -
     * `a\ \ b` is written back as two plain spaces - because inside a line
     * block the two are the same document: both parse to the same pair of
     * sentinels.
     */
    protected function resolveIndentPlaceholder(string $text): string
    {
        if ($this->inLineBlock === 0) {
            return $text;
        }

        return (string)preg_replace_callback(
            '/(?:^' . preg_quote($this->verbatimSentinels[4], '/') . '+)|' . preg_quote($this->verbatimSentinels[4], '/') . '{2,}/mu',
            fn (array $m): string => str_repeat($this->verbatimSentinels[0], (int)mb_strlen($m[0], 'UTF-8')),
            $text,
        );
    }

    protected function normalize(string $text): string
    {
        $text = str_replace($this->verbatimSentinels[4], '\ ', $text);
        $lines = explode("\n", $this->trimNonNbsp($text));
        foreach ($lines as $i => $line) {
            // Strip a line's trailing whitespace only where it cannot be
            // content. At the end of a paragraph the parser drops it too, so
            // the writer must; before a SOFT BREAK the parser keeps it, and
            // stripping it there changed the rendered output (carve#359).
            // A line whose successor is blank ends its block; one followed by
            // more text is mid-paragraph.
            // A line is blank when it holds only whitespace, counting the
            // non-breaking space: PHP trim() leaves NBSP in place where JS
            // trim() removes it, and the two writers must agree on which lines
            // end a block.
            // A line whose only content is ASCII space or tab is emitted EMPTY,
            // wherever it sits (PART 11 section 7). Editors and CI that strip
            // trailing whitespace rewrite such a line, so `fmt` would report a
            // diff on a file nobody edited (carve#375). NBSP is excluded because
            // it is content the author wrote, and verbatim content is still
            // sentinel-protected here, so three spaces inside a code block are
            // not reachable by this and stay intact.
            if ($line !== '' && trim($line, " \t") === '') {
                $lines[$i] = '';

                continue;
            }
            // THE RUN IS `whitespace`, A SPACE OR A TAB, AND NOTHING ELSE.
            // `\S` under `/u` is the Unicode property, so `[^\S<NBSP>]` reached
            // U+000B, U+000C, U+0085, U+1680, U+2000, U+2009, U+200A, U+202F,
            // U+205F and U+2028 - every one of them CONTENT under carve#890,
            // where `whitespace = ' ' | '\t'`. The writer DELETED them, so a
            // paragraph whose lines carry an invisible character came back a
            // character short and `to_html(fmt(x)) == to_html(x)` failed on it.
            // Excluding NBSP by name was the tell: the class was wide enough to
            // need an exception carved out of it, and NBSP was only the member
            // anybody had noticed.
            $next = $lines[$i + 1] ?? null;
            if ($next !== null && preg_replace('/[\s\x{00A0}]+/u', '', $next) !== '') {
                continue;
            }
            $lines[$i] = (string)preg_replace('/[ \t]+$/', '', $line);
        }
        $text = implode("\n", $lines);
        // The squeeze runs FIRST, so a decorative run still normalizes to one
        // blank line; the boundary sentinel is not a newline yet and passes
        // through untouched. Only the writer knows which run is which.
        $text = (string)preg_replace("/\n{3,}/", "\n\n", $text);
        // The boundary tag opens the line it sits on, and everything to its LEFT
        // is the prefix its host had already put there - two columns of a list
        // item's content, `> ` from a blockquote, both together when a list sits
        // in a quote. The three blank lines have to carry that same prefix,
        // minus its trailing whitespace, because that is how each host spells a
        // blank line: a list item writes nothing, a blockquote writes `>`.
        // Taking the prefix from the line rather than passing it down means no
        // host has to know the boundary exists.
        //
        // ONE TAG PER LINE, always: every site that writes one puts it directly
        // after a newline, so the lazy prefix cannot run past a line it does not
        // own.
        $text = (string)preg_replace_callback(
            '/^(.*?)' . preg_quote($this->listBoundary(), '/') . '/mu',
            static function (array $m): string {
                $prefix = $m[1];
                $blank = (string)preg_replace('/[ \t]+$/', '', $prefix);

                return $blank . "\n" . $blank . "\n" . $blank . "\n" . $prefix;
            },
            $text,
        );

        $out = $this->restoreVerbatim($this->trimNonNbsp($text));

        // A DOCUMENT MAY NOT BEGIN WITH U+FEFF, because the parser reads a BOM
        // at byte 0 as the file's encoding mark and removes it (PART 12). A
        // paragraph whose first character is a BOM is ordinary content - it
        // only reaches byte 0 because the writer dropped the indentation run in
        // front of it - so writing it bare turns `<p>&#65279;</p>` into an
        // empty document on the next read. One leading SPACE restores the
        // distinction: it is an indentation run the parser strips again, and it
        // keeps the BOM off byte 0. There is no escape for U+FEFF to reach for
        // instead.
        if (str_starts_with($out, "\u{FEFF}")) {
            $out = ' ' . $out;
        }

        return $out . "\n";
    }

    /**
     * Whole-document normalization (trailing-whitespace strip, blank-line
     * collapsing) must not reach inside verbatim content - code blocks, raw
     * blocks, frontmatter, and block comments reproduce their content
     * byte-exact (carve-js issue 340). Sentinel-encode the vulnerable bytes
     * before the content joins the document string; normalize() restores
     * them at the end. U+E000 is already the NBSP sentinel; U+E001..U+E003
     * extend the scheme.
     */
    protected function protectVerbatim(string $content): string
    {
        // An authored U+E000 inside verbatim content is the CHARACTER, not an
        // escape. normalize() rewrites every U+E000 to `\ `, which is right
        // outside verbatim and wrong inside it - escapes do not resolve in a code
        // block, so `\ ` there is a literal backslash and a space, and
        // toHtml(fmt(x)) != toHtml(x) (carve-php#829). Carrying it under its own
        // sentinel keeps it out of that rewrite; restoreVerbatim puts the
        // character back. carve-rs already emits it as itself.

        $content = (string)preg_replace_callback(
            '/[ \t]+(?=\n|$)/',
            fn (array $m): string => strtr(
                $m[0],
                [' ' => $this->verbatimSentinels[0], "\t" => $this->verbatimSentinels[1]],
            ),
            $content,
        );
        $lines = explode("\n", $content);
        foreach ($lines as $i => $line) {
            if ($line === '') {
                $lines[$i] = $this->verbatimSentinels[2];
            }
        }

        return implode("\n", $lines);
    }

    protected function restoreVerbatim(string $text): string
    {
        $marker = preg_quote($this->verbatimSentinels[2], '/');
        $text = (string)preg_replace_callback(
            '/^([ \t>]*)' . $marker . '$/m',
            static fn (array $m): string => rtrim($m[1], " \t"),
            $text,
        );

        $result = strtr($text, [
            $this->verbatimSentinels[0] => ' ',
            $this->verbatimSentinels[1] => "\t",
            $this->verbatimSentinels[2] => '',
        ]);

        // U+E004 marks a paragraph line that must not begin at column 0. It
        // resolves AFTER normalize()'s trims, which would otherwise strip a
        // plain leading space when the paragraph is the document's first block.
        return str_replace($this->verbatimSentinels[3], ' ', $result);
    }

    /**
     * Fold every line break in $text (a hard break's marker included) to a
     * single space, then trim.
     *
     * A heading is SINGLE-LINE (PART 2), so its text must not contain a
     * newline: writing one would end the heading and re-parse the remainder as
     * a following block, moving text out of the title. No parse builds such a
     * heading, but PART 12 lets an ingested AST put any inline in one, break
     * nodes included. Only an ODD run of backslashes before the newline is a
     * hard break's marker; an even run is literal backslashes that happen to
     * end the line, and dropping one there would eat the escape. Matches
     * carve-js and carve-rs.
     */
    protected function collapseBreaks(string $text): string
    {
        return $this->trimNonNbsp($this->collapseBreaksUntrimmed($text));
    }

    /**
     * The collapse alone, for a caller that trims by its own construct's rule.
     *
     * A heading trims only spaces off the front, so it cannot go through the
     * trim baked into `collapseBreaks()`, which takes a tab with it.
     */
    protected function collapseBreaksUntrimmed(string $text): string
    {
        $collapsed = preg_replace_callback(
            '/(\\\\*)\\n[ \\t]*/',
            static fn (array $m): string => (strlen($m[1]) % 2 === 1 ? substr($m[1], 1) : $m[1]) . ' ',
            $text,
        );

        return (string)$collapsed;
    }

    /**
     * Trim the document's own leading and trailing whitespace.
     *
     * `whitespace` is a space or a tab (PART 1, markup-carve/carve#890), plus
     * the line endings this is trimming at a document boundary. Every other
     * invisible character is CONTENT: the class was `[^\S<NBSP>]` under `/u`,
     * i.e. the Unicode property with NBSP carved out by name, and it ate a
     * trailing U+000C, U+0085, U+1680, U+2000 or U+2028 off the end of the
     * document - so `fmt` deleted the last character of a document that ended
     * in one. Excluding NBSP by name was the tell; NBSP was only the member
     * anybody had noticed.
     */
    protected function trimNonNbsp(string $text): string
    {
        return trim($text, " \t\n\r");
    }

    /**
     * A heading's separator run is SPACES, so only spaces may be trimmed off
     * the front.
     *
     * The marker takes `space+` and every one of those spaces is separator,
     * but `space` is U+0020 alone: the run ends at the first character that is
     * not one, and that character BEGINS the heading. So `##<SP><TAB>x` is the
     * heading `<TAB>x`, and writing it back as `##<SP>x` drops a character the
     * parser kept - PART 11 §1's first invariant, `parse(fmt(x)) == parse(x)`.
     * Written with the tab, `##<SP><TAB>x` re-reads as the same heading,
     * because the run still stops at the tab.
     *
     * A leading SPACE stays trimmed: the writer has no spelling for it, since
     * any space it emitted would be re-consumed as separator.
     */
    protected function trimHeadingText(string $text): string
    {
        return rtrim(ltrim($text, " \n\r"), " \t\n\r");
    }

    /**
     * A heading's text, keeping a hard break that ends it.
     *
     * `heading = hashes " "+ inline+ lineEnd` and `hardBreak = "\" (newline |
     * &end)`, so a trailing `\` stays inside the heading: `## x\` re-reads as
     * `<h2>x<br></h2>`. Every other break has nowhere to go, because a heading
     * ends at its newline, and collapses to a space the way carve-rs writes it.
     * A break nested in an inline construct is never the trailing one: the
     * construct still has to close after it.
     */
    protected function headingText(string $rendered): string
    {
        if (preg_match('/(?<!\\\\)((?:\\\\\\\\)*)\\\\\\n[ \\t]*$/', $rendered, $match, PREG_OFFSET_CAPTURE)) {
            $body = substr($rendered, 0, $match[0][1] + strlen($match[1][0]));

            return ltrim($this->collapseBreaksUntrimmed($body), " \n\r") . '\\';
        }

        return $this->trimHeadingText($this->collapseBreaksUntrimmed($rendered));
    }

    protected function trimEndNonNbsp(string $text): string
    {
        return rtrim($text, " \t\n\r");
    }

    protected function safeFence(string $content, int $min): string
    {
        preg_match_all('/`+/', $content, $matches);
        $longest = 0;
        foreach ($matches[0] as $match) {
            $longest = max($longest, strlen($match));
        }

        return str_repeat('`', max($min, $longest + 1));
    }

    protected function lastBoundary(?Node $node): string
    {
        $text = $this->inlineBoundaryText($node);

        return $text === '' ? '' : substr($text, -1);
    }

    protected function firstBoundary(?Node $node): string
    {
        $text = $this->inlineBoundaryText($node);

        return $text === '' ? '' : $text[0];
    }

    protected function inlineBoundaryText(?Node $node): string
    {
        if ($node instanceof Text) {
            return $node->getContent();
        }
        if ($node instanceof EscapedText) {
            return $node->getContent();
        }
        if ($node instanceof Code) {
            return $node->getContent();
        }

        return '';
    }

    protected function plainInlineText(Node $node): string
    {
        $out = '';
        foreach ($node->getChildren() as $child) {
            if ($child instanceof Text) {
                $out .= $child->getContent();
            } elseif ($child instanceof EscapedText) {
                $out .= $child->getContent();
            } else {
                $out .= $this->plainInlineText($child);
            }
        }

        return $out;
    }

    /**
     * The sigil for an alignment, read off the parser's own set so the writer
     * and the reader cannot drift.
     */
    protected function alignMarker(string $align): string
    {
        $marker = array_search($align, BlockParser::TABLE_ALIGNMENT_MARKERS, true);

        return $marker === false ? '' : $marker;
    }

    /**
     * Mark the lone brackets and destination-opening parens of every inline
     * run, once per document (PART 11 §5, markup-carve/carve#2357).
     */
    protected function planStructuralEscapes(Node $node): void
    {
        $run = [];
        foreach ($node->getChildren() as $child) {
            if ($child instanceof InlineNode) {
                $run[] = $child;

                continue;
            }
            $this->planInlineRun($run, false);
            $run = [];
            $this->planStructuralEscapes($child);
        }
        $this->planInlineRun($run, false);
    }

    /**
     * Pair the bare brackets of one run's text, then mark what §5 escapes.
     *
     * Only inside content a construct wraps in its own brackets is an unpaired
     * bracket lone; the paren rule applies to every run.
     *
     * @param array<\MarkupCarve\Carve\Node\Node> $nodes
     * @param bool $bracketed
     */
    protected function planInlineRun(array $nodes, bool $bracketed): void
    {
        if ($nodes === []) {
            return;
        }
        $flat = '';
        $marks = [];
        if (!$this->collectBracketMarks($nodes, $flat, $marks) || $marks === []) {
            return;
        }
        $open = [];
        $paired = [];
        $closers = [];
        foreach ($marks as $i => [$at, , , $char]) {
            if ($char === '[') {
                $open[] = $i;
            } elseif ($char === ']' && $open !== []) {
                $paired[array_pop($open)] = true;
                $paired[$i] = true;
                $closers[$at] = true;
            }
        }
        $scan = null;
        foreach ($marks as $i => [$at, $id, $offset, $char]) {
            if ($char === '(') {
                if (!isset($closers[$at - 1])) {
                    continue;
                }
                $scan ??= self::destinationScan($flat);
                if (!self::opensADestination($scan, $flat, $at)) {
                    continue;
                }
            } elseif (!$bracketed || isset($paired[$i])) {
                continue;
            }
            $this->structuralEscapes[$id][$offset] = true;
        }
    }

    /**
     * The run as the minimal form writes it, closely enough to pair its
     * brackets and read a destination, and where its brackets and parens sit.
     *
     * A construct writing its own brackets is planned as a run of its own, one
     * that writes none lends its text to this run, and verbatim content and
     * every other node take no part. Each stands in as a space, which ends a
     * destination, so the approximation can miss an escape but never invent
     * one. An inline extension's reader stops at the first `]` without
     * pairing, so its content gets the paren rule only.
     *
     * @param array<\MarkupCarve\Carve\Node\Node> $nodes
     * @param string $flat
     * @param array<int, array{int, int, int, string}> $marks
     *
     * @return bool False where the scan stopped early.
     */
    protected function collectBracketMarks(array $nodes, string &$flat, array &$marks): bool
    {
        foreach ($nodes as $node) {
            // An empty code span is written as a bare backtick run, which can
            // swallow what follows it, so the scan ends there.
            if ($node instanceof Code && $node->getContent() === '') {
                return false;
            }
            if ($node instanceof Text) {
                $content = str_replace("\r", '', $node->getContent());
                if (strpbrk($content, '[](') !== false) {
                    $id = spl_object_id($node);
                    preg_match_all('/[\[\](]/', $content, $found, PREG_OFFSET_CAPTURE);
                    foreach ($found[0] as [$char, $offset]) {
                        $marks[] = [strlen($flat) + $offset, $id, $offset, $char];
                    }
                }
                $flat .= $content;
            } elseif (
                $node instanceof Span
                || ($node instanceof Link && !$node->isAutolink())
                || $node instanceof InlineFootnote
            ) {
                $this->planInlineRun($node->getChildren(), true);
                $flat .= ' ';
            } elseif ($node instanceof InlineExtension) {
                $this->planInlineRun($node->getChildren(), false);
                $flat .= ' ';
            } elseif (
                $node instanceof Emphasis || $node instanceof Strong || $node instanceof Underline
                || $node instanceof Strike || $node instanceof Superscript || $node instanceof Subscript
                || $node instanceof Highlight || $node instanceof Insert || $node instanceof Delete
            ) {
                // Their delimiters are written, and none is a space or a paren.
                $flat .= "\x01";
                if (!$this->collectBracketMarks($node->getChildren(), $flat, $marks)) {
                    return false;
                }
                $flat .= "\x01";
            } else {
                $flat .= ' ';
            }
        }

        return true;
    }

    /**
     * Each `(` of a run's matching `)`, and the offsets of its whitespace,
     * found once so a run of `[a](` stays linear.
     *
     * The minimal form escapes every backslash and quote in text, so neither
     * can escape a paren or open a title here.
     *
     * @param string $flat
     *
     * @return array{array<int, int>, array<int, int>}
     */
    private static function destinationScan(string $flat): array
    {
        $close = [];
        $open = [];
        preg_match_all('/[()]/', $flat, $parens, PREG_OFFSET_CAPTURE);
        foreach ($parens[0] as [$paren, $offset]) {
            if ($paren === '(') {
                $open[] = $offset;
            } elseif ($open !== []) {
                $close[array_pop($open)] = $offset;
            }
        }
        if (preg_match_all('/[\s\p{Z}\x{0085}]/u', $flat, $spaces, PREG_OFFSET_CAPTURE) === false) {
            return [[], []];
        }

        return [$close, array_column($spaces[0], 1)];
    }

    /**
     * Does the written text from the `(` at `$at` read as an inline link
     * destination that closes?
     *
     * @param array{array<int, int>, array<int, int>} $scan
     * @param string $flat
     * @param int $at
     */
    private static function opensADestination(array $scan, string $flat, int $at): bool
    {
        [$close, $spaces] = $scan;
        $end = $close[$at] ?? null;
        if ($end === null || $end === $at + 1) {
            return false;
        }
        $low = 0;
        $high = count($spaces);
        while ($low < $high) {
            $mid = ($low + $high) >> 1;
            if ($spaces[$mid] <= $at) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }
        if ($low < count($spaces) && $spaces[$low] < $end) {
            return false;
        }

        return !($flat[$at + 1] === '<' && $flat[$end - 1] === '>');
    }

    /**
     * @param string $text
     * @param bool $opensBlockLine
     * @param bool $nextOpensVerbatim
     * @param array<int, true> $structural Offsets escaped in both forms.
     */
    protected function escapeText(
        string $text,
        bool $opensBlockLine = false,
        bool $nextOpensVerbatim = false,
        array $structural = [],
    ): string {
        // ONLY WHAT WOULD CHANGE THE RE-PARSE. The class here was every C0
        // control but tab and newline, plus DEL and the whole C1 block - a
        // blanket sanitizer, and the writer was the only artifact applying it.
        // The parser keeps those characters, the AST carries them, and the HTML
        // renderer emits them (corpus 261), so `fmt` was the one place an
        // author's byte disappeared: `to_html(fmt(x)) == to_html(x)` failed on
        // any document holding a vertical tab, a form feed or a C1 character.
        //
        // `\r` is the exception and keeps being stripped, because it is not
        // inert: `newline = '\n' | '\r\n' | '\r'`, so emitting one would end
        // the line and re-parse the rest of the text node as a following block.
        // A parse never produces one - line endings are normalized first - but
        // PART 12 lets an ingested tree carry any string at all.
        $text = str_replace("\r", '', $text);
        if (preg_match('/^\[\^[^\]\n]+\]$/u', $text) === 1) {
            return $text;
        }

        $minimal = $this->escapeModeHere() === self::ESCAPE_MODE_MINIMAL;
        $call = $minimal ? 0 : $this->nextEscapeCallIndex();
        // `!` AND `$` JOIN THEIR CLASSES SO THE BINDING CASE CAN BE FORCED.
        // Both are returned bare below wherever they do not bind, so the only
        // renders that change are the ones where the escape is structural: `!`
        // was already in the conservative class and is new to the minimal one,
        // and `$` was in neither.
        $pattern = $minimal
            ? ($structural === [] ? '/([\\\\`"\'^!$])/' : '/([\\\\`"\'^!$\[\](])/')
            : '/([\\\\`*_{}\[\]()#+\-.!~^\/<>@%|=:;"\'$])/';
        $insideNote = $this->inlineNoteDepth > 0;
        // The LAST braced-superscript closer in this text, found once.
        //
        // `caretOpensAConstruct()` asks whether a closer lies at or after a
        // given offset, and the answer is `$lastSupCloser >= $offset`: if the
        // last one is not there, none is. Asking `strpos()` per caret instead
        // rescans the tail once for every `{^` in the text, which is quadratic
        // on `{^{^{^...^}` - the class of unclosed-run scan this engine has had
        // to fix three times already. The reader avoids it the same way, with
        // the memoized `strrpos` in InlineParser::closerExistsFrom().
        $lastCloser = strrpos($text, '^}');
        $lastSupCloser = $lastCloser === false ? -1 : $lastCloser;

        return (string)preg_replace_callback(
            $pattern,
            function (array $match) use (
                $text,
                $opensBlockLine,
                $insideNote,
                $minimal,
                $nextOpensVerbatim,
                $lastSupCloser,
                $call,
                $structural,
            ): string {
                $char = $match[1][0];
                $offset = $match[1][1];
                if (isset($structural[$offset])) {
                    return '\\' . $char;
                }
                if ($minimal && ($char === '[' || $char === ']' || $char === '(')) {
                    return $char;
                }
                // PART 11 section 2's decision is taken per OPENER OCCURRENCE.
                // In a unit the search has escalated, each candidate site is
                // offered back on its own, so the one occurrence that needed
                // the escape no longer drags the rest of the unit with it. The
                // unconditional set is not a candidate and is never offered.
                if (
                    !$minimal
                    && !str_contains(self::NOT_OFFERED_PER_OCCURRENCE, $char)
                    && $this->occurrenceIsRelaxed(
                        $call,
                        $offset,
                        $offset > 0 && $text[$offset - 1] === $char && !isset($structural[$offset - 1]),
                    )
                ) {
                    return $char;
                }
                if ($char === '^' && self::caretOpensACaption($text, $offset, $opensBlockLine)) {
                    // Forced in BOTH modes - see the note on the method.
                    return '\\^';
                }
                // `!` is decided by the guard in BOTH passes, like `$` below.
                // §27 reinterprets it only where it binds to a following
                // backtick run, so anywhere else the backslash would add an
                // `escaped_text` node the source never had, which is what §2
                // forbids (carve-php#2013).
                if ($char === '!' && !self::sigilBindsToAVerbatimRun($text, $offset, $nextOpensVerbatim)) {
                    return '!';
                }
                // `$` was in NEITHER class, so both passes wrote it bare and
                // both are unchanged away from the binding case.
                if ($char === '$' && !self::sigilBindsToAVerbatimRun($text, $offset, $nextOpensVerbatim)) {
                    return '$';
                }
                if ($char === '^' && !self::caretOpensAConstruct($text, $offset, $insideNote, $lastSupCloser)) {
                    return '^';
                }
                // A COLON only opens something at the start of a line - `::`
                // opens a definition term, `:::` a div, and a caption's
                // `^ Figure #:` is read from the marker. Mid-line it is
                // ordinary punctuation, and PART 11 §2 escapes a character
                // only where omitting it would change the re-parse. Escaping
                // every colon put `\:` in `\^ Figure 1\: moon`, where the
                // caret is already escaped so nothing downstream reads the
                // colon at all (carve-php#743).
                if ($char === ':' && !self::opensLine($text, $offset)) {
                    return ':';
                }

                return '\\' . $char;
            },
            $text,
            flags: PREG_OFFSET_CAPTURE,
        );
    }

    protected function escapeImportedText(Text $node, string $rendered): string
    {
        if ($node->getRenderHint("\0carve-literal-inline-opener") === '1') {
            $rendered = (string)preg_replace('/(?<!\\\\)\[/', '\\\\[', $rendered, 1);
        }
        if ($node->getRenderHint("\0carve-literal-caret") === '1') {
            $rendered = (string)preg_replace('/(?<!\\\\)\^/', '\\\\^', $rendered, 1);
        }
        if ($node->getRenderHint("\0carve-literal-symbol") === '1') {
            $rendered = (string)preg_replace_callback(
                '/(?<!\\\\):((?:\\\\[+\-\[]|[\w+\-])+)(:|\\\\?\[)/',
                static fn (array $match): string => '\\:' . $match[1] . $match[2],
                $rendered,
            );
        }

        return $rendered;
    }

    /**
     * Is this offset at the start of a line within the node's text?
     *
     * A construct opens at a line start; mid-line the same character is
     * punctuation. The node's own first character counts, because a text node
     * that begins a paragraph begins a line.
     *
     * @param string $text
     * @param int $offset
     */
    private static function opensLine(string $text, int $offset): bool
    {
        for ($i = $offset - 1; $i >= 0; $i--) {
            $char = $text[$i];
            if ($char === "\n") {
                return true;
            }
            if ($char !== ' ' && $char !== "\t") {
                return false;
            }
        }

        return true;
    }

    /**
     * Does a `!` or `$` here BIND to the verbatim run that follows it?
     *
     * @param string $text
     * @param int $offset
     * @param bool $nextOpensVerbatim Whether the next sibling renders as a
     *   backtick run. A code span is the only inline whose written form opens
     *   with one; an inline literal opens with its own `!` and math with its
     *   own `$`.
     */
    private static function sigilBindsToAVerbatimRun(string $text, int $offset, bool $nextOpensVerbatim): bool
    {
        return $nextOpensVerbatim && $offset === strlen($text) - 1;
    }

    /**
     * Does this node's written form OPEN with a backtick run a sigil binds to?
     */
    private static function opensAVerbatimRun(?Node $node): bool
    {
        if ($node instanceof RawInline) {
            return true;
        }

        return $node instanceof Code && $node->getContent() !== '';
    }

    /**
     * Is this caret a CAPTION MARKER - `^` plus a space at the start of a block
     * line?
     *
     * @param string $text
     * @param int $offset
     * @param bool $opensBlockLine Whether offset 0 of $text is a block-line start.
     */
    private static function caretOpensACaption(string $text, int $offset, bool $opensBlockLine): bool
    {
        $next = $text[$offset + 1] ?? '';
        if ($next !== ' ') {
            return false;
        }

        return $offset === 0 && $opensBlockLine;
    }

    /**
     * A note's content, rendered with footnote recognition off.
     *
     * The reader turns it off for the whole content at every depth, so the
     * writer has to hold the same frame while it walks the children: in
     * `^[a ^[b ^[c] d] e]` the parse finds ONE note and two runs of ordinary
     * text, and the writer that escaped the inner carets wrote a document that
     * no longer said that (markup-carve/carve#1191).
     */
    protected function renderInlineNoteContent(InlineFootnote $node): string
    {
        $this->inlineNoteDepth++;
        try {
            return $this->renderInlines($node->getChildren());
        } finally {
            $this->inlineNoteDepth--;
        }
    }

    /**
     * A mention or tag opens only after a non-word character and its name runs
     * to the last name character, so one written against a word has no
     * spelling (markup-carve/carve-js#1807).
     *
     * @throws \MarkupCarve\Carve\Exception\SourceUnspellableException
     */
    private function refuseGluedMention(Node $node, ?Node $previous, string $written, string $previousRendered, string $rendered): void
    {
        if ($node instanceof Mention && preg_match('/^([@#])/', $rendered, $sigil) === 1 && preg_match('/[A-Za-z0-9_]\z/', $written) === 1) {
            throw new SourceUnspellableException(self::sigilType($sigil[1]), 'it has no Carve source spelling after a word character');
        }
        if ($previous instanceof Mention && preg_match('/^([@#])/', $previousRendered, $sigil) === 1 && preg_match('/^\.?[A-Za-z0-9_-]/', $rendered) === 1) {
            throw new SourceUnspellableException(self::sigilType($sigil[1]), 'it has no Carve source spelling before a name character');
        }
    }

    private static function sigilType(string $sigil): string
    {
        return $sigil === '#' ? 'tag' : 'mention';
    }

    /**
     * Does this written run end in a backtick run the next one would merge with?
     */
    private static function endsInABareBacktickRun(string $written): bool
    {
        $run = strlen($written) - strlen(rtrim($written, '`'));

        return $run > 0 && self::backslashRunBefore($written, strlen($written) - $run) % 2 === 0;
    }

    private static function backslashRunBefore(string $text, int $offset): int
    {
        $run = 0;
        while ($offset - $run > 0 && $text[$offset - $run - 1] === '\\') {
            $run++;
        }

        return $run;
    }

    private static function caretOpensAConstruct(
        string $text,
        int $offset,
        bool $insideNote,
        int $lastSupCloser,
    ): bool {
        $next = $text[$offset + 1] ?? '';
        // `^[` opens an inline footnote - but only where a note can open at
        // all. PART 9 §16 rules out three positions, and none of them needs an
        // escape because the bare spelling re-parses as the same text
        // (markup-carve/carve#1191).
        if ($next === '[') {
            return !$insideNote && self::inlineNoteCouldOpen($text, $offset + 1);
        }

        // AN EMPTY BRACED PAIR IS TEXT, SO ITS CARETS OPEN NOTHING.
        // `{^^}` holds no content, so no superscript can start in it
        // (markup-carve/carve#1447), and escaping it manufactures the very
        // difference PART 11 §1 forbids: `{^^}` reads back as ONE text node
        // where `{\^\^}` reads back as text plus two escaped_text nodes plus
        // text. An escape is PRESERVED where the author wrote one, not INVENTED
        // where they did not - which is what this writer already does for a
        // bare caret in prose (`a ^ b` stays `a ^ b`).
        $previous = $text[$offset - 1] ?? '';
        if (
            ($previous === '{' && $next === '^' && ($text[$offset + 2] ?? '') === '}')
            || ($next === '}' && $previous === '^' && ($text[$offset - 2] ?? '') === '{')
        ) {
            return false;
        }

        if ($previous === '{') {
            return $lastSupCloser >= $offset + 1;
        }

        return false;
    }

    /**
     * Would the bracketed run at $openPos give a note a body to hold?
     *
     * "Empty or whitespace-only (`^[]`, `^[ ]`) is literal; an unclosed `^[…`
     * is literal." Both are decided by the run itself, so ask the reader's own
     * scan where it closes and look at what is inside. A run this text node
     * does not close is one the document does not close either: the caret sits
     * in a text node precisely because no note formed around it.
     *
     * @param string $text
     * @param int $openPos
     */
    private static function inlineNoteCouldOpen(string $text, int $openPos): bool
    {
        $close = BracketScanner::balancedBracketEnd($text, $openPos);
        if ($close === null) {
            return false;
        }

        // The parser's whitespace set, not PHP's. `trim()` also strips a
        // vertical tab and a NUL, which `parseInlineFootnote` does not, so an
        // ingested `^[<VT>]` is a real note there and would have been written
        // bare here - the one direction this rule must never get wrong.
        $body = substr($text, $openPos + 1, $close - $openPos - 1);

        return trim($body, StringUtil::WHITESPACE_CHARS) !== '';
    }

    /**
     * An image's alt text, written between the `![` and its closing `]`.
     */
    protected function escapeImageAlt(string $text): string
    {
        // A comment-only verse line is removed before an image's scalar ALT is
        // built, leaving an empty line with no comment node to carry it.  Spell
        // that loss the same way §7c spells an emptied verbatim line, and the
        // same way the reference-image snapshot already does.
        if (str_contains($text, "\n")) {
            $lines = explode("\n", $text);
            foreach ($lines as $index => $line) {
                if ($line === '') {
                    $lines[$index] = '%%';
                }
            }
            $text = implode("\n", $lines);
        }

        if (BracketScanner::rawRunCloses($text)) {
            return $text;
        }

        return str_replace(['\\', '[', ']'], ['\\\\', '\\[', '\\]'], $text);
    }

    protected function escapeDestination(string $text): string
    {
        $text = (string)preg_replace('/^[\x00-\x20\x{00a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}]+/u', '', $text);
        $scheme = null;
        if (preg_match('/^[\x00-\x20\x{00a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}]*([a-zA-Z][a-zA-Z0-9+.-]*):/u', $text, $m) === 1) {
            $scheme = strtolower($m[1]);
        }
        $sanitizeBlank = $scheme !== null && in_array($scheme, ['javascript', 'vbscript', 'data', 'file'], true);
        // Whitespace is percent-encoded (it would otherwise end the
        // destination). A parenthesis is escaped only when it is UNBALANCED: a
        // balanced pair re-parses as itself, so leaving it bare is both the
        // minimal escaping PART 11 section 4 asks for and what keeps the common
        // URL readable. A backslash is escaped only in front of the three
        // characters the destination scan treats as escapes, so backslashes
        // elsewhere in a URL stay verbatim.
        if (!$sanitizeBlank) {
            $text = $this->escapeDestinationEscapes($text);
        }
        $text = (string)preg_replace_callback('/\s/u', static fn (array $m): string => rawurlencode($m[0]), $text);

        return (string)preg_replace_callback('/[()]/', static fn (array $m): string => $sanitizeBlank ? ($m[0] === '(' ? '%28' : '%29') : $m[0], $text);
    }

    /**
     * Backslash-escape exactly what the destination scan would otherwise read
     * differently: a parenthesis with no partner, and a backslash sitting in
     * front of one of the three escapable characters.
     */
    protected function escapeDestinationEscapes(string $text): string
    {
        $length = strlen($text);
        $openers = [];
        $marked = [];
        for ($i = 0; $i < $length; $i++) {
            if ($text[$i] === '(') {
                $openers[] = $i;
            } elseif ($text[$i] === ')') {
                if ($openers === []) {
                    $marked[$i] = true;
                } else {
                    array_pop($openers);
                }
            }
        }
        foreach ($openers as $i) {
            $marked[$i] = true;
        }

        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            $escapable = $char === '\\'
                && $i + 1 < $length
                && in_array($text[$i + 1], ['(', ')', '\\'], true);
            $out .= isset($marked[$i]) || $escapable ? '\\' . $char : $char;
        }

        return $out;
    }

    protected function escapeQuoted(string $text): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $text);
    }

    /**
     * The `"title"` token of a fence opener, with the one character the slot
     * has no spelling for dropped and the loss reported.
     *
     * `quoted_title = '"', {character - '"'}, '"'` (PART 9 §12) states no
     * escape mechanism, so a `"` inside the title cannot be written. Spelling
     * it `\"` closed the title early and the rest of the opener re-parsed as a
     * paragraph, taking the container, its own title and its whole body with it
     * (carve-php#2375). Dropping the character keeps the opener valid, which is
     * the answer HtmlToCarve already gives for an imported admonition title.
     *
     * A title that is ONLY `"` writes the empty title `""`, which parses and
     * carries an empty title rather than destroying the opener.
     *
     * The token is written VERBATIM, unlike a link or image title. The slot
     * reads its content up to the closing quote with no escape mechanism, so
     * the value already carries the one encoding the slot has: a code fence's
     * title is raw text and a div's is inline source, from a parse and from an
     * AST ingest alike. Running it through `escapeQuoted()` encoded a value
     * that was already encoded, so a backslash gained a backslash on every
     * writer pass without bound, and the div's rendered title changed from a
     * non-breaking space to a literal backslash (carve-php#2397).
     */
    protected function quotedTitleToken(Node $node, string $title): string
    {
        if (str_contains($title, '"')) {
            $this->recordUnspellableField(
                $node,
                'title',
                'Carve source cannot spell a double quote inside a quoted title',
            );
            $title = self::withoutQuotes($title);
        }

        return '"' . $title . '"';
    }

    /**
     * Drop every `"` from a title, taking the backslash that escapes one with
     * it. A div's title arrives as inline source, where a literal quote is
     * spelled `\"`, so removing the quote alone would leave a backslash the
     * title never held. `\\"` keeps its escaped backslash and loses the quote.
     */
    protected static function withoutQuotes(string $title): string
    {
        $out = '';
        $length = strlen($title);
        for ($i = 0; $i < $length; $i++) {
            if ($title[$i] === '\\' && $i + 1 < $length) {
                if ($title[$i + 1] !== '"') {
                    $out .= $title[$i] . $title[$i + 1];
                }
                $i++;

                continue;
            }
            if ($title[$i] !== '"') {
                $out .= $title[$i];
            }
        }

        return $out;
    }

    /**
     * An abbreviation, written between the `*[` and `]:` of its definition.
     *
     * Deliberately NOT one of the raw bracketed runs below. The definition is
     * read as `*[([A-Za-z0-9]+)]: `, so neither a backslash nor a bracket can
     * reach this from a parse, and an ingested one carrying either has no
     * definition spelling with or without the escape. A shared shape, not a
     * shared rule.
     */
    protected function escapeBracketText(string $text): string
    {
        return str_replace(['\\', ']'], ['\\\\', '\\]'], $text);
    }

    /**
     * A run written between brackets whose reader scans it FLAT: a container
     * label, a code-fence label, a footnote id.
     *
     * Written as authored, with no escape, because there is nothing an escape
     * could buy. Each of these readers anchors on the whole of what follows and
     * stops at the first `]` without resolving anything, so a label holding a
     * `]` fails to match with a backslash exactly as it fails without one - the
     * construct is not a label either way. What the escape did do was survive
     * into the label a reader that DID match handed back, so a backslash grew
     * one more backslash on every format pass and two of the five sites changed
     * what the document says (a container label is rendered). See
     * markup-carve/carve#1197 and markup-carve/carve-js#1068.
     */
    protected function writeFlatBracketRun(string $text): string
    {
        return $text;
    }

    protected function escapeIdentifier(string $text): string
    {
        return (string)preg_replace('/[^\w-]/u', '', $text);
    }

    /**
     * A symbol name may contain `+` and `-` (so `:+1:` / `:-1:` round-trip),
     * unlike an extension identifier.
     */
    protected function escapeSymbolName(string $text): string
    {
        return (string)preg_replace('/[^\w+-]/u', '', $text);
    }

    protected function escapeName(string $text): string
    {
        return trim((string)preg_replace('/[^\w.-]/u', '', $text), '.');
    }

    protected function escapeFormat(string $text): string
    {
        $safe = (string)preg_replace('/[^\w-]/u', '', $text);

        return $safe === '' ? 'text' : $safe;
    }

    protected function escapeFenceToken(string $text): string
    {
        $token = preg_split('/\s/u', $text, 2)[0] ?? '';

        return str_replace('`', '', $token);
    }

    protected function escapeAttrKey(string $text): string
    {
        $safe = (string)preg_replace('/^[^a-zA-Z_]+|[^\w-]/u', '', $text);

        return $safe === '' ? 'x' : $safe;
    }

    protected function escapeAttrNameValue(string $text): string
    {
        return (string)preg_replace('/[^\w-]/u', '-', $text);
    }

    protected function isAttrIdentifier(string $text): bool
    {
        return preg_match('/^[A-Za-z_][\w-]*$/', $text) === 1;
    }

    protected function isExplicitIdOrClassIdentifier(string $text): bool
    {
        return preg_match('/^[A-Za-z0-9_][\w-]*$/', $text) === 1;
    }

    /**
     * Whether a name can be written as a BOOLEAN attribute - a bare word with
     * no value. Narrower than isAttrIdentifier by exactly one character: a
     * leading `_` is legal in an id, a class and a key, and refused here,
     * because `{_x_}` is a forced underline (carve#1450).
     */
    protected function isBooleanAttrName(string $text): bool
    {
        return preg_match('/^[A-Za-z][\w-]*$/', $text) === 1;
    }

    protected function quoteAttrValue(string $value): string
    {
        if (preg_match('/^[^\s"\'{}]+$/u', $value) === 1) {
            return $value;
        }

        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    protected function escapeCriticText(string $text): string
    {
        return str_replace(['\\', '{', '}'], ['\\\\', '\\{', '\\}'], $text);
    }

    protected function escapeAutolinkHref(string $text): string
    {
        return str_replace(['\\', '<', '>'], ['\\\\', '\\<', '\\>'], $text);
    }

    protected function escapeCrossrefTarget(string $text): string
    {
        return str_replace(['\\', '>'], ['\\\\', '\\>'], $text);
    }
}
