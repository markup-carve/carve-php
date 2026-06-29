<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\HeadingId;

use function array_values;

/**
 * Heading ids supplied directly as an ordered list - an escape hatch for ids
 * harvested by other tooling, or hand-curated. Index 0 is the first heading in
 * document order; '' leaves that heading untouched.
 *
 * Example:
 *   new MapIds(['Getting-Started', '', 'API-Reference'])
 */
final class MapIds implements HeadingIdSource
{
    /**
     * @var array<int, string>
     */
    protected array $ids;

    /**
     * @param array<int, string> $ids heading ids in document order
     */
    public function __construct(array $ids)
    {
        $this->ids = array_values($ids);
    }

    /**
     * @return array<int, string>
     */
    public function idsInOrder(string $djotSource): array
    {
        return $this->ids;
    }
}
