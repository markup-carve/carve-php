<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

interface RenderTargetInterface
{
    public function getRenderTarget(): string;
}
