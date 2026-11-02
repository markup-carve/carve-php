<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

/**
 * Bare entries that decline at a closer for an inner word delimiter
 * (carve#4810), per kind as regions from the scan start to the closer.
 *
 * Until its closer such an entry is still open, so the opener test asks
 * whether any region of a kind covers a position. Regions are kept sorted by
 * start with a running maximum of their closers, so that is one binary search.
 *
 * @internal
 */
final class BareDeclines
{
    /**
     * @var array<string, array{from: array<int>, closer: array<int>, reach: array<int>}>
     */
    private array $kinds = [];

    public function __construct(public readonly string $text)
    {
    }

    public function add(int $from, int $closer, string $kind): void
    {
        $this->kinds[$kind] ??= ['from' => [], 'closer' => [], 'reach' => []];
        // In place: a copy of the arrays per region made recording quadratic.
        $regions = &$this->kinds[$kind];
        $last = count($regions['from']) - 1;
        if ($last < 0 || $regions['from'][$last] <= $from) {
            $regions['from'][] = $from;
            $regions['closer'][] = $closer;
            $regions['reach'][] = $last < 0 ? $closer : max($closer, $regions['reach'][$last]);

            return;
        }

        // Out of order: a lookahead scanned an opener behind the last region.
        $at = $this->lastAtOrBefore($regions['from'], $from) + 1;
        array_splice($regions['from'], $at, 0, [$from]);
        array_splice($regions['closer'], $at, 0, [$closer]);
        $reach = $at > 0 ? $regions['reach'][$at - 1] : $closer;
        array_splice($regions['reach'], $at);
        $count = count($regions['from']);
        for ($i = $at; $i < $count; $i++) {
            $reach = max($reach, $regions['closer'][$i]);
            $regions['reach'][] = $reach;
        }
    }

    /**
     * Whether a region of a kind in $kinds covers $at.
     */
    public function covers(int $at, string $kinds): bool
    {
        foreach (str_split($kinds) as $kind) {
            $regions = $this->kinds[$kind] ?? null;
            if ($regions === null) {
                continue;
            }
            $i = $this->lastAtOrBefore($regions['from'], $at);
            if ($i >= 0 && $regions['reach'][$i] > $at) {
                return true;
            }
        }

        return false;
    }

    /**
     * The regions covering the opener $at, shifted into the span content after it.
     */
    public function into(int $at, string $content): ?self
    {
        $held = null;
        foreach ($this->kinds as $kind => $regions) {
            for ($i = $this->lastAtOrBefore($regions['from'], $at); $i >= 0 && $regions['reach'][$i] > $at; $i--) {
                if ($regions['closer'][$i] > $at) {
                    $held ??= new self($content);
                    $held->add(0, $regions['closer'][$i] - $at - 1, (string)$kind);
                }
            }
        }

        return $held;
    }

    /**
     * @param array<int> $from
     */
    private function lastAtOrBefore(array $from, int $at): int
    {
        $low = 0;
        $high = count($from);
        while ($low < $high) {
            $mid = ($low + $high) >> 1;
            if ($from[$mid] <= $at) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }

        return $low - 1;
    }
}
