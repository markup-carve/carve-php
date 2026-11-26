<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Html;

use SplMinHeap;

/**
 * Render-loss entries held back by RenderLog, smallest source offset first.
 *
 * @extends \SplMinHeap<array{0: int, 1: int, 2: array{code: string, severity: string, message: string,
 *     channel: string, file: string|null, node: \MarkupCarve\Carve\Node|null, slots: array<string, string|int>,
 *     field: string|null}}>
 */
final class HeldEntries extends SplMinHeap
{
}
