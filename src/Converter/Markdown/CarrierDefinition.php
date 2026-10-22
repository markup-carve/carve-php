<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\Markdown;

final class CarrierDefinition
{
    public bool $pending = false;

    public ?int $marker = null;

    public function __construct(public string $base, public string $original, public int $depth = 1)
    {
    }
}
