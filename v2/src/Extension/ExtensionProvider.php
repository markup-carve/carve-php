<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Extension;

/**
 * Provisional (extensions.md: the extension host may change in 0.2.x). A package that ships extensions implements this
 * and is passed in the `extensions` option beside plain `ExtensionDefinition` objects.
 */
interface ExtensionProvider
{
    /**
     * @return array<\MarkupCarve\Carve2\Extension\ExtensionDefinition>
     */
    public function definitions(): array;
}
