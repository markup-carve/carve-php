<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer\Utility;

use MarkupCarve\Carve\Node\Inline\InlineNode;
use MarkupCarve\Carve\Node\Node;

final class CarveFieldDiagnostic extends InlineNode
{
    public function __construct(
        public readonly Node $origin,
        public readonly string $field,
        public readonly string $message,
    ) {
    }

    public function getType(): string
    {
        return 'carve_field_diagnostic';
    }
}
