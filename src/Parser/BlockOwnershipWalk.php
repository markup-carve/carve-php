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
     * Lines the walk opened a code fence on, keyed by line index.
     *
     * @var array<int, true>
     */
    public array $fenceOpenerLines = [];

    /**
     * Refused `+` lines the walk framed lazy, as they read unframed: the marker classifier reads these.
     *
     * @var array<int, string>
     */
    public array $refusedPlusLines = [];

    /**
     * Quote first-block `+` lines whose follower stands at an ineligible raw column, keyed by line index.
     *
     * @var array<int, true>
     */
    public array $refusedPlus = [];

    /**
     * @var array{widest: array<string, array<int, int>>, lastComment: array<int, int>}|null
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
    ) {
    }

    /**
     * @param array<int, string> $lines
     *
     * @return array<int, string>
     */
    public function own(array $lines): array
    {
        $this->lines = $lines;
        $this->runs = null;
        $this->textFences = [];
        $this->closingLines = [];
        $this->softLeaf = false;
        $this->reach = 0;
        $this->lazyLines = [];
        $this->fenceOpenerLines = [];
        $this->refusedPlusLines = [];
        $this->refusedPlus = [];
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

                    break;
                }
            }
        }
        for ($at = $start; $at < $count; $at++) {
            $afterSoftLeaf = $this->softLeaf;
            $this->softLeaf = false;
            $this->at = $at;
            $this->src = $lines[$at];
            $this->out = '';
            $this->m = self::indentCols($this->src);
            $reached = 0;
            $plus = null;
            $refusedPlus = false;
            $frameCount = count($this->frames);
            // A quote's first-block `+` reads a follower by its raw column (CARVE-P9-067).
            $topFrame = $this->frames[$frameCount - 1];
            $quotePlus = $topFrame->kind === 'quote' && $topFrame->plus === $at - 1 ? $topFrame : null;
            $baseAt = -1;
            if ($quotePlus !== null) {
                foreach ($this->frames as $k => $f) {
                    if ($f->line === $at - 1) {
                        $baseAt = $k;

                        break;
                    }
                }
            }
            $stackCol = null;
            // Column 0 of the follower's own quote level, past host quote prefixes.
            $zero = 0;
            // S2: the deepest open frame the line reaches.
            for ($k = 1; $k < $frameCount; $k++) {
                $f = $this->frames[$k];
                if ($quotePlus !== null && $stackCol === null && $f->line === $at - 1) {
                    $stackCol = -1;
                }
                if ($f->markerCol !== null && preg_match('/^\+[ \t]*$/D', $this->m['rest']) === 1 && $this->m['col'] === $f->markerCol) {
                    if ($f->kind === 'quote' || ($this->m['col'] < $f->col && $this->attachesAt($at + 1, $f->markerCol, $this->quoteDepth($k)))) {
                        $plus = $k;

                        break;
                    }
                    $refusedPlus = $refusedPlus || $this->m['col'] < $f->col;
                }
                if ($f->kind === 'quote') {
                    if (preg_match(self::QUOTE, $this->m['rest'], $q) !== 1) {
                        break;
                    }
                    if ($stackCol === -1) {
                        $stackCol = self::docColumn($lines[$at], $this->m['rest']);
                    }
                    $this->lead($this->frames[$k - 1]->canon);
                    $this->take(strlen($this->src) - strlen($q[1] ?? ''));
                    if ($k < $baseAt) {
                        $zero = self::docColumn($lines[$at], $this->src);
                    }
                    $this->m = self::indentCols($this->src);
                    $reached = $k;

                    continue;
                }
                if ($this->m['rest'] !== '' && $this->m['col'] < $f->col) {
                    break;
                }
                $reached = $k;
            }
            if ($plus !== null) {
                $this->frames = array_slice($this->frames, 0, $plus + 1);
                $this->frames[$plus]->para = false;
                $this->lead($this->frames[$plus]->markerCanon);
                $result[] = $this->out . $this->src;
                $afterPlus = true;
                // S2: a lone `+` taken at a marker column (B-plus-start).
                if ($this->trace !== null) {
                    $this->emit($at, 'S2', $this->ownerPath(), 'CARVE-P9-067', 'plus', ['cell' => 'B-plus-start']);
                }

                continue;
            }
            $plusFollower = $afterPlus;
            $afterPlus = false;
            $missed = $reached < count($this->frames) - 1;
            // A follower at an ineligible raw column refuses the `+` whatever block it starts (CARVE-P9-067, carve#3421).
            $stacked = $stackCol !== null && $stackCol >= 0;
            if (
                $quotePlus !== null && ($missed || $stacked) && $this->m['rest'] !== ''
                && preg_match('/^\+[ \t]*$/D', $this->m['rest']) !== 1
            ) {
                $col = $stacked ? $stackCol : self::docColumn($lines[$at], $this->m['rest']);
                if ($col !== 0 && $col !== $zero && $col !== $this->frames[$baseAt]->docMarker && $col !== $quotePlus->docMarker) {
                    $this->refusedPlus[$at - 1] = true;
                }
            }
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
                }
                $result[] = $this->out . $this->src;
                if ($this->trace !== null) {
                    $this->emit($at, 'S1', $payloadPath, 'CARVE-P0-005', $payloadForm, [
                        'cell' => 'B-' . $inner->family . '-' . ($payloadForm === 'closer' ? 'end' : 'keep'),
                    ]);
                }

                continue;
            }
            if ($inner->kind === 'fence') {
                array_pop($this->frames);
            }
            // S3: the line's form, the record's `form` field.
            $form = $this->classify($this->m['rest'], $this->frames[$reached], $this->m['col']);
            $topIndex = count($this->frames) - 1;
            $next = $this->frames[$reached + 1] ?? null;
            $missedNote = false;
            $missedItem = false;
            for ($k = $reached + 1; $k <= $topIndex; $k++) {
                $missedNote = $missedNote || $this->frames[$k]->kind === 'note';
                $missedItem = $missedItem || $this->frames[$k]->kind === 'item';
            }
            $ownerKind = $this->frames[$reached]->kind;
            $lazy = $missed && $next !== null && $this->m['rest'] !== '' && $this->frames[$topIndex]->para && !$form->interrupts
                && !($form->kind === 'item' && $next->kind === 'item' && $next->axes === $form->axes);
            if ($lazy && $missedNote && $form->kind === 'item' && ($ownerKind === 'item' || $ownerKind === 'dd' || $missedItem)) {
                // The marker opens past the missed note, and the readers still see the note's lazy line.
                $this->lazyLines[$at] = true;
                $lazy = false;
            }
            // S4: a line that missed a frame folds into the deepest open paragraph.
            if ($lazy) {
                // Past a missed note the line is framed lazy in the deepest container (carve#3926).
                if ($missedNote && $this->frames[$topIndex]->plus !== $at - 1) {
                    if ($refusedPlus) {
                        $owner = $this->frames[$reached];
                        $limit = $next->kind === 'quote' ? PHP_INT_MAX : $next->canon - 1;
                        $col = min(min(max($this->m['col'] - $owner->cut, $owner->canon), $limit), $this->m['col']);
                        $authored = substr($this->src, 0, strlen($this->src) - strlen($this->m['rest']));
                        $this->refusedPlusLines[$at] = $this->out . ($col === $this->m['col'] ? $authored : str_repeat(' ', $col)) . $this->m['rest'];
                    }
                    $this->lead(0);
                    for ($k = $reached + 1; $k <= $topIndex; $k++) {
                        if ($this->frames[$k]->kind === 'quote') {
                            $this->out .= str_repeat(' ', $this->frames[$k - 1]->reader ?? $this->frames[$k - 1]->canon) . '> ';
                        }
                    }
                    $leaf = $this->frames[$topIndex];
                    $result[] = $this->out . str_repeat(' ', $leaf->kind === 'quote' ? 0 : (int)$leaf->reader) . BlockGrammar::LAZY_FRAME . $this->src;
                    if (!$refusedPlus) {
                        $this->lazyLines[$at] = true;
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
                // A `+` the walk refuses at a marker column stays for the readers to refuse.
                if (!$refusedPlus && !$this->plusMeetsMovedItem($reached)) {
                    $this->lazyLines[$at] = true;
                }
                $this->emitLazy($at, $reached);

                continue;
            }
            if (
                $missed && !$plusFollower && $this->m['rest'] !== '' && !$afterSoftLeaf
                && $inner->kind !== 'fence' && !$this->frames[$topIndex]->para
            ) {
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
            $result[] = $this->out . $this->src;
            if ($this->trace !== null) {
                $this->emitApplied($at, $form, $closerPath ?? $this->ownerPath(), $closed, $reachedPath, $plusFollower);
            }
        }

        return $this->frameTextFences($result);
    }

    private function emitLazy(int $at, int $reached): void
    {
        if ($this->trace !== null) {
            $this->emit($at, 'S4', $this->ownerPath(), 'CARVE-P0-006', 'lazy', ['reached' => $this->ownerPath($reached)]);
        }
    }

    /**
     * A lone `+` at the column an indented item's marker was moved to reaches that item (PART 0 S3).
     */
    private function plusMeetsMovedItem(int $reached): bool
    {
        if (preg_match('/^\+[ \t]*$/D', $this->m['rest']) !== 1) {
            return false;
        }
        $count = count($this->frames);
        for ($k = $reached + 1; $k < $count; $k++) {
            $f = $this->frames[$k];
            if ($f->kind === 'quote') {
                return false;
            }
            if ($f->kind === 'item' && $f->markerCol !== null && $f->markerCanon === $this->m['col'] && $f->markerCol > $this->m['col']) {
                return true;
            }
        }

        return false;
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
        $this->frames[$owner]->para = $form->kind === 'term';
        $this->frames[$owner]->last = $form->kind;
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
            );
            preg_match(self::QUOTE, $this->src, $q);
            $this->take(strlen($this->src) - strlen($q[1] ?? ''));
            $this->m = self::indentCols($this->src);
            $this->content(count($this->frames) - 1, 0, 0);

            return;
        }
        if ($form->kind === 'item' || $form->kind === 'note' || $form->kind === 'dd') {
            $this->frames[] = new OwnershipFrame(
                $form->kind,
                $col + $form->width,
                $canon + $form->width,
                $col - $canon,
                markerCol: $col,
                markerCanon: $canon,
                axes: $form->axes,
                reader: $reader + $form->width,
                docMarker: self::docColumn($this->lines[$this->at], $this->src),
                line: $this->at,
            );
            $this->take($form->head);
            $this->m = self::indentCols($this->src);
            $this->content(count($this->frames) - 1, $col + $form->head, $canon + $form->head);
        }
    }

    private function content(int $frame, int $start, int $canon): void
    {
        if ($this->m['rest'] === '' || count($this->frames) > BlockGrammar::MAX_NESTING_DEPTH) {
            return;
        }
        $form = $this->classify($this->m['rest'], $this->frames[$frame], $this->frames[$frame]->kind === 'quote' ? $this->m['col'] : $start + $this->m['col']);
        if ($form->kind === 'text') {
            $this->lead($this->m['col']);
            $this->frames[$frame]->absorbs = $this->absorbsColon($this->m['rest']);
            $this->frames[$frame]->para = true;
            if (preg_match('/^\+[ \t]*$/D', $this->m['rest']) === 1) {
                $this->frames[$frame]->plus = $this->at;
            }
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
                        closes: static fn (string $r, int $closerCol): bool => preg_match('/^(:{3,})[ \t]*$/D', $r, $c) === 1 && strlen($c[1]) === $run,
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
        $marker = $this->listParser->parseListItemMarker($rest);
        if ($marker !== null) {
            // A term is not a paragraph a marker continues.
            if ($owner->para && $owner->kind !== 'item' && $owner->last !== 'term') {
                return new OwnershipForm(kind: 'text', interrupts: false);
            }
            $head = $this->listParser->markerHeadAt($rest);
            if ($head !== null) {
                $width = $head['content'] - $head['attrs'];
                if (isset($marker['taskMarker'])) {
                    $width = strlen((string)$marker['marker']) + 1;
                }

                return new OwnershipForm(
                    kind: 'item',
                    width: $width,
                    head: $head['content'],
                    interrupts: false,
                    axes: $marker['type'] === 'ordered'
                        ? substr((string)$marker['marker'], -1)
                    : substr((string)$marker['marker'], 0, 1),
                );
            }
        }
        if (preg_match(BlockGrammar::DEFINITION_TERM_LINE_PATTERN, $rest) === 1) {
            return new OwnershipForm(kind: 'term', interrupts: true);
        }
        if (preg_match('/^:( +)(?=[ \t]*[^ \t])/', $rest, $dd) === 1 && ($owner->last === 'term' || $owner->last === 'dd')) {
            $w = 1 + strlen($dd[1]);

            return new OwnershipForm(kind: 'dd', width: $w, head: $w, interrupts: false);
        }
        if (
            preg_match('/^(#{1,6}) +(?=.*[^ \t\n\r\f])(.*)$/D', $rest) === 1
            || preg_match('/^(-{3,}|\*{3,}|_{3,})[ \t]*$/D', $rest) === 1
            || ($rest[0] === '|' && $this->tables->isTableRow($rest))
            || ($this->isDefinition)($rest)
            || preg_match('/^\*\[([A-Za-z0-9]+)\]: +(?![ \t]*$)([^ ].*)$/D', $rest) === 1
            || ($rest[0] === '{' && ($this->isAttributeLine)($rest))
        ) {
            $this->softLeaf = $owner->para && str_starts_with($rest, '*[') && preg_match('/^\*\[([A-Za-z0-9]+)\]: +(?![ \t]*$)([^ ].*)$/D', $rest) === 1;

            return new OwnershipForm(kind: 'leaf', interrupts: true);
        }
        if (preg_match('/^\^ +(?=.*[^ \t\n\r\f])(.*?)[ \t]*$/D', $rest) === 1) {
            $this->softLeaf = in_array($owner->last, [null, 'para', 'term'], true);

            return new OwnershipForm(kind: 'leaf', interrupts: false);
        }

        return new OwnershipForm(kind: 'text', interrupts: false);
    }

    /**
     * @return array{widest: array<string, array<int, int>>, lastComment: array<int, int>}
     */
    private function closerRuns(): array
    {
        if ($this->runs !== null) {
            return $this->runs;
        }
        $n = count($this->lines);
        $widest = ['`' => array_fill(0, $n + 1, 0), '~' => array_fill(0, $n + 1, 0)];
        $lastComment = [];
        for ($j = $n - 1; $j >= 0; $j--) {
            $rest = self::indentCols($this->lines[$j])['rest'];
            while (preg_match(self::QUOTE, $rest, $q) === 1) {
                $rest = self::indentCols($q[1] ?? '')['rest'];
            }
            $widest['`'][$j] = $widest['`'][$j + 1];
            $widest['~'][$j] = $widest['~'][$j + 1];
            if (preg_match('/^(`{3,}|~{3,})[ \t]*$/D', $rest, $c) === 1) {
                $widest[$c[1][0]][$j] = max($widest[$c[1][0]][$j], strlen($c[1]));
            }
            if (preg_match('/^(%{3,})/', $rest, $c) === 1 && !isset($lastComment[strlen($c[1])])) {
                $lastComment[strlen($c[1])] = $j;
            }
        }

        return $this->runs = ['widest' => $widest, 'lastComment' => $lastComment];
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
     * Whether line `$next` holds a block at `$col` under `$depth` quote levels.
     */
    private function attachesAt(int $next, int $col, int $depth): bool
    {
        if (!isset($this->lines[$next])) {
            return false;
        }
        $m = self::indentCols($this->lines[$next]);
        for ($d = 0; $d < $depth; $d++) {
            if (preg_match(self::QUOTE, $m['rest'], $q) !== 1) {
                return false;
            }
            $m = self::indentCols($q[1] ?? '');
        }

        return trim($m['rest']) !== '' && $m['col'] === $col;
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
