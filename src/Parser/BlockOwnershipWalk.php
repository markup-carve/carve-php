<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use Closure;
use MarkupCarve\Carve\Parser\Block\FencedBlockParser;
use MarkupCarve\Carve\Parser\Block\ListParser;
use MarkupCarve\Carve\Parser\Block\TableParser;
use MarkupCarve\Carve\Parser\Utility\IndentationHelper;

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
     * Lines the walk read as lazy paragraph text, keyed by line index.
     *
     * @var array<int, true>
     */
    public array $lazyLines = [];

    /**
     * Lines before the block a taken `+` attaches, keyed by source line.
     * The note's own lazy continuation can follow the invisible run.
     *
     * @var array<int, true>
     */
    public array $attachedPreludeLines = [];

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
     * Blanks that end a `+` attached code fence with no closer (PART 9 §17 L3), keyed by line index.
     *
     * @var array<int, true>
     */
    public array $attachedFenceBlankEnds = [];

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
     * Every lone `+` the walk refused, keyed by line index: a refused marker renders nothing (carve#5013),
     * an off-column `+` is a letter. The value is its lint finding, or null where the rule does not report it.
     *
     * @var array<int, array{line: int, column: int, host: string, reason: string}|null>
     */
    public array $refusedPlus = [];

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

    /**
     * The invisible lines between a taken `+` and its block, read where that block stands (CARVE-P9-013).
     *
     * @var array{host: \MarkupCarve\Carve\Parser\OwnershipFrame, at: int, end: int, hostIndex: int, fenced: array<int, bool>, noteBodies: array<int, true>, column: int, lead: string, spanPad?: int, runColumn?: int|null}|null
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
     * @var array{frames: array<int, \MarkupCarve\Carve\Parser\OwnershipFrame>, column: int, depth: int, from: int, item: bool, kind: string}|null
     */
    private ?array $attachedTo = null;

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
     * @var array{widest: array<string, array<int, int>>, lastComment: array<int, int>, commentPositions: array<int, list<int>>, nextBlank: array<int, int>}|null
     */
    private ?array $runs = null;

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
     * @var array<int, true> Unmarked lines released from a quoted item span to its outer quote.
     */
    public array $outerQuoteLines = [];

    /**
     * Whether the document opens with frontmatter (PART 1 admits no indent on either delimiter).
     */
    public bool $frontmatter = false;

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

    private int $nextVisible = -1;

    private int $refusedKeptStart = -1;

    private int $refusedKeptThrough = -1;

    private int $movedSpanEnd = -1;

    /**
     * Decision trace records (docs/decision-trace.md in carve), or null when
     * tracing is off. Step ids follow PART 0 (CARVE-P0-024).
     *
     * @var array<int, array<string, int|string|null>>|null
     */
    public ?array $trace = null;

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
        $this->runs = null;
        $this->textFences = [];
        $this->closingLines = [];
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
        $this->movedSpanEnd = -1;
        $this->lazyLines = [];
        $this->attachedPreludeLines = [];
        $this->attachedPreludeContentColumns = [];
        $this->commentPayloadHostColumns = [];
        $this->skippedCommentLines = [];
        $this->commentColumnOwners = [];
        $this->lineBlockLazyLines = [];
        $this->attachedFenceBlankEnds = [];
        $this->attachedQuoteEnds = [];
        $this->traceBlankHost = null;
        $this->attachedFenceScans = [];
        $this->refusedKeptLines = [];
        $this->fenceOpenerLines = [];
        $this->commentEndLines = [];
        $this->commentCloserLines = [];
        $this->fenceBodyLines = [];
        $this->refusedPlus = [];
        $this->letterPlus = [];
        $this->claimedMarkers = [];
        $this->overindented = [];
        $this->plusHosts = [];
        $this->ddSiblingOwner = null;
        $this->skipped = null;
        $this->refusedAttributeHost = null;
        $this->attachedTo = null;
        $this->releasedSibling = 0;
        $this->frames = [new OwnershipFrame('doc', 0, 0, 0)];
        $result = [];
        $afterPlus = false;
        $count = count($lines);
        $start = $this->ownFrontmatter($lines, $result);
        for ($at = $start; $at < $count; $at++) {
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
            $this->recordCommentPayloadHostColumn($at);
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
            // A first-block `+` reads its quote where it stands, outside the
            // host's ordinary content column. Once that quote ends, a line at
            // the host's content column resumes the host rather than the
            // quote's parent. The quote's contents do not decide this boundary.
            if ($this->attachedTo !== null && $at > $this->attachedTo['from'] && ($this->attachedTo['item'] || $this->attachedTo['kind'] === 'note')) {
                $held = $this->attachedTo;
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
                        ($this->quoteDepth(count($this->frames) - 1) > $held['depth'] && ($held['item'] || !$this->foldsIntoAttachedQuote($level['rest'])))
                        || $this->endsAttachedQuote($held, $level['rest'])
                    )
                    && $this->attachedBlockEndsAt($at)
                ) {
                    $this->attachedQuoteEnds[$at] = true;
                    $this->frames = $held['frames'];
                    $this->attachedTo = null;
                }
            }
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
                $this->truncate($plus + 1);
                $host = $this->frames[$plus];
                $host->para = false;
                $column = self::docColumn($lines[$at], $this->m['rest']);
                $this->refusedPlus[$at] = isset($this->claimedMarkers[$at])
                    ? $this->finding($at, $host->kind, 'claimed')
                    : $this->plusFinding($at, $host->kind, $column, $plus);
                $this->skipInvisible($plus, $at, $column, true);
                if ($this->skipped !== null && !$keepMarkerColumn) {
                    $this->skipped['spanPad'] = self::spanPad($host);
                }
                $this->lead((int)$host->markerCanon);
                $result[] = $this->out . str_repeat(' ', $keepMarkerColumn ? 0 : max(0, $host->canon - (int)$host->markerCanon)) . '%%';
                if ($this->trace !== null) {
                    $this->emit($at, 'S2', $this->ownerPath(), 'CARVE-P9-067', 'plus', ['cell' => 'B-plus-start']);
                }

                continue;
            }
            if ($plus !== null) {
                $this->truncate($plus + 1);
                $this->frames[$plus]->para = false;
                $this->skipInvisible($plus, $at, self::docColumn($lines[$at], $this->m['rest']));
                $this->holdAttachedTo($plus, $this->skipped['end'] ?? $at + 1);
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
                $this->lead(max($this->m['col'] - $inner->cut, $inner->canon));
                $rest = $this->m['rest'];
                if ($rest !== '' && $inner->span !== null) {
                    $span = $inner->span;
                    $comment = $span[0] === '%';
                    if (
                        preg_match($comment ? '/^(%{3,})/' : '/^(`{3,}|~{3,})[ \t]*$/D', $rest, $c) === 1 && $c[1][0] === $span[0]
                        && ($comment ? strlen($c[1]) === strlen($span) : strlen($c[1]) >= strlen($span))
                    ) {
                        $this->frames[$innerIndex]->span = null;
                        // A fence-shaped verse run is stanza text, so a lazy line folds after its closer (PART 0 S4).
                        if (!$comment && $inner->lineBlock) {
                            $this->frames[$innerIndex]->para = true;
                        }
                    }
                } elseif ($rest !== '' && $inner->closes !== null && ($inner->closes)($rest, $this->m['col'])) {
                    array_pop($this->frames);
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
                        && ($this->closerRuns()['widest'][$f[1][0]][$at + 1] ?? 0) >= strlen($f[1])
                    ) {
                        $this->frames[$innerIndex]->span = $f[1];
                    } elseif ($this->m['col'] - $inner->col <= 3 && preg_match('/^(%{3,})/', $rest, $c) === 1) {
                        // A deeper percent run is verse text, not a span opener.
                        // A comment span leaves nothing: the stanza stays as it was before it (carve#4956).
                        $this->frames[$innerIndex]->span = $c[1];
                    } else {
                        // A blank payload line ends the stanza, so no lazy line folds past it (PART 0 S4).
                        $this->frames[$innerIndex]->para = $rest !== '';
                    }
                }
                $this->recordFenceBodyLine($at, $inner);
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
            if ($inner->kind === 'fence' && !($inner->lineBlock && $inner->para && $this->m['rest'] !== '')) {
                array_pop($this->frames);
                $opener = $inner->opened ?? $inner->line;
                if ($inner->family === 'comment' && $this->m['rest'] !== '' && $opener !== null && preg_match('/%{3,}/', $lines[$opener] ?? '', $run) === 1) {
                    $this->commentEndLines[$at] = [$opener, strlen($run[0])];
                }
            }
            // S3: the line's form, the record's `form` field.
            $form = $this->classifyLine($this->m['rest'], $this->frames[$reached], $this->m['col']);
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
                // A marker that a marker body owns, or that misses one, opens a list (PART 0 S3, carve#4860).
                && !($form->kind === 'item' && $this->marksBodyList($reached))
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
                if ($missedNote && $this->frames[$topIndex]->plus !== $at - 1) {
                    $this->lead(0);
                    for ($k = $reached + 1; $k <= $topIndex; $k++) {
                        if ($this->frames[$k]->kind === 'quote') {
                            $this->out .= str_repeat(' ', $this->frames[$k - 1]->reader ?? $this->frames[$k - 1]->canon) . '> ';
                        }
                    }
                    $leaf = $this->frames[$topIndex];
                    $result[] = $this->out . str_repeat(' ', $leaf->kind === 'quote' ? 0 : (int)$leaf->reader) . BlockGrammar::LAZY_FRAME . $this->src;
                    $this->lazyLines[$at] = true;
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
                $this->lead($this->m['col']);
            } elseif ($form->kind === 'colon-close') {
                $this->lead($this->frames[$form->frame]->canon);
                $closerPath = $this->trace !== null ? $this->ownerPath($form->frame) : null;
                $this->truncate($form->frame);
                $t = count($this->frames) - 1;
                $this->frames[$t]->para = false;
                $this->frames[$t]->last = 'block';
            } elseif ($form->kind === 'text') {
                if (!$plusFollower) {
                    $this->noteOpener($o, $form, $this->frames[$o]->col);
                }
                $owner = $this->frames[$o];
                $this->lead($owner->para || !$closedColumn || $plusFollower
                    ? max($this->m['col'] - $owner->cut, $owner->canon)
                    : $owner->canon);
                if ($owner->para && !$form->kept && preg_match('/^(`{3,}|~{3,})/', $this->m['rest']) === 1) {
                    $this->textFences[count($result)] = $owner->canon;
                }
                $opensParagraph = !$owner->para;
                $this->frames[$o]->para = true;
                $this->frames[$o]->last = 'para';
                $this->clearItemDefinitionAtParagraph($o, $opensParagraph);
            } else {
                if (!$plusFollower) {
                    $this->noteOpener($o, $form, $this->frames[$o]->col);
                }
                $shift = $this->strippedColumn($this->src, $segment, $this->m) - $this->m['col'];
                $this->lead($this->frames[$o]->canon, $form->kind === 'term' || $form->kind === 'dd');
                $this->apply($o, $this->m['col'], $form, $this->frames[$o]->canon, inHost: !$leftLineBlockSpan);
                $this->markAttachedFence($at, $o, $form, $plusFollower);
                // A marker opened on an off-stop tab run has its C5 columns that far right.
                for ($k = $o + 1, $n = count($this->frames); $k < $n && $this->frames[$k]->kind !== 'quote'; $k++) {
                    if (in_array($this->frames[$k]->kind, self::BODY_FRAMES, true)) {
                        $this->frames[$k]->c5Shift = $shift;
                    }
                }
            }
            $this->sibling = null;
            $result[] = $this->out . $this->src;
            if ($this->trace !== null) {
                $this->emitApplied($at, $form, $closerPath ?? $this->ownerPath(), $closed, $reachedPath, $plusFollower);
            }
        }
        $this->refusedKeptLines = array_filter(
            $this->refusedKeptLines,
            fn (int $runStart): bool => !$this->refusedRunMissesItsContainer($runStart),
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
        if ($count === 0 || preg_match(self::FRONTMATTER_OPENER, $lines[0]) !== 1) {
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
            $column = $level['col'] ?? 0;
            $this->skipped['runColumn'] ??= $column;
            $pad = max(0, $column - $this->skipped['runColumn']);
        }
        $result[] = $skipped['lead'] . match (true) {
            $inSpan => str_repeat(' ', $skipped['spanPad'] ?? 0) . '%%',
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
            $this->skipped = null;
        }

        return true;
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

        return true;
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
        if ($plusFollower && $form->kind === 'colon') {
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
        $opener = $this->fenced->parseCodeFenceOpener($this->m['rest']);
        if (
            $this->frames[$host]->kind === 'item' && $opener !== null
            && $this->attachedCodeFenceBlankAhead($at, (int)$this->frames[$host]->markerCol, $opener['char'], $opener['length'])
        ) {
            $fence->col = $this->m['col'];
        }
        $key = rtrim($this->m['rest']) . '|' . $this->m['col'] . '|' . $fence->col;
        $fence->endsAtBlank = !$this->attachedFenceClosed($at, $fence, $key);
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
        if ($this->reachesAttachedItemFence($frame) || $this->m['rest'] === '' || $this->m['col'] >= $f->col || $inWrappedAttribute) {
            return false;
        }
        // A tab past an off-stop frame column is measured in that frame (PART 9 §24 C5).
        $stripped ??= $this->strippedColumn($this->src, $segment, $this->m);

        return $stripped < $f->col + $f->c5Shift;
    }

    private function attachedItemFollowerReach(int $reached, bool $plusFollower): int
    {
        $item = $this->frames[count($this->frames) - 1];
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
    private function attachedFenceClosed(int $at, OwnershipFrame $fence, string $key): bool
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

                continue;
            }
            if (
                $afterBlank && $hostFrame !== null && in_array($hostFrame->kind, ['note', 'dd'], true)
                && $line['col'] < $fence->blankHostFrames[count($fence->blankHostFrames) - 1]->col
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
                if ($bare ? $nested > 0 : ($this->fenced->parseDivFenceOpener($line['rest'])['length'] ?? null) === $fence->run) {
                    $nested += $bare ? -1 : 1;
                    $afterBlank = false;

                    continue;
                }
            }
            if (
                ($codeOpener !== null && $line['col'] === $fence->col
                    && $this->fenced->isCodeFenceCloser($line['rest'], $codeOpener['char'], $codeOpener['length']))
                ||
                ($fence->closes !== null && ($fence->closes)($line['rest'], $line['col']))
                || ($fence->kind === 'colon' && preg_match('/^:{' . $fence->run . '}[ \t]*$/D', $line['rest']) === 1)
            ) {
                return ($this->attachedFenceScans[$key] = [$k, true])[1];
            }
            $afterBlank = false;
        }

        return ($this->attachedFenceScans[$key] = [$n, false])[1];
    }

    private function recordFenceBodyLine(int $at, OwnershipFrame $fence): void
    {
        if ($fence->family !== 'comment' && !$fence->lineBlock) {
            $this->fenceBodyLines[$at] = true;
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
        $this->frames[$owner]->para = $form->caption;
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
            $this->take($form->head);
            $this->m = self::indentCols($this->src);
            $this->content(count($this->frames) - 1, $col + $form->head, $canon + $form->head, $leadTab);
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

    private function content(int $frame, int $start, int $canon, bool $leadTab = false): void
    {
        if ($this->m['rest'] === '' || count($this->frames) > BlockGrammar::MAX_NESTING_DEPTH) {
            return;
        }
        $this->onMarkerLine = true;
        $column = $this->frames[$frame]->kind === 'quote' ? $this->m['col'] : $start + $this->m['col'];
        $form = $this->classifyLine($this->m['rest'], $this->frames[$frame], $column);
        $this->onMarkerLine = false;
        if ($form->kind === 'text') {
            $plus = false;
            $consumed = false;
            if ($leadTab && self::isPlus($this->m['rest'])) {
                $this->refusePlus($this->at, null);
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
                    if ($this->skipped !== null) {
                        $this->skipped['spanPad'] = self::spanPad($f);
                    }
                    $consumed = true;
                }
            }
            $this->lead($this->m['col']);
            if ($plus) {
                $this->plusHosts[$this->at] = $this->hostRecord($frame, self::columnOf($this->out), true);
            }
            // A consumed item marker leaves no paragraph a lazy line continues; past a note the note folds it (PART 0 S4).
            $this->frames[$frame]->para = !$plus && !($consumed && $this->consumedLeavesNoParagraph($frame));
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
        if ($frame->kind === 'item' || $frame->kind === 'fence' || $this->m['col'] <= $contentColumn) {
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

    private function lead(int $target, bool $spaces = false): void
    {
        $col = min($target, $this->m['col']);
        $authored = substr($this->src, 0, strlen($this->src) - strlen($this->m['rest']));
        $this->out .= $col === $this->m['col'] && !($spaces && $this->out === '' && str_contains($authored, "\t"))
            ? $authored
            : str_repeat(' ', $col);
        $this->src = $this->m['rest'];
    }

    private function take(int $count): void
    {
        $this->out .= substr($this->src, 0, $count);
        $this->src = (string)substr($this->src, $count);
    }

    /**
     * A wrapped attribute block is one line to its owner (A5, carve#4089), so its
     * later lines stay with the opener whatever their column. A later line without
     * the owner's quote markers does not continue it (PART 4).
     */
    private function opensWrappedAttribute(string $rest, OwnershipFrame $owner): bool
    {
        $depth = 0;
        foreach ($this->frames as $frame) {
            if ($this->onMarkerLine && $frame->kind !== 'quote' && $frame->kind !== 'doc') {
                return false;
            }
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
        $openerColumn = IndentationHelper::getLeadingColumns($opener);
        if ($length < 2 || ($this->wrappedAttributeLength)($run, $openerColumn) !== $length) {
            return false;
        }
        $this->wrappedAttributeEnd = $this->at + $length - 1;
        $this->wrappedAttributeColumn = $openerColumn;

        return true;
    }

    private function classifyLine(string $rest, OwnershipFrame $owner, int $col): OwnershipForm
    {
        return $this->keptByRefusedRun($rest, $owner, $col) ?? $this->classify($rest, $owner, $col);
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
        if ($at > $this->refusedKeptStart && $this->refusedRunMissesItsContainer($this->refusedKeptStart)) {
            $this->refusedKeptThrough = -1;
        }
        if ($at > $this->refusedKeptStart && $at <= $this->refusedKeptThrough && !$this->invisibleSibling($rest)) {
            $this->refusedKeptLines[$at] = $this->refusedKeptStart;

            return new OwnershipForm(kind: 'text', interrupts: false, kept: true);
        }
        // A comment beginning on its owner's marker line belongs to that
        // marker's reader; only a later opener can hold a paragraph follower.
        if ($rest[0] !== '{' || (str_starts_with($rest, '{%') && $owner->line === $at)) {
            return null;
        }
        if ($at === $this->refusedKeptStart) {
            return $at <= $this->refusedKeptThrough ? new OwnershipForm(kind: 'text', interrupts: false, kept: true) : null;
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
        // A description body reads its refused runs itself (DefinitionListBuilder).
        if ($top->kind === 'dd' || $top->kind === 'note') {
            return null;
        }
        $column = in_array($top->kind, ['doc', 'quote'], true) ? 0 : $top->col;
        // The window doubles only while the answer reaches its edge, so a run of one-line attributes stays linear.
        $window = [str_repeat(' ', $col) . $rest];
        $count = count($this->lines);
        $j = $at + 1;
        $ended = false;
        for ($size = 16;; $size *= 2) {
            for (; $j < $count && $j - $at < $size && !$ended; $j++) {
                $line = $this->lines[$j];
                for ($d = 0; $d < $depth && $line !== null; $d++) {
                    $line = preg_match(self::QUOTE, ltrim($line, " \t"), $q) === 1 ? ($q[1] ?? '') : null;
                }
                $window[] = $line ?? '';
                $ended = $line === null || trim($line, " \t") === '';
            }
            $kept = ($this->refusedRunKeptThrough)($window, 0, $column, !$ended && $j < $count);
            if ($kept !== -1) {
                break;
            }
        }
        if ($kept === null) {
            return null;
        }
        $this->refusedKeptStart = $at;
        $this->refusedKeptThrough = $at + $kept;
        $this->refusedKeptLines[$at] = $at;

        return new OwnershipForm(kind: 'text', interrupts: false, kept: true);
    }

    /**
     * A run that misses its container, as a lazy line or by closing it, keeps nothing.
     */
    private function refusedRunMissesItsContainer(int $start): bool
    {
        return isset($this->lazyLines[$start]) || isset($this->closingLines[$start]);
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
            || ($this->isDefinition)($rest)
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
     * @return array{widest: array<string, array<int, int>>, lastComment: array<int, int>, commentPositions: array<int, list<int>>, nextBlank: array<int, int>}
     */
    private function closerRuns(): array
    {
        if ($this->runs !== null) {
            return $this->runs;
        }
        $n = count($this->lines);
        $widest = ['`' => array_fill(0, $n + 1, 0), '~' => array_fill(0, $n + 1, 0)];
        $lastComment = [];
        $commentPositions = [];
        $nextBlank = [$n => $n];
        for ($j = $n - 1; $j >= 0; $j--) {
            $rest = self::indentCols($this->lines[$j])['rest'];
            while (preg_match(self::QUOTE, $rest, $q) === 1) {
                $rest = self::indentCols($q[1] ?? '')['rest'];
            }
            $nextBlank[$j] = $rest === '' ? $j : $nextBlank[$j + 1];
            $widest['`'][$j] = $widest['`'][$j + 1];
            $widest['~'][$j] = $widest['~'][$j + 1];
            if (preg_match('/^(`{3,}|~{3,})[ \t]*$/D', $rest, $c) === 1) {
                $widest[$c[1][0]][$j] = max($widest[$c[1][0]][$j], strlen($c[1]));
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
        $next = $this->firstVisibleLine($at + 1, $hostIndex, $column, $frames);
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
     */
    private function firstVisibleLine(
        int $from,
        int $hostIndex,
        int $column,
        array $frames,
        array &$fenced = [],
        array &$noteBodies = [],
        bool $refused = false,
    ): int {
        $n = count($this->lines);
        $host = $frames[$hostIndex];
        $content = !$refused && in_array($host->kind, ['item', 'dd', 'note'], true)
            ? (int)$host->docMarker + $host->col - (int)$host->markerCol
            : PHP_INT_MAX;
        $view = fn (int $k): string => $this->throughQuotes($this->lines[$k], $hostIndex, $frames)['rest'] ?? "\u{E000}";
        $k = $from;
        $quoted = fn (int $j): bool => $this->throughQuotes($this->lines[$j], $hostIndex, $frames) !== null;
        while ($k < $n) {
            // A line without the host's quote prefixes ends the quote, so no skipped construct reads it as text.
            if (!$quoted($k)) {
                return $k;
            }
            $rest = rtrim($view($k), " \t");
            $at = self::docColumn($this->lines[$k], $view($k));
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
            } elseif (preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $rest) === 1) {
                $fenced[$k] = false;
                $k++;
                while ($k < $n && $quoted($k) && $this->foldsAsPlainText(rtrim($view($k), " \t"))) {
                    $noteBodies[$k] = true;
                    $k++;
                }
            } elseif (
                preg_match('/^%%/', $rest) === 1 || ($this->isDefinition)($rest)
                || ($hostIndex === 1 && preg_match('/^\*\[([A-Za-z0-9]+)\]: +(?![ \t]*$)([^ ].*)$/D', $rest) === 1)
            ) {
                $k++;
            } elseif ($rest !== '' && $rest[0] === '{' && ($this->isAttributeLine)($rest)) {
                $k++;
            } elseif ($rest !== '' && $rest[0] === '{') {
                // A paragraph of only comments is skipped as a `%%` line is (carve#4799).
                $paragraph = str_starts_with($rest, '{%') ? $this->commentOnlyParagraphAt($k, $hostIndex, $frames) : -1;
                if ($paragraph !== -1) {
                    $k = $paragraph;

                    continue;
                }
                $run = [];
                for ($j = $k; $j < $n && $j < $k + 64 && trim($view($j)) !== ''; $j++) {
                    $run[] = rtrim($view($j), " \t");
                }
                $length = count($run) > 1 ? ($this->wrappedAttributeLength)($run) : null;
                if ($length === null || $length < 2) {
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
     * A line without `>` ends the `+`-attached quote unless it folds into its paragraph or captions it (PART 0 S4, carve#5427).
     *
     * @param array{frames: array<int, \MarkupCarve\Carve\Parser\OwnershipFrame>, column: int, depth: int, from: int, item: bool, kind: string} $held
     * @param string $rest
     */
    private function endsAttachedQuote(array $held, string $rest): bool
    {
        $top = count($this->frames) - 1;

        return $top === count($held['frames']) - 1 && $this->frames[$top]->kind === 'quote' && !$this->foldsIntoAttachedQuote($rest);
    }

    private function foldsIntoAttachedQuote(string $rest): bool
    {
        return $this->foldsAsPlainText($rest) || preg_match('/^(\^ |: )/', $rest) === 1;
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
        $this->skipped = [
            'host' => $this->frames[$hostIndex],
            'at' => $at,
            'end' => $end,
            'hostIndex' => $hostIndex,
            'fenced' => $fenced,
            'noteBodies' => $noteBodies,
            'column' => $column,
            'lead' => substr($basis, 0, strlen($basis) - strlen($raw)) . str_repeat(' ', $this->frames[$hostIndex]->markerCanon),
        ];
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

    private function holdAttachedTo(int $host, int $from): void
    {
        $this->attachedTo = [
            'frames' => array_slice($this->frames, 0, $host + 1),
            'column' => (int)$this->frames[$host]->markerCol,
            'depth' => $this->quoteDepth($host),
            'from' => $from,
            'item' => $this->frames[$host]->kind === 'item',
            'kind' => $this->frames[$host]->kind,
        ];
    }

    /**
     * @param int $at
     * @param array{frames: array<int, \MarkupCarve\Carve\Parser\OwnershipFrame>, column: int, depth: int, from: int, item: bool, kind: string} $held
     * @param array{col: int, rest: string}|null $level
     */
    private function releaseAttachedItemAtSibling(int $at, array $held, ?array $level): void
    {
        if (
            $level === null || $level['col'] !== $held['column']
            || $this->listParser->parseListItemMarker($level['rest']) === null
            || !$this->attachedBlockEndsAt($at)
        ) {
            return;
        }
        $this->frames = array_slice($held['frames'], 0, -1);
        $this->attachedTo = null;
        $this->closingLines[$at] = true;
        $this->releasedSibling = 1;
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
        while ($held['item'] && $last > $held['from']) {
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
     * A refused `+` opens no paragraph (PART 9 §17 L3); a note body still takes the next line lazily.
     * A quoted description keeps the spec model's HTML reading, which its trace does not share (carve#5415).
     */
    private function consumedLeavesNoParagraph(int $frame): bool
    {
        $parent = $this->frames[$frame - 1]->kind ?? 'doc';

        return !$this->framesHoldNote() && !($this->frames[$frame]->kind === 'dd' && $parent === 'quote');
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
    private function plusFinding(int $at, string $kind, int $column, int $hostIndex): array
    {
        $count = count($this->lines);
        // The markers a chain claimed offer the block after them, so that block decides the reason.
        $after = $at + 1;
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
        return preg_match('/^\+[ \t]*$/D', $rest) === 1;
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
            $origins[] = $this->frames[$q]->col;
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
            $col = $line[$i] === "\t" ? (intdiv($col, 4) + 1) * 4 : $col + 1;
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
