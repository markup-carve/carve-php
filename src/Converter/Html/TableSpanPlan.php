<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\Html;

use DOMElement;
use SplObjectStorage;

/**
 * Span counts keyed by source DOM cells.
 *
 * @internal
 */
final class TableSpanPlan
{
    /**
     * @var \SplObjectStorage<\DOMElement, array{colspan: int, rowspan: int}>
     */
    private SplObjectStorage $spans;

    public function __construct()
    {
        $this->spans = new SplObjectStorage();
    }

    public function set(DOMElement $cell, int $colspan, int $rowspan): void
    {
        $this->spans[$cell] = ['colspan' => $colspan, 'rowspan' => $rowspan];
    }

    /**
     * @return array{colspan: int, rowspan: int}
     */
    public function get(DOMElement $cell): array
    {
        return $this->spans[$cell];
    }
}
