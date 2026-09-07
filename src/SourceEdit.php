<?php

declare(strict_types=1);

namespace MarkupCarve\Carve;

use JsonSerializable;

final readonly class SourceEdit implements JsonSerializable
{
    public function __construct(
        public int $start,
        public int $end,
        public string $replacement,
        public string $kind = 'refactor',
        public string $code = 'replace-source',
    ) {
    }

    /**
     * @return array<string, int|string>
     */
    public function jsonSerialize(): array
    {
        return [
            'start' => $this->start,
            'end' => $this->end,
            'replacement' => $this->replacement,
            'kind' => $this->kind,
            'code' => $this->code,
        ];
    }
}
