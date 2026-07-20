<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Transform;

interface IncludeResolverInterface
{
    /**
     * Resolve an include path to child source. Return a ResolvedInclude to
     * supply a canonical file id alongside the source (recommended, see
     * ResolvedInclude), a plain string for source-only resolution, or throw
     * for an unresolvable path.
     */
    public function resolve(string $path, IncludeContext $context): ResolvedInclude|string;
}
