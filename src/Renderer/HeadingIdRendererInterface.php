<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

interface HeadingIdRendererInterface
{
    public function getHeadingIdTracker(): HeadingIdTracker;
}
