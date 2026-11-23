<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Block;

use MarkupCarve\Carve\AttributeParser;
use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Generated\Tables;

/**
 * The block pass (C2-B): one scan over the lines, a stack of open containers, one open leaf and
 * the facts about the lines directly above (C2-B-001).
 */
final class BlockParser
{
    /**
     * Leaves whose body is not read for blocks (C2-B-112, C2-B-114, C2-B-123).
     *
     * @var array<int, bool>
     */
    private const OPAQUE = [Frame::CODE => true, Frame::COMMENT_BLOCK => true, Frame::LINE_BLOCK => true, Frame::FRONTMATTER => true];

    /**
     * Containers that count toward the depth cap (C2-B-190).
     *
     * @var array<int, bool>
     */
    private const COUNTED = [
        Frame::QUOTE => true, Frame::LIST => true, Frame::ITEM => true, Frame::DLIST => true,
        Frame::DESC => true, Frame::FOOTNOTE => true, Frame::DIV => true,
    ];

    private string $s = '';

    /**
     * @var array<\MarkupCarve\Carve\Block\Frame>
     */
    private array $stack = [];

    private ?Frame $leaf = null;

    private int $blank = 0;

    private int $i = 0;

    private int $col = 0;

    private int $base = 0;

    private int $le = 0;

    private int $ls = 0;

    private int $depth = 0;

    private ?Frame $pending = null;

    private ?Frame $closed = null;

    private ?Frame $closedParent = null;

    private int $markerAt = -1;

    private ?Marker $markerRead = null;

    private int $stripI = -1;

    private int $stripCol = 0;

    /**
     * @param \MarkupCarve\Carve\Block\Sink $sink
     */
    public function __construct(private Sink $sink)
    {
    }

    /**
     * @param string $s
     * @param bool $fileStart
     *
     * @return \MarkupCarve\Carve\Block\Frame The document frame.
     */
    public function parse(string $s, bool $fileStart = true): Frame
    {
        $this->s = $s;
        $len = strlen($s);
        $doc = new Frame(Frame::DOCUMENT, 0);
        $this->stack = [$doc];
        $this->leaf = null;
        $this->pending = null;
        $this->closed = null;
        $this->closedParent = null;
        $this->blank = 0;
        $this->depth = 0;
        $this->sink->enter($doc, $doc);
        // C2-B-165: a byte-order mark is skipped before line 1 is read.
        $pos = str_starts_with($s, "\u{FEFF}") ? 3 : 0;
        $first = $fileStart;
        while ($pos < $len) {
            $nl = strpos($s, "\n", $pos);
            $next = $nl === false ? $len : $nl + 1;
            $end = $nl === false ? $len : $nl;
            // C2-B-010: a CR directly before LF is not part of the line.
            if ($nl !== false && $end > $pos && $s[$end - 1] === "\r") {
                $end--;
            }
            if ($first) {
                $first = false;
                if ($this->frontmatter($pos, $end)) {
                    $pos = $next;

                    continue;
                }
            }
            if ($pos === $end && ($this->leaf === null || !isset(self::OPAQUE[$this->leaf->kind]))) {
                $this->emptyLine();
            } else {
                $this->line($pos, $end);
            }
            $pos = $next;
        }
        $this->closeLeaf();
        $this->flushPending();
        $this->closeTo(0);
        $doc->end = $len;
        $this->sink->exit($doc);

        return $doc;
    }

    /**
     * C2-B-161: line 1 opens frontmatter.
     *
     * @param int $ls
     * @param int $le
     *
     * @return bool
     */
    private function frontmatter(int $ls, int $le): bool
    {
        $format = LineShapes::frontmatter($this->s, $ls, $le);
        if ($format === null) {
            return false;
        }
        $f = new Frame(Frame::FRONTMATTER, $ls);
        $f->value = $format;
        $f->end = $le;
        $this->leaf = $f;

        return true;
    }

    /**
     * Whether every container that owns the line is a list, a list item, a description list or a description.
     *
     * @param int $owner
     *
     * @return bool
     */
    private function listsOnly(int $owner): bool
    {
        for ($k = $owner; $k > 0; $k--) {
            $kind = $this->stack[$k]->kind;
            if ($kind !== Frame::LIST && $kind !== Frame::ITEM && $kind !== Frame::DLIST && $kind !== Frame::DESC) {
                return false;
            }
        }

        return true;
    }

    /**
     * C2-B-232, C2-B-233: after a blank line or pending top-level attribute lines, a typed opener opens frontmatter.
     *
     * @param int $ls
     * @param int $le
     *
     * @return bool
     */
    private function metadataBlock(int $ls, int $le): bool
    {
        if ($this->leaf !== null) {
            return false;
        }
        $pending = $this->pending;
        if ($pending !== null ? $pending->depth !== 0 : $this->blank === 0) {
            return false;
        }
        $f = new Frame(Frame::FRONTMATTER, $ls);
        $f->value = (string)LineShapes::typedFrontmatter($this->s, $ls, $le);
        $f->end = $le;
        $this->take($f);
        $this->leaf = $f;
        $this->closed = null;
        $this->blank = 0;

        return true;
    }

    /**
     * @param int $ls
     * @param int $le
     *
     * @return void
     */
    private function line(int $ls, int $le): void
    {
        $this->markerAt = -1;
        $this->ls = $ls;
        $this->le = $le;
        $this->i = $ls;
        $this->col = 0;
        $this->base = 0;
        if (
            $this->leaf !== null && $this->leaf->kind === Frame::COMMENT_BLOCK
            && $this->bareCommentCloser($ls, $le, $this->leaf->level)
        ) {
            $this->closeOpaque($this->leaf, $le);

            return;
        }
        $top = count($this->stack) - 1;
        if ($top === 0) {
            $this->stripI = -1;
            $owner = 0;
        } else {
            $owner = $this->matchContainers();
        }
        $leaf = $this->leaf;
        $forced = false;
        if ($leaf !== null && isset(self::OPAQUE[$leaf->kind])) {
            if ($leaf->kind === Frame::LINE_BLOCK && $owner < $top && $this->lazyLineBlockComment($leaf)) {
                return;
            }
            if ($owner === $top || $leaf->kind === Frame::LINE_BLOCK && $this->lazyLineBlock($leaf)) {
                $this->opaqueLine($leaf);

                return;
            }
            // C2-B-111, C2-B-135: an unclosed fence ends with its container; the line is then read normally.
            $this->closeLeaf();
            $leaf = null;
            $forced = true;
        }
        $j = $this->i;
        $c = $this->col;
        $w = strspn($this->s, ' ', $j, $this->le - $j);
        $j += $w;
        $c += $w;
        if ($j < $this->le && $this->s[$j] === "\t") {
            $this->skipWs($j, $c);
        }
        if ($j >= $le) {
            $this->blankLine($owner);

            return;
        }
        $s = $this->s;
        if (
            $j === $ls && $s[$j] === '-' && !$forced && ($leaf === null || $leaf->kind !== Frame::PARAGRAPH)
            && LineShapes::typedFrontmatter($s, $j, $le) !== null && $this->listsOnly($owner)
        ) {
            // C2-B-232: with no paragraph open, a column-0 typed opener ends the containers it does not
            // indent past (C2-B-026); below an open paragraph it is a lazy line of that paragraph (C2-B-024).
            $this->closeTo(0);
            $owner = 0;
            $top = 0;
            $leaf = $this->leaf;
            if ($this->metadataBlock($ls, $le)) {
                return;
            }
        }
        if ($s[$j] === '%' && $j + 1 < $le && $s[$j + 1] === '%' && ($j + 2 >= $le || $s[$j + 2] !== '%')) {
            if ($this->regionMarker($j)) {
                $this->closeLeaf();
                $this->closeTo($owner);
                $this->emitComment($j, $this->trimEnd($j));
                $this->closed = null;
                $this->closedParent = null;
                $this->blank = 0;

                return;
            }
            if ($forced) {
                $this->closeTo($owner);
                $top = $owner;
            }
            $this->commentLine($j);

            return;
        }
        $ch = $s[$j];
        if (($ch === '-' || $ch === '*') && ($leaf === null || $leaf->kind === Frame::PARAGRAPH) && $this->siblingItem($owner, $j, $c)) {
            $this->blank = 0;

            return;
        }
        if ($leaf !== null && $this->continues($leaf, $owner, $top, $j, $c)) {
            $this->blank = 0;

            return;
        }
        $this->closeLeaf();
        $this->openLine($owner, $j, $c);
        $this->blank = 0;
    }

    private function bareCommentCloser(int $ls, int $le, int $level): bool
    {
        $j = $ls + strspn($this->s, " \t", $ls, $le - $ls);
        $end = LineShapes::trimEnd($this->s, $j, $le);
        $quoteDepth = 0;
        foreach ($this->stack as $frame) {
            if ($frame->kind === Frame::QUOTE) {
                $quoteDepth++;
            }
        }
        while (
            $quoteDepth > 0 && $j < $end && $this->s[$j] === '>'
            && ($j + 1 === $end || $this->s[$j + 1] === ' ')
        ) {
            $j++;
            $j += strspn($this->s, " \t", $j, $end - $j);
            $quoteDepth--;
        }

        return $j < $end && strspn($this->s, '%', $j, $end - $j) === $end - $j && $end - $j >= $level;
    }

    private function regionMarker(int $j): bool
    {
        $line = substr($this->s, $j, $this->le - $j);

        $shape = '/^%%[ \t]*#(?:region[ \t]+[A-Za-z_][A-Za-z0-9_-]*'
            . '|endregion(?:[ \t]+[A-Za-z_][A-Za-z0-9_-]*)?)[ \t]*$/';

        return preg_match($shape, $line) === 1;
    }

    private function lazyLineBlock(Frame $leaf): bool
    {
        $n = count($leaf->spans);
        if ($n === 0 || $n >= 2 && $leaf->spans[$n - 2] === $leaf->spans[$n - 1]) {
            // C2-B-024: only an open stanza takes a lazy line.
            return false;
        }
        $j = $this->i + strspn($this->s, " \t", $this->i, $this->le - $this->i);
        if ($j >= $this->le || $this->regionMarker($j)) {
            return false;
        }
        $ch = $this->s[$j];
        if ($ch === '>' && ($j + 1 === $this->le || $this->s[$j + 1] === ' ')) {
            return false;
        }
        if ($ch === '#' && preg_match('/^#{1,6} /', substr($this->s, $j, $this->le - $j)) === 1) {
            return false;
        }
        if ($ch === '{' && LineShapes::attributeLine($this->s, $j, $this->le, new Attrs())) {
            return false;
        }
        if (($ch === '[' || $ch === '*') && $this->isDefinition($j)) {
            return false;
        }

        return !LineShapes::isFenceLine($this->s, $j, $this->le);
    }

    private function lazyLineBlockComment(Frame $leaf): bool
    {
        $j = $this->i + strspn($this->s, " \t", $this->i, $this->le - $this->i);
        if (
            $j + 1 >= $this->le || substr($this->s, $j, 2) !== '%%' || ($this->s[$j + 2] ?? '') === '%'
            || $this->regionMarker($j) || $leaf->spans === []
        ) {
            return false;
        }
        $lineIndex = count($leaf->spans) - 2;
        $leaf->lazyLineComments[$lineIndex][] = [$j, $this->le, $this->commentValue($j, $this->trimEnd($j))];
        $leaf->end = $this->le;

        return true;
    }

    /**
     * Fast path of openMarker() and openItem() for the common sibling bullet item `- text`: one space
     * after the marker and content that starts with a letter and is no marker (C2-B-049). Anything
     * else takes the general path.
     *
     * @param int $owner
     * @param int $j
     * @param int $c
     *
     * @return bool
     */
    private function siblingItem(int $owner, int $j, int $c): bool
    {
        $s = $this->s;
        $k = $j + 2;
        if ($k >= $this->le || $s[$j + 1] !== ' ' || !ctype_alpha($s[$k]) || $this->pending !== null) {
            return false;
        }
        if (!Markers::quickReject($s, $k, $this->le)) {
            return false;
        }
        $list = $this->stack[$owner + 1] ?? null;
        if ($list !== null && $list->kind === Frame::LIST && $list->key === $s[$j] && $this->blank < Tables::LIST_END_BLANK_LINES) {
            if ($this->depth + 1 > Tables::BLOCK_DEPTH) {
                return false;
            }
            $this->closeLeaf();
            $this->closeTo($owner + 1);
            if ($this->blank > 0) {
                $list->loose = true;
            }
        } elseif ($this->leaf === null && $this->depth + 2 <= Tables::BLOCK_DEPTH) {
            $this->closeTo($owner);
            $list = $this->newList($j, $c);
        } else {
            return false;
        }
        $item = new Frame(Frame::ITEM, $j);
        $item->markerCol = $c - $this->base;
        $item->contentCol = $item->markerCol + 2;
        $item->end = $j + 1;
        $list->items++;
        $this->push($item);
        $p = new Frame(Frame::PARAGRAPH, $k);
        $p->end = $this->le;
        $p->spans = [$k, $p->end];
        $p->n = 1;
        $this->leaf = $p;
        $this->i = $k;
        $this->col = $c + 2;

        return true;
    }

    /**
     * The list a bullet marker without attributes opens (openMarker()).
     *
     * @param int $j
     * @param int $c
     *
     * @return \MarkupCarve\Carve\Block\Frame
     */
    private function newList(int $j, int $c): Frame
    {
        $m = new Marker(false);
        $m->key = $m->char = $this->s[$j];
        $m->end = $j + 1;
        $m->box = $m->content = $j + 2;
        $list = new Frame(Frame::LIST, $j);
        $list->key = $m->key;
        $list->markerCol = $c - $this->base;
        $list->marker = $m;
        $list->end = $m->end;
        $this->push($list);

        return $list;
    }

    /**
     * An empty line with no opaque leaf open: it passes every container but a quote (C2-B-009, C2-B-031).
     *
     * @return void
     */
    private function emptyLine(): void
    {
        $n = count($this->stack);
        $owner = 0;
        for ($k = 1; $k < $n && $this->stack[$k]->kind !== Frame::QUOTE; $k++) {
            $owner = $k;
        }
        $this->blankLine($owner);
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
        $this->stripI = -1;
        // The end of the space run at the line position, kept across containers so deep nesting reads it once.
        $spaces = -1;
        for ($k = 1; $k < $n; $k++) {
            $f = $this->stack[$k];
            $kind = $f->kind;
            if ($kind === Frame::LIST || $kind === Frame::DLIST) {
                continue;
            }
            if ($kind === Frame::QUOTE) {
                if (!$this->matchQuote()) {
                    break;
                }
                // C2-B-029: an item before this quote is not the fence's direct container.
                $this->stripI = -1;
            } elseif ($kind !== Frame::DIV) {
                $j = $this->i;
                $c = $this->col;
                if ($spaces < $j) {
                    $spaces = $j + strspn($this->s, ' ', $j, $this->le - $j);
                }
                $c += $spaces - $j;
                $j = $spaces;
                if ($j < $this->le && $this->s[$j] === "\t") {
                    $this->skipWs($j, $c);
                }
                // C2-B-009: a blank line belongs to no container.
                if ($j < $this->le && $c - $this->base <= $f->markerCol) {
                    break;
                }
                $this->stripI = $this->i;
                $this->stripCol = $this->col;
                $need = $f->contentCol + $this->base - $this->col;
                if ($need > 0 && $need <= $spaces - $this->i) {
                    $this->i += $need;
                    $this->col += $need;
                } else {
                    $this->stripTo($f->contentCol, $spaces);
                }
            }
            $owner = $k;
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
        $w = strspn($this->s, ' ', $j, $this->le - $j);
        $j += $w;
        $c += $w;
        if ($j < $this->le && $this->s[$j] === "\t") {
            $this->skipWs($j, $c);
        }
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
        $n = strspn($s, ' ', $j, $le - $j);
        $j += $n;
        $c += $n;
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
     * @param int $spaces The end of the space run that holds the position, or -1 to read it.
     *
     * @return void
     */
    private function stripTo(int $target, int $spaces = -1): void
    {
        $s = $this->s;
        $target += $this->base;
        if ($this->col < $target) {
            $run = $spaces >= $this->i ? $spaces - $this->i : strspn($s, ' ', $this->i, $this->le - $this->i);
            $n = min($target - $this->col, $run);
            $this->i += $n;
            $this->col += $n;
        }
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
     *
     * @return int
     */
    private function trimEnd(int $j): int
    {
        return LineShapes::trimEnd($this->s, $j, $this->le);
    }

    /**
     * A blank line (C2-B-009, C2-B-013, C2-B-032, C2-B-192).
     *
     * @param int $owner
     *
     * @return void
     */
    private function blankLine(int $owner): void
    {
        $this->closeLeaf();
        $this->flushPending();
        // C2-B-051, C2-B-080: blank lines do not end a list or a description list.
        while (
            isset($this->stack[$owner + 1])
            && ($this->stack[$owner + 1]->kind === Frame::LIST || $this->stack[$owner + 1]->kind === Frame::DLIST)
        ) {
            $owner++;
        }
        $this->closeTo($owner);
        $this->blank++;
        $this->closed = null;
    }

    /**
     * C2-B-137, C2-B-138: a comment-only line is skipped by ownership; its comment goes to the open
     * leaf, or else to the innermost open container.
     *
     * @param int $j
     *
     * @return void
     */
    private function commentLine(int $j): void
    {
        $leaf = $this->leaf;
        $end = $this->trimEnd($j);
        if ($leaf !== null) {
            $kind = $leaf->kind;
            if ($kind === Frame::PARAGRAPH || $kind === Frame::TERM || $kind === Frame::CAPTION || $kind === Frame::HEADING) {
                $this->append($leaf, $j);

                return;
            }
            if ($kind === Frame::TABLE) {
                $item = new TableItem(false, $j, $this->le);
                $item->value = $this->commentValue($j, $end);
                $leaf->rows[] = $item;
                $leaf->end = $this->le;

                return;
            }
            $this->closeLeaf();
        }
        $this->emitComment($j, $end);
    }

    /**
     * C2-A-074: the text after `%%` without one space and without trailing spaces and tabs.
     *
     * @param int $j
     * @param int $end
     *
     * @return string
     */
    private function commentValue(int $j, int $end): string
    {
        $k = $j + 2;
        if ($k < $end && $this->s[$k] === ' ') {
            $k++;
        }

        return substr($this->s, $k, $end - $k);
    }

    /**
     * @param int $j
     * @param int $end
     *
     * @return void
     */
    private function emitComment(int $j, int $end): void
    {
        $f = new Frame(Frame::COMMENT_LINE, $j);
        $f->end = $this->le;
        $f->value = $this->commentValue($j, $end);
        $parent = $this->stack[count($this->stack) - 1];
        if ($f->end > $parent->end) {
            $parent->end = $f->end;
        }
        $this->sink->leaf($f, $parent);
    }

    /**
     * @param \MarkupCarve\Carve\Block\Frame $leaf
     * @param int $j
     *
     * @return void
     */
    private function append(Frame $leaf, int $j): void
    {
        $leaf->spans[] = $j;
        $leaf->spans[] = $leaf->end = $this->le;
    }

    /**
     * Whether a line that is not blank continues the open leaf (C2-B-012, C2-B-154, C2-B-170).
     *
     * @param \MarkupCarve\Carve\Block\Frame $leaf
     * @param int $owner
     * @param int $top
     * @param int $j
     * @param int $c
     *
     * @return bool
     */
    private function continues(Frame $leaf, int $owner, int $top, int $j, int $c): bool
    {
        $kind = $leaf->kind;
        if ($kind === Frame::PARAGRAPH || $kind === Frame::TERM || $kind === Frame::CAPTION) {
            if ($this->interrupts($leaf, $owner, $j, $c)) {
                return false;
            }
            $this->append($leaf, $j);
            $leaf->n++;

            return true;
        }
        if ($kind === Frame::HEADING) {
            if ($owner !== $top || $c - $this->base <= $leaf->markerCol || $this->interrupts($leaf, $owner, $j, $c)) {
                return false;
            }
            $this->append($leaf, $j);

            return true;
        }
        if ($kind === Frame::TABLE && $owner === $top) {
            return $this->tableLine($leaf, $j);
        }

        return false;
    }

    /**
     * A row or a continuation row of an open table (C2-B-166, C2-B-175).
     *
     * @param \MarkupCarve\Carve\Block\Frame $table
     * @param int $j
     *
     * @return bool
     */
    private function tableLine(Frame $table, int $j): bool
    {
        $ch = $this->s[$j];
        if ($ch !== '|' && $ch !== '+') {
            return false;
        }
        $split = TableBuilder::split($this->s, $j, $this->le);
        if ($split === null) {
            return false;
        }
        if ($ch === '|') {
            $this->addRow($table, $j, $split);

            return true;
        }
        if ($this->s[$this->trimEnd($j) - 1] !== '|') {
            return false;
        }
        for ($k = count($table->rows) - 1; $k >= 0; $k--) {
            if ($table->rows[$k]->isRow) {
                $table->rows[$k]->cont[] = TableBuilder::fragments($this->s, $j, $split['seps']);
                $table->rows[$k]->contSlots[] = array_map(static fn (int $p): int => $p + 1, [$j, ...$split['seps']]);
                $table->rows[$k]->continuationEnd = $this->le;

                break;
            }
        }
        $table->end = $this->le;

        return true;
    }

    /**
     * @param \MarkupCarve\Carve\Block\Frame $table
     * @param int $j
     * @param array{seps: array<int>, end: int, attrs: \MarkupCarve\Carve\Attrs|null, comment: int|null} $split
     *
     * @return void
     */
    private function addRow(Frame $table, int $j, array $split): void
    {
        $row = new TableItem(true, $j, $split['end']);
        $row->cells = TableBuilder::cells($this->s, $j, $split['seps']);
        $row->attrs = $split['attrs'];
        $table->rows[] = $row;
        if ($split['comment'] !== null) {
            $comment = new TableItem(false, $split['end'], $this->le);
            $comment->value = $this->commentValue($split['comment'], $this->trimEnd($j));
            $table->rows[] = $comment;
        }
        $table->end = $this->le;
    }

    /**
     * The six interrupters (C2-B-014 to C2-B-023, C2-B-200), tested in the context of the owner.
     *
     * @param \MarkupCarve\Carve\Block\Frame $leaf
     * @param int $owner
     * @param int $j
     * @param int $c
     *
     * @return bool
     */
    private function interrupts(Frame $leaf, int $owner, int $j, int $c): bool
    {
        $s = $this->s;
        $ch = $s[$j];
        if ($ch === '`' || $ch === ':' || $ch === '%') {
            $div = $ch === ':' ? LineShapes::div($s, $j, $this->le) : null;
            if ($div !== null && $div->bare) {
                $owned = $this->stack[$owner] ?? null;
                if ($owned !== null && $owned->kind === Frame::DIV && $owned->level <= $div->run) {
                    return true;
                }
            }
            if (
                ($ch !== ':' || $this->depthAt($owner) + 1 <= Tables::BLOCK_DEPTH)
                && LineShapes::isFenceLine($s, $j, $this->le)
            ) {
                return true;
            }
        }
        if ($ch === '|') {
            return TableBuilder::split($s, $j, $this->le) !== null;
        }
        if ($ch === '*' && LineShapes::isThematic($s, $j, $this->le)) {
            return true;
        }
        if ($ch === '^') {
            return $this->captionInterrupts($leaf, $owner, $j);
        }
        if ($ch === ':') {
            return $this->descInterrupts($owner, $j, $c);
        }
        if (($ch === '[' || $ch === '*') && $j === $this->ls && $owner === 0 && $this->definitionOpen() && $this->isDefinition($j)) {
            return true;
        }
        // Only a marker below a list, or inside an item, a description or a footnote can interrupt.
        $kind = $this->stack[$owner]->kind;
        if (!isset($this->stack[$owner + 1]) && $kind !== Frame::ITEM && $kind !== Frame::DESC && $kind !== Frame::FOOTNOTE) {
            return false;
        }
        $marker = $this->marker($j);
        if ($marker === null) {
            return false;
        }

        return $this->listInterrupts($marker, $owner, $c);
    }

    /**
     * The list marker at `$j` of the current line, read once per offset.
     *
     * @param int $j
     *
     * @return \MarkupCarve\Carve\Block\Marker|null
     */
    private function marker(int $j): ?Marker
    {
        if ($this->markerAt !== $j) {
            $this->markerAt = $j;
            $this->markerRead = Markers::quickReject($this->s, $j, $this->le) ? null : Markers::read($this->s, $j, $this->le);
        }

        return $this->markerRead;
    }

    /**
     * @return bool
     */
    private function definitionOpen(): bool
    {
        return isset($this->stack[1]) && $this->stack[1]->kind === Frame::FOOTNOTE;
    }

    /**
     * @param int $j
     *
     * @return bool
     */
    private function isDefinition(int $j): bool
    {
        $s = $this->s;
        if ($s[$j] === '*') {
            return LineShapes::abbreviationDef($s, $j, $this->le) !== null;
        }

        return LineShapes::footnoteDef($s, $j, $this->le) !== null || LineShapes::linkDef($s, $j, $this->le) !== null;
    }

    /**
     * C2-B-015, C2-B-020 to C2-B-022, C2-B-056.
     *
     * @param \MarkupCarve\Carve\Block\Marker $marker
     * @param int $owner
     * @param int $c
     *
     * @return bool
     */
    private function listInterrupts(Marker $marker, int $owner, int $c): bool
    {
        $candidate = $this->stack[$owner + 1] ?? null;
        $kind = $this->stack[$owner]->kind;
        $inItem = $kind === Frame::ITEM || $kind === Frame::DESC || $kind === Frame::FOOTNOTE;
        if ($candidate !== null) {
            if ($candidate->kind === Frame::LIST) {
                return true;
            }
            // C2-B-228: only a marker body, not a quote, loses its nested list at its column.
            $body = $candidate->kind === Frame::DLIST ? $this->stack[$owner + 2] ?? null : $candidate;
            if ($body !== null && ($body->kind === Frame::ITEM || $body->kind === Frame::DESC || $body->kind === Frame::FOOTNOTE)) {
                for ($k = $owner + 2, $n = count($this->stack); $k < $n; $k++) {
                    if ($this->stack[$k]->kind === Frame::LIST) {
                        return true;
                    }
                }
            }

            // C2-B-022 holds at the description list's column; left of it C2-B-015 nests in the body.
            $leftOfList = $candidate->kind === Frame::DLIST && $c - $this->base < $candidate->markerCol;
            if ($leftOfList && ($body !== null && $body->kind === Frame::DESC || $this->leaf?->kind === Frame::TERM)) {
                return $inItem && $this->mayNest($marker) && $this->canNestAt($owner);
            }

            // C2-B-021: a marker without the quote mark closes a quote inside an item, when it may open a nested list (C2-B-056).
            return $candidate->kind === Frame::QUOTE && $inItem && $this->mayNest($marker) && $this->canNestAt($owner);
        }

        return $inItem && $this->mayNest($marker) && $this->canNestAt($owner);
    }

    /**
     * C2-B-190: a refused two-container marker stays text in an open paragraph.
     *
     * @param int $owner
     *
     * @return bool
     */
    private function canNestAt(int $owner): bool
    {
        return $this->depthAt($owner) + 2 <= Tables::BLOCK_DEPTH;
    }

    /**
     * Count the open containers that own this line. Unowned quote or item
     * frames close before a new block is read at the line's column.
     */
    private function depthAt(int $owner): int
    {
        $depth = $this->depth;
        for ($k = count($this->stack) - 1; $k > $owner; $k--) {
            if (isset(self::COUNTED[$this->stack[$k]->kind])) {
                $depth--;
            }
        }

        return $depth;
    }

    /**
     * C2-B-056: a bullet or the value 1 opens a nested list without a blank line.
     *
     * @param \MarkupCarve\Carve\Block\Marker $marker
     *
     * @return bool
     */
    private function mayNest(Marker $marker): bool
    {
        return !$marker->ordered || $marker->value === 1 || $marker->value === -1;
    }

    /**
     * C2-B-016, C2-B-022, C2-B-023: a mark at an open description list's column.
     *
     * @param int $owner
     * @param int $j
     * @param int $c
     *
     * @return bool
     */
    private function descInterrupts(int $owner, int $j, int $c): bool
    {
        if ($this->descMark($j) === null) {
            return false;
        }
        $candidate = $this->stack[$owner + 1] ?? null;

        return $candidate !== null && $candidate->kind === Frame::DLIST && $c - $this->base === $candidate->markerCol;
    }

    /**
     * C2-B-067, C2-B-194: a term mark `::` or a description mark `:`, a space and content.
     * Returns [isTerm, contentOffset] or null.
     *
     * @param int $j
     *
     * @return array{0: bool, 1: int}|null
     */
    private function descMark(int $j): ?array
    {
        $s = $this->s;
        $le = $this->le;
        $term = $j + 1 < $le && $s[$j + 1] === ':';
        $k = $term ? $j + 2 : $j + 1;
        if ($k >= $le || $s[$k] !== ' ') {
            return null;
        }
        $k += strspn($s, ' ', $k, $le - $k);
        if ($k >= $le || $this->trimEnd($k) === $k) {
            return null;
        }

        return [$term, $k];
    }

    /**
     * C2-B-017, C2-B-018: a caption line interrupts one block image or math line, and closes a quote.
     *
     * @param \MarkupCarve\Carve\Block\Frame $leaf
     * @param int $owner
     * @param int $j
     *
     * @return bool
     */
    private function captionInterrupts(Frame $leaf, int $owner, int $j): bool
    {
        if ($leaf->kind !== Frame::PARAGRAPH || LineShapes::caption($this->s, $j, $this->le) < 0) {
            return false;
        }
        if ($owner === count($this->stack) - 1 && $leaf->n === 1 && $this->role($leaf) !== null) {
            return true;
        }
        $candidate = $this->stack[$owner + 1] ?? null;

        return $candidate !== null && $candidate->kind === Frame::QUOTE;
    }

    /**
     * C2-B-157, C2-B-158, C2-B-197, C2-B-198: the role of a paragraph of exactly one such line.
     *
     * @param \MarkupCarve\Carve\Block\Frame $p
     *
     * @return string|null
     */
    private function role(Frame $p): ?string
    {
        if ($p->n !== 1) {
            return null;
        }
        $a = $p->spans[0];
        $b = $p->spans[1];
        $ch = $this->s[$a] ?? '';
        if ($ch !== '!' && $ch !== '$') {
            return null;
        }
        if (LineShapes::isBlockImage($this->s, $a, $b)) {
            return 'image';
        }

        return LineShapes::isDisplayMath($this->s, $a, $b) ? 'math' : null;
    }

    /**
     * Opens the blocks of a line that no open leaf takes, in the context of its owner.
     *
     * @param int $owner
     * @param int $j
     * @param int $c
     *
     * @return void
     */
    private function openLine(int $owner, int $j, int $c): void
    {
        if ($this->pending !== null && $this->pending->depth > $owner) {
            $this->flushPending();
        }
        $ch = $this->s[$j];
        if ($ch === '-' || $ch === '*' || $ch === '.' || ctype_alnum($ch)) {
            $marker = $this->marker($j);
            if ($marker !== null) {
                $this->openMarker($owner, $marker, $j, $c);

                return;
            }
        }
        if ($ch === ':' && $this->openDescription($owner, $j, $c)) {
            return;
        }
        $this->closeTo($owner);
        $this->openBlock($j, $c);
    }

    /**
     * C2-B-049 to C2-B-055: a sibling item, or a new list.
     *
     * @param int $owner
     * @param \MarkupCarve\Carve\Block\Marker $m
     * @param int $j
     * @param int $c
     *
     * @return void
     */
    private function openMarker(int $owner, Marker $m, int $j, int $c): void
    {
        $candidate = $this->stack[$owner + 1] ?? null;
        if (
            $candidate !== null && $candidate->kind === Frame::LIST && $this->blank < Tables::LIST_END_BLANK_LINES
            && $candidate->marker !== null && Markers::matches($candidate->marker, $m)
        ) {
            $this->closeTo($owner + 1);
            if ($this->depth + 1 > Tables::BLOCK_DEPTH) {
                $this->closeTo($owner);
                $this->openParagraph($j);

                return;
            }
            if ($this->blank > 0) {
                $candidate->loose = true;
            }
            $this->openItem($candidate, $m, $j, $c);

            return;
        }
        $this->closeTo($owner);
        // C2-B-190: a marker that opens a list and its item is tested as one.
        if ($this->depth + 2 > Tables::BLOCK_DEPTH) {
            $this->openParagraph($j);

            return;
        }
        $list = new Frame(Frame::LIST, $j);
        $list->key = $m->key;
        $list->markerCol = $c - $this->base;
        $list->marker = $m;
        $list->end = $m->end;
        $this->take($list);
        $this->push($list);
        $this->openItem($list, $m, $j, $c);
    }

    /**
     * @param \MarkupCarve\Carve\Block\Frame $list
     * @param \MarkupCarve\Carve\Block\Marker $m
     * @param int $j
     * @param int $c
     *
     * @return void
     */
    private function openItem(Frame $list, Marker $m, int $j, int $c): void
    {
        $item = new Frame(Frame::ITEM, $j);
        $item->markerCol = $c - $this->base;
        $item->contentCol = $item->markerCol + ($m->box - $j);
        $item->task = $m->task;
        $item->attrs = $m->attrs;
        $item->end = $m->end;
        $list->items++;
        $this->push($item);
        $content = $m->content;
        $this->i = $content;
        $this->col = $c + ($content - $j);
        $this->openLine(count($this->stack) - 1, $content, $this->col);
    }

    /**
     * C2-B-067 to C2-B-080: a term or a description.
     *
     * @param int $owner
     * @param int $j
     * @param int $c
     *
     * @return bool
     */
    private function openDescription(int $owner, int $j, int $c): bool
    {
        $mark = $this->descMark($j);
        if ($mark === null) {
            return false;
        }
        [$term, $content] = $mark;
        $candidate = $this->stack[$owner + 1] ?? null;
        if (
            $candidate !== null && $candidate->kind === Frame::DLIST && $c - $this->base === $candidate->markerCol
            && $this->blank < Tables::LIST_END_BLANK_LINES
        ) {
            $this->closeTo($owner + 1);
            if ($term) {
                $this->openTerm($j, $content);

                return true;
            }

            return $this->openDesc($j, $c, $content);
        }
        // C2-B-074: a description line with no term above it is text.
        if (!$term) {
            return false;
        }
        $this->closeTo($owner);
        if ($this->depth + 1 > Tables::BLOCK_DEPTH) {
            return false;
        }
        $list = new Frame(Frame::DLIST, $j);
        $list->markerCol = $c - $this->base;
        $list->end = $j + 2;
        $this->take($list);
        $this->push($list);
        $this->openTerm($j, $content);

        return true;
    }

    /**
     * C2-B-069, C2-B-075: a term is one inline leaf and takes no block opener.
     *
     * @param int $j
     * @param int $content
     *
     * @return void
     */
    private function openTerm(int $j, int $content): void
    {
        $f = new Frame(Frame::TERM, $j);
        $f->end = $this->le;
        // C2-B-069: spaces and tabs behind the mark's one space stand before the content column.
        $content += strspn($this->s, " \t", $content, max(0, $this->le - $content));
        $f->spans = [$content, $f->end];
        $f->n = 1;
        $this->take($f);
        $this->startLeaf($f);
    }

    /**
     * @param int $j
     * @param int $c
     * @param int $content
     *
     * @return bool
     */
    private function openDesc(int $j, int $c, int $content): bool
    {
        if ($this->depth + 1 > Tables::BLOCK_DEPTH) {
            return false;
        }
        $f = new Frame(Frame::DESC, $j);
        $f->markerCol = $c - $this->base;
        $f->contentCol = $f->markerCol + ($content - $j);
        $f->end = $j + 1;
        $this->take($f);
        $this->push($f);
        $this->i = $content;
        $this->col = $c + ($content - $j);
        // C2-I-002: a tab before the first content of the description is indent, as behind a list marker.
        $k = $content;
        $kc = $this->col;
        $this->skipWs($k, $kc);
        $this->openLine(count($this->stack) - 1, $k, $kc);

        return true;
    }

    /**
     * Opens a block other than a list item or a description entry in the innermost container.
     *
     * @param int $j
     * @param int $c
     *
     * @return void
     */
    private function openBlock(int $j, int $c): void
    {
        $s = $this->s;
        $done = match ($s[$j]) {
            '>' => $this->openQuote($j, $c),
            '`' => $this->openCode($j, $c),
            ':' => $this->openDiv($j, $c),
            '%' => $this->openPercent($j, $c),
            '=', '#' => $this->openHeading($j, $c),
            '|' => $this->openTable($j),
            '*' => $this->openStar($j),
            '^' => $this->openCaption($j),
            '[' => $this->openDefinition($j, $c),
            '{' => $this->openBrace($j),
            default => false,
        };
        if (!$done) {
            $this->openParagraph($j);
        }
    }

    /**
     * @param int $j
     *
     * @return void
     */
    private function openParagraph(int $j): void
    {
        $p = new Frame(Frame::PARAGRAPH, $j);
        $p->end = $this->le;
        $p->spans = [$j, $p->end];
        $p->n = 1;
        $this->take($p);
        $this->startLeaf($p);
    }

    /**
     * C2-B-031, C2-B-136.
     *
     * @param int $j
     * @param int $c
     *
     * @return bool
     */
    private function openQuote(int $j, int $c): bool
    {
        $s = $this->s;
        if (($j + 1 < $this->le && $s[$j + 1] !== ' ') || $this->depth + 1 > Tables::BLOCK_DEPTH) {
            return false;
        }
        $quote = new Frame(Frame::QUOTE, $j);
        $quote->end = $j + 1 < $this->le ? $j + 2 : $j + 1;
        $this->take($quote);
        $this->push($quote);
        $this->i = $j;
        $this->col = $c;
        $this->matchQuote();
        if (LineShapes::trimEnd($s, $this->i, $this->le) === $this->i) {
            // C2-A-151: the empty quote owns spaces behind its final marker.
            $quote->end = $this->le;
        }
        $k = $this->i;
        $kc = $this->col;
        $this->skipWs($k, $kc);
        if ($k < $this->le) {
            $this->openLine(count($this->stack) - 1, $k, $kc);
        }

        return true;
    }

    /**
     * C2-B-108, C2-B-116, C2-B-117, C2-B-180.
     *
     * @param int $j
     * @param int $c
     *
     * @return bool
     */
    private function openCode(int $j, int $c): bool
    {
        $info = LineShapes::codeFence($this->s, $j, $this->le);
        if ($info === null) {
            return false;
        }
        $f = new Frame(Frame::CODE, $j);
        $f->level = $info->run;
        $f->indent = $c - $this->col;
        $f->openCol = $c - $this->base;
        $f->end = $this->le;
        $syntax = $info->syntax;
        $f->syntax = $syntax;
        $f->raw = $info->raw;
        $f->title = $info->title;
        $f->label = $info->label;
        $this->openerFinding($f, $info);
        $this->take($f);
        if ($f->attrs !== null && ($syntax === 'csv' || $syntax === 'tsv') && $f->attrs->get('table') !== null) {
            $f->delimited = $syntax === 'csv' ? ',' : "\t";
        }
        $this->startLeaf($f);

        return true;
    }

    /**
     * C2-B-119, C2-B-125 to C2-B-129, C2-B-196.
     *
     * @param int $j
     * @param int $c
     *
     * @return bool
     */
    private function openDiv(int $j, int $c): bool
    {
        $info = LineShapes::div($this->s, $j, $this->le);
        if ($info === null) {
            return false;
        }
        $run = $info->run;
        if ($info->bare && $this->closeDiv($run, $this->le)) {
            return true;
        }
        if ($this->depth + 1 > Tables::BLOCK_DEPTH) {
            return false;
        }
        $sigil = $info->sigil;
        $kind = $sigil === '|' ? Frame::LINE_BLOCK : Frame::DIV;
        $f = new Frame($kind, $j);
        $f->level = $run;
        $f->indent = $c - $this->col;
        $f->openCol = $c - $this->base;
        $f->end = $this->le;
        $this->divKind($f, $info);
        $this->openerFinding($f, $info);
        $this->take($f);
        if ($kind === Frame::LINE_BLOCK) {
            $this->startLeaf($f);

            return true;
        }
        $this->push($f);

        return true;
    }

    /**
     * C2-B-105: a brace block behind a fence language or an admonition name, or glued to any name, is an attribute block the
     * opener does not take; C2-B-122, C2-B-225: any other further words.
     *
     * @param \MarkupCarve\Carve\Block\Frame $f
     * @param \MarkupCarve\Carve\Block\Opener $info
     *
     * @return void
     */
    private function openerFinding(Frame $f, Opener $info): void
    {
        $at = $info->extra;
        if ($at < 0) {
            return;
        }
        $brace = $this->s[$at] === '{' && AttributeParser::read($this->s, $at, $this->le, new Attrs()) > $at;
        $f->findings[] = [
            $brace && $at === $info->wordEnd
            ? 'fence-opener-fallback' : 'fence-title-syntax', $at,
        ];
    }

    /**
     * C2-B-125: a bare run closes the innermost open div it is long enough for.
     *
     * @param int $run
     * @param int $end
     *
     * @return bool
     */
    private function closeDiv(int $run, int $end): bool
    {
        for ($k = count($this->stack) - 1; $k > 0; $k--) {
            $f = $this->stack[$k];
            if ($f->kind !== Frame::DIV) {
                return false;
            }
            if ($f->level <= $run) {
                // C2-A-154: only this div has a closer; inner divs end at their last child.
                $f->end = $end;
                $f->closed = true;
                $this->closeTo($k - 1);

                return true;
            }
        }

        return false;
    }

    /**
     * C2-B-127, C2-B-128: the node a div name or sigil gives.
     *
     * @param \MarkupCarve\Carve\Block\Frame $f
     * @param \MarkupCarve\Carve\Block\Opener $info
     *
     * @return void
     */
    private function divKind(Frame $f, Opener $info): void
    {
        $f->node = 'div';
        $f->title = $info->title;
        $f->label = $info->label;
        $sigil = $info->sigil;
        if ($sigil === '>') {
            $f->node = 'block_quote';

            return;
        }
        if ($sigil === '\\') {
            $f->sigilClass = 'hardbreaks';

            return;
        }
        if ($sigil === '|') {
            $f->node = 'line_block';

            return;
        }
        if ($info->name === null) {
            return;
        }
        $name = $info->name;
        $kind = Tables::DIV_NAME_KINDS[$name] ?? null;
        if ($kind === 'admonition' || $kind === 'directive') {
            $f->node = $kind;
            $f->divKind = $name;
        } elseif ($kind === 'figure_group' && $info->title === null && $info->label === null) {
            $insideGroup = false;
            foreach ($this->stack as $open) {
                if ($open->node === 'figure_group') {
                    $insideGroup = true;

                    break;
                }
            }
            if ($insideGroup) {
                $f->nameClass = 'figure';
            } else {
                $f->node = 'figure_group';
            }
        } else {
            $f->nameClass = $name;
        }
    }

    /**
     * C2-B-108: a percent run of three or more opens a comment block; `%%` after a marker is a comment (C2-B-136).
     *
     * @param int $j
     * @param int $c
     *
     * @return bool
     */
    private function openPercent(int $j, int $c): bool
    {
        $s = $this->s;
        $n = strspn($s, '%', $j, $this->le - $j);
        if ($n === 2) {
            $this->emitComment($j, $this->trimEnd($j));

            return true;
        }
        if ($n < 3) {
            return false;
        }
        $f = new Frame(Frame::COMMENT_BLOCK, $j);
        $f->level = $n;
        $f->indent = $c - $this->col;
        $f->openCol = $c - $this->base;
        $f->end = $this->le;
        $k = $j + $n;
        $k += strspn($s, " \t", $k, $this->le - $k);
        // C2-B-108: only the spaces and tabs before the opener text are dropped.
        if ($k < $this->le) {
            $f->spans = [$k, $this->le];
        }
        $this->take($f);
        $this->startLeaf($f);

        return true;
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
        $n = strspn($s, $s[$j], $j, $this->le - $j);
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
        $f->end = $this->le;
        $f->spans = [$k, $f->end];
        $this->take($f);
        $this->startLeaf($f);

        return true;
    }

    /**
     * @param int $j
     *
     * @return bool
     */
    private function openTable(int $j): bool
    {
        $split = TableBuilder::split($this->s, $j, $this->le);
        if ($split === null) {
            return false;
        }
        $f = new Frame(Frame::TABLE, $j);
        $this->addRow($f, $j, $split);
        $this->take($f);
        $this->startLeaf($f);

        return true;
    }

    /**
     * A thematic break (C2-B-159) or an abbreviation definition (C2-B-084).
     *
     * @param int $j
     *
     * @return bool
     */
    private function openStar(int $j): bool
    {
        if (LineShapes::isThematic($this->s, $j, $this->le)) {
            $f = new Frame(Frame::THEMATIC, $j);
            $f->end = $this->le;
            $this->take($f);
            $this->emitLeaf($f);

            return true;
        }
        if (!$this->atDefinitionColumn($j)) {
            return false;
        }
        $def = LineShapes::abbreviationDef($this->s, $j, $this->le);
        if ($def === null) {
            return false;
        }
        $f = new Frame(Frame::ABBR_DEF, $j);
        $f->value = $def['key'];
        $f->href = $def['expansion'];
        $f->end = $this->le;
        $this->take($f);
        $this->emitLeaf($f);

        return true;
    }

    /**
     * C2-B-081: a definition stands at column 0 outside every container.
     *
     * @param int $j
     *
     * @return bool
     */
    private function atDefinitionColumn(int $j): bool
    {
        return $j === $this->ls && count($this->stack) === 1;
    }

    /**
     * C2-B-146 to C2-B-149, C2-B-096.
     *
     * @param int $j
     *
     * @return bool
     */
    private function openCaption(int $j): bool
    {
        $content = LineShapes::caption($this->s, $j, $this->le);
        if ($content < 0) {
            return false;
        }
        if ($this->pending !== null) {
            $this->flushPending();
        }
        $target = $this->closed;
        if ($target === null || $this->closedParent !== $this->stack[count($this->stack) - 1] || !$this->captionable($target)) {
            return false;
        }
        $f = new Frame(Frame::CAPTION, $j);
        $f->end = $this->le;
        $f->spans = [$content, $f->end];
        $f->target = $target;
        $this->startLeaf($f);

        return true;
    }

    /**
     * C2-B-147: an image, a table, a code block, a quote, a math block or a figure group.
     *
     * @param \MarkupCarve\Carve\Block\Frame $f
     *
     * @return bool
     */
    private function captionable(Frame $f): bool
    {
        return match ($f->kind) {
            Frame::TABLE, Frame::QUOTE => true,
            Frame::CODE => $f->raw === null,
            Frame::INCLUDE => is_array($f->include['options'] ?? null) && isset($f->include['options']['code']),
            Frame::DIV => $f->node === 'block_quote' || $f->node === 'figure_group',
            Frame::PARAGRAPH => $f->role !== null,
            default => false,
        };
    }

    /**
     * C2-B-081 to C2-B-087: a footnote definition or a link definition.
     *
     * @param int $j
     * @param int $c
     *
     * @return bool
     */
    private function openDefinition(int $j, int $c): bool
    {
        if (!$this->atDefinitionColumn($j)) {
            return false;
        }
        $s = $this->s;
        $fn = LineShapes::footnoteDef($s, $j, $this->le);
        if ($fn !== null) {
            $f = new Frame(Frame::FOOTNOTE, $j);
            $f->value = $fn['key'];
            $f->markerCol = 0;
            $f->contentCol = $c + $fn['content'] - $j;
            $f->end = $fn['content'];
            $this->take($f);
            $this->push($f);
            $this->i = $fn['content'];
            $this->col = $f->contentCol;
            $this->openLine(1, $fn['content'], $this->col);

            return true;
        }
        $def = LineShapes::linkDef($s, $j, $this->le);
        if ($def === null) {
            return false;
        }
        $f = new Frame(Frame::LINK_DEF, $j);
        $f->value = $def['key'];
        $f->href = $def['href'];
        $f->tooltip = $def['tooltip'] ?? null;
        $f->end = $this->le;
        $this->take($f);
        if (isset($def['attrs'])) {
            $f->attrs ??= new Attrs();
            $f->attrs->merge($def['attrs']);
        }
        $this->emitLeaf($f);

        return true;
    }

    /**
     * An attribute line (C2-B-090 to C2-B-102) or an include directive (C2-B-156, C2-B-201).
     *
     * @param int $j
     *
     * @return bool
     */
    private function openBrace(int $j): bool
    {
        $attrs = new Attrs();
        $top = count($this->stack) - 1;
        if (LineShapes::attributeLine($this->s, $j, $this->le, $attrs)) {
            $end = $this->le;
            $pending = $this->pending;
            if ($pending !== null && $pending->depth === $top && $pending->attrs !== null) {
                $pending->attrs->merge($attrs);
                $pending->spans[] = $j;
                $pending->spans[] = $end;
                $pending->end = $end;

                return true;
            }
            $pending = new Frame(Frame::ATTR_PARAGRAPH, $j);
            $pending->attrs = $attrs;
            $pending->spans = [$j, $end];
            $pending->end = $end;
            $pending->depth = $top;
            $this->pending = $pending;
            $this->closed = null;

            return true;
        }
        $inc = IncludeShape::parse($this->s, $j, $this->le);
        if ($inc === null) {
            return false;
        }
        $f = new Frame(Frame::INCLUDE, $j);
        $f->include = $inc;
        $f->end = $this->le;
        $this->take($f);
        $this->emitLeaf($f);

        return true;
    }

    /**
     * A line owned by the container of an open opaque leaf.
     *
     * @param \MarkupCarve\Carve\Block\Frame $f
     *
     * @return void
     */
    private function opaqueLine(Frame $f): void
    {
        // G-blocks-7: an opener left of the content column strips the body only to its own column.
        if ($f->kind !== Frame::LINE_BLOCK && $this->stripI >= 0 && $this->col - $this->base > $f->openCol) {
            $this->i = $this->stripI;
            $this->col = $this->stripCol;
        }
        if ($f->kind !== Frame::LINE_BLOCK && $this->stripI >= 0) {
            // C2-B-029: a tab may leave container matching short of the fence opener column.
            $this->stripTo($f->openCol);
        }
        $s = $this->s;
        $j = $this->i;
        $c = $this->col;
        $w = strspn($this->s, ' ', $j, $this->le - $j);
        $j += $w;
        $c += $w;
        if ($j < $this->le && $this->s[$j] === "\t") {
            $this->skipWs($j, $c);
        }
        if ($f->kind === Frame::CODE || $f->kind === Frame::COMMENT_BLOCK) {
            $this->fenceBodyLine($f, $j);

            return;
        }
        $end = $this->trimEnd($j);
        switch ($f->kind) {
            case Frame::FRONTMATTER:
                // C2-B-162.
                if ($this->ls === $j && $end - $j === 3 && substr($s, $j, 3) === '---') {
                    $f->end = $this->le;
                    $f->closed = true;
                    $this->leaf = null;
                    $this->emitLeaf($f);

                    return;
                }
                $f->spans[] = $this->ls;
                $f->spans[] = $this->le;
                $f->end = $this->le;

                return;
            case Frame::LINE_BLOCK:
                $this->lineBlockBodyLine($f, $j, $end);

                return;
        }
    }

    private function lineBlockBodyLine(Frame $f, int $j, int $end): void
    {
        $s = $this->s;
        if ($j < $end && $s[$j] === ':' && strspn($s, ':', $j, $end - $j) === $end - $j && $end - $j >= $f->level) {
            if ($f->lineComment !== null) {
                $commentEnd = $this->ls;
                if ($commentEnd > $f->lineComment->start && $s[$commentEnd - 1] === "\n") {
                    $commentEnd--;
                }
                if ($commentEnd > $f->lineComment->start && $s[$commentEnd - 1] === "\r") {
                    $commentEnd--;
                }
                $f->lineComment->end = $commentEnd;
                $f->hiddenComments[] = $f->lineComment;
                $f->lineComment = null;
            }
            $this->closeOpaque($f, $this->le);

            return;
        }
        $comment = $f->lineComment;
        if ($comment !== null) {
            if (
                $j < $end && $s[$j] === '%' && strspn($s, '%', $j, $end - $j) === $end - $j
                && $end - $j >= $comment->level
            ) {
                $comment->end = $this->le;
                $comment->closed = true;
                $f->hiddenComments[] = $comment;
                $f->lineComment = null;
            } else {
                $comment->spans[] = $this->i;
                $comment->spans[] = $this->le;
                $comment->end = $this->le;
            }
            $f->end = $this->le;

            return;
        }
        $run = $j < $end && $s[$j] === '%' ? strspn($s, '%', $j, $end - $j) : 0;
        if ($run >= 3) {
            $comment = new Frame(Frame::COMMENT_BLOCK, $j);
            $comment->level = $run;
            $comment->end = $this->le;
            $text = $j + $run + strspn($s, " \t", $j + $run, $end - $j - $run);
            if ($text < $end) {
                $comment->spans = [$text, $this->le];
            }
            $f->lineComment = $comment;
            $f->end = $this->le;

            return;
        }
        $f->spans[] = $this->i;
        $f->spans[] = $this->le;
        if ($j < $end) {
            $f->end = $this->le;
        }
    }

    /**
     * A body line of a code fence or comment block (C2-B-109, C2-B-110, C2-B-029, C2-B-113).
     *
     * @param \MarkupCarve\Carve\Block\Frame $f
     * @param int $j First byte after the leading spaces and tabs.
     *
     * @return void
     */
    private function fenceBodyLine(Frame $f, int $j): void
    {
        $s = $this->s;
        $ch = $f->kind === Frame::CODE ? '`' : '%';
        if ($j < $this->le && $s[$j] === $ch) {
            // C2-B-109, C2-B-110: a bare run of the opener's character, at least as long.
            $end = $this->trimEnd($j);
            if (strspn($s, $ch, $j, $end - $j) === $end - $j && $end - $j >= $f->level) {
                $f->closed = true;
                $this->closeOpaque($f, $this->le);

                return;
            }
        }
        // C2-B-029: the body is stripped by at most the indent of its opener.
        if ($f->indent > 0 && $this->stripI < 0) {
            $this->stripTo($this->col - $this->base + $f->indent);
        }
        $f->spans[] = $this->i;
        $f->spans[] = $this->le;
        if ($j < $this->le) {
            $f->end = $this->le;
            $this->blank = 0;
        } else {
            // C2-B-113: held back blank lines count as blank lines above the next line.
            $this->blank++;
        }
    }

    /**
     * @param \MarkupCarve\Carve\Block\Frame $f
     * @param int $end
     *
     * @return void
     */
    private function closeOpaque(Frame $f, int $end): void
    {
        $this->blank = 0;
        $f->end = $end;
        $f->closed = true;
        $this->leaf = null;
        $this->emitLeaf($f);
    }

    /**
     * Blocks the pending attribute lines go to (C2-B-091): the next block of the same container.
     *
     * @param \MarkupCarve\Carve\Block\Frame $f
     *
     * @return void
     */
    private function take(Frame $f): void
    {
        $p = $this->pending;
        if ($p === null || $p->depth !== count($this->stack) - 1) {
            return;
        }
        $this->pending = null;
        $f->attrs = $p->attrs;
        $f->own = $f->start;
        $f->start = $p->start;
    }

    /**
     * C2-B-092, C2-B-192: attribute lines that no block takes become one closed paragraph.
     *
     * @return void
     */
    private function flushPending(): void
    {
        $p = $this->pending;
        if ($p === null) {
            return;
        }
        $this->pending = null;
        $p->attrs = null;
        $p->kind = Frame::PARAGRAPH;
        $p->n = 2;
        $this->emitLeaf($p);
    }

    /**
     * @param \MarkupCarve\Carve\Block\Frame $f
     *
     * @return void
     */
    private function startLeaf(Frame $f): void
    {
        $this->closed = null;
        $this->leaf = $f;
    }

    /**
     * @param \MarkupCarve\Carve\Block\Frame $f
     *
     * @return void
     */
    private function push(Frame $f): void
    {
        $parent = $this->stack[count($this->stack) - 1];
        $f->depth = count($this->stack);
        $this->stack[] = $f;
        if (isset(self::COUNTED[$f->kind])) {
            $this->depth++;
        }
        $this->closed = null;
        $this->sink->enter($f, $parent);
    }

    /**
     * @param \MarkupCarve\Carve\Block\Frame $f
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
        $this->closed = $f;
        $this->closedParent = $parent;
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
        $kind = $leaf->kind;
        if ($kind === Frame::PARAGRAPH) {
            $leaf->role = $this->role($leaf);
        } elseif (($kind === Frame::CODE || $kind === Frame::COMMENT_BLOCK || $kind === Frame::LINE_BLOCK) && !$leaf->closed) {
            $this->trimHeldBlank($leaf);
        } elseif ($kind === Frame::CAPTION) {
            $parent = $this->stack[count($this->stack) - 1];
            $target = $leaf->target;
            assert($target !== null);
            if ($leaf->end > $parent->end) {
                $parent->end = $leaf->end;
            }
            $this->sink->caption($leaf, $parent, $target);
            $this->closed = null;

            return;
        }
        $this->emitLeaf($leaf);
    }

    /**
     * C2-B-113: blank lines at the end of an unclosed code fence, comment block or line block are not body.
     *
     * @param \MarkupCarve\Carve\Block\Frame $f
     *
     * @return void
     */
    private function trimHeldBlank(Frame $f): void
    {
        $s = $this->s;
        $n = count($f->spans);
        while ($n >= 2 && LineShapes::trimEnd($s, $f->spans[$n - 2], $f->spans[$n - 1]) === $f->spans[$n - 2]) {
            $n -= 2;
        }
        $f->spans = array_slice($f->spans, 0, $n);
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
        // C2-B-192: attribute lines that no block took stay in the container that ends.
        if ($this->pending !== null && $this->pending->depth > $keep) {
            $this->flushPending();
        }
        for ($k = $n; $k > $keep; $k--) {
            $f = $this->stack[$k];
            array_pop($this->stack);
            if (isset(self::COUNTED[$f->kind])) {
                $this->depth--;
            }
            $this->sink->exit($f);
            $parent = $this->stack[$k - 1];
            if ($f->end > $parent->end) {
                $parent->end = $f->end;
            }
            $this->closed = $f;
            $this->closedParent = $parent;
        }
    }
}
