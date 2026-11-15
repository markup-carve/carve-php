<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Block;

use MarkupCarve\Carve2\Generated\Tables;

/**
 * The block pass (C2-B): one scan over the lines, a stack of open containers and one open leaf.
 */
final class BlockParser
{
    private string $s = '';

    /**
     * @var array<\MarkupCarve\Carve2\Block\Frame>
     */
    private array $stack = [];

    private ?Frame $leaf = null;

    private int $blank = 0;

    private int $i = 0;

    private int $col = 0;

    private int $base = 0;

    private int $le = 0;

    private int $ls = 0;

    /**
     * @param \MarkupCarve\Carve2\Block\Sink $sink
     */
    public function __construct(private Sink $sink)
    {
    }

    /**
     * @param string $s
     *
     * @return \MarkupCarve\Carve2\Block\Frame The document frame.
     */
    public function parse(string $s): Frame
    {
        $this->s = $s;
        $len = strlen($s);
        $doc = new Frame(Frame::DOCUMENT, 0);
        $this->stack = [$doc];
        $this->leaf = null;
        $this->blank = 0;
        $this->sink->enter($doc, $doc);
        $pos = str_starts_with($s, "\u{FEFF}") ? 3 : 0;
        while ($pos < $len) {
            $nl = strpos($s, "\n", $pos);
            $next = $nl === false ? $len : $nl + 1;
            $end = $nl === false ? $len : $nl;
            // C2-B-010: a CR directly before LF is not part of the line.
            if ($end > $pos && $s[$end - 1] === "\r" && $nl !== false) {
                $end--;
            }
            $this->line($pos, $end);
            $pos = $next;
        }
        $this->closeLeaf();
        $this->closeTo(0);
        $doc->end = $len;
        $this->sink->exit($doc);

        return $doc;
    }

    /**
     * @param int $ls
     * @param int $le
     *
     * @return void
     */
    private function line(int $ls, int $le): void
    {
        $this->ls = $ls;
        $this->le = $le;
        $this->i = $ls;
        $this->col = 0;
        $this->base = 0;
        $leaf = $this->leaf;
        $owner = $this->matchContainers();
        $top = count($this->stack) - 1;
        if ($leaf !== null && $leaf->kind === Frame::CODE) {
            if ($owner === $top) {
                $this->codeLine($leaf);

                return;
            }
        }
        $j = $this->i;
        $c = $this->col;
        $this->skipWs($j, $c);
        if ($j >= $le) {
            $this->closeLeaf();
            $this->closeTo($owner);
            $this->blank++;

            return;
        }
        if ($leaf !== null && $leaf->kind === Frame::PARAGRAPH && $this->continuesParagraph($owner, $top, $j, $c)) {
            $leaf->spans[] = $j;
            $leaf->spans[] = $leaf->end = $this->trimEnd($j, $le);
            $this->blank = 0;

            return;
        }
        if ($leaf !== null && $leaf->kind === Frame::HEADING && $owner === $top && $c - $this->base > $leaf->markerCol && !$this->interrupts($j, $c, $owner, $top)) {
            $leaf->spans[] = $j;
            $leaf->spans[] = $leaf->end = $this->trimEnd($j, $le);
            $this->blank = 0;

            return;
        }
        if ($leaf !== null && $leaf->kind === Frame::TABLE && $owner === $top && $this->s[$j] === '|' && $this->tableRow($leaf, $j)) {
            $this->blank = 0;

            return;
        }
        $this->closeLeaf();
        $this->openBlocks($owner);
        $this->blank = 0;
    }

    /**
     * Walks the open containers outside-in and consumes their prefixes (C2-B-008, C2-B-025).
     * Returns the index of the innermost container that owns the line.
     *
     * @return int
     */
    private function matchContainers(): int
    {
        $n = count($this->stack);
        $owner = 0;
        for ($k = 1; $k < $n; $k++) {
            $f = $this->stack[$k];
            $kind = $f->kind;
            if ($kind === Frame::QUOTE) {
                if (!$this->matchQuote()) {
                    break;
                }
            } elseif ($kind === Frame::ITEM) {
                $j = $this->i;
                $c = $this->col;
                $this->skipWs($j, $c);
                // C2-B-009: a blank line belongs to no container.
                if ($j < $this->le && $c - $this->base <= $f->markerCol) {
                    break;
                }
                $this->stripTo($f->contentCol);
            }
            $owner = $kind === Frame::LIST ? $owner : $k;
        }

        return $owner;
    }

    /**
     * C2-B-031: `>` followed by a space or the end of the line.
     *
     * @return bool
     */
    private function matchQuote(): bool
    {
        $j = $this->i;
        $c = $this->col;
        $this->skipWs($j, $c);
        if ($j < $this->le && $this->s[$j] === '>' && ($j + 1 === $this->le || $this->s[$j + 1] === ' ')) {
            $j++;
            $c++;
            if ($j < $this->le) {
                $j++;
                $c++;
            }
            $this->i = $j;
            $this->col = $c;
            $this->base = $c;

            return true;
        }

        return false;
    }

    /**
     * C2-B-004, C2-B-005: a tab advances to the next multiple of the tab stop, counted from the line start.
     *
     * @param int $j
     * @param int $c
     *
     * @return void
     */
    private function skipWs(int &$j, int &$c): void
    {
        $s = $this->s;
        $le = $this->le;
        while ($j < $le) {
            $ch = $s[$j];
            if ($ch === ' ') {
                $c++;
            } elseif ($ch === "\t") {
                $c += Tables::TAB_STOP - ($c % Tables::TAB_STOP);
            } else {
                return;
            }
            $j++;
        }
    }

    /**
     * C2-B-007, C2-B-027: strips whole whitespace characters up to a relative column.
     *
     * @param int $target
     *
     * @return void
     */
    private function stripTo(int $target): void
    {
        $s = $this->s;
        $target += $this->base;
        while ($this->i < $this->le && $this->col < $target) {
            $ch = $s[$this->i];
            if ($ch === ' ') {
                $next = $this->col + 1;
            } elseif ($ch === "\t") {
                $next = $this->col + Tables::TAB_STOP - ($this->col % Tables::TAB_STOP);
                if ($next > $target) {
                    return;
                }
            } else {
                return;
            }
            $this->col = $next;
            $this->i++;
        }
    }

    /**
     * @param int $j
     * @param int $le
     *
     * @return int
     */
    private function trimEnd(int $j, int $le): int
    {
        while ($le > $j && ($this->s[$le - 1] === ' ' || $this->s[$le - 1] === "\t")) {
            $le--;
        }

        return $le;
    }

    /**
     * C2-B-012 to C2-B-024: whether a non-blank line continues the open paragraph.
     *
     * @param int $owner
     * @param int $top
     * @param int $j
     * @param int $c
     *
     * @return bool
     */
    private function continuesParagraph(int $owner, int $top, int $j, int $c): bool
    {
        return !$this->interrupts($j, $c, $owner, $top);
    }

    /**
     * The six interrupters (C2-B-014 to C2-B-020, C2-B-200), tested in the context of the owner.
     *
     * @param int $j
     * @param int $c
     * @param int $owner
     * @param int $top
     *
     * @return bool
     */
    private function interrupts(int $j, int $c, int $owner, int $top): bool
    {
        $ch = $this->s[$j];
        if ($ch === '`' && $this->fenceRun($j) >= 3) {
            return true;
        }
        if ($ch === '|' && $this->isTableRow($j)) {
            return true;
        }
        if ($ch === '*' && $this->isThematicBreak($j)) {
            return true;
        }
        $marker = $this->marker($j);
        if ($marker === null) {
            return false;
        }
        $candidate = $this->stack[$owner + 1] ?? null;
        if ($candidate !== null && $candidate->kind === Frame::LIST) {
            return true;
        }
        if ($candidate !== null && $candidate->kind === Frame::QUOTE && $this->stack[$owner]->kind === Frame::ITEM) {
            // C2-B-021.
            return true;
        }
        if ($candidate !== null) {
            return false;
        }
        $kind = $this->stack[$owner]->kind;
        if ($kind !== Frame::ITEM) {
            return false;
        }

        // C2-B-015, C2-B-056: a nested list without a blank line needs a bullet, a bare dot or value 1.
        return $marker['ordered'] === false || $marker['value'] === 1;
    }

    /**
     * Opens the blocks of a line that no open leaf takes, in the owner container.
     *
     * @param int $owner
     *
     * @return void
     */
    private function openBlocks(int $owner): void
    {
        $j = $this->i;
        $c = $this->col;
        $this->skipWs($j, $c);
        $marker = $this->marker($j);
        if ($marker !== null) {
            $candidate = $this->stack[$owner + 1] ?? null;
            if ($candidate !== null && $candidate->kind === Frame::LIST && $this->blank < Tables::LIST_END_BLANK_LINES && $this->matches($candidate, $marker)) {
                $this->closeTo($owner + 1);
                if ($this->blank > 0) {
                    $candidate->loose = true;
                }
                $this->openItem($candidate, $marker, $j, $c);

                return;
            }
            $this->closeTo($owner);
            $list = new Frame(Frame::LIST, $j);
            $list->key = $marker['key'];
            $list->markerCol = $c - $this->base;
            $list->data = $marker;
            $list->end = $marker['end'];
            $this->push($list);
            $this->openItem($list, $marker, $j, $c);

            return;
        }
        $this->closeTo($owner);
        $this->openNonMarker($j, $c);
    }

    /**
     * @param int $j
     * @param int $c
     *
     * @return void
     */
    private function openNonMarker(int $j, int $c): void
    {
        $s = $this->s;
        $ch = $s[$j];
        if ($ch === '>' && ($j + 1 === $this->le || $s[$j + 1] === ' ')) {
            $quote = new Frame(Frame::QUOTE, $j);
            $quote->end = $j + 1;
            $this->push($quote);
            $this->i = $j;
            $this->col = $c;
            $this->matchQuote();
            $j = $this->i;
            $c = $this->col;
            $this->skipWs($j, $c);
            if ($j < $this->le) {
                $this->openBlocks(count($this->stack) - 1);
            }

            return;
        }
        if (($ch === '=' || $ch === '#') && $this->openHeading($j, $c)) {
            return;
        }
        if ($ch === '`' && $this->openFence($j, $c)) {
            return;
        }
        if ($ch === '*' && $this->isThematicBreak($j)) {
            $f = new Frame(Frame::THEMATIC, $j);
            $f->end = $this->trimEnd($j, $this->le);
            $this->emitLeaf($f);

            return;
        }
        if ($ch === '|' && $this->isTableRow($j)) {
            $f = new Frame(Frame::TABLE, $j);
            $this->leaf = $f;
            $this->tableRow($f, $j);

            return;
        }
        $p = new Frame(Frame::PARAGRAPH, $j);
        $p->end = $this->trimEnd($j, $this->le);
        $p->spans = [$j, $p->end];
        $this->leaf = $p;
    }

    /**
     * C2-B-151 to C2-B-154.
     *
     * @param int $j
     * @param int $c
     *
     * @return bool
     */
    private function openHeading(int $j, int $c): bool
    {
        $s = $this->s;
        $ch = $s[$j];
        $n = strspn($s, $ch, $j, $this->le - $j);
        if ($n > 6 || $j + $n >= $this->le || $s[$j + $n] !== ' ') {
            return false;
        }
        $k = $j + $n + 1;
        $k += strspn($s, " \t", $k, $this->le - $k);
        if ($k >= $this->le) {
            return false;
        }
        $f = new Frame(Frame::HEADING, $j);
        $f->level = $n;
        $f->markerCol = $c - $this->base;
        $f->end = $this->trimEnd($k, $this->le);
        $f->spans = [$k, $f->end];
        $this->leaf = $f;

        return true;
    }

    /**
     * @param int $j
     *
     * @return int
     */
    private function fenceRun(int $j): int
    {
        return strspn($this->s, '`', $j, $this->le - $j);
    }

    /**
     * C2-B-108, C2-B-117, C2-B-118.
     *
     * @param int $j
     * @param int $c
     *
     * @return bool
     */
    private function openFence(int $j, int $c): bool
    {
        $n = $this->fenceRun($j);
        if ($n < 3) {
            return false;
        }
        $info = substr($this->s, $j + $n, $this->le - $j - $n);
        if (str_contains($info, '`')) {
            return false;
        }
        $f = new Frame(Frame::CODE, $j);
        $f->level = $n;
        $f->indent = $c - $this->col;
        $f->data['info'] = trim($info, " \t");
        $f->end = $this->le;
        $this->leaf = $f;

        return true;
    }

    /**
     * A line owned by the container of an open code fence.
     *
     * @param \MarkupCarve\Carve2\Block\Frame $f
     *
     * @return void
     */
    private function codeLine(Frame $f): void
    {
        $j = $this->i;
        $c = $this->col;
        $this->skipWs($j, $c);
        if ($j < $this->le && $this->s[$j] === '`') {
            $n = $this->fenceRun($j);
            if ($n >= $f->level && $j + $n === $this->trimEnd($j + $n, $this->le)) {
                $f->end = $this->trimEnd($j, $this->le);
                $f->data['closed'] = true;
                $this->leaf = null;
                $this->emitLeaf($f);

                return;
            }
        }
        $this->stripTo($this->col - $this->base + $f->indent);
        $f->spans[] = $this->i;
        $f->spans[] = $this->le;
        $f->end = $this->le;
    }

    /**
     * C2-B-166: starts with a pipe, ends with an unescaped pipe outside a code span, two pipes or more.
     *
     * @param int $j
     *
     * @return bool
     */
    private function isTableRow(int $j): bool
    {
        return $this->splitRow($j) !== null;
    }

    /**
     * Splits a row into cell spans (C2-B-169): flat start/end offsets between separators.
     *
     * @param int $j
     *
     * @return array<int>|null
     */
    private function splitRow(int $j): ?array
    {
        $s = $this->s;
        $le = $this->trimEnd($j, $this->le);
        $cells = [];
        $start = $j + 1;
        $k = $j + 1;
        $last = -1;
        while ($k < $le) {
            $n = strcspn($s, '|\\`', $k, $le - $k);
            $k += $n;
            if ($k >= $le) {
                break;
            }
            $ch = $s[$k];
            if ($ch === '\\') {
                $k += 2;

                continue;
            }
            if ($ch === '`') {
                $run = strspn($s, '`', $k, $le - $k);
                $close = $this->findRun($k + $run, $le, $run);
                $k = $close < 0 ? $k + $run : $close + $run;

                continue;
            }
            $cells[] = $start;
            $cells[] = $k;
            $start = $k + 1;
            $last = $k;
            $k++;
        }
        if ($cells === [] || $last !== $le - 1) {
            return null;
        }

        return $cells;
    }

    /**
     * Finds a backtick run of exactly `$run` characters in [$k, $le).
     *
     * @param int $k
     * @param int $le
     * @param int $run
     *
     * @return int
     */
    private function findRun(int $k, int $le, int $run): int
    {
        $s = $this->s;
        while ($k < $le) {
            $p = strpos($s, '`', $k);
            if ($p === false || $p >= $le) {
                return -1;
            }
            $n = strspn($s, '`', $p, $le - $p);
            if ($n === $run) {
                return $p;
            }
            $k = $p + $n;
        }

        return -1;
    }

    /**
     * @param \MarkupCarve\Carve2\Block\Frame $table
     * @param int $j
     *
     * @return bool
     */
    private function tableRow(Frame $table, int $j): bool
    {
        $cells = $this->splitRow($j);
        if ($cells === null) {
            return false;
        }
        $table->spans[] = -1;
        array_push($table->spans, ...$cells);
        $table->end = $this->trimEnd($j, $this->le);

        return true;
    }

    /**
     * C2-B-159.
     *
     * @param int $j
     *
     * @return bool
     */
    private function isThematicBreak(int $j): bool
    {
        $n = strspn($this->s, '*', $j, $this->le - $j);

        return $n >= 3 && $this->trimEnd($j + $n, $this->le) === $j + $n;
    }

    /**
     * Reads a list marker with content at `$j` (C2-B-033 to C2-B-043, line-shapes.json bullet and ordered).
     *
     * @param int $j
     *
     * @return array<string, mixed>|null
     */
    private function marker(int $j): ?array
    {
        $s = $this->s;
        $le = $this->le;
        $ch = $s[$j];
        if ($ch === '-' || $ch === '*') {
            return $this->markerTail($j + 1, 1, ['ordered' => false, 'key' => $ch, 'char' => $ch, 'value' => 0]);
        }
        if ($ch === '.') {
            $m = $this->markerTail($j + 1, 1, ['ordered' => true, 'style' => '1', 'delimiter' => '.', 'key' => '1.', 'value' => -1, 'letters' => '']);
            // C2-B-043: a bare dot whose content starts with a dot and a space is no marker.
            if ($m !== null && $s[$m['content']] === '.' && $m['content'] + 1 < $le && $s[$m['content'] + 1] === ' ') {
                return null;
            }

            return $m;
        }
        $n = strspn($s, '0123456789', $j, min(10, $le - $j));
        if ($n > 0) {
            if ($n > 9 || $j + $n >= $le || ($s[$j + $n] !== '.' && $s[$j + $n] !== ')')) {
                return null;
            }
            $d = $s[$j + $n];

            return $this->markerTail($j + $n + 1, 1, ['ordered' => true, 'style' => '1', 'delimiter' => $d, 'key' => '1' . $d, 'value' => (int)substr($s, $j, $n), 'letters' => '']);
        }

        return $this->letterMarker($j);
    }

    /**
     * @param int $j
     *
     * @return array<string, mixed>|null
     */
    private function letterMarker(int $j): ?array
    {
        $s = $this->s;
        $le = $this->le;
        $n = strspn($s, Tables::CHARS_LETTER_OR_ROMAN_NUMBER, $j, min(10, $le - $j));
        if ($n === 0 || $n > 9 || $j + $n >= $le) {
            return null;
        }
        $d = $s[$j + $n];
        if ($d !== '.' && $d !== ')') {
            return null;
        }
        $letters = substr($s, $j, $n);
        $upper = ctype_upper($letters);
        if (!$upper && !ctype_lower($letters)) {
            return null;
        }
        $roman = Roman::value($letters);
        if ($n > 1 && $roman === 0) {
            return null;
        }
        // C2-B-040: an uppercase marker with a dot needs two spaces.
        $spaces = $upper && $d === '.' ? 2 : 1;
        $lower = strtolower($letters);
        $style = $n > 1 || $lower === 'i' ? 'i' : 'a';
        if ($upper) {
            $style = strtoupper($style);
        }
        $value = $style === 'a' || $style === 'A' ? ord($lower) - 96 : $roman;

        return $this->markerTail($j + $n + 1, $spaces, ['ordered' => true, 'style' => $style, 'delimiter' => $d, 'key' => $style . $d, 'value' => $value, 'letters' => $letters, 'alpha' => ord($lower) - 96, 'roman' => $roman]);
    }

    /**
     * The part after the marker characters: optional brace block, spaces, content (C2-B-033, C2-B-101).
     *
     * @param int $k
     * @param int $spaces
     * @param array<string, mixed> $m
     *
     * @return array<string, mixed>|null
     */
    private function markerTail(int $k, int $spaces, array $m): ?array
    {
        $s = $this->s;
        $le = $this->le;
        $m['end'] = $k;
        $n = strspn($s, ' ', $k, $le - $k);
        if ($n < $spaces) {
            return null;
        }
        $content = $k + $n;
        if ($content >= $le || $this->trimEnd($content, $le) === $content) {
            return null;
        }
        $m['content'] = $content;

        return $m;
    }

    /**
     * C2-B-049: a marker matches a list in bullet character, or in ordered style and delimiter.
     *
     * @param \MarkupCarve\Carve2\Block\Frame $list
     * @param array<string, mixed> $m
     *
     * @return bool
     */
    private function matches(Frame $list, array $m): bool
    {
        if ($list->key === $m['key']) {
            return true;
        }
        if (!$m['ordered'] || !isset($list->data['delimiter']) || $list->data['delimiter'] !== $m['delimiter']) {
            return false;
        }
        $style = $list->data['style'];
        $letters = $m['letters'];
        if ($style === '1') {
            return $m['style'] === '1';
        }
        if (strlen($letters) !== 1) {
            return false;
        }
        $upper = ctype_upper($letters);
        // C2-B-038, C2-B-039: a single roman letter in a roman list, every single letter in an alphabetic one.
        if ($style === 'a' || $style === 'A') {
            return $upper === ($style === 'A');
        }

        return $upper === ($style === 'I') && $m['roman'] > 0;
    }

    /**
     * @param \MarkupCarve\Carve2\Block\Frame $list
     * @param array<string, mixed> $m
     * @param int $j
     * @param int $c
     *
     * @return void
     */
    private function openItem(Frame $list, array $m, int $j, int $c): void
    {
        $item = new Frame(Frame::ITEM, $j);
        $item->markerCol = $c - $this->base;
        $content = $m['content'];
        $item->contentCol = $item->markerCol + ($content - $j);
        $item->data = $m;
        $item->end = $m['end'];
        $list->items++;
        $this->push($item);
        $this->i = $content;
        $this->col = $c + ($content - $j);
        $this->openBlocks(count($this->stack) - 1);
    }

    /**
     * @param \MarkupCarve\Carve2\Block\Frame $f
     *
     * @return void
     */
    private function push(Frame $f): void
    {
        $parent = $this->stack[count($this->stack) - 1];
        $this->stack[] = $f;
        $this->sink->enter($f, $parent);
    }

    /**
     * @param \MarkupCarve\Carve2\Block\Frame $f
     *
     * @return void
     */
    private function emitLeaf(Frame $f): void
    {
        $parent = $this->stack[count($this->stack) - 1];
        if ($f->end > $parent->end) {
            $parent->end = $f->end;
        }
        $this->sink->leaf($f, $parent);
    }

    /**
     * @return void
     */
    private function closeLeaf(): void
    {
        $leaf = $this->leaf;
        if ($leaf === null) {
            return;
        }
        $this->leaf = null;
        $this->emitLeaf($leaf);
    }

    /**
     * Closes every container above index `$keep`.
     *
     * @param int $keep
     *
     * @return void
     */
    private function closeTo(int $keep): void
    {
        $n = count($this->stack) - 1;
        if ($n <= $keep) {
            return;
        }
        $this->closeLeaf();
        for ($k = $n; $k > $keep; $k--) {
            $f = array_pop($this->stack);
            $this->sink->exit($f);
            $parent = $this->stack[$k - 1];
            if ($f->end > $parent->end) {
                $parent->end = $f->end;
            }
        }
    }
}
