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
     * Lazy lines folded into an open line block's stanza, keyed by line index.
     *
     * @var array<int, true>
     */
    public array $lineBlockLazyLines = [];

    /**
     * Lines the walk opened a code fence on, keyed by line index.
     *
     * @var array<int, true>
     */
    public array $fenceOpenerLines = [];

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
     * @var array{host: \MarkupCarve\Carve\Parser\OwnershipFrame, at: int, end: int, hostIndex: int, fenced: array<int, bool>, noteBodies: array<int, true>, column: int, lead: string, spanPad?: int}|null
     */
    private ?array $skipped = null;

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
     * Whether the document opens with frontmatter (PART 1 admits no indent on either delimiter).
     */
    public bool $frontmatter = false;

    /**
     * Whether the line just read was an abbreviation under a paragraph or a
     * caption with no block before it: the readers fold the next line there.
     */
    private bool $softLeaf = false;

    /**
     * Whether the last line in a verse's open comment span was blank; such a blank stays outside an S1 end.
     */
    private bool $verseSpanBlank = false;

    private string $src = '';

    private string $out = '';

    /**
     * @var array{col: int, rest: string}
     */
    private array $m = ['col' => 0, 'rest' => ''];

    private int $at = 0;

    private int $wrappedAttributeEnd = -1;

    private int $wrappedAttributeColumn = 0;

    private bool $onMarkerLine = false;

    private int $nextVisible = -1;

    private int $movedSpanEnd = -1;

    /**
     * Decision trace records (docs/decision-trace.md in carve), or null when
     * tracing is off. Step ids follow PART 0 (CARVE-P0-024).
     *
     * @var array<int, array<string, int|string|null>>|null
     */
    public ?array $trace = null;

    public function __construct(
        private readonly ListParser $listParser,
        private readonly FencedBlockParser $fenced,
        private readonly TableParser $tables,
        private readonly Closure $isDefinition,
        private readonly Closure $isAttributeLine,
        private readonly Closure $wrappedAttributeLength,
        private readonly Closure $commentOnlyParagraphEnd,
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
        $this->frontmatter = false;
        $this->softLeaf = false;
        $this->reach = 0;
        $this->wrappedAttributeEnd = -1;
        $this->nextVisible = -1;
        $this->movedSpanEnd = -1;
        $this->lazyLines = [];
        $this->attachedPreludeLines = [];
        $this->skippedCommentLines = [];
        $this->lineBlockLazyLines = [];
        $this->fenceOpenerLines = [];
        $this->refusedPlus = [];
        $this->letterPlus = [];
        $this->claimedMarkers = [];
        $this->overindented = [];
        $this->plusHosts = [];
        $this->ddSiblingOwner = null;
        $this->skipped = null;
        $this->attachedTo = null;
        $this->frames = [new OwnershipFrame('doc', 0, 0, 0)];
        $result = [];
        $afterPlus = false;
        $start = 0;
        $count = count($lines);
        // A first-line opener claims through its first closer or EOF (carve#5016).
        if ($count > 0 && preg_match(self::FRONTMATTER_OPENER, $lines[0]) === 1) {
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
        }
        for ($at = $start; $at < $count; $at++) {
            if ($this->carrySkippedPrelude($at, $result)) {
                continue;
            }
            $afterSoftLeaf = $this->softLeaf;
            $this->softLeaf = false;
            $this->at = $at;
            $this->src = $lines[$at];
            $this->out = '';
            $this->m = self::indentCols($this->src);
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
            // A first-block `+` reads its quote where it stands, outside the
            // host's ordinary content column. Once that quote ends, a line at
            // the host's content column resumes the host rather than the
            // quote's parent. The quote's contents do not decide this boundary.
            if ($this->attachedTo !== null && $at > $this->attachedTo['from'] && $this->attachedTo['item']) {
                $held = $this->attachedTo;
                $host = $held['frames'][count($held['frames']) - 1];
                $level = $this->levelOf($this->src, $held['depth']);
                // A marker at the attached item's authored marker column is
                // its sibling, even when the one attached block was a quote.
                $this->releaseAttachedItemAtSibling($at, $held, $level);
                if (
                    $this->attachedTo !== null && $level !== null && $level['rest'] !== '' && $level['col'] >= $host->col
                    && $this->levelOf($this->src, $held['depth'] + 1) === null
                    && $this->quoteDepth(count($this->frames) - 1) > $held['depth']
                    && $this->attachedBlockEndsAt($at)
                ) {
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
            // S2: the deepest open frame the line reaches.
            for ($k = 1; $k < $frameCount; $k++) {
                $f = $this->frames[$k];
                // A lone `+` reaches the marker column of an open marker container.
                if (
                    $f->markerCol !== null && $this->m['col'] === $f->markerCol && ($f->kind === 'quote' || $this->m['col'] < $f->col)
                    && self::isPlus($this->m['rest'])
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

                    continue;
                }
                if ($this->m['rest'] !== '' && $this->m['col'] < $f->col && !$inWrappedAttribute) {
                    // A tab past an off-stop frame column is measured in that frame (PART 9 §24 C5).
                    $stripped ??= $this->strippedColumn($this->src, $segment, $this->m);
                    if ($stripped < $f->col + $f->c5Shift) {
                        break;
                    }
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
            $reached = $this->standInvisibleLine($reached, $plus !== null);
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
            $missed = $reached < count($this->frames) - 1;
            $innerIndex = count($this->frames) - 1;
            $inner = $this->frames[$innerIndex];
            // A comment line below the column misses no host of an open comment span (CARVE-P0-005).
            if (
                $missed && $inner->kind === 'fence' && $inner->family === 'comment' && $inner->span === null
                && str_starts_with($this->m['rest'], '%%')
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
                $this->lead($this->frames[$reached]->canon);
                $result[] = $this->out . $this->src;
                if ($this->trace !== null) {
                    $this->emit($at, 'S1', $this->ownerPath(), 'CARVE-P0-005', 'closer', ['cell' => 'B-comment-end']);
                }

                continue;
            }
            if ($inner->kind === 'fence' && !$missed) {
                $this->verseSpanBlank = $inner->lineBlock && $inner->span !== null && $inner->span[0] === '%' && $this->m['rest'] === '';
                // S1: an open payload tests its closer first (B-<family>-keep, B-<family>-end).
                $payloadPath = $this->trace !== null ? $this->ownerPath() : '';
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
                    } elseif (preg_match('/^(%{3,})/', $rest, $c) === 1) {
                        // A comment span leaves nothing: the stanza stays as it was before it (carve#4956).
                        $this->frames[$innerIndex]->span = $c[1];
                    } else {
                        // A blank payload line ends the stanza, so no lazy line folds past it (PART 0 S4).
                        $this->frames[$innerIndex]->para = $rest !== '';
                    }
                }
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
            }
            // S3: the line's form, the record's `form` field.
            $form = $this->classify($this->m['rest'], $this->frames[$reached], $this->m['col']);
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
            $lazy = $missed && $next !== null && $this->m['rest'] !== '' && $this->frames[$topIndex]->para && !$form->interrupts && !$captionsQuote && $form->kind !== 'dd'
                // A marker that a marker body owns, or that misses one, opens a list (PART 0 S3, carve#4860).
                && !($form->kind === 'item' && $this->marksBodyList($reached))
                && !($this->verseSpanBlank && $this->frames[$topIndex]->lineBlock && $this->frames[$topIndex]->span !== null && $this->frames[$topIndex]->span[0] === '%');
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
                $this->lead(min(max($this->m['col'] - $owner->cut, $owner->canon), $limit));
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
            // A document-owned abbreviation definition ends a term too (carve#4412).
            if (!$missed && $this->frames[$reached]->last === 'term' && $this->abbreviationMissesFrames($reached, $form)) {
                $this->closingLines[$at] = true;
            }
            // S5: apply the line, the boundary table T of CARVE-P0-003.
            $closed = count($this->frames) - 1 - $reached;
            $reachedPath = $this->trace !== null && $closed > 0 ? $this->ownerPath($reached) : null;
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
                if ($owner->para && preg_match('/^(`{3,}|~{3,})/', $this->m['rest']) === 1) {
                    $this->textFences[count($result)] = $owner->canon;
                }
                $this->frames[$o]->para = true;
                $this->frames[$o]->last = 'para';
            } else {
                if (!$plusFollower) {
                    $this->noteOpener($o, $form, $this->frames[$o]->col);
                }
                $shift = $this->strippedColumn($this->src, $segment, $this->m) - $this->m['col'];
                $this->lead($this->frames[$o]->canon, $form->kind === 'term' || $form->kind === 'dd');
                $this->apply($o, $this->m['col'], $form, $this->frames[$o]->canon, inHost: !$leftLineBlockSpan);
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

        return $this->frameTextFences($result);
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
        $rest = $this->throughQuotes($this->lines[$at], $skipped['hostIndex'], $this->frames)['rest'] ?? '';
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
        $result[] = $skipped['lead'] . match (true) {
            $inSpan => str_repeat(' ', $skipped['spanPad'] ?? 0) . '%%',
            $abbreviation !== null => '%%',
            default => $rest,
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

    private function apply(int $owner, int $col, OwnershipForm $form, int $canon, ?int $reader = null, bool $inHost = false): void
    {
        $reader ??= $this->frames[$owner]->reader ?? $this->frames[$owner]->canon;
        $this->frames[$owner]->para = $form->caption;
        // An invisible line between siblings leaves the host's last form standing (carve#4716).
        if (
            $form->family !== 'comment' && ($form->kind !== 'leaf' || ((!str_starts_with($this->m['rest'], '%%') || str_starts_with($this->m['rest'], '%%%')) && !($this->isDefinition)($this->m['rest'])
            && !($this->frames[$owner]->kind === 'doc' && preg_match('/^\*\[[A-Za-z0-9]+\]: +(?![ \t]*$)/', $this->m['rest']) === 1)))
        ) {
            $this->frames[$owner]->last = $form->kind;
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
        $this->frames[] = $frame;
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
        for ($n = count($this->frames); $n > $count; $n--) {
            array_pop($this->frames);
        }
    }

    private function content(int $frame, int $start, int $canon, bool $leadTab = false): void
    {
        if ($this->m['rest'] === '' || count($this->frames) > BlockGrammar::MAX_NESTING_DEPTH) {
            return;
        }
        $this->onMarkerLine = true;
        $form = $this->classify($this->m['rest'], $this->frames[$frame], $this->frames[$frame]->kind === 'quote' ? $this->m['col'] : $start + $this->m['col']);
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

    private function classify(string $rest, OwnershipFrame $owner, int $col): OwnershipForm
    {
        if ($rest === '') {
            return new OwnershipForm(kind: 'blank', interrupts: true);
        }
        if (preg_match(self::QUOTE, $rest) === 1) {
            return new OwnershipForm(kind: 'quote', interrupts: true);
        }
        if (preg_match('/^(:{3,})(.*)$/D', $rest, $colon) === 1) {
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
            ($rest[0] === '`' || $rest[0] === '~') && preg_match('/^(`{3,}|~{3,})(.*)$/D', $rest, $fence) === 1
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
        if (preg_match('/^(%{3,})(.*)$/D', $rest, $comment) === 1) {
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
        if (preg_match('/^\[\^[^\]]+\]: +/', $rest, $note) === 1 && preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $rest) === 1) {
            return new OwnershipForm(kind: 'note', width: 2, head: strlen($note[0]), interrupts: true);
        }
        // T6: a continuation row right under a row of the owner's table is that table's line (carve#4161).
        if (
            ($rest[0] === '|' && $this->tables->isTableRow($rest))
            || ($rest[0] === '+' && $owner->tableLine === $this->at - 1 && $this->tables->isContinuationRow($rest))
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
        if (preg_match(BlockGrammar::DEFINITION_TERM_LINE_PATTERN, $rest) === 1) {
            return new OwnershipForm(kind: 'term', interrupts: true);
        }
        if (preg_match('/^:( +)(?=[ \t]*[^ \t])/', $rest, $dd) === 1 && ($owner->last === 'term' || $owner->last === 'dd' || $owner === $this->ddSiblingOwner)) {
            $w = 1 + strlen($dd[1]);

            return new OwnershipForm(kind: 'dd', width: $w, head: $w, interrupts: false);
        }
        if (
            preg_match('/^(#{1,6}) +(?=.*[^ \t\n\r])(.*)$/D', $rest) === 1
            || preg_match(self::THEMATIC_BREAK, $rest) === 1
            || ($this->isDefinition)($rest)
            || (preg_match('/^\*\[([A-Za-z0-9]+)\]: +(?![ \t]*$)([^ ].*)$/D', $rest) === 1)
            || ($rest[0] === '{' && (($this->isAttributeLine)($rest) || $this->opensWrappedAttribute($rest, $owner)))
        ) {
            $this->softLeaf = $owner->kind === 'doc' && $owner->para && str_starts_with($rest, '*[')
                && preg_match('/^\*\[([A-Za-z0-9]+)\]: +(?![ \t]*$)([^ ].*)$/D', $rest) === 1;

            return new OwnershipForm(kind: 'leaf', interrupts: true);
        }
        if (preg_match('/^\^ +(?=.*[^ \t\n\r\f])(.*?)[ \t]*$/D', $rest) === 1) {
            // A caret line with no host above it is paragraph text (PART 9 §10 I7).
            if ($owner->last === null && $owner->kind !== 'dd') {
                return new OwnershipForm(kind: 'text', interrupts: false);
            }
            $this->softLeaf = in_array($owner->last, [null, 'para', 'term'], true);

            return new OwnershipForm(kind: 'leaf', interrupts: false, caption: true);
        }

        return new OwnershipForm(kind: 'text', interrupts: false);
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
            $rest = $this->throughQuotes($this->lines[$j], $hostIndex, $frames)['rest'] ?? '';
            if (trim($rest, " \t") === '') {
                break;
            }
            $run[] = $rest;
        }
        $end = ($this->commentOnlyParagraphEnd)($run, 0, $frames[$hostIndex]->kind === 'item');

        return $end === -1 ? -1 : $k + $end;
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
        while ($held['item'] && $last > $held['from'] && trim($this->lines[$last - 1]) === '') {
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

    private function consumedLeavesNoParagraph(int $frame): bool
    {
        $kind = $this->frames[$frame]->kind;
        $parent = $this->frames[$frame - 1]->kind ?? 'doc';

        return !$this->framesHoldNote() && ($kind === 'item' || $kind === 'quote' || ($kind === 'dd' && $parent === 'item'));
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
}
