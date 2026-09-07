<?php

declare(strict_types=1);

namespace MarkupCarve\Carve;

use JsonSerializable;

final readonly class SourceSuggestion implements JsonSerializable
{
    public function __construct(
        public int $start,
        public int $end,
        public string $replacement,
        public string $kind,
        public string $code,
        public string $message,
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
            'message' => $this->message,
        ];
    }
}
