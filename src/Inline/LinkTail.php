<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Inline;

/**
 * The link tail `(destination "title")` glued to a closing bracket (C2-I-066 to C2-I-069).
 *
 * The parentheses of the content are matched once, inside each run of characters without whitespace,
 * so a tail is found without rescanning the text behind it (a run of `[a](` stays linear).
 */
final class LinkTail
{
    /**
     * Offset of an opening parenthesis to the offset of its closing one, or to -1 when the run ends first.
     *
     * @var array<int, int>|null
     */
    private ?array $match = null;

    /**
     * Opening parenthesis left open at the end of its run to the whitespace offset that ended it, when it was
     * the innermost open one there.
     *
     * @var array<int, int>
     */
    private array $openAtEnd = [];

    /**
     * @param string $t
     * @param int $n
     */
    public function __construct(private string $t, private int $n)
    {
    }

    /**
     * @return void
     */
    private function index(): void
    {
        $t = $this->t;
        $n = $this->n;
        $this->match = [];
        $stack = [];
        $q = 0;
        while ($q < $n) {
            $q += strcspn($t, "()\\ \t\n", $q);
            if ($q >= $n) {
                break;
            }
            $c = $t[$q];
            if ($c === '\\') {
                $d = $t[$q + 1] ?? '';
                $q += $d === '(' || $d === ')' || $d === '\\' ? 2 : 1;

                continue;
            }
            if ($c === '(') {
                $stack[] = $q;
            } elseif ($c === ')') {
                $open = array_pop($stack);
                if ($open !== null) {
                    $this->match[$open] = $q;
                }
            } else {
                $this->endRun($stack, $q);
                $stack = [];
            }
            $q++;
        }
        $this->endRun($stack, $n);
    }

    /**
     * @param array<int> $stack
     * @param int $end
     *
     * @return void
     */
    private function endRun(array $stack, int $end): void
    {
        $top = array_pop($stack);
        if ($top !== null) {
            $this->match[$top] = -1;
            $this->openAtEnd[$top] = $end;
        }
        foreach ($stack as $open) {
            $this->match[$open] = -1;
        }
    }

    /**
     * Reads the tail whose `(` stands at `$p`. Returns destination, title and end offset, or null.
     *
     * @param int $p
     *
     * @return array{0: string, 1: string|null, 2: int}|null
     */
    public function read(int $p): ?array
    {
        if ($this->match === null) {
            $this->index();
        }
        $close = $this->match[$p] ?? -1;
        if ($close >= 0) {
            return [$this->destination($p + 1, $close), null, $close + 1];
        }
        // C2-I-067: parentheses in the destination are balanced; C2-I-068: one space, then the title.
        $w = $this->openAtEnd[$p] ?? -1;
        $t = $this->t;
        if ($w < 0 || $w + 1 >= $this->n || $t[$w] !== ' ') {
            return null;
        }
        $quote = $t[$w + 1];
        if ($quote !== '"' && $quote !== "'") {
            return null;
        }
        $title = '';
        $r = $this->title($w + 2, $quote, $title);
        if ($r < 0) {
            return null;
        }
        $r += strspn($t, ' ', $r);
        if ($r >= $this->n || $t[$r] !== ')') {
            return null;
        }

        return [$this->destination($p + 1, $w), $title, $r + 1];
    }

    /**
     * C2-I-013: a destination reads only the escaped parenthesis and the escaped backslash.
     *
     * @param int $from
     * @param int $to
     *
     * @return string
     */
    private function destination(int $from, int $to): string
    {
        $raw = substr($this->t, $from, $to - $from);
        if (!str_contains($raw, '\\')) {
            return $raw;
        }

        // C2-I-013: one escape and one character class, linear; only destinations with a backslash.
        return (string)preg_replace('/\\\\([()\\\\])/', '$1', $raw);
    }

    /**
     * C2-I-068: a backslash escapes as in text; the title stays on its line.
     *
     * @param int $q
     * @param string $quote
     * @param string $title
     *
     * @return int
     */
    private function title(int $q, string $quote, string &$title): int
    {
        $t = $this->t;
        $n = $this->n;
        $out = '';
        while ($q < $n) {
            $k = strcspn($t, $quote . "\\\n", $q);
            $out .= substr($t, $q, $k);
            $q += $k;
            if ($q >= $n || $t[$q] === "\n") {
                return -1;
            }
            if ($t[$q] === $quote) {
                $title = $out;

                return $q + 1;
            }
            $d = $t[$q + 1] ?? '';
            if ($d !== '' && ctype_punct($d)) {
                $out .= $d;
                $q += 2;
            } else {
                $out .= '\\';
                $q++;
            }
        }

        return -1;
    }
}
