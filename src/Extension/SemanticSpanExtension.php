<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use MarkupCarve\Carve\CarveConverter;

/**
 * Compatibility shim for semantic span attributes now handled by core.
 *
 * Existing registrations remain source-compatible during 0.1.x. New code can
 * use `[text]{kbd}`, `[HTML]{abbr="…"}`, and the other standardized semantic
 * attributes without registering an extension.
 *
 * @deprecated Semantic span attributes are built into Carve core.
 */
class SemanticSpanExtension implements ExtensionInterface
{
    public function register(CarveConverter $converter): void
    {
        // Core HTML rendering owns this behavior; intentionally a no-op.
    }
}
