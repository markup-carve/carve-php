<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

/**
 * Writes Markdown list item markers the way `carve fmt` does, one container at
 * a time, for MarkdownToCarve.
 *
 * Ordered items are numbered on from the list's first number. A bullet or
 * delimiter change starts a new list in GFM as in Carve, so `separate` asks for
 * the blank line fmt puts between the two; and since `+` is written as `-`, a
 * new list whose bullet would come out the same as the list above flips to
 * `*`, or the two would merge.
 *
 * A marker of another width moves the item's content column. The caller moves
 * the lines the item holds with it, by what `shiftAt()` answers for them.
 *
 * @internal
 */
class MarkdownListMarkers
{
    /**
     * Open lists, outermost first. `col` is the marker column and `content`
     * the item's content column in the source; `outer` is how far the marker
     * line moved and `shift` how far the lines the item holds do.
     *
     * @var array<int, array{col: int, content: int, shift: int, outer: int, kind: string, bullet: string, next: int, restart: bool}>
     */
    protected array $open = [];

    /**
     * A block at `$col` ends every list whose items sit at or past it.
     */
    public function end(int $col): void
    {
        $last = array_key_last($this->open);
        while ($last !== null && $this->open[$last]['col'] >= $col) {
            array_pop($this->open);
            $last = array_key_last($this->open);
        }
    }

    /**
     * A line at `$col` outside the open items ends the lists holding them. They
     * stay open for spacing, but an item after them numbers from its own marker.
     */
    public function leave(int $col): void
    {
        foreach ($this->open as $at => $list) {
            if ($list['content'] > $col) {
                $this->open[$at]['restart'] = true;
            }
        }
    }

    /**
     * How far a line indented `$col` columns in the source moves: by the shift
     * of the innermost open item holding it.
     */
    public function shiftAt(int $col): int
    {
        for ($at = count($this->open) - 1; $at >= 0; $at--) {
            if ($this->open[$at]['content'] <= $col) {
                return $this->open[$at]['shift'];
            }
        }

        return 0;
    }

    /**
     * The content column of the innermost open item holding a line at `$col`,
     * or 0 when no open item holds it.
     */
    public function contentAt(int $col): int
    {
        for ($at = count($this->open) - 1; $at >= 0; $at--) {
            if ($this->open[$at]['content'] <= $col) {
                return $this->open[$at]['content'];
            }
        }

        return 0;
    }

    /**
     * Whether a line at `$col` is held by the innermost open item.
     */
    public function holdsItemAt(int $col): bool
    {
        $last = array_key_last($this->open);

        return $last !== null && $this->open[$last]['content'] <= $col;
    }

    /**
     * Whether a marker at `$col` would belong to an open list's level: the list
     * open directly in the item holding the marker, when the marker is within
     * three columns of that item's content.
     */
    public function hasListAt(int $col): bool
    {
        $parent = count($this->open) - 1;
        while ($parent >= 0 && $this->open[$parent]['content'] > $col) {
            $parent--;
        }

        return isset($this->open[$parent + 1]) && $col - ($this->open[$parent]['content'] ?? 0) <= 3;
    }

    /**
     * Write the marker of an item line. `$onePad` asks for two to four spaces
     * of padding to be written as one, as fmt writes them. A `$fixed` item
     * keeps its column and the width of its marker, so nothing it holds moves.
     *
     * @return array{line: string, separate: bool, outer: int, shift: int, content: int}
     */
    public function write(string $line, bool $onePad = false, bool $fixed = false): array
    {
        if (preg_match('/^([ \t]*)(?:([-*+])|(\d+)([.)]))(?=[ \t])/', $line, $m) !== 1) {
            return ['line' => $line, 'separate' => false, 'outer' => 0, 'shift' => 0, 'content' => 0];
        }
        $col = self::columns($m[1]);
        // The item holding the marker, and the list open directly in it. A
        // marker up to three columns past that item's content belongs to that
        // list's level, wherever the list's own markers sit (CommonMark 5.2).
        $parent = count($this->open) - 1;
        while ($parent >= 0 && $this->open[$parent]['content'] > $col) {
            $parent--;
        }
        $level = $this->open[$parent + 1] ?? null;
        $this->open = array_slice($this->open, 0, $parent + 1);
        $slack = $col - ($this->open[$parent]['content'] ?? 0);
        $prev = $level !== null && $slack <= 3 ? $level : null;
        $bulletChar = $m[2] ?? '';
        $kind = $bulletChar !== '' ? $bulletChar : ($m[4] ?? '');
        $same = $prev !== null && $prev['kind'] === $kind;
        // A sibling goes where its list's markers were written, and a new list
        // to its container's content column, without the slack.
        $outer = $same ? $prev['col'] + $prev['outer'] - $col : $this->shiftAt($col) - ($slack <= 3 ? $slack : 0);
        // Even a fixed item goes to its siblings' column, or it is no sibling.
        if ($fixed) {
            $outer = $same ? $prev['col'] + $prev['outer'] - $col : 0;
            $onePad = false;
        }
        $bullet = '';
        $next = 0;
        if ($bulletChar !== '') {
            $bullet = $same ? $prev['bullet'] : ($bulletChar === '+' ? '-' : $bulletChar);
            if (!$same && $prev !== null && $prev['bullet'] === $bullet) {
                $bullet = $bullet === '-' ? '*' : '-';
            }
            $marker = $bullet;
        } else {
            $digits = $m[3] ?? '';
            $number = $same && !$prev['restart'] ? $prev['next'] : (int)$digits;
            $marker = ($fixed && strlen((string)$number) !== strlen($digits) ? $digits : $number) . ($m[4] ?? '');
            $next = $number + 1;
        }
        $after = substr($line, strlen($m[0]));
        $pad = $onePad && preg_match('/^ {2,4}(?=\S)/', $after, $padding) === 1 ? strlen($padding[0]) : 1;
        $shift = $outer + strlen($marker) - (strlen($m[0]) - strlen($m[1])) - ($pad - 1);
        $content = preg_match('/^[ \t]*(?:[-*+]|\d+[.)]) +/', $line, $own) === 1 ? self::columns($own[0]) : self::columns($m[0]);
        // Five or more columns of padding put the content one column past the
        // marker, the rest being indented code (CommonMark 5.2).
        if ($content - self::columns($m[0]) > 4) {
            $content = self::columns($m[0]) + 1;
        }
        $this->open[] = ['col' => $col, 'content' => $content, 'shift' => $shift, 'outer' => $outer, 'kind' => $kind, 'bullet' => $bullet, 'next' => $next, 'restart' => false];
        // Items the first line nests (`- - a`) are written as they are, and
        // move with this one.
        foreach (self::nestedItemsOnLine($line, strlen($own[0] ?? $m[0])) as $inner) {
            $this->open[] = [
                'col' => $inner['col'],
                'content' => $inner['content'],
                'shift' => $shift,
                'outer' => $shift,
                'kind' => $inner['kind'],
                'bullet' => $inner['bullet'] === '+' ? '-' : $inner['bullet'],
                'next' => $inner['number'] === '' ? 0 : (int)$inner['number'] + 1,
                'restart' => false,
            ];
        }

        return [
            'line' => $m[1] . $marker . substr($after, $pad - 1),
            'separate' => $prev !== null && !$same,
            'outer' => $outer,
            'shift' => $shift,
            'content' => $content,
        ];
    }

    /**
     * The items a list line nests past byte `$from` of its first line
     * (`- - a`), with their marker and content columns and where each ends.
     *
     * @return array<int, array{col: int, content: int, kind: string, bullet: string, number: string, end: int}>
     */
    public static function nestedItemsOnLine(string $line, int $from): array
    {
        $items = [];
        // Past four columns of padding a marker's content is indented code,
        // which nests nothing (CommonMark 5.2).
        $padded = static fn (int $start, int $end): bool => self::columns(substr($line, 0, $end)) - self::columns(substr($line, 0, $start)) > 4;
        if (preg_match('/^[ \t]*(?:[-*+]|\d{1,9}[.)])/', $line, $own) === 1 && $padded(strlen($own[0]), $from)) {
            return $items;
        }
        $at = $from;
        while (true) {
            $rest = substr($line, $at);
            // A thematic break wins over a list item (`- * * *`).
            if (preg_match('/^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/', $rest) === 1) {
                return $items;
            }
            if (preg_match('/^([ \t]*)(?:([-*+])|(\d{1,9})([.)]))([ \t]+)(?=\S)/', $rest, $m) !== 1) {
                return $items;
            }
            $markerEnd = $at + strlen($m[0]) - strlen($m[5]);
            if ($padded($markerEnd, $at + strlen($m[0]))) {
                return $items;
            }
            $items[] = [
                'col' => self::columns(substr($line, 0, $at + strlen($m[1]))),
                'content' => self::columns(substr($line, 0, $at + strlen($m[0]))),
                'kind' => $m[2] !== '' ? $m[2] : $m[4],
                'bullet' => $m[2],
                'number' => $m[3],
                'end' => $at + strlen($m[0]),
            ];
            $at += strlen($m[0]);
        }
    }

    /**
     * Width of a string in columns, a tab advancing to the next four-column stop.
     */
    public static function columns(string $text): int
    {
        $width = 0;
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $width += $text[$i] === "\t" ? 4 - ($width % 4) : 1;
        }

        return $width;
    }
}
