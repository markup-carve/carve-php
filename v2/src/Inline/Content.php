<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Inline;

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
     * @param string $s
     * @param array<int> $spans
     */
    public function __construct(string $s, array $spans)
    {
        $parts = [];
        $off = 0;
        $n = count($spans);
        for ($k = 0; $k + 1 < $n; $k += 2) {
            $this->lt[] = $off;
            $this->ls[] = $spans[$k];
            $line = self::trimEnd(substr($s, $spans[$k], max(0, $spans[$k + 1] - $spans[$k])));
            $len = strlen($line);
            $parts[] = $line;
            $off += $len + 1;
        }
        $this->t = implode("\n", $parts);
    }

    /**
     * C2-I-002: spaces and tabs at a line end are no content; a space a backslash escapes stays.
     *
     * @param string $line
     *
     * @return string
     */
    private static function trimEnd(string $line): string
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
     * Source offset of the text offset `$x`; the line feed between two lines maps to the end of the
     * first, the offset after it to the start of the next.
     *
     * @param int $x
     *
     * @return int
     */
    public function src(int $x): int
    {
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
