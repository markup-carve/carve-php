<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer\Utility;

use SplQueue;

/**
 * @internal
 *
 * @extends \SplQueue<array{type: string, event: \MarkupCarve\Carve\Event\RenderEvent|null}>
 */
final class RenderEventQueue extends SplQueue
{
}
