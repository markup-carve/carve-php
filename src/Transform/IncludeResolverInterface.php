<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Transform;

interface IncludeResolverInterface
{
    /**
     * Resolve an include path to child source. Return a ResolvedInclude to
     * supply a canonical file id alongside the source (recommended, see
     * ResolvedInclude), or a plain string for source-only resolution. An
     * unresolvable path may either return null or throw; both degrade to a
     * warning plus a literal directive.
     */
    public function resolve(string $path, IncludeContext $context): ResolvedInclude|string|null;
}
