<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer\Utility;

use SplQueue;

/**
 * @internal
 *
 * @extends \SplQueue<\MarkupCarve\Carve\Event\RenderEvent>
 */
final class RenderEventQueue extends SplQueue
{
}
