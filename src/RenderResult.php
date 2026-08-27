<?php

declare(strict_types=1);

namespace MarkupCarve\Carve;

final readonly class RenderResult
{
    /**
     * @param string $value
     * @param list<array<string, mixed>> $losses
     * @param bool $truncated
     * @param int $totalLosses
     */
    public function __construct(
        public string $value,
        public array $losses,
        public int $totalLosses,
        public bool $truncated,
    ) {
    }

    /**
     * @return array{value: string, losses: list<array<string, mixed>>, totalLosses: int, truncated: bool}
     */
    public function toArray(): array
    {
        return ['value' => $this->value, 'losses' => $this->losses, 'totalLosses' => $this->totalLosses, 'truncated' => $this->truncated];
    }
}
