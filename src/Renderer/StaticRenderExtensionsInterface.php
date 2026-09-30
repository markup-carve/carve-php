<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

use MarkupCarve\Carve\Extension\StaticRenderExtensionInterface;

interface StaticRenderExtensionsInterface
{
    public function addStaticRenderExtension(StaticRenderExtensionInterface $extension): self;
}
