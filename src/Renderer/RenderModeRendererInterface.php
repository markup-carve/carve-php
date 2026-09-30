<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

interface RenderModeRendererInterface
{
    public function setRenderMode(string $mode): self;

    public function getRenderMode(): string;
}
