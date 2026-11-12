<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use Closure;
use MarkupCarve\Carve\Parser\Block\FencedBlockParser;
use MarkupCarve\Carve\Parser\Block\ListParser;
use MarkupCarve\Carve\Parser\Block\TableParser;
use MarkupCarve\Carve\Parser\Utility\IndentationHelper;
use WeakMap;

/**
 * Block ownership, rule A (carve#3885): one walk moves every opener past its
 * owner's content column, and every line its block owns, onto that column.
 * Port of `ownLines()` in the spec model (carve#3887).
 */
final class BlockOwnershipWalk
{
    /**
     * Frames whose body opens a list at a list marker under an open paragraph (PART 0 S3, carve#4860).
     *
     * @var array<string>
     */
    private const BODY_FRAMES = ['item', 'dd', 'note'];

    /**
     * @var string
     */
    private const THEMATIC_BREAK = '/^(-{3,}|\*{3,}|_{3,}|-(?: -){2,}|\*(?: \*){2,})[ \t]*$/D';

    /**
     * @var string
     */
    private const QUOTE = '/^>(?: ([^\r\n]*)|[ \t]*$)/D';

    /**
     * @var string
     */
    private const BARE_QUOTE_MARKERS = '/^(?:>[ \t]*)*$/D';

    /**
     * @var string
     */
    private const FRONTMATTER_OPENER = '/^---(?:[ \t]*$| ?[A-Za-z0-9]+[ \t]*$)/D';

    /**
     * @var array<int, \MarkupCarve\Carve\Parser\OwnershipFrame>
     */
    private array $frames = [];

    /**
     * @var array<int, string>
     */
    private array $lines = [];

    private int $reach = 0;

    /**
     * Whether the last lead() wrote its run out as spaces instead of as authored.
     */
    private bool $leadRewrote = false;

    /**
     * Lines the walk read as lazy paragraph text, keyed by line index.
     *
     * @var array<int, true>
     */
    public array $lazyLines = [];

    /**
     * Descriptions that took a lazy line written inside the quote holding them, with that quote's frame index.
     *
     * @var \WeakMap<\MarkupCarve\Carve\Parser\OwnershipFrame, int>
     */
    private WeakMap $quotedLazyDescriptions;

    /**
     * Lines before the block a taken `+` attaches, keyed by source line.
     * The note's own lazy continuation can follow the invisible run.
     *
     * @var array<int, true>
     */
    public array $attachedPreludeLines = [];

    /**
     * Losing duplicate note lines whose lone `+` took nothing (PART 9R R2, carve#5474).
     *
     * @var array<int, true>
     */
    public array $losingNoteLines = [];

    /**
     * Per skipped run (keyed by its `+` line): the first line not yet moved to the host's content column.
     *
     * @var array<int, int>
     */
    private array $skippedRunShiftedTo = [];

    /**
     * The content column of the host that kept each prelude line.
     *
     * @var array<int, int>
     */
    public array $attachedPreludeContentColumns = [];

    /**
     * The deepest reached item prefix of a comment payload line.
     *
     * @var array<int, int>
     */
    public array $commentPayloadHostColumns = [];

    /**
     * Abbreviation definitions skipped after an attached plus, keyed by source line.
     *
     * @var array<int, array{string, string}>
     */
    public array $skippedAbbreviations = [];

    /**
     * Lines of a `%%%` span skipped after a `+`, keyed by source line. Its body is opaque (PART 0 S1).
     *
     * @var array<int, true>
     */
    public array $skippedCommentLines = [];

    /**
     * Traced only: the owner a `%%` line's own column reaches, before the next visible line stands it deeper (carve#5178).
     *
     * @var array<int, string>
     */
    public array $commentColumnOwners = [];

    /**
     * Lazy lines folded into an open line block's stanza, keyed by line index.
     *
     * @var array<int, true>
     */
    public array $lineBlockLazyLines = [];

    /**
     * Verse lines whose tabbed lead the walk wrote as its per-frame spaces (carve#5885).
     *
     * @var array<int, true>
     */
    public array $respelledVerseLines = [];

    /**
     * Blanks that end a `+` attached code fence with no closer (PART 9 §17 L3), keyed by line index.
     *
     * @var array<int, true>
     */
    public array $attachedFenceBlankEnds = [];

    /**
     * Lines after a blank that missed the host of an own-line attached fence and ended it.
     *
     * @var array<int, true>
     */
    public array $attachedFenceMisses = [];

    /**
     * @var array<int, true>
     */
    public array $foldedFenceAfterAttachedBlank = [];

    /**
     * @var array{min: int, at: int}|null
     */
    private ?array $endedAttachedFence = null;

    /**
     * Reuse a closer search until the boundary where its answer can change.
     *
     * @var array<string, array{through: int, paired: bool}>
     */
    private array $laterFenceClosers = [];

    /**
     * Lines without `>` that end a `+` attached quote and resume its host (PART 0 S4, carve#5427), keyed by line index.
     *
     * @var array<int, true>
     */
    public array $attachedQuoteEnds = [];

    /**
     * Per fence shape, the line the last closer scan stopped at and its answer.
     *
     * @var array<string, array{int, bool}>
     */
    private array $attachedFenceScans = [];

    /**
     * Lines a refused brace run's paragraph keeps as text, the run included, each to its run's first line (CARVE-P2-035).
     *
     * @var array<int, int>
     */
    public array $refusedKeptLines = [];

    /**
     * @var array<int, true>
     */
    private array $lazyBraceReachesContainer = [];

    /**
     * Later lines of a block `{%` run whose continuations all pass its `{`, each to the run's first line (CARVE-P2-035).
     *
     * @var array<int, int>
     */
    public array $heldCommentLines = [];

    /**
     * Lazy brace openers whose continuations all reach the paragraph's frame (carve#5830 rule B).
     *
     * @var array<int, true>
     */
    public array $lazyRunsHeld = [];

    /**
     * Completed refused comment lookaheads for later openers before the same closer.
     *
     * @var array<int, array{owner: \MarkupCarve\Carve\Parser\OwnershipFrame, top: \MarkupCarve\Carve\Parser\OwnershipFrame, col: int, column: int, depth: int, through: int}>
     */
    private array $commentRunLookaheads = [];

    /**
     * Block openers spelled on an off-stop tab run: a nested parse reads them past the body's
     * column, where they still open their block rather than fold into a paragraph (PART 0 S2, S3).
     *
     * @var array<int, true>
     */
    public array $shiftedOpeners = [];

    /**
     * Description-shaped lines the nested reader must keep as text.
     *
     * @var array<int, true>
     */
    public array $pastColumnDescriptionLines = [];

    /**
     * Lines the walk opened a code fence on, keyed by line index.
     *
     * @var array<int, true>
     */
    public array $fenceOpenerLines = [];

    /**
     * Lines that ended an open `%%%` span by missing its owner (PART 0 S1), keyed by line index,
     * to the span's opener line and delimiter width.
     *
     * @var array<int, array{0: int, 1: int}>
     */
    public array $commentEndLines = [];

    /**
     * Lines that closed an open `%%%` span with its exact-width run, keyed by line index.
     *
     * @var array<int, true>
     */
    public array $commentCloserLines = [];

    /**
     * Payload and closer lines of a code or raw fence the walk opened, keyed by line index.
     *
     * @var array<int, true>
     */
    public array $fenceBodyLines = [];

    /**
     * Payload lines of a code or raw fence the walk opened, closers left out, keyed by line index.
     *
     * @var array<int, true>
     */
    public array $codePayloadLines = [];

    /**
     * Every lone `+` the walk refused, keyed by line index: a refused marker renders nothing (carve#5013),
     * an off-column `+` is a letter. The value is its lint finding, or null where the rule does not report it.
     *
     * @var array<int, array{line: int, column: int, host: string, reason: string}|null>
     */
    public array $refusedPlus = [];

    /**
     * Refused marker-line `+` of footnote definitions the walk passes over (carve#5953), for lint only.
     *
     * @var array<int, array{line: int, column: int, host: string, reason: string}|null>
     */
    public array $refusedNotePlus = [];

    /**
     * Refused `+` lines a note with a body keeps, each to the note's definition line (carve#5890).
     *
     * @var array<int, int>
     */
    public array $noteListBreaks = [];

    /**
     * The lines of `$noteListBreaks` whose next visible line is below the note's body column: the note ends there.
     *
     * @var array<int, true>
     */
    public array $noteEndsAtBreak = [];

    /**
     * The lines of `$refusedPlus` whose `+` is written as the letter, keyed by line index.
     *
     * @var array<int, true>
     */
    public array $letterPlus = [];

    /**
     * Later markers in a chain whose block an earlier marker already offered (carve#5013).
     *
     * @var array<int, true>
     */
    private array $claimedMarkers = [];

    /**
     * Every block opener S3 read past a non-item owner's content column (carve#3992).
     *
     * @var array<int, array{line: int, column: int, kind: string, owner: string, contentColumn: int, authoredColumn: int}>
     */
    public array $overindented = [];

    /**
     * The host of every lone `+` the one attach rule accepted, keyed by line index.
     *
     * @var array<int, array{kind: string, column: int, depth: int, content: int, first: bool, list: int|null, note: int|null}>
     */
    public array $plusHosts = [];

    /**
     * The owner of a description whose first-block `+` took a block other than a paragraph: a `: ` after that block is its sibling.
     */
    private ?OwnershipFrame $ddSiblingOwner = null;

    /**
     * The first frame the current line closed, while S5 applies it: an item of the same list keeps its list column.
     */
    private ?OwnershipFrame $sibling = null;

    private int $closedAtBlank = -1;

    /**
     * @var array<int, \MarkupCarve\Carve\Parser\OwnershipFrame> The frames after the last line the boundary step applied.
     */
    private array $lineFrames = [];

    /**
     * The invisible lines between a taken `+` and its block, read where that block stands (CARVE-P9-013).
     *
     * @var array{host: \MarkupCarve\Carve\Parser\OwnershipFrame, at: int, end: int, hostIndex: int, fenced: array<int, bool>, noteBodies: array<int, true>, column: int, lead: string, spanPad?: int, outside?: bool, runColumn?: int|null, offered?: bool, offeredAt?: int}|null
     */
    private ?array $skipped = null;

    /**
     * The frame a refused marker-line plus closes before its attribute follower.
     */
    private ?int $refusedAttributeHost = null;

    /**
     * The private byte a refused `+` is written as until the tree is built.
     */
    private string $letter = "\x01";

    /**
     * The frames at the last accepted `+`, its host's marker column and quote depth, and where its block starts.
     *
     * @var array{frames: array<int, \MarkupCarve\Carve\Parser\OwnershipFrame>, column: int, depth: int, from: int, item: bool, kind: string, parent?: \MarkupCarve\Carve\Parser\AttachmentParent|null, ownLine?: bool}|null
     */
    private ?array $attachedTo = null;

    /**
     * A marker-column attribute block closed its host on the previous line (A02).
     *
     * @var array{line: int, column: int|null, hostIndex: int}|null
     */
    private ?array $literalPlusAfterAttribute = null;

    /**
     * Trace owner restored after an attached colon block ends at a blank.
     *
     * @var array{owner: string, column: int}|null
     */
    private ?array $traceBlankHost = null;

    /**
     * Trace paths of the item a marker-line `+` attached the current line to, closed only by the walk.
     *
     * @var array{host: string, reached: string}|null
     */
    private ?array $traceAttachedHost = null;

    /**
     * The item released before S5 sees the next sibling marker.
     */
    private int $releasedSibling = 0;

    /**
     * @var array{widest: array<string, array<int, int>>, depths: array<int, int>, fenceRuns: array<int, string>, lastComment: array<int, int>, commentPositions: array<int, list<int>>, nextBlank: array<int, int>}|null
     */
    private ?array $runs = null;

    /**
     * @var array<string, array{int, int, int}>
     */
    private array $verseRunMisses = [];

    /**
     * Fence-shaped lines the walk read as paragraph text: result index to owner column.
     *
     * @var array<int, int>
     */
    private array $textFences = [];

    /**
     * Lines that missed an open container with no paragraph open to fold
     * into, so they closed it, keyed by line index.
     *
     * @var array<int, true>
     */
    public array $closingLines = [];

    /**
     * Marker lines that closed an item of another list kind or column before opening their own item.
     *
     * @var array<int, true>
     */
    private array $itemBoundaryLines = [];

    /**
     * Refused runs opened in the item such a marker line opens, by first line: that closing missed nothing of theirs.
     *
     * @var array<int, true>
     */
    private array $runsPastItemBoundary = [];

    /**
     * @var array<int, true> Unmarked lines released from a quoted item span to its outer quote.
     */
    public array $outerQuoteLines = [];

    /**
     * Whether the document opens with frontmatter (PART 1 admits no indent on either delimiter).
     */
    public bool $frontmatter = false;

    /**
     * False when an erased line (a `%%` comment, a definition) precedes the visible first line,
     * so no opener stands on authored line one (PART 1).
     */
    public bool $firstLineAuthored = true;

    /**
     * Whether the line just read was an abbreviation under a paragraph or a
     * caption with no block before it: the readers fold the next line there.
     */
    private bool $softLeaf = false;

    /**
     * A blank that does not carry its outer quote marker ends the stanza when
     * the next line leaves the verse span under S1.
     */
    private bool $verseSpanBlankMissedOwner = false;

    private bool $markedQuotedItemSpanBlank = false;

    private string $src = '';

    private string $out = '';

    /**
     * @var array{col: int, rest: string}
     */
    private array $m = ['col' => 0, 'rest' => ''];

    private int $at = 0;

    private int $wrappedAttributeEnd = -1;

    private int $wrappedAttributeColumn = 0;

    /**
     * A brace run a lone `+` attached, as moved: its last line, source column and written column.
     *
     * @var array{last: int, col: int, out: int}|null
     */
    private ?array $attachedBraceRun = null;

    private bool $onMarkerLine = false;

    /**
     * Note labels defined so far, by normalized key; a later definition of one loses (PART 9R R2).
     *
     * @var array<string, true>
     */
    private array $noteLabels = [];

    private bool $losingNoteLine = false;

    private int $nextVisible = -1;

    private int $refusedKeptStart = -1;

    private int $refusedKeptThrough = -1;

    private ?OwnershipFrame $refusedKeptOwner = null;

    private ?OwnershipFrame $refusedKeptLazyNoteHost = null;

    private int $refusedKeptColumn = 0;

    private int $movedSpanEnd = -1;

    /**
     * Decision trace records (docs/decision-trace.md in carve), or null when
     * tracing is off. Step ids follow PART 0 (CARVE-P0-024).
     *
     * @var array<int, array<string, int|string|null>>|null
     */
    public ?array $trace = null;

    /**
     * Keep only the S5 leaf records, the ones the erasure pass reads.
     */
    public bool $traceLeavesOnly = false;

    // Forms are immutable, so the two most common ones are shared.
    private static ?OwnershipForm $blankForm = null;

    private static ?OwnershipForm $textForm = null;

    public function __construct(
        private readonly ListParser $listParser,
        private readonly FencedBlockParser $fenced,
        private readonly TableParser $tables,
        private readonly Closure $isDefinition,
        private readonly Closure $isAttributeLine,
        private readonly Closure $wrappedAttributeLength,
        private readonly Closure $commentOnlyParagraphEnd,
        private readonly ?Closure $refusedRunKeptThrough = null,
        private readonly ?Closure $refusedBraceRunEnd = null,
        private readonly ?Closure $inlineSpanOpen = null,
    ) {
    }

    /**
     * @param array<int, string> $lines
     * @param string $letter
     *
     * @return array<int, string>
     */
    public function own(array $lines, string $letter = "\x01"): array
    {
        $this->letter = $letter;
        $this->lines = $lines;
        $this->closedAtBlank = -1;
        $this->lineFrames = [];
        $this->runs = null;
        $this->verseRunMisses = [];
        $this->textFences = [];
        $this->closingLines = [];
        $this->itemBoundaryLines = [];
        $this->runsPastItemBoundary = [];
        $this->outerQuoteLines = [];
        $this->frontmatter = false;
        $this->softLeaf = false;
        $this->verseSpanBlankMissedOwner = false;
        $this->markedQuotedItemSpanBlank = false;
        $this->reach = 0;
        $this->wrappedAttributeEnd = -1;
        $this->attachedBraceRun = null;
        $this->nextVisible = -1;
        $this->refusedKeptStart = -1;
        $this->refusedKeptThrough = -1;
        $this->refusedKeptOwner = null;
        $this->refusedKeptLazyNoteHost = null;
        $this->movedSpanEnd = -1;
        $this->lazyLines = [];
        $this->quotedLazyDescriptions = new WeakMap();
        $this->attachedPreludeLines = [];
        $this->losingNoteLines = [];
        $this->skippedRunShiftedTo = [];
        $this->attachedPreludeContentColumns = [];
        $this->commentPayloadHostColumns = [];
        $this->skippedCommentLines = [];
        $this->commentColumnOwners = [];
        $this->lineBlockLazyLines = [];
        $this->respelledVerseLines = [];
        $this->attachedFenceBlankEnds = [];
        $this->attachedFenceMisses = [];
        $this->foldedFenceAfterAttachedBlank = [];
        $this->endedAttachedFence = null;
        $this->laterFenceClosers = [];
        $this->attachedQuoteEnds = [];
        $this->traceBlankHost = null;
        $this->attachedFenceScans = [];
        $this->refusedKeptLines = [];
        $this->heldCommentLines = [];
        $this->lazyRunsHeld = [];
        $this->commentRunLookaheads = [];
        $this->shiftedOpeners = [];
        $this->pastColumnDescriptionLines = [];
        $this->fenceOpenerLines = [];
        $this->commentEndLines = [];
        $this->commentCloserLines = [];
        $this->fenceBodyLines = [];
        $this->codePayloadLines = [];
        $this->refusedPlus = [];
        $this->refusedNotePlus = [];
        $this->noteListBreaks = [];
        $this->noteEndsAtBreak = [];
        $this->letterPlus = [];
        $this->claimedMarkers = [];
        $this->overindented = [];
        $this->plusHosts = [];
        $this->noteLabels = [];
        $this->ddSiblingOwner = null;
        $this->skipped = null;
        $this->refusedAttributeHost = null;
        $this->attachedTo = null;
        $this->literalPlusAfterAttribute = null;
        $this->releasedSibling = 0;
        $this->frames = [new OwnershipFrame('doc', 0, 0, 0)];
        $result = [];
        $afterPlus = false;
        $count = count($lines);
        $start = $this->ownFrontmatter($lines, $result);
        for ($at = $start; $at < $count; $at++) {
            if ($this->frames[count($this->frames) - 1]->losing) {
                $this->truncate(count($this->frames) - 1);
            }
            $this->releasedSibling = 0;
            $refusedAttributeReach = $this->resumeRefusedAttribute();
            if ($this->carrySkippedPrelude($at, $result)) {
                continue;
            }
            $afterSoftLeaf = $this->softLeaf;
            $this->softLeaf = false;
            if ($this->ownsPlainDocumentLine($at, $afterPlus)) {
                $result[] = $lines[$at];

                continue;
            }
            $this->at = $at;
            $this->src = $lines[$at];
            $this->out = '';
            $this->m = self::indentCols($this->src);
            $this->endOwnLineFenceAtMiss($at, $lines);
            $this->restoreEnclosingAttachedHost($at);
            $this->recordCommentPayloadHostColumn($at);
            $this->closeAttributeHostBeforeLiteralPlus($at);
            // A `+` right after the block a `+` attached reaches the same host, whose
            // frames the walk closed when it read that block where it stands.
            if ($this->attachedTo !== null && str_contains($this->src, '+')) {
                $stands = $this->levelOf($this->src, $this->attachedTo['depth']);
                if ($stands !== null && self::isPlus($stands['rest']) && $stands['col'] === $this->attachedTo['column']) {
                    if ($this->attachedBlockEndsAt($at)) {
                        $this->frames = $this->attachedTo['frames'];
                    }
                    $this->attachedTo = null;
                }
            }
            $this->endQuoteGrantAtBlank($at);
            $this->resumeHostAfterAttachedQuote($at);
            $reached = 0;
            $plus = null;
            $plusAccepted = false;
            $inWrappedAttribute = $at <= $this->wrappedAttributeEnd;
            $frameCount = count($this->frames);
            // The C5 column of the current quote segment, measured on demand.
            $stripped = null;
            $segment = 1;
            $quotesRead = 0;
            // S2: the deepest open frame the line reaches.
            $frameLimit = $refusedAttributeReach === null ? $frameCount : $refusedAttributeReach + 1;
            for ($k = 1; $k < $frameLimit; $k++) {
                $f = $this->frames[$k];
                // A lone `+` reaches the marker column of an open marker container.
                if (
                    $f->markerCol !== null && $this->m['col'] === $f->markerCol && ($f->kind === 'quote' || $this->m['col'] < $f->col)
                    && self::isPlus($this->m['rest'])
                    && !$this->openCodeFenceKeepsPlus($frameCount)
                    && !($f->kind === 'quote' && $this->hasAttachedQuoteAncestor($k) && $quotesRead > 0 && $f->para && $quotesRead < $this->quoteDepth($k + 1))
                ) {
                    $plus = $k;
                    $plusAccepted = !isset($this->claimedMarkers[$at]) && $this->attachesAt($f->kind, $at, self::docColumn($lines[$at], $this->m['rest']), $k);

                    break;
                }
                if ($f->kind === 'quote') {
                    // An own-line `+` at a descendant quote's marker column reaches that quote without the quote markers between.
                    if (self::isPlus($this->m['rest']) && $this->descendantQuoteTakesPlus($k, self::docColumn($lines[$at], $this->m['rest']))) {
                        $child = $this->frames[$k + 1];
                        $this->out .= str_repeat(' ', $f->markerCanon) . '> ';
                        $this->src = str_repeat(' ', max(0, self::docColumn($lines[$at], $this->m['rest']) - (($child->docMarker ?? 0) - ($child->markerCol ?? 0))))
                            . $this->m['rest'];
                        $this->m = self::indentCols($this->src);
                        $reached = $k;

                        continue;
                    }
                    if (preg_match(self::QUOTE, $this->m['rest'], $q) !== 1) {
                        break;
                    }
                    $this->lead($this->frames[$k - 1]->canon);
                    $this->take(strlen($this->src) - strlen($q[1] ?? ''));
                    $this->m = self::indentCols($this->src);
                    $stripped = null;
                    $segment = $k + 1;
                    $reached = $k;
                    $quotesRead++;

                    continue;
                }
                if ($this->missesFrameAtColumn($k, $inWrappedAttribute, $segment, $stripped)) {
                    break;
                }
                $k = $this->runEnd($k, $frameCount);
                $reached = $k;
            }
            if ($inWrappedAttribute) {
                // Past the opener's column, as written, keeps the continuation inside its run (CARVE-P2-035).
                $pad = max(1, $this->m['col'] - $this->wrappedAttributeColumn);
                $result[] = $this->out . str_repeat(' ', $this->frames[$reached]->canon + $pad) . $this->m['rest'];

                continue;
            }
            $this->recordCommentColumnOwner($at, $reached);
            $reached = $refusedAttributeReach ?? $this->standInvisibleLine($reached, $plus !== null);
            // A lone `+` nothing took is a plain letter and ends nothing (carve#4396). Payload stays as written.
            if (
                $plus === null && self::isPlus($this->m['rest'])
                && !($this->frames[$frameCount - 1]->kind === 'fence' && $reached === $frameCount - 1)
            ) {
                $this->refusePlus($at, $this->separatePlusFinding($at, $reached));
            }
            if ($plus !== null && !$plusAccepted) {
                // A refused marker is consumed and renders nothing (carve#5013). Its placeholder stays
                // inside the host, so the next line keeps the same owner.
                $keepMarkerColumn = count($this->frames) > $plus + 1 || isset($this->claimedMarkers[$at]);
                [$closer, $afterNestedBlank] = $this->refusedItemContext($plus, $at);
                $this->truncate($plus + 1);
                $host = $this->frames[$plus];
                $host->para = false;
                $column = self::docColumn($lines[$at], $this->m['rest']);
                $this->refusedPlus[$at] = isset($this->claimedMarkers[$at])
                    ? $this->finding($at, $host->kind, 'claimed')
                    : $this->plusFinding($at, $host->kind, $column, $plus);
                if ($host->kind === 'note' && $host->line !== null && $this->bodyBetween($host->line, $at)) {
                    $this->noteListBreaks[$at] = $host->line;
                    if (!$this->followerAtColumn($at, $host->col)) {
                        $this->noteEndsAtBreak[$at] = true;
                    }
                }
                $this->skipInvisible($plus, $at, $column, true);
                if (!$keepMarkerColumn) {
                    $this->standRefusedSpan($plus);
                }
                $this->lead((int)$host->markerCanon);
                $result[] = $this->out . $this->refusedPlaceholder($host, $at, $keepMarkerColumn, $closer, $afterNestedBlank);
                if ($this->trace !== null) {
                    $this->emit($at, 'S2', $this->ownerPath(), 'CARVE-P9-067', 'plus', ['cell' => 'B-plus-start']);
                }

                continue;
            }
            if ($plus !== null) {
                $this->truncate($plus + 1);
                $this->frames[$plus]->para = false;
                $this->skipInvisible($plus, $at, self::docColumn($lines[$at], $this->m['rest']));
                $this->holdAttachedTo($plus, $this->skipped['end'] ?? $at + 1, true);
                $this->lead($this->frames[$plus]->markerCanon);
                $this->plusHosts[$at] = $this->hostRecord($plus, self::columnOf($this->out), false);
                $result[] = $this->out . $this->src;
                $afterPlus = true;
                // S2: a lone `+` taken at a marker column (B-plus-start).
                if ($this->trace !== null) {
                    $this->emit($at, 'S2', $this->ownerPath(), 'CARVE-P9-067', 'plus', ['cell' => 'B-plus-start']);
                }

                continue;
            }
            // The line right after a marker-line `+` follows it as a standalone one's does.
            $plusFollower = $afterPlus || $this->frames[count($this->frames) - 1]->plus === $at - 1;
            $afterPlus = false;
            $reached = $this->attachedItemFollowerReach($reached, $plusFollower);
            $missed = $reached < count($this->frames) - 1;
            $innerIndex = count($this->frames) - 1;
            $inner = $this->frames[$innerIndex];
            $missedQuote = $this->quoteBelow($reached);
            // A comment line below the column misses no host of an open comment span (CARVE-P0-005).
            if (
                $missed && $inner->kind === 'fence' && $inner->family === 'comment' && $inner->span === null
                && str_starts_with($this->m['rest'], '%%')
                // A `%%%` run that misses a quote ends the quote's span (S1) and opens its own.
                && !(str_starts_with($this->m['rest'], '%%%') && $this->quoteBelow($reached))
                && !($inner->closes !== null && ($inner->closes)($this->m['rest'], $this->m['col']))
            ) {
                $missed = false;
                $reached = $innerIndex;
            }
            // So does one in a line block's open `%%%` span, where an exact-width run closes it (PART 9 §28, carve#4994).
            if (
                $missed && $inner->kind === 'fence' && $inner->lineBlock && $inner->span !== null && $inner->span[0] === '%'
                && str_starts_with($this->m['rest'], '%%') && !$this->quoteBelow($reached)
            ) {
                if (preg_match('/^(%{3,})/', $this->m['rest'], $c) === 1 && strlen($c[1]) === strlen($inner->span)) {
                    $this->frames[$innerIndex]->span = null;
                }
                // Written at the block's column, so the collectors read it inside the span.
                $result[] = $this->out . str_repeat(' ', $inner->canon) . $this->m['rest'];
                if ($this->trace !== null) {
                    $this->emit($at, 'S1', $this->ownerPath(), 'CARVE-P0-005', 'payload', ['cell' => 'B-' . $inner->family . '-keep']);
                }

                continue;
            }
            $this->reach = $reached;
            // A comment closer below its host's column still closes the span (PART 9 §28).
            if (
                $inner->kind === 'fence' && $inner->family === 'comment' && $missed && $inner->span === null
                && $this->m['rest'] !== '' && $inner->closes !== null && ($inner->closes)($this->m['rest'], $this->m['col'])
            ) {
                array_pop($this->frames);
                $this->frames[count($this->frames) - 1]->last = 'block';
                $this->commentCloserLines[$at] = true;
                $this->lead($this->frames[$reached]->canon);
                $result[] = $this->out . $this->src;
                if ($this->trace !== null) {
                    $this->emit($at, 'S1', $this->ownerPath(), 'CARVE-P0-005', 'closer', ['cell' => 'B-comment-end']);
                }

                continue;
            }
            if (!$missed && $this->blankEndsAttachedFence($at, $inner)) {
                $this->recordAttachedBlankHost($inner);
                $missed = !$this->resumeAttachedHost($inner);
                $innerIndex = count($this->frames) - 1;
                $inner = $this->frames[$innerIndex];
                $reached = $innerIndex - (int)$missed;
            }
            if ($inner->kind === 'fence' && !$missed) {
                $this->verseSpanBlankMissedOwner = $inner->lineBlock && $inner->span !== null && $inner->span[0] === '%'
                    && $this->m['rest'] === '' && $this->spanBlankEndsStanza($lines[$at]);
                $this->markedQuotedItemSpanBlank = $this->verseSpanBlankMissedOwner
                    && $this->frames[1]->kind === 'quote' && str_contains($lines[$at], '>');
                // S1: an open payload tests its closer first (B-<family>-keep, B-<family>-end).
                $payloadPath = $this->trace !== null ? $this->traceFenceOwner($inner) : '';
                $payloadForm = 'payload';
                $shiftedCloser = $this->readsShiftedCloser($inner);
                $this->leadPayload($inner, $shiftedCloser, $segment);
                $rest = $this->m['rest'];
                $inCommentSpan = $inner->span !== null && $inner->span[0] === '%';
                if ($rest !== '' && $inner->span !== null) {
                    $span = $inner->span;
                    $comment = $span[0] === '%';
                    if (
                        preg_match($comment ? '/^(%{3,})/' : '/^(`{3,}|~{3,})[ \t]*$/D', $rest, $c) === 1 && $c[1][0] === $span[0]
                        && ($comment ? strlen($c[1]) === strlen($span) : strlen($c[1]) >= strlen($span))
                    ) {
                        $this->frames[$innerIndex]->span = null;
                    }
                } elseif ($shiftedCloser || ($rest !== '' && $inner->closes !== null && ($inner->closes)($rest, $this->m['col']))) {
                    array_pop($this->frames);
                    $this->resumeLineBlockHost($inner);
                    // A matched comment span is invisible: its host keeps the form it had (§10 I5).
                    if ($inner->family !== 'comment') {
                        $this->frames[count($this->frames) - 1]->last = 'block';
                    } else {
                        $this->commentCloserLines[$at] = true;
                    }
                    $payloadForm = 'closer';
                } elseif ($inner->lineBlock && !($inner->span !== null && $inner->span[0] === '%')) {
                    // A span in a line-block payload pairs past the column too (carve-php#3137).
                    if (
                        preg_match('/^(`{3,}|~{3,})(.*)$/D', $rest, $f) === 1
                        && ($this->fenced->parseCodeFenceOpener($rest) !== null || $this->fenced->parseRawBlockOpener($rest) !== null)
                        && $this->verseRunPairs($at, $f[1])
                    ) {
                        $this->frames[$innerIndex]->span = $f[1];
                    } elseif ($this->m['col'] - $inner->col <= 3 && preg_match('/^(%{3,})/', $rest, $c) === 1) {
                        // A deeper percent run is verse text, not a span opener.
                        // A comment span leaves nothing: the stanza stays as it was before it (carve#4956).
                        $this->frames[$innerIndex]->span = $c[1];
                    }
                }
                // Verse text, a fence-shaped run included, keeps the stanza open for a lazy line; a blank ends it (PART 0 S4).
                // A `%%%` span leaves nothing, so the stanza stays as it was before it (carve#4956).
                if ($inner->lineBlock && !$inCommentSpan && !($inner->span !== null && $inner->span[0] === '%')) {
                    $inner->para = $rest !== '';
                }
                $this->recordFenceBodyLine($at, $inner, $payloadForm === 'closer');
                $result[] = $this->out . $this->src;
                if ($this->trace !== null) {
                    $this->emit($at, 'S1', $payloadPath, 'CARVE-P0-005', $payloadForm, [
                        'cell' => 'B-' . $inner->family . '-' . ($payloadForm === 'closer' ? 'end' : 'keep'),
                    ]);
                }

                continue;
            }
            // A line leaving a line block's open `%%%` span keeps the opener base (carve#4994).
            $leftLineBlockSpan = $inner->kind === 'fence' && $inner->lineBlock && $inner->span !== null && $inner->span[0] === '%';
            // An open payload span cannot carry the stanza past a quote the line missed (carve#5062).
            $spanLeavesQuote = $inner->span !== null && $inner->span[0] !== '%' && $this->quoteBelow($reached);
            if ($inner->kind === 'fence' && !($inner->lineBlock && $inner->para && $this->m['rest'] !== '' && !$spanLeavesQuote)) {
                array_pop($this->frames);
                $opener = $inner->opened ?? $inner->line;
                if ($inner->family === 'comment' && $this->m['rest'] !== '' && $opener !== null && preg_match('/%{3,}/', $lines[$opener] ?? '', $run) === 1) {
                    $this->commentEndLines[$at] = [$opener, strlen($run[0])];
                }
            }
            // S3: the line's form, the record's `form` field.
            $form = $this->classifyLine($this->m['rest'], $this->attachedQuoteHost($reached) ?? $this->frames[$reached], $this->m['col']);
            $form = $this->foldFenceAfterAttachedBlank($at, $reached, $form);
            $topIndex = count($this->frames) - 1;
            if ($this->m['rest'] !== '' && ($form->kind === 'dd' || ($reached === $topIndex && $this->frames[$reached] === $this->ddSiblingOwner))) {
                $this->ddSiblingOwner = null;
            }
            // The one block a first-block `: +` takes ends the description, which stays open for a sibling (PART 9 §17 L3, carve#4408).
            $firstBlockDd = $this->frames[$topIndex];
            if (
                $plusFollower && $reached < $topIndex && $firstBlockDd->kind === 'dd' && $firstBlockDd->plus === $at - 1
                && $this->m['rest'] !== '' && $this->m['col'] < $firstBlockDd->col
                && !in_array($form->kind, ['text', 'dd', 'term', 'colon-close'], true)
            ) {
                $this->ddSiblingOwner = $this->frames[$reached];
            }
            $next = $this->frames[$reached + 1] ?? null;
            $missedNote = false;
            for ($k = $reached + 1; $k <= $topIndex; $k++) {
                $missedNote = $missedNote || $this->frames[$k]->kind === 'note';
            }
            // A caption at its owner's block column attaches to a quote it follows and is never its lazy line (carve#4312).
            $captionsQuote = $form->caption && $this->m['col'] === $this->frames[$reached]->col && $next?->kind === 'quote' && !$missedNote;
            if ($captionsQuote) {
                $this->softLeaf = true;
            }
            $endsMissedItem = $missed && $next !== null && $this->descriptionEndsMissedItem($at, $reached, $missedQuote);
            $lazy = $missed && $next !== null && $this->m['rest'] !== '' && $this->frames[$topIndex]->para && !$form->interrupts && !$captionsQuote && $form->kind !== 'dd'
                && !$endsMissedItem
                && !($missedNote && ($this->refusedNotePlusMissesBody($at, $topIndex) || $this->refusedNotePlusPrecedesBraceRun($at, $topIndex)))
                // A marker that a marker body owns, or that misses one, opens a list (PART 0 S3, carve#4860).
                && !($form->kind === 'item' && $this->marksBodyList($reached) && !$this->followsRefusedNoteBrace($at, $topIndex)
                    && !$this->missesQuotedLazyDescription($reached, $topIndex))
                && !($this->verseSpanBlankMissedOwner && $this->frames[$topIndex]->lineBlock
                    && $this->frames[$topIndex]->span !== null && $this->frames[$topIndex]->span[0] === '%');
            // S4: a line that missed a frame folds into the deepest open paragraph.
            if ($lazy) {
                // The fold is the S1 end of a verse's comment span (carve#4956).
                if ($this->frames[$topIndex]->lineBlock && $this->frames[$topIndex]->span !== null && $this->frames[$topIndex]->span[0] === '%') {
                    $this->frames[$topIndex]->span = null;
                }
                // A folded marker opens no item (§10 I2), so a `+` closing it is text (PART 0 S4, carve#5112).
                // Past a missed note the line is framed lazy in the deepest container (carve#3926).
                if ($missedNote && ($this->frames[$topIndex]->plus !== $at - 1 || $this->followsRefusedNoteBrace($at, $topIndex))) {
                    $this->lead(0);
                    for ($k = $reached + 1; $k <= $topIndex; $k++) {
                        if ($this->frames[$k]->kind === 'quote') {
                            $this->out .= str_repeat(' ', $this->frames[$k - 1]->reader ?? $this->frames[$k - 1]->canon) . '> ';
                        }
                    }
                    $leaf = $this->frames[$topIndex];
                    // A missed line is ordinary lazy paragraph text. It cannot
                    // complete a block-position brace run in this container.
                    $result[] = $this->out . str_repeat(' ', $leaf->kind === 'quote' ? 0 : (int)$leaf->reader) . BlockGrammar::LAZY_FRAME . $this->src;
                    $this->lazyLines[$at] = true;
                    $this->markLazyBraceOpener($at, $reached);
                    if ($leaf->kind === 'fence' && $leaf->lineBlock) {
                        $this->lineBlockLazyLines[$at] = true;
                    }
                    $this->emitLazy($at, $reached);

                    continue;
                }
                $owner = $this->frames[$reached];
                $limit = $next->kind === 'quote' ? PHP_INT_MAX : $next->canon - 1;
                $this->lead(min(max($this->lazyColumn($at, $owner), $owner->canon), $limit));
                if (preg_match('/^(`{3,}|~{3,})/', $this->m['rest']) === 1) {
                    $this->textFences[count($result)] = $owner->canon;
                }
                $result[] = $this->out . $this->src;
                $this->lazyLines[$at] = true;
                $this->markQuotedLazyDescription($reached, $topIndex);
                $this->markLazyBraceOpener($at, $reached);
                if ($this->frames[$topIndex]->kind === 'fence' && $this->frames[$topIndex]->lineBlock) {
                    $this->lineBlockLazyLines[$at] = true;
                }
                $this->emitLazy($at, $reached);

                continue;
            }
            $this->recordOuterQuoteLine($at, $missed, $reached);
            // A lone `+` at a marker column attaches a paragraph written there and the host keeps it (§17 L3):
            // S4 tests the lines after it against that paragraph, so a sibling marker interrupts it.
            $lonePlus = $plusFollower ? ($this->frames[$reached + 1] ?? null) : null;
            if (
                $lonePlus !== null && $reached === count($this->frames) - 2 && $form->kind === 'text' && $this->m['rest'] !== ''
                && ($lonePlus->kind === 'item' || $lonePlus->kind === 'quote' || (($lonePlus->kind === 'dd' || $lonePlus->kind === 'note') && $lonePlus->plus === $at - 1))
                && !self::isPlus($this->m['rest'])
            ) {
                $this->lead($lonePlus->markerCanon);
                $result[] = $this->out . $this->src;
                $this->holdAttachedBraceRun($at, (int)$lonePlus->markerCanon);
                $lonePlus->para = true;
                $lonePlus->paragraphStart = $at;
                $lonePlus->paragraphSpanNext = $at;
                $lonePlus->paragraphSpanState = ['scanned' => 0, 'code' => 0, 'comment' => false];
                $lonePlus->last = 'para';
                if ($this->trace !== null) {
                    $this->emit($at, 'S5', $this->ownerPath(), 'CARVE-P0-024', 'text', ['plus' => $at]);
                }

                continue;
            }
            // An attribute run after it leads the `+`'s one block, so the item stays open for the line after the run.
            if (
                $lonePlus !== null && $lonePlus->kind === 'item' && $reached === count($this->frames) - 2 && $this->m['col'] === $lonePlus->markerCol
                && $this->frames[$reached]->kind !== 'dd' && $this->m['rest'] !== '' && $this->m['rest'][0] === '{' && ($this->isAttributeLine)($this->m['rest'])
            ) {
                $runNext = $this->levelOf($lines[$at + 1] ?? '', $this->quoteDepth($reached + 1));
                if ($runNext !== null && $runNext['rest'] !== '' && $runNext['col'] >= $lonePlus->col) {
                    $this->lead($lonePlus->markerCanon);
                    $result[] = $this->out . $this->src;
                    $afterPlus = true;
                    if ($this->trace !== null) {
                        $this->emit($at, 'S5', $this->ownerPath(), 'CARVE-P0-024', 'leaf', ['plus' => $at]);
                    }

                    continue;
                }
            }
            if (
                $missed && !$plusFollower && $this->m['rest'] !== '' && !$afterSoftLeaf
                && ($inner->kind !== 'fence' || $inner->family === 'code'
                    || ($inner->family === 'comment' && $reached < $innerIndex - 1
                        && (in_array($this->frames[$innerIndex - 1]->kind, ['quote', 'dd', 'note'], true)
                            || ($this->frames[$innerIndex - 1]->kind === 'item' && $reached === $innerIndex - 2))
                        && $this->commentCloserBeforeBlank($at, $inner, $this->frames[$innerIndex - 1]->kind === 'quote')))
                && (!$this->frames[$topIndex]->para || ($form->kind === 'leaf' && $form->interrupts
                    && (($this->isDefinition)($this->m['rest']) || $this->abbreviationMissesFrames($reached, $form))))
            ) {
                $this->closingLines[$at] = true;
            }
            $this->recordClosedDocumentLineBlock($at, $missed, $reached, $inner);
            // A document-owned abbreviation definition ends a term too (carve#4412).
            if (!$missed && $this->frames[$reached]->last === 'term' && $this->abbreviationMissesFrames($reached, $form)) {
                $this->closingLines[$at] = true;
            }
            // S5: apply the line, the boundary table T of CARVE-P0-003.
            $closed = count($this->frames) - 1 - $reached + $this->releasedSibling;
            $this->recordItemBoundary($at, $reached, $form);
            $reachedPath = $this->traceReach($at, $reached, $closed, $plusFollower);
            $closerPath = null;
            $closedColumn = false;
            $frameCount = count($this->frames);
            for ($k = $reached + 1; $k < $frameCount; $k++) {
                if ($this->frames[$k]->kind !== 'quote') {
                    $closedColumn = true;
                }
            }
            $this->sibling = $this->frames[$reached + 1] ?? null;
            $this->truncate($reached + 1);
            $o = $reached;
            if ($this->m['rest'] === '') {
                $this->frames[$o]->para = false;
                $this->closedAtBlank = max($this->closedAtBlank, $this->blankClosedColumn());
                $this->lead($this->m['col']);
            } elseif ($form->kind === 'colon-close') {
                // A closer is checked at its authored base before the handoff.
                $this->lead($this->frames[$form->frame]->canon, contentTab: true);
                $closerPath = $this->trace !== null ? $this->ownerPath($form->frame) : null;
                $closedColon = $this->frames[$form->frame];
                $this->truncate($form->frame);
                if ($closedColon->blankHostFrames !== null) {
                    $this->resumeAttachedHost($closedColon);
                }
                $t = count($this->frames) - 1;
                $this->frames[$t]->para = false;
                $this->frames[$t]->last = 'block';
            } elseif ($form->kind === 'text') {
                if (!$plusFollower) {
                    $this->noteOpener($o, $form, $this->frames[$o]->col);
                }
                $owner = $this->frames[$o];
                $this->lead($owner->para || !$this->opensBelowClosedColumn($closedColumn) || $plusFollower
                    ? max($this->m['col'] - $owner->cut, $owner->canon)
                    : $owner->canon, contentTab: true);
                if ($owner->para && !$form->kept && preg_match('/^(`{3,}|~{3,})/', $this->m['rest']) === 1) {
                    $this->textFences[count($result)] = $owner->canon;
                }
                $opensParagraph = !$owner->para;
                $this->openParagraph($this->frames[$o], $at, $opensParagraph);
                $this->frames[$o]->last = 'para';
                $this->clearItemDefinitionAtParagraph($o, $opensParagraph);
            } else {
                if (!$plusFollower) {
                    $this->noteOpener($o, $form, $this->frames[$o]->col);
                }
                $shift = $this->strippedColumn($this->src, $segment, $this->m) - $this->m['col'];
                $this->noteShiftedOpener($at, $shift);
                $definitionMarker = $form->kind === 'term' || $form->kind === 'dd';
                $this->recordPastColumnDescriptionLine($at, $segment, $shift, $o, $form);
                $openerTabbed = str_contains(substr($this->src, 0, strlen($this->src) - strlen($this->m['rest'])), "\t");
                $this->lead(
                    $this->frames[$o]->canon,
                    $definitionMarker,
                    contentTab: $this->keepsOpenerTabRun($form, $shift),
                    definitionMarker: $definitionMarker && $this->frames[$o]->kind === 'dd',
                );
                $this->apply($o, $this->m['col'], $form, $this->frames[$o]->canon, inHost: !$leftLineBlockSpan);
                $this->recordLineBlockOpener($form, $o, $shift, $openerTabbed);
                $this->markAttachedFence($at, $o, $form, $plusFollower);
                $this->recordRewrittenOpener($form, $shift);
                // A marker opened on an off-stop tab run has its C5 columns that far right.
                for ($k = $o + 1, $n = count($this->frames); $k < $n && $this->frames[$k]->kind !== 'quote'; $k++) {
                    if (in_array($this->frames[$k]->kind, self::BODY_FRAMES, true)) {
                        $this->frames[$k]->c5Shift = $shift;
                    }
                }
            }
            $this->sibling = null;
            $this->endAppliedLine();
            $result[] = $this->out . $this->src;
            if ($this->trace !== null) {
                $this->emitApplied($at, $form, $closerPath ?? $this->ownerPath(), $closed, $reachedPath, $plusFollower);
            }
        }

        $this->refusedKeptLines = array_filter(
            $this->refusedKeptLines,
            fn (int $runStart): bool => (!isset($this->closingLines[$runStart])
                && !isset($this->lazyBraceReachesContainer[$runStart]))
                || isset($this->runsPastItemBoundary[$runStart]),
        );

        return $this->frameTextFences($result);
    }

    /**
     * A first-line opener claims through its first closer or EOF (carve#5016).
     *
     * @param array<int, string> $lines
     * @param array<int, string> $result
     *
     * @return int The first line the walk owns.
     */
    private function ownFrontmatter(array $lines, array &$result): int
    {
        $count = count($lines);
        if ($count === 0 || !$this->firstLineAuthored || preg_match(self::FRONTMATTER_OPENER, $lines[0]) !== 1) {
            return 0;
        }
        $start = $count;
        for ($k = 1; $k < $count; $k++) {
            if (preg_match('/^---[ \t]*$/D', $lines[$k]) === 1) {
                $start = $k + 1;

                break;
            }
        }
        for ($j = 0; $j < $start; $j++) {
            $result[] = $lines[$j];
            // INPUT: frontmatter, before ownership.
            if ($this->trace !== null) {
                $this->emit($j, 'INPUT', 'doc', null, 'frontmatter');
            }
        }
        $this->frontmatter = true;

        return $start;
    }

    /**
     * A blank or plain text line with only the document open: S2 reaches the
     * document, S3 reads it as text, and S5 keeps it where it stands.
     */
    private function ownsPlainDocumentLine(int $at, bool $afterPlus): bool
    {
        $line = $this->lines[$at];
        if (
            count($this->frames) !== 1 || $afterPlus || $this->attachedTo !== null || $this->trace !== null
            || $this->ddSiblingOwner !== null || $this->markedQuotedItemSpanBlank
            || $at <= $this->wrappedAttributeEnd || $at <= $this->movedSpanEnd || $at <= $this->refusedKeptThrough
        ) {
            return false;
        }
        $doc = $this->frames[0];
        if (trim($line, " \t") === '') {
            $doc->para = false;

            return true;
        }
        $first = $line[0];
        if (
            $first === ' ' || $first === "\t" || $first === '+' || $first === '{' || $first === '`' || $first === '~'
            || $doc->plus === $at - 1 || $doc->last === 'term'
        ) {
            return false;
        }
        $this->at = $at;
        $this->reach = 0;
        $form = $this->classify($line, $doc, 0);
        if ($form === self::$textForm) {
            if (!$doc->para) {
                $doc->paragraphStart = $at;
                $doc->paragraphSpanNext = $at;
                $doc->paragraphSpanState = ['scanned' => 0, 'code' => 0, 'comment' => false];
            }
            $doc->para = true;
            $doc->last = 'para';

            return true;
        }
        // A heading is a leaf that opens no frame; S5 records it as the document's last form.
        if ($first === '#' && $form->kind === 'leaf' && !$form->table && !($this->isDefinition)($line)) {
            $doc->para = false;
            $doc->last = 'leaf';
            $doc->tableLine = null;

            return true;
        }

        return false;
    }

    private function recordItemBoundary(int $at, int $reached, OwnershipForm $form): void
    {
        if ($form->kind !== 'item') {
            return;
        }
        for ($k = $reached + 1, $n = count($this->frames); $k < $n; $k++) {
            $frame = $this->frames[$k];
            if ($frame->kind === 'item' && ($frame->axes !== $form->axes || $frame->markerCol !== $this->m['col'])) {
                $this->closingLines[$at] = true;
                $this->itemBoundaryLines[$at] = true;

                return;
            }
        }
    }

    /**
     * A line leaving a closed verse stanza can close every enclosing host even
     * though the innermost frame is a div. Downstream collectors must not take
     * that document-owned line as lazy text (carve#5265, carve#5268).
     */
    private function recordClosedDocumentLineBlock(int $at, bool $missed, int $reached, OwnershipFrame $inner): void
    {
        if (!$missed || $reached !== 0 || $inner->kind !== 'fence' || !$inner->lineBlock || $inner->para) {
            return;
        }

        $hasContainer = false;
        foreach ($this->frames as $frame) {
            if ($frame->kind === 'dd') {
                return;
            }
            $hasContainer = $hasContainer || $frame->kind === 'quote' || $frame->kind === 'item';
        }
        if ($hasContainer) {
            $this->closingLines[$at] = true;
        }
    }

    private function spanBlankEndsStanza(string $line): bool
    {
        $item = false;
        foreach ($this->frames as $frame) {
            if ($frame->kind === 'dd') {
                return false;
            }
            $item = $item || $frame->kind === 'item';
        }

        return $item || ($this->frames[1]->kind !== 'quote' || trim($line, " \t") === '');
    }

    private function recordOuterQuoteLine(int $at, bool $missed, int $reached): void
    {
        if ($missed && $reached === 0 && $this->markedQuotedItemSpanBlank && $this->m['rest'] !== '') {
            $this->outerQuoteLines[$at] = true;
        }
        $this->markedQuotedItemSpanBlank = false;
    }

    private function recordCommentPayloadHostColumn(int $at): void
    {
        foreach ($this->frames as $frame) {
            if ($frame->kind !== 'fence' || $frame->span === null || $frame->span[0] !== '%') {
                continue;
            }
            $hostColumn = 0;
            foreach ($this->frames as $owner) {
                if (in_array($owner->kind, ['item', 'dd', 'note'], true) && $owner->col <= $frame->col && $owner->col <= $this->m['col']) {
                    $hostColumn = max($hostColumn, $owner->col);
                }
            }
            $this->commentPayloadHostColumns[$at] = $hostColumn;

            break;
        }
    }

    private function resumeRefusedAttribute(): ?int
    {
        if ($this->refusedAttributeHost === null) {
            return null;
        }
        $parent = $this->refusedAttributeHost - 1;
        $this->refusedAttributeHost = null;
        $this->skipped = null;

        return $parent;
    }

    /**
     * @param int $at
     * @param array<int, string> $result
     */
    private function carrySkippedPrelude(int $at, array &$result): bool
    {
        $skipped = $this->skipped;
        if ($skipped === null) {
            return false;
        }
        $this->attachedPreludeLines[$at] = true;
        $this->attachedPreludeContentColumns[$at] = $skipped['host']->col;
        if (isset($skipped['noteBodies'][$at])) {
            $this->lazyLines[$at] = true;
        }
        $inSpan = $skipped['fenced'][$at] ?? false;
        if ($inSpan) {
            $this->skippedCommentLines[$at] = true;
            if (isset($skipped['outside'])) {
                $this->skipped['outside'] = true;
            }
        } elseif ($skipped['outside'] ?? false) {
            // Past a span that closed the host, a comment line stands in the host's parent.
            $this->commentColumnOwners[$at] = $this->ownerPath($skipped['hostIndex'] - 1);
        }
        $abbreviation = !$inSpan && $skipped['hostIndex'] === 1
            && preg_match('/^\*\[([A-Za-z0-9]+)\]: +(?![ \t]*$)([^ ].*)$/D', trim($this->lines[$at]), $match) === 1
            ? [$match[1], rtrim($match[2], " \t")]
            : null;
        if ($abbreviation !== null) {
            $this->skippedAbbreviations[$at] = $abbreviation;
        }
        $level = $this->throughQuotes($this->lines[$at], $skipped['hostIndex'], $this->frames);
        $rest = $level['rest'] ?? '';
        if (!($skipped['fenced'][$at] ?? false) && preg_match('/^[ \t]*\[\^([^\]]+)\]: (.*)$/D', $rest, $note) === 1) {
            $key = LabelKey::normalize($note[1]);
            $noteColumn = self::docColumn($this->lines[$at], ltrim($rest, " \t"));
            if (isset($this->noteLabels[$key]) && self::isPlus($note[2])) {
                // Like any note marker line it ends the paragraph open before it.
                $this->losingNoteLines[$at] = true;
                $this->refusedNotePlus[$at] = $this->notePlusFinding($at, $noteColumn, $skipped['hostIndex'], $this->skippedRunClaims($skipped));
                $skipped['host']->para = false;
                // Only while the block below still stands in the host; each skipped line moves once.
                // A live `+` definition skipped before it is offered that block, as if the losing line were not there (carve#5528).
                if (
                    !($skipped['offered'] ?? false)
                    && $this->throughQuotes($this->lines[$skipped['end']] ?? '', $skipped['hostIndex'], $this->frames) !== null
                ) {
                    $from = max($skipped['at'] + 1, $this->skippedRunShiftedTo[$skipped['at']] ?? 0);
                    self::standSkippedRunInHost($skipped['host'], $from, $at, $skipped['lead'], $skipped['fenced'], $this->losingNoteLines, $this->lines, $result);
                    $this->skippedRunShiftedTo[$skipped['at']] = $at + 1;
                }
            } else {
                $this->skipped['offered'] = self::isPlus($note[2]) && self::docColumn($this->lines[$at], $rest) === $skipped['column'];
                if (self::isPlus($note[2])) {
                    $this->skipped['offeredAt'] = $at;
                    $this->refusedNotePlus[$at] = $this->notePlusFinding($at, $noteColumn, $skipped['hostIndex'], $this->skippedRunClaims($skipped));
                }
            }
            $this->noteLabels[$key] = true;
        }
        // A second marker in a skipped definition cannot claim the block the earlier marker took (carve#5013).
        if (!($skipped['fenced'][$at] ?? false) && $abbreviation === null && self::isPlus(trim($rest, " \t"))) {
            if (self::docColumn($this->lines[$at], ltrim($rest, " \t")) === $skipped['column']) {
                $this->refusedPlus[$at] = $this->finding($at, $skipped['host']->kind, 'claimed');
                $rest = '%%';
            } else {
                $rest = str_replace('+', $this->letter, $rest);
                $this->refusedPlus[$at] = null;
                $this->letterPlus[$at] = true;
            }
        }
        // A continuation keeps its columns past the line that opened its run (CARVE-P2-035).
        $pad = 0;
        if (trim($rest, " \t") === '') {
            $this->skipped['runColumn'] = null;
        } else {
            $text = ltrim($rest, " \t");
            $column = ($level['col'] ?? 0)
                + self::frameColumn($this->lines[$at], $text, $this->frames, $skipped['hostIndex']) - self::docColumn($this->lines[$at], $text);
            $this->skipped['runColumn'] ??= $column;
            $pad = max(0, $column - $this->skipped['runColumn']);
        }
        $result[] = $skipped['lead'] . match (true) {
            $inSpan => str_repeat(' ', $skipped['spanPad'] ?? 0) . '%%',
            // A losing duplicate takes nothing and stands in the host, so the host keeps the lines below (carve#5474).
            isset($this->losingNoteLines[$at]) => str_repeat(' ', self::spanPad($skipped['host'])) . '%%',
            $abbreviation !== null => '%%',
            default => str_repeat(' ', $pad) . $rest,
        };
        if ($this->trace !== null) {
            $this->emit($at, 'S5', $this->ownerPath(), 'CARVE-P0-024', 'leaf', ['plus' => $at]);
        }
        if ($at + 1 === $skipped['end']) {
            if ($skipped['host']->plus === $skipped['at']) {
                $skipped['host']->plus = $at;
            }
            if ($this->skippedAttributeClosesHost($skipped)) {
                $this->literalPlusAfterAttribute = [
                    'line' => $skipped['end'],
                    'column' => $skipped['host']->markerCol,
                    'hostIndex' => $skipped['hostIndex'],
                ];
            }
            $this->skipped = null;
        }

        return true;
    }

    /**
     * A valid marker-column attribute block in the skipped prelude closes its
     * host before the next line is owned (plus ownership A02).
     *
     * @param array{at: int, end: int, hostIndex: int, column: int, host: \MarkupCarve\Carve\Parser\OwnershipFrame} $skipped
     */
    private function skippedAttributeClosesHost(array $skipped): bool
    {
        if (!in_array($skipped['host']->kind, ['item', 'dd', 'note'], true)) {
            return false;
        }
        for ($start = $skipped['at'] + 1; $start < $skipped['end']; $start++) {
            $line = $this->throughQuotes($this->lines[$start], $skipped['hostIndex'], $this->frames);
            if ($line === null || ($line['col'] ?? null) !== $skipped['host']->markerCol || !str_starts_with($line['rest'], '{')) {
                continue;
            }
            if ($start + 1 === $skipped['end'] && ($this->isAttributeLine)($line['rest'])) {
                return true;
            }
            $length = $this->skippedWrappedAttributeLength($start, $skipped['hostIndex'], $this->frames);
            if ($length !== null && $start + $length === $skipped['end']) {
                return true;
            }
        }

        return false;
    }

    /**
     * A losing line ends the definitions skipped before it, so the host keeps them and the block below (PART 9R R2, carve-php#4909).
     *
     * @param \MarkupCarve\Carve\Parser\OwnershipFrame $host
     * @param int $from
     * @param int $at
     * @param string $lead
     * @param array<int, bool> $fenced
     * @param array<int, true> $losing
     * @param array<int, string> $lines
     * @param array<int, string> $result
     */
    private static function standSkippedRunInHost(
        OwnershipFrame $host,
        int $from,
        int $at,
        string $lead,
        array $fenced,
        array $losing,
        array $lines,
        array &$result,
    ): void {
        $pad = str_repeat(' ', self::spanPad($host));
        if ($pad === '') {
            return;
        }
        for ($k = $from; $k < $at; $k++) {
            // A line whose own prefix is not the host's lead stands outside it.
            if (isset($losing[$k]) || ($fenced[$k] ?? false) || !isset($result[$k]) || !str_starts_with($lines[$k] ?? '', $lead) || !str_starts_with($result[$k], $lead)) {
                continue;
            }
            $result[$k] = $lead . $pad . substr($result[$k], strlen($lead));
        }
    }

    /**
     * A comment or one-line definition closes no indentation frame: the next
     * visible line picks the owner (CARVE-P9-013).
     */
    private function standInvisibleLine(int $from, bool $atPlus): int
    {
        $top = count($this->frames) - 1;
        if ($this->at <= $this->movedSpanEnd) {
            // The rest of a matched `%%%` span moves with its opener.
            $this->standAt($this->frames[$top]->col);

            return $top;
        }
        if ($atPlus || $from === $top || $this->frames[$top]->kind === 'fence' || !$this->transparentLine($this->m['rest'], $this->at)) {
            return $from;
        }
        // A line that misses an open fence's owner ends that fence first (S1), so it is read where it stands.
        $deeper = $this->nextVisibleReach($this->at, $from);
        if ($deeper === $from) {
            return $from;
        }
        // A document-level abbreviation keeps its definition and leaves a comment in the frame it moves into.
        if ($from === 0 && preg_match('/^\*\[([A-Za-z0-9]+)\]: +(?![ \t]*$)([^ ].*)$/D', rtrim($this->m['rest'], " \t"), $abbreviation) === 1) {
            $this->skippedAbbreviations[$this->at] = [$abbreviation[1], $abbreviation[2]];
            $this->m['rest'] = '%%';
        }
        $this->standAt($this->frames[$deeper]->col);
        $this->movedSpanEnd = $this->commentSpanEnd(rtrim($this->m['rest'], " \t"), $this->at);

        return $deeper;
    }

    private function holdAttachedBraceRun(int $at, int $out): void
    {
        $this->attachedBraceRun = str_starts_with($this->m['rest'], '{') ? ['last' => $at, 'col' => $this->m['col'], 'out' => $out] : null;
    }

    /**
     * The column a lazy line is written at before the frame's bounds apply.
     */
    private function lazyColumn(int $at, OwnershipFrame $owner): int
    {
        // A continuation of an attached brace run moves with its opener, so its column past the `{` holds (CARVE-P2-035).
        if ($this->attachedBraceRun === null || $this->attachedBraceRun['last'] !== $at - 1) {
            return $this->m['col'] - $owner->cut;
        }
        $this->attachedBraceRun['last'] = $at;

        return $this->attachedBraceRun['out'] + $this->m['col'] - $this->attachedBraceRun['col'];
    }

    private function recordCommentColumnOwner(int $at, int $reached): void
    {
        // Between a term and its description the description takes the comment (CARVE-P12-027).
        if ($this->trace !== null && str_starts_with($this->m['rest'], '%%') && $this->frames[$reached]->last !== 'term') {
            $this->commentColumnOwners[$at] = $this->ownerPath($reached);
        }
    }

    private function standAt(int $col): void
    {
        $this->src = str_repeat(' ', $col) . $this->m['rest'];
        $this->m = self::indentCols($this->src);
    }

    private function transparentLine(string $rest, int $k): bool
    {
        $text = rtrim($rest, " \t");
        if (ctype_alpha($text[0] ?? '')) {
            return false;
        }
        // CARVE-P11-073: a region marker below a list closes every item; a footnote definition opens a frame.
        if (preg_match('/^%%[ \t]*#(?:end)?region(?![A-Za-z0-9_])/', $text) === 1 || str_starts_with($text, '[^')) {
            return false;
        }

        return $this->commentSpanEnd($text, $k) !== -1 || str_starts_with($text, '%%') || ($this->isDefinition)($text)
            || preg_match('/^\*\[[A-Za-z0-9]+\]: +(?![ \t]*$)[^ ]/', $text) === 1;
    }

    /**
     * The last line of the `%%%` span opened by `$text` at `$k`, or -1 when `$text` opens none.
     * With no closer the span runs to the end (carve#4907), so its follower is no visible line.
     */
    private function commentSpanEnd(string $text, int $k): int
    {
        if (preg_match('/^(%{3,})/', $text, $comment) !== 1) {
            return -1;
        }
        $count = count($this->lines);
        if (($this->closerRuns()['lastComment'][strlen($comment[1])] ?? -1) <= $k) {
            return $count - 1;
        }
        for ($j = $k + 1; $j < $count; $j++) {
            $line = ltrim(rtrim($this->lines[$j], " \t"), " \t>");
            if (preg_match('/^(%{3,})/', $line, $close) === 1 && strlen($close[1]) === strlen($comment[1])) {
                return $j;
            }
        }

        return $count - 1;
    }

    /**
     * How deep the next visible line reaches through the indentation frames below `$from`.
     */
    private function nextVisibleReach(int $at, int $from): int
    {
        $count = count($this->lines);
        $unquoted = static fn (string $line): string => self::indentCols((string)preg_replace('/^[ \t>]*(?=>)>? ?/', '', $line))['rest'];
        if ($this->nextVisible <= $at) {
            $own = $this->commentSpanEnd(rtrim($unquoted($this->lines[$at]), " \t"), $at);
            $this->nextVisible = ($own === -1 ? $at : $own) + 1;
            for (; $this->nextVisible < $count; $this->nextVisible++) {
                $text = rtrim($unquoted($this->lines[$this->nextVisible]), " \t");
                if ($text === '') {
                    continue;
                }
                $span = $this->commentSpanEnd($text, $this->nextVisible);
                if ($span !== -1) {
                    $this->nextVisible = $span;
                } elseif (!$this->transparentLine($text, $this->nextVisible)) {
                    break;
                }
            }
        }
        if ($this->nextVisible >= $count) {
            return $from;
        }
        $next = self::indentCols($this->lines[$this->nextVisible]);
        for ($k = 1; $k <= $from; $k++) {
            if ($this->frames[$k]->kind !== 'quote') {
                continue;
            }
            if (preg_match(self::QUOTE, $next['rest'], $q) !== 1) {
                return $from;
            }
            $next = self::indentCols($q[1] ?? '');
        }
        $deepest = $from;
        $frameCount = count($this->frames);
        for ($k = $from + 1; $k < $frameCount; $k++) {
            $frame = $this->frames[$k];
            if (!in_array($frame->kind, ['item', 'dd', 'note'], true) || $next['col'] < $frame->col) {
                break;
            }
            $deepest = $k;
        }

        return $deepest;
    }

    private function abbreviationMissesFrames(int $reached, OwnershipForm $form): bool
    {
        return $reached === 0 && $form->kind === 'leaf' && str_starts_with($this->m['rest'], '*[')
            && ($this->m['col'] === 0 || (isset($this->frames[1]) && $this->m['col'] < $this->frames[1]->col));
    }

    private function emitLazy(int $at, int $reached): void
    {
        if ($this->trace !== null) {
            $this->emit($at, 'S4', $this->ownerPath(), 'CARVE-P0-006', 'lazy', ['reached' => $this->ownerPath($reached)]);
        }
    }

    /**
     * The S5 record: `form` is the S3 classification, `cell` the FAMILIES
     * entry (B-<family>-start, B-div-end) when one decided the line.
     */
    private function emitApplied(int $at, OwnershipForm $form, string $owner, int $closed, ?string $reached, bool $plusFollower): void
    {
        // The attached block is the host's one next block, so the host it reads past is not closed (PART 9 §17 L3).
        if ($this->traceAttachedHost !== null) {
            $owner = $this->traceAttachedHost['host'] . substr($owner, strlen($this->traceAttachedHost['reached']));
            $closed--;
            $reached = $closed > 0 ? $this->traceAttachedHost['host'] : null;
            $this->traceAttachedHost = null;
        }
        if ($this->traceBlankHost !== null) {
            if ($form->kind === 'blank' || ($form->kind === 'text' && $this->m['col'] >= $this->traceBlankHost['column'])) {
                $owner = $this->traceBlankHost['owner'];
            } else {
                $this->traceBlankHost = null;
            }
        }
        $kind = match ($form->kind) {
            'colon-close' => 'closer',
            'colon' => 'div',
            'note' => 'footnote',
            default => $form->family ?? $form->kind,
        };
        $cell = match ($form->kind) {
            'colon-close' => 'B-div-end',
            'quote', 'item', 'note', 'dd', 'term', 'colon', 'fence' => 'B-' . ($form->family ?? $kind) . '-start',
            default => null,
        };
        $rule = match ($form->kind) {
            'blank' => 'CARVE-P0-003',
            'leaf' => 'CARVE-P0-011',
            default => 'CARVE-P0-024',
        };
        $this->emit($at, 'S5', $owner, $rule, $kind, [
            'cell' => $cell,
            'closed' => $closed > 0 ? $closed : null,
            'reached' => $reached,
            'plus' => $plusFollower ? $at : null,
        ]);
    }

    /**
     * @param int $index
     * @param string $step
     * @param string $owner
     * @param string|null $rule
     * @param string $form
     * @param array<string, int|string|null> $extra
     */
    private function emit(int $index, string $step, string $owner, ?string $rule, string $form, array $extra = []): void
    {
        if ($this->traceLeavesOnly && ($step !== 'S5' || $form !== 'leaf')) {
            return;
        }
        $record = ['line' => $index + 1, 'step' => $step, 'owner' => $owner, 'rule' => $rule, 'form' => $form];
        foreach ($extra as $key => $value) {
            if ($value !== null) {
                $record[$key] = $value;
            }
        }
        $this->trace[] = $record;
    }

    private function traceReach(int $at, int $reached, int $closed, bool $plusFollower): ?string
    {
        $this->traceAttachedHost = null;
        if ($this->trace === null || $closed <= 0) {
            return null;
        }
        $path = $this->ownerPath($reached);
        if (
            $plusFollower && $this->releasedSibling === 0 && $this->quoteDepth($reached + 2) === 0
            && ($this->frames[$reached + 1] ?? null)?->kind === 'item' && $this->frames[$reached + 1]->plus === $at - 1
        ) {
            $this->traceAttachedHost = ['host' => $this->ownerPath($reached + 1), 'reached' => $path];
        }

        return $path;
    }

    /**
     * Open containers from the document down to frame `$depth`, as `doc/<kind>:<line>`.
     */
    private function ownerPath(?int $depth = null): string
    {
        $depth ??= count($this->frames) - 1;
        $path = 'doc';
        for ($k = 1; $k <= $depth; $k++) {
            $f = $this->frames[$k];
            $kind = match ($f->kind) {
                'note' => 'footnote',
                'colon' => 'div',
                'fence' => (string)$f->family,
                default => $f->kind,
            };
            $path .= '/' . $kind . ':' . (($f->opened ?? $f->line ?? 0) + 1);
        }

        return $path;
    }

    /**
     * The blank that ends a `+` attached container with no closer is the host's (§17 L3).
     */
    private function blankEndsAttachedFence(int $at, OwnershipFrame $inner): bool
    {
        if (!in_array($inner->kind, ['fence', 'colon'], true) || !$inner->endsAtBlank || $this->m['rest'] !== '') {
            return false;
        }
        $this->attachedFenceBlankEnds[$at] = true;
        if ($inner->kind === 'fence' && $inner->family === 'code' && $inner->closes !== null) {
            // The `+` host, which the walk may already have closed when it read the fence where it stands.
            $hostFrames = $this->attachedTo['frames'] ?? array_slice($this->frames, 0, count($this->frames) - 1);
            $hostIndex = count($hostFrames) - 1;
            $host = $hostFrames[$hostIndex];
            $stacked = false;
            for ($k = 1; $k < $hostIndex; $k++) {
                $stacked = $stacked || ($host->line !== null && $hostFrames[$k]->line === $host->line);
            }
            // The fold belongs to the paragraph's owner however many hosts the blank released; the line must pass the host's reach (carve#5606, carve#5636).
            $this->endedAttachedFence = [
                'min' => $host->kind === 'item' && !$stacked ? $host->col : $inner->col + ($stacked ? 1 : 2),
                'at' => $at,
            ];
            // Its one attached block has ended, so later lines must not re-test that offer.
            $this->attachedTo = ($this->attachedTo['parent'] ?? null)?->host;
        }

        return true;
    }

    private function hasLaterFenceCloser(int $at, int $reached, OwnershipForm $form): bool
    {
        $depth = $this->quoteDepth($reached + 1);
        $ownerColumn = $this->frames[$reached]->col;
        preg_match('/^(`{3,}|~{3,})/', $this->m['rest'], $run);
        $key = $depth . ':' . $ownerColumn . ':' . $this->m['col'] . ':' . ($run[1] ?? '');
        $cached = $this->laterFenceClosers[$key] ?? null;
        if ($cached !== null && $at < $cached['through']) {
            return $cached['paired'];
        }
        for ($next = $at + 1, $count = count($this->lines); $next < $count; $next++) {
            $line = $this->levelOf($this->lines[$next], $depth);
            if ($line === null) {
                $this->laterFenceClosers[$key] = ['through' => $next, 'paired' => false];

                return false;
            }
            if ($line['rest'] !== '' && $line['col'] < $ownerColumn) {
                $this->laterFenceClosers[$key] = ['through' => $next, 'paired' => false];

                return false;
            }
            if ($line['rest'] !== '' && $form->closes !== null && ($form->closes)($line['rest'], $line['col'])) {
                $this->laterFenceClosers[$key] = ['through' => $next, 'paired' => true];

                return true;
            }
        }

        $this->laterFenceClosers[$key] = ['through' => count($this->lines), 'paired' => false];

        return false;
    }

    private function foldFenceAfterAttachedBlank(int $at, int $reached, OwnershipForm $form): OwnershipForm
    {
        if ($this->endedAttachedFence === null || $at <= $this->endedAttachedFence['at']) {
            return $form;
        }
        if ($form->kind === 'blank' || ($form->kind !== 'text' && $form->family !== 'code')) {
            $this->endedAttachedFence = null;

            return $form;
        }
        if (
            $form->family === 'code' && $this->frames[$reached]->para
            && $this->m['col'] >= $this->endedAttachedFence['min']
            && !$this->hasLaterFenceCloser($at, $reached, $form)
        ) {
            $this->foldedFenceAfterAttachedBlank[$at] = true;

            return new OwnershipForm('text', false, kept: true);
        }

        return $form;
    }

    /**
     * The host the colon's opener left resumes, so later lines keep their columns (carve#5396).
     */
    private function resumeAttachedHost(OwnershipFrame $inner): bool
    {
        if ($inner->blankHostFrames === null) {
            return false;
        }
        $this->frames = $inner->blankHostFrames;
        $host = $this->frames[count($this->frames) - 1];
        $host->para = false;
        $host->last = 'block';

        return true;
    }

    /**
     * A closed attached line block hands the line after it back to its host, as a colon block does (carve#5396).
     */
    private function resumeLineBlockHost(OwnershipFrame $inner): void
    {
        if ($inner->lineBlock) {
            $this->resumeAttachedHost($inner);
        }
    }

    private function traceFenceOwner(OwnershipFrame $inner): string
    {
        if ($inner->kind !== 'fence' || $inner->blankHostFrames === null) {
            return $this->ownerPath();
        }
        $saved = $this->frames;
        $this->frames = $inner->blankHostFrames;
        $path = $this->ownerPath() . '/' . $inner->family . ':' . (($inner->opened ?? $inner->line ?? 0) + 1);
        $this->frames = $saved;

        return $path;
    }

    private function recordAttachedBlankHost(OwnershipFrame $inner): void
    {
        if ($this->trace === null || $inner->blankHostFrames === null) {
            return;
        }
        $saved = $this->frames;
        $this->frames = $inner->blankHostFrames;
        $this->traceBlankHost = ['owner' => $this->ownerPath(), 'column' => $this->frames[count($this->frames) - 1]->col];
        $this->frames = $saved;
    }

    /**
     * An attached colon block or code fence ends at its blank unless it has a reachable closer.
     */
    private function markAttachedFence(int $at, int $host, OwnershipForm $form, bool $plusFollower): void
    {
        if ($plusFollower && ($form->kind === 'colon' || ($form->kind === 'fence' && $form->lineBlock))) {
            $container = $this->frames[count($this->frames) - 1];
            if (in_array($this->frames[$host]->kind, ['note', 'dd'], true)) {
                $container->blankHostFrames = array_slice($this->frames, 0, $host + 1);
            }
            if ($this->sibling !== null && $this->sibling->plus === $at - 1) {
                $container->blankHostFrames = [...array_slice($this->frames, 0, $host + 1), $this->sibling];
            }
            $key = rtrim($this->m['rest']) . '|' . $this->m['col'] . '|' . $container->col . '|' . ($this->sibling->markerCol ?? -1);
            $container->endsAtBlank = !$this->attachedFenceClosed($at, $container, $key);

            return;
        }
        if (!$plusFollower || $form->kind !== 'fence' || $form->family !== 'code') {
            return;
        }
        $fence = $this->frames[count($this->frames) - 1];
        $fence->plusAttached = true;
        if (in_array($this->frames[$host]->kind, ['note', 'dd'], true)) {
            $fence->blankHostFrames = array_slice($this->frames, 0, $host + 1);
        }
        if (
            $this->sibling !== null && in_array($this->sibling->kind, ['item', 'note', 'dd'], true)
            && $this->sibling->plus === $at - 1
        ) {
            $fence->blankHostFrames = [...array_slice($this->frames, 0, $host + 1), $this->sibling];
        }
        if ($this->frames[$host]->kind !== 'item' && $fence->blankHostFrames === null) {
            return;
        }
        $opener = $this->fenced->parseCodeFenceOpener($this->m['rest'])
            ?? $this->fenced->parseRawBlockOpener($this->m['rest']);
        if (
            $this->frames[$host]->kind === 'item' && $opener !== null
            && $this->attachedCodeFenceBlankAhead($at, (int)$this->frames[$host]->markerCol, $opener['fence'][0], $opener['length'])
        ) {
            $fence->col = $this->m['col'];
        }
        $key = rtrim($this->m['rest']) . '|' . $this->m['col'] . '|' . $fence->col;
        $plusItem = $this->frames[$host]->kind === 'item' ? $this->frames[$host]
            : ($this->sibling?->kind === 'item' && $this->sibling->plus === $at - 1 ? $this->sibling : null);
        $fence->endsAtBlank = !$this->attachedFenceClosed($at, $fence, $key, $plusItem?->col);
        $fence->closerBelow = !$fence->endsAtBlank && $plusItem !== null && $fence->col < $plusItem->col ? $plusItem->col : null;
    }

    /**
     * A `+` at an open code fence's content column stays payload (PART 9 §17 L3, carve#5494).
     */
    private function openCodeFenceKeepsPlus(int $frameCount): bool
    {
        $top = $this->frames[$frameCount - 1];

        return $top->kind === 'fence' && $top->plusAttached && $this->m['col'] >= $top->col;
    }

    private function attachedCodeFenceBlankAhead(int $at, int $column, string $char, int $length): bool
    {
        for ($k = $at + 1, $n = count($this->lines); $k < $n; $k++) {
            $line = self::indentCols($this->lines[$k]);
            while (preg_match(self::QUOTE, $line['rest'], $q) === 1) {
                $line = self::indentCols($q[1] ?? '');
            }
            if ($line['rest'] === '') {
                return true;
            }
            if ($line['col'] < $column || $this->fenced->isCodeFenceCloser($line['rest'], $char, $length)) {
                return false;
            }
        }

        return false;
    }

    private function reachesAttachedItemFence(int $frame): bool
    {
        $item = $this->frames[$frame];
        $fence = $this->frames[$frame + 1] ?? null;

        return $item->kind === 'item' && $item->plus !== null && $item->markerCol > 0
            && $this->m['col'] >= $item->markerCol
            && $fence?->kind === 'fence' && $fence->col <= $this->m['col'];
    }

    private function missesFrameAtColumn(int $frame, bool $inWrappedAttribute, int $segment, ?int &$stripped): bool
    {
        $f = $this->frames[$frame];
        $inner = $this->frames[count($this->frames) - 1];
        // S1 keeps an open code fence's payload before S2 remeasures frames.
        $lead = substr($this->src, 0, strlen($this->src) - strlen($this->m['rest']));
        if ($inner->kind === 'fence' && $inner->family === 'code' && str_contains($lead, "\t") && $this->m['col'] >= $inner->col) {
            return false;
        }
        if ($this->reachesAttachedItemFence($frame) || $this->m['rest'] === '' || $inWrappedAttribute) {
            return false;
        }
        // A tab past an off-stop frame column is measured in that frame (PART 9 §24 C5).
        $stripped ??= $this->strippedColumn($this->src, $segment, $this->m);

        return $stripped < $f->col + $f->c5Shift;
    }

    private function attachedItemFollowerReach(int $reached, bool $plusFollower): int
    {
        $item = $this->frames[count($this->frames) - 1];
        $held = $this->attachedTo;
        // A marker-line plus offers the entire table at the nested item's
        // marker column. Once its first row is read, following rows have the
        // same owner even though they remain left of the content column.
        if (
            $reached < count($this->frames) - 1
            && $item->kind === 'item' && $item->markerCol > 0
            && $this->m['col'] === $item->markerCol
            && ($this->tables->isTableRow($this->m['rest']) || $this->tables->isContinuationRow($this->m['rest']))
            && ($plusFollower || $item->tableLine === $this->at - 1)
        ) {
            return count($this->frames) - 1;
        }
        if (
            $plusFollower && $held !== null && $held['kind'] === 'note'
            && $held['from'] === $this->at && $this->heldOnMarkerLine($held)
            && $item->kind === 'note' && $this->m['col'] === $item->markerCol
        ) {
            $parent = $this->frames[count($this->frames) - 2];
            $form = $this->classifyLine($this->m['rest'], $parent, $this->m['col']);
            if ($form->kind === 'leaf' && $form->interrupts) {
                return count($this->frames) - 1;
            }
        }
        $opener = $this->fenced->parseCodeFenceOpener($this->m['rest']);
        if (
            $plusFollower && $reached < count($this->frames) - 1
            && $item->kind === 'item' && $item->markerCol > 0
            && $this->m['col'] === $item->markerCol
            && $opener !== null
            && $this->attachedCodeFenceBlankAhead($this->at, (int)$item->markerCol, $opener['char'], $opener['length'])
        ) {
            return count($this->frames) - 1;
        }

        return $reached;
    }

    /**
     * Whether a closer follows the fence before a line below its host's column.
     *
     * A later fence of the same shape below the last scan's stop reuses its answer, so the walk stays linear.
     */
    private function attachedFenceClosed(int $at, OwnershipFrame $fence, string $key, ?int $itemContentColumn = null): bool
    {
        $last = $this->attachedFenceScans[$key] ?? null;
        if ($last !== null && $last[0] > $at) {
            return $last[1];
        }
        $n = count($this->lines);
        $code = null;
        $afterBlank = false;
        // Colon blocks of the same run opened past the attached one, each waiting for its own closer.
        $nested = 0;
        $hostFrame = $fence->blankHostFrames === null ? null
            : $fence->blankHostFrames[count($fence->blankHostFrames) - 1];
        $codeOpener = $fence->kind === 'fence' && $fence->family === 'code'
            && $hostFrame !== null && in_array($hostFrame->kind, ['note', 'dd'], true)
            ? $this->fenced->parseCodeFenceOpener(ltrim($this->lines[$at], " \t")) : null;
        // Past an interior blank the item reads a closer below its content column at the fence's,
        // whatever stands between the blank and it (§17 L3, carve-php#4961, carve#5587).
        $shifts = false;
        $shiftBelow = $itemContentColumn !== null && $fence->kind === 'fence' && $fence->family === 'code' ? $itemContentColumn : -1;
        for ($k = $at + 1; $k < $n; $k++) {
            $line = self::indentCols($this->lines[$k]);
            while (preg_match(self::QUOTE, $line['rest'], $q) === 1) {
                $line = self::indentCols($q[1] ?? '');
            }
            if ($fence->kind === 'colon' && $code !== null) {
                if ($this->fenced->isCodeFenceCloser($line['rest'], $code['char'], $code['length'])) {
                    $code = null;
                }

                continue;
            }
            if ($line['rest'] === '') {
                $afterBlank = true;
                $shifts = true;

                continue;
            }
            if (
                $afterBlank && $hostFrame !== null && in_array($hostFrame->kind, ['note', 'dd'], true)
                && $line['col'] < $fence->blankHostFrames[count($fence->blankHostFrames) - 1]->col
            ) {
                return ($this->attachedFenceScans[$key] = [$k, false])[1];
            }
            if (
                $afterBlank && $itemContentColumn !== null && $line['col'] < $itemContentColumn
                && !($fence->kind === 'fence' && $fence->family === 'code')
                && !($fence->closes !== null && ($fence->closes)($line['rest'], $line['col']))
            ) {
                return ($this->attachedFenceScans[$key] = [$k, false])[1];
            }
            if ($line['col'] < $fence->col) {
                return ($this->attachedFenceScans[$key] = [$k, false])[1];
            }
            if (
                $fence->kind === 'colon' && $this->sibling?->kind === 'item'
                && $line['col'] === $this->sibling->markerCol
                && $this->listParser->parseListItemMarker($line['rest']) !== null
            ) {
                return ($this->attachedFenceScans[$key] = [$k, false])[1];
            }
            if ($fence->kind === 'colon') {
                $code = $this->fenced->parseCodeFenceOpener($line['rest']);
                if ($code !== null) {
                    continue;
                }
                $span = $this->commentSpanEnd(rtrim($line['rest'], " \t"), $k);
                if ($span !== -1) {
                    $k = $span;
                    $afterBlank = false;

                    continue;
                }
                $bare = preg_match('/^:{' . $fence->run . '}[ \t]*$/D', $line['rest']) === 1;
                if (!$fence->lineBlock && ($bare ? $nested > 0 : ($this->fenced->parseDivFenceOpener($line['rest'])['length'] ?? null) === $fence->run)) {
                    $nested += $bare ? -1 : 1;
                    $afterBlank = false;

                    continue;
                }
            }
            if (
                ($codeOpener !== null && $line['col'] === $fence->col
                    && $this->fenced->isCodeFenceCloser($line['rest'], $codeOpener['char'], $codeOpener['length']))
                ||
                ($fence->closes !== null && ($fence->closes)($line['rest'], $shifts && $line['col'] < $shiftBelow ? min($line['col'], $fence->col) : $line['col']))
                || ($fence->kind === 'colon' && preg_match('/^:{' . $fence->run . '}[ \t]*$/D', $line['rest']) === 1)
            ) {
                return ($this->attachedFenceScans[$key] = [$k, true])[1];
            }
            $afterBlank = false;
        }

        return ($this->attachedFenceScans[$key] = [$n, false])[1];
    }

    /**
     * Whether this payload line closes the fence at its column from below the `+` item's content column (carve-php#4961).
     */
    private function readsShiftedCloser(OwnershipFrame $fence): bool
    {
        if ($fence->closerBelow === null) {
            return false;
        }
        $reads = $fence->closerShifts && $fence->closes !== null
            && $this->m['col'] > $fence->col && $this->m['col'] < $fence->closerBelow
            && ($fence->closes)($this->m['rest'], $fence->col);
        $fence->closerShifts = $fence->closerShifts || $this->m['rest'] === '';

        return $reads;
    }

    private function recordFenceBodyLine(int $at, OwnershipFrame $fence, bool $closer): void
    {
        if ($fence->family !== 'comment' && !$fence->lineBlock) {
            $this->fenceBodyLines[$at] = true;
        }
        if ($fence->family === 'code' && !$closer) {
            $this->codePayloadLines[$at] = true;
        }
    }

    private function apply(int $owner, int $col, OwnershipForm $form, int $canon, ?int $reader = null, bool $inHost = false): void
    {
        $reader ??= $this->frames[$owner]->reader ?? $this->frames[$owner]->canon;
        // A child list replaces the term as the item's latest block. Its
        // following description-shaped line can then remain lazy (carve#5320).
        if ($form->kind === 'item' && $this->frames[$owner]->kind === 'item') {
            $this->frames[$owner]->hasDefinition = false;
        }
        if ($form->kind === 'term') {
            for ($k = count($this->frames) - 1; $k >= 0; $k--) {
                if ($this->frames[$k]->kind === 'item') {
                    $this->frames[$k]->hasDefinition = true;

                    break;
                }
            }
        }
        if ($form->caption) {
            $this->openParagraph($this->frames[$owner], $this->at, true);
        } else {
            $this->frames[$owner]->para = false;
        }
        // An invisible line between siblings leaves the host's last form standing (carve#4716).
        if (
            $form->family !== 'comment' && ($form->kind !== 'leaf' || ((!str_starts_with($this->m['rest'], '%%') || str_starts_with($this->m['rest'], '%%%')) && !($this->isDefinition)($this->m['rest'])
            && !($this->frames[$owner]->kind === 'doc' && preg_match('/^\*\[[A-Za-z0-9]+\]: +(?![ \t]*$)/', $this->m['rest']) === 1)))
        ) {
            $this->frames[$owner]->last = $form->kind;
        }
        if ($form->kind === 'term' && $this->frames[$owner]->kind === 'item') {
            $this->frames[$owner]->descriptionLeft = true;
        } elseif ($form->kind !== 'dd' && $this->frames[$owner]->kind === 'item' && $this->frames[$owner]->last === $form->kind) {
            $this->leaveClosedDescription($owner);
        }
        $this->frames[$owner]->tableLine = $form->table ? $this->at : null;
        $o = $this->frames[$owner];
        if ($form->kind === 'fence' || $form->kind === 'colon') {
            if ($form->kind === 'fence' && !$form->lineBlock) {
                $this->fenceOpenerLines[$this->at] = true;
            }
            $this->push(new OwnershipFrame(
                $form->kind,
                $o->col,
                $o->canon,
                // A comment payload is host-relative; the opener's indentation sets no base (CARVE-P9-060).
                $form->family === 'comment' && $inHost ? $o->col - $o->canon : $col - $canon,
                closes: $form->closes,
                run: $form->run,
                lineBlock: $form->lineBlock,
                reader: $reader,
                family: $form->family ?? 'div',
                opened: $this->at,
            ));

            return;
        }
        if ($form->kind === 'quote') {
            $this->push(new OwnershipFrame(
                'quote',
                0,
                0,
                0,
                markerCol: $col,
                markerCanon: $canon,
                docMarker: self::docColumn($this->lines[$this->at], $this->src),
                line: $this->at,
                written: self::columnOf($this->out),
            ));
            preg_match(self::QUOTE, $this->src, $q);
            $this->take(strlen($this->src) - strlen($q[1] ?? ''));
            $this->m = self::indentCols($this->src);
            $this->content(count($this->frames) - 1, 0, 0);

            return;
        }
        if ($form->kind === 'item' || $form->kind === 'note' || $form->kind === 'dd') {
            // A sibling keeps its list's column.
            $sibling = $this->sibling;
            $this->sibling = null;
            $listCol = $sibling !== null && $sibling->kind === $form->kind && $sibling->axes === $form->axes ? ($sibling->listCol ?? $col) : $col;
            $this->push(new OwnershipFrame(
                $form->kind,
                $col + $form->width,
                $canon + $form->width,
                $col - $canon,
                markerCol: $col,
                markerCanon: $canon,
                axes: $form->axes,
                listCol: $listCol,
                task: $form->task,
                reader: $reader + $form->width,
                docMarker: self::docColumn($this->lines[$this->at], $this->src),
                line: $this->at,
                written: self::columnOf($this->out),
            ));
            // Content behind a lead tab is paragraph text, whatever it spells (corpus 684, 736, 840).
            $leadTab = str_contains(substr($this->src, 0, $form->head), "\t")
                || ($this->src[$form->head] ?? '') === "\t";
            if ($form->kind === 'note' && preg_match('/^\[\^([^\]]+)\]:/', $this->m['rest'], $note) === 1) {
                $key = LabelKey::normalize($note[1]);
                $this->losingNoteLine = isset($this->noteLabels[$key]);
                $this->noteLabels[$key] = true;
            }
            $this->take($form->head);
            $this->m = self::indentCols($this->src);
            $this->content(count($this->frames) - 1, $col + $form->head, $canon + $form->head, $leadTab);
            $this->losingNoteLine = false;
        }
    }

    /**
     * A plain frame (no marker column, not a quote) joins the run of an equal plain frame below it,
     * so S2 can step over the run at once. Frames are pushed once and never move.
     */
    private function push(OwnershipFrame $frame): void
    {
        $index = count($this->frames);
        $below = $this->frames[$index - 1];
        $head = $below->runHead ?? $below;
        if (
            $index > 1 && $frame->markerCol === null && $frame->kind !== 'quote' && $below->markerCol === null && $below->kind !== 'quote'
            && $frame->col === $below->col && $frame->c5Shift === $below->c5Shift
        ) {
            $frame->runHead = $head;
        } else {
            $frame->runEnd = $index;
        }
        $head->runEnd = $frame->runHead !== null ? $index : $index - 1;
        // A list, quote or note opened after the term or description keeps its own paragraph open to lazy lines.
        if ($frame->kind === 'item' || $frame->kind === 'quote' || $frame->kind === 'note') {
            $this->markDescriptionLeft($index - 1, false);
        }
        $this->frames[] = $frame;
    }

    private function markDescriptionLeft(int $from, bool $left): void
    {
        for ($k = $from; $k > 0; $k--) {
            if ($this->frames[$k]->kind === 'item') {
                $this->frames[$k]->descriptionLeft = $left;
                if (!$left) {
                    $this->frames[$k]->hasDefinition = false;
                }

                return;
            }
        }
    }

    /**
     * The rest of a run of plain frames answers S2 as frame `$k` did.
     */
    private function runEnd(int $k, int $frameCount): int
    {
        $f = $this->frames[$k];
        if ($f->markerCol !== null || $f->kind === 'quote') {
            return $k;
        }
        $head = $f->runHead ?? $f;
        $end = min($head->runEnd, $frameCount - 1);

        return $end > $k && $this->frames[$end]->runHead === $head ? $end : $k;
    }

    private function truncate(int $count): void
    {
        $leftDescription = false;
        for ($n = count($this->frames); $n > $count; $n--) {
            $closing = $this->frames[$n - 1];
            $leftDescription = $leftDescription || $closing->kind === 'dd' || $closing->last === 'term' || $closing->descriptionLeft;
            array_pop($this->frames);
        }
        if ($leftDescription) {
            $this->markDescriptionLeft($count - 1, true);
        }
    }

    private function noteChildAtSource(string $rest): bool
    {
        return $this->listParser->parseListItemMarker($rest) !== null
            || preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $rest) === 1;
    }

    private function content(int $frame, int $start, int $canon, bool $leadTab = false): void
    {
        if ($this->m['rest'] === '' || count($this->frames) > BlockGrammar::MAX_NESTING_DEPTH) {
            return;
        }
        $this->onMarkerLine = true;
        $column = match (true) {
            $this->frames[$frame]->kind === 'quote' => $this->m['col'],
            // A marker-line note's opener is read at the body column unless it chains markers (CARVE-P9-083).
            $this->frames[$frame]->kind === 'note' && !$this->noteChildAtSource($this->m['rest']) => $this->frames[$frame]->col,
            default => $start + $this->m['col'],
        };
        $form = $this->classifyLine($this->m['rest'], $this->frames[$frame], $column);
        $this->onMarkerLine = false;
        if ($form->kind === 'text') {
            $plus = false;
            $consumed = false;
            $losing = false;
            if ($leadTab && self::isPlus($this->m['rest'])) {
                $this->refusePlus($this->at, null);
            } elseif ($this->losingNoteLine && self::isPlus($this->m['rest'])) {
                // A losing duplicate's `+` takes nothing, leaves no paragraph and closes the note (carve#5474).
                $losing = true;
                $this->frames[$frame]->losing = true;
                $this->losingNoteLines[$this->at] = true;
                $this->refusedNotePlus[$this->at] = $this->notePlusFinding($this->at, (int)$this->frames[$frame]->docMarker, $frame);
            } elseif (self::isPlus($this->m['rest'])) {
                // Past the one space after a `>` the `+` is the quote's text (corpus 646).
                $f = $this->frames[$frame];
                $plus = !($f->kind === 'quote' && $this->m['col'] > 0)
                    && $this->attachesAt($f->kind, $this->at, (int)$f->docMarker, $frame);
                if ($plus) {
                    $f->plus = $this->at;
                    $this->skipInvisible($frame, $this->at, (int)$f->docMarker);
                    $this->holdAttachedTo($frame, $this->skipped['end'] ?? $this->at + 1);
                } elseif (($f->kind === 'quote' && $this->m['col'] > 0) || preg_match('/(?:^|[ \t>])(?:[-*]|\d+[.)]) *\t/', $this->out) === 1) {
                    // CARVE-P2-010: behind an item marker's tab the line is text, so its `+` stays a letter.
                    $this->refusePlus($this->at, null);
                } else {
                    // A refused marker is consumed and renders nothing (carve#5013).
                    $this->refusedPlus[$this->at] = $this->plusFinding($this->at, $f->kind, (int)$f->docMarker, $frame);
                    $this->consumePlus();
                    $this->skipInvisible($frame, $this->at, (int)$f->docMarker, true);
                    $next = $this->lines[$this->at + 1] ?? '';
                    $attribute = $this->throughQuotes($next, $frame, $this->frames);
                    if ($this->trace !== null && $attribute !== null && ($attribute['col'] ?? null) === $f->markerCol && ($this->isAttributeLine)($attribute['rest'])) {
                        $this->refusedAttributeHost = $frame;
                    }
                    $this->standRefusedSpan($frame);
                    $consumed = true;
                }
            }
            $this->lead($this->m['col'], contentTab: true);
            if ($plus) {
                $this->plusHosts[$this->at] = $this->hostRecord($frame, self::columnOf($this->out), true);
            }
            // A consumed item marker leaves no paragraph a lazy line continues; past a note the note folds it (PART 0 S4).
            $this->frames[$frame]->para = !$plus && !$losing && !($consumed && $this->consumedLeavesNoParagraph($frame));
            if ($this->frames[$frame]->para) {
                $this->frames[$frame]->paragraphStart = $this->at;
                $this->frames[$frame]->paragraphSpanNext = $this->at;
                $this->frames[$frame]->paragraphSpanState = ['scanned' => 0, 'code' => 0, 'comment' => false];
            }
            $this->frames[$frame]->last = 'para';

            return;
        }
        if ($this->frames[$frame]->kind === 'quote') {
            $col = $this->m['col'];
            $this->noteOpener($frame, $form, 0);
            $this->lead(0);
            $this->apply($frame, $col, $form, 0, inHost: true);

            return;
        }
        $col = $start + $this->m['col'];
        $shift = $this->m['col'];
        $this->lead($shift);
        $f = $this->frames[$frame];
        $this->apply($frame, $col, $form, $canon + $shift, $f->kind === 'note' ? $f->reader : $f->reader + $canon + $shift - $f->canon);
    }

    /**
     * An opener past a non-item owner's content column. List items and markers have their own lint rules.
     */
    private function noteOpener(int $owner, OwnershipForm $form, int $contentColumn): void
    {
        $frame = $this->frames[$owner];
        if ($frame->kind === 'item' || $frame->kind === 'fence' || $this->m['col'] <= $contentColumn) {
            return;
        }
        $rest = $this->m['rest'];
        $kind = match ($form->kind) {
            'quote' => 'block quote',
            'colon' => 'colon fence',
            'fence' => $form->family === 'comment' ? null : ($form->lineBlock ? 'line block' : 'code fence'),
            'term' => 'definition term',
            // A spaced break reads as a marker here, but renders as a thematic break (carve#5003).
            'item' => preg_match(self::THEMATIC_BREAK, $rest) === 1 ? 'thematic break' : null,
            'text' => $form->breakLine ? 'thematic break' : null,
            'leaf' => match (true) {
                preg_match('/^#{1,6} +(?=.*[^ \t\n\r])/', $rest) === 1 => 'heading',
                preg_match(self::THEMATIC_BREAK, $rest) === 1 => 'thematic break',
                $form->table && $rest[0] === '|' => 'table row',
                default => null,
            },
            default => null,
        };
        // A table reports once, on its first row.
        if ($kind === null || ($kind === 'table row' && $frame->tableLine === $this->at - 1)) {
            return;
        }
        $line = $this->lines[$this->at];
        $this->overindented[] = [
            'line' => $this->at + 1,
            'column' => mb_strlen(substr($line, 0, strlen($line) - strlen($rest)), 'UTF-8') + 1,
            'kind' => $kind,
            'owner' => $frame->kind,
            'contentColumn' => $contentColumn,
            'authoredColumn' => $this->m['col'],
        ];
    }

    private function noteShiftedOpener(int $at, int $shift): void
    {
        if ($shift !== 0) {
            $this->shiftedOpeners[$at] = true;
        }
    }

    /**
     * A quoted description's tab can reach its parent body while putting the
     * separator past the term's column in that body's local coordinates.
     */
    private function recordPastColumnDescriptionLine(int $at, int $segment, int $shift, int $owner, OwnershipForm $form): void
    {
        if (
            $segment > 1 && $shift > 0 && $form->kind === 'dd' && $this->frames[$owner]->kind === 'dd'
            && $this->frames[$owner]->last === 'term'
            && str_contains(substr($this->src, 0, strlen($this->src) - strlen($this->m['rest'])), "\t")
        ) {
            $this->pastColumnDescriptionLines[$at] = true;
        }
    }

    /**
     * A bare `:::` kept on its tab run would reach a nested reader `$shift`
     * columns right, where it can pair with an earlier opener's closer.
     */
    private function keepsOpenerTabRun(OwnershipForm $form, int $shift): bool
    {
        return ($form->kind === 'fence' && ($form->family !== 'code' || $shift === 0))
            || ($form->kind === 'colon' && ($form->lineBlock || $shift === 0));
    }

    private function recordLineBlockOpener(OwnershipForm $form, int $owner, int $shift, bool $tabbed): void
    {
        if (!$form->lineBlock) {
            return;
        }
        $frame = $this->frames[count($this->frames) - 1];
        // A kept tab run is measured by the reader itself; a rewritten one stands at the owner's column.
        $frame->openerFrameLead = $this->leadRewrote ? $this->m['col'] + $shift - $this->frames[$owner]->canon : 0;
        $frame->openerTabbed = $tabbed;
    }

    private function recordRewrittenOpener(OwnershipForm $form, int $shift): void
    {
        if (($form->kind === 'fence' || $form->kind === 'colon') && $this->leadRewrote) {
            $this->frames[count($this->frames) - 1]->openerTabShift = $shift;
        }
    }

    private function leadPayload(OwnershipFrame $inner, bool $shiftedCloser, int $segment): void
    {
        $payloadColumn = max($this->m['col'] - $inner->cut, $inner->canon);
        $frameColumn = $this->strippedColumn($this->src, $segment, $this->m);
        $authored = substr($this->src, 0, strlen($this->src) - strlen($this->m['rest']));
        if ($inner->lineBlock && !$shiftedCloser && ($inner->openerTabbed || str_contains($authored, "\t"))) {
            // A verse lead is its per-frame column less the opener's; the opener is no frame (§23, carve#5885).
            $target = max($frameColumn - ($inner->openerFrameLead ?? $inner->cut), $inner->canon);
            $tabbed = str_contains($authored, "\t");
            $from = $tabbed ? $frameColumn : $this->m['col'];
            $col = min($target, $from);
            // A tab run that already lands where the frames put it keeps its authored spelling.
            $keep = $col === $this->m['col'] && $frameColumn === $this->m['col'];
            $this->out .= $keep ? $authored : str_repeat(' ', $col);
            if (!$keep && $tabbed) {
                $this->respelledVerseLines[$this->at] = true;
            }
            $this->src = $this->m['rest'];

            return;
        }
        if ($frameColumn !== $this->m['col'] && ($inner->family !== 'code' || ($inner->openerTabShift ?? 0) > 0)) {
            // A structural tab can land at a different column after an enclosing
            // frame strips its prefix. Give the payload reader that frame's
            // space spelling; tabs in code content itself remain untouched.
            $this->out .= str_repeat(' ', $shiftedCloser ? $inner->col : $payloadColumn);
            $this->src = $this->m['rest'];

            return;
        }
        if (!$this->rewritesTabbedPayload($inner, $shiftedCloser, $segment, $payloadColumn)) {
            $this->lead($shiftedCloser ? $inner->col : $payloadColumn);

            return;
        }
        // The rewritten opener gives the reader no authored run to re-measure this one against (§24 C5).
        $this->out .= str_repeat(' ', $payloadColumn);
        $this->src = $this->m['rest'];
    }

    /**
     * A tabbed payload line under an opener the walk rewrote goes out as spaces
     * too, when the walk column and the per-frame column agree on it.
     */
    private function rewritesTabbedPayload(OwnershipFrame $inner, bool $shiftedCloser, int $segment, int $payloadColumn): bool
    {
        if ($inner->family === 'code' && $inner->opened !== null) {
            $opener = $this->lines[$inner->opened];
            $openerLead = substr($opener, 0, strspn($opener, " \t"));
            $payloadLead = substr($this->src, 0, strlen($this->src) - strlen($this->m['rest']));
            if (
                $openerLead !== '' && str_starts_with($payloadLead, $openerLead)
                && str_contains(substr($payloadLead, strlen($openerLead)), "\t")
            ) {
                // Tabs after the opener's structural run belong to literal code.
                return false;
            }
        }
        if (
            $shiftedCloser || $inner->openerTabShift === null || $inner->family === 'comment'
            || !str_contains(substr($this->src, 0, strlen($this->src) - strlen($this->m['rest'])), "\t")
            || preg_match('/^(?:`{3,}|~{3,}|:{3,}|%{3,})[ \t]*$/D', $this->m['rest']) === 1
        ) {
            return false;
        }
        $frameShift = $this->strippedColumn($this->src, $segment, $this->m) - $this->m['col'];

        return max($this->m['col'] + $frameShift - $inner->openerTabShift - $inner->cut, $inner->canon) === $payloadColumn;
    }

    private function lead(int $target, bool $spaces = false, bool $contentTab = false, bool $definitionMarker = false): void
    {
        $authored = substr($this->src, 0, strlen($this->src) - strlen($this->m['rest']));
        // The block readers peel each reached frame before measuring the next
        // tab. Keep an authored run only when that second reading lands at the
        // same column the ownership walk handed over (PART 0 S2, §24 C5).
        $hasTab = str_contains($authored, "\t");
        $segment = 1;
        $insideVerbatimBlock = false;
        if ($hasTab && !$contentTab) {
            for ($index = count($this->frames) - 1; $index > 0; $index--) {
                $frame = $this->frames[$index];
                if ($frame->kind === 'fence') {
                    $insideVerbatimBlock = true;

                    continue;
                }
                if ($frame->kind === 'quote') {
                    $segment = $index + 1;

                    break;
                }
            }
        }
        $frameLocalTab = $hasTab && count($this->frames) > $segment + 1 && !$insideVerbatimBlock && !$contentTab
            && (!$definitionMarker || $this->strippedColumn($this->src, $segment, $this->m) === $this->m['col']);
        $col = $frameLocalTab ? $target : min($target, $this->m['col']);
        $keep = $col === $this->m['col'] && !$frameLocalTab && !($spaces && $this->out === '' && $hasTab);
        $this->leadRewrote = !$keep;
        $this->out .= $keep ? $authored : str_repeat(' ', $col);
        $this->src = $this->m['rest'];
    }

    private function take(int $count): void
    {
        $this->out .= substr($this->src, 0, $count);
        $this->src = (string)substr($this->src, $count);
    }

    /**
     * The authored brace column for a marker-line run, with the note exception.
     */
    private function braceRunColumn(string $opener, string $rest, OwnershipFrame $owner): int
    {
        if (!$this->onMarkerLine || $owner->kind === 'quote') {
            return IndentationHelper::getLeadingColumns($opener);
        }
        if ($owner->kind === 'note') {
            return (int)$owner->markerCol + 2;
        }

        // Marker-glued attributes leave the content column alone but occupy
        // written columns before the brace that opens the body run.
        $brace = strrpos($opener, $rest);

        return $brace === false ? $owner->col : WrappedBlockAttributeScanner::columnAt($opener, $brace);
    }

    /**
     * A wrapped attribute block is one line to its owner (A5, carve#4089), so its
     * later lines stay with the opener whatever their column. A later line without
     * the owner's quote markers does not continue it (PART 4).
     */
    private function opensWrappedAttribute(string $rest, OwnershipFrame $owner): bool
    {
        // A `%` payload is never an attribute (WrappedBlockAttributeScanner::validPayload), so a `{%` run needs no scan.
        if (str_starts_with($rest, '{%')) {
            return false;
        }
        $depth = 0;
        foreach ($this->frames as $frame) {
            $depth += $frame->kind === 'quote' ? 1 : 0;
            if ($frame === $owner) {
                break;
            }
        }
        $opener = $this->lines[$this->at];
        for ($d = 0; $d < $depth; $d++) {
            $opener = preg_match(self::QUOTE, ltrim($opener, " \t"), $q) === 1 ? ($q[1] ?? '') : $opener;
        }
        $run = [$rest];
        $count = count($this->lines);
        for ($j = $this->at + 1; $j < $count; $j++) {
            $line = $this->lines[$j];
            for ($d = 0; $d < $depth; $d++) {
                if (preg_match(self::QUOTE, ltrim($line, " \t"), $q) !== 1) {
                    return false;
                }
                $line = $q[1] ?? '';
            }
            $run[] = $line;
            if (!WrappedBlockAttributeScanner::endsInsideBlock($line, true)) {
                break;
            }
            if (trim($line, " \t") === '' || ltrim($line, " \t")[0] === '{') {
                return false;
            }
        }
        $length = count($run);
        // The written brace column decides a marker-line run, except in notes.
        $openerColumn = $this->braceRunColumn($opener, $rest, $owner);
        if ($length < 2 || ($this->wrappedAttributeLength)($run, $openerColumn) !== $length) {
            return false;
        }
        $this->wrappedAttributeEnd = $this->at + $length - 1;
        // The item content column remains the rebasing origin even when bytes
        // before the brace move the acceptance column to the right.
        $this->wrappedAttributeColumn = $this->onMarkerLine && $owner->kind !== 'note' && $owner->kind !== 'quote'
            ? $owner->col : $openerColumn;

        return true;
    }

    /**
     * A block `{%` run whose continuations all pass its `{` holds its later lines
     * through the closer, whatever block they spell (CARVE-P2-035, PART 9 §21a).
     */
    private function opensHeldComment(string $rest, OwnershipFrame $owner): bool
    {
        if (!str_starts_with($rest, '{%') || str_contains($rest, '%}') || $this->at <= $this->wrappedAttributeEnd) {
            return false;
        }
        $depth = 0;
        foreach ($this->frames as $frame) {
            $depth += $frame->kind === 'quote' ? 1 : 0;
            if ($frame === $owner) {
                break;
            }
        }
        $opener = $this->lines[$this->at];
        for ($d = 0; $d < $depth; $d++) {
            if (preg_match(self::QUOTE, ltrim($opener, " \t"), $q) !== 1) {
                return false;
            }
            $opener = $q[1] ?? '';
        }
        $openerColumn = $this->braceRunColumn($opener, $rest, $owner);
        $count = count($this->lines);
        for ($j = $this->at + 1; $j < $count; $j++) {
            $line = $this->lines[$j];
            for ($d = 0; $d < $depth; $d++) {
                if (preg_match(self::QUOTE, ltrim($line, " \t"), $q) !== 1) {
                    return false;
                }
                $line = $q[1] ?? '';
            }
            if (trim($line, " \t") === '' || IndentationHelper::getLeadingColumns($line) <= $openerColumn) {
                return false;
            }
            if (str_contains($line, '%}')) {
                $this->wrappedAttributeEnd = $j;
                $this->wrappedAttributeColumn = $this->onMarkerLine && $owner->kind !== 'note' && $owner->kind !== 'quote'
                    ? $owner->col : $openerColumn;
                for ($k = $this->at + 1; $k <= $j; $k++) {
                    $this->heldCommentLines[$k] = $this->at;
                }

                return true;
            }
        }

        return false;
    }

    private function classifyLine(string $rest, OwnershipFrame $owner, int $col): OwnershipForm
    {
        $form = $this->keptByRefusedRun($rest, $owner, $col) ?? $this->classify($rest, $owner, $col);
        if ($form->kind === 'text' && !$form->kept && $rest[0] === '{') {
            $this->opensHeldComment($rest, $owner);
        }

        return $form;
    }

    private function openParagraph(OwnershipFrame $frame, int $at, bool $new): void
    {
        $frame->para = true;
        if ($new) {
            $frame->paragraphStart = $at;
            $frame->paragraphSpanNext = $at;
            $frame->paragraphSpanState = ['scanned' => 0, 'code' => 0, 'comment' => false];
        }
    }

    /**
     * A refused brace run and the line its paragraph keeps are text (CARVE-P2-035).
     */
    private function keptByRefusedRun(string $rest, OwnershipFrame $owner, int $col): ?OwnershipForm
    {
        if ($this->refusedRunKeptThrough === null || $rest === '') {
            return null;
        }
        $at = $this->at;
        // A refused run written lazily reaches the note paragraph through S4.
        // Its source owner is the document, but its single protected follower
        // still belongs to the note.
        if (
            $at > $this->refusedKeptStart && $this->refusedRunMissesItsContainer($this->refusedKeptStart)
            && $this->refusedKeptLazyNoteHost === null
        ) {
            $this->refusedKeptThrough = -1;
        }
        // A block that misses the run's container, or a sibling description marker, ends its paragraph (PART 0 S2).
        // A lazy text line there is read as usual and keeps the follower protected; a note definition is that follower.
        if ($at > $this->refusedKeptStart && $at <= $this->refusedKeptThrough) {
            $missed = ($owner !== $this->refusedKeptOwner && $owner !== $this->refusedKeptLazyNoteHost)
                || $col < $this->refusedKeptColumn;
            $form = $missed || $rest[0] === ':' ? $this->classify($rest, $owner, $col) : null;
            if ($missed && $form?->kind === 'note') {
                $this->refusedKeptLines[$at] = $this->refusedKeptStart;

                return new OwnershipForm(kind: 'text', interrupts: false, kept: true);
            }
            if ($missed && $form?->kind === 'text') {
                return null;
            }
            if ($missed || $form?->kind === 'dd') {
                $this->refusedKeptThrough = $at - 1;
                // A run whose first continuation is that block was never a run: its line is read as usual.
                if ($at - 1 === $this->refusedKeptStart) {
                    unset($this->refusedKeptLines[$this->refusedKeptStart]);
                }
            }
        }
        if (
            $at > $this->refusedKeptStart && $at <= $this->refusedKeptThrough
            && (!$this->invisibleSibling($rest) || $this->insideRefusedFencePayload($at))
        ) {
            $this->refusedKeptLines[$at] = $this->refusedKeptStart;

            return new OwnershipForm(kind: 'text', interrupts: false, kept: true);
        }
        if ($rest[0] !== '{') {
            return null;
        }
        if ($at === $this->refusedKeptStart) {
            return $at <= $this->refusedKeptThrough ? new OwnershipForm(kind: 'text', interrupts: false, kept: true) : null;
        }
        if ($owner->para && $owner->paragraphStart !== null && $this->inlineSpanOpen !== null) {
            $from = $owner->paragraphSpanNext ?? $owner->paragraphStart;
            $parts = array_map(
                static fn (string $line): string => ltrim($line, " \t"),
                array_slice($this->lines, $from, $at - $from),
            );
            $open = ($this->inlineSpanOpen)($parts, $owner->paragraphSpanState);
            $owner->paragraphSpanNext = $at;
            if ($open) {
                return new OwnershipForm(kind: 'text', interrupts: false);
            }
        }
        $lookahead = $this->commentRunLookaheads[$at] ?? null;
        if (RefusedBraceRun::endsOnItsLine($rest)) {
            return null;
        }
        $depth = 0;
        foreach ($this->frames as $index => $frame) {
            if ($index > 0 && $frame->kind === 'quote') {
                $depth++;
            }
            if ($frame === $owner) {
                break;
            }
        }
        $top = $this->frames[count($this->frames) - 1];
        $lazyNoteParagraph = $top->kind === 'note' && $top->para && $owner !== $top && $col < $top->col;
        // A first-block marker sits on the lead line, not in the frame's plus slot.
        $firstBlockLead = $top->line === $at - 1
            && preg_match('/^(?:[ \t]*:[ \t]+|[ \t]*\[\^[^]]+\]:[ \t]+)\+[ \t]*$/D', $this->lines[$top->line] ?? '') === 1;
        // A run on a description or note marker line is measured in its body's coordinates by that body's reader.
        if (($top->kind === 'dd' || $top->kind === 'note') && $top->line === $at) {
            return null;
        }
        $column = in_array($top->kind, ['doc', 'quote'], true) || $top->plus === $at - 1 ? $col : $top->col;
        $writtenColumn = $col;
        $brace = strpos($this->lines[$at], '{');
        if ($depth > 0 && $brace !== false) {
            $writtenColumn = WrappedBlockAttributeScanner::columnAt($this->lines[$at], $brace);
        }
        // Decide the first continuation in the same coordinates as the probe
        // below. A quote marker has column zero in the raw line, but its
        // content can still be deeper than the opening brace. In that case a
        // closerless paragraph needs one shared suffix scan, not one probe per
        // opener.
        if (str_starts_with($rest, '{%') && $lookahead === null) {
            $next = $this->lines[$at + 1] ?? null;
            $nextContent = $next === null ? null : BlockGrammar::quotedContentAtDepth($next, $depth);
            $firstContinuationPasses = $nextContent !== null && trim($nextContent, " \t") !== ''
                && !str_starts_with($next, BlockGrammar::LAZY_FRAME)
                && IndentationHelper::getLeadingColumns($nextContent)
                    + max(0, $writtenColumn - $col) > $writtenColumn;
            if (
                ($next === null || trim($next, " \t") === '' || $firstContinuationPasses)
                && !RefusedBraceRun::closerBeforeBlank($this->lines, $at)
            ) {
                return null;
            }
        }
        if (
            $lookahead !== null && str_starts_with($rest, '{%')
            && $lookahead['owner'] === $owner && $lookahead['top'] === $top
            && $lookahead['col'] === $col && $lookahead['column'] === $column && $lookahead['depth'] === $depth
            && !$lazyNoteParagraph && !$firstBlockLead
        ) {
            $kept = $lookahead['through'] - $at;
            $window = [];
        } else {
            // The window doubles only while the answer reaches its edge, so a run of one-line attributes stays linear.
            $window = [str_repeat(' ', $writtenColumn) . $rest];
            $count = count($this->lines);
            $j = $at + 1;
            $ended = false;
            for ($size = 16;; $size *= 2) {
                for (; $j < $count && $j - $at < $size && !$ended; $j++) {
                    $line = $this->lines[$j];
                    $prefixed = true;
                    for ($d = 0; $d < $depth; $d++) {
                        if (preg_match(self::QUOTE, ltrim($line, " \t"), $q) !== 1) {
                            $prefixed = false;
                            $line = $this->lines[$j];

                            break;
                        }
                        $line = $q[1] ?? '';
                    }
                    if ($prefixed && $depth > 0) {
                        $line = str_repeat(' ', max(0, $writtenColumn - $col)) . $line;
                    }
                    $window[] = $line;
                    $ended = trim($line, " \t") === '';
                }
                $kept = ($this->refusedRunKeptThrough)($window, 0, $column, !$ended && $j < $count, $top->kind === 'dd');
                if ($kept !== -1) {
                    break;
                }
            }
            if (str_starts_with($rest, '{%') && $kept !== null && $kept >= 0 && !$lazyNoteParagraph && !$firstBlockLead) {
                $this->rememberCommentRunLookaheads($window, $at, $owner, $top, $col, $column, $depth, $kept);
            }
        }
        if ($kept === null) {
            return null;
        }
        if ($lazyNoteParagraph) {
            $follower = ltrim($this->lines[$at + $kept] ?? '', " \t");
            if ($this->fenced->parseCodeFenceOpener($follower) !== null || $this->fenced->parseRawBlockOpener($follower) !== null) {
                // A fence-shaped follower has its own span boundary. The note
                // reader stops that span before a later column-zero block.
                $lazyNoteParagraph = false;
            }
        }
        if ($firstBlockLead && $top->plus !== $at - 1) {
            $runEnd = $this->refusedBraceRunEnd !== null ? ($this->refusedBraceRunEnd)($window, 0) : null;
            $protectedCloser = $runEnd !== null && $kept > $runEnd;
            $next = $at + $kept + 1;
            $nextLine = $this->lines[$next] ?? '';
            $nextColumn = IndentationHelper::getLeadingColumns($nextLine);
            $nextRest = ltrim($nextLine, " \t");
            // Otherwise the body reader already handles this run; avoid
            // changing how it escapes ordinary paragraph text.
            if (
                !$protectedCloser && ($kept < 0 || $nextRest === '' || $nextColumn <= $top->col
                    || $this->classify($nextRest, $top, $nextColumn)->kind === 'text')
            ) {
                return null;
            }
        }
        $this->refusedKeptStart = $at;
        // Refusal protects only the literal opener. Later physical lines are
        // read by their ordinary container and interruption rules.
        $this->refusedKeptThrough = $at;
        $this->refusedKeptOwner = $owner;
        if (isset($this->itemBoundaryLines[$at]) && $owner->line === $at) {
            $this->runsPastItemBoundary[$at] = true;
        }
        $this->refusedKeptLazyNoteHost = $lazyNoteParagraph ? $top : null;
        $this->refusedKeptColumn = $column;
        $this->refusedKeptLines[$at] = $at;

        return new OwnershipForm(kind: 'text', interrupts: false, kept: true);
    }

    /**
     * A run that misses its container, as a lazy line or by closing it, keeps nothing.
     *
     * A marker line that only ends a sibling item of another list closes nothing of the item it opens for the run.
     */
    private function refusedRunMissesItsContainer(int $start): bool
    {
        return isset($this->closingLines[$start]) && !isset($this->runsPastItemBoundary[$start]);
    }

    private function markLazyBraceOpener(int $at, int $reached): void
    {
        $rest = ltrim($this->lines[$at], " \t");
        if (!str_starts_with($rest, '{') || RefusedBraceRun::endsOnItsLine($rest)) {
            return;
        }
        if (!$this->lazyRunMissesFrame($at, str_starts_with($rest, '{%') ? count($this->frames) - 1 : $reached)) {
            $this->lazyRunsHeld[$at] = true;

            return;
        }
        $this->refusedKeptLines[$at] = $at;
        $column = IndentationHelper::getLeadingColumns($this->lines[$at]);
        foreach ($this->frames as $frame) {
            if (
                $frame->kind !== 'doc'
                && ($frame->kind !== 'quote' || str_starts_with(ltrim($this->lines[$at], " \t"), '>'))
                && $column >= $frame->col
            ) {
                $this->lazyBraceReachesContainer[$at] = true;

                break;
            }
        }
    }

    /**
     * A multi-line brace run takes no lazy lines (carve#5830 rule B): each
     * continuation must carry its quotes and reach frame `$deeper`'s column.
     */
    private function lazyRunMissesFrame(int $at, int $deeper): bool
    {
        $comment = str_starts_with(ltrim($this->lines[$at], " \t"), '{%');
        $depth = 0;
        for ($k = 1; $k <= $deeper; $k++) {
            if ($this->frames[$k]->kind === 'quote') {
                $depth++;
            }
        }
        $column = $this->frames[$deeper]->col;
        for ($j = $at + 1, $n = count($this->lines); $j < $n && trim($this->lines[$j], " \t") !== ''; $j++) {
            $stands = BlockGrammar::quotedContentAtDepth($this->lines[$j], $depth);
            if ($stands === null || IndentationHelper::getLeadingColumns($stands) < $column) {
                return true;
            }
            if (str_contains($stands, $comment ? '%}' : '}')) {
                break;
            }
        }

        return false;
    }

    /**
     * A comment opener's completed probe also decides later openers before its closer.
     * A later opener is refused only when its own suffix has a shallow or lazy
     * continuation; the final opener can therefore still be a held run.
     *
     * @param array<string> $window
     * @param int $at
     * @param \MarkupCarve\Carve\Parser\OwnershipFrame $owner
     * @param \MarkupCarve\Carve\Parser\OwnershipFrame $top
     * @param int $col
     * @param int $column
     * @param int $depth
     * @param int $kept
     */
    private function rememberCommentRunLookaheads(
        array $window,
        int $at,
        OwnershipFrame $owner,
        OwnershipFrame $top,
        int $col,
        int $column,
        int $depth,
        int $kept,
    ): void {
        $closer = null;
        foreach ($window as $index => $line) {
            if (str_contains($line, '%}')) {
                $closer = $index;

                break;
            }
        }
        if ($closer === null) {
            return;
        }
        $openerColumn = IndentationHelper::getLeadingColumns($window[0]);
        $shallowest = PHP_INT_MAX;
        $lazy = false;
        for ($index = $closer; $index > 0; $index--) {
            $line = $window[$index];
            $content = BlockGrammar::stripLazyFrame($line);
            $lineColumn = IndentationHelper::getLeadingColumns($content);
            if (
                $index < $closer && $lineColumn === $openerColumn && str_starts_with(ltrim($content, " \t"), '{%')
                && !str_contains($content, '%}') && ($shallowest <= $openerColumn || $lazy)
            ) {
                $this->commentRunLookaheads[$at + $index] = [
                    'owner' => $owner,
                    'top' => $top,
                    'col' => $col,
                    'column' => $column,
                    'depth' => $depth,
                    'through' => $at + $kept,
                ];
            }
            $shallowest = min($shallowest, $lineColumn);
            $lazy = $lazy || $content !== $line;
        }
    }

    private function insideRefusedFencePayload(int $at): bool
    {
        for ($line = $this->refusedKeptStart + 1; $line < $at; $line++) {
            $rest = ltrim($this->lines[$line] ?? '', " \t");
            if (
                preg_match('/^(`{3,}|~{3,})/', $rest) === 1
                && $this->fenced->parseCodeFenceOpener($rest) !== null
            ) {
                return true;
            }
        }

        return false;
    }

    private function invisibleSibling(string $rest): bool
    {
        return str_starts_with($rest, '%%') || (!str_starts_with($rest, '[^') && ($this->isDefinition)($rest));
    }

    private function classify(string $rest, OwnershipFrame $owner, int $col): OwnershipForm
    {
        if ($rest === '') {
            return self::$blankForm ??= new OwnershipForm(kind: 'blank', interrupts: true);
        }
        // Each pattern below needs its own first byte; plain prose skips them all.
        $first = $rest[0];
        if ($first === '>' && preg_match(self::QUOTE, $rest) === 1) {
            return new OwnershipForm(kind: 'quote', interrupts: true);
        }
        if ($first === ':' && preg_match('/^(:{3,})(.*)$/D', $rest, $colon) === 1) {
            if (preg_match('/^(:{3,})[ \t]*$/D', $rest) === 1) {
                for ($k = $this->reach; $k > 0 && $this->frames[$k]->kind === 'colon'; $k--) {
                    if ($this->frames[$k]->run === strlen($colon[1])) {
                        return new OwnershipForm(kind: 'colon-close', frame: $k, interrupts: true);
                    }
                }
            }
            $opener = $this->fenced->parseDivFenceOpener($rest);
            if ($opener !== null) {
                $run = strlen($colon[1]);
                if ($opener['className'] === '|') {
                    return new OwnershipForm(
                        kind: 'fence',
                        interrupts: true,
                        lineBlock: true,
                        family: 'div',
                        closes: static fn (string $r, int $closerCol): bool => $closerCol <= $col
                            && preg_match('/^(:{3,})[ \t]*$/D', $r, $c) === 1 && strlen($c[1]) === $run,
                    );
                }

                return new OwnershipForm(kind: 'colon', run: $run, interrupts: true);
            }
        }
        if (
            ($first === '`' || $first === '~') && preg_match('/^(`{3,}|~{3,})(.*)$/D', $rest, $fence) === 1
            && ($this->fenced->parseCodeFenceOpener($rest) !== null || $this->fenced->parseRawBlockOpener($rest) !== null)
        ) {
            $run = $fence[1];
            // A run indented past its opener is code content (CARVE-P2-006).
            $closes = static function (string $r, int $closerCol) use ($run, $col): bool {
                return $closerCol <= $col && preg_match('/^(`{3,}|~{3,})[ \t]*$/D', $r, $c) === 1
                    && $c[1][0] === $run[0] && strlen($c[1]) >= strlen($run);
            };

            return new OwnershipForm(kind: 'fence', interrupts: true, closes: $closes, family: 'code');
        }
        if ($first === '%' && preg_match('/^(%{3,})(.*)$/D', $rest, $comment) === 1) {
            $run = strlen($comment[1]);

            return new OwnershipForm(
                kind: 'fence',
                interrupts: true,
                closes: static fn (string $r, int $closerCol): bool => preg_match('/^(%{3,})/', $r, $c) === 1 && strlen($c[1]) === $run,
                family: 'comment',
            );
        }
        if (str_starts_with($rest, '%%')) {
            return new OwnershipForm(kind: 'leaf', interrupts: true);
        }
        if ($first === '[' && preg_match('/^\[\^[^\]]+\]: +/', $rest, $note) === 1 && preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $rest) === 1) {
            return new OwnershipForm(kind: 'note', width: 2, head: strlen($note[0]), interrupts: true);
        }
        // T6: a continuation row right under a row of the owner's table is that table's line (carve#4161).
        if (
            ($first === '|' && $this->tables->isTableRow($rest))
            || ($first === '+' && $owner->tableLine === $this->at - 1 && $this->tables->isContinuationRow($rest))
        ) {
            return new OwnershipForm(kind: 'leaf', interrupts: true, table: true);
        }
        $marker = $this->listParser->parseListItemMarker($rest);
        if ($marker !== null) {
            // A term is not a paragraph a marker continues.
            if ($owner->para && !in_array($owner->kind, self::BODY_FRAMES, true)) {
                return new OwnershipForm(kind: 'text', interrupts: false, breakLine: preg_match(self::THEMATIC_BREAK, $rest) === 1);
            }
            $head = $this->listParser->markerHeadAt($rest);
            if ($head !== null) {
                $width = $head['content'] - $head['attrs'];
                // The checkbox contributes zero; the authored gap after the bullet counts (CARVE-P9-083).
                if (isset($marker['taskMarker'])) {
                    $bullet = strlen((string)$marker['marker']);
                    $width = $bullet + max(1, strspn($rest, " \t", $bullet + $head['attrs']));
                }

                return new OwnershipForm(
                    kind: 'item',
                    width: $width,
                    head: $head['content'],
                    interrupts: false,
                    task: isset($marker['taskMarker']),
                    axes: $marker['type'] === 'ordered'
                        ? substr((string)$marker['marker'], -1)
                    : substr((string)$marker['marker'], 0, 1),
                );
            }
        }
        if ($first === ':' && preg_match(BlockGrammar::DEFINITION_TERM_LINE_PATTERN, $rest) === 1) {
            return new OwnershipForm(kind: 'term', interrupts: true);
        }
        if ($first === ':' && preg_match('/^:( +)(?=[ \t]*[^ \t])/', $rest, $dd) === 1 && ($owner->last === 'term' || $owner->last === 'dd' || $owner === $this->ddSiblingOwner)) {
            $w = 1 + strlen($dd[1]);

            return new OwnershipForm(kind: 'dd', width: $w, head: $w, interrupts: false);
        }
        if (
            ($first === '#' && preg_match('/^(#{1,6}) +(?=.*[^ \t\n\r])(.*)$/D', $rest) === 1)
            || (($first === '-' || $first === '*' || $first === '_') && preg_match(self::THEMATIC_BREAK, $rest) === 1)
            // A letter-led line is never a definition, as in BlockParser's prose dispatch.
            || (!ctype_alpha($first) && ($this->isDefinition)($rest))
            || ($first === '*' && preg_match('/^\*\[([A-Za-z0-9]+)\]: +(?![ \t]*$)([^ ].*)$/D', $rest) === 1)
            || ($first === '{' && (($this->isAttributeLine)($rest) || $this->opensWrappedAttribute($rest, $owner)))
        ) {
            $this->softLeaf = $owner->kind === 'doc' && $owner->para && str_starts_with($rest, '*[')
                && preg_match('/^\*\[([A-Za-z0-9]+)\]: +(?![ \t]*$)([^ ].*)$/D', $rest) === 1;

            return new OwnershipForm(kind: 'leaf', interrupts: true);
        }
        if ($first === '^' && preg_match('/^\^ +(?=.*[^ \t\n\r\f])(.*?)[ \t]*$/D', $rest) === 1) {
            // A caret line with no host above it is paragraph text (PART 9 §10 I7).
            if ($owner->last === null && $owner->kind !== 'dd') {
                return new OwnershipForm(kind: 'text', interrupts: false);
            }
            $this->softLeaf = in_array($owner->last, [null, 'para', 'term'], true);

            return new OwnershipForm(kind: 'leaf', interrupts: false, caption: true);
        }

        return self::$textForm ??= new OwnershipForm(kind: 'text', interrupts: false);
    }

    /**
     * A missed comment owner closes the reader's run only while a closer is still in it.
     *
     * Only a quote's search stops at a blank line (carve corpus 930-4).
     */
    private function commentCloserBeforeBlank(int $from, OwnershipFrame $fence, bool $stopAtBlank = true): bool
    {
        if ($fence->opened === null || preg_match('/(%{3,})/', $this->lines[$fence->opened], $opener) !== 1) {
            return false;
        }
        $length = strlen($opener[1]);
        $runs = $this->closerRuns();
        $positions = $runs['commentPositions'][$length] ?? [];
        $lo = 0;
        $hi = count($positions);
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($positions[$mid] <= $from) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        return isset($positions[$lo]) && (!$stopAtBlank || $positions[$lo] < ($runs['nextBlank'][$from + 1] ?? count($this->lines)));
    }

    /**
     * A verse run pairs with a later run of its kind; in a quote only before the first line that leaves it (carve#5062).
     */
    private function verseRunPairs(int $at, string $run): bool
    {
        $runs = $this->closerRuns();
        if (($runs['widest'][$run[0]][$at + 1] ?? 0) < strlen($run)) {
            return false;
        }
        $depth = 0;
        foreach ($this->frames as $frame) {
            $depth += (int)($frame->kind === 'quote');
        }
        if ($depth === 0) {
            return true;
        }
        $key = $run[0] . $depth;
        $miss = $this->verseRunMisses[$key] ?? null;
        if ($miss !== null && $at >= $miss[0] && $at < $miss[1] && strlen($run) > $miss[2]) {
            return false;
        }
        $widest = 0;
        for ($j = $at + 1, $n = count($this->lines); $j < $n && $runs['depths'][$j] >= $depth; $j++) {
            $found = $runs['fenceRuns'][$j] ?? '';
            if ($found !== '' && $found[0] === $run[0]) {
                if (strlen($found) >= strlen($run)) {
                    return true;
                }
                $widest = max($widest, strlen($found));
            }
        }
        $this->verseRunMisses[$key] = [$at, $j, $widest];

        return false;
    }

    /**
     * @return array{widest: array<string, array<int, int>>, depths: array<int, int>, fenceRuns: array<int, string>, lastComment: array<int, int>, commentPositions: array<int, list<int>>, nextBlank: array<int, int>}
     */
    private function closerRuns(): array
    {
        if ($this->runs !== null) {
            return $this->runs;
        }
        $n = count($this->lines);
        $widest = ['`' => array_fill(0, $n + 1, 0), '~' => array_fill(0, $n + 1, 0)];
        $depths = array_fill(0, $n, 0);
        $fenceRuns = [];
        $lastComment = [];
        $commentPositions = [];
        $nextBlank = [$n => $n];
        for ($j = $n - 1; $j >= 0; $j--) {
            $rest = self::indentCols($this->lines[$j])['rest'];
            while (preg_match(self::QUOTE, $rest, $q) === 1) {
                $rest = self::indentCols($q[1] ?? '')['rest'];
                $depths[$j]++;
            }
            $nextBlank[$j] = $rest === '' ? $j : $nextBlank[$j + 1];
            $widest['`'][$j] = $widest['`'][$j + 1];
            $widest['~'][$j] = $widest['~'][$j + 1];
            if (preg_match('/^(`{3,}|~{3,})[ \t]*$/D', $rest, $c) === 1) {
                $widest[$c[1][0]][$j] = max($widest[$c[1][0]][$j], strlen($c[1]));
                $fenceRuns[$j] = $c[1];
            }
            if (preg_match('/^(%{3,})/', $rest, $c) === 1) {
                $width = strlen($c[1]);
                $lastComment[$width] ??= $j;
                $commentPositions[$width][] = $j;
            }
        }
        foreach ($commentPositions as &$positions) {
            $positions = array_reverse($positions);
        }
        unset($positions);

        return $this->runs = [
            'widest' => $widest,
            'depths' => $depths,
            'fenceRuns' => $fenceRuns,
            'lastComment' => $lastComment,
            'commentPositions' => $commentPositions,
            'nextBlank' => $nextBlank,
        ];
    }

    /**
     * A fence-shaped line the walk read as paragraph text is framed LAZY at its
     * owner's column when a closer-shaped run lies below it at its column or
     * deeper, so the readers cannot pair it with a moved opener (CARVE-P2-006).
     *
     * @param array<int, string> $result
     *
     * @return array<int, string>
     */
    private function frameTextFences(array $result): array
    {
        if ($this->textFences === []) {
            return $result;
        }
        $segments = [];
        $size = 0;
        foreach ($result as $j => $line) {
            $s = $line;
            $m = self::indentCols($s);
            while (preg_match(self::QUOTE, $m['rest'], $q) === 1) {
                $s = $q[1] ?? '';
                $m = self::indentCols($s);
            }
            $segments[$j] = [substr($line, 0, strlen($line) - strlen($s)), $m];
            $size = max($size, $m['col']);
        }
        $size += 2;
        // Index `$size - 1 - col`, so a prefix holds every column at or past `col`.
        $trees = ['`' => array_fill(0, $size, 0), '~' => array_fill(0, $size, 0)];
        for ($j = count($result) - 1; $j >= 0; $j--) {
            [$prefix, $m] = $segments[$j];
            if (isset($this->textFences[$j]) && preg_match('/^(`{3,}|~{3,})/', $m['rest'], $f) === 1) {
                $best = 0;
                for ($k = $size - 1 - $m['col']; $k >= 0; $k = ($k & ($k + 1)) - 1) {
                    $best = max($best, $trees[$f[1][0]][$k]);
                }
                if ($best >= strlen($f[1])) {
                    $result[$j] = $prefix . str_repeat(' ', $this->textFences[$j]) . BlockGrammar::LAZY_FRAME . $m['rest'];
                }
            }
            if (preg_match('/^(`{3,}|~{3,})[ \t]*$/D', $m['rest'], $c) === 1) {
                for ($k = $size - 1 - $m['col']; $k < $size; $k |= $k + 1) {
                    $trees[$c[1][0]][$k] = max($trees[$c[1][0]][$k], strlen($c[1]));
                }
            }
        }

        return $result;
    }

    /**
     * Whether a quote nested in frame k has its marker at the column and takes the line after the `+`.
     *
     * @param int $k
     * @param int $column
     */
    private function descendantQuoteTakesPlus(int $k, int $column): bool
    {
        $frameCount = count($this->frames);
        if ($k + 1 >= $frameCount || $this->frames[$k + 1]->markerCol === null || $this->frames[$k + 1]->docMarker === null) {
            return false;
        }
        for ($c = $k + 1; $c < $frameCount; $c++) {
            $child = $this->frames[$c];
            if ($child->kind === 'quote' && $child->docMarker === $column) {
                return true;
            }
        }

        return false;
    }

    /**
     * The one attach rule (PART 9 §17 L3, carve#4409): the host gives its marker column and the
     * quote prefixes the next line must carry, and the column is measured on the authored line.
     *
     * @param string $kind
     * @param int $at
     * @param int $column
     * @param int $hostIndex
     * @param array<int, \MarkupCarve\Carve\Parser\OwnershipFrame>|null $frames
     */
    private function attachesAt(string $kind, int $at, int $column, int $hostIndex, ?array $frames = null): bool
    {
        if (isset($this->claimedMarkers[$at])) {
            return false;
        }
        $frames ??= $this->frames;
        $held = false;
        $fenced = [];
        $noteBodies = [];
        $next = $this->firstVisibleLine($at + 1, $hostIndex, $column, $frames, $fenced, $noteBodies, false, $held);
        if ($held) {
            return true;
        }
        // A later marker at the same column offers the same block, so the first marker claims it (carve#5013).
        $chained = [];
        while (isset($this->lines[$next])) {
            $level = $this->throughQuotes($this->lines[$next], $hostIndex, $frames);
            if ($level === null || !self::isPlus(trim($level['rest'], " \t")) || self::docColumn($this->lines[$next], ltrim($level['rest'], " \t")) !== $column) {
                break;
            }
            $chained[] = $next;
            $next = $this->firstVisibleLine($next + 1, $hostIndex, $column, $frames);
        }
        foreach ($chained as $marker) {
            $this->claimedMarkers[$marker] = true;
        }
        $line = $this->lines[$next] ?? null;
        if ($line === null) {
            return false;
        }
        $m = $this->throughQuotes($line, $hostIndex, $frames);
        if ($m === null) {
            return false;
        }
        $rest = rtrim($m['rest'], " \t");
        // A line of nothing but quote markers is blank at its own level: it holds no block.
        if (preg_match(self::BARE_QUOTE_MARKERS, $rest) === 1 || self::docColumn($line, $m['rest']) !== $column) {
            return false;
        }
        if (preg_match('/^(:{3,})$/D', $rest, $closer) === 1) {
            foreach ($this->frames as $f) {
                if ($f->kind === 'colon' && $f->run === strlen($closer[1])) {
                    return false;
                }
            }
        }

        return match ($kind) {
            'item' => $this->listParser->parseListItemMarker($rest) === null,
            'dd' => preg_match(BlockGrammar::DEFINITION_TERM_LINE_PATTERN, $rest) !== 1 && preg_match('/^:( +)(?=[ \t]*[^ \t])/', $rest) !== 1,
            default => true,
        };
    }

    /**
     * The first line past the invisible lines from `$from` (CARVE-P9-013, carve#4449). Only the
     * lines the host keeps are skipped: at the marker column or at or past its content column
     * (carve#5130). A refused marker moves no line, so it skips only those at its column.
     * A note definition's body is its own.
     *
     * @param int $from
     * @param int $hostIndex
     * @param int $column
     * @param array<int, \MarkupCarve\Carve\Parser\OwnershipFrame> $frames
     * @param array<int, bool> $fenced Collects the lines of a skipped comment fence (true) and each skipped note definition (false).
     * @param array<int, true> $noteBodies Folded plain-text lines of skipped note definitions.
     * @param bool $refused
     * @param bool $held Set when a skipped attribute line holds the block whatever line follows.
     */
    private function firstVisibleLine(
        int $from,
        int $hostIndex,
        int $column,
        array $frames,
        array &$fenced = [],
        array &$noteBodies = [],
        bool $refused = false,
        bool &$held = false,
    ): int {
        $n = count($this->lines);
        $host = $frames[$hostIndex];
        $body = in_array($host->kind, ['item', 'dd', 'note'], true) ? (int)$host->docMarker + $host->col - (int)$host->markerCol : PHP_INT_MAX;
        $content = $refused ? PHP_INT_MAX : $body;
        $view = fn (int $k): string => $this->throughQuotes($this->lines[$k], $hostIndex, $frames)['rest'] ?? "\u{E000}";
        $rawView = fn (int $k): string => $this->throughQuotes($this->lines[$k], $hostIndex, $frames, true)['rest'] ?? "\u{E000}";
        $k = $from;
        $quoted = fn (int $j): bool => $this->throughQuotes($this->lines[$j], $hostIndex, $frames) !== null;
        $labels = $this->noteLabels;
        while ($k < $n) {
            // A line without the host's quote prefixes ends the quote, so no skipped construct reads it as text.
            if (!$quoted($k)) {
                return $k;
            }
            $rest = rtrim($view($k), " \t");
            $frameAt = self::perFrameColumn($this->lines[$k], $view($k), $frames);
            // A line short of the content column that line-start stops put at the marker column stands there.
            $stops = $frameAt !== $column && $frameAt < $body && self::docColumn($this->lines[$k], $view($k)) === $column;
            $at = $stops ? $column : $frameAt;
            if ($at !== $column && $at < $content) {
                return $k;
            }
            if (preg_match('/^(%{3,})/', $rest, $c) === 1) {
                $closer = '/^%{' . strlen($c[1]) . '}(?!%)/';
                $opener = $k++;
                // A nonblank line that misses the span's owner ends the span (CARVE-P0-005, carve#4870);
                // a span the host keeps at its content column is missed below that column.
                $bound = $at === $column ? $column : $content;
                $misses = fn (int $j): bool => $this->throughQuotes($this->lines[$j], $hostIndex, $frames) === null
                    || (trim($view($j), " \t") !== '' && !str_starts_with(ltrim($view($j), " \t"), '%%')
                        && self::docColumn($this->lines[$j], $view($j)) < $bound);
                while ($k < $n && preg_match($closer, $view($k)) !== 1 && !$misses($k)) {
                    $k++;
                }
                if ($k < $n && preg_match($closer, $view($k)) === 1) {
                    $k++;
                }
                for ($j = $opener; $j < min($k, $n); $j++) {
                    $fenced[$j] = true;
                }
            } elseif (preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $rest, $definition) === 1) {
                $offered = trim($definition[2], " \t") === '+' && $at === $column;
                $fenced[$k] = false;
                $key = LabelKey::normalize($definition[1]);
                // A losing duplicate's `+` takes nothing, so no line below is its body (carve#5474).
                if (trim($definition[2], " \t") === '+' && isset($labels[$key])) {
                    $k++;

                    continue;
                }
                $labels[$key] = true;
                $k++;
                // An attribute line at the note's body column is the note's (§15 A4) and ends its paragraph (§15 A1).
                $open = true;
                while ($k < $n && $quoted($k) && (!$offered || self::docColumn($this->lines[$k], $view($k)) !== $column)) {
                    $inBody = self::frameColumn($this->lines[$k], $view($k), $frames, $hostIndex) >= $at + BlockGrammar::FOOTNOTE_BODY_COLUMN;
                    if (!$inBody && !$open) {
                        break;
                    }
                    $line = rtrim($view($k), " \t");
                    if ($inBody && str_starts_with(ltrim($line, " \t"), '{')) {
                        $length = ($this->isAttributeLine)(ltrim($line, " \t"))
                            ? 1
                            : $this->skippedWrappedAttributeLength($k, $hostIndex, $frames);
                        if ($length !== null) {
                            $k += $length;
                            $open = false;

                            continue;
                        }
                    }
                    if (
                        !$this->foldsAsPlainText($line)
                        || (str_starts_with(ltrim($line, " \t"), '{') && $this->skippedWrappedAttributeLength($k, $hostIndex, $frames) !== null)
                    ) {
                        break;
                    }
                    $noteBodies[$k] = true;
                    $open = true;
                    $k++;
                }
            } elseif (
                preg_match('/^%%/', $rest) === 1 || ($this->isDefinition)($rest)
                || ($hostIndex === 1 && preg_match('/^\*\[([A-Za-z0-9]+)\]: +(?![ \t]*$)([^ ].*)$/D', $rest) === 1)
            ) {
                $k++;
            } elseif ($rest !== '' && $rest[0] === '{' && ($this->isAttributeLine)($rest)) {
                // After a refused `+` the item holds no block for an attribute line to wait for (CARVE-P0-011).
                if ($refused) {
                    return $k;
                }
                // Such an attribute line holds the block whatever follows it (PART 9 §17 L3).
                $held = $held || $stops;
                $k++;
            } elseif ($rest !== '' && $rest[0] === '{') {
                // A paragraph of only comments is skipped as a `%%` line is (carve#4799).
                $paragraph = str_starts_with($rest, '{%') ? $this->commentOnlyParagraphAt($k, $hostIndex, $frames) : -1;
                if ($paragraph !== -1) {
                    $k = $paragraph;

                    continue;
                }
                $length = $this->skippedWrappedAttributeLength($k, $hostIndex, $frames);
                if ($length === null || $refused) {
                    return $k;
                }
                $k += $length;
            } else {
                return $k;
            }
        }

        return $n;
    }

    /**
     * Classify a wrapped attribute run before a skipped note can take its opener as lazy text.
     *
     * @param int $at
     * @param int $hostIndex
     * @param array<int, \MarkupCarve\Carve\Parser\OwnershipFrame> $frames
     */
    private function skippedWrappedAttributeLength(int $at, int $hostIndex, array $frames): ?int
    {
        $view = fn (int $k): string => $this->throughQuotes($this->lines[$k], $hostIndex, $frames)['rest'] ?? "\u{E000}";
        $rawView = fn (int $k): string => $this->throughQuotes($this->lines[$k], $hostIndex, $frames, true)['rest'] ?? "\u{E000}";
        if (!isset($this->lines[$at]) || !str_starts_with(ltrim($view($at), " \t"), '{')) {
            return null;
        }
        $run = [];
        $openerColumn = IndentationHelper::getLeadingColumns($rawView($at));
        for ($j = $at, $n = count($this->lines); $j < $n && $j < $at + 64 && trim($view($j)) !== ''; $j++) {
            $part = rtrim($rawView($j), " \t");
            $run[] = str_repeat(' ', max(0, IndentationHelper::getLeadingColumns($part) - $openerColumn)) . ltrim($part, " \t");
            if ($j > $at && !WrappedBlockAttributeScanner::endsInsideBlock($part, true)) {
                break;
            }
        }

        $length = count($run) > 1 ? ($this->wrappedAttributeLength)($run) : null;

        return $length !== null && $length >= 2 ? $length : null;
    }

    /**
     * End of a paragraph of only comments at `$k` in the host's view, or -1. A line that misses the host is blank there.
     *
     * @param int $k
     * @param int $hostIndex
     * @param array<int, \MarkupCarve\Carve\Parser\OwnershipFrame> $frames
     */
    private function commentOnlyParagraphAt(int $k, int $hostIndex, array $frames): int
    {
        $run = [];
        for ($j = $k, $n = count($this->lines); $j < $n; $j++) {
            $rest = $this->throughQuotes($this->lines[$j], $hostIndex, $frames, true)['rest'] ?? '';
            if (trim($rest, " \t") === '') {
                break;
            }
            $run[] = $rest;
        }
        // A run refused by its continuation's column is paragraph text, so it is visible (CARVE-P2-035).
        if ($this->refusedBraceRunEnd !== null && ($this->refusedBraceRunEnd)($run, 0) !== null) {
            return -1;
        }
        $end = ($this->commentOnlyParagraphEnd)($run, 0, $frames[$hostIndex]->kind === 'item');

        return $end === -1 ? -1 : $k + $end;
    }

    /**
     * A line without `>` ends the `+`-attached quote unless it folds into its paragraph (PART 0 S4, carve#5427).
     *
     * @param array{frames: array<int, \MarkupCarve\Carve\Parser\OwnershipFrame>, column: int, depth: int, from: int, item: bool, kind: string} $held
     * @param array{col: int, rest: string} $level
     */
    private function endsAttachedQuote(array $held, array $level): bool
    {
        $top = count($this->frames) - 1;

        return $top === count($held['frames']) - 1 && $this->frames[$top]->kind === 'quote' && !$this->foldsIntoAttachedQuote($held, $level);
    }

    /**
     * The description a marker-line `+` quote stands in for, when the line reaches its column past the quote (carve#4871).
     */
    private function attachedQuoteHost(int $reached): ?OwnershipFrame
    {
        $held = $this->attachedTo;
        if ($held === null || $held['kind'] !== 'dd' || $this->at <= $held['from'] || $reached !== count($held['frames']) - 2) {
            return null;
        }
        $quote = $this->frames[$reached + 1] ?? null;
        $host = $held['frames'][count($held['frames']) - 1];

        return $quote !== null && $quote->kind === 'quote' && $quote->line === $held['from'] && $this->m['col'] >= $host->col ? $host : null;
    }

    /**
     * A first-block `+` reads its quote where it stands, outside the host's ordinary content column.
     * Once that quote ends, a line at the host's content column resumes the host rather than the
     * quote's parent. The quote's contents do not decide this boundary.
     */
    private function resumeHostAfterAttachedQuote(int $at): void
    {
        $held = $this->attachedTo;
        if (
            $held === null || $at <= $held['from']
            || !($held['item'] || $held['kind'] === 'note' || ($held['kind'] === 'dd' && $this->heldOnMarkerLine($held)))
        ) {
            return;
        }
        $host = $held['frames'][count($held['frames']) - 1];
        $level = $this->levelOf($this->src, $held['depth']);
        // A marker at the attached item's authored marker column is
        // its sibling, even when the one attached block was a quote.
        if ($held['item']) {
            $this->releaseAttachedItemAtSibling($at, $held, $level);
        }
        if (
            $this->attachedTo !== null && $level !== null && $level['rest'] !== '' && $level['col'] >= $host->col
            && $this->levelOf($this->src, $held['depth'] + 1) === null
            && (
                ($this->quoteDepth(count($this->frames) - 1) > $held['depth'] && ($held['item'] || !$this->foldsIntoAttachedQuote($held, $level)))
                || $this->endsAttachedQuote($held, $level)
            )
            && $this->attachedBlockEndsAt($at)
        ) {
            if (!$this->foldsIntoAttachedQuote($held, $level)) {
                $this->attachedQuoteEnds[$at] = true;
            }
            $this->frames = $held['frames'];
            $this->attachedTo = null;

            return;
        }
        // Once the attached block has closed, the next line is read against the note itself, so a line
        // below its column closes it at its authored column as it would without the `+` (carve-php#4942).
        if (
            $this->attachedTo !== null && $held['kind'] === 'note' && $level !== null && $level['rest'] !== ''
            && count($this->frames) < count($held['frames'])
            && $level['col'] >= (int)$host->markerCol && (int)$host->markerCol !== (int)$host->markerCanon
            && $this->heldOnMarkerLine($held)
            && $this->levelOf($this->src, $held['depth'] + 1) === null
        ) {
            $this->frames = $held['frames'];
            $this->attachedTo = null;

            return;
        }
        // Below a note's column a line that cannot fold ends the attached quote and the note with it (PART 0 S4, carve#5427).
        if (
            $this->attachedTo !== null && $held['kind'] === 'note' && $level !== null && $level['rest'] !== ''
            && $level['col'] < $host->col
            && $this->levelOf($this->src, $held['depth'] + 1) === null
            && $this->endsAttachedQuote($held, $level)
        ) {
            $this->attachedQuoteEnds[$at] = true;
            $this->frames = array_slice($held['frames'], 0, -1);
            $this->attachedTo = null;
        }
    }

    /**
     * Whether the held `+` stood on its host's marker line.
     *
     * @param array{frames: array<int, \MarkupCarve\Carve\Parser\OwnershipFrame>, column: int, depth: int, from: int, item: bool, kind: string} $held
     */
    private function heldOnMarkerLine(array $held): bool
    {
        return $held['frames'][count($held['frames']) - 1]->line === $held['from'] - 1;
    }

    /**
     * A caption captions a marker-line `+` quote only at the host's content column (carve#5432).
     *
     * @param array{frames: array<int, \MarkupCarve\Carve\Parser\OwnershipFrame>, column: int, depth: int, from: int, item: bool, kind: string} $held
     * @param array{col: int, rest: string} $level
     */
    private function foldsIntoAttachedQuote(array $held, array $level): bool
    {
        return $this->foldsAsPlainText($level['rest']) || str_starts_with($level['rest'], ': ')
            || (str_starts_with($level['rest'], '^ ')
                && (!$this->heldOnMarkerLine($held) || $level['col'] !== $held['frames'][count($held['frames']) - 1]->col));
    }

    /**
     * A line a note's body folds at its own column: text that opens no block.
     */
    private function foldsAsPlainText(string $rest): bool
    {
        if ($rest === '' || preg_match('/^(%%|\^ |::? (?=[ \t]*[^ \t]))/', $rest) === 1 || ($this->isDefinition)($rest)) {
            return false;
        }

        return $this->classify($rest, new OwnershipFrame('doc', 0, 0, 0, para: true), 0)->kind === 'text'
            && $this->classify($rest, new OwnershipFrame('doc', 0, 0, 0), 0)->kind === 'text';
    }

    /**
     * The line behind the quotes around frame `$hostIndex`. A `>` left of a frame holding that
     * quote has ended the frame, so it is not that quote's prefix.
     *
     * @param string $line
     * @param int $hostIndex
     * @param array<int, \MarkupCarve\Carve\Parser\OwnershipFrame> $frames
     * @param bool $raw The rest as written, its indentation kept.
     *
     * @return array{col?: int, rest: string}|null
     */
    private function throughQuotes(string $line, int $hostIndex, array $frames, bool $raw = false): ?array
    {
        $m = self::indentCols($line);
        $rest = $line;
        $floor = 0;
        for ($k = 1; $k < $hostIndex; $k++) {
            if ($frames[$k]->kind !== 'quote') {
                $floor = $frames[$k]->col;

                continue;
            }
            if ($m['col'] < $floor || preg_match(self::QUOTE, $m['rest'], $q) !== 1) {
                return null;
            }
            $rest = $q[1] ?? '';
            $m = self::indentCols($rest);
            $floor = 0;
        }

        return $raw ? ['rest' => $rest] : $m;
    }

    /**
     * The readers meet the skipped lines where the block stands, one line for a line, as a host reads a comment before its block.
     */
    private function skipInvisible(int $hostIndex, int $at, int $column, bool $refused = false): void
    {
        $fenced = [];
        $noteBodies = [];
        $end = $this->firstVisibleLine($at + 1, $hostIndex, $column, $this->frames, $fenced, $noteBodies, $refused);
        if ($end === $at + 1) {
            return;
        }
        if (in_array(false, $fenced, true)) {
            for ($j = $end; isset($this->lines[$j]) && preg_match('/^: +(?=[^ \t])/', ltrim($this->lines[$j], " \t")) === 1; $j++) {
                $this->lazyLines[$j] = true;
                $this->attachedPreludeLines[$j] = true;
            }
        }
        $basis = isset($this->lines[$end]) && $this->throughQuotes($this->lines[$end], $hostIndex, $this->frames) !== null ? $this->lines[$end] : $this->lines[$at];
        $raw = $this->throughQuotes($basis, $hostIndex, $this->frames, true)['rest'] ?? $basis;
        $prefix = substr($basis, 0, strlen($basis) - strlen($raw));
        // A quoted prelude carries the quote's content separator before its
        // host-relative marker column, even when that column is nonzero.
        if (str_ends_with($prefix, '>')) {
            $prefix .= ' ';
        }
        $lead = $prefix . str_repeat(' ', $this->frames[$hostIndex]->markerCanon);
        $this->skipped = [
            'host' => $this->frames[$hostIndex],
            'at' => $at,
            'end' => $end,
            'hostIndex' => $hostIndex,
            'fenced' => $fenced,
            'noteBodies' => $noteBodies,
            'column' => $column,
            'lead' => $lead,
        ];
    }

    /**
     * A refused `+` changes no owner (CARVE-P9-067), so the `%%%` span it skipped
     * stands in the host only when the next visible line does; otherwise it reads
     * as written and closes the host (PART 0 FAMILIES `invisible`).
     */
    private function standRefusedSpan(int $hostIndex): void
    {
        if ($this->skipped === null) {
            return;
        }
        $from = 0;
        for ($k = $hostIndex - 1; $k > 0; $k--) {
            if (!in_array($this->frames[$k]->kind, ['item', 'dd', 'note'], true)) {
                $from = $k;

                break;
            }
        }
        $this->nextVisible = -1;
        if ($this->nextVisibleReach($this->skipped['at'], $from) >= $hostIndex) {
            $this->skipped['spanPad'] = self::spanPad($this->frames[$hostIndex]);
        } elseif (!$this->nextVisibleIsSibling($this->frames[$hostIndex])) {
            $this->skipped['outside'] = false;
        }
    }

    /**
     * A sibling marker keeps the host's list open, so a comment before it stays in the host.
     */
    private function nextVisibleIsSibling(OwnershipFrame $host): bool
    {
        $line = $this->lines[$this->nextVisible] ?? null;
        if ($line === null || $host->kind !== 'item') {
            return false;
        }
        $rest = ltrim((string)preg_replace('/^(?:[ \t]*>[ ]?)*/', '', $line), " \t");

        return preg_match('/^(?:[-*+]|\d+[.)])(?:[ \t]|$)/', $rest) === 1 && self::docColumn($line, $rest) === $host->docMarker;
    }

    /**
     * A `%%%` span after a refused marker closes no item (PART 0 FAMILIES `invisible`, carve#5170),
     * so its placeholder lines stand at the host's content column, not below it.
     */
    private static function spanPad(OwnershipFrame $host): int
    {
        return in_array($host->kind, ['item', 'dd', 'note'], true) ? max(0, $host->canon - (int)$host->markerCanon) : 0;
    }

    /**
     * A blank ends a quote, and with it the block its `+` attached (PART 9 §17 L3, carve#5381).
     */
    private function endQuoteGrantAtBlank(int $at): void
    {
        $held = $this->attachedTo;
        if ($held === null || $held['kind'] !== 'quote' || $at < $held['from']) {
            return;
        }
        $level = $this->levelOf($this->src, $held['depth']);
        if ($level === null || $level['rest'] !== '') {
            return;
        }
        // A blank inside an open fence, comment span or div is its payload.
        for ($k = count($held['frames']) - 1, $n = count($this->frames); $k < $n; $k++) {
            if (in_array($this->frames[$k]->kind, ['fence', 'colon'], true)) {
                return;
            }
        }
        $this->frames = array_slice($held['frames'], 0, -1);
        $this->attachedTo = null;
    }

    private function holdAttachedTo(int $host, int $from, bool $ownLine = false): void
    {
        $depth = $this->quoteDepth($host);
        $parent = $this->attachedTo !== null && $depth > $this->attachedTo['depth'] ? new AttachmentParent($this->attachedTo) : null;
        $this->attachedTo = [
            'frames' => array_slice($this->frames, 0, $host + 1),
            'column' => (int)$this->frames[$host]->markerCol,
            'depth' => $depth,
            'from' => $from,
            'item' => $this->frames[$host]->kind === 'item',
            'kind' => $this->frames[$host]->kind,
            'parent' => $parent,
            'ownLine' => $ownLine,
        ];
    }

    /**
     * The placeholder a refused marker leaves in its host. An item keeps the next line, so its placeholder
     * stands at the content column, as the closer of the item's open code fence (CARVE-P9-067, carve#5637).
     */
    private function refusedPlaceholder(OwnershipFrame $host, int $at, bool $keepMarkerColumn, ?string $closer, bool $afterNestedBlank): string
    {
        $keepsItem = $host->kind === 'item' && !$afterNestedBlank
            && in_array($this->refusedPlus[$at]['reason'] ?? null, ['right', 'blank'], true);
        $pad = $keepMarkerColumn && !$keepsItem ? 0 : max(0, $host->canon - (int)$host->markerCanon);

        return str_repeat(' ', $pad) . ($keepsItem && $closer !== null ? $closer : '%%');
    }

    /**
     * Whether the first visible line after `$at` reaches `$column` past its quote markers.
     */
    private function followerAtColumn(int $at, int $column): bool
    {
        for ($k = $at + 1; isset($this->lines[$k]); $k++) {
            $body = ContainerPrefix::stripQuoteMarkers($this->lines[$k]);
            if (trim($body) !== '') {
                return IndentationHelper::getLeadingColumns($body) >= $column;
            }
        }

        return false;
    }

    /**
     * Whether a visible line stands between two lines, so a note's marker-line `+` attached a block.
     */
    private function bodyBetween(int $from, int $to): bool
    {
        for ($k = $from + 1; $k < $to; $k++) {
            if (trim(ContainerPrefix::stripQuoteMarkers($this->lines[$k])) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Read before the frames above the host close: the run of the open code fence directly in it,
     * and whether a blank before the marker follows a nested item, which the marker leaves as before.
     *
     * @return array{0: ?string, 1: bool}
     */
    private function refusedItemContext(int $host, int $at): array
    {
        $above = $this->frames[$host + 1] ?? null;

        return [
            $this->openCodeRunAbove($host),
            $at > 0 && trim($this->lines[$at - 1]) === '' && $above?->kind === 'item',
        ];
    }

    /**
     * The run of the open code fence directly in frame `$host`, or null.
     */
    private function openCodeRunAbove(int $host): ?string
    {
        $f = $this->frames[$host + 1] ?? null;
        if (
            $f !== null && $f->kind === 'fence' && $f->family === 'code' && $f->opened !== null
            && preg_match('/(`{3,}|~{3,})/', $this->lines[$f->opened] ?? '', $run) === 1
        ) {
            return $run[1];
        }

        return null;
    }

    /**
     * After a blank, a line that misses the host of an own-line attached fence ends that fence
     * unless it closes it (PART 0 S1 step 2, carve#5635).
     *
     * @param int $at
     * @param array<string> $lines
     */
    private function endOwnLineFenceAtMiss(int $at, array $lines): void
    {
        $held = $this->attachedTo;
        $top = $this->frames[count($this->frames) - 1];
        if (
            $held === null || !($held['ownLine'] ?? false) || $top->kind !== 'fence' || $at === 0
            // Only the attached fence: a later fence in the same document reads its blanks as payload.
            || $top->opened !== $held['from']
            || !IndentationHelper::isBlankLine($lines[$at - 1]) || IndentationHelper::isBlankLine($this->src)
        ) {
            return;
        }
        $last = count($held['frames']) - 1;
        $host = $held['frames'][$last];
        // A host nested in another body keeps its whole fence, as the spec model's readers do.
        for ($k = 1; $k < $last; $k++) {
            if (in_array($held['frames'][$k]->kind, ['item', 'dd', 'note', 'quote'], true)) {
                return;
            }
        }
        $through = $this->levelOf($this->src, $held['depth']);
        $col = $through === null ? -1 : $through['col'];
        if ($col >= $host->col || ($col === $host->markerCol && self::isPlus($through['rest'] ?? ''))) {
            return;
        }
        if ($through !== null && $top->closes !== null && ($top->closes)($through['rest'], $col)) {
            return;
        }
        $this->attachedFenceMisses[$at] = true;
        $this->frames = array_slice($held['frames'], 0, $last);
        $this->attachedTo = null;
    }

    /**
     * Keep an attribute's host only when the next plus can attach a block there.
     */
    private function closeAttributeHostBeforeLiteralPlus(int $at): void
    {
        $closedAttribute = $this->literalPlusAfterAttribute;
        if ($closedAttribute === null || $at !== $closedAttribute['line'] || !isset($this->frames[$closedAttribute['hostIndex']])) {
            return;
        }

        $view = $this->throughQuotes($this->src, $closedAttribute['hostIndex'], $this->frames);
        if ($view !== null && self::isPlus($view['rest']) && ($view['col'] ?? null) === $closedAttribute['column']) {
            $claimed = $this->claimedMarkers;
            unset($this->claimedMarkers[$at]);
            $host = $this->frames[$closedAttribute['hostIndex']];
            $attaches = $this->attachesAt($host->kind, $at, self::docColumn($this->src, $view['rest']), $closedAttribute['hostIndex']);
            $this->claimedMarkers = $claimed;
            if (!$attaches) {
                $this->truncate($closedAttribute['hostIndex']);
                $this->attachedTo = null;
            }
        }
    }

    /**
     * Restore the outer offer when a nested attachment or its quote has ended.
     */
    private function restoreEnclosingAttachedHost(int $at): void
    {
        while (
            $this->attachedTo !== null && isset($this->attachedTo['parent'])
            && $this->levelOf($this->src, $this->attachedTo['depth']) === null
        ) {
            $this->attachedTo = $this->attachedTo['parent']->host;
        }
        if (
            $this->attachedTo === null
            || !in_array($this->attachedTo['kind'], ['item', 'note', 'dd'], true)
            || count($this->frames) >= count($this->attachedTo['frames'])
        ) {
            return;
        }
        $held = $this->attachedTo;
        // A first-block offer can leave its host before the next block.
        // Only marker-line offers use this restoration boundary.
        if (!$this->heldOnMarkerLine($held)) {
            return;
        }
        $first = $this->levelOf($this->lines[$held['from']] ?? '', $held['depth']);
        // A definition has its own body boundary, including invisible spans;
        // it is not a single closed block merely because a later line appears.
        if ($first !== null && str_starts_with($first['rest'], ':: ')) {
            return;
        }
        $level = $this->levelOf($this->src, $held['depth']);
        $host = $held['frames'][count($held['frames']) - 1];
        if ($level !== null && $level['rest'] !== '' && $level['col'] >= $host->col && $this->attachedBlockEndsAt($at)) {
            $this->frames = $held['frames'];
            $this->attachedTo = ($held['parent'] ?? null)?->host;
        }
    }

    /**
     * @param int $at
     * @param array{frames: array<int, \MarkupCarve\Carve\Parser\OwnershipFrame>, column: int, depth: int, from: int, item: bool, kind: string} $held
     * @param array{col: int, rest: string}|null $level
     */
    private function releaseAttachedItemAtSibling(int $at, array $held, ?array $level): void
    {
        if (
            $level === null || $level['col'] < $held['column']
            || ($level['col'] !== $held['column'] && !$this->heldOnMarkerLine($held))
            || $level['col'] >= $held['frames'][count($held['frames']) - 1]->col
            || $this->listParser->parseListItemMarker($level['rest']) === null
            || !$this->attachedBlockEndsAt($at)
            || ($level['col'] !== $held['column'] && $this->attachedBlockKeepsMarker($held))
        ) {
            return;
        }
        $this->frames = array_slice($held['frames'], 0, -1);
        $this->attachedTo = null;
        $this->closingLines[$at] = true;
        $this->releasedSibling = 1;
    }

    private function opensBelowClosedColumn(bool $closedColumn): bool
    {
        return $closedColumn || $this->m['col'] < $this->closedAtBlank;
    }

    private function endAppliedLine(): void
    {
        if ($this->m['rest'] !== '') {
            $this->closedAtBlank = -1;
        }
        $this->lineFrames = $this->frames;
    }

    /**
     * The content column of the innermost container gone at this blank, a held `+` host included; -1 when none.
     */
    private function blankClosedColumn(): int
    {
        $column = -1;
        foreach ($this->lineFrames as $frame) {
            if ($frame->kind !== 'quote' && !in_array($frame, $this->frames, true)) {
                $column = max($column, $frame->col);
            }
        }
        $held = $this->attachedTo['frames'] ?? [];
        $host = $held === [] ? null : $held[count($held) - 1];
        if ($host !== null && $host->kind !== 'quote' && !in_array($host, $this->frames, true)) {
            $column = max($column, $host->col);
        }

        return $column;
    }

    /**
     * Whether the item's attached block reads a marker past the item's column as its own text: an open colon paragraph or a line block (carve-php#4968).
     *
     * @param array{frames: array<int, \MarkupCarve\Carve\Parser\OwnershipFrame>, column: int, depth: int, from: int, item: bool, kind: string} $held
     */
    private function attachedBlockKeepsMarker(array $held): bool
    {
        $para = $this->frames[count($this->frames) - 1]->para;
        // A column-0 block stands where the held item stood, so its frame sits at the item's depth.
        foreach (array_slice($this->frames, count($held['frames']) - 1) as $frame) {
            if (($frame->kind === 'colon' && $para) || ($frame->kind === 'fence' && $frame->lineBlock)) {
                return true;
            }
        }

        return false;
    }

    /**
     * An attached colon block ends at its closer; with none, a marker-line one ends at its first blank
     * and an own-line one holds every later line, a `+` included (carve#5658).
     *
     * @param int $at
     * @param array{frames: array<int, \MarkupCarve\Carve\Parser\OwnershipFrame>, column: int, depth: int, from: int, item: bool, kind: string, parent?: \MarkupCarve\Carve\Parser\AttachmentParent|null, ownLine?: bool} $held
     * @param int $run
     */
    private function attachedColonEndsBefore(int $at, array $held, int $run): bool
    {
        $nested = 0;
        // An unclosed code fence in the block ends at its first blank, and the block with it.
        $openCode = false;
        for ($k = $held['from'] + 1; $k < $at; $k++) {
            $level = $this->levelOf($this->lines[$k], $held['depth']);
            if ($level === null) {
                return false;
            }
            $rest = rtrim($level['rest'], " \t");
            if ($rest === '') {
                if ($openCode || !($held['ownLine'] ?? false)) {
                    return true;
                }

                continue;
            }
            // A closed code fence in the block keeps its payload, colon lines included; past an
            // unclosed one, which ends at its first blank, no opener is searched again.
            $code = $openCode ? null : $this->fenced->parseCodeFenceOpener($rest);
            if ($code !== null) {
                $closer = $k + 1;
                while ($closer < $at) {
                    $payload = $this->levelOf($this->lines[$closer], $held['depth']);
                    if ($payload !== null && $this->fenced->isCodeFenceCloser($payload['rest'], $code['fence'][0], $code['length'])) {
                        break;
                    }
                    $closer++;
                }
                if ($closer < $at) {
                    $k = $closer;

                    continue;
                }
                $openCode = true;
            }
            if (preg_match('/^:{' . $run . '}$/D', $rest) === 1) {
                if ($nested === 0) {
                    return true;
                }
                $nested--;
            } elseif (($this->fenced->parseDivFenceOpener($rest)['length'] ?? null) === $run) {
                $nested++;
            }
        }

        return false;
    }

    /**
     * Whether the lines since the last accepted `+` hold exactly one block.
     */
    private function attachedBlockEndsAt(int $at): bool
    {
        $held = $this->attachedTo;
        if ($held === null) {
            return false;
        }
        $last = $at;
        while (in_array($held['kind'], ['item', 'note', 'dd'], true) && $last > $held['from']) {
            $level = $this->levelOf($this->lines[$last - 1], $held['depth']);
            if ($level === null || trim($level['rest']) !== '') {
                break;
            }
            $last--;
        }
        if ($last <= $held['from']) {
            return false;
        }
        $own = [];
        for ($k = $held['from']; $k < $last; $k++) {
            $level = $this->levelOf($this->lines[$k], $held['depth']);
            if ($level === null) {
                return false;
            }
            $own[] = $level;
        }
        $first = $own[0]['rest'];
        if (preg_match('/^(:{3,})/', $first, $colon) === 1 && $this->fenced->parseDivFenceOpener($first) !== null) {
            return $this->attachedColonEndsBefore($at, $held, strlen($colon[1]));
        }
        if ($held['kind'] === 'note' && preg_match(BlockGrammar::DEFINITION_TERM_LINE_PATTERN, $first) === 1) {
            foreach ($own as $line) {
                if (
                    preg_match(BlockGrammar::DEFINITION_TERM_LINE_PATTERN, $line['rest']) !== 1
                    && preg_match(BlockGrammar::DEFINITION_BODY_LINE_PREFIX, $line['rest']) !== 1
                    && $line['col'] === 0
                ) {
                    return false;
                }
            }

            return true;
        }
        if (preg_match('/^(`{3,}|~{3,}|%{3,})/', $first, $open) === 1) {
            for ($k = 1, $n = count($own); $k < $n; $k++) {
                if (str_starts_with($own[$k]['rest'], $open[1]) && rtrim($own[$k]['rest'], " \t" . $open[1][0]) === '') {
                    return $k === $n - 1;
                }
            }

            return false;
        }
        $softLeaf = $this->softLeaf;
        $reach = $this->reach;
        $this->reach = 0;
        $form = $this->classify($first, new OwnershipFrame('doc', 0, 0, 0), 0);
        $oneBlock = true;
        foreach ($own as $k => $line) {
            if ($k === 0) {
                continue;
            }
            if ($line['rest'] === '') {
                $oneBlock = false;

                break;
            }
            $next = $this->classify($line['rest'], new OwnershipFrame('doc', 0, 0, 0, para: $form->kind === 'text'), 0);
            if ($form->kind === 'text' ? $next->interrupts : !($first[0] === '|' && $line['rest'][0] === '|') && !($form->kind === 'quote' || $form->kind === 'item' || $form->kind === 'colon')) {
                $oneBlock = false;

                break;
            }
        }
        $this->softLeaf = $softLeaf;
        $this->reach = $reach;

        return $oneBlock;
    }

    /**
     * @param int $at
     * @param array{line: int, column: int, host: string, reason: string}|null $finding
     */
    private function refusePlus(int $at, ?array $finding): void
    {
        $this->src = str_replace('+', $this->letter, $this->src);
        $this->m = self::indentCols($this->src);
        $this->refusedPlus[$at] = $finding;
        $this->letterPlus[$at] = true;
    }

    /**
     * A missed quote prefix makes the note's brace opener literal, leaving its paragraph open.
     */
    private function followsRefusedNoteBrace(int $at, int $topIndex): bool
    {
        $note = $this->frames[$topIndex];
        if ($note->kind !== 'note' || !$note->para || $at < 2) {
            return false;
        }
        $opener = $this->lines[$at - 1];
        $body = ltrim(ContainerPrefix::stripQuoteMarkers($opener), " \t");

        return str_starts_with($body, '{')
            && ContainerPrefix::countLeadingQuoteMarkers(ltrim($this->lines[$at], " \t"))
        < ContainerPrefix::countLeadingQuoteMarkers(ltrim($opener, " \t"));
    }

    /**
     * A refused marker-line plus grants no body below the note's ordinary content column.
     */
    private function refusedNotePlusMissesBody(int $at, int $topIndex): bool
    {
        $note = $this->frames[$topIndex];

        if ($note->kind !== 'note' || $note->line !== $at - 1) {
            return false;
        }
        $markerLine = $this->lines[$note->line] ?? '';
        if (preg_match('/^((?:>[ \t]*)+)/', $markerLine, $quotePrefix) !== 1) {
            return false;
        }
        $depth = substr_count($quotePrefix[1], '>');
        if (preg_match('/^(?:>[ \t]*){' . $depth . '}/', $this->lines[$at]) !== 1) {
            return false;
        }

        return preg_match('/\[\^[^]]+\]:[ \t]+\+[ \t]*$/D', $markerLine) === 1
            && $this->m['col'] < $note->col;
    }

    /**
     * A wrapped brace run below a refused marker-line note `+` is read where
     * its opener stands. Plain text reaching the note body still folds lazily (CARVE-P9-067).
     */
    private function refusedNotePlusPrecedesBraceRun(int $at, int $topIndex): bool
    {
        $note = $this->frames[$topIndex];
        if (
            $note->kind !== 'note' || $note->line !== $at - 1 || $this->refusedBraceRunEnd === null
            || preg_match('/\[\^[^]]+\]:[ \t]+\+[ \t]*$/D', $this->lines[$note->line] ?? '') !== 1
            || !str_starts_with($this->m['rest'], '{')
        ) {
            return false;
        }
        $run = [str_repeat(' ', self::docColumn($this->lines[$at], $this->m['rest'])) . $this->m['rest']];
        for ($line = $at + 1, $count = count($this->lines); $line < $count; $line++) {
            $view = $this->throughQuotes($this->lines[$line], $topIndex, $this->frames);
            $run[] = $view === null ? $this->lines[$line]
                : str_repeat(' ', self::docColumn($this->lines[$line], $view['rest'])) . $view['rest'];
            $continuation = $run[count($run) - 1];
            if (str_contains($continuation, '}') && ($this->refusedBraceRunEnd)($run, 0) !== null) {
                return true;
            }
            if (trim($continuation, " \t") === '' || preg_match('/^\s*(?:>\s*)*\[\^[^]]+\]:/', $this->lines[$line]) === 1) {
                return false;
            }
        }

        return false;
    }

    /**
     * A refused `+` opens no paragraph (PART 9 §17 L3); a note body still takes the next line lazily.
     */
    private function consumedLeavesNoParagraph(int $frame): bool
    {
        return !$this->framesHoldNote();
    }

    private function framesHoldNote(): bool
    {
        foreach ($this->frames as $f) {
            if ($f->kind === 'note') {
                return true;
            }
        }

        return false;
    }

    /**
     * A refused marker on a marker line is consumed: an invisible comment takes its place (carve#5013).
     */
    private function consumePlus(): void
    {
        $plusAt = (int)strrpos($this->src, '+');
        $this->src = substr($this->src, 0, $plusAt) . '%%' . substr($this->src, $plusAt + 1);
        $this->m = self::indentCols($this->src);
    }

    /**
     * A plus sign with no frame at its marker column is a letter under PART 0 S2.
     *
     * @return array{line: int, column: int, host: string, reason: string}|null
     */
    private function separatePlusFinding(int $at, int $reached): ?array
    {
        $owner = $this->frames[$reached];
        if ($owner->kind === 'quote' && $this->m['col'] === 0) {
            $next = $this->levelOf($this->lines[$at + 1] ?? '', $this->quoteDepth($reached + 1));

            return $next !== null && $next['col'] === 0 && $this->listParser->parseListItemMarker($next['rest']) !== null
                ? $this->finding($at, 'quote', 'sibling')
                : null;
        }

        return null;
    }

    /**
     * Why the one attach rule refused a `+` at a host's marker column.
     *
     * @return array{line: int, column: int, host: string, reason: string}
     */
    private function plusFinding(int $at, string $kind, int $column, int $hostIndex, ?int $after = null): array
    {
        $count = count($this->lines);
        // The markers a chain claimed offer the block after them, so that block decides the reason.
        $after ??= $at + 1;
        while (isset($this->claimedMarkers[$after])) {
            $after++;
        }
        $next = $this->lines[$after] ?? null;
        if ($next === null || ($next === '' && $after + 1 === $count)) {
            return $this->finding($at, $kind, 'document');
        }
        $level = $this->throughQuotes($next, $hostIndex, $this->frames);
        if ($level === null) {
            return $this->finding($at, $kind, 'left');
        }
        if (preg_match(self::BARE_QUOTE_MARKERS, rtrim($level['rest'], " \t")) === 1) {
            return $this->finding($at, $kind, 'blank');
        }
        $followerColumn = self::docColumn($next, $level['rest']);
        if ($followerColumn !== $column) {
            return $this->finding($at, $kind, $followerColumn < $column ? 'left' : 'right');
        }

        return $this->finding($at, $kind, preg_match('/^:{3,}[ \t]*$/D', $level['rest']) === 1 ? 'closer' : 'sibling');
    }

    /**
     * Whether the last `+` offered the block after a skipped run still takes it.
     *
     * @param array{at: int, offeredAt?: int} $skipped
     */
    private function skippedRunClaims(array $skipped): bool
    {
        $offer = $skipped['offeredAt'] ?? $skipped['at'];

        return !isset($this->refusedNotePlus[$offer]) && !isset($this->refusedPlus[$offer]);
    }

    /**
     * Lint for a definition's marker-line `+` the walk passes over, losing or live (carve#5953).
     * Its follower decides, as for any marker-line `+`; a following definition is the note's sibling,
     * and a follower at its column that an earlier `+` takes is claimed.
     *
     * @return array{line: int, column: int, host: string, reason: string}|null
     */
    private function notePlusFinding(int $at, int $column, int $hostIndex, bool $claimed = false): ?array
    {
        // An invisible comment span between the `+` and its follower is stepped over, a definition is not.
        $fenced = [];
        $after = $this->firstVisibleLine($at + 1, $hostIndex, $column, $this->frames, $fenced, refused: true);
        for ($k = $at + 1; $k < $after; $k++) {
            $line = $this->throughQuotes($this->lines[$k], $hostIndex, $this->frames);
            if (!($fenced[$k] ?? false) && preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, ltrim($line['rest'] ?? '', " \t")) === 1) {
                $after = $k;

                break;
            }
        }
        $finding = $this->plusFinding($at, 'note', $column, $hostIndex, $after);
        $next = $this->throughQuotes($this->lines[$after] ?? '', $hostIndex, $this->frames);
        if ($finding['reason'] !== 'sibling' || preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, ltrim($next['rest'] ?? '', " \t")) === 1) {
            return $finding;
        }

        return $claimed ? $this->finding($at, 'note', 'claimed') : null;
    }

    /**
     * @return array{line: int, column: int, host: string, reason: string}
     */
    private function finding(int $at, string $kind, string $reason): array
    {
        $line = $this->lines[$at];

        return [
            'line' => $at + 1,
            'column' => mb_strlen(substr($line, 0, (int)strrpos($line, '+')), 'UTF-8') + 1,
            'host' => match ($kind) {
                'item' => 'list item',
                'dd' => 'definition description',
                'note' => 'footnote body',
                'quote' => 'block quote',
                default => 'document',
            },
            'reason' => $reason,
        ];
    }

    /**
     * @return array{col: int, rest: string}|null
     */
    private function levelOf(string $line, int $depth): ?array
    {
        $m = self::indentCols($line);
        for ($d = 0; $d < $depth; $d++) {
            if (preg_match(self::QUOTE, $m['rest'], $q) !== 1) {
                return null;
            }
            $m = self::indentCols($q[1] ?? '');
        }

        return $m;
    }

    /**
     * @return array{kind: string, column: int, depth: int, content: int, first: bool, list: int|null, note: int|null}
     */
    private function hostRecord(int $frame, int $column, bool $first): array
    {
        $f = $this->frames[$frame];
        $list = null;
        $note = null;
        for ($k = $frame; $k > 0; $k--) {
            $open = $this->frames[$k];
            if ($open->kind === 'item' && $list === null) {
                $list = $open->written;
            }
            if ($open->kind === 'note' && $note === null) {
                $note = $open->written;
            }
        }

        return [
            'kind' => match ($f->kind) {
                'item' => 'list',
                'dd' => 'description',
                'note' => 'footnote',
                default => $f->kind,
            },
            'column' => $first && $f->kind !== 'quote' ? (int)$f->written : $column,
            'depth' => $this->quoteDepth($frame) + ($first && $f->kind === 'quote' ? 1 : 0),
            'content' => $column,
            'first' => $first,
            'list' => $list,
            'note' => $note,
        ];
    }

    private static function isPlus(string $rest): bool
    {
        return ($rest[0] ?? '') === '+' && strspn($rest, " \t", 1) === strlen($rest) - 1;
    }

    /**
     * The column the end of `$written` reaches.
     */
    private static function columnOf(string $written): int
    {
        $col = 0;
        $len = strlen($written);
        for ($i = 0; $i < $len; $i++) {
            $col = $written[$i] === "\t" ? (intdiv($col, 4) + 1) * 4 : $col + 1;
        }

        return $col;
    }

    private function quoteBelow(int $reached): bool
    {
        for ($k = $reached + 1, $n = count($this->frames); $k < $n; $k++) {
            if ($this->frames[$k]->kind === 'quote') {
                return true;
            }
        }

        return false;
    }

    private function quoteDepth(int $below): int
    {
        $depth = 0;
        for ($k = 1; $k < $below; $k++) {
            if ($this->frames[$k]->kind === 'quote') {
                $depth++;
            }
        }

        return $depth;
    }

    private function hasAttachedQuoteAncestor(int $below): bool
    {
        for ($k = 1; $k < $below; $k++) {
            if ($this->frames[$k]->kind === 'quote' && $this->frames[$k]->plus !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * A leading run's column as nested frames strip it (PART 9 §24 C5): tab stops
     * restart at every frame column of the quote segment from `$segment` the run reaches.
     *
     * @param string $line
     * @param int $segment
     * @param array{col: int, rest: string} $m
     */
    private function strippedColumn(string $line, int $segment, array $m): int
    {
        if (!str_contains(substr($line, 0, strlen($line) - strlen($m['rest'])), "\t")) {
            return $m['col'];
        }
        $origins = [];
        for ($q = $segment, $n = count($this->frames); $q < $n && $this->frames[$q]->kind !== 'quote'; $q++) {
            $origins[] = $this->frames[$q]->col + $this->frames[$q]->c5Shift;
        }
        $col = 0;
        $origin = 0;
        $o = 0;
        $last = count($origins);
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            if ($line[$i] === ' ') {
                $col++;
            } elseif ($line[$i] === "\t") {
                $col = $origin + (intdiv($col - $origin, 4) + 1) * 4;
            } else {
                break;
            }
            while ($o < $last && $origins[$o] <= $col) {
                $origin = $origins[$o++];
            }
        }

        return $col;
    }

    /**
     * The document column where `$rest`, a suffix of `$line`, starts.
     */
    private static function docColumn(string $line, string $rest): int
    {
        $col = 0;
        $end = strlen($line) - strlen($rest);
        for ($i = 0; $i < $end; $i++) {
            if ((ord($line[$i]) & 0xC0) === 0x80) {
                continue;
            }
            $col = $line[$i] === "\t" ? (intdiv($col, 4) + 1) * 4 : $col + 1;
        }

        return $col;
    }

    /**
     * The column of a tab-led line with tab stops restarted at every open frame column
     * (PART 9 §24 C5); under a quote or a fence the line-start stops hold.
     *
     * @param string $line
     * @param string $rest
     * @param array<int, \MarkupCarve\Carve\Parser\OwnershipFrame> $frames
     */
    private static function perFrameColumn(string $line, string $rest, array $frames): int
    {
        if (preg_match('/^[ \t]*\t/', $line) !== 1) {
            return self::docColumn($line, $rest);
        }
        $origins = [];
        foreach (array_slice($frames, 1) as $f) {
            if ($f->kind === 'quote' || $f->kind === 'fence') {
                return self::docColumn($line, $rest);
            }
            $origins[] = $f->col + $f->c5Shift;
        }
        $col = 0;
        $origin = 0;
        $o = 0;
        $last = count($origins);
        for ($i = 0, $len = strlen($line); $i < $len; $i++) {
            if ($line[$i] === ' ') {
                $col++;
            } elseif ($line[$i] === "\t") {
                $col = $origin + (intdiv($col - $origin, 4) + 1) * 4;
            } else {
                break;
            }
            while ($o < $last && $origins[$o] <= $col) {
                $origin = $origins[$o++];
            }
        }

        return $col;
    }

    /**
     * `docColumn()` with the tab stops of the indentation before `$rest` restarted at
     * every body frame column up to `$hostIndex` (PART 9 §24 C5).
     *
     * @param string $line
     * @param string $rest
     * @param array<int, \MarkupCarve\Carve\Parser\OwnershipFrame> $frames
     * @param int $hostIndex
     */
    private static function frameColumn(string $line, string $rest, array $frames, int $hostIndex): int
    {
        $end = strlen($line) - strlen($rest);
        $start = $end;
        while ($start > 0 && ($line[$start - 1] === ' ' || $line[$start - 1] === "\t")) {
            $start--;
        }
        if (!str_contains(substr($line, $start, $end - $start), "\t") || str_contains(substr($line, 0, $start), '>')) {
            return self::docColumn($line, $rest);
        }
        $col = self::docColumn($line, substr($line, $start));
        $origins = [0];
        for ($k = 1; $k <= $hostIndex && isset($frames[$k]); $k++) {
            $f = $frames[$k];
            if (in_array($f->kind, ['item', 'dd', 'note'], true) && $f->docMarker !== null) {
                $origins[] = $f->docMarker + $f->col - (int)$f->markerCol;
            }
        }
        sort($origins);
        $origin = 0;
        foreach ($origins as $o) {
            if ($o <= $col) {
                $origin = $o;
            }
        }
        for ($i = $start; $i < $end; $i++) {
            $col = $line[$i] === ' ' ? $col + 1 : $origin + (intdiv($col - $origin, 4) + 1) * 4;
            foreach ($origins as $o) {
                if ($o <= $col) {
                    $origin = max($origin, $o);
                }
            }
        }

        return $col;
    }

    /**
     * @return array{col: int, rest: string}
     */
    private static function indentCols(string $line): array
    {
        $col = 0;
        $i = 0;
        $len = strlen($line);
        while ($i < $len) {
            $c = $line[$i];
            if ($c === ' ') {
                $col++;
            } elseif ($c === "\t") {
                $col += 4 - ($col % 4);
            } else {
                break;
            }
            $i++;
        }

        return ['col' => $col, 'rest' => $i >= $len ? '' : substr($line, $i)];
    }

    /**
     * A lazy line that reaches the quote holding a description and misses only its body
     * leaves a later marker that misses the quote too lazy text, not a list (PART 0 S4).
     */
    private function markQuotedLazyDescription(int $reached, int $topIndex): void
    {
        if ($reached >= $topIndex || $this->frames[$reached]->kind !== 'quote' || $this->frames[$reached + 1]->kind !== 'dd') {
            return;
        }
        for ($k = $reached + 2; $k <= $topIndex; $k++) {
            if (!in_array($this->frames[$k]->kind, ['dd', 'quote'], true)) {
                return;
            }
        }
        $this->quotedLazyDescriptions[$this->frames[$reached + 1]] = $reached;
    }

    private function missesQuotedLazyDescription(int $reached, int $topIndex): bool
    {
        for ($k = $reached + 1; $k <= $topIndex; $k++) {
            if ($this->frames[$k]->kind === 'dd' && $reached < ($this->quotedLazyDescriptions[$this->frames[$k]] ?? -1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the owner at `$reached` is a marker body, or the line misses one (a first-block quote stands for its item).
     */
    private function marksBodyList(int $reached): bool
    {
        if (in_array($this->frames[$reached]->kind, self::BODY_FRAMES, true)) {
            return true;
        }
        $count = count($this->frames);
        for ($k = $reached + 1; $k < $count; $k++) {
            $frame = $this->frames[$k];
            if (in_array($frame->kind, self::BODY_FRAMES, true)) {
                return true;
            }
            $host = $this->frames[$k - 1];
            if ($frame->kind === 'quote' && ($host->kind === 'item' || $host->kind === 'dd') && $host->plus !== null && $host->plus === ($frame->line ?? -2) - 1) {
                return true;
            }
            // An attached quote stands in its host's place after the host
            // leaves the frame stack. A description can also leave through
            // an own-line `+`; item hosts take their own sibling path
            // (carve#4871, carve#5644).
            if (
                $frame->kind === 'quote' && $this->attachedTo !== null && $this->attachedTo['from'] === $frame->line
                && in_array($this->attachedTo['kind'], ['item', 'dd'], true)
                && ($this->attachedTo['kind'] === 'dd' || $this->heldOnMarkerLine($this->attachedTo))
                && $this->m['col'] < $this->attachedTo['frames'][count($this->attachedTo['frames']) - 1]->col
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a description-shaped line ends a missed item; collectors must not fold it into that item either (carve#5312).
     */
    private function descriptionEndsMissedItem(int $at, int $reached, bool $missedQuote): bool
    {
        if ($this->m['rest'] === '' || !$this->descriptionAtMissedItemMarker($reached, $missedQuote)) {
            return false;
        }
        $this->closingLines[$at] = true;

        return true;
    }

    private function clearItemDefinitionAtParagraph(int $owner, bool $opens): void
    {
        if ($this->frames[$owner]->kind === 'item') {
            $this->frames[$owner]->hasDefinition = false;
            if ($opens) {
                $this->leaveClosedDescription($owner);
            }
        }
    }

    /**
     * A block outside the item's definition list ends that list, and the marker-column boundary with it
     * (PART 0 S4, carve#5368, carve#5373).
     */
    private function leaveClosedDescription(int $owner): void
    {
        $this->frames[$owner]->descriptionLeft = false;
    }

    /**
     * A description-shaped line at a surviving item's marker column ends that item (PART 0 S4).
     */
    private function descriptionAtMissedItemMarker(int $reached, bool $missedQuote): bool
    {
        if (
            in_array($this->frames[$reached]->kind, ['dd', 'note'], true)
            || preg_match(BlockGrammar::DEFINITION_BODY_LINE_PREFIX, $this->m['rest']) !== 1
        ) {
            return false;
        }
        for ($k = $reached + 1, $count = count($this->frames); $k < $count; $k++) {
            // An item inside a quote the line missed is no surviving container (carve#5273).
            if ($this->frames[$k]->kind === 'quote') {
                return false;
            }
            if ($this->frames[$k]->kind === 'item' && $this->m['col'] === $this->frames[$k]->markerCol) {
                for ($child = $k; $child < $count; $child++) {
                    // A description inside a quote is the quote's: its paragraph takes the line lazily.
                    if ($this->frames[$child]->kind === 'quote') {
                        return false;
                    }
                    // An open description, or one that closed in an item still open (carve#5320).
                    if ($this->frames[$child]->kind === 'dd' || $this->frames[$child]->descriptionLeft || (!$missedQuote && $this->frames[$child]->hasDefinition)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
