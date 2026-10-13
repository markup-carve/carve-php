<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Inline;

/**
 * The `+` continuation joins inside a verbatim payload (CARVE-P12-069): each
 * `offset` counts codepoints up to a joining space in the value.
 */
trait CarriesCellWraps
{
    /**
     * @var array<array{offset: int, line: int, row?: int}>|null
     */
    protected ?array $cellWraps = null;

    /**
     * @return array<array{offset: int, line: int, row?: int}>|null
     */
    public function getCellWraps(): ?array
    {
        return $this->cellWraps;
    }

    /**
     * @param array<array{offset: int, line: int, row?: int}>|null $cellWraps
     *
     * @return void
     */
    public function setCellWraps(?array $cellWraps): void
    {
        $this->cellWraps = $cellWraps === [] ? null : $cellWraps;
    }
}
