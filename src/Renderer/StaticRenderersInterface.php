<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

interface StaticRenderersInterface
{
    /**
     * @param array<string, \Closure(string): string> $renderers
     */
    public function setStaticRenderers(array $renderers): self;

    /**
     * @return array<string, \Closure(string): string>
     */
    public function getStaticRenderers(): array;
}
