<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Inline;

/**
 * One content (C2-I-002, C2-I-003): its source spans joined by one line feed, and the map from an
 * offset in that text back to the source.
 */
final class Content
{
    public string $t = '';

    /**
     * Offset in `$t` of each line start.
     *
     * @var array<int>
     */
    private array $lt = [];

    /**
     * Source offset of each line start.
     *
     * @var array<int>
     */
    private array $ls = [];

    private int $last = 0;

    /**
     * Source end of each line, trailing spaces included.
     *
     * @var array<int>
     */
    private array $le = [];

    /**
     * @var array<int> C2-B-138: an atom opened before this line cannot close after it.
     */
    private array $atomStops = [];

    /**
     * Source offset of the text start when the content is one line, else -1.
     */
    public int $base = -1;

    /**
     * Source end of the line, trailing spaces included, when the content is one line.
     */
    private int $baseEnd = -1;

    /**
     * @param string $s
     * @param array<int> $spans
     */
    public function __construct(string $s, array $spans)
    {
        $n = count($spans);
        if ($n === 2) {
            $this->base = $spans[0];
            $this->baseEnd = $spans[1];
            $this->t = self::trimEnd(substr($s, $spans[0], max(0, $spans[1] - $spans[0])));

            return;
        }
        $parts = [];
        $off = 0;
        for ($k = 0; $k + 1 < $n; $k += 2) {
            $this->lt[] = $off;
            $this->ls[] = $spans[$k];
            $this->le[] = $spans[$k + 1];
            $line = self::trimEnd(substr($s, $spans[$k], max(0, $spans[$k + 1] - $spans[$k])));
            if ($k > 0 && str_starts_with(ltrim($line, " \t"), '%%')) {
                $this->atomStops[] = $off;
            }
            $len = strlen($line);
            $parts[] = $line;
            $off += $len + 1;
        }
        $this->t = implode("\n", $parts);
    }

    public function crossesAtomStop(int $from, int $end): bool
    {
        $lo = 0;
        $hi = count($this->atomStops);
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($this->atomStops[$mid] <= $from) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        return isset($this->atomStops[$lo]) && $this->atomStops[$lo] <= $end;
    }

    /**
     * C2-I-002: spaces and tabs at a line end are no content; a space a backslash escapes stays.
     *
     * @param string $line
     *
     * @return string
     */
    public static function trimEnd(string $line): string
    {
        $trimmed = rtrim($line, " \t");
        $len = strlen($trimmed);
        if ($len === strlen($line)) {
            return $line;
        }
        $slashes = $len - strlen(rtrim($trimmed, '\\'));
        if ($slashes % 2 === 1 && $line[$len] === ' ') {
            return $trimmed . ' ';
        }

        return $trimmed;
    }

    /**
     * Source end of the line that holds the text offset `$x`, its trailing spaces included.
     *
     * @param int $x
     *
     * @return int
     */
    public function lineEnd(int $x): int
    {
        if ($this->base >= 0) {
            return $this->baseEnd;
        }
        $lo = 0;
        $hi = count($this->lt) - 1;
        while ($lo < $hi) {
            $mid = ($lo + $hi + 1) >> 1;
            if ($this->lt[$mid] <= $x) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }

        return $this->le[$lo] ?? $this->src($x);
    }

    /**
     * Source offset of the text offset `$x`; the line feed between two lines maps to the end of the
     * first, the offset after it to the start of the next.
     *
     * @param int $x
     *
     * @return int
     */
    public function src(int $x): int
    {
        if ($this->base >= 0) {
            return $this->base + $x;
        }
        $lines = count($this->lt);
        if ($lines === 0) {
            return $x;
        }
        $k = $this->last;
        if ($k >= $lines || $this->lt[$k] > $x || ($k + 1 < $lines && $this->lt[$k + 1] <= $x)) {
            $lo = 0;
            $hi = $lines - 1;
            while ($lo < $hi) {
                $mid = ($lo + $hi + 1) >> 1;
                if ($this->lt[$mid] <= $x) {
                    $lo = $mid;
                } else {
                    $hi = $mid - 1;
                }
            }
            $k = $lo;
            $this->last = $k;
        }

        return $this->ls[$k] + $x - $this->lt[$k];
    }
}
