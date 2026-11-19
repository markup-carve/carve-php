<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Layout;

/**
 * Line reading for the source-layout record: line bounds, quote prefixes (C2-B-003) and blank lines inside quotes
 * (C2-L-053).
 */
final class SourceLines
{
    private int $length;

    public function __construct(public readonly string $s)
    {
        $this->length = strlen($s);
    }

    public function lineStart(int $pos): int
    {
        if ($pos <= 0) {
            return 0;
        }
        $feed = strrpos($this->s, "\n", $pos - 1 - $this->length);

        return $feed === false ? 0 : $feed + 1;
    }

    /**
     * The end of the line at `$pos`, before its CR LF or LF.
     *
     * @param int $pos
     *
     * @return int
     */
    public function lineEnd(int $pos): int
    {
        $feed = strpos($this->s, "\n", $pos);
        if ($feed === false) {
            return $this->length;
        }

        return $feed > $pos && $this->s[$feed - 1] === "\r" ? $feed - 1 : $feed;
    }

    /**
     * C2-B-003: at most `$quotes` quote marks, each with its one space.
     *
     * @param int $start
     * @param int $end
     * @param int $quotes
     *
     * @return array{0: int, 1: int} The offset behind the marks and the number of marks read.
     */
    public function prefix(int $start, int $end, int $quotes): array
    {
        $pos = $start === 0 && str_starts_with($this->s, "\u{FEFF}") ? 3 : $start;
        $marks = 0;
        while ($marks < $quotes) {
            $p = $pos + strspn($this->s, " \t", $pos, max(0, $end - $pos));
            if ($p >= $end || $this->s[$p] !== '>') {
                break;
            }
            $p++;
            if ($p < $end && $this->s[$p] === ' ') {
                $p++;
            }
            $pos = $p;
            $marks++;
        }

        return [$pos, $marks];
    }

    /**
     * C2-L-053: a line is blank inside its quotes when it carries exactly their marks and nothing else.
     *
     * @param int $start
     * @param int $quotes
     *
     * @return bool
     */
    public function blank(int $start, int $quotes): bool
    {
        $end = $this->lineEnd($start);
        [$pos, $marks] = $this->prefix($start, $end, $quotes);

        return $marks === $quotes && $pos + strspn($this->s, " \t", $pos, max(0, $end - $pos)) >= $end;
    }

    /**
     * C2-L-026: the blank lines right above the line of `$start`, not above `$floor`.
     *
     * @param int $start
     * @param int $floor
     * @param int $quotes
     *
     * @return int
     */
    public function blankBefore(int $start, int $floor, int $quotes): int
    {
        $count = 0;
        $line = $this->lineStart($start);
        while ($line > 0) {
            $above = $this->lineStart($line - 1);
            if ($above < $floor || !$this->blank($above, $quotes)) {
                break;
            }
            $count++;
            $line = $above;
        }

        return $count;
    }

    /**
     * C2-L-026: the blank lines right below the line of `$end - 1`, not at or behind `$limit`.
     *
     * @param int $end
     * @param int $limit
     * @param int $quotes
     *
     * @return int
     */
    public function blankAfter(int $end, int $limit, int $quotes): int
    {
        $count = 0;
        $feed = strpos($this->s, "\n", max(0, $end - 1));
        while ($feed !== false && $feed + 1 < $this->length && $feed + 1 < $limit) {
            $start = $feed + 1;
            if (!$this->blank($start, $quotes)) {
                break;
            }
            $count++;
            $feed = strpos($this->s, "\n", $start);
        }

        return $count;
    }

    /**
     * C2-L-049: the text from `$start` to `$stop`, read without the prefixes and the indentation of the lines it
     * crosses, the pieces joined by a line feed.
     *
     * @param int $start
     * @param int $stop
     * @param int $quotes
     *
     * @return string
     */
    public function joined(int $start, int $stop, int $quotes): string
    {
        $out = '';
        $at = $start;
        while (($feed = strpos($this->s, "\n", $at)) !== false && $feed < $stop) {
            $segment = substr($this->s, $at, $feed - $at);
            $out .= (str_ends_with($segment, "\r") ? substr($segment, 0, -1) : $segment) . "\n";
            $next = $this->prefix($feed + 1, $stop, $quotes)[0];
            $at = $next + strspn($this->s, " \t", $next, max(0, $stop - $next));
        }

        return $out . substr($this->s, $at, max(0, $stop - $at));
    }

    /**
     * C2-L-007, C2-L-054: `none`, `lf`, `crlf`, `cr` or `mixed`; a lone CR is reported although it ends no line.
     *
     * @return string
     */
    public function lineEndings(): string
    {
        $crlf = substr_count($this->s, "\r\n");
        $lf = substr_count($this->s, "\n") - $crlf;
        $cr = substr_count($this->s, "\r") - $crlf;
        $kinds = array_filter(['crlf' => $crlf, 'lf' => $lf, 'cr' => $cr]);
        if ($kinds === []) {
            return 'none';
        }

        return count($kinds) > 1 ? 'mixed' : (string)array_key_first($kinds);
    }
}
