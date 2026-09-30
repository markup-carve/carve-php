<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

use MarkupCarve\Carve\SafeMode;

interface SafeModeRendererInterface
{
    public function setSafeMode(?SafeMode $safeMode): self;

    public function getSafeMode(): ?SafeMode;
}
