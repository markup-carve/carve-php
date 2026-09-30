<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

use Closure;

interface RenderEventsInterface
{
    /**
     * @param string $event
     * @param \Closure(\MarkupCarve\Carve\Event\RenderEvent): void $listener
     */
    public function on(string $event, Closure $listener): void;

    public function off(?string $event = null): void;
}
