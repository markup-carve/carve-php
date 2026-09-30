<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

interface SmartTypographyRendererInterface
{
    public function setSmartTypography(SmartTypographyMode $mode): self;

    public function getSmartTypography(): SmartTypographyMode;
}
