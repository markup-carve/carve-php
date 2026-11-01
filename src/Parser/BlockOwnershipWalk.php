<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use Closure;
use MarkupCarve\Carve\Parser\Block\FencedBlockParser;
use MarkupCarve\Carve\Parser\Block\ListParser;
use MarkupCarve\Carve\Parser\Block\TableParser;

/**
 * Block ownership, rule A (carve#3885): one walk moves every opener past its
 * owner's content column, and every line its block owns, onto that column.
 * Port of `ownLines()` in the spec model (carve#3887).
 */
final class BlockOwnershipWalk
{
    /**
     * @var string
     */
    private const QUOTE = '/^>(?: ([^\r\n]*)|[ \t]*$)/D';

    /**
     * @var string
     */
    private const DESCRIPTION_LEAD = '/^: +(?=[^ \t])/';

    /**
     * @var string
     */
    private const BARE_QUOTE_MARKERS = '/^(?:>[ \t]*)*$/D';

    /**
     * @var string
     */
    private const FRONTMATTER_OPENER = '/^---(?:[ \t]*$|(?! *[^\S ])( (?! )|[A-Za-z0-9]+[ \t]*$))/D';

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
     * Every lone `+` the one attach rule refused, keyed by line index (CARVE-P9-085): each is written as the letter.
     * The value is its lint finding, or null where the rule does not report it.
     *
     * @var array<int, array{line: int, column: int, host: string, reason: string}|null>
     */
    public array $refusedPlus = [];

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
     * @var array{host: \MarkupCarve\Carve\Parser\OwnershipFrame, at: int, end: int, hostIndex: int, fenced: array<int, bool>, noteBodies: array<int, true>, lead: string}|null
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

    private string $src = '';

    private string $out = '';

    /**
     * @var array{col: int, rest: string}
     */
    private array $m = ['col' => 0, 'rest' => ''];

    private int $at = 0;

    private int $wrappedAttributeEnd = -1;

    private bool $onMarkerLine = false;

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
        $this->lazyLines = [];
        $this->attachedPreludeLines = [];
        $this->lineBlockLazyLines = [];
        $this->fenceOpenerLines = [];
        $this->refusedPlus = [];
        $this->plusHosts = [];
        $this->ddSiblingOwner = null;
        $this->skipped = null;
        $this->attachedTo = null;
        $this->frames = [new OwnershipFrame('doc', 0, 0, 0)];
        $result = [];
        $afterPlus = false;
        $start = 0;
        $count = count($lines);
        if ($count > 0 && preg_match(self::FRONTMATTER_OPENER, $lines[0]) === 1) {
            for ($k = 1; $k < $count; $k++) {
                if (preg_match('/^---[ \t]*$/D', $lines[$k]) === 1) {
                    for ($j = 0; $j <= $k; $j++) {
                        $result[] = $lines[$j];
                        // INPUT: frontmatter, before ownership.
                        if ($this->trace !== null) {
                            $this->emit($j, 'INPUT', 'doc', null, 'frontmatter');
                        }
                    }
                    $start = $k + 1;
                    $this->frontmatter = true;

                    break;
                }
            }
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
                    if (
                        $this->attachedBlockEndsAt($at)
                        && $this->attachesAt($this->attachedTo['kind'], $at, self::docColumn($lines[$at], $stands['rest']), count($this->attachedTo['frames']) - 1, $this->attachedTo['frames'])
                    ) {
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
            $refusedHost = null;
            $inWrappedAttribute = $at <= $this->wrappedAttributeEnd;
            $frameCount = count($this->frames);
            // S2: the deepest open frame the line reaches.
            for ($k = 1; $k < $frameCount; $k++) {
                $f = $this->frames[$k];
                // A lone `+` reaches the marker column of an open marker container.
                if (
                    $f->markerCol !== null && $this->m['col'] === $f->markerCol && ($f->kind === 'quote' || $this->m['col'] < $f->col)
                    && self::isPlus($this->m['rest'])
                ) {
                    if ($this->attachesAt($f->kind, $at, self::docColumn($lines[$at], $this->m['rest']), $k)) {
                        $plus = $k;

                        break;
                    }
                    $refusedHost = $k;
                }
                if ($f->kind === 'quote') {
                    if (preg_match(self::QUOTE, $this->m['rest'], $q) !== 1) {
                        break;
                    }
                    $this->lead($this->frames[$k - 1]->canon);
                    $this->take(strlen($this->src) - strlen($q[1] ?? ''));
                    $this->m = self::indentCols($this->src);
                    $reached = $k;

                    continue;
                }
                if ($this->m['rest'] !== '' && $this->m['col'] < $f->col && !$inWrappedAttribute) {
                    break;
                }
                $reached = $k;
            }
            if ($inWrappedAttribute) {
                $result[] = $this->out . str_repeat(' ', $this->frames[$reached]->canon) . $this->m['rest'];

                continue;
            }
            // A lone `+` nothing took is a plain letter and ends nothing (carve#4396). Payload stays as written.
            if (
                $plus === null && self::isPlus($this->m['rest'])
                && !($this->frames[$frameCount - 1]->kind === 'fence' && $reached === $frameCount - 1)
            ) {
                $this->refusePlus($at, $this->separatePlusFinding($at, $refusedHost, $reached));
            }
            if ($plus !== null) {
                $this->frames = array_slice($this->frames, 0, $plus + 1);
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
            $this->reach = $reached;
            $innerIndex = count($this->frames) - 1;
            $inner = $this->frames[$innerIndex];
            if ($inner->kind === 'fence' && !$missed) {
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
                    }
                } elseif ($rest !== '' && $inner->closes !== null && ($inner->closes)($rest, $this->m['col'])) {
                    array_pop($this->frames);
                    $this->frames[count($this->frames) - 1]->last = 'block';
                    $payloadForm = 'closer';
                } elseif ($inner->lineBlock) {
                    // A span in a line-block payload pairs past the column too (carve-php#3137).
                    if (
                        preg_match('/^(`{3,}|~{3,})(.*)$/D', $rest, $f) === 1
                        && ($this->fenced->parseCodeFenceOpener($rest) !== null || $this->fenced->parseRawBlockOpener($rest) !== null)
                        && ($this->closerRuns()['widest'][$f[1][0]][$at + 1] ?? 0) >= strlen($f[1])
                    ) {
                        $this->frames[$innerIndex]->span = $f[1];
                    } elseif (preg_match('/^(%{3,})/', $rest, $c) === 1 && ($this->closerRuns()['lastComment'][strlen($c[1])] ?? -1) > $at) {
                        $this->frames[$innerIndex]->span = $c[1];
                    }
                    // A blank payload line ends the stanza, so no lazy line folds past it (PART 0 S4).
                    $this->frames[$innerIndex]->para = $rest !== '';
                }
                $result[] = $this->out . $this->src;
                if ($this->trace !== null) {
                    $this->emit($at, 'S1', $payloadPath, 'CARVE-P0-005', $payloadForm, [
                        'cell' => 'B-' . $inner->family . '-' . ($payloadForm === 'closer' ? 'end' : 'keep'),
                    ]);
                }

                continue;
            }
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
            $missedItem = false;
            for ($k = $reached + 1; $k <= $topIndex; $k++) {
                $missedNote = $missedNote || $this->frames[$k]->kind === 'note';
                $missedItem = $missedItem || $this->frames[$k]->kind === 'item';
            }
            // A `: ` description is a sibling at any column where its list's owner reads it (CARVE-P0-024 S3).
            $missedQuote = false;
            for ($k = $reached + 1; $k <= $topIndex; $k++) {
                $missedQuote = $missedQuote || $this->frames[$k]->kind === 'quote';
            }
            // PART 0 [B-term-keep]: past a missed quote or note a marker leaves a term, and past a quote a separator too (carve#4300).
            $termRefuses = $this->frames[$topIndex]->last === 'term' && ($this->listParser->parseListItemMarker($this->m['rest']) !== null
                ? $missedQuote || $missedNote
                : $missedQuote && preg_match(self::DESCRIPTION_LEAD, $this->m['rest']) === 1);
            // That separator ends the quote; a container around it that the line also missed keeps it as lazy text (carve#4432).
            if ($termRefuses && $this->listParser->parseListItemMarker($this->m['rest']) === null && $next?->kind !== 'quote' && !$missedNote) {
                for ($k = $topIndex; $this->frames[$k]->kind !== 'quote'; $k--) {
                }
                array_splice($this->frames, $k);
                $topIndex = count($this->frames) - 1;
                $this->frames[$topIndex]->para = true;
                $this->frames[$topIndex]->last = 'para';
                $termRefuses = false;
            }
            // A caption at its owner's block column attaches to a quote it follows and is never its lazy line (carve#4312).
            $captionsQuote = $form->caption && $this->m['col'] === $this->frames[$reached]->col && $next?->kind === 'quote' && !$missedNote;
            if ($captionsQuote) {
                $this->softLeaf = true;
            }
            $lazy = $missed && $next !== null && $this->m['rest'] !== '' && $this->frames[$topIndex]->para && !$form->interrupts && !$captionsQuote && !$termRefuses && $form->kind !== 'dd'
                // A marker at the missed list's column or at or left of the owner's content column opens a list of its own (S4, carve#4369).
                && !($form->kind === 'item' && $next->kind === 'item' && (
                    ($next->marker !== null && $form->marker !== null && $this->listParser->itemMatchesList($next->marker, $form->marker))
                    || $this->m['col'] === min($next->listCol ?? (int)$next->markerCol, (int)$next->markerCol)
                    || $this->m['col'] <= $this->frames[$reached]->col
                ))
                // A marker that misses a description but reaches the item holding it opens there (S3, carve#4479).
                && !($form->kind === 'item' && $next->kind === 'dd' && $this->frames[$reached]->kind === 'item' && $this->frames[$topIndex]->kind === 'item');
            // Past a missed note a marker opens only beside a missed list (carve#4160).
            // A marker between a missed item's columns that differs on an axis still folds into the note.
            if ($lazy && $missedNote && $form->kind === 'item' && $missedItem && !$this->foldsBetweenMissedItemColumns($reached, $form)) {
                // A quote's readers still see the note's lazy line.
                if ($next->kind === 'quote') {
                    $this->lazyLines[$at] = true;
                }
                $lazy = false;
            }
            // S4: a line that missed a frame folds into the deepest open paragraph.
            if ($lazy) {
                // A malformed colon opener is paragraph text even when it arrived lazily.
                // Its next bare colon line is absorbed only at this paragraph's depth.
                if ($this->absorbsColon($this->m['rest'])) {
                    $this->frames[$topIndex]->absorbs = true;
                }
                // A separator line the quote still marks is text, so the term is over for a later line.
                if ($this->frames[$topIndex]->last === 'term' && preg_match(self::DESCRIPTION_LEAD, $this->m['rest']) === 1) {
                    $this->frames[$topIndex]->last = 'para';
                }
                // The readers open a list at a folded marker (§10 I2), so a `+` there is decided too.
                if ($form->kind === 'item') {
                    $this->decideFoldedMarkerPlus($at, $reached + 1);
                }
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
                && ($lonePlus->kind === 'item' || (($lonePlus->kind === 'dd' || $lonePlus->kind === 'note') && $lonePlus->plus === $at - 1))
                && !self::isPlus($this->m['rest'])
            ) {
                $this->lead($lonePlus->markerCanon);
                $result[] = $this->out . $this->src;
                $lonePlus->absorbs = $this->absorbsColon($this->m['rest']);
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
                    || ($inner->family === 'comment' && $reached === 0 && $innerIndex === 2
                        && in_array($this->frames[1]->kind, ['quote', 'dd', 'note'], true)
                        && $this->commentCloserBeforeBlank($at, $inner)))
                && (!$this->frames[$topIndex]->para || ($termRefuses && $next?->kind !== 'item') || ($form->kind === 'leaf' && $form->interrupts
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
            $this->frames = array_slice($this->frames, 0, $reached + 1);
            $o = $reached;
            if ($this->m['rest'] === '') {
                $this->frames[$o]->para = false;
                $this->lead($this->m['col']);
            } elseif ($form->kind === 'colon-close') {
                $this->lead($this->frames[$form->frame]->canon);
                $closerPath = $this->trace !== null ? $this->ownerPath($form->frame) : null;
                $this->frames = array_slice($this->frames, 0, $form->frame);
                $t = count($this->frames) - 1;
                $this->frames[$t]->para = false;
                $this->frames[$t]->last = 'block';
            } elseif ($form->kind === 'text') {
                $owner = $this->frames[$o];
                $term = $owner->para && $owner->last === 'term';
                $this->lead($owner->para || !$closedColumn || $plusFollower
                    ? max($this->m['col'] - ($term ? $owner->termCut : $owner->cut), $owner->canon)
                    : $owner->canon, $term);
                if ($owner->para && preg_match('/^(`{3,}|~{3,})/', $this->m['rest']) === 1) {
                    $this->textFences[count($result)] = $owner->canon;
                }
                $this->frames[$o]->absorbs = ($owner->para && $owner->absorbs) || $this->absorbsColon($this->m['rest']);
                $this->frames[$o]->para = true;
                if (!$term) {
                    $this->frames[$o]->last = 'para';
                }
            } else {
                $this->lead($this->frames[$o]->canon, $form->kind === 'term' || $form->kind === 'dd');
                $this->apply($o, $this->m['col'], $form, $this->frames[$o]->canon);
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
        $result[] = $skipped['lead'] . (($skipped['fenced'][$at] ?? false)
            ? '%%'
            : ($this->throughQuotes($this->lines[$at], $skipped['hostIndex'], $this->frames)['rest'] ?? ''));
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

    private function apply(int $owner, int $col, OwnershipForm $form, int $canon, ?int $reader = null): void
    {
        $reader ??= $this->frames[$owner]->reader ?? $this->frames[$owner]->canon;
        $this->frames[$owner]->para = $form->kind === 'term' || $form->caption;
        $this->frames[$owner]->last = $form->kind;
        $this->frames[$owner]->tableLine = $form->table ? $this->at : null;
        if ($form->kind === 'term') {
            $this->frames[$owner]->termCut = $col - $canon;
        }
        $o = $this->frames[$owner];
        if ($form->kind === 'fence' || $form->kind === 'colon') {
            if ($form->kind === 'fence' && !$form->lineBlock) {
                $this->fenceOpenerLines[$this->at] = true;
            }
            $this->frames[] = new OwnershipFrame(
                $form->kind,
                $o->col,
                $o->canon,
                $col - $canon,
                closes: $form->closes,
                run: $form->run,
                lineBlock: $form->lineBlock,
                reader: $reader,
                family: $form->family ?? 'div',
                opened: $this->at,
            );

            return;
        }
        if ($form->kind === 'quote') {
            $this->frames[] = new OwnershipFrame(
                'quote',
                0,
                0,
                0,
                markerCol: $col,
                markerCanon: $canon,
                docMarker: self::docColumn($this->lines[$this->at], $this->src),
                line: $this->at,
                written: self::columnOf($this->out),
            );
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
            $this->frames[] = new OwnershipFrame(
                $form->kind,
                $col + $form->width,
                $canon + $form->width,
                $col - $canon,
                markerCol: $col,
                markerCanon: $canon,
                axes: $form->axes,
                marker: $form->marker,
                listCol: $listCol,
                task: $form->task,
                reader: $reader + $form->width,
                docMarker: self::docColumn($this->lines[$this->at], $this->src),
                line: $this->at,
                written: self::columnOf($this->out),
            );
            // Content behind a lead tab is paragraph text, whatever it spells (corpus 684, 736, 840).
            $leadTab = str_contains(substr($this->src, 0, $form->head), "\t")
                || ($this->src[$form->head] ?? '') === "\t";
            $this->take($form->head);
            $this->m = self::indentCols($this->src);
            $this->content(count($this->frames) - 1, $col + $form->head, $canon + $form->head, $leadTab);
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
                } else {
                    $this->refusePlus($this->at, $f->kind === 'quote' && $this->m['col'] > 0
                        ? null
                        : $this->plusFinding($this->at, $f->kind, (int)$f->docMarker, $frame));
                }
            }
            $this->lead($this->m['col']);
            if ($plus) {
                $this->plusHosts[$this->at] = $this->hostRecord($frame, self::columnOf($this->out), true);
            }
            $this->frames[$frame]->absorbs = $this->absorbsColon($this->m['rest']);
            $this->frames[$frame]->para = !$plus;
            $this->frames[$frame]->last = 'para';

            return;
        }
        if ($this->frames[$frame]->kind === 'quote') {
            $col = $this->m['col'];
            $this->lead(0);
            $this->apply($frame, $col, $form, 0);

            return;
        }
        $col = $start + $this->m['col'];
        $shift = $this->m['col'];
        $this->lead($shift);
        $f = $this->frames[$frame];
        $this->apply($frame, $col, $form, $canon + $shift, $f->kind === 'note' ? $f->reader : $f->reader + $canon + $shift - $f->canon);
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

    private function absorbsColon(string $rest): bool
    {
        $rest = rtrim($rest, " \t");

        return preg_match('/^:{3,}/', $rest) === 1 && $this->fenced->parseDivFenceOpener($rest) === null;
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
            if (preg_match('/\}[ \t]*$/D', $line) === 1) {
                break;
            }
            if (trim($line, " \t") === '' || ltrim($line, " \t")[0] === '{') {
                return false;
            }
        }
        $length = count($run);
        if ($length < 2 || ($this->wrappedAttributeLength)($run) !== $length) {
            return false;
        }
        $this->wrappedAttributeEnd = $this->at + $length - 1;

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
                // A bare `:::` interrupts unless the paragraph's own lines absorbed an
                // invalid opener (§10 I4, CARVE-P9-016, CARVE-P9-017).
                $interrupts = preg_match('/^(:{3,})[ \t]*$/D', $rest) !== 1
                    || !($owner === $this->frames[count($this->frames) - 1] && $owner->para && $owner->absorbs);
                if ($owner->para && !$interrupts) {
                    return new OwnershipForm(kind: 'text', interrupts: false);
                }
                if ($opener['className'] === '|') {
                    return new OwnershipForm(
                        kind: 'fence',
                        interrupts: $interrupts,
                        lineBlock: true,
                        family: 'div',
                        closes: static fn (string $r, int $closerCol): bool => $closerCol <= $col
                            && preg_match('/^(:{3,})[ \t]*$/D', $r, $c) === 1 && strlen($c[1]) === $run,
                    );
                }

                return new OwnershipForm(kind: 'colon', run: $run, interrupts: $interrupts);
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
            if (($this->closerRuns()['lastComment'][$run] ?? -1) > $this->at) {
                return new OwnershipForm(
                    kind: 'fence',
                    interrupts: true,
                    closes: static fn (string $r, int $closerCol): bool => preg_match('/^(%{3,})/', $r, $c) === 1 && strlen($c[1]) === $run,
                    family: 'comment',
                );
            }
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
            if ($owner->para && $owner->kind !== 'item' && $owner->last !== 'term') {
                return new OwnershipForm(kind: 'text', interrupts: false);
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
                    marker: $marker,
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
            || preg_match('/^(-{3,}|\*{3,}|_{3,})[ \t]*$/D', $rest) === 1
            || ($this->isDefinition)($rest)
            // An abbreviation-shaped line is visible paragraph text in every container (CARVE-P12-026).
            || ($owner->kind === 'doc' && preg_match('/^\*\[([A-Za-z0-9]+)\]: +(?![ \t]*$)([^ ].*)$/D', $rest) === 1)
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
     */
    private function commentCloserBeforeBlank(int $from, OwnershipFrame $fence): bool
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

        return isset($positions[$lo]) && $positions[$lo] < ($runs['nextBlank'][$from + 1] ?? count($this->lines));
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
        $line = $this->lines[$this->firstVisibleLine($at + 1, $hostIndex, $column, $frames ?? $this->frames)] ?? null;
        if ($line === null) {
            return false;
        }
        $m = $this->throughQuotes($line, $hostIndex, $frames ?? $this->frames);
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
     * The first line past the invisible lines from `$from` (CARVE-P9-013, carve#4449). Off the
     * marker column an invisible line is that next line, and a note definition's body is its own.
     *
     * @param int $from
     * @param int $hostIndex
     * @param int $column
     * @param array<int, \MarkupCarve\Carve\Parser\OwnershipFrame> $frames
     * @param array<int, bool> $fenced Collects the lines of a skipped comment fence (true) and each skipped note definition (false).
     * @param array<int, true> $noteBodies Folded plain-text lines of skipped note definitions.
     */
    private function firstVisibleLine(int $from, int $hostIndex, int $column, array $frames, array &$fenced = [], array &$noteBodies = []): int
    {
        $n = count($this->lines);
        $view = fn (int $k): string => $this->throughQuotes($this->lines[$k], $hostIndex, $frames)['rest'] ?? "\u{E000}";
        $k = $from;
        while ($k < $n) {
            $rest = rtrim($view($k), " \t");
            if (self::docColumn($this->lines[$k], $view($k)) !== $column) {
                return $k;
            }
            if (preg_match('/^(%{3,})/', $rest, $c) === 1 && ($this->closerRuns()['lastComment'][strlen($c[1])] ?? -1) > $k) {
                $closer = '/^%{' . strlen($c[1]) . '}(?!%)/';
                $opener = $k++;
                while ($k < $n && preg_match($closer, $view($k)) !== 1) {
                    $k++;
                }
                $k++;
                for ($j = $opener; $j < min($k, $n); $j++) {
                    $fenced[$j] = true;
                }
            } elseif (preg_match(BlockGrammar::FOOTNOTE_DEFINITION_PATTERN, $rest) === 1) {
                $fenced[$k] = false;
                $k++;
                while ($k < $n && $this->foldsAsPlainText(rtrim($view($k), " \t"))) {
                    $noteBodies[$k] = true;
                    $k++;
                }
            } elseif (preg_match('/^%%/', $rest) === 1 || ($this->isDefinition)($rest)) {
                $k++;
            } elseif ($rest !== '' && $rest[0] === '{' && ($this->isAttributeLine)($rest)) {
                $k++;
            } elseif ($rest !== '' && $rest[0] === '{') {
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
    private function skipInvisible(int $hostIndex, int $at, int $column): void
    {
        $fenced = [];
        $noteBodies = [];
        $end = $this->firstVisibleLine($at + 1, $hostIndex, $column, $this->frames, $fenced, $noteBodies);
        if ($end === $at + 1 || !isset($this->lines[$end])) {
            return;
        }
        if (in_array(false, $fenced, true)) {
            for ($j = $end; isset($this->lines[$j]) && preg_match('/^: +(?=[^ \t])/', ltrim($this->lines[$j], " \t")) === 1; $j++) {
                $this->lazyLines[$j] = true;
                $this->attachedPreludeLines[$j] = true;
            }
        }
        $raw = $this->throughQuotes($this->lines[$end], $hostIndex, $this->frames, true)['rest'] ?? '';
        $this->skipped = [
            'host' => $this->frames[$hostIndex],
            'at' => $at,
            'end' => $end,
            'hostIndex' => $hostIndex,
            'fenced' => $fenced,
            'noteBodies' => $noteBodies,
            'lead' => substr($this->lines[$end], 0, strlen($this->lines[$end]) - strlen($raw)) . str_repeat(' ', $this->frames[$hostIndex]->markerCanon),
        ];
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
     * The `+` closing a marker chain on a line the walk folds stays for the readers only if it attaches.
     */
    private function foldsBetweenMissedItemColumns(int $reached, OwnershipForm $form): bool
    {
        $col = $this->m['col'];
        for ($k = $reached + 1, $top = count($this->frames); $k < $top; $k++) {
            $frame = $this->frames[$k];
            if (
                $frame->kind === 'item' && $frame->markerCol !== null && $col > $frame->markerCol && $col < $frame->col
                && !($frame->marker !== null && $form->marker !== null && $this->listParser->itemMatchesList($frame->marker, $form->marker))
            ) {
                return true;
            }
        }

        return false;
    }

    private function decideFoldedMarkerPlus(int $at, int $hostIndex): void
    {
        $tail = $this->m['rest'];
        $column = self::docColumn($this->lines[$at], $tail);
        for ($guard = 0; $guard <= BlockGrammar::MAX_NESTING_DEPTH; $guard++) {
            $head = $this->listParser->markerHeadAt($tail);
            if ($head === null) {
                return;
            }
            $body = (string)substr($tail, $head['content']);
            $inner = self::indentCols($body);
            $column = self::docColumn($this->lines[$at], $inner['rest']);
            if (self::isPlus($inner['rest'])) {
                $marker = $column - (strlen($tail) - strlen($inner['rest']));
                if (!$this->attachesAt('item', $at, $marker, $hostIndex)) {
                    $this->src = (string)substr($this->src, 0, strlen($this->src) - strlen($inner['rest'])) . $this->letter . (string)substr($inner['rest'], 1);
                    $this->m = self::indentCols($this->src);
                    $this->refusedPlus[$at] = $this->plusFinding($at, 'item', $marker, $hostIndex);
                }

                return;
            }
            $tail = $inner['rest'];
        }
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
    }

    /**
     * A plus sign with no frame at its marker column is a letter under PART 0 S2.
     *
     * @return array{line: int, column: int, host: string, reason: string}|null
     */
    private function separatePlusFinding(int $at, ?int $host, int $reached): ?array
    {
        if ($host !== null) {
            return $this->plusFinding($at, $this->frames[$host]->kind, self::docColumn($this->lines[$at], $this->m['rest']), $host);
        }
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
        $next = $this->lines[$at + 1] ?? null;
        if ($next === null || ($next === '' && $at + 2 === $count)) {
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
}
