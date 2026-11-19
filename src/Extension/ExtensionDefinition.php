<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use Closure;

/**
 * The registration of an extension (C2-X-001, C2-X-002, C2-X-033): its qualified name and version, the slots in the
 * order of its declaration, an optional matcher with its transform (C2-X-005 to C2-X-015), the slot of the extension
 * that alone may hold it, and an optional renderer, which is trusted host code (C2-X-034).
 */
final class ExtensionDefinition
{
    /**
     * @param string $name
     * @param string $version
     * @param array<string> $slots The slot names in declaration order.
     * @param array{form: string, name: string}|null $matcher `form` is `div`, `fence` or `inline`.
     * @param array{name: string, slot: string}|null $parent
     * @param (\Closure(\MarkupCarve\Carve\Extension\Claim): (\MarkupCarve\Carve\Node|null))|null $transform
     * @param (\Closure(\MarkupCarve\Carve\Node, \MarkupCarve\Carve\Extension\RenderHost): string)|null $renderer
     * @param (\Closure(\MarkupCarve\Carve\Node, \MarkupCarve\Carve\Extension\RenderHost): string)|null $buildTimeRenderer
     */
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly array $slots = [],
        public readonly ?array $matcher = null,
        public readonly ?array $parent = null,
        public readonly ?Closure $transform = null,
        public readonly ?Closure $renderer = null,
        public readonly ?Closure $buildTimeRenderer = null,
    ) {
    }

    /**
     * The same registration with a renderer.
     *
     * @param \Closure(\MarkupCarve\Carve\Node, \MarkupCarve\Carve\Extension\RenderHost): string $renderer
     *
     * @return self
     */
    public function withRenderer(Closure $renderer): self
    {
        return new self(
            $this->name,
            $this->version,
            $this->slots,
            $this->matcher,
            $this->parent,
            $this->transform,
            $renderer,
            $this->buildTimeRenderer,
        );
    }

    /**
     * Register the renderer used only in static mode (C2-X-052).
     *
     * @param \Closure(\MarkupCarve\Carve\Node, \MarkupCarve\Carve\Extension\RenderHost): string $renderer
     */
    public function withBuildTimeRenderer(Closure $renderer): self
    {
        return new self(
            $this->name,
            $this->version,
            $this->slots,
            $this->matcher,
            $this->parent,
            $this->transform,
            $this->renderer,
            $renderer,
        );
    }
}
