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
     * @var array{widest: array<string, array<int, int>>, lastComment: array<int, int>}|null
     */
    private ?array $runs = null;

    /**
     * @var array<string, array<int, int>>
     */
    private array $widestByColumn = [];

    private string $src = '';

    private string $out = '';

    /**
     * @var array{col: int, rest: string}
     */
    private array $m = ['col' => 0, 'rest' => ''];

    private int $at = 0;

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
        $this->widestByColumn = [];
        $this->reach = 0;
        $this->lazyLines = [];
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
                    }
                    $start = $k + 1;

                    break;
                }
            }
        }
        for ($at = $start; $at < $count; $at++) {
            $this->at = $at;
            $this->src = $lines[$at];
            $this->out = '';
            $this->m = self::indentCols($this->src);
            $reached = 0;
            $plus = null;
            $frameCount = count($this->frames);
            for ($k = 1; $k < $frameCount; $k++) {
                $f = $this->frames[$k];
                if (
                    $f->markerCol !== null && preg_match('/^\+[ \t]*$/D', $this->m['rest']) === 1 && $this->m['col'] === $f->markerCol
                    && ($f->kind === 'quote' || $this->m['col'] < $f->col)
                ) {
                    $plus = $k;

                    break;
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

                continue;
            }
            $plusFollower = $afterPlus;
            $afterPlus = false;
            $missed = $reached < count($this->frames) - 1;
            $this->reach = $reached;
            $innerIndex = count($this->frames) - 1;
            $inner = $this->frames[$innerIndex];
            if ($inner->kind === 'fence' && !$missed) {
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
                } elseif ($rest !== '' && $inner->closes !== null && ($inner->closes)($rest)) {
                    array_pop($this->frames);
                    $this->frames[count($this->frames) - 1]->last = 'block';
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

                continue;
            }
            if ($inner->kind === 'fence') {
                array_pop($this->frames);
            }
            $form = $this->classify($this->m['rest'], $this->frames[$reached]);
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
            if ($lazy && $next !== null) {
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

                    continue;
                }
                $owner = $this->frames[$reached];
                $limit = $next->kind === 'quote' ? PHP_INT_MAX : $next->canon - 1;
                $this->lead(min(max($this->m['col'] - $owner->cut, $owner->canon), $limit));
                $result[] = $this->out . $this->src;
                $this->lazyLines[$at] = true;

                continue;
            }
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
        }

        return $result;
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
            $this->frames[] = new OwnershipFrame(
                $form->kind,
                $o->col,
                $o->canon,
                $col - $canon,
                closes: $form->closes,
                run: $form->run,
                lineBlock: $form->lineBlock,
                reader: $reader,
            );

            return;
        }
        if ($form->kind === 'quote') {
            $this->frames[] = new OwnershipFrame('quote', 0, 0, 0, markerCol: $col, markerCanon: $canon);
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
        $form = $this->classify($this->m['rest'], $this->frames[$frame]);
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

    private function classify(string $rest, OwnershipFrame $owner): OwnershipForm
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
                        closes: static fn (string $r): bool => preg_match('/^(:{3,})[ \t]*$/D', $r, $c) === 1 && strlen($c[1]) === $run,
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
            $closes = static function (string $r) use ($run): bool {
                return preg_match('/^(`{3,}|~{3,})[ \t]*$/D', $r, $c) === 1 && $c[1][0] === $run[0] && strlen($c[1]) >= strlen($run);
            };
            // A closer below the owner's column never matches (CARVE-P0-014).
            $reachable = ($this->widestFrom($run[0], $owner->col)[$this->at + 1] ?? 0) >= strlen($run);
            if ($owner->para && !$reachable) {
                return new OwnershipForm(kind: 'text', interrupts: false);
            }

            return new OwnershipForm(kind: 'fence', interrupts: $reachable, closes: $closes);
        }
        if (preg_match('/^(%{3,})(.*)$/D', $rest, $comment) === 1) {
            $run = strlen($comment[1]);
            if (($this->closerRuns()['lastComment'][$run] ?? -1) > $this->at) {
                return new OwnershipForm(
                    kind: 'fence',
                    interrupts: true,
                    closes: static fn (string $r): bool => preg_match('/^(%{3,})/', $r, $c) === 1 && strlen($c[1]) === $run,
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
            if ($owner->para && $owner->kind !== 'item') {
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
            return new OwnershipForm(kind: 'leaf', interrupts: true);
        }
        if (preg_match('/^\^ +(?=.*[^ \t\n\r\f])(.*?)[ \t]*$/D', $rest) === 1) {
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
     * The widest closer run of `$char` at or after every line, counting only
     * closers written at `$col` or deeper. One pass per column asked.
     *
     * @return array<int, int>
     */
    private function widestFrom(string $char, int $col): array
    {
        $key = $char . $col;
        if (isset($this->widestByColumn[$key])) {
            return $this->widestByColumn[$key];
        }
        $n = count($this->lines);
        $widest = array_fill(0, $n + 1, 0);
        for ($j = $n - 1; $j >= 0; $j--) {
            $widest[$j] = $widest[$j + 1];
            $m = self::indentCols($this->lines[$j]);
            while (preg_match(self::QUOTE, $m['rest'], $q) === 1) {
                $m = self::indentCols($q[1] ?? '');
            }
            if ($m['col'] >= $col && preg_match('/^(`{3,}|~{3,})[ \t]*$/D', $m['rest'], $c) === 1 && $c[1][0] === $char) {
                $widest[$j] = max($widest[$j], strlen($c[1]));
            }
        }

        return $this->widestByColumn[$key] = $widest;
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
