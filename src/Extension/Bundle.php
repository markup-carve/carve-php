<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

/**
 * Provisional (extensions.md). The registrations of one or more standard extensions: pass the bundle in the
 * `extensions` option, and its transforms to `Carve::renderWithDocumentTransforms()` (C2-X-053).
 */
final class Bundle implements ExtensionProvider
{
    /**
     * @param array<\MarkupCarve\Carve\Extension\ExtensionDefinition> $definitions
     * @param array<\MarkupCarve\Carve\Extension\DocumentTransform> $transforms
     */
    public function __construct(private readonly array $definitions, private readonly array $transforms = [])
    {
    }

    /**
     * Merges bundles into one; two claims of one name fail at registration (C2-X-014).
     */
    public static function merge(self ...$bundles): self
    {
        $definitions = [];
        $transforms = [];
        foreach ($bundles as $bundle) {
            array_push($definitions, ...$bundle->definitions);
            array_push($transforms, ...$bundle->transforms);
        }

        return new self($definitions, $transforms);
    }

    /**
     * @return array<\MarkupCarve\Carve\Extension\ExtensionDefinition>
     */
    public function definitions(): array
    {
        return $this->definitions;
    }

    /**
     * @return array<\MarkupCarve\Carve\Extension\DocumentTransform>
     */
    public function transforms(): array
    {
        return $this->transforms;
    }

    /**
     * The same registrations without renderers: every extension node writes its fallback (C2-X-035).
     */
    public function withoutRenderers(): self
    {
        $definitions = array_map(static fn (ExtensionDefinition $d): ExtensionDefinition => $d->withoutRenderers(), $this->definitions);

        return new self($definitions, $this->transforms);
    }
}
